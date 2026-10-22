<?php
/**
 * Resumable streaming SQL dumper built on top of a wpdb compatible object.
 *
 * Rows are fetched page by page using keyset pagination on the primary key
 * (falling back to LIMIT/OFFSET for tables without one) and emitted as
 * multi-row INSERT statements no larger than ~1 MB each.
 *
 * Output format guarantees (relied upon by restore.php):
 *  - every statement ends with ";\n";
 *  - string values never contain raw newlines (they are escaped).
 *
 * @package WPSSB
 */

namespace WPSSB\Db;

class Dumper {

	const MAX_STATEMENT = 1048576;
	const PAGE_BYTES    = 4194304;
	const MAX_PAGE_ROWS = 1000;

	/** @var \wpdb|object */
	private $db;

	/** @var array */
	private $cursor;

	/** @var callable|null */
	private $warn;

	/** @var array<string,array> */
	private $meta = array();

	/** @var int Rows emitted during this run. */
	private $rows = 0;

	/** @var array<string, array{0:string, 1:string[]}> table => [column, values] rows to leave out. */
	private $skip_rows;

	/**
	 * @param object        $db        wpdb compatible object.
	 * @param array         $cursor    Cursor from a previous run or from initialCursor().
	 * @param callable|null $warn      Receives warning messages.
	 * @param array         $skip_rows table => [column, values] rows excluded from the dump.
	 */
	public function __construct( $db, array $cursor, ?callable $warn = null, array $skip_rows = array() ) {
		$this->db        = $db;
		$this->cursor    = $cursor;
		$this->warn      = $warn;
		$this->skip_rows = $skip_rows;
	}

	/**
	 * Lists the tables to back up.
	 *
	 * @param object   $db          wpdb compatible object.
	 * @param string   $prefix      Table prefix.
	 * @param bool     $all_tables  Include tables not starting with the prefix.
	 * @param string[] $excluded    Table names to skip.
	 * @return string[]
	 */
	public static function listTables( $db, string $prefix, bool $all_tables, array $excluded ): array {
		$rows = $db->get_results( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", ARRAY_N );
		if ( ! empty( $db->last_error ) ) {
			throw new \RuntimeException( 'Cannot list tables: ' . $db->last_error );
		}
		$tables = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) $row[0];
			if ( ! $all_tables && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( in_array( $name, $excluded, true ) ) {
				continue;
			}
			$tables[] = $name;
		}
		sort( $tables, SORT_STRING );
		return $tables;
	}

	public static function initialCursor( array $tables ): array {
		return array(
			'tables' => array_values( $tables ),
			'i'      => 0,
			'stage'  => 'schema',
			'key'    => null,
			'offset' => 0,
			'header' => false,
			'rows'   => 0,
		);
	}

	public function cursor(): array {
		return $this->cursor;
	}

	public function rowsThisRun(): int {
		return $this->rows;
	}

	/**
	 * Dumps until finished or until $deadline (microtime) is reached.
	 *
	 * @param callable $out      Receives SQL text.
	 * @param float    $deadline Absolute microtime(true) deadline.
	 * @return bool True when the whole database has been dumped.
	 */
	public function run( callable $out, float $deadline ): bool {
		if ( empty( $this->cursor['header'] ) ) {
			$out( $this->header() );
			$this->cursor['header'] = true;
		}

		$previous_tz = $this->db->get_var( 'SELECT @@session.time_zone' );
		$this->db->query( "SET time_zone = '+00:00'" );

		try {
			$tables = $this->cursor['tables'];
			$worked = false;
			while ( $this->cursor['i'] < count( $tables ) ) {
				if ( $worked && microtime( true ) >= $deadline ) {
					return false;
				}
				$worked = true;
				$table  = $tables[ $this->cursor['i'] ];

				if ( 'schema' === $this->cursor['stage'] ) {
					$create = $this->db->get_row( 'SHOW CREATE TABLE ' . self::ident( $table ), ARRAY_N );
					if ( empty( $create ) || ! isset( $create[1] ) ) {
						$error = (string) $this->db->last_error;
						if ( '' !== $error && false === stripos( $error, "doesn't exist" ) ) {
							throw new \RuntimeException( 'SHOW CREATE TABLE failed on ' . $table . ': ' . $error );
						}
						$this->warning( sprintf( 'Table %s disappeared during backup, skipped.', $table ) );
						$this->nextTable();
						continue;
					}
					$out(
						"\n--\n-- Table structure for " . self::ident( $table ) . "\n--\n" .
						'DROP TABLE IF EXISTS ' . self::ident( $table ) . ";\n" .
						str_replace( "\r", '', (string) $create[1] ) . ";\n\n"
					);
					$this->cursor['stage']  = 'rows';
					$this->cursor['key']    = null;
					$this->cursor['offset'] = 0;
				}

				if ( $this->dumpRows( $table, $out, $deadline ) ) {
					$this->nextTable();
				}
			}

			$out( "\nSET FOREIGN_KEY_CHECKS=1;\n-- Dump completed\n" );
			return true;
		} finally {
			if ( is_string( $previous_tz ) && '' !== $previous_tz ) {
				$this->db->query( "SET time_zone = '" . self::escape( $previous_tz ) . "'" );
			}
		}
	}

	private function nextTable(): void {
		$this->cursor['i']++;
		$this->cursor['stage']  = 'schema';
		$this->cursor['key']    = null;
		$this->cursor['offset'] = 0;
	}

	private function header(): string {
		$charset = ! empty( $this->db->charset ) ? preg_replace( '/[^a-z0-9_]/i', '', (string) $this->db->charset ) : 'utf8mb4';
		return "-- WP SFTP Stream Backup database dump\n" .
			'-- Created (UTC): ' . gmdate( 'Y-m-d H:i:s' ) . "\n" .
			"-- Each statement ends with \";\\n\"\n\n" .
			'SET NAMES ' . $charset . ";\n" .
			"SET FOREIGN_KEY_CHECKS=0;\n" .
			"SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" .
			"SET time_zone='+00:00';\n";
	}

	private function meta( string $table ): array {
		if ( isset( $this->meta[ $table ] ) ) {
			return $this->meta[ $table ];
		}
		$columns = $this->db->get_results( 'SHOW COLUMNS FROM ' . self::ident( $table ), ARRAY_A );
		if ( ! empty( $this->db->last_error ) ) {
			throw new \RuntimeException( 'Cannot read columns of ' . $table . ': ' . $this->db->last_error );
		}
		$cols     = array();
		$kind     = array();
		$not_null = array();
		foreach ( (array) $columns as $col ) {
			$name          = (string) $col['Field'];
			$cols[]        = $name;
			$kind[ $name ] = self::columnKind( (string) $col['Type'] );
			if ( 'NO' === strtoupper( (string) $col['Null'] ) ) {
				$not_null[ $name ] = true;
			}
		}

		// Keyset pagination key: the primary key, or else a unique index on NOT NULL columns.
		$indexes = array();
		$keys    = $this->db->get_results( 'SHOW KEYS FROM ' . self::ident( $table ) . ' WHERE Non_unique = 0', ARRAY_A );
		foreach ( (array) $keys as $key ) {
			$indexes[ (string) $key['Key_name'] ][ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
		}
		$pk = array();
		if ( isset( $indexes['PRIMARY'] ) ) {
			$pk = $indexes['PRIMARY'];
		} else {
			foreach ( $indexes as $index ) {
				if ( ! array_diff( $index, array_keys( $not_null ) ) ) {
					$pk = $index;
					break;
				}
			}
		}
		ksort( $pk );
		$pk = array_values( $pk );

		$page   = self::MAX_PAGE_ROWS;
		$status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( is_array( $status ) && ! empty( $status['Avg_row_length'] ) ) {
			$page = (int) max( 10, min( self::MAX_PAGE_ROWS, floor( self::PAGE_BYTES / max( 1, (int) $status['Avg_row_length'] ) ) ) );
		}

		$this->meta[ $table ] = array(
			'cols' => $cols,
			'kind' => $kind,
			'pk'   => $pk,
			'page' => $page,
		);
		return $this->meta[ $table ];
	}

	/**
	 * @return bool True when the table is complete.
	 */
	private function dumpRows( string $table, callable $out, float $deadline ): bool {
		$meta = $this->meta( $table );
		if ( ! $meta['cols'] ) {
			return true;
		}
		$col_list = implode( ',', array_map( array( __CLASS__, 'ident' ), $meta['cols'] ) );
		$prefix   = 'INSERT INTO ' . self::ident( $table ) . ' (' . $col_list . ") VALUES\n";

		$conditions = array();
		if ( isset( $this->skip_rows[ $table ] ) && in_array( $this->skip_rows[ $table ][0], $meta['cols'], true ) ) {
			list( $skip_col, $skip_values ) = $this->skip_rows[ $table ];
			$quoted = array();
			foreach ( $skip_values as $value ) {
				$quoted[] = "'" . self::escape( (string) $value ) . "'";
			}
			if ( $quoted ) {
				$conditions[] = self::ident( $skip_col ) . ' NOT IN (' . implode( ',', $quoted ) . ')';
			}
		}

		while ( true ) {
			$where = $conditions;
			if ( $meta['pk'] ) {
				$order = implode( ',', array_map( array( __CLASS__, 'ident' ), $meta['pk'] ) );
				if ( null !== $this->cursor['key'] ) {
					$vals = array();
					foreach ( $meta['pk'] as $i => $col ) {
						$vals[] = $this->literal( $this->cursor['key'][ $i ], $meta['kind'][ $col ] );
					}
					$where[] = '(' . $order . ') > (' . implode( ',', $vals ) . ')';
				}
				$sql = 'SELECT ' . $col_list . ' FROM ' . self::ident( $table ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY ' . $order . ' LIMIT ' . (int) $meta['page'];
			} else {
				$sql = 'SELECT ' . $col_list . ' FROM ' . self::ident( $table ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' LIMIT ' . (int) $this->cursor['offset'] . ',' . (int) $meta['page'];
			}

			$rows = $this->db->get_results( $sql, ARRAY_A );
			if ( ! empty( $this->db->last_error ) ) {
				throw new \RuntimeException( 'Query failed on ' . $table . ': ' . $this->db->last_error );
			}
			if ( empty( $rows ) ) {
				return true;
			}

			$stmt = '';
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $meta['cols'] as $col ) {
					$vals[] = $this->literal( $row[ $col ], $meta['kind'][ $col ] );
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( '' !== $stmt && strlen( $stmt ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
					$out( $prefix . $stmt . ";\n" );
					$stmt = '';
				}
				$stmt .= ( '' === $stmt ? '' : ",\n" ) . $tuple;
			}
			if ( '' !== $stmt ) {
				$out( $prefix . $stmt . ";\n" );
			}

			$count = count( $rows );
			$this->rows             += $count;
			$this->cursor['rows']    = (int) $this->cursor['rows'] + $count;
			if ( $meta['pk'] ) {
				$last = end( $rows );
				$key  = array();
				foreach ( $meta['pk'] as $col ) {
					$key[] = $last[ $col ];
				}
				$this->cursor['key'] = $key;
			} else {
				$this->cursor['offset'] += $count;
			}
			unset( $rows, $stmt );

			if ( $count < $meta['page'] ) {
				return true;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}
	}

	public static function columnKind( string $type ): string {
		$type = strtolower( $type );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/', $type ) ) {
			return 'num';
		}
		if ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/', $type ) ) {
			return 'bin';
		}
		return 'str';
	}

	/**
	 * @param mixed $value
	 */
	public function literal( $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		if ( 'num' === $kind && is_numeric( $value ) && ! preg_match( '/[^0-9eE.+\-]/', $value ) ) {
			return $value;
		}
		if ( 'bin' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . self::escape( $value ) . "'";
	}

	public static function escape( string $value ): string {
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private function warning( string $message ): void {
		if ( $this->warn ) {
			( $this->warn )( $message );
		}
	}
}

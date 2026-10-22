<?php
/**
 * Database phase: dumps SQL into gzip members appended to database.sql.gz.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

use WPSSB\Db\Dumper;
use WPSSB\Remote\RemoteAppender;
use WPSSB\Remote\RemoteStorage;
use WPSSB\Stream\GzipMemberWriter;

class DbPhase {

	const FILE = 'database.sql.gz';

	/**
	 * @param array $db_state ['offset' => int, 'cursor' => array, 'done' => bool]
	 * @return array{state: array, warnings: string[], rows: int}
	 */
	public static function run( RemoteStorage $storage, string $remote_dir, array $db_state, $db, int $level, float $deadline, array $skip_rows = array() ): array {
		$path = $remote_dir . '/' . self::FILE;
		Phase::reconcile( $storage, $path, (int) $db_state['offset'] );

		$warnings = array();
		$appender = new RemoteAppender( $storage, $path, (int) $db_state['offset'] );
		$gzip     = new GzipMemberWriter( array( $appender, 'write' ), $level );
		$dumper   = new Dumper(
			$db,
			$db_state['cursor'],
			function ( $message ) use ( &$warnings ) {
				$warnings[] = $message;
			},
			$skip_rows
		);

		$done = $dumper->run( array( $gzip, 'write' ), $deadline );
		$gzip->finish();
		$appender->flush();

		return array(
			'state'    => array(
				'offset' => $appender->position(),
				'cursor' => $dumper->cursor(),
				'done'   => $done,
			),
			'warnings' => $warnings,
			'rows'     => $dumper->rowsThisRun(),
		);
	}
}

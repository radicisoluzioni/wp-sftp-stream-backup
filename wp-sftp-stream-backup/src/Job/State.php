<?php
/**
 * Persistence of the current job state and job history.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

class State {

	const OPTION  = 'wpssb_job';
	const HISTORY = 'wpssb_history';
	const MAX_LOG = 300;

	public static function load(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, null );
		return is_array( $state ) ? $state : null;
	}

	public static function save( array $state ): void {
		Lock::heartbeat();
		if ( count( $state['log'] ) > self::MAX_LOG ) {
			$state['log'] = array_slice( $state['log'], -self::MAX_LOG );
		}
		update_option( self::OPTION, $state, false );
	}

	public static function log( array &$state, string $level, string $message ): void {
		$state['log'][] = array( time(), $level, $message );
		if ( 'warning' === $level ) {
			$state['warnings'] = (int) $state['warnings'] + 1;
		}
	}

	public static function history(): array {
		$history = get_option( self::HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	public static function addHistory( array $state ): void {
		$history = self::history();
		$history = array_values( array_filter( $history, function ( $entry ) use ( $state ) { return $entry['id'] !== $state['id']; } ) );
		array_unshift(
			$history,
			array(
				'id'          => $state['id'],
				'status'      => $state['status'],
				'trigger'     => $state['trigger'],
				'started_at'  => $state['started_at'],
				'finished_at' => $state['finished_at'],
				'remote_dir'  => $state['remote_dir'],
				'db_bytes'    => (int) $state['db']['offset'],
				'files_bytes' => (int) $state['files']['offset'],
				'files_count' => (int) $state['files']['count'],
				'warnings'    => (int) $state['warnings'],
				'error'       => (string) $state['last_error'],
				'repositories' => $state['repositories'] ?? array(),
				'primary'     => $state['primary'] ?? '',
				'format'      => $state['format'] ?? 1,
			)
		);
		update_option( self::HISTORY, array_slice( $history, 0, 1000 ), false );
	}

	/** Public, non-sensitive view of the state for the UI / CLI. */
	public static function summary( ?array $state ): ?array {
		if ( ! $state ) {
			return null;
		}
		$cursor = $state['db']['cursor'];
		$repositories = $state['repositories'] ?? array();
		foreach ( $repositories as &$repository ) {
			unset( $repository['revision'] );
		}
		unset( $repository );
		return array(
			'id'            => $state['id'],
			'status'        => $state['status'],
			'phase'         => $state['phase'],
			'trigger'       => $state['trigger'],
			'started_at'    => (int) $state['started_at'],
			'updated_at'    => (int) $state['updated_at'],
			'finished_at'   => (int) $state['finished_at'],
			'remote_dir'    => $state['remote_dir'],
			'db_tables'     => count( $cursor['tables'] ),
			'db_table_done' => min( (int) $cursor['i'], count( $cursor['tables'] ) ),
			'db_rows'       => (int) $cursor['rows'],
			'db_bytes'      => (int) $state['db']['offset'],
			'files_count'   => (int) $state['files']['count'],
			'files_raw'     => (int) $state['files']['raw_bytes'],
			'files_bytes'   => (int) $state['files']['offset'],
			'files_current' => (string) ( $state['files']['partial']['rel'] ?? ( $state['files']['cursor'] ?? '' ) ),
			'warnings'      => (int) $state['warnings'],
			'errors'        => (int) $state['errors'],
			'last_error'    => (string) $state['last_error'],
			'steps'         => (int) $state['steps'],
			'log'           => array_slice( $state['log'], -100 ),
			'repositories'  => $repositories,
			'peak_memory'   => $state['peak_memory'] ?? 0,
		);
	}
}

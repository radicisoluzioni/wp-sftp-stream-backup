<?php
/**
 * Runs ONE very short restore step (10 ms, at least one statement/table) using restore.php as a library.
 * Used by restore-chaos.sh, which kills this process at random moments.
 * Prints the phase after the step.
 */
define( 'WPSSB_RESTORE_LIBRARY_ONLY', true );
define( 'RS_DIR', '/var/www/chaos' );
require RS_DIR . '/restore.php';

$lock = rs_lock();
if ( ! $lock ) {
	echo "busy\n";
	exit( 3 );
}
$state = rs_load_state();
if ( ! $state ) {
	$state = rs_new_state(
		array(
			'db_host' => 'db',
			'db_name' => 'wp4',
			'db_user' => 'root',
			'db_pass' => 'root',
			'url'     => 'http://localhost:8081/sub',
		)
	);
	rs_save_state( $state );
}
if ( 'done' !== $state['phase'] ) {
	rs_step( $state, microtime( true ) + 0.01 );
	// "nosave" simulates a crash right after the step's database work was committed.
	if ( 'nosave' !== ( isset( $argv[1] ) ? $argv[1] : '' ) || 'done' === $state['phase'] ) {
		rs_save_state( $state );
	}
}
echo $state['phase'], "\n";
if ( 'done' === $state['phase'] ) {
	foreach ( $state['log'] as $line ) {
		echo $line, "\n";
	}
}

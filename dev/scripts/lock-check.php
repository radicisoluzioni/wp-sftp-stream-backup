<?php
/** Exercises lease renewal and rejects writes from a worker that lost ownership. */

use WPSSB\Job\Lock;
use WPSSB\Job\LostLockException;
use WPSSB\Job\Runner;
use WPSSB\Job\State;

$original = get_option( State::OPTION, null );
$token = Lock::acquire( 120 );
if ( null === $token ) {
	throw new RuntimeException( 'Run lock checks while the test backup is idle.' );
}
$other = null;
try {
	Lock::begin( $token, 120 );
	Lock::heartbeat();
	try {
		Runner::start( 'cli' );
		throw new LogicException( 'Concurrent start ignored the active lease.' );
	} catch ( RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'busy' ) ) {
			throw $e;
		}
	}
	Lock::release( $token );
	$other = Lock::acquire( 120 );
	if ( null === $other ) {
		throw new RuntimeException( 'Could not simulate replacement worker.' );
	}
	try {
		State::save( array( 'log' => array(), 'status' => 'must-not-be-written' ) );
		throw new LogicException( 'A stale worker overwrote job state.' );
	} catch ( LostLockException $e ) {
		if ( get_option( State::OPTION, null ) !== $original ) {
			throw new RuntimeException( 'Job state changed after losing ownership.' );
		}
	}
	echo "Lease renewed; concurrent start refused; stale worker state write rejected.\n";
} finally {
	Lock::end();
	Lock::release( $token );
	if ( null !== $other ) {
		Lock::release( $other );
	}
}

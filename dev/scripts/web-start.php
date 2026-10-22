<?php
/**
 * Starts a backup exactly like the "Back up now" button does (loopback driven).
 *
 * @package WPSSB
 */

$state = \WPSSB\Job\Runner::start( 'manual' );
\WPSSB\Job\Driver::kick( $state );
\WPSSB\Job\Driver::ensureFallback( 120 );
echo 'started ' . $state['id'] . "\n";

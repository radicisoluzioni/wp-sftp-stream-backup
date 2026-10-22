<?php
/**
 * Configures the plugin (wp eval-file configure.php <password|key|badfp> [step_time] [retention]).
 *
 * @package WPSSB
 */

$mode      = isset( $args[0] ) ? $args[0] : 'password';
$step_time = isset( $args[1] ) ? (int) $args[1] : 0;
$retention = isset( $args[2] ) ? (int) $args[2] : 2;

$current     = \WPSSB\Settings::get();
$fingerprint = $current['fingerprint'];
if ( 'badfp' === $mode ) {
	update_option( 'wpssb_test_realfp', $fingerprint );
	$fingerprint = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
} elseif ( get_option( 'wpssb_test_realfp' ) ) {
	$fingerprint = get_option( 'wpssb_test_realfp' );
	delete_option( 'wpssb_test_realfp' );
}
$input = array(
	'host'          => 'sftp',
	'port'          => 22,
	'username'      => 'backup',
	'auth'          => 'key' === $mode ? 'key' : 'password',
	'password'      => 'key' === $mode ? '' : 'secret',
	'private_key'   => 'key' === $mode ? file_get_contents( '/keys/id_ed25519' ) : '',
	'fingerprint'   => $fingerprint,
	'remote_path'   => 'upload/wp-backups',
	'schedule'      => 'off',
	'retention'     => $retention,
	'exclude_paths' => \WPSSB\Settings::defaultExcludes(),
	'compression'   => 6,
	'step_time'     => $step_time,
	'notify_on'     => 'never',
);
if ( 'key' === $mode ) {
	$input['clear_password'] = 1;
}
\WPSSB\Settings::save( \WPSSB\Settings::sanitize( $input ) );
$stored = get_option( 'wpssb_settings' );
echo 'configured: ' . $mode . ', password stored encrypted: ' . ( 0 === strpos( (string) $stored['password'], 'wpssb1:' ) || '' === $stored['password'] ? 'yes' : 'NO' ) . "\n";

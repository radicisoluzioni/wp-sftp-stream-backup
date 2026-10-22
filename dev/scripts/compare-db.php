<?php
/**
 * Compares source (wp) and restored (wp2/wp3) databases. Run with plain PHP:
 * php compare-db.php <restored-db> <expected-home-url>
 *
 * @package WPSSB
 */

mysqli_report( MYSQLI_REPORT_OFF );
$restored = $argv[1];
$new_url  = $argv[2];
$m        = mysqli_connect( 'db', 'root', 'root' );
$fail     = 0;
$check    = function ( $ok, $msg ) use ( &$fail ) {
	echo ( $ok ? '[ok]   ' : '[FAIL] ' ) . $msg . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$one = function ( $sql ) use ( $m ) {
	$r = $m->query( $sql );
	if ( ! $r ) {
		return 'ERR ' . $m->error;
	}
	$row = $r->fetch_row();
	return $row ? $row[0] : null;
};

// Tables untouched by the URL rewrite must be byte-identical.
foreach ( array( 'wp_wpssb_nopk', 'wp_wpssb_bin', 'wp_wpssb_uniq', 'wp_terms', 'wp_term_taxonomy' ) as $t ) {
	$a = $m->query( "CHECKSUM TABLE wp.$t" )->fetch_row()[1];
	$b = $m->query( "CHECKSUM TABLE $restored.$t" )->fetch_row()[1];
	$check( null !== $a && $a === $b, "checksum $t ($a / $b)" );
}
// Logging in rehashes the password, so only compare the stable user columns.
$users = 'SELECT GROUP_CONCAT(CONCAT(ID, user_login, user_email, user_registered) ORDER BY ID) FROM %s.wp_users';
$check( $one( sprintf( $users, 'wp' ) ) === $one( sprintf( $users, $restored ) ), 'users identical' );
$tables_src = $one( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp'" );
$tables_dst = $one( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$restored'" );
$check( $tables_src === $tables_dst, "table count $tables_src / $tables_dst" );
foreach ( array( 'wp_posts', 'wp_postmeta', 'wp_wpssb_composite' ) as $t ) {
	$a = $one( "SELECT COUNT(*) FROM wp.$t" );
	$b = $one( "SELECT COUNT(*) FROM $restored.$t" );
	$check( $a === $b, "rows $t ($a / $b)" );
}
$check( '0' === $one( "SELECT id FROM $restored.wp_wpssb_bin WHERE zero = 1" ), 'explicit zero AUTO_INCREMENT id preserved' );
$check( $one( "SELECT GROUP_CONCAT(ts ORDER BY id) FROM wp.wp_wpssb_bin" ) === $one( "SELECT GROUP_CONCAT(ts ORDER BY id) FROM $restored.wp_wpssb_bin" ), 'timestamps identical' );

$check( $new_url === $one( "SELECT option_value FROM $restored.wp_options WHERE option_name='home'" ), 'home URL rewritten' );
$check( $new_url === $one( "SELECT option_value FROM $restored.wp_options WHERE option_name='siteurl'" ), 'siteurl rewritten' );
$ser = $one( "SELECT option_value FROM $restored.wp_options WHERE option_name='wpssb_test_serialized'" );
$val = @unserialize( $ser );
$check( is_array( $val ) && $new_url . '/page' === $val['url'] && $new_url === $val['nested']['u'] && $new_url . '/wp-admin' === $val['obj']->site, 'serialized option rewritten and still valid' );
$json = json_decode( (string) $one( "SELECT option_value FROM $restored.wp_options WHERE option_name='wpssb_test_json'" ), true );
$check( is_array( $json ) && $new_url . '/j' === $json['u'], 'JSON escaped URL rewritten' );
$old = 'http://localhost:8081';
$check(
	$one( "SELECT COUNT(*) FROM wp.wp_posts WHERE post_content LIKE '%$old%'" ) === $one( "SELECT COUNT(*) FROM $restored.wp_posts WHERE post_content LIKE '%$new_url%'" ),
	'post content URLs rewritten'
);
if ( 0 === strpos( $new_url, $old ) ) {
	$double = $new_url . substr( $new_url, strlen( $old ) );
	$check( '0' === $one( "SELECT COUNT(*) FROM $restored.wp_options WHERE option_value LIKE '%$double%'" ) && '0' === $one( "SELECT COUNT(*) FROM $restored.wp_posts WHERE post_content LIKE '%$double%'" ), 'no URL replaced twice' );
} else {
	$check( '0' === $one( "SELECT COUNT(*) FROM $restored.wp_posts WHERE post_content LIKE '%$old%'" ), 'no old URL left in post content' );
}
$check( $one( "SELECT COUNT(*) FROM wp.wp_posts WHERE guid LIKE '%localhost:8081%'" ) === $one( "SELECT COUNT(*) FROM $restored.wp_posts WHERE guid LIKE '%localhost:8081%'" ), 'GUIDs left untouched' );
$check( null === $one( "SELECT option_value FROM $restored.wp_options WHERE option_name='wpssb_job'" ), 'backup job state not restored' );

exit( $fail ? 1 : 0 );

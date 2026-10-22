<?php
/**
 * Seeds tricky data in the source database (wp eval-file).
 *
 * @package WPSSB
 */

global $wpdb;
$p = $wpdb->prefix;

$wpdb->query( "DROP TABLE IF EXISTS {$p}wpssb_nopk, {$p}wpssb_bin, {$p}wpssb_composite, {$p}wpssb_uniq" );
$wpdb->query( "CREATE TABLE {$p}wpssb_uniq (code VARCHAR(40) NOT NULL, label VARCHAR(40) NULL, v TEXT, UNIQUE KEY code (code), KEY label (label)) DEFAULT CHARSET=utf8mb4" );
for ( $i = 0; $i < 2500; $i++ ) {
	$wpdb->insert(
		"{$p}wpssb_uniq",
		array(
			'code'  => sprintf( 'c-%05d-%s', $i, 0 === $i % 3 ? "o'k" : 'x' ),
			'label' => 0 === $i % 5 ? null : 'l' . ( $i % 17 ),
			'v'     => str_repeat( 'v', $i % 300 ),
		)
	);
}
$wpdb->query( "CREATE TABLE {$p}wpssb_nopk (label VARCHAR(255) NULL, body LONGTEXT NULL, n INT NULL) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE {$p}wpssb_bin (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, data LONGBLOB, ts TIMESTAMP NULL, amount DECIMAL(12,4), f DOUBLE, zero INT NOT NULL DEFAULT 0) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE {$p}wpssb_composite (a INT NOT NULL, b VARCHAR(20) NOT NULL, v TEXT, PRIMARY KEY (a, b)) DEFAULT CHARSET=utf8mb4" );

$tricky = array(
	"quote ' and \" and backslash \\ and ;\n newline and ;\r\n crlf",
	"emoji 😀🚀 and accents àèìòù ñ",
	"null byte \0 inside",
	"sql-ish '); DROP TABLE x; --",
	'%s %d placeholders',
	"ends with semicolon;",
);
for ( $i = 0; $i < 2500; $i++ ) {
	$wpdb->insert(
		"{$p}wpssb_nopk",
		array(
			'label' => 0 === $i % 7 ? null : 'row ' . $i,
			'body'  => $tricky[ $i % count( $tricky ) ] . ' #' . $i,
			'n'     => 0 === $i % 11 ? null : $i,
		)
	);
}
for ( $i = 0; $i < 300; $i++ ) {
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$p}wpssb_bin (data, ts, amount, f) VALUES (UNHEX(%s), %s, %s, %s)",
			bin2hex( random_bytes( 50 + $i * 37 ) ),
			gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 3600 ),
			( $i * 1.2345 ) . '',
			( $i / 7 ) . ''
		)
	);
}
// Explicit zero id requires NO_AUTO_VALUE_ON_ZERO, both here and on import.
$wpdb->query( "SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')" );
$wpdb->query( "INSERT INTO {$p}wpssb_bin (id, data, zero) VALUES (0, 0x00, 1)" );
for ( $a = 0; $a < 60; $a++ ) {
	foreach ( array( 'alpha', 'beta', 'gamma', "d'elta", 'eps' ) as $b ) {
		$wpdb->insert(
			"{$p}wpssb_composite",
			array(
				'a' => $a,
				'b' => $b,
				'v' => "value $a/$b " . home_url( '/c' ),
			)
		);
	}
}

update_option(
	'wpssb_test_serialized',
	array(
		'url'    => home_url( '/page' ),
		'nested' => array( 'u' => home_url(), 'n' => 5 ),
		'obj'    => (object) array( 'site' => site_url( '/wp-admin' ) ),
	)
);
update_option( 'wpssb_test_json', wp_json_encode( array( 'u' => home_url( '/j' ) ) ) );
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Links',
		'post_status'  => 'publish',
		'post_content' => 'Visit <a href="' . home_url( '/about' ) . '">about</a>.',
	)
);
update_post_meta( $post_id, 'wpssb_meta', array( 'link' => home_url( '/meta' ) ) );

echo "seeded\n";

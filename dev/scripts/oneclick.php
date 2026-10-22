<?php
/**
 * One-click restore integration helper (wp eval-file oneclick.php <mode> [args]).
 *
 * Modes:
 *   snapshot              records the DB facts that a rollback must bring back
 *   mutate                changes the DB after the backup
 *   verify                checks that the rollback restored the snapshot
 *   path <repository>     prints the newest complete backup path of a repository
 *   prepare <repo> <path> prints the runtime URL (token in the fragment)
 *   negative <repo> <path> checks the refusals of the admin entry points
 *
 * @package WPSSB
 */

use WPSSB\Restore\OneClick;

$mode = $args[0] ?? '';
$fail = function ( $msg ) {
	fwrite( STDERR, "[FAIL] $msg\n" );
	exit( 1 );
};
$facts = function () {
	global $wpdb;
	return array(
		'posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'marker' => (string) get_option( 'wpssb_oneclick_marker', '' ),
		'tables' => $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ),
		'users'  => (string) $wpdb->get_var( "SELECT GROUP_CONCAT(user_login ORDER BY ID) FROM {$wpdb->users}" ),
	);
};

if ( 'snapshot' === $mode ) {
	update_option( 'wpssb_oneclick_marker', 'before-backup', false );
	file_put_contents( '/tmp/oneclick-facts.json', json_encode( $facts() ) );
	echo "snapshot saved\n";
} elseif ( 'mutate' === $mode ) {
	global $wpdb;
	update_option( 'wpssb_oneclick_marker', 'after-backup', false );
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' LIMIT 25" );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$wpdb->query( "CREATE TABLE {$wpdb->prefix}oneclick_extra (id INT PRIMARY KEY)" );
	$wpdb->query( 'CREATE TABLE IF NOT EXISTS other_prefix_keep (id INT PRIMARY KEY)' );
	wp_insert_user( array( 'user_login' => 'intruder', 'user_pass' => wp_generate_password(), 'user_email' => 'intruder@example.com' ) );
	echo 'mutated: deleted ' . count( $ids ) . " posts\n";
} elseif ( 'verify' === $mode ) {
	global $wpdb;
	wp_cache_flush();
	$want = json_decode( (string) file_get_contents( '/tmp/oneclick-facts.json' ), true );
	$have = $facts();
	sort( $want['tables'] );
	sort( $have['tables'] );
	foreach ( array( 'posts', 'marker', 'tables', 'users' ) as $k ) {
		if ( $want[ $k ] !== $have[ $k ] ) {
			$fail( "$k differs: " . json_encode( $want[ $k ] ) . ' / ' . json_encode( $have[ $k ] ) );
		}
		echo "[ok] $k restored\n";
	}
	if ( ! $wpdb->get_var( "SHOW TABLES LIKE 'other_prefix_keep'" ) ) {
		$fail( 'table with another prefix was deleted' );
	}
	echo "[ok] tables of other prefixes preserved\n";
	$complete = array_filter(
		\WPSSB\Repositories::listing( 'legacy-sftp' ),
		function ( $r ) {
			return $r['complete'];
		}
	);
	if ( ! $complete ) {
		$fail( 'backup history lost by the rollback' );
	}
	echo "[ok] plugin history and settings kept\n";
} elseif ( 'path' === $mode ) {
	$rows = array_values(
		array_filter(
			\WPSSB\Repositories::listing( $args[1] ),
			function ( $r ) {
				return $r['complete'];
			}
		)
	);
	if ( ! $rows ) {
		$fail( 'no complete backup in ' . $args[1] );
	}
	echo $rows[0]['path'];
} elseif ( 'cleanup' === $mode ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS other_prefix_keep' );
	echo "other_prefix_keep dropped\n";
} elseif ( 'prepare' === $mode ) {
	$runtime = OneClick::prepareRollback( $args[1], $args[2], OneClick::expectedConfirmation() );
	echo $runtime['url'];
} elseif ( 'negative' === $mode ) {
	$expect = function ( $label, $fn, $needle ) use ( $fail ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			if ( false === stripos( $e->getMessage(), $needle ) ) {
				$fail( "$label: unexpected error " . $e->getMessage() );
			}
			echo "[ok] $label refused\n";
			return;
		}
		$fail( "$label accepted" );
	};
	$expect( 'wrong confirmation', function () use ( $args ) {
		OneClick::prepareRollback( $args[1], $args[2], 'example.org' );
	}, 'domain' );
	$expect( 'path traversal', function () use ( $args ) {
		OneClick::prepareRollback( $args[1], $args[2] . '/../x', OneClick::expectedConfirmation() );
	}, 'invalid' );
	$expect( 'unknown backup', function () use ( $args ) {
		OneClick::prepareRollback( $args[1], dirname( $args[2] ) . '/20000101-000000-nope', OneClick::expectedConfirmation() );
	}, 'history' );
	$expect( 'write-only File Drop', function () use ( $args ) {
		OneClick::migrationCode( 'drop-copy', $args[2], str_repeat( 'p', 12 ), 1 );
	}, 'File Drop' );
	$expect( 'short password', function () use ( $args ) {
		OneClick::migrationCode( $args[1], $args[2], 'short', 1 );
	}, '12' );
	$runtime = OneClick::prepareRollback( $args[1], $args[2], OneClick::expectedConfirmation() );
	$runtime_dir = ABSPATH . $runtime['dir'];
	OneClick::sweepRuntimes( rtrim( ABSPATH, '/' ), time() );
	if ( is_dir( $runtime_dir ) ) {
		$fail( 'never-started runtime not swept' );
	}
	echo "[ok] unused runtime swept\n";
} else {
	$fail( 'unknown mode' );
}

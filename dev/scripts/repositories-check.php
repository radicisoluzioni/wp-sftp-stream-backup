<?php
/** WordPress integration fixture: wp eval-file /scripts/repositories-check.php configure|verify|fault|recover */

use WPSSB\Repositories;
use WPSSB\Job\State;
use WPSSB\Remote\SegmentedStorage;
use WPSSB\Security\Secrets;

$mode = $args[0] ?? 'configure';
if ( 'large' === $mode ) {
	$path = ABSPATH . 'wp-content/uploads/wpssb-large-fixture.bin';
	$handle = fopen( $path, 'wb' );
	if ( false === $handle ) {
		throw new RuntimeException( 'Cannot create integration source fixture.' );
	}
	for ( $i = 0; $i < 512; $i++ ) {
		$chunk = random_bytes( 1048576 );
		if ( 1048576 !== fwrite( $handle, $chunk ) ) {
			throw new RuntimeException( 'Cannot write integration source fixture.' );
		}
	}
	fclose( $handle );
	echo 'source fixture: 512 MiB, sha256 ' . hash_file( 'sha256', $path ) . "\n";
} elseif ( 'configure' === $mode ) {
	$raw = Repositories::raw();
	$primary = $raw['items']['legacy-sftp'];
	$primary['enabled'] = true;
	$primary['base'] = 'upload/segmented';
	$primary['retention'] = 2;
	$copy = $primary;
	$copy['name'] = 'SFTP replica';
	$copy['base'] = 'upload/replica';
	$items = array( 'legacy-sftp' => $primary, 'sftp-copy' => $copy );
	if ( getenv( 'WPSSB_TEST_S3_ENDPOINT' ) ) {
		$s3 = Repositories::blank( 's3' );
		$s3['enabled'] = true;
		$s3['name'] = 'Local S3';
		$s3['endpoint'] = getenv( 'WPSSB_TEST_S3_ENDPOINT' );
		$s3['bucket'] = getenv( 'WPSSB_TEST_S3_BUCKET' );
		$s3['region'] = 'us-east-1';
		$s3['access_key'] = getenv( 'WPSSB_TEST_S3_ACCESS' );
		$s3['secret_key'] = Secrets::encrypt( getenv( 'WPSSB_TEST_S3_SECRET' ) );
		$items['s3-copy'] = $s3;
	}
	if ( getenv( 'WPSSB_TEST_NEXTCLOUD_URL' ) ) {
		$nc = Repositories::blank( 'nextcloud' );
		$nc['enabled'] = true;
		$nc['name'] = 'Nextcloud WebDAV';
		$nc['url'] = getenv( 'WPSSB_TEST_NEXTCLOUD_URL' );
		$nc['username'] = getenv( 'WPSSB_TEST_NEXTCLOUD_USER' );
		$nc['password'] = Secrets::encrypt( getenv( 'WPSSB_TEST_NEXTCLOUD_PASSWORD' ) );
		$nc['path'] = 'plugin-integration';
		$items['nc-copy'] = $nc;
	}
	if ( getenv( 'WPSSB_TEST_FILEDROP_URL' ) ) {
		$nc = Repositories::blank( 'nextcloud' );
		$nc['enabled'] = true;
		$nc['name'] = 'Nextcloud File Drop';
		$nc['public_url'] = Secrets::encrypt( getenv( 'WPSSB_TEST_FILEDROP_URL' ) );
		$nc['public_password'] = Secrets::encrypt( getenv( 'WPSSB_TEST_FILEDROP_PASSWORD' ) ?: '' );
		$items['drop-copy'] = $nc;
	}
	update_option( Repositories::OPTION, array( 'primary' => 'legacy-sftp', 'items' => $items ), false );
	echo 'configured repositories: ' . implode( ',', array_keys( $items ) ) . "\n";
} elseif ( 'fail-run' === $mode ) {
	add_filter( 'wpssb_repository_storage', function ( $storage, $id, $config ) {
		if ( 'sftp-copy' !== $id ) {
			return $storage;
		}
		return new class implements \WPSSB\Remote\ObjectStorage {
			public function capabilities(): array { return array( 'read' => true, 'list' => true, 'delete' => true, 'overwrite' => true ); }
			public function put( string $key, string $data ): void { throw new RuntimeException( 'Injected destination outage.' ); }
			public function get( string $key ): ?string { throw new RuntimeException( 'Injected destination outage.' ); }
			public function stat( string $key ): ?array { throw new RuntimeException( 'Injected destination outage.' ); }
			public function objects( string $prefix, ?string $cursor = null ): array { throw new RuntimeException( 'Injected destination outage.' ); }
			public function remove( string $key ): void { throw new RuntimeException( 'Injected destination outage.' ); }
			public function disconnect(): void {}
		};
	}, 10, 3 );
	$state = \WPSSB\Job\Runner::start( 'cli' );
	do {
		$result = \WPSSB\Job\Runner::step( 3, 'cli' );
		$state = $result['state'];
		if ( ( $state['retry_after'] ?? 0 ) > time() ) {
			sleep( $state['retry_after'] - time() );
		}
	} while ( 'running' === $state['status'] );
	if ( 'partial' !== $state['status'] || 'verified' !== $state['repositories']['legacy-sftp']['status'] || 'failed' !== $state['repositories']['sftp-copy']['status'] ) {
		throw new RuntimeException( 'Expected partial result with primary preserved: ' . json_encode( $state ) );
	}
	try {
		\WPSSB\Job\Runner::start( 'cli' );
		throw new LogicException( 'Pending primary was not protected.' );
	} catch ( RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'Retry or abandon' ) ) {
			throw $e;
		}
	}
	echo 'partial backup protected for retry: ' . $state['id'] . "\n";
} elseif ( 'drop-loss-run' === $mode ) {
	$state = State::load();
	if ( 'done' !== $state['status'] || ! isset( $state['repositories']['drop-copy'] ) ) {
		throw new RuntimeException( 'A completed File Drop backup is required.' );
	}
	$id = $state['id'];
	$generation = bin2hex( random_bytes( 8 ) );
	$state['repositories']['drop-copy']['status'] = 'failed';
	$state['repositories']['drop-copy']['generation'] = $generation;
	$state['repositories']['drop-copy']['cursor'] = array( 'stream' => 0, 'page' => 0, 'item' => 0 );
	$state['repositories']['drop-copy']['inflight'] = false;
	$state['status'] = 'partial';
	State::save( $state );
	$lose_ack = true;
	add_filter( 'wpssb_repository_storage', function ( $storage, $repo_id, $config ) use ( &$lose_ack ) {
		if ( 'drop-copy' !== $repo_id ) {
			return $storage;
		}
		$inner = new \WPSSB\Remote\NextcloudStorage( $config );
		return new class( $inner, $lose_ack ) implements \WPSSB\Remote\ObjectStorage {
			private $inner;
			private $lose_ack;
			public function __construct( $inner, &$lose_ack ) { $this->inner = $inner; $this->lose_ack =& $lose_ack; }
			public function capabilities(): array { return $this->inner->capabilities(); }
			public function put( string $key, string $data ): void {
				$this->inner->put( $key, $data );
				if ( $this->lose_ack ) {
					$this->lose_ack = false;
					throw new RuntimeException( 'Injected loss of acknowledgement after the server stored the part.' );
				}
			}
			public function get( string $key ): ?string { return $this->inner->get( $key ); }
			public function stat( string $key ): ?array { return $this->inner->stat( $key ); }
			public function objects( string $prefix, ?string $cursor = null ): array { return $this->inner->objects( $prefix, $cursor ); }
			public function remove( string $key ): void { $this->inner->remove( $key ); }
			public function disconnect(): void { $this->inner->disconnect(); }
		};
	}, 10, 3 );
	\WPSSB\Job\Replication::retry();
	do {
		$state = \WPSSB\Job\Runner::step( 3, 'cli' )['state'];
		if ( ( $state['retry_after'] ?? 0 ) > time() ) {
			sleep( $state['retry_after'] - time() );
		}
	} while ( 'running' === $state['status'] );
	if ( 'done' !== $state['status'] || $id !== $state['id'] || $generation === $state['repositories']['drop-copy']['generation'] || 'accepted' !== $state['repositories']['drop-copy']['status'] ) {
		throw new RuntimeException( 'File Drop lost acknowledgement did not recover safely.' );
	}
	echo "Lost File Drop acknowledgement recovered in a new generation; original backup ID unchanged.\n";
} elseif ( 'http-diagnostics' === $mode ) {
	$state = State::load();
	$r = Repositories::get()['items']['nc-copy'];
	$url = $r['url'] . '/remote.php/dav/files/' . rawurlencode( $r['username'] ) . '/' . $r['path'] . '/' . $r['base'] . '/' . $state['id'] . '/database.sql.gz.index-00000000.json';
	foreach ( array( false, true ) as $identity ) {
		$options = array( 'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $r['username'] . ':' . $r['password'] ) ) );
		if ( $identity ) {
			$options['headers']['Accept-Encoding'] = 'identity';
			$options['decompress'] = false;
		}
		$response = wp_remote_get( $url, $options );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Diagnostic request failed.' );
		}
		echo json_encode( array(
			'identity' => $identity, 'code' => wp_remote_retrieve_response_code( $response ),
			'encoding' => wp_remote_retrieve_header( $response, 'content-encoding' ),
			'length' => wp_remote_retrieve_header( $response, 'content-length' ),
			'body_bytes' => strlen( wp_remote_retrieve_body( $response ) ),
		) ) . "\n";
	}
} elseif ( 'verify' === $mode ) {
	$state = State::load();
	if ( 'done' !== $state['status'] ) {
		throw new RuntimeException( 'Expected completed job: ' . json_encode( State::summary( $state ) ) );
	}
	$source = Repositories::storage( $state['primary'] );
	$all = Repositories::get();
	foreach ( $state['repositories'] as $id => $r ) {
		if ( $r['public'] ) {
			if ( 'accepted' !== $r['status'] ) {
				throw new RuntimeException( 'File Drop not accepted.' );
			}
			if ( ! getenv( 'WPSSB_TEST_FILEDROP_OWNER_PATH' ) ) {
				continue;
			}
			$owner = $all['items']['nc-copy'];
			$owner['path'] = getenv( 'WPSSB_TEST_FILEDROP_OWNER_PATH' );
			$target = new \WPSSB\Remote\NextcloudStorage( $owner );
			$target_prefix = $state['id'] . '--' . $r['generation'] . '--';
		} else {
			$target = Repositories::storage( $id );
			$target_prefix = $r['base'] . '/' . $state['id'] . '/';
		}
		$count = 0;
		foreach ( array( 'database.sql.gz', 'files.tar.gz' ) as $file ) {
			$head = SegmentedStorage::decode( $source->get( $state['remote_dir'] . '/' . $file . '.parts.json' ) );
			for ( $page = 0; $page < ceil( $head['count'] / 256 ); $page++ ) {
				$index = SegmentedStorage::decode( $source->get( SegmentedStorage::pageName( $state['remote_dir'] . '/' . $file, $page ) ) );
				foreach ( $index['parts'] as $part ) {
					$data = $target->get( $target_prefix . $part['file'] );
					if ( null === $data || strlen( $data ) > 8388608 || ! hash_equals( $part['sha256'], hash( 'sha256', $data ) ) ) {
						throw new RuntimeException( 'Replica bytes differ: ' . $id . '/' . $part['file'] );
					}
					$count++;
				}
			}
		}
		echo $id . ': ' . $count . " parts identical\n";
		$target->disconnect();
	}
	$source->disconnect();
	echo "all readable copies verified\n";
}

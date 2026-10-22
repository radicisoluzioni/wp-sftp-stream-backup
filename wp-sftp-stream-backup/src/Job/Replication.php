<?php
namespace WPSSB\Job;

use WPSSB\Repositories;
use WPSSB\Remote\SegmentedStorage;

class Replication {
	public static function configured(): bool {
		return null !== get_option( Repositories::OPTION, null ) || defined( 'WPSSB_REPOSITORIES' );
	}

	public static function initialize( array &$state ): void {
		$all = Repositories::get();
		$active = Repositories::active();
		$primary = $all['primary'];
		if ( ! isset( $active[ $primary ] ) ) {
			throw new \RuntimeException( 'Choose an enabled, readable primary repository.' );
		}
		$state['primary'] = $primary;
		$state['format'] = 2;
		$state['repositories'] = array();
		foreach ( $active as $id => $r ) {
			Repositories::validate( $r );
			$state['repositories'][ $id ] = array(
				'name' => $r['name'], 'base' => $r['base'], 'retention' => (int) $r['retention'],
				'public' => 'nextcloud' === $r['type'] && '' !== $r['public_url'],
				'revision' => Repositories::revision( $r ),
				'status' => 'pending', 'error' => '', 'attempts' => 0,
				'generation' => bin2hex( random_bytes( 8 ) ),
				'cursor' => array( 'stream' => 0, 'page' => 0, 'item' => 0 ),
				'inflight' => false, 'bytes' => 0,
			);
		}
		if ( $state['repositories'][ $primary ]['public'] ) {
			throw new \RuntimeException( 'File Drop cannot be the primary repository.' );
		}
		$state['base'] = $active[ $primary ]['base'];
		$state['remote_dir'] = $state['base'] . '/' . $state['id'];
	}

	/** One object at a time; never enumerate the entire backup in PHP. */
	private static function next( SegmentedStorage $source, array $state, array $cursor ): ?array {
		$dir = $state['remote_dir'] . '/';
		$names = array( DbPhase::FILE, FilesPhase::FILE );
		if ( $cursor['stream'] < 2 ) {
			$name = $names[ $cursor['stream'] ];
			$head_data = $source->getContents( $dir . $name . '.parts.json' );
			$head = SegmentedStorage::decode( $head_data );
			$pages = (int) ceil( $head['count'] / SegmentedStorage::PAGE_PARTS );
			if ( $cursor['page'] < $pages ) {
				$page_name = basename( SegmentedStorage::pageName( $name, $cursor['page'] ) );
				$json = $source->getContents( $dir . $page_name );
				$page = SegmentedStorage::decode( $json );
				$expected = $head['sha256'];
				if ( $cursor['page'] + 1 < $pages ) {
					$following = SegmentedStorage::decode( $source->getContents( $dir . SegmentedStorage::pageName( $name, $cursor['page'] + 1 ) ) );
					$expected = $following['previous'];
				}
				if ( ! hash_equals( $expected, hash( 'sha256', (string) $json ) ) ) {
					throw new FatalJobException( 'Primary catalog checksum mismatch.' );
				}
				if ( $cursor['item'] < count( $page['parts'] ) ) {
					$part = $page['parts'][ $cursor['item'] ];
					$cursor['item']++;
					return array( 'name' => $part['file'], 'hash' => $part['sha256'], 'size' => $part['size'], 'cursor' => $cursor );
				}
				$cursor['page']++;
				$cursor['item'] = 0;
				return array( 'name' => $page_name, 'hash' => $expected, 'size' => strlen( $json ), 'cursor' => $cursor );
			}
			$cursor['stream']++;
			$cursor['page'] = 0;
			$cursor['item'] = 0;
			return array( 'name' => $name . '.parts.json', 'hash' => hash( 'sha256', $head_data ), 'size' => strlen( $head_data ), 'cursor' => $cursor );
		}
		if ( $cursor['stream'] < 4 ) {
			$name = 2 === $cursor['stream'] ? 'restore.php' : 'manifest.json';
			$cursor['stream']++;
			$data = $source->getContents( $dir . $name );
			if ( null === $data ) {
				throw new FatalJobException( 'Primary backup metadata disappeared: ' . $name );
			}
			return array( 'name' => $name, 'hash' => hash( 'sha256', $data ), 'size' => strlen( $data ), 'cursor' => $cursor );
		}
		return null;
	}

	public static function run( array $state, SegmentedStorage $source, float $deadline ): array {
		$state['retry_after'] = 0;
		foreach ( $state['repositories'] as $id => &$r ) {
			if ( in_array( $r['status'], array( 'verified', 'accepted', 'failed', 'abandoned' ), true ) ) {
				continue;
			}
			if ( ( $r['next_attempt'] ?? 0 ) > time() ) {
				continue;
			}
			$target = null;
			try {
				$target = Repositories::storage( $id );
				$primary = $id === $state['primary'];
				$caps = $target->capabilities();
				if ( $r['public'] && $r['inflight'] ) {
					$r['generation'] = bin2hex( random_bytes( 8 ) );
					$r['cursor'] = array( 'stream' => 0, 'page' => 0, 'item' => 0 );
					$r['bytes'] = 0;
					$r['inflight'] = false;
					State::log( $state, 'warning', 'File Drop acknowledgement was lost; restarting replica with unique names. Old parts may require manual cleanup.' );
				}
				$r['status'] = 'copying';
				while ( microtime( true ) < $deadline ) {
					$object = self::next( $source, $state, $r['cursor'] );
					if ( null === $object ) {
						$r['status'] = $caps['read'] ? 'verified' : 'accepted';
						$r['error'] = '';
						State::log( $state, 'info', $r['name'] . ': ' . $r['status'] );
						State::save( $state );
						break;
					}
					$name = $object['name'];
					if ( basename( $name ) !== $name || false !== strpos( $name, '..' ) ) {
						throw new FatalJobException( 'Invalid catalog object name.' );
					}
					$data = $source->getContents( $state['remote_dir'] . '/' . $name );
					if ( null === $data || strlen( $data ) !== $object['size'] || ! hash_equals( $object['hash'], hash( 'sha256', $data ) ) ) {
						throw new FatalJobException( 'Primary object checksum mismatch: ' . $name );
					}
					$prefix = $r['public'] ? $state['id'] . '--' . $r['generation'] . '--' : '';
					$key = $r['public'] ? $prefix . $name : $r['base'] . '/' . $state['id'] . '/' . $name;
					if ( 'manifest.json' === $name && '' !== $prefix ) {
						$manifest = SegmentedStorage::decode( $data );
						$manifest['object_prefix'] = $prefix;
						$data = SegmentedStorage::json( $manifest );
					}
					$r['inflight'] = true;
					State::save( $state );
					if ( ! $primary ) {
						$target->put( $key, $data );
						if ( $caps['read'] ) {
							$check = $target->get( $key );
							if ( null === $check || ! hash_equals( hash( 'sha256', $data ), hash( 'sha256', $check ) ) ) {
								throw new \RuntimeException( 'Replica read-back checksum mismatch.' );
							}
						}
					}
					$r['cursor'] = $object['cursor'];
					$r['bytes'] += strlen( $data );
					$r['inflight'] = false;
					$r['attempts'] = 0;
					State::save( $state );
					unset( $data, $check );
				}
			} catch ( FatalJobException $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				$r['attempts']++;
				$r['error'] = $e->getMessage();
				$r['next_attempt'] = time() + min( 60, 2 ** $r['attempts'] );
				if ( $r['attempts'] >= Runner::MAX_ERRORS ) {
					$r['status'] = 'failed';
				}
				State::log( $state, 'warning', $r['name'] . ': ' . $r['error'] );
				State::save( $state );
			} finally {
				if ( $target ) {
					$target->disconnect();
				}
			}
			if ( microtime( true ) >= $deadline ) {
				return $state;
			}
		}
		unset( $r );
		$retry_after = PHP_INT_MAX;
		foreach ( $state['repositories'] as $r ) {
			if ( in_array( $r['status'], array( 'pending', 'copying' ), true ) ) {
				$retry_after = min( $retry_after, (int) ( $r['next_attempt'] ?? 0 ) );
			}
		}
		if ( PHP_INT_MAX !== $retry_after ) {
			$state['retry_after'] = $retry_after;
			return $state;
		}
		$state['phase'] = 'retention';
		return $state;
	}

	public static function retry(): array {
		$lock = Lock::acquire( 120 );
		if ( null === $lock ) {
			throw new \RuntimeException( 'A backup step is busy.' );
		}
		try {
			$state = State::load();
			if ( ! $state || 'partial' !== $state['status'] || empty( $state['repositories'] ) ) {
				throw new \RuntimeException( 'No partial multi-repository backup to retry.' );
			}
			foreach ( $state['repositories'] as &$r ) {
				if ( 'failed' === $r['status'] ) {
					$r['status'] = 'pending';
					$r['attempts'] = 0;
					$r['next_attempt'] = 0;
				}

			}
			unset( $r );
			$state['status'] = 'running';
			$state['phase'] = 'replicate';
			$state['errors'] = 0;
			$state['last_error'] = '';
			$state['updated_at'] = time();
			State::save( $state );
			return $state;
		} finally {
			Lock::release( $lock );
		}
	}

	public static function abandon(): void {
		$lock = Lock::acquire( 120 );
		if ( null === $lock ) {
			throw new \RuntimeException( 'A backup step is busy.' );
		}
		try {
			$state = State::load();
			if ( ! $state || 'partial' !== $state['status'] || empty( $state['repositories'] ) ) {
				throw new \RuntimeException( 'No pending replicas to abandon.' );
			}
			foreach ( $state['repositories'] as &$r ) {
				if ( 'failed' === $r['status'] ) {
					$r['status'] = 'abandoned';
				}
			}
			unset( $r );
			$state['status'] = 'cancelled';
			State::log( $state, 'warning', 'Pending replicas abandoned. Completed copies preserved; partial uploads may need manual cleanup.' );
			State::save( $state );
			State::addHistory( $state );
		} finally {
			Lock::release( $lock );
		}
	}

	public static function retention( array &$state ): void {
		foreach ( $state['repositories'] as $id => $r ) {
			if ( ! in_array( $r['status'], array( 'verified', 'accepted' ), true ) || 0 === $r['retention'] ) {
				continue;
			}
			if ( $r['public'] ) {
				State::log( $state, 'warning', $r['name'] . ': File Drop retention is unavailable; delete old copies from the owner account.' );
				continue;
			}
			$objects = null;
			try {
				$objects = Repositories::storage( $id );
				$kept = 1;
				foreach ( State::history() as $backup ) {
					if ( ! preg_match( Retention::sitePattern( Runner::siteSlug() ), $backup['id'] ) ) {
						continue;
					}
					$old = $backup['repositories'][ $id ] ?? null;
					if ( $backup['id'] === $state['id'] || ! $old || ! in_array( $old['status'], array( 'verified', 'accepted' ), true ) ) {
						continue;
					}
					if ( ( $old['revision'] ?? '' ) !== $r['revision'] ) {
						State::log( $state, 'warning', $r['name'] . ': retention skipped an older repository configuration.' );
						continue;
					}
					$deleting = null !== $objects->stat( $old['base'] . '/' . $backup['id'] . '/manifest.deleting.json' );
					if ( ! $deleting && null === $objects->stat( $old['base'] . '/' . $backup['id'] . '/manifest.json' ) ) {
						continue;
					}
					if ( ! $deleting && $kept++ < $r['retention'] ) {
						continue;
					}
					$storage = new SegmentedStorage( $objects );
					$storage->delete( $old['base'] . '/' . $backup['id'] );
					State::log( $state, 'info', $r['name'] . ': retention removed ' . $backup['id'] );
				}
			} catch ( \Throwable $e ) {
				State::log( $state, 'warning', $r['name'] . ': retention failed: ' . $e->getMessage() );
			} finally {
				if ( $objects ) {
					$objects->disconnect();
				}
			}
		}
	}
}

<?php
/** Runs repository migration and settings assertions inside the test WordPress. */

use WPSSB\Repositories;
use WPSSB\Settings;
use WPSSB\Job\State;

if ( Repositories::locked() ) {
	throw new RuntimeException( 'Finish the test backup before testing settings.' );
}
$original = get_option( Repositories::OPTION, null );
$state = State::load();
$assertions = 0;
$check = function ( $ok, $message ) use ( &$assertions ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	$assertions++;
};
$post = function ( array $raw ): array {
	foreach ( $raw['items'] as &$item ) {
		foreach ( Repositories::SECRETS as $field ) {
			unset( $item[ $field ] );
		}
	}
	unset( $item );
	return $raw;
};
try {
	delete_option( Repositories::OPTION );
	$legacy = Repositories::raw();
	$check( $legacy === Repositories::raw(), 'Legacy migration is not deterministic.' );
	$check( Settings::raw()['password'] === $legacy['items']['legacy-sftp']['password'], 'Migration changed encrypted password.' );
	$check( Settings::get()['password'] === Repositories::get()['items']['legacy-sftp']['password'], 'Migration changed effective password.' );
	$input = $post( $legacy );
	$input['add_type'] = 's3';
	Repositories::save( $input );
	$next = Repositories::raw();
	$check( 2 === count( $next['items'] ), 'Adding repository lost the migrated repository.' );
	$check( $next['items']['legacy-sftp']['password'] === $legacy['items']['legacy-sftp']['password'], 'Blank secret fields did not preserve ciphertext.' );
	$id = array_values( array_diff( array_keys( $next['items'] ), array( 'legacy-sftp' ) ) )[0];
	$input = $post( $next );
	$input['items'][ $id ]['provider'] = 'hetzner';
	$input['items'][ $id ]['location'] = 'fsn1';
	$input['items'][ $id ]['secret_key'] = 'integration-only-secret';
	Repositories::save( $input );
	$effective = Repositories::get()['items'][ $id ];
	$check( 'https://fsn1.your-objectstorage.com' === $effective['endpoint'], 'Hetzner endpoint preset is wrong.' );
	$check( 'fsn1' === $effective['region'], 'Hetzner signing region preset is wrong.' );
	$check( 'integration-only-secret' === $effective['secret_key'], 'S3 secret did not roundtrip.' );
	$check( 'integration-only-secret' !== Repositories::raw()['items'][ $id ]['secret_key'], 'S3 secret stored as plaintext.' );
	$input = $post( Repositories::raw() );
	$input['items'][ $id ]['clear_secret_key'] = 1;
	Repositories::save( $input );
	$check( '' === Repositories::get()['items'][ $id ]['secret_key'], 'Explicit clearing failed.' );
	update_option( State::OPTION, array( 'status' => 'running' ), false );
	try {
		Repositories::save( $input );
		throw new LogicException( 'Running backup did not lock settings.' );
	} catch ( RuntimeException $e ) {
		$check( false !== strpos( $e->getMessage(), 'before editing' ), 'Unexpected configuration lock error.' );
	}
	echo $assertions . " repository settings assertions passed\n";
} finally {
	if ( null === $original ) {
		delete_option( Repositories::OPTION );
	} else {
		update_option( Repositories::OPTION, $original, false );
	}
	if ( null === $state ) {
		delete_option( State::OPTION );
	} else {
		update_option( State::OPTION, $state, false );
	}
}

<?php
/**
 * Raised when the SFTP host key is unknown or does not match. No credentials
 * have been sent to the server when this is thrown.
 *
 * @package WPSSB
 */

namespace WPSSB\Remote;

class HostKeyException extends RemoteException {

	/** @var string */
	private $fingerprint;

	public function __construct( string $fingerprint, string $message ) {
		parent::__construct( $message );
		$this->fingerprint = $fingerprint;
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}
}

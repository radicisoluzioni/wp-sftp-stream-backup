<?php
namespace WPSSB\Remote;

interface ObjectStorage {
	/** @return array{read:bool,list:bool,delete:bool,overwrite:bool} */
	public function capabilities(): array;
	/** Payloads are bounded by the caller; implementations must never spool to disk. */
	public function put( string $key, string $data ): void;
	/** Missing objects return null; authorization and transport errors throw. */
	public function get( string $key ): ?string;
	/** @return array{size:int,mtime:int}|null */
	public function stat( string $key ): ?array;
	/** @return array{items:array<string,array{size:int,mtime:int}>,next:?string} */
	public function objects( string $prefix, ?string $cursor = null ): array;
	public function remove( string $key ): void;
	public function disconnect(): void;
}

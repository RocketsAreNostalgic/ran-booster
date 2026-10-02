<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RuntimeException;

/**
 * One locally downloaded archive whose identity is frozen for mutation.
 */
final class PreparedArtifact implements RepositoryReleaseArtifactCustody {

	private bool $cleaned = false;

	public function __construct(
		private readonly string $path,
		private readonly string $resolved_ref,
		private readonly string $expected_version,
		private readonly string $digest,
		private readonly int $device,
		private readonly int $inode,
		private readonly int $size,
		private readonly int $permissions,
		private readonly int $links,
		private readonly ?string $owned_directory = null
	) {
		if ( '' === $path
			|| '' === $resolved_ref
			|| strlen( $resolved_ref ) > 191
			|| preg_match( '/[[:cntrl:]]/', $resolved_ref ) === 1
			|| strlen( $expected_version ) > 64
			|| preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $expected_version ) !== 1
			|| preg_match( '/^[a-f0-9]{64}$/D', $digest ) !== 1
			|| $device < 0
			|| $inode <= 0
			|| $size < 0
			|| 0600 !== $permissions
			|| 1 !== $links
			|| ( null !== $owned_directory && ! self::private_directory_for_path( $owned_directory, $path ) ) ) {
			throw new RuntimeException( 'The prepared deployment artifact identity is invalid.' );
		}
	}
	public function get_path(): string {
		return $this->path;
	}
	public function get_resolved_ref(): string {
		return $this->resolved_ref;
	}
	public function get_expected_version(): string {
		return $this->expected_version;
	}

	/** @param callable(string): mixed $inspection */
	public function inspect( callable $inspection ): mixed {
		$this->assert_unchanged();

		return $inspection( $this->path );
	}

	public function discard(): bool {
		$this->cleanup();

		return true;
	}
	public function resolved_ref(): string {
		return $this->resolved_ref;
	}

	public function version(): string {
		return $this->expected_version;
	}

	public function size(): int {
		return $this->size;
	}

	public function sha256(): string {
		return $this->digest;
	}

	/**
	 * Prove that the caller is about to use the exact downloaded bytes.
	 */
	public function assert_unchanged(): void {
		if ( $this->cleaned ) {
			throw new RuntimeException( 'The prepared deployment artifact has already been cleaned up.' );
		}
		if ( ! $this->has_original_identity() ) {
			BoosterLogger::log(
				'artifact integrity check failed before use',
				array(
					'step'         => 'artifact_identity_changed',
					'resolved_ref' => $this->resolved_ref,
				)
			);
			throw new RuntimeException( 'The prepared deployment artifact changed before use.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- The digest is the immutable deployment boundary.
		$digest = hash_file( 'sha256', $this->path );
		if ( ! is_string( $digest ) || ! hash_equals( $this->digest, $digest ) ) {
			BoosterLogger::log(
				'artifact integrity digest check failed before use',
				array(
					'step'         => 'artifact_digest_changed',
					'resolved_ref' => $this->resolved_ref,
				)
			);
			throw new RuntimeException( 'The prepared deployment artifact changed before use.' );
		}
	}

	/**
	 * Delete only the unchanged file owned by this artifact.
	 */
	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		}
		$this->assert_unchanged();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- This removes one identity-checked private temporary file.
		if ( ! unlink( $this->path ) ) {
			throw new RuntimeException( 'The prepared deployment artifact could not be removed safely.' );
		}
		clearstatcache( true, $this->path );
		if ( file_exists( $this->path ) || is_link( $this->path ) ) {
			throw new RuntimeException( 'The prepared deployment artifact could not be removed safely.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- This removes the exact empty Core-owned temporary directory. Retain the promoted constructor or external DTO property contract.
		if ( null !== $this->owned_directory && ! rmdir( $this->owned_directory ) ) {
			throw new RuntimeException( 'The prepared deployment artifact could not be removed safely.' );
		}

		$this->cleaned = true;
	}

	private function has_original_identity(): bool {
		$identity = self::regular_file_identity( $this->path );

		return null !== $identity
			&& $identity['device'] === $this->device
			&& $identity['inode'] === $this->inode
			&& $identity['size'] === $this->size
			&& $identity['permissions'] === $this->permissions
			&& $identity['links'] === $this->links;
	}

	private static function private_directory_for_path( string $directory, string $path ): bool {
		if ( dirname( $path ) !== $directory || is_link( $directory ) || ! is_dir( $directory ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- The Core-owned temporary directory must not be replaced.
		$stat = lstat( $directory );
		if ( false === $stat ) {
			return false;
		}

		return 0040000 === ( (int) ( $stat['mode'] ?? 0 ) & 0170000 )
			&& 0700 === ( (int) ( $stat['mode'] ?? 0 ) & 0777 );
	}

	/**
	 * @return array{device: int, inode: int, size: int, permissions: int, links: int}|null
	 */
	public static function regular_file_identity( string $path ): ?array {
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Symlink-aware identity is required before WordPress receives the archive.
		$stat = lstat( $path );
		if ( false === $stat || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$mode = (int) ( $stat['mode'] ?? 0 );
		if ( 0100000 !== ( $mode & 0170000 ) ) {
			return null;
		}

		return array(
			'device'      => (int) ( $stat['dev'] ?? -1 ),
			'inode'       => (int) ( $stat['ino'] ?? 0 ),
			'size'        => (int) ( $stat['size'] ?? -1 ),
			'permissions' => $mode & 0777,
			'links'       => (int) ( $stat['nlink'] ?? 0 ),
		);
	}
}

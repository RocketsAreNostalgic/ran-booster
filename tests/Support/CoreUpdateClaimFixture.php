<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Focused stand-in for the updater's typed Core handoff contract.
 *
 * Core still pins the last released updater during this branch integration, so
 * this fixture lets its unit tests exercise the already-landed next contract
 * without changing the vendored dependency ahead of release sequencing.
 */
final class CoreUpdateClaimFixture {

	public static int $digestChecks = 0;

	private bool $accepted = false;

	private ?bool $discardResult = null;

	/** @param array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int} $identity */
	private function __construct(
		private readonly string $path,
		private readonly string $sha256,
		private readonly string $targetType,
		private readonly string $targetIdentifier,
		private readonly string $expectedVersion,
		private readonly array $identity
	) {
	}

	public static function reset(): void {
		self::$digestChecks = 0;
	}

	public static function forCoreUpdate(
		string $path,
		string $sha256,
		string $targetType,
		string $targetIdentifier,
		string $expectedVersion
	): self {
		$identity = self::fileIdentity( $path );
		if ( null === $identity ) {
			throw new RuntimeException( 'The Core update artifact claim is invalid.' );
		}

		return new self( $path, $sha256, $targetType, $targetIdentifier, $expectedVersion, $identity );
	}

	public function path(): string {
		return $this->path;
	}

	public function acceptCoreUpdate(
		string $targetType,
		string $targetIdentifier,
		string $action,
		string $path
	): string {
		if ( $this->accepted
			|| 'update' !== $action
			|| ! hash_equals( $this->targetType, $targetType )
			|| ! hash_equals( $this->targetIdentifier, $targetIdentifier )
			|| ! hash_equals( $this->path, $path )
		) {
			throw new RuntimeException( 'The Core update artifact claim does not match this operation.' );
		}

		++self::$digestChecks;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test fixture models the updater's final digest proof.
		$digest = hash_file( 'sha256', $this->path );
		if ( ! is_string( $digest ) || ! hash_equals( $this->sha256, $digest ) ) {
			throw new RuntimeException( 'The claimed artifact changed after custody transfer.' );
		}
		$this->accepted = true;

		return $this->expectedVersion;
	}

	public function discard(): bool {
		if ( null !== $this->discardResult ) {
			return $this->discardResult;
		}

		if ( self::fileIdentity( $this->path ) !== $this->identity ) {
			$this->discardResult = false;

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture deletes one private temporary file.
		$this->discardResult = unlink( $this->path );

		return $this->discardResult;
	}

	/** @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int}|null */
	private static function fileIdentity( string $path ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Test fixture freezes the updater contract's local identity.
		$stat = lstat( $path );
		if ( false === $stat ) {
			return null;
		}

		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}
}

if ( ! class_exists( 'RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact', false ) ) {
	class_alias(
		CoreUpdateClaimFixture::class,
		'RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact'
	);
}

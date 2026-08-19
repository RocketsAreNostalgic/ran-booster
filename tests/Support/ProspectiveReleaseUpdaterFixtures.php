<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Updater-runtime fixtures share one isolated namespace.

/**
 * Focused stand-in for the optional updater runtime, which is not loaded by
 * Composer during Core PHPUnit tests.
 */
final class ReleaseCandidatePreflight {

	public const PROSPECTIVE_API_VERSION = 4;

	public static mixed $discovery   = null;
	public static mixed $candidates  = null;
	public static mixed $inspection  = null;
	public static mixed $acquired    = null;
	public static mixed $check       = null;
	public static int $discoverCalls = 0;
	public static int $listCalls     = 0;
	public static int $inspectCalls  = 0;
	public static int $acquireCalls  = 0;

	/** @var array<string, mixed> */
	public static array $target = array();

	/** @param array<string, mixed> $target */
	public static function fromProspectiveTarget( array $target ): self {
		self::$target = $target;

		return new self();
	}

	/** @param array<string, mixed> $target */
	public static function fromTarget( array $target ): self {
		self::$target = $target;

		return new self();
	}

	public static function reset(): void {
		self::$discovery     = null;
		self::$candidates    = null;
		self::$inspection    = null;
		self::$acquired      = null;
		self::$check         = null;
		self::$discoverCalls = 0;
		self::$listCalls     = 0;
		self::$inspectCalls  = 0;
		self::$acquireCalls  = 0;
		self::$target        = array();
	}

	public function discover(): mixed {
		++self::$discoverCalls;

		return self::$discovery;
	}

	public function check( bool $force = false ): mixed {
		unset( $force );

		return self::$check;
	}

	public function listCandidates(): mixed {
		++self::$listCalls;

		return self::$candidates;
	}

	public function inspectExact( int $releaseId, string $tag ): mixed {
		unset( $releaseId, $tag );
		++self::$inspectCalls;

		return self::$inspection;
	}

	public function acquireExact(
		int $releaseId,
		string $tag,
		ReleaseFingerprint $fingerprint
	): mixed {
		unset( $releaseId, $tag );
		++self::$acquireCalls;
		if ( self::$inspection instanceof ProspectiveInspectionFixture
			&& ! hash_equals( self::$inspection->fingerprint()->value(), $fingerprint->value() ) ) {
			return new \WP_Error(
				'github_updater_artifact_continuity_failed',
				'The selected release changed.'
			);
		}

		return self::$acquired;
	}
}

final readonly class ManagedCandidateValidationFixture {

	public function __construct( private string $code ) {
	}

	public function code(): string {
		return $this->code;
	}

	public function releaseVersion(): string {
		return '1.2.3';
	}

	public function releaseTag(): string {
		return 'v1.2.3';
	}

	public function packageHeaderVersion(): ?string {
		return '1.2.3';
	}

	public function relationshipTo( string $installedVersion ): string {
		unset( $installedVersion );

		return 'same';
	}
}

final readonly class ReleaseFingerprint {

	private function __construct( private string $value ) {
	}

	public static function fromString( string $value ): self|\WP_Error {
		return 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $value )
			? new self( $value )
			: new \WP_Error( 'github_updater_invalid_release_fingerprint', 'The fingerprint is invalid.' );
	}

	public function value(): string {
		return $this->value;
	}
}

final readonly class ProspectiveFingerprintFixture {

	public function value(): string {
		return 'v1:' . str_repeat( 'a', 64 );
	}
}

final readonly class ProspectiveDiscoveryFixture {

	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version
	) {
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}
}

final readonly class ProspectiveCandidateFixture {

	/**
	 * @param list<string> $expectedAssetNames
	 */
	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version,
		private bool $prerelease,
		private string $publishedAt,
		private array $expectedAssetNames
	) {
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}

	public function isPrerelease(): bool {
		return $this->prerelease;
	}

	public function publishedAt(): string {
		return $this->publishedAt;
	}

	/** @return list<string> */
	public function expectedAssetNames(): array {
		return $this->expectedAssetNames;
	}
}

final readonly class ProspectiveInspectionFixture {

	public function __construct(
		private string $version = '1.2.3',
		private string $packageType = 'plugin'
	) {
	}

	public function releaseId(): int {
		return 42;
	}

	public function tag(): string {
		return 'v1.2.3';
	}

	public function version(): string {
		return $this->version;
	}

	public function commit(): string {
		return str_repeat( 'a', 40 );
	}

	public function detailsUrl(): string {
		return 'https://github.com/owner/example/releases/tag/v1.2.3';
	}

	public function packageType(): string {
		return $this->packageType;
	}

	public function packageRoot(): string {
		return 'example';
	}

	public function mainFile(): string {
		return 'example.php';
	}

	public function fingerprint(): ProspectiveFingerprintFixture {
		return new ProspectiveFingerprintFixture();
	}
}

final class ProspectiveAcquisitionFixture {

	public int $discardCalls   = 0;
	public int $handoffCalls   = 0;
	public bool $discardResult = true;
	public bool $discardThrows = false;
	private bool $handedOff    = false;

	public function __construct(
		private string $path,
		private ProspectiveInspectionFixture $inspection
	) {
	}

	public function inspection(): ProspectiveInspectionFixture {
		return $this->inspection;
	}

	public function discard(): bool {
		++$this->discardCalls;
		if ( $this->discardThrows ) {
			throw new \RuntimeException( 'Test-only cleanup failure.' );
		}
		if ( ! $this->discardResult ) {
			return false;
		}
		if ( ! $this->handedOff && is_file( $this->path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			unlink( $this->path );
		}

		return ! file_exists( $this->path );
	}

	public function handoffToCore(): \RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact|\WP_Error {
		++$this->handoffCalls;
		if ( $this->handedOff ) {
			return new \WP_Error( 'github_updater_artifact_already_claimed', 'The artifact was already claimed.' );
		}
		$this->handedOff = true;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only temporary artifact identity.
		$digest = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;
		if ( ! is_string( $digest ) ) {
			return new \WP_Error( 'github_updater_artifact_identity_changed', 'The artifact changed before handoff.' );
		}

		return \RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact::forCoreUpdate(
			$this->path,
			$digest,
			'plugin',
			'example/example.php',
			'1.2.3'
		);
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

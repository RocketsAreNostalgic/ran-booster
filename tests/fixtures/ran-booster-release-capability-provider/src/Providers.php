<?php

declare(strict_types=1);

namespace RANBoosterReleaseCapabilityFixture;

use RAN\Deployment\PreparedArtifact;
use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RuntimeException;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Installed fixture aggregates belong together.

interface FixturePrivateCapability extends ProviderCapability {
	public function private_value(): string;
}

abstract class BaseProvider implements RepositoryProvider {
	public function __construct( private readonly string $code ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'P2 fixture ' . $this->code, 'https://p2.invalid/', 'Owner' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor(
			ProviderCode::parse( $this->code ),
			$request->locator,
			basename( $request->locator ),
			sha1( $this->code . "\0" . $request->locator ),
			false,
			'main',
			null
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside the release-capability fixture.' );
	}
}

final class ZeroProvider extends BaseProvider implements FixturePrivateCapability {
	public function __construct() {
		parent::__construct( 'p2-zero' );
	}

	public function private_value(): string {
		return 'private';
	}
}

final class PartialProvider extends BaseProvider implements RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseNativeTargets {
	public function __construct() {
		parent::__construct( 'p2-partial' );
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		return 'https://p2.invalid/' . $repository->locator;
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return $this->expected_update_uri( $repository ) . '/releases/' . rawurlencode( $tag );
	}

	public function list_release_candidates( string $package_type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		unset( $package_type, $repository, $channel );

		update_option( 'ran_booster_p2_partial_called', true, false );

		return new RepositoryReleaseCandidateList( array() );
	}

	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
		unset( $package_type, $installed_identifier );

		return false;
	}

	public function create_native_target(
		string $package_type,
		RepositoryReference $repository,
		string $metadata_file,
		string $package_root,
		string $installed_identifier,
		string $channel,
		string $deployment_policy
	): RepositoryReleaseNativeTarget {
		unset( $package_type, $repository, $metadata_file, $package_root, $installed_identifier, $channel, $deployment_policy );

		throw new RuntimeException( 'The partial fixture must remain inert.' );
	}
}

final class ReleaseProvider extends BaseProvider implements RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseNativeTargets, FixturePrivateCapability {
	private const FINGERPRINT = 'v2:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function __construct() {
		parent::__construct( 'p2-release' );
	}

	public function private_value(): string {
		return 'private';
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		return 'https://p2.invalid/' . $repository->locator;
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return $this->expected_update_uri( $repository ) . '/releases/' . rawurlencode( $tag );
	}

	public function list_release_candidates( string $package_type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		unset( $package_type, $repository, $channel );

		return new RepositoryReleaseCandidateList(
			array( new RepositoryReleaseCandidate( '42', 'v2.0.0', '2.0.0', false, '2026-08-18T00:00:00Z', array( 'fixture.zip' ) ) )
		);
	}

	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		unset( $repository, $channel );

		if ( '42' !== $provider_release_id || 'v2.0.0' !== $tag ) {
			throw new RuntimeException( 'The requested fixture release is invalid.' );
		}

		return new RepositoryReleaseInspection(
			$provider_release_id,
			$tag,
			'2.0.0',
			str_repeat( 'a', 40 ),
			'plugin' === $package_type ? 'ran-booster-p2-fixture-plugin' : 'ran-booster-p2-fixture-theme',
			'plugin' === $package_type ? 'ran-booster-p2-fixture-plugin.php' : 'style.css',
			self::FINGERPRINT
		);
	}

	public function acquire_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $expected_fingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		unset( $repository, $channel );

		if ( '42' !== $provider_release_id || 'v2.0.0' !== $tag || self::FINGERPRINT !== $expected_fingerprint ) {
			throw RepositoryReleaseAcquisitionRejected::invalid_release();
		}
		$source = get_option( 'ran_booster_p2_' . $package_type . '_archive', '' );
		if ( ! is_string( $source ) || ! is_file( $source ) || is_link( $source ) ) {
			throw new RuntimeException( 'The fixture release archive is unavailable.' );
		}
		$copy = wp_tempnam( 'ran-booster-p2-release.zip' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only private artifact custody.
		if ( ! is_string( $copy ) || ! copy( $source, $copy ) || ! chmod( $copy, 0600 ) ) {
			throw new RuntimeException( 'The fixture release archive could not be claimed.' );
		}
		update_option( 'ran_booster_p2_last_artifact', $copy, false );

		return new FixtureReleaseArtifact(
			$copy,
			'plugin' === $package_type ? 'ran-booster-p2-fixture-plugin' : 'ran-booster-p2-fixture-theme',
			'plugin' === $package_type ? 'ran-booster-p2-fixture-plugin.php' : 'style.css'
		);
	}

	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
		unset( $package_type, $installed_identifier );

		return false;
	}

	public function create_native_target(
		string $package_type,
		RepositoryReference $repository,
		string $metadata_file,
		string $package_root,
		string $installed_identifier,
		string $channel,
		string $deployment_policy
	): RepositoryReleaseNativeTarget {
		unset( $package_type, $repository, $metadata_file, $package_root, $installed_identifier, $channel, $deployment_policy );

		return new class() implements RepositoryReleaseNativeTarget {
			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true );
			}

			public function refresh(): bool {
				return true;
			}
		};
	}
}

final class FixtureReleaseArtifact implements RepositoryReleaseArtifact {
	private bool $handed_off = false;

	public function __construct(
		private readonly string $path,
		private readonly string $root,
		private readonly string $main_file
	) {
	}

	public function discard(): bool {
		if ( $this->handed_off || ! file_exists( $this->path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only single-file artifact cleanup.
		return unlink( $this->path );
	}

	public function handoff_to_core(): PreparedArtifact {
		if ( $this->handed_off ) {
			throw new RuntimeException( 'The fixture artifact was already handed off.' );
		}
		$identity = PreparedArtifact::regularFileIdentity( $this->path );
		$digest   = hash_file( 'sha256', $this->path );
		if ( null === $identity || ! is_string( $digest ) ) {
			throw new RuntimeException( 'The fixture artifact identity is invalid.' );
		}
		$this->handed_off = true;

		return new PreparedArtifact(
			$this->path,
			str_repeat( 'a', 40 ),
			'2.0.0',
			$digest,
			$identity['device'],
			$identity['inode'],
			$identity['size'],
			$identity['permissions'],
			$identity['links']
		);
	}

	public function version(): string {
		return '2.0.0';
	}

	public function package_root(): string {
		return $this->root;
	}

	public function main_file(): string {
		return $this->main_file;
	}

	public function identifier( string $package_type ): string {
		return 'plugin' === $package_type ? $this->root . '/' . $this->main_file : $this->root;
	}
}

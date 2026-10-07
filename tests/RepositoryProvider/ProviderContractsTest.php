<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;

final class ProviderContractsTest extends TestCase {
	public function test_release_native_targets_are_one_exact_typed_capability(): void {
		self::assertTrue( ( new \ReflectionClass( RepositoryReleaseNativeTargets::class ) )->isSubclassOf( \RAN\Provider\ProviderCapability::class ) );
		self::assertSame(
			array( 'has_registered_native_target', 'create_native_target' ),
			get_class_methods( RepositoryReleaseNativeTargets::class )
		);
		$factory = new \ReflectionMethod( RepositoryReleaseNativeTargets::class, 'create_native_target' );
		self::assertSame( RepositoryReleaseNativeTarget::class, (string) $factory->getReturnType() );
		self::assertSame(
			array( 'package_type', 'repository', 'metadata_file', 'package_root', 'installed_identifier', 'channel', 'deployment_policy' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, $factory->getParameters() )
		);
		self::assertSame( RepositoryReference::class, (string) $factory->getParameters()[1]->getType() );
		self::assertSame(
			array( 'register', 'status', 'refresh' ),
			get_class_methods( RepositoryReleaseNativeTarget::class )
		);
	}

	public function test_release_native_target_status_is_bounded_and_contains_no_generic_payload(): void {
		$status = new RepositoryReleaseNativeTargetStatus(
			true,
			'2.0.0',
			'newer',
			1_700_000_000,
			1_700_003_600,
			'',
			'release_identity_verified',
			'v2.0.0',
			'2.0.0',
			'2.0.0',
			'42'
		);
		self::assertTrue( $status->active );
		self::assertSame(
			array( 'active', 'offered_version', 'version_relationship', 'last_check', 'next_check', 'failure_code', 'candidate_code', 'candidate_release_tag', 'candidate_release_version', 'candidate_package_header_version', 'candidate_provider_release_id' ),
			array_map(
				static fn ( \ReflectionProperty $property ): string => $property->name,
				( new \ReflectionClass( RepositoryReleaseNativeTargetStatus::class ) )->getProperties( \ReflectionProperty::IS_PUBLIC )
			)
		);
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseNativeTargetStatus( false, str_repeat( '1', 65 ) );
	}

	#[DataProvider( 'invalid_native_target_provider_release_identity' )]
	public function test_release_native_target_status_rejects_unverified_provider_release_identity(
		string $candidate_code,
		string $candidate_release_tag,
		string $candidate_release_version,
		string $candidate_package_header_version
	): void {
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseNativeTargetStatus(
			true,
			'2.0.0',
			'newer',
			1_700_000_000,
			1_700_003_600,
			'',
			$candidate_code,
			$candidate_release_tag,
			$candidate_release_version,
			$candidate_package_header_version,
			'42'
		);
	}

	/** @return array<string, array{string, string, string, string}> */
	public static function invalid_native_target_provider_release_identity(): array {
		return array(
			'missing candidate evidence'     => array( '', '', '', '' ),
			'unverified candidate code'      => array( 'release_version_mismatch', 'v2.0.0', '2.0.0', '2.0.0' ),
			'missing package header version' => array( 'release_identity_verified', 'v2.0.0', '2.0.0', '' ),
		);
	}

	public function test_release_candidate_listing_is_one_exact_typed_capability(): void {
		self::assertTrue( ( new \ReflectionClass( RepositoryReleaseCandidateListing::class ) )->isSubclassOf( \RAN\Provider\ProviderCapability::class ) );

		$methods = get_class_methods( RepositoryReleaseCandidateListing::class );
		self::assertSame( array( 'list_release_candidates' ), $methods );

		$method = new \ReflectionMethod( RepositoryReleaseCandidateListing::class, 'list_release_candidates' );
		self::assertSame( RepositoryReleaseCandidateList::class, (string) $method->getReturnType() );
		self::assertSame(
			array( 'package_type', 'repository', 'channel' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, $method->getParameters() )
		);
		self::assertSame( RepositoryReference::class, (string) $method->getParameters()[1]->getType() );
	}

	public function test_release_candidate_values_are_bounded_and_typed(): void {
		$long_asset_name = str_repeat( 'a', 216 ) . '.zip';
		$candidate       = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3+build.zip', $long_asset_name )
		);
		$list            = new RepositoryReleaseCandidateList( array( $candidate ) );

		self::assertSame( array( $candidate ), $list->candidates );
		self::assertSame( '42', $candidate->provider_release_id );
		self::assertSame( array( 'example-1.2.3+build.zip', $long_asset_name ), $candidate->expected_asset_names );
	}

	public function test_release_candidate_list_rejects_unbounded_lists(): void {
		$candidate = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);

		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidateList( array_fill( 0, 9, $candidate ) );
	}

	public function test_release_candidate_list_rejects_duplicate_identities(): void {
		$candidate = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);

		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidateList(
			array(
				$candidate,
				new RepositoryReleaseCandidate(
					'42',
					'v1.2.4',
					'1.2.4',
					false,
					'2026-08-18T12:00:00Z',
					array( 'example-1.2.4.zip' )
				),
			)
		);
	}

	public function test_release_candidate_rejects_unbounded_provider_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidate(
			'42',
			"invalid\ntag",
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);
	}

	public function test_release_candidate_rejects_invalid_utc_publication_time(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-99-99T99:99:99Z',
			array( 'example-1.2.3.zip' )
		);
	}

	public function test_release_inspection_is_one_exact_typed_capability(): void {
		self::assertTrue( ( new \ReflectionClass( RepositoryReleaseInspector::class ) )->isSubclassOf( \RAN\Provider\ProviderCapability::class ) );
		self::assertSame( array( 'inspect_release' ), get_class_methods( RepositoryReleaseInspector::class ) );

		$method     = new \ReflectionMethod( RepositoryReleaseInspector::class, 'inspect_release' );
		$parameters = $method->getParameters();
		self::assertSame( RepositoryReleaseInspection::class, (string) $method->getReturnType() );
		self::assertSame(
			array( 'package_type', 'repository', 'provider_release_id', 'tag', 'channel' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, $parameters )
		);
		self::assertSame( 'string', (string) $parameters[0]->getType() );
		self::assertSame( RepositoryReference::class, (string) $parameters[1]->getType() );
		self::assertSame( 'string', (string) $parameters[2]->getType() );
		self::assertSame( 'string', (string) $parameters[3]->getType() );
		self::assertSame( 'string', (string) $parameters[4]->getType() );
	}

	public function test_release_acquisition_is_one_exact_typed_capability(): void {
		self::assertTrue( ( new \ReflectionClass( RepositoryReleaseAcquirer::class ) )->isSubclassOf( \RAN\Provider\ProviderCapability::class ) );
		self::assertSame( array( 'acquire_release' ), get_class_methods( RepositoryReleaseAcquirer::class ) );

		$method     = new \ReflectionMethod( RepositoryReleaseAcquirer::class, 'acquire_release' );
		$parameters = $method->getParameters();
		self::assertSame( RepositoryReleaseArtifact::class, (string) $method->getReturnType() );
		self::assertSame(
			array( 'package_type', 'repository', 'provider_release_id', 'tag', 'expected_fingerprint', 'channel' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, $parameters )
		);
		self::assertSame( RepositoryReference::class, (string) $parameters[1]->getType() );
		foreach ( array( 0, 2, 3, 4, 5 ) as $index ) {
			self::assertSame( 'string', (string) $parameters[ $index ]->getType() );
		}
	}

	public function test_release_artifact_is_one_shot_and_path_free(): void {
		self::assertTrue( interface_exists( RepositoryReleaseArtifact::class ) );
		$methods = get_class_methods( RepositoryReleaseArtifact::class );
		sort( $methods );
		self::assertSame(
			array( 'discard', 'handoff_to_core', 'identifier', 'main_file', 'package_root', 'version' ),
			$methods
		);
		self::assertSame(
			RepositoryReleaseArtifactCustody::class,
			(string) ( new \ReflectionMethod( RepositoryReleaseArtifact::class, 'handoff_to_core' ) )->getReturnType()
		);
	}

	public function test_release_acquisition_rejection_is_bounded(): void {
		$rejection = RepositoryReleaseAcquisitionRejected::invalid_release();

		self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $rejection->reason );
		self::assertSame(
			RepositoryReleaseAcquisitionRejected::CLEANUP_FAILED,
			RepositoryReleaseAcquisitionRejected::cleanup_failed()->reason
		);
		self::assertSame(
			array(
				'INVALID_RELEASE' => 'invalid_release',
				'CLEANUP_FAILED'  => 'cleanup_failed',
			),
			( new \ReflectionClass( RepositoryReleaseAcquisitionRejected::class ) )->getConstants()
		);
		self::assertTrue(
			( new \ReflectionClass( RepositoryReleaseAcquisitionRejected::class ) )->getConstructor()?->isPrivate()
		);
	}

	public function test_release_inspection_evidence_is_bounded_typed_and_path_free(): void {
		$inspection = new RepositoryReleaseInspection(
			'release:42',
			'release/v1.2.3',
			'1.2.3',
			'commit:0123456789abcdef',
			'example-plugin',
			'example.php',
			'provider:v2:0123456789abcdef'
		);

		self::assertSame( 'release:42', $inspection->provider_release_id );
		self::assertSame( 'commit:0123456789abcdef', $inspection->provider_commit_id );
		self::assertSame( 'provider:v2:0123456789abcdef', $inspection->fingerprint );
		self::assertSame(
			array( 'provider_release_id', 'tag', 'version', 'provider_commit_id', 'package_root', 'main_file', 'fingerprint' ),
			array_map(
				static fn ( \ReflectionProperty $property ): string => $property->name,
				( new \ReflectionClass( RepositoryReleaseInspection::class ) )->getProperties( \ReflectionProperty::IS_PUBLIC )
			)
		);
	}

	public function test_release_inspection_rejects_unsafe_or_unbounded_evidence(): void {
		$valid          = array(
			'42',
			'v1.2.3',
			'1.2.3',
			str_repeat( 'a', 40 ),
			'example',
			'example.php',
			'v1:' . str_repeat( 'b', 64 ),
		);
		$invalid_values = array(
			0 => '',
			1 => "invalid\ntag",
			2 => '-1.2.3',
			3 => "commit\0identity",
			4 => '../example',
			5 => 'subdirectory/example.php',
			6 => str_repeat( 'f', 192 ),
		);

		foreach ( $invalid_values as $index => $invalid ) {
			$values           = $valid;
			$values[ $index ] = $invalid;
			try {
				new RepositoryReleaseInspection( ...$values );
				self::fail( 'Unsafe release inspection evidence must be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}

		$values    = $valid;
		$values[4] = str_repeat( 'a', 101 );
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseInspection( ...$values );
	}

	public function test_release_inspection_rejection_has_only_the_bounded_domain_reasons(): void {
		$no_releases  = RepositoryReleaseInspectionRejected::no_releases();
		$invalid      = RepositoryReleaseInspectionRejected::invalid_release();
		$incompatible = RepositoryReleaseInspectionRejected::incompatible();

		self::assertSame( RepositoryReleaseInspectionRejected::NO_RELEASES, $no_releases->reason );
		self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $invalid->reason );
		self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $incompatible->reason );
		self::assertSame( $no_releases->getMessage(), $invalid->getMessage() );
		self::assertSame( $no_releases->getMessage(), $incompatible->getMessage() );
		self::assertTrue(
			( new \ReflectionClass( RepositoryReleaseInspectionRejected::class ) )->getConstructor()?->isPrivate()
		);
	}

	public function test_release_metadata_is_an_exact_optional_capability(): void {
		self::assertTrue( ( new \ReflectionClass( RepositoryReleaseMetadata::class ) )->isSubclassOf( \RAN\Provider\ProviderCapability::class ) );

		$methods = get_class_methods( RepositoryReleaseMetadata::class );
		sort( $methods );

		self::assertSame( array( 'expected_update_uri', 'release_details_url' ), $methods );
		self::assertSame(
			array( 'repository' ),
			array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryReleaseMetadata::class, 'expected_update_uri' ) )->getParameters()
			)
		);
		self::assertSame(
			array( 'repository', 'tag' ),
			array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryReleaseMetadata::class, 'release_details_url' ) )->getParameters()
			)
		);
	}

	public function test_repository_provider_has_the_exact_mandatory_api_four_surface(): void {
		$methods = get_class_methods( RepositoryProvider::class );
		sort( $methods );

		self::assertSame(
			array( 'get_metadata', 'get_provider_diagnostics', 'prepare_archive', 'resolve_repository' ),
			$methods
		);
	}

	public function test_credentialed_public_browsing_is_an_additive_optional_capability(): void {
		self::assertTrue( ( new \ReflectionClass( CredentialedPublicRepositoryBrowser::class ) )->isSubclassOf( RepositoryBrowser::class ) );

		$methods = get_class_methods( CredentialedPublicRepositoryBrowser::class );
		sort( $methods );

		self::assertSame(
			array( 'browse_repositories', 'get_public_repository_browse_metadata' ),
			$methods
		);
		self::assertTrue( ( new PublicRepositoryBrowseMetadata( true ) )->supports_provider_default_profile );
		self::assertFalse( ( new PublicRepositoryBrowseMetadata( false ) )->supports_provider_default_profile );
	}

	public function test_repository_webhook_operation_has_only_the_five_fixed_actions(): void {
		$fitness_methods    = get_class_methods( RepositoryWebhookFitness::class );
		$management_methods = get_class_methods( RepositoryWebhookManagement::class );
		sort( $fitness_methods );
		sort( $management_methods );

		self::assertSame( array( 'assess_check', 'assess_reconfigure', 'assess_remove', 'assess_setup', 'assess_test' ), $fitness_methods );
		self::assertSame( array( 'check', 'reconfigure', 'remove', 'setup', 'test' ), $management_methods );
		self::assertSame( 'repository-webhook-management', RepositoryWebhookFitness::OPERATION );
		self::assertSame( 3, RepositoryWebhookFitness::VERSION );
		self::assertSame( RepositoryWebhookFitness::OPERATION, RepositoryWebhookManagement::OPERATION );
		self::assertSame( RepositoryWebhookFitness::VERSION, RepositoryWebhookManagement::VERSION );
		self::assertSame(
			array( 'repository_id', 'repository', 'credential_profile_id' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, ( new \ReflectionMethod( RepositoryWebhookFitness::class, 'assess_setup' ) )->getParameters() )
		);
		self::assertSame(
			array( 'repository_id', 'repository', 'credential_profile_id', 'hook_id' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, ( new \ReflectionMethod( RepositoryWebhookFitness::class, 'assess_remove' ) )->getParameters() )
		);

		foreach ( array( 'check', 'remove' ) as $method ) {
			$names = array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryWebhookManagement::class, $method ) )->getParameters()
			);
			self::assertSame(
				array( 'repository_id', 'repository', 'hook_id', 'callback_url', 'credential_profile_id' ),
				$names,
				'Exact endpoint ownership requires the Core-derived callback URL.'
			);
		}
	}

	public function test_manual_capability_fixture_resolves_readonly_lookup_values(): void {
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'fixture' ), 'Fixture', 'https://example.test/', 'Owner' );
			}
		};
		$request  = new RepositoryLookupRequest( 'group/subgroup/package', 'credential-one', true );

		$repository = $provider->resolve_repository( $request );

		self::assertTrue( $provider->get_metadata()->code->equals( $repository->provider ) );
		self::assertSame( $request->locator, $repository->locator );
		self::assertSame( 'package', $repository->package_slug );
		self::assertSame( 'test:' . hash( 'sha256', $request->locator ), $repository->provider_repository_id );
		self::assertFalse( $repository->private );
		self::assertSame( 'main', $repository->default_branch );
		self::assertSame( $request->credential_id, $repository->credential_id );
		self::assertTrue( $request->public_only );
	}

	public function test_prepared_archive_cleanup_can_be_implemented_idempotently(): void {
		$archive = new class() implements PreparedArchive {

			public int $cleanup_count = 0;

			private bool $cleaned = false;

			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
				if ( $this->cleaned ) {
					return;
				}

				$this->cleaned = true;
				++$this->cleanup_count;
			}
		};

		$archive->cleanup();
		$archive->cleanup();

		self::assertSame( 1, $archive->cleanup_count );
	}

	public function test_archive_capability_uses_normalized_requests_and_prepared_archives(): void {
		$archive  = new class() implements PreparedArchive {

			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
			}
		};
		$provider = new class( $archive ) implements RepositoryProvider {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?ArchiveRequest $request = null;

			public function __construct( private PreparedArchive $archive ) {
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
				$this->request = $request;
				return $this->archive;
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );
		$request  = new ArchiveRequest(
			new RepositoryReference( 'owner/repository', '42', true, 'credential-one' ),
			'abcdef'
		);

		$capability = $registry->get( ProviderCode::parse( 'gh' ) );

		self::assertSame( $provider, $capability );
		self::assertSame( $archive, $capability->prepare_archive( $request ) );
		self::assertSame( $request, $provider->request );
	}
}

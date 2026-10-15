<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\NativePortabilityFacade;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintCredentialAction;
use RAN\PackageOperationService;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use ReflectionClass;
use InvalidArgumentException;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\RepositoryProvider\SelectionContextDatabase;

require_once dirname( __DIR__ ) . '/RepositoryProvider/GitHubProviderSelectionTest.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Runtime/RuntimeSupportWordPressFunctions.php';

/** Guarded composition proof; separate from actual host bootstrap qualification. */
final class BlueprintProviderExpectationTest extends TestCase {
	/** @return iterable<string,array{string}> */
	public static function rejected_expectations(): iterable {
		yield 'absent' => array( 'absent' );
		yield 'malformed' => array( 'malformed' );
		yield 'stale' => array( 'stale' );
	}

	#[DataProvider( 'rejected_expectations' )]
	public function test_apply_refuses_before_credentials_provider_and_destination( string $kind ): void {
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$registry    = new ProviderRegistry( array( $provider ) );
		$selection   = GitHubSelectionTestFixture::bind( $registry );
		$expectation = $selection->expectation();
		self::assertNotNull( $expectation );
		$expectation['selection_generation'] += 1;
		if ( 'absent' === $kind ) {
			$expectation = null;
		} elseif ( 'malformed' === $kind ) {
			$expectation['extra'] = true;
		}
		$secrets = $this->createMock( SecretsFile::class );
		foreach ( array( 'credential_material', 'credential_profiles', 'with_temporary_credential', 'import_credentials_if_absent', 'assert_managed_storage_ready' ) as $method ) {
			$secrets->expects( self::never() )->method( $method );
		}
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'is_installed' );
		$plugins->expects( self::never() )->method( 'has_management_record' );
		$service = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ),
			new BlueprintRepositoryVerifier( $registry, $secrets, $selection ),
			// Any accidental execute() reaches uninitialized mandatory collaborators and fails this test.
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);
		$package    = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'id', 'owner/example', 'main', null );
		$credential = new BlueprintCredential(
			'gh',
			'Transfer',
			'classic',
			array(),
			'fixture-only-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint( array( $package ), array( $credential ) );
		$candidate  = new PortabilityCandidate( 'plugin', 'example/example.php', 'Example', 'gh', 'owner/example', 'main', null, 'saved-profile' );
		foreach ( array( false, true ) as $public ) {
			try {
				$result = $public
					? $service->apply_candidate( $candidate, 'v1:' . str_repeat( 'a', 64 ), $expectation )
					: $service->apply(
						$blueprint,
						0,
						'install',
						array(
							array(
								'action'    => BlueprintCredentialAction::IMPORT,
								'target_id' => null,
							),
						),
						null,
						true,
						true,
						array( 0 ),
						$expectation
					);
				self::assertSame( 'stale', $kind );
				if ( is_array( $result ) ) {
					self::assertSame( 'skipped', $result['status'] );
				} else {
					self::assertSame( 'provider_selection_changed', $result->reason );
					self::assertFalse( $result->target_verified );
				}
			} catch ( InvalidArgumentException ) {
				self::assertNotSame( 'stale', $kind );
			}
		}
	}

	public function test_matching_expectation_still_rechecks_changed_destination(): void {
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$registry  = new ProviderRegistry( array( $provider ) );
		$selection = GitHubSelectionTestFixture::bind( $registry );
		$plugins   = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'is_installed' )->willReturn( true );
		$plugins->expects( self::once() )->method( 'has_management_record' )->willReturn( false );
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'credential_material' );
		$service = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ),
			new BlueprintRepositoryVerifier( $registry, $secrets, $selection ),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'id', 'owner/example', 'main', null );
		$result  = $service->apply( new PackageBlueprint( array( $package ) ), 0, 'install', array(), null, true, true, array( 0 ), $selection->expectation() );
		self::assertSame( 'skipped', $result['status'] );
		self::assertStringContainsString( 'changed since review', $result['message'] );
	}

	public function test_preview_observations_never_cache_selection_authority(): void {
		$db = new SelectionContextDatabase();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact WordPress option fixture bytes.
		$db->active_bytes = serialize( array( 'booster.php' ) );
		$root             = sys_get_temp_dir() . '/blueprint-selection-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Random fixture directory.
		mkdir( $root, 0700 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Passive fixture file, never loaded.
		file_put_contents( $root . '/booster.php', '<?php // passive fixture' );
		try {
			$declaration = new GitHubReplacementDeclaration(
				array(
					'plugin_basename'        => 'booster.php',
					'implementation_id'      => 'booster.php',
					'compatibility_revision' => '1',
					'provider_api_version'   => 15,
					'api_credential_formats' => array( 'github-classic-pat-v1' ),
					'webhook_signing_format' => null,
					'facets'                 => array(),
				)
			);
			$store       = new GitHubSelectionStore( $db, 'wp_options' );
			$store->initialize_bundled( $declaration );
			$selection         = new GitHubProviderSelection( $store, $root, $declaration );
			$credential_checks = 0;
			$secrets           = new SecretsFile(
				$root . '/absent-secrets',
				array(),
				github_credential_access: static function ( ?array $metadata ) use ( $selection, &$credential_checks ): bool {
					++$credential_checks;
					return $selection->credential_access_allowed( $metadata );
				}
			);
			$provider          = $this->createMock( RepositoryProvider::class );
			$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
			$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
			$resolutions = 0;
			$provider->expects( self::exactly( 3 ) )->method( 'resolve_repository' )->willReturnCallback(
				static function () use ( &$resolutions, $db ): RepositoryDescriptor {
					++$resolutions;
					if ( 3 === $resolutions ) {
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Change authority during provider resolution.
						$db->active_bytes = serialize( array() );
					}
					return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repo', 'fixture', 'id', false, 'main', null );
				}
			);
			$credentials = $this->createStub( ProviderCredentialStore::class );
			$evidence    = $this->createStub( AuthenticatedWebhookDeliveryEvidenceReader::class );
			$registry    = new ProviderRegistry(
				credential_store_factory: static fn (): ProviderCredentialStore => $credentials,
				delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
				github_selection_guard: array( $selection, 'registry_selection' ),
				github_candidates: $selection->candidates(),
				bundled_github_factory: static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ) use ( $provider ): RepositoryProvider {
					unset( $credentials, $evidence );
					self::assertTrue( $context->operation_allowed() );
					return $provider;
				}
			);
			$selection->bind_registry( $registry );
			$selection->complete_collection();
			$registry->seal();
			$verifier = new BlueprintRepositoryVerifier( $registry, $secrets, $selection );
			$item     = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'fixture/main.php', 'Fixture', 'gh', 'id', 'owner/repo', 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
			$preview  = $verifier->for_preview();
			$reviewed = $preview->verify( $item );
			self::assertSame( TargetPackageAction::INSTALL, $reviewed->action );
			self::assertNotNull( $reviewed->provider_expectation );
			self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $item )->action );
			// A new preview must resolve again even though selection still matches.
			self::assertSame( TargetPackageAction::INSTALL, $verifier->for_preview()->verify( $item )->action );
			// A change during provider I/O must discard the newly observed success.
			self::assertSame( TargetPackageReason::PROVIDER_UNAVAILABLE, $verifier->for_preview()->verify( $item )->reason );
			// The earlier anonymous cache cannot bypass the now unavailable owner.
			self::assertSame( TargetPackageReason::PROVIDER_UNAVAILABLE, $preview->verify( $reviewed )->reason );
			self::assertSame( TargetPackageReason::PROVIDER_UNAVAILABLE, $preview->verify( $reviewed, target_credential_id: 'saved-profile' )->reason );
			self::assertSame( 'provider_unavailable', $verifier->provider_review_refusal( 'gh', $reviewed->provider_expectation ) );
			$plugins = $this->createMock( PluginRepository::class );
			$plugins->expects( self::never() )->method( 'is_installed' );
			$service   = new PortabilityApplicationService(
				new BlueprintReviewer( $plugins, $this->createStub( ThemeRepository::class ) ),
				$verifier,
				( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
				$secrets
			);
			$candidate = new PortabilityCandidate( 'plugin', 'fixture/main.php', 'Fixture', 'gh', 'owner/repo', 'main', null, 'saved-profile' );
			$reads     = $db->active_reads;
			foreach ( array( false, true ) as $capability ) {
				$nonce_checks = 0;
				$facade       = new NativePortabilityFacade(
					$service,
					static fn (): bool => $capability,
					static function () use ( &$nonce_checks ): bool {
						++$nonce_checks;
						return false;
					}
				);
				self::assertSame( 'forbidden', $facade->review( $candidate, 'wrong' )->reason );
				self::assertSame( 'forbidden', $facade->apply( $candidate, 'v1:' . str_repeat( 'a', 64 ), 'wrong', $reviewed->provider_expectation )->reason );
				self::assertSame( $capability ? 2 : 0, $nonce_checks );
				self::assertSame( $reads, $db->active_reads );
			}
			self::assertSame( 0, $credential_checks );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Only test-owned passive file.
			unlink( $root . '/booster.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty test-owned directory.
			rmdir( $root );
		}
	}
}

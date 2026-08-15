<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/../Support/ExternalFixturePluginWordPressFunctions.php';
require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

	// Direct temporary sidecar operations prove the external credential boundary.
	// phpcs:disable WordPress.WP.AlternativeFunctions

	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use PHPUnit\Framework\TestCase;
	use RAN\Admin\PackageRepositoryRequestResolver;
	use RAN\Admin\ProviderSettingsPresenter;
	use RAN\RepositoryProvider\ArchiveRequest;
	use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
	use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
	use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
	use RAN\RepositoryProvider\InvalidCredentialInput;
	use RAN\RepositoryProvider\ProviderCode;
	use RAN\RepositoryProvider\ProviderCredentialStore;
	use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderRegistry;
	use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
	use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryReference;
	use RAN\RepositoryProvider\RepositoryWebhookFitness;
	use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
	use RAN\RepositoryProvider\RepositoryWebhookManagement;
	use RAN\RepositoryProvider\UnsupportedProviderCapability;
	use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use Tests\Secrets\SecretsFileTestFactory;
use RAN\Storage\CredentialUsageReader;
use Tests\Support\CredentialUsageDatabase;
	use RANBoosterFixtureProvider\Provider;

final class ExternalFixturePluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPresenterSuppressesManagementPresentationForAPartialCapabilityProvider(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );
		$this->loadFixturePlugin();
		list( $registry, , $path ) = $this->registry();

		try {
			$this->runRegistrationHook( $registry );
			$provider = $registry->get( 'fixture-provider' );
			self::assertInstanceOf( Provider::class, $provider );
			$partial = new class( $provider ) implements \RAN\RepositoryProvider\RepositoryProvider, RepositoryWebhookFitness {
				public function __construct( private Provider $provider ) {
				}

				public function getMetadata(): \RAN\RepositoryProvider\ProviderMetadata {
					return $this->provider->getMetadata();
				}

				public function getProviderDiagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
					return $this->provider->getProviderDiagnostics();
				}

				public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
					return $this->provider->resolveRepository( $request );
				}

				public function prepareArchive( ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
					return $this->provider->prepareArchive( $request );
				}

				public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
					return $this->provider->assessSetup( $repositoryId, $repository, $credentialProfileId, $requestCredential );
				}

				public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
					return $this->provider->assessCheck( $repositoryId, $repository, $credentialProfileId, $hookId, $requestCredential );
				}

				public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
					return $this->provider->assessReconfigure( $repositoryId, $repository, $credentialProfileId, $hookId, $requestCredential );
				}

				public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
					return $this->provider->assessRemove( $repositoryId, $repository, $credentialProfileId, $hookId, $requestCredential );
				}
			};

			$metadata   = $partial->getMetadata();
			$reflection = new \ReflectionClass( ProviderSettingsPresenter::class );
			$projection = $reflection->getMethod( 'provider' )->invoke(
				$reflection->newInstanceWithoutConstructor(),
				$partial,
				$metadata,
				$metadata->admin
			);

			self::assertNull( $projection['webhook_assistance'] );
		} finally {
			$this->cleanSidecar( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testPluginLoadedBeforeTheApiMarkerRegistersOnTheLaterHook(): void {
		$this->loadFixturePlugin();
		self::assertFalse( defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );

		list( $registry, , $path ) = $this->registry();
		$this->runRegistrationHook( $registry );

		self::assertInstanceOf( Provider::class, $registry->get( 'fixture-provider' ) );
		$this->cleanSidecar( $path );
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testPluginLoadedAfterTheApiMarkerExercisesTheCompleteProviderContract(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );
		$this->loadFixturePlugin();
		list( $registry, $secrets, $path ) = $this->registry();

		try {
			$this->runRegistrationHook( $registry );
			$registry->seal();
			$provider = $registry->get( 'fixture-provider' );
			self::assertInstanceOf( Provider::class, $provider );
			self::assertSame( 0, $provider->getClient()->getRequestCount(), 'Registration must not run provider diagnostics or contact the provider client.' );
			self::assertFalse( $provider->latestDeliveryWasObserved() );
			try {
				$secrets->saveCredential(
					'fixture-provider',
					null,
					array(
						'label'         => 'Invalid fixture',
						'kind'          => 'api-key',
						'configuration' => array( 'tenant' => 'ran-lab' ),
					),
					'wrong-prefix-secret',
					true
				);
				self::fail( 'The fixture submitted-credential contract must run.' );
			} catch ( InvalidCredentialInput $failure ) {
				self::assertSame( InvalidCredentialInput::INVALID_SECRET_SHAPE, $failure->reason );
				self::assertSame( 'Fixture API keys must begin with fixture_.', $failure->getMessage() );
			}

			$credentialId = $secrets->saveCredential(
				'fixture-provider',
				'fixture-primary',
				array(
					'label'         => 'Fixture primary',
					'kind'          => 'api-key',
					'configuration' => array( 'tenant' => 'ran-lab' ),
				),
				'fixture_not-a-real-secret',
				true
			);

			self::assertTrue( $provider->validateCredential( $credentialId )->isValid() );
			self::assertSame( 'ran-lab', $secrets->credentialProfiles( 'fixture-provider' )[ $credentialId ]['configuration']['tenant'] );

			$resolved = ( new PackageRepositoryRequestResolver( $registry ) )->resolve(
				array(
					'provider'      => 'fixture-provider',
					'repository'    => 'group/subgroup/package',
					'branch'        => '',
					'credential_id' => $credentialId,
				)
			);

			self::assertSame( 'group/subgroup/package', $resolved['repository'] );
			self::assertSame( 'package', $resolved['package_slug'] );
			self::assertSame( 'fixture:' . hash( 'sha256', 'group/subgroup/package' ), $resolved['provider_repository_id'] );
			self::assertSame( 'fixture-provider', $resolved['provider'] );

			$settings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->build( 'fixture-provider' );
			self::assertSame( 'fixture-provider', $settings['provider']['code'] );
			self::assertSame( 'core:webhook-management', $settings['provider']['webhook_assistance']['action_key'] );
			self::assertSame( 'Manage webhook', $settings['provider']['webhook_assistance']['action_label'] );
			self::assertFalse( $settings['provider']['capabilities']['browse'] );
			self::assertFalse( $settings['provider']['capabilities']['credentialed_public_browse'] );
			self::assertFalse( $settings['provider']['capabilities']['provider_default_public_lookup_profile'] );
			self::assertFalse( $settings['provider']['capabilities']['webhooks'] );
			$packageForm     = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->buildPackageForm( 'fixture-provider' );
			$packageProvider = array_column( $packageForm['providers'], null, 'code' )['fixture-provider'];
			self::assertSame( 'fixture-provider', $packageForm['default_provider'] );
			self::assertTrue( $packageProvider['deploy'] );
			self::assertFalse( $packageProvider['browse'] );
			self::assertFalse( $packageProvider['credentialed_public_browse'] );
			self::assertFalse( $packageProvider['provider_default_public_lookup_profile'] );
			self::assertFalse( $packageProvider['webhooks'] );

			$beforeDiagnostics = $provider->getClient()->getRequestCount();
			$now               = 100.0;
			$request           = new ProviderDiagnosticRequest(
				$credentialId,
				'group/subgroup/package',
				ProviderDiagnosticRequest::MAX_REMOTE_CALLS,
				4.25,
				static function () use ( &$now ): float {
					return $now;
				}
			);
			$results           = $provider->getProviderDiagnostics()->diagnose( $request );
			self::assertCount( 3, $results );
			self::assertSame(
				array(
					'fixture-provider.environment.ready',
					'fixture-provider.credential.valid',
					'fixture-provider.repository.reachable',
				),
				array_map( static fn( $result ): string => $result->code, $results )
			);
			self::assertSame( 3, $request->getRemoteCalls() );
			self::assertSame( 3, $provider->getClient()->getRequestCount() - $beforeDiagnostics );
			self::assertSame( array( 4.25, 4.25, 4.25 ), $provider->getClient()->getDiagnosticTimeouts() );
			foreach ( $results as $result ) {
				self::assertSame( array( 'status', 'code', 'message', 'remediation' ), array_keys( $result->toArray() ) );
			}

			$reference   = new RepositoryReference(
				$resolved['repository'],
				$resolved['provider_repository_id'],
				true,
				$credentialId
			);
			$archive     = $provider->prepareArchive( new ArchiveRequest( $reference, 'main' ) );
			$resolvedRef = sha1( "group/subgroup/package\0main" );
			self::assertSame( $resolvedRef, $archive->getResolvedRef() );
			self::assertSame( 'https://fixtures.example.test/group/subgroup/package/' . $resolvedRef . '.zip', $archive->getUrl() );

			$automatic = $provider->prepareArchive( new ArchiveRequest( $reference, $resolvedRef, 'main' ) );
			$provider->getClient()->setBranchHead( 'main', '89abcdef0123456789abcdef0123456789abcdef' );
			try {
				$automatic->verifyCurrentHead();
				self::fail( 'The fixture provider must re-check automatic deployment heads before mutation.' );
			} catch ( \RAN\RepositoryProvider\StaleDeployment $exception ) {
				self::assertSame( 409, $exception->getCode() );
			}

			$fitness = $registry->requireCapability( 'fixture-provider', RepositoryWebhookFitness::class );
			self::assertSame(
				'fixture.permission.webhook_exact',
				$fitness->assessSetup( $resolved['provider_repository_id'], $resolved['repository'], $credentialId )->toArray()['code']
			);
			$management = $registry->requireCapability( 'fixture-provider', RepositoryWebhookManagement::class );
			$operation  = $management->setup( $resolved['provider_repository_id'], $resolved['repository'], 'https://site.example/webhook', $credentialId, null, str_repeat( 's', 32 ) );
			self::assertSame( 'configured_pending_delivery', $operation->code() );
			self::assertStringNotContainsString( 'fixture_not-a-real-secret', json_encode( $operation->toArray(), JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( str_repeat( 's', 32 ), json_encode( $operation->toArray(), JSON_THROW_ON_ERROR ) );

			foreach ( array( RepositoryBrowser::class, CredentialedPublicRepositoryBrowser::class, WebhookNormalizer::class ) as $capability ) {
				try {
					$registry->requireCapability( 'fixture-provider', $capability );
					self::fail( 'The fixture must not expose unsupported optional capabilities.' );
				} catch ( UnsupportedProviderCapability ) {
					self::assertSame( $provider, $registry->get( 'fixture-provider' ) );
				}
			}
		} finally {
			$this->cleanSidecar( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testPluginDoesNotRegisterWithAnOlderProviderApi(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 8 );
		$this->loadFixturePlugin();
		list( $registry, , $path ) = $this->registry();

		try {
			$this->runRegistrationHook( $registry );
			self::assertFalse( class_exists( Provider::class, false ) );
		} finally {
			$this->cleanSidecar( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function testPluginIsHarmlessWhenBoosterIsAbsent(): void {
		$this->loadFixturePlugin();
		$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();

		self::assertCount( 1, $callbacks );
		$callbacks[0]( new \stdClass() );
		self::assertFalse( class_exists( Provider::class, false ) );
	}

	private function loadFixturePlugin(): void {
		$GLOBALS['ran_booster_external_fixture_actions'] = array();
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-provider/ran-booster-fixture-provider.php';
	}

		/**
		 * @return array{ProviderRegistry, SecretsFile, string}
		 */
	private function registry(): array {
		$path           = sys_get_temp_dir() . '/ran-booster-external-fixture-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secretPolicies = new ProviderSecretPolicyCatalog();
		$secrets        = SecretsFileTestFactory::create( $path, array(), $secretPolicies );
		$registry       = new ProviderRegistry(
			array(),
			$secretPolicies,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code ),
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			}
		);

		return array( $registry, $secrets, $path );
	}

	private function runRegistrationHook( ProviderRegistry $registry ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();
		self::assertCount( 1, $callbacks );
		$callbacks[0]( $registry );
	}

	private function cleanSidecar( string $path ): void {
		foreach ( array( $path, $path . '.lock' ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				unlink( $candidate );
			}
		}
	}
}

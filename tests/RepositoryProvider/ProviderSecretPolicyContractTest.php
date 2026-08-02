<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// Direct local filesystem operations verify sidecar policy and deactivation behavior.
// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use Tests\Support\CredentialUsageDatabase;
use RuntimeException;
use Tests\RepositoryProvider\Support\ExternalFixtureCredentialPolicy;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use Tests\Secrets\SecretsFileTestFactory;

final class ProviderSecretPolicyContractTest extends TestCase {

	public function testPolicyFailureLeavesBothRegistryAndCatalogUnchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );
		$code     = ProviderCode::parse( 'atomic-fixture' );
		$provider = new AtomicPolicyProvider(
			$code,
			new ExternalFixtureCredentialPolicy( $code ),
			new InertWebhookPolicy( ProviderCode::parse( 'gh' ) )
		);

		try {
			$registry->register( $provider );
			self::fail( 'A mismatched webhook policy must reject registration.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, $code );
		$this->assertWebhookPolicyUnavailable( $catalog, $code );
		$this->assertValidSameCodeRetry( $registry, $catalog, $code );
	}

	public function testCredentialPolicyIdentityFailureIsRedactedAndAtomic(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );
		$code     = ProviderCode::parse( 'atomic-fixture' );
		$provider = new AtomicPolicyProvider(
			$code,
			new ExplodingCredentialPolicy(),
			new InertWebhookPolicy( $code )
		);

		try {
			$registry->register( $provider );
			self::fail( 'A failing credential-policy identity must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertSame( 'The provider credential policy is unavailable.', $exception->getMessage() );
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, $code );
		$this->assertWebhookPolicyUnavailable( $catalog, $code );
		$this->assertValidSameCodeRetry( $registry, $catalog, $code );
	}

	public function testWebhookPolicyIdentityFailureIsRedactedAndAtomic(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );
		$code     = ProviderCode::parse( 'atomic-fixture' );
		$provider = new AtomicPolicyProvider(
			$code,
			new ExternalFixtureCredentialPolicy( $code ),
			new ExplodingWebhookPolicy()
		);

		try {
			$registry->register( $provider );
			self::fail( 'A failing webhook-policy identity must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertSame( 'The provider webhook policy is unavailable.', $exception->getMessage() );
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, $code );
		$this->assertWebhookPolicyUnavailable( $catalog, $code );
		$this->assertValidSameCodeRetry( $registry, $catalog, $code );
	}

	public function testWebhookMetadataWithoutTheOptionalCapabilityIsRejected(): void {
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'missing-webhook' ),
					'Missing webhook',
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						array(),
						array( new WebhookScopeMetadata( 'repository', 'Repository', true, 'Repository' ) )
					)
				);
			}

			public function getProviderDiagnostics(): ProviderDiagnostics {
				return new EmptyProviderDiagnostics();
			}
		};

		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'Webhook metadata without a webhook policy must be rejected.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assertWebhookPolicyUnavailable( $catalog, ProviderCode::parse( 'missing-webhook' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'missing-webhook' ) );
	}

	public function testCredentialMetadataWithoutTheOptionalPolicyIsRejectedAtomically(): void {
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'missing-credential' ),
					'Missing credential policy',
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						array( new CredentialKindMetadata( 'api-key', 'API key', 'API key', '' ) ),
						array()
					)
				);
			}
		};
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'Credential metadata without a credential policy must be rejected.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'missing-credential' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'missing-credential' ) );
	}

	public function testExternalProviderWithoutWebhooksBuildsSettingsAndManualDeploymentState(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$provider = null;
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);
		$registry->registerWithCredentialStore(
			'fixture',
			static function ( ProviderCredentialStore $credentials ) use ( &$provider ): ExternalFixtureProvider {
				$provider = new ExternalFixtureProvider( 'fixture', $credentials );

				return $provider;
			}
		);
		$settings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->build( 'fixture' );

		self::assertInstanceOf( ExternalFixtureProvider::class, $provider );
		self::assertSame( array(), $settings['webhook_profiles'] );
		self::assertFalse( $settings['provider']['capabilities']['webhooks'] );
		self::assertNotInstanceOf( WebhookNormalizer::class, $provider );
	}

	public function testCredentialStoreFactoryFailuresAreRedactedAndLeaveRegistrationUnchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static function (): ProviderCredentialStore {
				throw new RuntimeException( 'credential-store-token-canary' );
			}
		);

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static fn ( ProviderCredentialStore $credentials ): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
			);
			self::fail( 'A failing internal store factory must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testExternalProviderFactoryFailuresAreRedactedAndLeaveRegistrationUnchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static function (): RepositoryProvider {
					throw new RuntimeException( 'provider-factory-path-canary' );
				}
			);
			self::fail( 'A failing external provider factory must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testProviderMetadataFailureLeavesCatalogAndRegistryRetryable(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				throw new RuntimeException( 'provider-metadata-token-canary' );
			}
		};

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static fn (): RepositoryProvider => $provider
			);
			self::fail( 'A provider with unavailable metadata must be rejected.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testDirectRegistrationMetadataFailureIsRedactedAndRetryable(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array(), $catalog );
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				throw new RuntimeException( 'direct-provider-metadata-token-canary' );
			}
		};

		try {
			$registry->register( $provider );
			self::fail( 'Unavailable metadata must reject direct registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertSame( 'Repository provider metadata could not be supplied.', $exception->getMessage() );
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertWebhookPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testProviderFactoryCannotReadCredentialsBeforePolicyRegistration(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static function ( ProviderCredentialStore $credentials ): ExternalFixtureProvider {
					$credentials->credentialMaterial();

					return new ExternalFixtureProvider( 'fixture', $credentials );
				}
			);
			self::fail( 'Credential reads during provider construction must be rejected.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertSame( 'The provider factory returned an invalid provider.', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testRequestedProviderCodeIsCheckedBeforeIssuingCredentials(): void {
		$issued   = 0;
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array( new ExternalFixtureProvider( 'fixture' ) ),
			$catalog,
			static function ( ProviderCode $code ) use ( $secrets, &$issued ): ProviderCredentialStore {
				++$issued;

				return $secrets->credentialsFor( $code );
			}
		);

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static fn ( ProviderCredentialStore $credentials ): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
			);
			self::fail( 'A duplicate code must fail before credentials are issued.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 0, $issued );
			self::assertSame( 'Repository provider is already registered.', $exception->getMessage() );
			self::assertCount( 1, $registry->all() );
		}
	}

	public function testSealedCredentialRegistrationRejectsBeforeEitherFactoryRuns(): void {
		$credentialStoreCalls = 0;
		$providerCalls        = 0;
		$catalog              = new ProviderSecretPolicyCatalog();
		$registry             = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static function ( ProviderCode $code ) use ( &$credentialStoreCalls ): ProviderCredentialStore {
				++$credentialStoreCalls;

				throw new RuntimeException( 'The credential-store factory must not run after sealing.' );
			}
		);
		$registry->seal();

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static function ( ProviderCredentialStore $credentials ) use ( &$providerCalls ): RepositoryProvider {
					++$providerCalls;

					return new ExternalFixtureProvider( 'fixture', $credentials );
				}
			);
			self::fail( 'A sealed registry must reject credential registration.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'Repository provider registration is closed.', $exception->getMessage() );
			self::assertSame( 0, $credentialStoreCalls );
			self::assertSame( 0, $providerCalls );
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertWebhookPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testProviderFactoryIdentityMismatchLeavesCatalogAndRegistryUnchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);

		try {
			$registry->registerWithCredentialStore(
				'fixture',
				static fn ( ProviderCredentialStore $credentials ): ExternalFixtureProvider => new ExternalFixtureProvider( 'other-fixture', $credentials )
			);
			self::fail( 'A mismatched provider factory must reject registration.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
		$this->assertValidSameCodeRetry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function testProviderMetadataIsCapturedOnceBeforeAtomicRegistration(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$provider = new AlternatingMetadataProvider();
		$registry = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code )
		);

		$registry->registerWithCredentialStore(
			'fixture',
			static fn (): RepositoryProvider => $provider
		);

		self::assertSame( 1, $provider->metadataCalls );
		self::assertSame( $provider, $registry->get( 'fixture' ) );
		self::assertArrayNotHasKey( 'other-fixture', $registry->all() );
		self::assertSame( 'fixture', $registry->metadata()['fixture']->code->value );
		self::assertSame( 1, $provider->metadataCalls );
		self::assertSame( 'fixture', $catalog->credentialPolicy( 'fixture' )->getProvider()->value );
		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
	}

	public function testCredentialPolicyIdentityIsFrozenAtRegistration(): void {
		$path     = sys_get_temp_dir() . '/ran-booster-policy-drift-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$catalog  = new ProviderSecretPolicyCatalog();
		$policy   = new AlternatingCredentialPolicy();
		$provider = new MutablePolicyProvider( $policy );
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ), $catalog );
		$secrets  = SecretsFileTestFactory::create( $path, array(), $catalog );

		$secrets->saveCredential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array(),
			),
			'fixture-secret-canary'
		);

		self::assertSame( 1, $policy->providerCalls );
		self::assertArrayHasKey( 'fixture_primary', $secrets->credentialProfiles( 'fixture' ) );
		$this->assertCredentialPolicyUnavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
		self::assertSame( $provider, $registry->get( 'fixture' ) );

		unlink( $path );
		if ( is_file( $path . '.lock' ) ) {
			unlink( $path . '.lock' );
		}
	}

	public function testUnknownProviderAccessFailsBeforeSidecarInclusion(): void {
		$path = sys_get_temp_dir() . '/ran-booster-explosive-' . bin2hex( random_bytes( 8 ) ) . '.php';
		file_put_contents( $path, "<?php throw new \\RuntimeException('explosive-sidecar-include');" );
		chmod( $path, 0600 );

		try {
			$secrets = new SecretsFile( $path, array(), ShippedSecretPolicyCatalog::create() );
			$secrets->credentialProfiles( 'fixture' );
			self::fail( 'An unsupported provider must fail before the sidecar is included.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Credential provider is not supported.', $exception->getMessage() );
		} finally {
			unlink( $path );
		}
	}

	public function testDeactivatedProviderRecordsRemainOpaqueWhileShippedRecordsStayUsable(): void {
		$path          = sys_get_temp_dir() . '/ran-booster-deactivated-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$activeCatalog = ShippedSecretPolicyCatalog::create();
		$activeSecrets = SecretsFileTestFactory::create( $path, array(), $activeCatalog );
		$registry      = new ProviderRegistry(
			new \Tests\Support\NullLoggingFacade(),
			array(),
			$activeCatalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $activeSecrets->credentialsFor( $code )
		);
		$registry->registerWithCredentialStore(
			'fixture',
			static fn ( ProviderCredentialStore $credentials ): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
		);

		$activeSecrets->saveCredential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'ran-lab' ),
			),
			'fixture-secret-canary'
		);
		$activeSecrets->saveCredential(
			'gh',
			'github_primary',
			array(
				'label'         => 'GitHub primary',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'github-secret-canary'
		);
		$before = $activeSecrets->credentialMaterial( 'fixture', 'fixture_primary' );

		$deactivated = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		self::assertSame( 'github-secret-canary', $deactivated->credentialMaterial( 'gh', 'github_primary' )['secret'] );
		$deactivated->saveCredential(
			'gh',
			'github_primary',
			array(
				'label'         => 'GitHub renamed',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			null
		);
		$reactivated = SecretsFileTestFactory::create( $path, array(), $activeCatalog );
		$after       = $reactivated->credentialMaterial( 'fixture', 'fixture_primary' );

		self::assertSame( $before, $after );
		self::assertSame( 'GitHub renamed', $deactivated->credentialProfiles( 'gh' )['github_primary']['label'] );

		unlink( $path );
		if ( is_file( $path . '.lock' ) ) {
			unlink( $path . '.lock' );
		}
	}

	public function testWebhookRequestRetainsOnlyProviderDeclaredHeadersAndBoundsPolicySize(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'X-GitHub-Event' => 'push',
				'X-Event-Key'    => 'repo:push',
				'Authorization'  => 'Bearer canary',
			),
			array( 'x-github-event' )
		);

		self::assertSame( 'push', $request->getHeader( 'x-github-event' ) );
		self::assertNull( $request->getHeader( 'x-event-key' ) );
		self::assertNull( $request->getHeader( 'authorization' ) );

		$this->expectException( \InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(),
			array_map( static fn ( int $index ): string => 'x-provider-' . $index, range( 1, 17 ) )
		);
	}

	public function testWebhookPolicyCannotRetainUniversalSensitiveHeaders(): void {
		$this->expectException( \InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'Authorization' => 'Bearer canary' ),
			array( 'authorization' )
		);
	}

	private function assertCredentialPolicyUnavailable( ProviderSecretPolicyCatalog $catalog, ProviderCode $code ): void {
		try {
			$catalog->credentialPolicy( $code );
			self::fail( 'Credential policy catalog must remain unchanged.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Credential provider is not supported.', $exception->getMessage() );
		}
	}

	private function assertWebhookPolicyUnavailable( ProviderSecretPolicyCatalog $catalog, ProviderCode $code ): void {
		try {
			$catalog->webhookPolicy( $code );
			self::fail( 'Webhook policy catalog must remain unchanged.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Webhook provider is not supported.', $exception->getMessage() );
		}
	}

	private function assertValidSameCodeRetry(
		ProviderRegistry $registry,
		ProviderSecretPolicyCatalog $catalog,
		ProviderCode $code
	): void {
		$provider = new ExternalFixtureProvider( $code->value );
		$registry->register( $provider );

		self::assertSame( $provider, $registry->get( $code ) );
		self::assertSame( $code->value, $catalog->credentialPolicy( $code )->getProvider()->value );
	}
}

final readonly class AtomicPolicyProvider implements RepositoryProvider, ProviderCredentialPolicySupplier, WebhookNormalizer {
	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public function __construct(
		private ProviderCode $code,
		private ProviderCredentialPolicy $credentialPolicy,
		private ProviderWebhookPolicy $webhookPolicy
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( $this->code, 'Atomic fixture', 'https://example.test/', 'Owner' );
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return $this->credentialPolicy;
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return $this->webhookPolicy;
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}

final readonly class EmptyProviderDiagnostics implements ProviderDiagnostics {

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array();
	}
}

final class AlternatingMetadataProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {
	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public int $metadataCalls = 0;

	public function getMetadata(): ProviderMetadata {
		++$this->metadataCalls;
		$code = 1 === $this->metadataCalls ? 'fixture' : 'other-fixture';

		return new ProviderMetadata(
			ProviderCode::parse( $code ),
			'Alternating fixture',
			'https://example.test/',
			'Owner',
			new ProviderAdminMetadata(
				array(
					new \RAN\RepositoryProvider\Admin\CredentialKindMetadata(
						'api-key',
						'API key',
						'API key',
						'',
						array()
					),
				),
				array()
			)
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return new ExternalFixtureCredentialPolicy( ProviderCode::parse( 'fixture' ) );
	}
}

final class AlternatingCredentialPolicy implements ProviderCredentialPolicy {

	public int $providerCalls = 0;

	public function getProvider(): ProviderCode {
		++$this->providerCalls;

		return ProviderCode::parse( 1 === $this->providerCalls ? 'fixture' : 'other-fixture' );
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		return array(
			'label'         => is_string( $metadata['label'] ?? null ) ? $metadata['label'] : 'Fixture',
			'kind'          => 'api-key',
			'configuration' => is_array( $metadata['configuration'] ?? null ) ? $metadata['configuration'] : array(),
			'secret'        => is_string( $secret ) ? $secret : '',
		);
	}

	public function getConstantNames(): array {
		return array();
	}

	public function credentialFromConstants( array $constants ): ?array {
		return null;
	}
}

final readonly class MutablePolicyProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {
	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public function __construct( private ProviderCredentialPolicy $policy ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'fixture' ),
			'Mutable policy fixture',
			'https://example.test/',
			'Owner',
			new ProviderAdminMetadata(
				array(
					new \RAN\RepositoryProvider\Admin\CredentialKindMetadata(
						'api-key',
						'API key',
						'API key',
						'',
						array()
					),
				),
				array()
			)
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return $this->policy;
	}
}

final readonly class ExplodingCredentialPolicy implements ProviderCredentialPolicy {

	public function getProvider(): ProviderCode {
		throw new RuntimeException( 'credential-policy-token-canary' );
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'not called' );
	}

	public function getConstantNames(): array {
		return array();
	}

	public function credentialFromConstants( array $constants ): ?array {
		return null;
	}
}

final readonly class ExplodingWebhookPolicy implements ProviderWebhookPolicy {

	public function getProvider(): ProviderCode {
		throw new RuntimeException( 'webhook-policy-path-canary' );
	}

	public function getRetainedHeaders(): array {
		return array();
	}

	public function getSignatureHeader(): string {
		return 'x-fixture-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'not called' );
	}

	public function getConstantNames(): array {
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array {
		return null;
	}

	public function authorizeWebhook( \RAN\RepositoryProvider\SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return false;
	}
}

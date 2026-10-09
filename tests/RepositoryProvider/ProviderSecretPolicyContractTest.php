<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

// Direct local filesystem operations verify sidecar policy and deactivation behavior.

use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
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
use RAN\Tests\Support\CredentialUsageDatabase;
use RuntimeException;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureCredentialPolicy;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use RAN\Tests\Secrets\SecretsFileTestFactory;

final class ProviderSecretPolicyContractTest extends TestCase {

	public function test_policy_failure_leaves_both_registry_and_catalog_unchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );
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

		$this->assert_credential_policy_unavailable( $catalog, $code );
		$this->assert_webhook_policy_unavailable( $catalog, $code );
		$this->assert_valid_same_code_retry( $registry, $catalog, $code );
	}

	public function test_credential_policy_identity_failure_is_redacted_and_atomic(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );
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

		$this->assert_credential_policy_unavailable( $catalog, $code );
		$this->assert_webhook_policy_unavailable( $catalog, $code );
		$this->assert_valid_same_code_retry( $registry, $catalog, $code );
	}

	public function test_webhook_policy_identity_failure_is_redacted_and_atomic(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );
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

		$this->assert_credential_policy_unavailable( $catalog, $code );
		$this->assert_webhook_policy_unavailable( $catalog, $code );
		$this->assert_valid_same_code_retry( $registry, $catalog, $code );
	}

	public function test_webhook_metadata_without_the_optional_capability_is_rejected(): void {
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function get_metadata(): ProviderMetadata {
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

			public function get_provider_diagnostics(): ProviderDiagnostics {
				return new EmptyProviderDiagnostics();
			}
		};

		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'Webhook metadata without a webhook policy must be rejected.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_webhook_policy_unavailable( $catalog, ProviderCode::parse( 'missing-webhook' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'missing-webhook' ) );
	}

	public function test_credential_metadata_without_the_optional_policy_is_rejected_atomically(): void {
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
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
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'Credential metadata without a credential policy must be rejected.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'missing-credential' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'missing-credential' ) );
	}

	public function test_external_provider_without_webhooks_builds_settings_and_manual_deployment_state(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$provider = null;
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		$registry->register_with_credential_store(
			'fixture',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			) use ( &$provider ): ExternalFixtureProvider {
				$provider = new ExternalFixtureProvider( 'fixture', $credentials );

				return $provider;
			}
		);
		$settings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->build( 'fixture' );

		self::assertInstanceOf( ExternalFixtureProvider::class, $provider );
		self::assertSame( array(), $settings['webhook_profiles'] );
		self::assertFalse( $settings['provider']['capabilities']['webhooks'] );
		self::assertFalse( ( new \ReflectionClass( $provider ) )->implementsInterface( WebhookNormalizer::class ) );
	}

	public function test_credential_store_factory_failures_are_redacted_and_leave_registration_unchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static function ( ProviderCode $code ): ProviderCredentialStore {
				throw new RuntimeException( 'credential-store-token-canary' );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static fn (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
			);
			self::fail( 'A failing internal store factory must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_external_provider_factory_failures_are_redacted_and_leave_registration_unchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static function (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): RepositoryProvider {
					throw new RuntimeException( 'provider-factory-path-canary' );
				}
			);
			self::fail( 'A failing external provider factory must reject registration.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_provider_metadata_failure_leaves_catalog_and_registry_retryable(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				throw new RuntimeException( 'provider-metadata-token-canary' );
			}
		};

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static fn (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): RepositoryProvider => $provider
			);
			self::fail( 'A provider with unavailable metadata must be rejected.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertStringNotContainsString( 'canary', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_direct_registration_metadata_failure_is_redacted_and_retryable(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
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

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_webhook_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_provider_factory_cannot_read_credentials_before_policy_registration(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static function (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): ExternalFixtureProvider {
					$credentials->credential_material();

					return new ExternalFixtureProvider( 'fixture', $credentials );
				}
			);
			self::fail( 'Credential reads during provider construction must be rejected.' );
		} catch ( InvalidProviderPolicy $exception ) {
			self::assertSame( 'The provider factory returned an invalid provider.', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_requested_provider_code_is_checked_before_issuing_credentials(): void {
		$issued   = 0;
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			array( new ExternalFixtureProvider( 'fixture' ) ),
			$catalog,
			static function ( ProviderCode $code ) use ( $secrets, &$issued ): ProviderCredentialStore {
				++$issued;

				return $secrets->credentials_for( $code );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static fn (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
			);
			self::fail( 'A duplicate code must fail before credentials are issued.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 0, $issued );
			self::assertSame( 'Repository provider is already registered.', $exception->getMessage() );
			self::assertCount( 1, $registry->all() );
		}
	}

	public function test_sealed_credential_registration_rejects_before_either_factory_runs(): void {
		$credential_store_calls = 0;
		$provider_calls         = 0;
		$catalog                = new ProviderSecretPolicyCatalog();
		$registry               = new ProviderRegistry(
			array(),
			$catalog,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static function ( ProviderCode $code ) use ( &$credential_store_calls ): ProviderCredentialStore {
				++$credential_store_calls;

				throw new RuntimeException( 'The credential-store factory must not run after sealing.' );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		$registry->seal();

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static function (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				) use ( &$provider_calls ): RepositoryProvider {
					++$provider_calls;

					return new ExternalFixtureProvider( 'fixture', $credentials );
				}
			);
			self::fail( 'A sealed registry must reject credential registration.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'Repository provider registration is closed.', $exception->getMessage() );
			self::assertSame( 0, $credential_store_calls );
			self::assertSame( 0, $provider_calls );
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_webhook_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_provider_factory_identity_mismatch_leaves_catalog_and_registry_unchanged(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		try {
			$registry->register_with_credential_store(
				'fixture',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static fn (
					ProviderCredentialStore $credentials,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				): ExternalFixtureProvider => new ExternalFixtureProvider( 'other-fixture', $credentials )
			);
			self::fail( 'A mismatched provider factory must reject registration.' );
		} catch ( InvalidProviderPolicy ) {
			self::assertSame( array(), $registry->all() );
		}

		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'fixture' ) );
		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
		$this->assert_valid_same_code_retry( $registry, $catalog, ProviderCode::parse( 'fixture' ) );
	}

	public function test_provider_metadata_is_captured_once_before_atomic_registration(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( '/path/that/does/not/exist.php', array(), $catalog );
		$provider = new AlternatingMetadataProvider();
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		$registry->register_with_credential_store(
			'fixture',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			): RepositoryProvider => $provider
		);

		self::assertSame( 1, $provider->metadata_calls );
		self::assertSame( $provider, $registry->get( 'fixture' ) );
		self::assertArrayNotHasKey( 'other-fixture', $registry->all() );
		self::assertSame( 'fixture', $registry->metadata()['fixture']->code->value );
		self::assertSame( 1, $provider->metadata_calls );
		self::assertSame( 'fixture', $catalog->credential_policy( 'fixture' )->get_provider()->value );
		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
	}

	public function test_credential_policy_identity_is_frozen_at_registration(): void {
		$path     = sys_get_temp_dir() . '/ran-booster-policy-drift-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$catalog  = new ProviderSecretPolicyCatalog();
		$policy   = new AlternatingCredentialPolicy();
		$provider = new MutablePolicyProvider( $policy );
		$registry = new ProviderRegistry( array( $provider ), $catalog );
		$secrets  = SecretsFileTestFactory::create( $path, array(), $catalog );

		$secrets->save_credential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array(),
			),
			'fixture-secret-canary'
		);

		self::assertSame( 1, $policy->provider_calls );
		self::assertArrayHasKey( 'fixture_primary', $secrets->credential_profiles( 'fixture' ) );
		$this->assert_credential_policy_unavailable( $catalog, ProviderCode::parse( 'other-fixture' ) );
		self::assertSame( $provider, $registry->get( 'fixture' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
		unlink( $path );
		if ( is_file( $path . '.lock' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
			unlink( $path . '.lock' );
		}
	}

	public function test_unknown_provider_access_fails_before_sidecar_inclusion(): void {
		$path = sys_get_temp_dir() . '/ran-booster-explosive-' . bin2hex( random_bytes( 8 ) ) . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the local test-owned fixture bytes exercised by this contract.
		file_put_contents( $path, "<?php throw new \\RuntimeException('explosive-sidecar-include');" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The test controls the local fixture permissions at the secrets boundary.
		chmod( $path, 0600 );

		try {
			$secrets = new SecretsFile( $path, array(), ShippedSecretPolicyCatalog::create() );
			$secrets->credential_profiles( 'fixture' );
			self::fail( 'An unsupported provider must fail before the sidecar is included.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Credential provider is not supported.', $exception->getMessage() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
			unlink( $path );
		}
	}

	public function test_deactivated_provider_records_remain_opaque_while_shipped_records_stay_usable(): void {
		$path           = sys_get_temp_dir() . '/ran-booster-deactivated-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$active_catalog = ShippedSecretPolicyCatalog::create();
		$active_secrets = SecretsFileTestFactory::create( $path, array(), $active_catalog );
		$registry       = new ProviderRegistry(
			array(),
			$active_catalog,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $active_secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		$registry->register_with_credential_store(
			'fixture',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			): ExternalFixtureProvider => new ExternalFixtureProvider( 'fixture', $credentials )
		);

		$active_secrets->save_credential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'ran-lab' ),
			),
			'fixture-secret-canary'
		);
		$active_secrets->save_credential(
			'gh',
			'github_primary',
			array(
				'label'         => 'GitHub primary',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'github-secret-canary'
		);
		$before = $active_secrets->credential_material( 'fixture', 'fixture_primary' );

		$deactivated = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		$material    = $deactivated->credential_material( 'gh', 'github_primary' );
		self::assertNotNull( $material );
		self::assertSame( 'github-secret-canary', $material['secret'] );
		$deactivated->save_credential(
			'gh',
			'github_primary',
			array(
				'label'         => 'GitHub renamed',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			null
		);
		$reactivated = SecretsFileTestFactory::create( $path, array(), $active_catalog );
		$after       = $reactivated->credential_material( 'fixture', 'fixture_primary' );

		self::assertSame( $before, $after );
		self::assertSame( 'GitHub renamed', $deactivated->credential_profiles( 'gh' )['github_primary']['label'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
		unlink( $path );
		if ( is_file( $path . '.lock' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
			unlink( $path . '.lock' );
		}
	}

	public function test_webhook_request_retains_only_provider_declared_headers_and_bounds_policy_size(): void {
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

		self::assertSame( 'push', $request->get_header( 'x-github-event' ) );
		self::assertNull( $request->get_header( 'x-event-key' ) );
		self::assertNull( $request->get_header( 'authorization' ) );

		$this->expectException( \InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(),
			array_map( static fn ( int $index ): string => 'x-provider-' . $index, range( 1, 17 ) )
		);
	}

	public function test_webhook_policy_cannot_retain_universal_sensitive_headers(): void {
		$this->expectException( \InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'Authorization' => 'Bearer canary' ),
			array( 'authorization' )
		);
	}

	private function assert_credential_policy_unavailable( ProviderSecretPolicyCatalog $catalog, ProviderCode $code ): void {
		try {
			$catalog->credential_policy( $code );
			self::fail( 'Credential policy catalog must remain unchanged.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Credential provider is not supported.', $exception->getMessage() );
		}
	}

	private function assert_webhook_policy_unavailable( ProviderSecretPolicyCatalog $catalog, ProviderCode $code ): void {
		try {
			$catalog->webhook_policy( $code );
			self::fail( 'Webhook policy catalog must remain unchanged.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Webhook provider is not supported.', $exception->getMessage() );
		}
	}

	private function assert_valid_same_code_retry(
		ProviderRegistry $registry,
		ProviderSecretPolicyCatalog $catalog,
		ProviderCode $code
	): void {
		$provider = new ExternalFixtureProvider( $code->value );
		$registry->register( $provider );

		self::assertSame( $provider, $registry->get( $code ) );
		self::assertSame( $code->value, $catalog->credential_policy( $code )->get_provider()->value );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class AtomicPolicyProvider implements RepositoryProvider, ProviderCredentialPolicySupplier, WebhookNormalizer {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public function __construct(
		private ProviderCode $code,
		private ProviderCredentialPolicy $credential_policy,
		private ProviderWebhookPolicy $webhook_policy
	) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( $this->code, 'Atomic fixture', 'https://example.test/', 'Owner' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->credential_policy;
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return $this->webhook_policy;
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class EmptyProviderDiagnostics implements ProviderDiagnostics {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of diagnose retains the production method contract; these inputs do not affect this controlled result.
	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class AlternatingMetadataProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public int $metadata_calls = 0;

	public function get_metadata(): ProviderMetadata {
		++$this->metadata_calls;
		$code = 1 === $this->metadata_calls ? 'fixture' : 'other-fixture';

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

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return new ExternalFixtureCredentialPolicy( ProviderCode::parse( 'fixture' ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class AlternatingCredentialPolicy implements ProviderCredentialPolicy {

	public int $provider_calls = 0;

	public function get_provider(): ProviderCode {
		++$this->provider_calls;

		return ProviderCode::parse( 1 === $this->provider_calls ? 'fixture' : 'other-fixture' );
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		return array(
			'label'         => is_string( $metadata['label'] ?? null ) ? $metadata['label'] : 'Fixture',
			'kind'          => 'api-key',
			'configuration' => is_array( $metadata['configuration'] ?? null ) ? $metadata['configuration'] : array(),
			'secret'        => is_string( $secret ) ? $secret : '',
		);
	}

	public function get_constant_names(): array {
		return array();
	}

	public function credential_from_constants( array $constants ): ?array {
		return null;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class MutablePolicyProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public function __construct( private ProviderCredentialPolicy $policy ) {
	}

	public function get_metadata(): ProviderMetadata {
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

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new EmptyProviderDiagnostics();
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->policy;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class ExplodingCredentialPolicy implements ProviderCredentialPolicy {

	public function get_provider(): ProviderCode {
		throw new RuntimeException( 'credential-policy-token-canary' );
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'not called' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function credential_from_constants( array $constants ): ?array {
		return null;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class ExplodingWebhookPolicy implements ProviderWebhookPolicy {

	public function get_provider(): ProviderCode {
		throw new RuntimeException( 'webhook-policy-path-canary' );
	}

	public function get_retained_headers(): array {
		return array();
	}

	public function get_signature_header(): string {
		return 'x-fixture-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'not called' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		return null;
	}

	public function authorize_webhook( \RAN\RepositoryProvider\SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return false;
	}
}

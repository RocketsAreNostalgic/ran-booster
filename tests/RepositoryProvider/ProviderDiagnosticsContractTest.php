<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

// Direct local filesystem operations verify the provider-owned sidecar contract.

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Tests\Secrets\SecretsFileTestFactory;
use RAN\Tests\Support\CredentialUsageDatabase;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;

final class ProviderDiagnosticsContractTest extends TestCase {

	public function test_novel_provider_supplies_diagnostics_and_supports_the_manual_package_path(): void {
		$path            = sys_get_temp_dir() . '/ran-booster-fixture-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secret_policies = new ProviderSecretPolicyCatalog();
		$secrets         = SecretsFileTestFactory::create( $path, array(), $secret_policies );
		$provider        = null;
		$registry        = new ProviderRegistry(
			array(),
			$secret_policies,
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
		self::assertInstanceOf( ExternalFixtureProvider::class, $provider );
		self::assertSame( 0, $provider->get_client()->get_requests(), 'Registration must not run provider diagnostics or call the provider client.' );
		$registry->seal();
		$credential_id = $secrets->save_credential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'ran-lab' ),
			),
			'fixture-secret-canary'
		);

		self::assertSame( $provider, $registry->get( 'fixture' ) );
		self::assertTrue( $registry->is_sealed() );
		self::assertTrue( $provider->validate_credential( $credential_id )->is_valid() );
		self::assertSame( 'ran-lab', $secrets->credential_profiles( 'fixture' )[ $credential_id ]['configuration']['tenant'] );

		$resolved = ( new PackageRepositoryRequestResolver( $registry ) )->resolve(
			array(
				'provider'      => 'fixture',
				'repository'    => 'group/subgroup/package',
				'branch'        => '',
				'credential_id' => $credential_id,
			)
		);

		self::assertSame( 'fixture', $resolved['provider'] );
		self::assertSame( 'group/subgroup/package', $resolved['repository'] );
		self::assertSame( 'package', $resolved['package_slug'] );
		self::assertSame( 'fixture:group/subgroup/package', $resolved['provider_repository_id'] );
		self::assertSame( 'main', $resolved['branch'] );

		$package_settings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ), new \RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore(), new \RAN\Tests\Support\InMemoryCredentialExpiryObservationStore() ) )
			->build_package_form( 'fixture' );
		self::assertSame( 'fixture', $package_settings['default_provider'] );
		self::assertSame( 'fixture', $package_settings['providers'][0]['code'] );
		self::assertTrue( $package_settings['providers'][0]['deploy'] );
		self::assertFalse( $package_settings['providers'][0]['webhooks'] );
		self::assertSame(
			'admin.php?page=ran-booster&tab=fixture&view=credentials',
			$package_settings['providers'][0]['credentials_url']
		);

		$reference = new RepositoryReference(
			$resolved['repository'],
			$resolved['provider_repository_id'],
			false,
			null
		);
		$archive   = $provider->prepare_archive( new ArchiveRequest( $reference, 'main' ) );

		$resolved_ref = sha1( "group/subgroup/package\0main" );
		self::assertSame( $resolved_ref, $archive->get_resolved_ref() );
		self::assertSame( 'https://fixtures.example.test/group/subgroup/package/' . $resolved_ref . '.zip', $archive->get_url() );
		self::assertFalse( ( new \ReflectionClass( $provider ) )->implementsInterface( WebhookNormalizer::class ) );

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
			unlink( $path );
		}
		if ( is_file( $path . '.lock' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned temporary fixture during cleanup.
			unlink( $path . '.lock' );
		}
	}

	public function test_fixture_provider_diagnostics_use_the_same_provider_client(): void {
		$provider    = new ExternalFixtureProvider();
		$diagnostics = $provider->get_provider_diagnostics();
		$results     = $diagnostics->diagnose(
			new ProviderDiagnosticRequest( null, 'example/package' )
		);

		self::assertCount( 3, $results );
		self::assertSame( 'fixture.environment.ready', $results[0]->code );
		self::assertSame( 'fixture.credential.public', $results[1]->code );
		self::assertSame( 'fixture.repository.reachable', $results[2]->code );
		self::assertSame( 2, $provider->get_client()->get_requests() );
	}

	public function test_missing_diagnostics_are_rejected_before_registry_mutation(): void {
		$provider = new class() {
			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'missing-diagnostics' ),
					'Missing diagnostics',
					'https://example.test/',
					'Owner'
				);
			}
		};
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			// @phpstan-ignore argument.type (A provider without the required diagnostics contract must be rejected at the typed registration boundary.)
			$registry->register( $provider );
			self::fail( 'A provider without diagnostics must be rejected.' );
		} catch ( \TypeError ) {
			self::assertSame( array(), $registry->all() );
		}

		$valid = new ExternalFixtureProvider( 'missing-diagnostics' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'missing-diagnostics' ) );
		self::assertSame( 'missing-diagnostics', $catalog->credential_policy( 'missing-diagnostics' )->get_provider()->value );
	}

	public function test_failing_diagnostics_supplier_is_safely_rejected_before_mutation(): void {
		$provider = new class() implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'unsafe' ), 'Unsafe', 'https://example.test/', 'Owner' );
			}

			public function get_provider_diagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
				throw new \RuntimeException( 'token-bearing-provider-error' );
			}
		};
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'A failing diagnostics supplier must be rejected.' );
		} catch ( \LogicException $exception ) {
			self::assertNull( $exception->getPrevious() );
			self::assertStringNotContainsString( 'token-bearing', $exception->getMessage() );
			self::assertSame( array(), $registry->all() );
		}

		$valid = new ExternalFixtureProvider( 'unsafe' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'unsafe' ) );
		self::assertSame( 'unsafe', $catalog->credential_policy( 'unsafe' )->get_provider()->value );
	}

	public function test_selected_provider_diagnostics_do_not_sweep_the_sealed_registry(): void {
		$selected = new ExternalFixtureProvider( 'selected' );
		$idle     = new ExternalFixtureProvider( 'idle' );
		$registry = new ProviderRegistry( array( $selected, $idle ) );
		$registry->seal();

		$provider = $registry->get( 'selected' );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$provider->get_provider_diagnostics()->diagnose( new ProviderDiagnosticRequest( null, 'example/package' ) );

		self::assertSame( 2, $selected->get_client()->get_requests() );
		self::assertSame( 0, $idle->get_client()->get_requests() );
	}

	public function test_sealed_registry_rejects_late_registration(): void {
		$metadata_calls = 0;
		$provider       = new class( $metadata_calls ) implements RepositoryProvider {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function __construct( private int &$metadata_calls ) {
			}

			public function get_metadata(): ProviderMetadata {
				++$this->metadata_calls;

				return new ProviderMetadata( ProviderCode::parse( 'another-fixture' ), 'Another fixture', 'https://example.test/', 'Owner' );
			}

			public function get_provider_diagnostics(): ProviderDiagnostics {
				throw new \LogicException( 'Diagnostics must not be requested after sealing.' );
			}
		};
		$registry       = new ProviderRegistry( array( new ExternalFixtureProvider() ) );
		$registry->seal();

		try {
			$registry->register( $provider );
			self::fail( 'A sealed registry must reject registration.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'Repository provider registration is closed.', $exception->getMessage() );
			self::assertSame( 0, $metadata_calls );
		}
	}

	/** @return array<string, array{string, string, class-string<\Throwable>, string}> */
	public static function reentrant_registration_callbacks(): array {
		$boundaries = array(
			'credential_store_factory'   => array( InvalidProviderPolicy::class, 'The provider credential-store factory returned an invalid store.' ),
			'delivery_evidence_factory'  => array( InvalidProviderPolicy::class, 'The provider delivery-evidence factory returned an invalid reader.' ),
			'provider_factory'           => array( InvalidProviderPolicy::class, 'The provider factory returned an invalid provider.' ),
			'metadata'                   => array( InvalidProviderPolicy::class, 'Repository provider metadata could not be supplied.' ),
			'diagnostics'                => array( LogicException::class, 'Repository provider diagnostics could not be supplied.' ),
			'credential_policy_getter'   => array( InvalidProviderPolicy::class, 'The provider credential policy is unavailable.' ),
			'webhook_policy_getter'      => array( InvalidProviderPolicy::class, 'The provider webhook policy is unavailable.' ),
			'credential_policy_identity' => array( InvalidProviderPolicy::class, 'The provider credential policy is unavailable.' ),
			'webhook_policy_identity'    => array( InvalidProviderPolicy::class, 'The provider webhook policy is unavailable.' ),
		);
		$cases      = array();

		foreach ( $boundaries as $boundary => $expected ) {
			foreach ( array( 'register', 'register_with_store', 'seal' ) as $operation ) {
				$cases[ $boundary . '/' . $operation ] = array( $boundary, $operation, $expected[0], $expected[1] );
			}
		}

		return $cases;
	}

	/** @param class-string<\Throwable> $expected_exception */
	#[DataProvider( 'reentrant_registration_callbacks' )]
	public function test_every_registration_callback_rejects_registry_reentry(
		string $boundary,
		string $operation,
		string $expected_exception,
		string $expected_message
	): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = null;
		$callback = static function ( string $current_boundary ) use ( &$registry, $boundary, $operation ): void {
			if ( $current_boundary !== $boundary ) {
				return;
			}

			self::assertInstanceOf( ProviderRegistry::class, $registry );

			if ( 'register' === $operation ) {
				$registry->register( new ExternalFixtureProvider( 'nested' ) );
			} elseif ( 'register_with_store' === $operation ) {
				$registry->register_with_credential_store(
					'nested',
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
					static fn (
						ProviderCredentialStore $store,
						AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
						ProviderRegistrationContext $registration_context
					): ExternalFixtureProvider => new ExternalFixtureProvider( 'nested', $store )
				);
			} else {
				$registry->seal();
			}
		};
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static function ( ProviderCode $code ) use ( $callback ): ProviderCredentialStore {
				$callback( 'credential_store_factory' );

				return new RegistrationGuardCredentialStore();
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static function ( ProviderCode $code ) use ( $callback ): AuthenticatedWebhookDeliveryEvidenceReader {
				$callback( 'delivery_evidence_factory' );

				return new EmptyAuthenticatedWebhookDeliveryEvidenceReader();
			}
		);

		try {
			$registry->register_with_credential_store(
				'outer',
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
				static function (
					ProviderCredentialStore $store,
					AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
					ProviderRegistrationContext $registration_context
				) use ( $callback ): RepositoryProvider {
					$callback( 'provider_factory' );

					return new RegistrationGuardProvider( 'outer', $callback );
				}
			);
			self::fail( 'A provider registration callback must not re-enter or seal the registry.' );
		} catch ( \Throwable $exception ) {
			self::assertInstanceOf( $expected_exception, $exception );
			self::assertSame( $expected_message, $exception->getMessage() );
			self::assertSame( array(), $registry->all() );
			self::assertFalse( $registry->is_sealed() );
			self::assertNull( $catalog->find_credential_policy( 'outer' ) );
			self::assertNull( $catalog->find_credential_policy( 'nested' ) );
			self::assertNull( $catalog->find_webhook_policy( 'outer' ) );
			self::assertNull( $catalog->find_webhook_policy( 'nested' ) );
		}

		$valid = new ExternalFixtureProvider( 'outer' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'outer' ) );
	}

	public function test_diagnostic_request_stops_before_afirst_call_beyond_its_limit(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			5.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);

		for ( $call = 1; $call <= 5; ++$call ) {
			self::assertSame( 5.0, $request->claim_remote_call() );
		}

		try {
			$request->claim_remote_call();
			self::fail( 'The sixth remote call must not start.' );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $exception->get_reason() );
			self::assertSame( 5, $request->get_remote_calls() );
		}
	}

	public function test_diagnostic_request_stops_before_acall_after_its_deadline(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			5.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		$now     = 105.0;

		try {
			$request->claim_remote_call();
			self::fail( 'A remote call after the deadline must not start.' );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->get_reason() );
			self::assertSame( 0, $request->get_remote_calls() );
		}
	}

	public function test_diagnostic_request_returns_the_exact_remaining_deadline_for_each_claim(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);

		self::assertSame( 10.0, $request->claim_remote_call() );
		$now = 104.25;
		self::assertSame( 5.75, $request->claim_remote_call() );
		self::assertSame( 2, $request->get_remote_calls() );
	}

	public function test_diagnostic_result_rejects_an_unstable_code(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'Fixture Invalid Code',
			'Safe message.',
			'Safe remediation.'
		);
	}

	/** @return list<array{string, string}> */
	public static function unsafe_diagnostic_text(): array {
		return array(
			array( 'Authorization: Bearer secret-value', 'Safe remediation.' ),
			array( 'Proxy-Authorization: Basic secret-value', 'Safe remediation.' ),
			array( 'Cookie: session=secret-value', 'Safe remediation.' ),
			array( 'Set-Cookie: session=secret-value', 'Safe remediation.' ),
			array( 'X-Hub-Signature-256: sha256=secret-value', 'Safe remediation.' ),
			array( 'X-API-Key: secret-value', 'Safe remediation.' ),
			array( 'X-Auth-Token: secret-value', 'Safe remediation.' ),
			array( 'Private-Token: secret-value', 'Safe remediation.' ),
			array( 'Bearer abcdefgh12345678', 'Safe remediation.' ),
			array( 'Basic abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token ghp_abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token github_pat_abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token ATATT3abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token glpat-abcdefgh12345678', 'Safe remediation.' ),
			array( '{"error":"unsafe response"}', 'Safe remediation.' ),
			array( '[unsafe response]', 'Safe remediation.' ),
			array( 'File /tmp is unavailable.', 'Safe remediation.' ),
			array( 'File /etc/passwd is unavailable.', 'Safe remediation.' ),
			array( 'File C:\\Users\\name is unavailable.', 'Safe remediation.' ),
			array( 'File \\\\server\\share\\file is unavailable.', 'Safe remediation.' ),
			array( 'See https://user:secret@example.test/path', 'Safe remediation.' ),
			array( 'Safe message.', '<a href="https://example.test/">Unsafe</a>' ),
		);
	}

	#[DataProvider( 'unsafe_diagnostic_text' )]
	public function test_diagnostic_result_rejects_unsafe_text( string $message, string $remediation ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'fixture.safe_code',
			$message,
			$remediation
		);
	}

	public function test_diagnostic_result_allows_benign_operational_text_and_relative_package_language(): void {
		$result = new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'fixture.benign',
			'WordPress uses the plugin/theme directory and the provider returned a safe status.',
			'Open provider settings and review repository access.'
		);

		self::assertSame( 'fixture.benign', $result->code );
	}

	public function test_diagnostic_request_accepts_exact_input_boundaries(): void {
		$request = new ProviderDiagnosticRequest( str_repeat( 'a', 128 ), str_repeat( 'b', 512 ) );

		self::assertSame( 128, strlen( (string) $request->get_credential_id() ) );
		self::assertSame( 512, strlen( (string) $request->get_repository() ) );
	}

	public function test_diagnostic_request_preserves_provider_owned_repository_locator_bytes(): void {
		$locator = ' group/subgroup/package ';
		$request = new ProviderDiagnosticRequest( null, $locator );

		self::assertSame( $locator, $request->get_repository() );
	}

	/** @return list<array{string|null, string|null}> */
	public static function overlong_diagnostic_input_provider(): array {
		return array(
			array( str_repeat( 'a', 129 ), null ),
			array( null, str_repeat( 'b', 513 ) ),
		);
	}

	#[DataProvider( 'overlong_diagnostic_input_provider' )]
	public function test_diagnostic_request_rejects_inputs_above_exact_boundaries( ?string $credential_id, ?string $repository ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticRequest( $credential_id, $repository );
	}

	public function test_first_failed_claim_reason_remains_sticky_across_later_failure_kinds(): void {
		$now     = 0.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		for ( $index = 0; $index < 5; ++$index ) {
			$request->claim_remote_call();
		}
		try {
			$request->claim_remote_call();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $exception->get_reason() );
		}
		$now = 11.0;
		try {
			$request->claim_remote_call();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->get_reason() );
		}
		self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $request->get_exhaustion_reason() );

		$now      = 11.0;
		$request2 = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		$now      = 22.0;
		try {
			$request2->claim_remote_call();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->get_reason() );
		}
		$now = 11.0;
		for ( $index = 0; $index < 6; ++$index ) {
			try {
				$request2->claim_remote_call();
			} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
				self::assertContains( $exception->get_reason(), array( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, ProviderDiagnosticBudgetExceeded::DEADLINE ) );
			}
		}
		self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $request2->get_exhaustion_reason() );
	}

	/** @return list<array{int, float}> */
	public static function invalid_diagnostic_ceilings(): array {
		return array(
			array( 6, 10.0 ),
			array( 5, 10.1 ),
		);
	}

	#[DataProvider( 'invalid_diagnostic_ceilings' )]
	public function test_diagnostic_request_rejects_ceilings_above_the_contract( int $calls, float $seconds ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticRequest( null, null, $calls, $seconds );
	}

	public function test_provider_ids_are_open_but_strict_and_reserve_admin_tabs(): void {
		self::assertSame( 'fixture', ProviderCode::parse( 'fixture' )->value );
		self::assertTrue( ProviderCode::parse( 'gh' )->equals( ProviderCode::parse( 'gh' ) ) );
		self::assertNotSame( ProviderCode::parse( 'gh' ), ProviderCode::parse( 'gh' ) );

		foreach ( array( 'Fixture', 'fixture!', 'overview', 'portability', 'documentation', 'troubleshooting' ) as $invalid ) {
			try {
				ProviderCode::parse( $invalid );
				self::fail( 'Expected provider ID rejection.' );
			} catch ( InvalidProviderCode ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_fixture_without_webhooks_fails_push_to_deploy_explicitly(): void {
		$resolver = new PackageRepositoryRequestResolver(
			new ProviderRegistry( array( new ExternalFixtureProvider() ) )
		);

		$this->expectException( UnsupportedProviderCapability::class );

		$resolver->resolve(
			array(
				'provider'          => 'fixture',
				'repository'        => 'example/package',
				'deployment_policy' => DeploymentPolicy::AUTOMATIC->value,
			)
		);
	}

	public function test_fixture_without_webhooks_cannot_contribute_webhook_readiness(): void {
		$registry = new ProviderRegistry( array( new ExternalFixtureProvider() ) );

		$this->expectException( UnsupportedProviderCapability::class );

		$registry->require_capability( 'fixture', WebhookNormalizer::class );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class RegistrationGuardCredentialStore implements ProviderCredentialStore {

	public function credential_profiles(): array {
		return array();
	}

	public function credential_material( ?string $id = null ): ?array {
		return null;
	}

	public function has_webhook_profile(): bool {
		return false;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class RegistrationGuardDiagnostics implements ProviderDiagnostics {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of diagnose retains the production method contract; these inputs do not affect this controlled result.
	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class RegistrationGuardCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct( private ProviderCode $code, private Closure $callback ) {
	}

	public function get_provider(): ProviderCode {
		( $this->callback )( 'credential_policy_identity' );

		return $this->code;
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function credential_from_constants( array $constants ): ?array {
		return null;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class RegistrationGuardWebhookPolicy implements ProviderWebhookPolicy {

	public function __construct( private ProviderCode $code, private Closure $callback ) {
	}

	public function get_provider(): ProviderCode {
		( $this->callback )( 'webhook_policy_identity' );

		return $this->code;
	}

	public function get_retained_headers(): array {
		return array();
	}

	public function get_signature_header(): string {
		return 'x-fixture-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		return null;
	}

	public function authorize_webhook(
		SignedWebhookVerification $verification,
		string $repository_authority_id,
		string $repository
	): bool {
		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return false;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final readonly class RegistrationGuardProvider implements RepositoryProvider, ProviderCredentialPolicySupplier, WebhookNormalizer {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	private ProviderCode $code;

	public function __construct( string $code, private Closure $callback ) {
		$this->code = ProviderCode::parse( $code );
	}

	public function get_metadata(): ProviderMetadata {
		( $this->callback )( 'metadata' );

		return new ProviderMetadata(
			$this->code,
			'Registration guard',
			'https://example.test/',
			'Owner',
			new ProviderAdminMetadata(
				array( new CredentialKindMetadata( 'api-key', 'API key', 'API key' ) ),
				array( new WebhookScopeMetadata( 'owner', 'Owner', true, 'Owner' ) )
			)
		);
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		( $this->callback )( 'diagnostics' );

		return new RegistrationGuardDiagnostics();
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		( $this->callback )( 'credential_policy_getter' );

		return new RegistrationGuardCredentialPolicy( $this->code, $this->callback );
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		( $this->callback )( 'webhook_policy_getter' );

		return new RegistrationGuardWebhookPolicy( $this->code, $this->callback );
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}

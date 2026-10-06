<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

require_once __DIR__ . '/../Support/ExternalFixturePluginWordPressFunctions.php';
require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

	// Direct temporary sidecar operations prove the external credential boundary.
	// phpcs:disable WordPress.WP.AlternativeFunctions

	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\DataProvider;
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
	use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
	use RAN\RepositoryProvider\RepositoryWebhookFitness;
	use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
	use RAN\RepositoryProvider\RepositoryWebhookManagement;
	use RAN\RepositoryProvider\SignedWebhookVerification;
	use RAN\RepositoryProvider\UnsupportedProviderCapability;
	use RAN\RepositoryProvider\WebhookNormalizer;
	use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Tests\Secrets\SecretsFileTestFactory;
use RAN\Storage\CredentialUsageReader;
use RAN\Tests\Support\CredentialUsageDatabase;
	use RAN_Booster_FixtureProvider\Provider;

final class ExternalFixturePluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_presenter_suppresses_management_presentation_for_apartial_capability_provider(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );
		$this->load_fixture_plugin();
		list( $registry, , $path ) = $this->registry();

		try {
			$this->run_registration_hook( $registry );
			$provider = $registry->get( 'fixture-provider' );
			self::assertInstanceOf( Provider::class, $provider );
			$partial = new class( $provider ) implements \RAN\RepositoryProvider\RepositoryProvider, RepositoryWebhookFitness {
				public function __construct( private Provider $provider ) {
				}

				public function get_metadata(): \RAN\RepositoryProvider\ProviderMetadata {
					return $this->provider->get_metadata();
				}

				public function get_provider_diagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
					return $this->provider->get_provider_diagnostics();
				}

				public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
					return $this->provider->resolve_repository( $request );
				}

				public function prepare_archive( ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
					return $this->provider->prepare_archive( $request );
				}

				public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult {
					return $this->provider->assess_setup( $repository_id, $repository, $credential_profile_id );
				}

				public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
					return $this->provider->assess_check( $repository_id, $repository, $credential_profile_id, $hook_id );
				}

				public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
					return $this->provider->assess_reconfigure( $repository_id, $repository, $credential_profile_id, $hook_id );
				}

				public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
					return $this->provider->assess_remove( $repository_id, $repository, $credential_profile_id, $hook_id );
				}

				public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
					return $this->provider->assess_test( $repository_id, $repository, $credential_profile_id, $hook_id );
				}
			};

			$metadata   = $partial->get_metadata();
			$reflection = new \ReflectionClass( ProviderSettingsPresenter::class );
			$projection = $reflection->getMethod( 'provider' )->invoke(
				$reflection->newInstanceWithoutConstructor(),
				$partial,
				$metadata,
				$metadata->admin
			);

			self::assertArrayNotHasKey( 'webhook_assistance', $projection );
		} finally {
			$this->clean_sidecar( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_plugin_loaded_before_the_api_marker_registers_on_the_later_hook(): void {
		$this->load_fixture_plugin();
		self::assertFalse( defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );

		list( $registry, , $path ) = $this->registry();
		$this->run_registration_hook( $registry );

		self::assertInstanceOf( Provider::class, $registry->get( 'fixture-provider' ) );
		$this->clean_sidecar( $path );
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_plugin_loaded_after_the_api_marker_exercises_the_complete_provider_contract(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );
		$this->load_fixture_plugin();
		list( $registry, $secrets, $path ) = $this->registry();

		try {
			$this->run_registration_hook( $registry );
			$registry->seal();
			$provider = $registry->get( 'fixture-provider' );
			self::assertInstanceOf( Provider::class, $provider );
			self::assertSame( 0, $provider->get_client()->get_request_count(), 'Registration must not run provider diagnostics or contact the provider client.' );
			self::assertFalse( $provider->latest_delivery_was_observed() );
			try {
				$secrets->save_credential(
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

			$credential_id = $secrets->save_credential(
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

			self::assertTrue( $provider->validate_credential( $credential_id )->is_valid() );
			self::assertSame( 'ran-lab', $secrets->credential_profiles( 'fixture-provider' )[ $credential_id ]['configuration']['tenant'] );

			$resolved = ( new PackageRepositoryRequestResolver( $registry ) )->resolve(
				array(
					'provider'      => 'fixture-provider',
					'repository'    => 'group/subgroup/package',
					'branch'        => '',
					'credential_id' => $credential_id,
				)
			);

			self::assertSame( 'group/subgroup/package', $resolved['repository'] );
			self::assertSame( 'package', $resolved['package_slug'] );
			self::assertSame( 'fixture:' . hash( 'sha256', 'group/subgroup/package' ), $resolved['provider_repository_id'] );
			self::assertSame( 'fixture-provider', $resolved['provider'] );

			$settings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->build( 'fixture-provider' );
			self::assertSame( 'fixture-provider', $settings['provider']['code'] );
			self::assertArrayNotHasKey( 'webhook_assistance', $settings['provider'] );
			self::assertFalse( $settings['provider']['capabilities']['browse'] );
			self::assertFalse( $settings['provider']['capabilities']['credentialed_public_browse'] );
			self::assertFalse( $settings['provider']['capabilities']['provider_default_public_lookup_profile'] );
			self::assertTrue( $settings['provider']['capabilities']['webhooks'] );
			$package_form     = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )->build_package_form( 'fixture-provider' );
			$package_provider = array_column( $package_form['providers'], null, 'code' )['fixture-provider'];
			self::assertSame( 'fixture-provider', $package_form['default_provider'] );
			self::assertTrue( $package_provider['deploy'] );
			self::assertFalse( $package_provider['browse'] );
			self::assertFalse( $package_provider['credentialed_public_browse'] );
			self::assertFalse( $package_provider['provider_default_public_lookup_profile'] );
			self::assertTrue( $package_provider['webhooks'] );

			$before_diagnostics = $provider->get_client()->get_request_count();
			$now                = 100.0;
			$request            = new ProviderDiagnosticRequest(
				$credential_id,
				'group/subgroup/package',
				ProviderDiagnosticRequest::MAX_REMOTE_CALLS,
				4.25,
				static function () use ( &$now ): float {
					return $now;
				}
			);
			$results            = $provider->get_provider_diagnostics()->diagnose( $request );
			self::assertCount( 3, $results );
			self::assertSame(
				array(
					'fixture-provider.environment.ready',
					'fixture-provider.credential.valid',
					'fixture-provider.repository.reachable',
				),
				array_map( static fn( $result ): string => $result->code, $results )
			);
			self::assertSame( 3, $request->get_remote_calls() );
			self::assertSame( 3, $provider->get_client()->get_request_count() - $before_diagnostics );
			self::assertSame( array( 4.25, 4.25, 4.25 ), $provider->get_client()->get_diagnostic_timeouts() );
			foreach ( $results as $result ) {
				self::assertSame( array( 'status', 'code', 'message', 'remediation' ), array_keys( $result->to_array() ) );
			}

			$reference    = new RepositoryReference(
				$resolved['repository'],
				$resolved['provider_repository_id'],
				true,
				$credential_id
			);
			$archive      = $provider->prepare_archive( new ArchiveRequest( $reference, 'main' ) );
			$resolved_ref = sha1( "group/subgroup/package\0main" );
			self::assertSame( $resolved_ref, $archive->get_resolved_ref() );
			self::assertSame( 'https://fixtures.example.test/group/subgroup/package/' . $resolved_ref . '.zip', $archive->get_url() );

			$automatic = $provider->prepare_archive( new ArchiveRequest( $reference, $resolved_ref, 'main' ) );
			$provider->get_client()->set_branch_head( 'main', '89abcdef0123456789abcdef0123456789abcdef' );
			try {
				$automatic->verify_current_head();
				self::fail( 'The fixture provider must re-check automatic deployment heads before mutation.' );
			} catch ( \RAN\RepositoryProvider\StaleDeployment $exception ) {
				self::assertSame( 409, $exception->getCode() );
			}

			$fitness = $registry->require_capability( 'fixture-provider', RepositoryWebhookFitness::class );
			self::assertSame(
				'fixture.permission.webhook_exact',
				$fitness->assess_setup( $resolved['provider_repository_id'], $resolved['repository'], $credential_id )->to_array()['code']
			);
			$management = $registry->require_capability( 'fixture-provider', RepositoryWebhookManagement::class );
			$operation  = $management->setup( $resolved['provider_repository_id'], $resolved['repository'], 'https://site.example/webhook', $credential_id, str_repeat( 's', 32 ) );
			self::assertSame( 'configured_pending_delivery', $operation->code() );
			self::assertStringNotContainsString( 'fixture_not-a-real-secret', json_encode( $operation->to_array(), JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( str_repeat( 's', 32 ), json_encode( $operation->to_array(), JSON_THROW_ON_ERROR ) );

			$normalizer = $registry->require_capability( 'fixture-provider', WebhookNormalizer::class );
			self::assertSame( $provider, $normalizer );
			self::assertSame( array( 'x-fixture-event', 'x-fixture-signature' ), $normalizer->get_webhook_policy()->get_retained_headers() );
			$before_normalization = $provider->get_client()->get_request_count();
			$request              = new WebhookRequest(
				ProviderCode::parse( 'fixture-provider' ),
				'',
				array( 'x-fixture-event' => 'ping' ),
				$normalizer->get_webhook_policy()->get_retained_headers()
			);
			try {
				$normalizer->normalize_webhook( $request );
				self::fail( 'Fixture normalization must require verified provider evidence.' );
			} catch ( \RAN\RepositoryProvider\WebhookRejected $failure ) {
				self::assertSame( 401, $failure->get_status_code() );
			}
			$verified = $request->with_verification(
				new SignedWebhookVerification(
					ProviderCode::parse( 'fixture-provider' ),
					array(
						array(
							'id'           => 'fixture-webhook',
							'scope'        => 'repository',
							'target'       => $resolved['repository'],
							'authority_id' => $resolved['provider_repository_id'],
						),
					),
				)
			);
			self::assertTrue( $normalizer->normalize_webhook( $verified )->is_probe() );
			$other_provider_request = new WebhookRequest(
				ProviderCode::parse( 'gh' ),
				'',
				array( 'x-fixture-event' => 'ping' ),
				$normalizer->get_webhook_policy()->get_retained_headers()
			);
			try {
				$normalizer->normalize_webhook(
					$other_provider_request->with_verification(
						new SignedWebhookVerification(
							ProviderCode::parse( 'gh' ),
							array(
								array(
									'id'           => 'other',
									'scope'        => 'repository',
									'target'       => 'group/subgroup/package',
									'authority_id' => 'other',
								),
							),
						)
					)
				);
				self::fail( 'Fixture normalization must reject a differently verified provider.' );
			} catch ( \RAN\RepositoryProvider\WebhookRejected $failure ) {
				self::assertSame( 400, $failure->get_status_code() );
			}
			self::assertSame( $before_normalization, $provider->get_client()->get_request_count(), 'Normalization must not contact the provider client.' );

			foreach ( array( RepositoryBrowser::class, CredentialedPublicRepositoryBrowser::class, RepositoryReleaseCandidateListing::class ) as $capability ) {
				try {
					$registry->require_capability( 'fixture-provider', $capability );
					self::fail( 'The fixture must not expose unsupported optional capabilities.' );
				} catch ( UnsupportedProviderCapability ) {
					self::assertSame( $provider, $registry->get( 'fixture-provider' ) );
				}
			}
		} finally {
			$this->clean_sidecar( $path );
		}
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	#[DataProvider( 'incompatible_provider_apis' )]
	public function test_plugin_does_not_register_with_an_incompatible_provider_api( int $api ): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', $api );
		$this->load_fixture_plugin();
		list( $registry, , $path ) = $this->registry();

		try {
			$this->run_registration_hook( $registry );
			self::assertFalse( class_exists( Provider::class, false ) );
		} finally {
			$this->clean_sidecar( $path );
		}
	}

	/** @return array<string, array{int}> */
	public static function incompatible_provider_apis(): array {
		return array(
			'older'    => array( 10 ),
			'api_11'   => array( 11 ),
			'previous' => array( 12 ),
			'future'   => array( 15 ),
		);
	}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
	public function test_plugin_is_harmless_when_booster_is_absent(): void {
		$this->load_fixture_plugin();
		$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();

		self::assertCount( 1, $callbacks );
		$callbacks[0]( new \stdClass() );
		self::assertFalse( class_exists( Provider::class, false ) );
	}

	private function load_fixture_plugin(): void {
		$GLOBALS['ran_booster_external_fixture_actions'] = array();
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-provider/ran-booster-fixture-provider.php';
	}

		/**
		 * @return array{ProviderRegistry, SecretsFile, string}
		 */
	private function registry(): array {
		$path            = sys_get_temp_dir() . '/ran-booster-external-fixture-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secret_policies = new ProviderSecretPolicyCatalog();
		$secrets         = SecretsFileTestFactory::create( $path, array(), $secret_policies );
		$registry        = new ProviderRegistry(
			array(),
			$secret_policies,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Provider registration and dependency callbacks retain the production callback arguments; this fixture supplies a controlled provider or service.
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			},
			new \RAN\RepositoryProvider\ProviderRegistrationContext( static fn (): int => 52_428_800 )
		);

		return array( $registry, $secrets, $path );
	}

	private function run_registration_hook( ProviderRegistry $registry ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();
		self::assertCount( 1, $callbacks );
		$callbacks[0]( $registry );
	}

	private function clean_sidecar( string $path ): void {
		foreach ( array( $path, $path . '.lock' ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				unlink( $candidate );
			}
		}
	}
}

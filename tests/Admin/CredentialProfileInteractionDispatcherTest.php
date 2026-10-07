<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once __DIR__ . '/Interaction/AdminInteractionWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\Admin\Interaction\SignedAdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureCredentialPolicy;
use RAN\Tests\Secrets\InMemorySiteKeyStore;
use RAN\Tests\Secrets\SecretsFileTestFactory;
use RAN\Tests\Support\CredentialUsageDatabase;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

// Direct local filesystem operations exercise the encrypted sidecar fixture.

final class CredentialProfileInteractionDispatcherTest extends TestCase {

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private ProviderRegistry $providers;
	private WordPressUpdaterLock $updater_lock;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;

		$this->directory = sys_get_temp_dir() . '/ran-booster-provider-profile-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native filesystem calls exercise the encrypted sidecar fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$policies        = new ProviderSecretPolicyCatalog();
		$this->secrets   = SecretsFileTestFactory::create( $this->path, array(), $policies );
		$this->providers = new ProviderRegistry( array( $this->provider() ), $policies );

		$this->updater_lock = $this->createStub( WordPressUpdaterLock::class );
		$this->updater_lock->method( 'acquire' )->willReturn( 'credential-fixture-lock' );
		$this->updater_lock->method( 'release' )->willReturn( true );
		$this->seed_stored_profiles();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HX_REQUEST'],
			$_SERVER['HTTP_HX_TARGET'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);

		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native filesystem calls exercise the encrypted sidecar fixture.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native filesystem calls exercise the encrypted sidecar fixture.
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function successful_mutations(): array {
		return array(
			'save access'    => array(
				array(
					'action'        => 'save-access-profile',
					'provider'      => 'fixture',
					'label'         => 'Deployment access',
					'kind'          => 'api-key',
					'configuration' => array( 'tenant' => 'deployment' ),
					'secret'        => 'secret-canary-access',
				),
				'Repository credential saved.',
			),
			'delete access'  => array(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'fixture',
					'id'       => 'credential_existing',
				),
				'Repository credential removed. Public repository lookup now uses anonymous access.',
			),
			'save webhook'   => array(
				array(
					'action'   => 'save-webhook-profile',
					'provider' => 'fixture',
					'label'    => 'Owner hook',
					'scope'    => 'owner',
					'target'   => 'workspace',
					'secret'   => 'secret-canary-webhook-secret-value',
				),
				'Push-to-Deploy secret saved.',
			),
			'delete webhook' => array(
				array(
					'action'   => 'delete-webhook-profile',
					'provider' => 'fixture',
					'id'       => 'webhook_existing',
				),
				'Push-to-Deploy secret removed.',
			),
		);
	}

	/** @return array<string, array{string, string}> */
	public static function unauthorized_actions(): array {
		return array(
			'save access'        => array( 'save-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'delete access'      => array( 'delete-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'save webhook'       => array( 'save-webhook-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'delete webhook'     => array( 'delete-webhook-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'validate access'    => array( 'validate-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'save public lookup' => array( 'save-public-lookup-profile', 'You do not have sufficient permissions to manage Booster provider settings.' ),
		);
	}

	#[DataProvider( 'unauthorized_actions' )]
	public function test_every_profile_action_stops_before_sensitive_state_is_read( string $action, string $denial ): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'credential_profiles' );
		$secrets->expects( self::never() )->method( 'webhook_profiles' );
		$secrets->expects( self::never() )->method( 'save_credential' );
		$secrets->expects( self::never() )->method( 'delete_credential' );
		$secrets->expects( self::never() )->method( 'save_webhook' );
		$secrets->expects( self::never() )->method( 'delete_webhook' );
		$dashboard            = $this->createMock( Dashboard::class );
		$interaction          = new CapturingProviderProfileInteraction();
		$_POST['ran_booster'] = array(
			'action'     => $action,
			'provider'   => 'fixture',
			'id'         => 'credential_existing',
			'secret'     => 'secret-canary-must-not-be-read',
			'profile_id' => 'credential_existing',
		);

		try {
			$this->dispatcher(
				$dashboard,
				$secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)->dispatch_post_requests();
			self::fail( 'An unauthorized provider-profile action must terminate before parsing state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( $denial, $failure->getMessage() );
		}

		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'unauthorized_actions' )]
	public function test_every_profile_action_stops_at_its_exact_invalid_nonce_before_sensitive_state_is_read( string $action ): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$secrets                                 = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'credential_profiles' );
		$secrets->expects( self::never() )->method( 'webhook_profiles' );
		$secrets->expects( self::never() )->method( 'save_credential' );
		$secrets->expects( self::never() )->method( 'delete_credential' );
		$secrets->expects( self::never() )->method( 'save_webhook' );
		$secrets->expects( self::never() )->method( 'delete_webhook' );
		$dashboard            = $this->createMock( Dashboard::class );
		$interaction          = new CapturingProviderProfileInteraction();
		$_POST['ran_booster'] = array(
			'action'     => $action,
			'provider'   => 'fixture',
			'id'         => 'credential_existing',
			'secret'     => 'secret-canary-must-not-be-read',
			'profile_id' => 'credential_existing',
		);

		try {
			$this->dispatcher(
				$dashboard,
				$secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)->dispatch_post_requests();
			self::fail( 'An invalid provider-profile nonce must terminate before parsing state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Invalid nonce.', $failure->getMessage() );
		}

		$expected_nonce = 'save-public-lookup-profile' === $action
			? 'ran-booster-save-public-lookup-profile'
			: 'ran-booster-save-secrets';
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $expected_nonce ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/**
	 * @param array<string, mixed> $request
	 */
	#[DataProvider( 'successful_mutations' )]
	public function test_every_remaining_mutation_uses_verified_signed_success(
		array $request,
		string $expected_message
	): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->method( 'add_failure_message' )
			->willReturnCallback(
				static function ( mixed $error, \Throwable $exception ): void {
					unset( $error );
					self::fail( 'Unexpected mutation failure: ' . $exception->getMessage() );
				}
			);
		$lookup               = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles     = array( 'fixture' => 'credential_existing' );
		$_GET['view']         = str_contains( $request['action'], 'access' ) ? 'credentials' : 'secrets';
		$_POST['ran_booster'] = $request;
		$lock                 = $this->createMock( WordPressUpdaterLock::class );
		if ( str_contains( $request['action'], 'access' ) ) {
			$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'credential-lock' );
			$lock->expects( self::once() )->method( 'release' )->with( 'credential-lock' )->willReturn( true );
		} else {
			$lock->expects( self::never() )->method( 'acquire' );
			$lock->expects( self::never() )->method( 'release' );
		}
		$this->updater_lock = $lock;
		$dispatcher         = $this->dispatcher( $dashboard, $this->secrets, $interaction, $lookup );

		$response = $interaction->dispatch( $dispatcher );
		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'success', $response->kind );
		self::assertSame( $expected_message, $response->feedback_message );
		self::assertSame( 'core:' . $request['action'], $response->request->operation );
		self::assertStringNotContainsString( 'secret-canary', $response->feedback_message );
		self::assertStringNotContainsString( 'secret-canary', $response->request->canonical_url );

		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-secrets' ), $GLOBALS['ran_booster_test_nonce_checks'] );

		if ( 'save-access-profile' === $request['action'] ) {
			self::assertContains(
				'Deployment access',
				array_column( $this->secrets->credential_profiles( 'fixture' ), 'label' )
			);
		} elseif ( 'delete-access-profile' === $request['action'] ) {
			self::assertArrayNotHasKey( 'credential_existing', $this->secrets->credential_profiles( 'fixture' ) );
			self::assertSame( array(), $lookup->profiles );
		} elseif ( 'save-webhook-profile' === $request['action'] ) {
			self::assertContains(
				'Owner hook',
				array_column( $this->secrets->webhook_profiles( 'fixture' ), 'label' )
			);
		} else {
			self::assertArrayNotHasKey( 'webhook_existing', $this->secrets->webhook_profiles( 'fixture' ) );
		}
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function invalid_mutations(): array {
		return array(
			'self-destruct without date' => array(
				array(
					'action'        => 'save-access-profile',
					'provider'      => 'fixture',
					'label'         => 'Temporary access',
					'kind'          => 'api-key',
					'configuration' => array( 'tenant' => 'deployment' ),
					'secret'        => 'secret-canary-access',
					'expires_on'    => '',
					'self_destruct' => '1',
				),
				'Enter an expiry / removal date before enabling automatic removal.',
			),
			'save access'                => array(
				array(
					'action'        => 'save-access-profile',
					'provider'      => 'fixture',
					'label'         => '',
					'kind'          => 'api-key',
					'configuration' => array( 'tenant' => 'deployment' ),
					'secret'        => 'secret-canary-access',
				),
				'Enter a label for this credential.',
			),
			'delete access'              => array(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'fixture',
					'id'       => '',
				),
				'Choose a repository credential to remove.',
			),
			'save webhook'               => array(
				array(
					'action'   => 'save-webhook-profile',
					'provider' => 'fixture',
					'label'    => '',
					'scope'    => 'owner',
					'target'   => 'workspace',
					'secret'   => 'secret-canary-webhook-secret-value',
				),
				'Enter a label for this credential.',
			),
			'delete webhook'             => array(
				array(
					'action'   => 'delete-webhook-profile',
					'provider' => 'fixture',
					'id'       => '',
				),
				'Choose a Push-to-Deploy secret to remove.',
			),
		);
	}

	/**
	 * @param array<string, mixed> $request
	 */
	#[DataProvider( 'invalid_mutations' )]
	public function test_expected_failures_remain_local_and_never_reflect_submitted_secrets(
		array $request,
		string $expected_message
	): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_GET['view']         = str_contains( $request['action'], 'access' ) ? 'credentials' : 'secrets';
		$_POST['ran_booster'] = $request;

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)
		);
		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame( $expected_message, $response->feedback_message );
		self::assertStringNotContainsString( 'secret-canary', $response->feedback_message );
	}

	public function test_automatic_removal_uses_the_recorded_expiry_as_its_encrypted_deadline(): void {
		$expires_on          = gmdate( 'Y-m-d', time() + 30 * 86400 );
		$interaction         = new CapturingProviderProfileInteraction();
		$dashboard           = $this->createMock( Dashboard::class );
		$expiry_observations = new InMemoryCredentialExpiryObservationStore();
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'label'         => 'Temporary access',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'deployment' ),
			'secret'        => 'secret-canary-access',
			'expires_on'    => $expires_on,
			'self_destruct' => '1',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiry_observations
			)
		);

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'success', $response->kind );
		$profiles = array_values(
			array_filter(
				$this->secrets->credential_profiles( 'fixture' ),
				static fn ( array $profile ): bool => 'Temporary access' === $profile['label']
			)
		);
		self::assertCount( 1, $profiles );
		self::assertTrue( $profiles[0]['self_destruct'] );
		self::assertSame( $expires_on, $profiles[0]['destroy_on'] );
		self::assertSame(
			$expires_on,
			$expiry_observations->get( 'fixture', $profiles[0]['id'] )['manual_expires_on']
		);
	}

	public function test_known_provider_expiry_rejects_alater_submitted_date_before_saving(): void {
		$interaction         = new CapturingProviderProfileInteraction();
		$dashboard           = $this->createMock( Dashboard::class );
		$expiry_observations = new InMemoryCredentialExpiryObservationStore();
		$expiry_observations->record_provider_expiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Changed label',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'existing' ),
			'secret'        => '',
			'expires_on'    => '2026-09-11',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiry_observations
			)
		);

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'The expiry / removal date cannot be later than the expiry reported by the provider.',
			$response->feedback_message
		);
		self::assertSame(
			'Existing credential',
			$this->secrets->credential_profiles( 'fixture' )['credential_existing']['label']
		);
	}

	public function test_unchanged_provider_fallback_does_not_become_amanual_expiry(): void {
		$interaction         = new CapturingProviderProfileInteraction();
		$dashboard           = $this->createMock( Dashboard::class );
		$expiry_observations = new InMemoryCredentialExpiryObservationStore();
		$expiry_observations->record_provider_expiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Renamed credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'existing' ),
			'secret'        => '',
			'expires_on'    => '2026-09-10',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiry_observations
			)
		);

		self::assertSame( 'success', $response->kind );
		$observation = $expiry_observations->get( 'fixture', 'credential_existing' );
		self::assertSame( '2026-09-10T12:00:00Z', $observation['provider_expires_at'] );
		self::assertArrayNotHasKey( 'manual_expires_on', $observation );
	}

	public function test_replacement_does_not_inherit_the_previous_tokens_provider_expiry(): void {
		$interaction         = new CapturingProviderProfileInteraction();
		$dashboard           = $this->createMock( Dashboard::class );
		$expiry_observations = new InMemoryCredentialExpiryObservationStore();
		$expiry_observations->record_provider_expiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Replacement credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'existing' ),
			'secret'        => 'replacement-secret-canary',
			'expires_on'    => '',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiry_observations
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array(), $expiry_observations->get( 'fixture', 'credential_existing' ) );
		self::assertSame(
			'Replacement credential',
			$this->secrets->credential_profiles( 'fixture' )['credential_existing']['label']
		);
	}

	public function test_credential_replacement_invalidates_evidence_before_replacing_secret_material(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$evidence             = new ReplacementAwareBranchCheckEvidenceStore(
			function (): bool {
				return 'replacement-secret-canary' === ( $this->secrets->credential_material( 'fixture', 'credential_existing' )['secret'] ?? null );
			}
		);
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Replacement credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'existing' ),
			'secret'        => 'replacement-secret-canary',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				branch_check_evidence: $evidence
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array( 'fixture:credential_existing' ), $evidence->invalidated_profiles );
		self::assertFalse( $evidence->replacement_was_persisted );
	}

	public function test_credential_replacement_does_not_persist_when_evidence_invalidation_fails(): void {
		$interaction    = new CapturingProviderProfileInteraction();
		$dashboard      = $this->createMock( Dashboard::class );
		$profile_before = $this->secrets->credential_profiles( 'fixture' )['credential_existing'];
		$secret_before  = $this->secrets->credential_material( 'fixture', 'credential_existing' )['secret'];
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Replacement credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'existing' ),
			'secret'        => 'replacement-secret-canary',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				branch_check_evidence: new ThrowingBranchCheckEvidenceStore()
			)
		);

		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( $profile_before, $this->secrets->credential_profiles( 'fixture' )['credential_existing'] );
		self::assertSame( $secret_before, $this->secrets->credential_material( 'fixture', 'credential_existing' )['secret'] );
	}

	public function test_credential_configuration_change_invalidates_evidence_before_saving(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$evidence             = new ReplacementAwareBranchCheckEvidenceStore(
			function (): bool {
				return 'changed' === ( $this->secrets->credential_material( 'fixture', 'credential_existing' )['configuration']['tenant'] ?? null );
			}
		);
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Existing credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'changed' ),
			'secret'        => '',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				branch_check_evidence: $evidence
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array( 'fixture:credential_existing' ), $evidence->invalidated_profiles );
		self::assertFalse( $evidence->replacement_was_persisted );
		self::assertSame( 'changed', $this->secrets->credential_profiles( 'fixture' )['credential_existing']['configuration']['tenant'] );
	}

	public function test_credential_configuration_change_does_not_persist_when_evidence_invalidation_fails(): void {
		$interaction    = new CapturingProviderProfileInteraction();
		$dashboard      = $this->createMock( Dashboard::class );
		$profile_before = $this->secrets->credential_profiles( 'fixture' )['credential_existing'];
		$secret_before  = $this->secrets->credential_material( 'fixture', 'credential_existing' )['secret'];
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Existing credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'changed' ),
			'secret'        => '',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				branch_check_evidence: new ThrowingBranchCheckEvidenceStore()
			)
		);

		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( $profile_before, $this->secrets->credential_profiles( 'fixture' )['credential_existing'] );
		self::assertSame( $secret_before, $this->secrets->credential_material( 'fixture', 'credential_existing' )['secret'] );
	}

	public function test_credential_deletion_invalidates_evidence_only_after_removing_secret_material(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$lookup               = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles     = array( 'fixture' => 'credential_existing' );
		$evidence             = new ReplacementAwareBranchCheckEvidenceStore(
			function (): bool {
				return null === $this->secrets->credential_material( 'fixture', 'credential_existing' );
			}
		);
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'   => 'delete-access-profile',
			'provider' => 'fixture',
			'id'       => 'credential_existing',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				$lookup,
				branch_check_evidence: $evidence
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array( 'fixture:credential_existing' ), $evidence->invalidated_profiles );
		self::assertTrue( $evidence->replacement_was_persisted );
	}

	public function test_credential_deletion_clears_the_deleted_default_even_when_evidence_invalidation_fails(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$lookup               = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles     = array( 'fixture' => 'credential_existing' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'   => 'delete-access-profile',
			'provider' => 'fixture',
			'id'       => 'credential_existing',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				$lookup,
				branch_check_evidence: new ThrowingBranchCheckEvidenceStore()
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( 'Repository credential removed. Public repository lookup now uses anonymous access.', $response->feedback_message );
		self::assertArrayNotHasKey( 'credential_existing', $this->secrets->credential_profiles( 'fixture' ) );
		self::assertSame( array(), $lookup->profiles );
	}

	public function test_access_profile_lock_contention_fails_before_credential_deletion(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'fixture' => 'credential_existing' );
		$lock             = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )
			->method( 'acquire' )
			->willThrowException( new \RuntimeException( 'busy' ) );
		$lock->expects( self::never() )->method( 'release' );
		$this->updater_lock   = $lock;
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'   => 'delete-access-profile',
			'provider' => 'fixture',
			'id'       => 'credential_existing',
		);

		$response = $interaction->dispatch( $this->dispatcher( $dashboard, $this->secrets, $interaction, $lookup ) );

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedback_message );
		self::assertArrayHasKey( 'credential_existing', $this->secrets->credential_profiles( 'fixture' ) );
		self::assertSame( array( 'fixture' => 'credential_existing' ), $lookup->profiles );
	}

	public function test_access_profile_lock_release_failure_does_not_report_save_success(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'credential-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'credential-lock' )->willReturn( false );
		$this->updater_lock   = $lock;
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'label'         => 'Contended credential',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'deployment' ),
			'secret'        => 'secret-canary-release-failure',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)
		);

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedback_message );
		self::assertContains(
			'Contended credential',
			array_column( $this->secrets->credential_profiles( 'fixture' ), 'label' )
		);
	}

	public function test_unexpected_storage_failure_uses_only_generic_response_copy(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )
			->method( 'save_credential' )
			->willThrowException( new \RuntimeException( 'secret-canary-storage-fault' ) );
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'label'         => 'Deployment access',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'deployment' ),
			'secret'        => 'secret-canary-unexpected',
		);
		$_GET['view']         = 'credentials';

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)
		);
		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedback_message );
		self::assertStringNotContainsString( 'secret-canary', $response->feedback_message );
	}

	public function test_closed_provider_input_failure_remains_actionable_and_never_reflects_the_credential_secret(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'credential_profiles' )->willReturn(
			array(
				'credential_existing' => array(
					'configured' => true,
					'label'      => 'Existing credential',
				),
			)
		);
		$secrets->expects( self::once() )
			->method( 'save_credential' )
			->willThrowException(
				new InvalidCredentialInput(
					InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH,
					'The submitted credential does not match the selected credential kind. Choose the matching kind or enter another credential secret.'
				)
			);
		$interaction         = new CapturingProviderProfileInteraction();
		$dashboard           = $this->createMock( Dashboard::class );
		$expiry_observations = new InMemoryCredentialExpiryObservationStore();
		$expiry_observations->set_manual_expiry( 'fixture', 'credential_existing', '2026-09-01' );
		$expiry_observations->record_provider_expiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$observation_before = $expiry_observations->document;
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => 'credential_existing',
			'label'         => 'Deployment access',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'deployment' ),
			'secret'        => 'credential-secret-value-must-not-render',
			'expires_on'    => '',
		);
		$_GET['view']         = 'credentials';

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiry_observations
			)
		);
		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'The submitted credential does not match the selected credential kind. Choose the matching kind or enter another credential secret.',
			$response->feedback_message
		);
		self::assertStringNotContainsString( 'secret-value', $response->feedback_message );
		self::assertSame( $observation_before, $expiry_observations->document );
	}

	public function test_closed_webhook_input_failure_remains_actionable_and_never_reflects_the_secret(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$before = $this->secrets->webhook_profiles( 'fixture' );

		$_POST['ran_booster'] = array(
			'action'   => 'save-webhook-profile',
			'provider' => 'fixture',
			'label'    => 'Duplicate hook',
			'scope'    => 'owner',
			'target'   => 'existing-workspace',
			'secret'   => 'secret-canary-webhook-value-that-must-not-render',
		);
		$_GET['view']         = 'secrets';

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)
		);

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'A Push-to-Deploy secret already exists for this owner or repository. Edit the existing secret instead.',
			$response->feedback_message
		);
		self::assertStringNotContainsString( 'secret-canary', $response->feedback_message );
		self::assertSame( $before, $this->secrets->webhook_profiles( 'fixture' ) );
	}

	public function test_unexpected_webhook_storage_failure_keeps_only_generic_response_copy(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )
			->method( 'save_webhook' )
			->willThrowException( new \RuntimeException( 'secret-canary-webhook-storage-fault' ) );
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$_POST['ran_booster'] = array(
			'action'   => 'save-webhook-profile',
			'provider' => 'fixture',
			'label'    => 'Storage failure',
			'scope'    => 'owner',
			'target'   => 'new-workspace',
			'secret'   => 'secret-canary-webhook-value-that-must-not-render',
		);
		$_GET['view']         = 'secrets';

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore()
			)
		);

		self::assertThat( $response, self::logicalNot( self::isNull() ) );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedback_message );
		self::assertStringNotContainsString( 'secret-canary', $response->feedback_message );
	}

	private function seed_stored_profiles(): void {
		$this->secrets->save_credential(
			'fixture',
			'credential_existing',
			array(
				'label'         => 'Existing credential',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'existing' ),
			),
			'fixture-existing-access-secret'
		);
		$this->secrets->save_webhook(
			'fixture',
			'webhook_existing',
			array(
				'label'        => 'Existing webhook',
				'scope'        => 'owner',
				'target'       => 'existing-workspace',
				'authority_id' => '',
				'origin'       => 'manual',
			),
			'fixture-existing-webhook-secret-value'
		);
	}

	private function provider(): RepositoryProvider {
		$code           = ProviderCode::parse( 'fixture' );
		$webhook_policy = $this->createStub( ProviderWebhookPolicy::class );
		$webhook_policy->method( 'get_provider' )->willReturn( $code );
		$webhook_policy->method( 'normalize_webhook' )
			->willReturnCallback(
				static fn ( array $metadata, mixed $secret ): array => array(
					'label'        => (string) ( $metadata['label'] ?? '' ),
					'scope'        => (string) ( $metadata['scope'] ?? '' ),
					'target'       => (string) ( $metadata['target'] ?? '' ),
					'authority_id' => (string) ( $metadata['authority_id'] ?? '' ),
					'secret'       => (string) $secret,
				)
			);

		$provider = $this->createStubForIntersectionOfInterfaces(
			array(
				RepositoryProvider::class,
				ProviderCredentialPolicySupplier::class,
				WebhookNormalizer::class,
			)
		);
		$provider->method( 'get_metadata' )
			->willReturn(
				new ProviderMetadata(
					$code,
					'Fixture',
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						array(
							new CredentialKindMetadata(
								'api-key',
								'API key',
								'API key',
								'',
								array( new CredentialFieldMetadata( 'tenant', 'Tenant', 'text', true ) )
							),
						),
						array( new WebhookScopeMetadata( 'owner', 'Owner', true, 'Owner' ) )
					)
				)
			);
		$provider->method( 'get_credential_policy' )
			->willReturn( new ExternalFixtureCredentialPolicy( $code ) );
		$provider->method( 'get_webhook_policy' )->willReturn( $webhook_policy );

		self::assertInstanceOf( RepositoryProvider::class, $provider );
		return $provider;
	}

	private function dispatcher(
		Dashboard $dashboard,
		SecretsFile $secrets,
		CapturingProviderProfileInteraction $interaction,
		InMemoryPublicRepositoryLookupProfileStore $lookup,
		?InMemoryCredentialExpiryObservationStore $expiry_observations = null,
		?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null
	): Dispatcher {
		$plugins = new class() extends PluginRepository { public function __construct() {} };
		$themes  = new class() extends ThemeRepository { public function __construct() {} };
		$usage   = new CredentialUsageReader(
			new CredentialUsageDatabase(),
			'wp_ran_booster_packages',
			$this->createStub( Database::class )
		);

		return new Dispatcher(
			$dashboard,
			$this->providers,
			$secrets,
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $this->providers ), plugins: $plugins, themes: $themes, providers: $this->providers ),
			$this->updater_lock,
			credential_usage: $usage,
			public_lookup_profiles: $lookup,
			expiry_observations: $expiry_observations ?? new InMemoryCredentialExpiryObservationStore(),
			provider_profile_interaction: new ProviderProfileAdminController(
				$dashboard,
				$this->providers,
				$secrets,
				new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
				$this->updater_lock,
				$usage,
				$lookup,
				$expiry_observations ?? new InMemoryCredentialExpiryObservationStore(),
				$interaction->facade(),
				$branch_check_evidence
			)
		);
	}
}


// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused provider profile interaction fixtures.
final readonly class CapturedProviderProfileResponse {

	public function __construct(
		public readonly string $kind,
		public readonly SignedAdminInteractionRequest $request,
		public readonly string $feedback_message
	) {}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused provider profile interaction fixtures.
final class CapturingProviderProfileInteraction {

	public ?CapturedProviderProfileResponse $response = null;
	private CoreAdminInteractionFacade $facade;

	public function __construct() {
		$this->facade = new CoreAdminInteractionFacade(
			redirect: $this->capture_redirect( ... ),
			terminate: static function (): never {
				\Fiber::suspend();
				throw new \RuntimeException( 'A completed provider-profile response cannot resume.' );
			}
		);
	}

	public function facade(): CoreAdminInteractionFacade {
		return $this->facade;
	}

	public function dispatch( Dispatcher $dispatcher ): CapturedProviderProfileResponse {
		$fiber = new \Fiber( static fn () => $dispatcher->dispatch_post_requests() );
		$fiber->start();
		if ( ! $fiber->isSuspended() || null === $this->response ) {
			throw new \RuntimeException( 'Provider profile response was not captured.' );
		}

		return $this->response;
	}

	private function capture_redirect( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused signed redirect fixture.
		$query = parse_url( $url, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $args );
		$return_url     = is_string( $args['ran_booster_interaction_return'] ?? null )
			? $args['ran_booster_interaction_return']
			: '';
		$request        = new SignedAdminInteractionRequest(
			(string) ( $args['ran_booster_interaction_operation'] ?? '' ),
			(string) ( $args['ran_booster_interaction_target'] ?? '' ),
			ProviderProfileAdminController::TARGET_SELECTOR,
			$return_url,
			(string) ( $args['ran_booster_interaction_error_region'] ?? '' )
		);
		$this->response = new CapturedProviderProfileResponse(
			(string) ( $args['ran_booster_interaction_outcome'] ?? '' ),
			$request,
			(string) ( $args['ran_booster_interaction_message'] ?? '' )
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused provider profile interaction fixtures.
final class ReplacementAwareBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var list<string> */
	public array $invalidated_profiles     = array();
	public bool $replacement_was_persisted = false;

	/** @param \Closure(): bool $replacement_material_was_persisted */
	public function __construct( private \Closure $replacement_material_was_persisted ) {}

	public function bump_profile_generation( string $provider, string $profile_id ): void {
		$this->replacement_was_persisted = ( $this->replacement_material_was_persisted )();
		$this->invalidated_profiles[]    = $provider . ':' . $profile_id;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused provider profile interaction fixtures.
final class ThrowingBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	public function bump_profile_generation( string $provider, string $profile_id ): void {
		unset( $provider, $profile_id );
		throw new \RuntimeException( 'Fixture evidence invalidation failed.' );
	}
}

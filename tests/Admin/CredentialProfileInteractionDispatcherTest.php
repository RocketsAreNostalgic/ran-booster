<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
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
use Tests\RepositoryProvider\Support\ExternalFixtureCredentialPolicy;
use Tests\Secrets\InMemorySiteKeyStore;
use Tests\Secrets\SecretsFileTestFactory;
use Tests\Support\CredentialUsageDatabase;
use Tests\Support\InMemoryCredentialExpiryObservationStore;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

// Direct local filesystem operations exercise the encrypted sidecar fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions
final class CredentialProfileInteractionDispatcherTest extends TestCase {

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private ProviderRegistry $providers;
	private WordPressUpdaterLock $updaterLock;

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
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$policies        = new ProviderSecretPolicyCatalog();
		$this->secrets   = SecretsFileTestFactory::create( $this->path, array(), $policies );
		$this->providers = new ProviderRegistry( array( $this->provider() ), $policies );

		$this->updaterLock = $this->createStub( WordPressUpdaterLock::class );
		$this->updaterLock->method( 'acquire' )->willReturn( 'credential-fixture-lock' );
		$this->updaterLock->method( 'release' )->willReturn( true );
		$this->seedStoredProfiles();
	}

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
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function successfulMutations(): array {
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
	public static function unauthorizedActions(): array {
		return array(
			'save access'        => array( 'save-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'delete access'      => array( 'delete-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'save webhook'       => array( 'save-webhook-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'delete webhook'     => array( 'delete-webhook-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'validate access'    => array( 'validate-access-profile', 'You do not have sufficient permissions to manage Booster credentials.' ),
			'save public lookup' => array( 'save-public-lookup-profile', 'You do not have sufficient permissions to manage Booster provider settings.' ),
		);
	}

	#[DataProvider( 'unauthorizedActions' )]
	public function testEveryProfileActionStopsBeforeSensitiveStateIsRead( string $action, string $denial ): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'credentialProfiles' );
		$secrets->expects( self::never() )->method( 'webhookProfiles' );
		$secrets->expects( self::never() )->method( 'saveCredential' );
		$secrets->expects( self::never() )->method( 'deleteCredential' );
		$secrets->expects( self::never() )->method( 'saveWebhook' );
		$secrets->expects( self::never() )->method( 'deleteWebhook' );
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
			)->dispatchPostRequests();
			self::fail( 'An unauthorized provider-profile action must terminate before parsing state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( $denial, $failure->getMessage() );
		}

		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'unauthorizedActions' )]
	public function testEveryProfileActionStopsAtItsExactInvalidNonceBeforeSensitiveStateIsRead( string $action ): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$secrets                                 = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'credentialProfiles' );
		$secrets->expects( self::never() )->method( 'webhookProfiles' );
		$secrets->expects( self::never() )->method( 'saveCredential' );
		$secrets->expects( self::never() )->method( 'deleteCredential' );
		$secrets->expects( self::never() )->method( 'saveWebhook' );
		$secrets->expects( self::never() )->method( 'deleteWebhook' );
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
			)->dispatchPostRequests();
			self::fail( 'An invalid provider-profile nonce must terminate before parsing state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Invalid nonce.', $failure->getMessage() );
		}

		$expectedNonce = 'save-public-lookup-profile' === $action
			? 'ran-booster-save-public-lookup-profile'
			: 'ran-booster-save-secrets';
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $expectedNonce ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/**
	 * @param array<string, mixed> $request
	 */
	#[DataProvider( 'successfulMutations' )]
	public function testEveryRemainingMutationUsesVerifiedSignedSuccess(
		array $request,
		string $expectedMessage
	): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->method( 'addFailureMessage' )
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
		$this->updaterLock = $lock;
		$dispatcher        = $this->dispatcher( $dashboard, $this->secrets, $interaction, $lookup );

		$response = $interaction->dispatch( $dispatcher );
		self::assertNotNull( $response );
		self::assertSame( 'success', $response->kind );
		self::assertSame( $expectedMessage, $response->feedbackMessage );
		self::assertSame( 'core:' . $request['action'], $response->request->operation );
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
		self::assertStringNotContainsString( 'secret-canary', $response->request->canonicalUrl );

		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-secrets' ), $GLOBALS['ran_booster_test_nonce_checks'] );

		if ( 'save-access-profile' === $request['action'] ) {
			self::assertContains(
				'Deployment access',
				array_column( $this->secrets->credentialProfiles( 'fixture' ), 'label' )
			);
		} elseif ( 'delete-access-profile' === $request['action'] ) {
			self::assertArrayNotHasKey( 'credential_existing', $this->secrets->credentialProfiles( 'fixture' ) );
			self::assertSame( array(), $lookup->profiles );
		} elseif ( 'save-webhook-profile' === $request['action'] ) {
			self::assertContains(
				'Owner hook',
				array_column( $this->secrets->webhookProfiles( 'fixture' ), 'label' )
			);
		} else {
			self::assertArrayNotHasKey( 'webhook_existing', $this->secrets->webhookProfiles( 'fixture' ) );
		}
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function invalidMutations(): array {
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
	#[DataProvider( 'invalidMutations' )]
	public function testExpectedFailuresRemainLocalAndNeverReflectSubmittedSecrets(
		array $request,
		string $expectedMessage
	): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
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
		self::assertNotNull( $response );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame( $expectedMessage, $response->feedbackMessage );
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
	}

	public function testAutomaticRemovalUsesTheRecordedExpiryAsItsEncryptedDeadline(): void {
		$interaction        = new CapturingProviderProfileInteraction();
		$dashboard          = $this->createMock( Dashboard::class );
		$expiryObservations = new InMemoryCredentialExpiryObservationStore();
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'label'         => 'Temporary access',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'deployment' ),
			'secret'        => 'secret-canary-access',
			'expires_on'    => '2026-09-30',
			'self_destruct' => '1',
		);

		$response = $interaction->dispatch(
			$this->dispatcher(
				$dashboard,
				$this->secrets,
				$interaction,
				new InMemoryPublicRepositoryLookupProfileStore(),
				$expiryObservations
			)
		);

		self::assertNotNull( $response );
		self::assertSame( 'success', $response->kind );
		$profiles = array_values(
			array_filter(
				$this->secrets->credentialProfiles( 'fixture' ),
				static fn ( array $profile ): bool => 'Temporary access' === $profile['label']
			)
		);
		self::assertCount( 1, $profiles );
		self::assertTrue( $profiles[0]['self_destruct'] );
		self::assertSame( '2026-09-30', $profiles[0]['destroy_on'] );
		self::assertSame(
			'2026-09-30',
			$expiryObservations->get( 'fixture', $profiles[0]['id'] )['manual_expires_on']
		);
	}

	public function testKnownProviderExpiryRejectsALaterSubmittedDateBeforeSaving(): void {
		$interaction        = new CapturingProviderProfileInteraction();
		$dashboard          = $this->createMock( Dashboard::class );
		$expiryObservations = new InMemoryCredentialExpiryObservationStore();
		$expiryObservations->recordProviderExpiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
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
				$expiryObservations
			)
		);

		self::assertNotNull( $response );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'The expiry / removal date cannot be later than the expiry reported by the provider.',
			$response->feedbackMessage
		);
		self::assertSame(
			'Existing credential',
			$this->secrets->credentialProfiles( 'fixture' )['credential_existing']['label']
		);
	}

	public function testUnchangedProviderFallbackDoesNotBecomeAManualExpiry(): void {
		$interaction        = new CapturingProviderProfileInteraction();
		$dashboard          = $this->createMock( Dashboard::class );
		$expiryObservations = new InMemoryCredentialExpiryObservationStore();
		$expiryObservations->recordProviderExpiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
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
				$expiryObservations
			)
		);

		self::assertSame( 'success', $response->kind );
		$observation = $expiryObservations->get( 'fixture', 'credential_existing' );
		self::assertSame( '2026-09-10T12:00:00Z', $observation['provider_expires_at'] );
		self::assertArrayNotHasKey( 'manual_expires_on', $observation );
	}

	public function testReplacementDoesNotInheritThePreviousTokensProviderExpiry(): void {
		$interaction        = new CapturingProviderProfileInteraction();
		$dashboard          = $this->createMock( Dashboard::class );
		$expiryObservations = new InMemoryCredentialExpiryObservationStore();
		$expiryObservations->recordProviderExpiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
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
				$expiryObservations
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array(), $expiryObservations->get( 'fixture', 'credential_existing' ) );
		self::assertSame(
			'Replacement credential',
			$this->secrets->credentialProfiles( 'fixture' )['credential_existing']['label']
		);
	}

	public function testCredentialReplacementInvalidatesEvidenceOnlyAfterReplacingSecretMaterial(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$evidence             = new ReplacementAwareBranchCheckEvidenceStore(
			function (): bool {
				return 'replacement-secret-canary' === ( $this->secrets->credentialMaterial( 'fixture', 'credential_existing' )['secret'] ?? null );
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
				branchCheckEvidence: $evidence
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array( 'fixture:credential_existing' ), $evidence->invalidatedProfiles );
		self::assertTrue( $evidence->replacementWasPersisted );
	}

	public function testCredentialDeletionInvalidatesEvidenceOnlyAfterRemovingSecretMaterial(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$lookup               = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles     = array( 'fixture' => 'credential_existing' );
		$evidence             = new ReplacementAwareBranchCheckEvidenceStore(
			function (): bool {
				return null === $this->secrets->credentialMaterial( 'fixture', 'credential_existing' );
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
				branchCheckEvidence: $evidence
			)
		);

		self::assertSame( 'success', $response->kind );
		self::assertSame( array( 'fixture:credential_existing' ), $evidence->invalidatedProfiles );
		self::assertTrue( $evidence->replacementWasPersisted );
	}

	public function testAccessProfileLockContentionFailsBeforeCredentialDeletion(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'fixture' => 'credential_existing' );
		$lock             = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )
			->method( 'acquire' )
			->willThrowException( new \RuntimeException( 'busy' ) );
		$lock->expects( self::never() )->method( 'release' );
		$this->updaterLock    = $lock;
		$_GET['view']         = 'credentials';
		$_POST['ran_booster'] = array(
			'action'   => 'delete-access-profile',
			'provider' => 'fixture',
			'id'       => 'credential_existing',
		);

		$response = $interaction->dispatch( $this->dispatcher( $dashboard, $this->secrets, $interaction, $lookup ) );

		self::assertNotNull( $response );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedbackMessage );
		self::assertArrayHasKey( 'credential_existing', $this->secrets->credentialProfiles( 'fixture' ) );
		self::assertSame( array( 'fixture' => 'credential_existing' ), $lookup->profiles );
	}

	public function testAccessProfileLockReleaseFailureDoesNotReportSaveSuccess(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'credential-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'credential-lock' )->willReturn( false );
		$this->updaterLock    = $lock;
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

		self::assertNotNull( $response );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedbackMessage );
		self::assertContains(
			'Contended credential',
			array_column( $this->secrets->credentialProfiles( 'fixture' ), 'label' )
		);
	}

	public function testUnexpectedStorageFailureUsesOnlyGenericResponseCopy(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )
			->method( 'saveCredential' )
			->willThrowException( new \RuntimeException( 'secret-canary-storage-fault' ) );
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
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
		self::assertNotNull( $response );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedbackMessage );
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
	}

	public function testClosedProviderInputFailureRemainsActionableAndNeverReflectsTheCredentialSecret(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'credentialProfiles' )->willReturn(
			array(
				'credential_existing' => array(
					'configured' => true,
					'label'      => 'Existing credential',
				),
			)
		);
		$secrets->expects( self::once() )
			->method( 'saveCredential' )
			->willThrowException(
				new InvalidCredentialInput(
					InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH,
					'The submitted credential does not match the selected credential kind. Choose the matching kind or enter another credential secret.'
				)
			);
		$interaction        = new CapturingProviderProfileInteraction();
		$dashboard          = $this->createMock( Dashboard::class );
		$expiryObservations = new InMemoryCredentialExpiryObservationStore();
		$expiryObservations->setManualExpiry( 'fixture', 'credential_existing', '2026-09-01' );
		$expiryObservations->recordProviderExpiry(
			'fixture',
			'credential_existing',
			CredentialExpiryReport::known( '2026-09-10T12:00:00Z' ),
			'2026-08-08T12:00:00Z'
		);
		$observationBefore = $expiryObservations->document;
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
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
				$expiryObservations
			)
		);
		self::assertNotNull( $response );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'The submitted credential does not match the selected credential kind. Choose the matching kind or enter another credential secret.',
			$response->feedbackMessage
		);
		self::assertStringNotContainsString( 'secret-value', $response->feedbackMessage );
		self::assertSame( $observationBefore, $expiryObservations->document );
	}

	public function testClosedWebhookInputFailureRemainsActionableAndNeverReflectsTheSecret(): void {
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
		$before = $this->secrets->webhookProfiles( 'fixture' );

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

		self::assertNotNull( $response );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame(
			'A Push-to-Deploy secret already exists for this owner or repository. Edit the existing secret instead.',
			$response->feedbackMessage
		);
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
		self::assertSame( $before, $this->secrets->webhookProfiles( 'fixture' ) );
	}

	public function testUnexpectedWebhookStorageFailureKeepsOnlyGenericResponseCopy(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )
			->method( 'saveWebhook' )
			->willThrowException( new \RuntimeException( 'secret-canary-webhook-storage-fault' ) );
		$interaction = new CapturingProviderProfileInteraction();
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
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

		self::assertNotNull( $response );
		self::assertSame( 'unexpected_failure', $response->kind );
		self::assertSame( 'We could not complete that request. Please try again.', $response->feedbackMessage );
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
	}

	private function seedStoredProfiles(): void {
		$this->secrets->saveCredential(
			'fixture',
			'credential_existing',
			array(
				'label'         => 'Existing credential',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'existing' ),
			),
			'fixture-existing-access-secret'
		);
		$this->secrets->saveWebhook(
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
		$code          = ProviderCode::parse( 'fixture' );
		$webhookPolicy = $this->createStub( ProviderWebhookPolicy::class );
		$webhookPolicy->method( 'getProvider' )->willReturn( $code );
		$webhookPolicy->method( 'normalizeWebhook' )
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
		$provider->method( 'getMetadata' )
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
		$provider->method( 'getCredentialPolicy' )
			->willReturn( new ExternalFixtureCredentialPolicy( $code ) );
		$provider->method( 'getWebhookPolicy' )->willReturn( $webhookPolicy );

		return $provider;
	}

	private function dispatcher(
		Dashboard $dashboard,
		SecretsFile $secrets,
		CapturingProviderProfileInteraction $interaction,
		InMemoryPublicRepositoryLookupProfileStore $lookup,
		?InMemoryCredentialExpiryObservationStore $expiryObservations = null,
		?RepositoryBranchCheckEvidenceStore $branchCheckEvidence = null
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
			new PackageRepositoryRequestResolver( $this->providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $this->providers ), plugins: $plugins, themes: $themes, providers: $this->providers ),
			$this->updaterLock,
			credentialUsage: $usage,
			publicLookupProfiles: $lookup,
			expiryObservations: $expiryObservations ?? new InMemoryCredentialExpiryObservationStore(),
			providerProfileInteraction: new ProviderProfileAdminController(
				$dashboard,
				$this->providers,
				$secrets,
				new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
				$this->updaterLock,
				$usage,
				$lookup,
				$expiryObservations ?? new InMemoryCredentialExpiryObservationStore(),
				$interaction->facade(),
				$branchCheckEvidence
			)
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused provider profile interaction fixtures.
final readonly class CapturedProviderProfileResponse {

	public function __construct(
		public readonly string $kind,
		public readonly SignedAdminInteractionRequest $request,
		public readonly string $feedbackMessage
	) {}
}

final class CapturingProviderProfileInteraction {

	public ?CapturedProviderProfileResponse $response = null;
	private CoreAdminInteractionFacade $facade;

	public function __construct() {
		$this->facade = new CoreAdminInteractionFacade(
			redirect: $this->captureRedirect( ... ),
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
		$fiber = new \Fiber( static fn () => $dispatcher->dispatchPostRequests() );
		$fiber->start();
		if ( ! $fiber->isSuspended() || null === $this->response ) {
			throw new \RuntimeException( 'Provider profile response was not captured.' );
		}

		return $this->response;
	}

	private function captureRedirect( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused signed redirect fixture.
		$query = parse_url( $url, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $args );
		$returnUrl      = is_string( $args['ran_booster_interaction_return'] ?? null )
			? $args['ran_booster_interaction_return']
			: '';
		$request        = new SignedAdminInteractionRequest(
			(string) ( $args['ran_booster_interaction_operation'] ?? '' ),
			(string) ( $args['ran_booster_interaction_target'] ?? '' ),
			ProviderProfileAdminController::TARGET_SELECTOR,
			$returnUrl,
			(string) ( $args['ran_booster_interaction_error_region'] ?? '' )
		);
		$this->response = new CapturedProviderProfileResponse(
			(string) ( $args['ran_booster_interaction_outcome'] ?? '' ),
			$request,
			(string) ( $args['ran_booster_interaction_message'] ?? '' )
		);
	}
}

final class ReplacementAwareBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var list<string> */
	public array $invalidatedProfiles    = array();
	public bool $replacementWasPersisted = false;

	/** @param \Closure(): bool $replacementMaterialWasPersisted */
	public function __construct( private \Closure $replacementMaterialWasPersisted ) {}

	public function bumpProfileGeneration( string $provider, string $profileId ): void {
		$this->replacementWasPersisted = ( $this->replacementMaterialWasPersisted )();
		$this->invalidatedProfiles[]   = $provider . ':' . $profileId;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
// phpcs:enable WordPress.WP.AlternativeFunctions

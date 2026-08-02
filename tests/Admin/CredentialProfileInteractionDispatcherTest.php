<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once __DIR__ . '/Interaction/AdminInteractionWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Interaction\CoreProviderProfileInteraction;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\Admin\Interaction\SignedAdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
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
use Tests\Support\NullLoggingFacade;

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
		$this->providers = new ProviderRegistry( new NullLoggingFacade(), array( $this->provider() ), $policies );

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
				'Repository access token saved.',
			),
			'delete access'  => array(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'fixture',
					'id'       => 'credential_existing',
				),
				'Repository access token removed. Public repository lookup now uses anonymous access.',
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

		$dispatcher->dispatchPostRequests();
		$response = $interaction->response;
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
			'save access'    => array(
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
			'delete access'  => array(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'fixture',
					'id'       => '',
				),
				'Choose a repository credential to remove.',
			),
			'save webhook'   => array(
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
			'delete webhook' => array(
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

		$this->dispatcher(
			$dashboard,
			$this->secrets,
			$interaction,
			new InMemoryPublicRepositoryLookupProfileStore()
		)->dispatchPostRequests();
		$response = $interaction->response;
		self::assertNotNull( $response );
		self::assertSame( 'validation_failure', $response->kind );
		self::assertSame( $expectedMessage, $response->feedbackMessage );
		self::assertStringNotContainsString( 'secret-canary', $response->feedbackMessage );
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

		$this->dispatcher( $dashboard, $this->secrets, $interaction, $lookup )->dispatchPostRequests();

		$response = $interaction->response;
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

		$this->dispatcher(
			$dashboard,
			$this->secrets,
			$interaction,
			new InMemoryPublicRepositoryLookupProfileStore()
		)->dispatchPostRequests();

		$response = $interaction->response;
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

		$this->dispatcher(
			$dashboard,
			$secrets,
			$interaction,
			new InMemoryPublicRepositoryLookupProfileStore()
		)->dispatchPostRequests();
		$response = $interaction->response;
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
		InMemoryPublicRepositoryLookupProfileStore $lookup
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
			new PackageEditProviderGuard( $plugins, $themes, $this->providers ),
			$this->updaterLock,
			credentialUsage: $usage,
			publicLookupProfiles: $lookup,
			expiryObservations: new InMemoryCredentialExpiryObservationStore(),
			providerProfileInteraction: $interaction
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

final class CapturingProviderProfileInteraction implements CoreProviderProfileInteraction {

	public ?CapturedProviderProfileResponse $response = null;

	public function providerProfileRequest(
		string $action,
		string $provider
	): SignedAdminInteractionRequest {
		return ( new CoreAdminInteractionFacade() )->providerProfileRequest(
			$action,
			$provider
		);
	}

	public function respondToProviderProfileSuccess( SignedAdminInteractionRequest $request, string $message ): void {
		$this->response = new CapturedProviderProfileResponse( 'success', $request, $message );
	}

	public function respondToProviderProfileValidationFailure( SignedAdminInteractionRequest $request, string $message ): void {
		$this->response = new CapturedProviderProfileResponse( 'validation_failure', $request, $message );
	}

	public function respondToProviderProfileUnexpectedFailure( SignedAdminInteractionRequest $request ): void {
		$this->response = new CapturedProviderProfileResponse(
			'unexpected_failure',
			$request,
			'We could not complete that request. Please try again.'
		);
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
// phpcs:enable WordPress.WP.AlternativeFunctions

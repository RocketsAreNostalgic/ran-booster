<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Storage\CredentialUsageReader;
use RAN\WordPress\WordPressUpdaterLock;

final class CredentialValidationHtmxDispatcherTest extends TestCase {
	private HtmxCredentialValidationTestController $controller;

	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HX_REQUEST'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	public function testOrdinaryPostKeepsTheExistingDashboardNoticeFlow(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addMessage' )
			->with( 'Repository credential validated successfully.' );
		$dispatcher = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );

		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatchPostRequests();

		self::assertNull( $this->controller->response );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-secrets' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testHtmxPostReturnsOnlyTheSafeSuccessToastPayload(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatchPostRequests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credentialId );
			self::assertSame( 'Repository credential validated successfully.', $response->toastMessage );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function testHtmxValidationFailureRemainsLocalAndDoesNotClaimSuccess(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::rateLimited() );
		$_SERVER['HTTP_HX_REQUEST'] = 'TRUE';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatchPostRequests();
			self::fail( 'An HTMX validation failure must return the local error fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credentialId );
			self::assertNull( $response->toastMessage );
			self::assertSame( 'The repository provider rate-limited credential validation. Try again later.', $response->error );
			self::assertSame( 422, $response->status );
		}
	}

	/** @return array{action:string,provider:string,id:string} */
	private function request(): array {
		return array(
			'action'   => 'validate-access-profile',
			'provider' => 'bb',
			'id'       => 'credential_1',
		);
	}

	private function dispatcher( Dashboard $dashboard, CredentialValidationResult $result ): Dispatcher {
		$provider  = new CredentialValidationProvider( $result );
		$providers = new ProviderRegistry( array( $provider ) );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };
		$lock      = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::never() )->method( 'acquire' );
		$lock->expects( self::never() )->method( 'release' );

		$this->controller = new HtmxCredentialValidationTestController(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			$lock,
			new CredentialUsageReader(),
			new PublicRepositoryLookupProfileStore(),
			new CredentialExpiryObservationStore()
		);

		return new Dispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$lock,
			providerProfileInteraction: $this->controller
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused HTMX response spy.
final class HtmxCredentialValidationResponse extends \RuntimeException {

	public function __construct(
		public readonly string $credentialId,
		public readonly ?string $toastMessage,
		public readonly ?string $error,
		public readonly int $status
	) {
		parent::__construct( 'HTMX response sent.' );
	}
}

final class HtmxCredentialValidationTestController extends ProviderProfileAdminController {

	/** @var array{id:string,message:?string,error:?string,status:int}|null */
	public ?array $response = null;

	protected function respondToHtmxCredentialValidation( string $credentialId, ?string $message, ?string $error, int $status ): never {
		$this->response = array(
			'id'      => $credentialId,
			'message' => $message,
			'error'   => $error,
			'status'  => $status,
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test spy captures its fixed method arguments without output.
		throw new HtmxCredentialValidationResponse( $credentialId, $message, $error, $status );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile

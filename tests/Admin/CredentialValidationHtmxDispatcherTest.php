<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks']        = array();
		$GLOBALS['ran_booster_test_nonce_checks']             = array();
		$GLOBALS['ran_booster_test_capabilities']             = array();
		$GLOBALS['ran_booster_test_nonce_valid']              = true;
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HX_REQUEST'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid'],
			$GLOBALS['ran_booster_admin_test_translations'],
			$GLOBALS['ran_booster_repository_admin_translations']
		);
	}

	public function test_ordinary_post_keeps_the_existing_dashboard_notice_flow(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_message' )
			->with( 'Repository credential validated successfully.' );
		$dispatcher = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );

		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatch_post_requests();

		self::assertNull( $this->controller->response );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-secrets' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_htmx_post_returns_only_the_safe_success_toast_payload(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertSame( 'Repository credential validated successfully.', $response->toast_message );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function test_htmx_success_toast_uses_the_translated_credential_validation_message(): void {
		$translations = array(
			'Repository credential validated successfully.' => 'Translated credential validation success.',
		);
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']       = $translations;
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster'] = $translations;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertSame( 'Translated credential validation success.', $response->toast_message );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function test_htmx_validation_failure_remains_local_and_does_not_claim_success(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::rate_limited() );
		$_SERVER['HTTP_HX_REQUEST'] = 'TRUE';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX validation failure must return the local error fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertNull( $response->toast_message );
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
			new InMemoryPublicRepositoryLookupProfileStore(),
			new InMemoryCredentialExpiryObservationStore()
		);

		return new Dispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$lock,
			provider_profile_interaction: $this->controller
		);
	}
}


// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused HTMX response spy.
final class HtmxCredentialValidationResponse extends \RuntimeException {

	public function __construct(
		public readonly string $credential_id,
		public readonly ?string $toast_message,
		public readonly ?string $error,
		public readonly int $status
	) {
		parent::__construct( 'HTMX response sent.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused HTMX response spy.
final class HtmxCredentialValidationTestController extends ProviderProfileAdminController {

	/** @var array{id:string,message:?string,error:?string,status:int}|null */
	public ?array $response = null;

	protected function respond_to_htmx_credential_validation( string $credential_id, ?string $message, ?string $error, int $status ): never {
		$this->response = array(
			'id'      => $credential_id,
			'message' => $message,
			'error'   => $error,
			'status'  => $status,
		);

		// The test spy captures its fixed method arguments without output.
		throw new HtmxCredentialValidationResponse( $credential_id, $message, $error, $status );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Dispatcher;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\Database;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\Deployment\AttemptRepositoryDatabase;

final class OperatorActionDispatcherTest extends TestCase {

	private OperatorDispatcherCoordinator $coordinator;
	/** @var list<string> */
	private array $captureDirectories = array();

	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
		$this->coordinator                             = new OperatorDispatcherCoordinator();
	}

	protected function tearDown(): void {
		$_POST = array();
		foreach ( $this->captureDirectories as $directory ) {
			foreach ( array( $directory . '/ran-booster-debug.php', $directory . '/ran-booster-debug.php.lock' ) as $path ) {
				if ( is_file( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
					unlink( $path );
				}
			}
			if ( is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
				rmdir( $directory );
			}
		}
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HX_REQUEST'], $GLOBALS['ran_booster_test_capability_checks'], $GLOBALS['ran_booster_test_nonce_checks'], $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_nonce_valid'] );
	}

	public function testAdministratorCanStartStopAndDeleteDebugCaptureWithOneProtectedAction(): void {
		$capture = $this->capture();

		$startRedirect = $this->dispatchCaptureOperation( $capture, 'start' );
		self::assertSame( 'active', $capture->snapshot()['state'] );
		self::assertStringContainsString( 'panel=debug-capture', $startRedirect );

		$stopRedirect = $this->dispatchCaptureOperation( $capture, 'stop' );
		self::assertSame( 'retained', $capture->snapshot()['state'] );
		self::assertSame( $startRedirect, $stopRedirect );

		$deleteRedirect = $this->dispatchCaptureOperation( $capture, 'delete' );
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
		self::assertSame( $startRedirect, $deleteRedirect );
		self::assertSame(
			array_fill( 0, 3, 'manage_options' ),
			$GLOBALS['ran_booster_test_capability_checks']
		);
		self::assertSame(
			array_fill( 0, 3, 'ran-booster-manage-debug-capture' ),
			$GLOBALS['ran_booster_test_nonce_checks']
		);
	}

	public function testDebugCaptureRejectsInvalidOperationsAfterCapabilityAndNonceChecks(): void {
		$capture = $this->capture();

		$_POST['ran_booster'] = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'export',
		);

		$this->dispatcher( $this->createStub( Dashboard::class ), $capture )->dispatchPostRequests();

		self::assertSame( 'inactive', $capture->snapshot()['state'] );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-manage-debug-capture' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testDebugCaptureRequiresPostCapabilityAndNonce(): void {
		$capture = $this->capture();

		$_POST['ran_booster'] = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'start',
		);

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->dispatcher( $this->createStub( Dashboard::class ), $capture )->dispatchPostRequests();
		self::assertSame( 'inactive', $capture->snapshot()['state'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );

		$_SERVER['REQUEST_METHOD']                                  = 'POST';
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $capture )->dispatchPostRequests();
			self::fail( 'A missing capability must terminate the request.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 'inactive', $capture->snapshot()['state'] );
		}

		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_test_nonce_valid']                    = false;
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $capture )->dispatchPostRequests();
			self::fail( 'An invalid nonce must terminate the request.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 'inactive', $capture->snapshot()['state'] );
		}
	}

	public function testDebugCaptureFilesystemFailureUsesAGenericAdminError(): void {
		$capture   = new TemporaryDebugCapture( '/missing/private-canary/secrets.json' );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static function ( mixed $message ): bool {
						return $message instanceof \WP_Error
							&& 'ran_booster_debug_capture_unavailable' === $message->get_error_code()
							&& ! str_contains( $message->get_error_message(), 'private-canary' );
					}
				)
			);
		$_POST['ran_booster'] = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'start',
		);

		$this->dispatcher( $dashboard, $capture )->dispatchPostRequests();
	}

	public function testHtmxDebugCaptureStartReturnsARegionAndSuccessEventWithoutARedirect(): void {
		$capture   = $this->capture();
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'start',
		);

		try {
			$this->htmxDispatcher( $dashboard, $capture )->dispatchPostRequests();
			self::fail( 'An HTMX capture response must end after rendering its bounded region.' );
		} catch ( HtmxDebugCaptureResponse $response ) {
			self::assertSame( 'active', $capture->snapshot()['state'] );
			self::assertSame( 'Temporary logging capture started.', $response->toastMessage );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function testHtmxDebugCaptureStopReturnsARegionAndSuccessEventWithoutARedirect(): void {
		$capture = $this->capture();
		$capture->start();

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'stop',
		);

		try {
			$this->htmxDispatcher( $dashboard, $capture )->dispatchPostRequests();
			self::fail( 'An HTMX capture response must end after rendering its bounded region.' );
		} catch ( HtmxDebugCaptureResponse $response ) {
			self::assertSame( 'retained', $capture->snapshot()['state'] );
			self::assertSame( 'Temporary logging capture stopped.', $response->toastMessage );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function testHtmxDebugCaptureFailureKeepsARedactedLocalErrorWithoutASuccessEvent(): void {
		$capture   = new TemporaryDebugCapture( '/missing/private-canary/secrets.json' );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'    => 'manage-debug-capture',
			'operation' => 'start',
		);

		try {
			$this->htmxDispatcher( $dashboard, $capture )->dispatchPostRequests();
			self::fail( 'An HTMX capture failure must end after rendering its bounded region.' );
		} catch ( HtmxDebugCaptureResponse $response ) {
			self::assertNull( $response->toastMessage );
			self::assertSame( 'Booster could not update the temporary logging capture. No deployment was interrupted.', $response->error );
			self::assertStringNotContainsString( 'private-canary', $response->error );
			self::assertSame( 500, $response->status );
		}
	}

	public function testHtmxDiagnosticsRefreshesItsPanelAndOnlyToastsAnAllPassResult(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postRunTroubleshooting' )
			->with(
				array(
					'provider'      => 'gh',
					'credential_id' => null,
					'repository'    => null,
				)
			);
		$dashboard->expects( self::once() )->method( 'troubleshootingSucceeded' )->willReturn( true );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'   => 'run-troubleshooting',
			'provider' => 'gh',
		);

		try {
			$this->htmxDispatcher( $dashboard, $this->capture() )->dispatchPostRequests();
			self::fail( 'An HTMX diagnostics response must end after rendering its bounded region.' );
		} catch ( HtmxDiagnosticsResponse $response ) {
			self::assertTrue( $response->succeeded );
		}
	}

	public function testHtmxDiagnosticsKeepsAPartialResultPersistentWithoutASuccessEvent(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postRunTroubleshooting' )
			->with(
				array(
					'provider'      => 'gh',
					'credential_id' => null,
					'repository'    => null,
				)
			);
		$dashboard->expects( self::once() )->method( 'troubleshootingSucceeded' )->willReturn( false );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'   => 'run-troubleshooting',
			'provider' => 'gh',
		);

		try {
			$this->htmxDispatcher( $dashboard, $this->capture() )->dispatchPostRequests();
			self::fail( 'An HTMX diagnostics response must end after rendering its bounded region.' );
		} catch ( HtmxDiagnosticsResponse $response ) {
			self::assertFalse( $response->succeeded );
		}
	}

	public function testAdministratorCanRequestTheOneShotRunnerWithActionNonce(): void {
		$_POST['ran_booster'] = array( 'action' => 'request-deployment-runner' );
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addMessage' )->with( 'The deployment runner was requested.' );

		$this->dispatcher( $dashboard )->dispatchPostRequests();

		self::assertSame( 1, $this->coordinator->requests );
		self::assertSame( array( 'manage_options', 'update_plugins', 'update_themes' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-request-deployment-runner' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testInvalidNonceBlocksRunnerRequest(): void {
		$_POST['ran_booster']                    = array( 'action' => 'request-deployment-runner' );
		$GLOBALS['ran_booster_test_nonce_valid'] = false;

		$this->expectException( \RuntimeException::class );
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ) )->dispatchPostRequests();
		} finally {
			self::assertSame( 0, $this->coordinator->requests );
		}
	}

	public function testGetCannotRequestRunner(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST['ran_booster']      = array( 'action' => 'request-deployment-runner' );

		$this->dispatcher( $this->createStub( Dashboard::class ) )->dispatchPostRequests();

		self::assertSame( 0, $this->coordinator->requests );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testMissingPackageUpdateCapabilityBlocksRunnerRequest(): void {
		$_POST['ran_booster']                                      = array( 'action' => 'request-deployment-runner' );
		$GLOBALS['ran_booster_test_capabilities']['update_themes'] = false;
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' )->with( self::isInstanceOf( \WP_Error::class ) );

		$this->dispatcher( $dashboard )->dispatchPostRequests();

		self::assertSame( 0, $this->coordinator->requests );
	}

	public function testAdministratorCanResolveTheExactNeedsAttentionAttemptWithTheStoredPackageCapability(): void {
		$database         = new AttemptRepositoryDatabase();
		$request          = new DeploymentRequest(
			'owner/example',
			'profile_123',
			true,
			'main',
			'example',
			null,
			DeploymentPolicy::MANUAL,
			null
		);
		$database->rows[] = array(
			'id'                      => 9,
			'correlation_id'          => str_repeat( 'a', 32 ),
			'source'                  => 'manual',
			'operation'               => 'update',
			'package_type'            => 'plugin',
			'package_slug'            => 'example',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'provider'                => 'gh',
			'provider_repository_id'  => 'R_example',
			'requested_ref'           => 'main',
			'resolved_ref'            => null,
			'delivery_id'             => null,
			'delivery_digest'         => null,
			'state'                   => 'needs_attention',
			'mutation_started_at'     => '2026-07-26 09:00:00',
			'outcome_code'            => DeploymentOutcome::CODE_INTERRUPTED,
			'request_json'            => $request->toJson(),
			'created_at'              => '2026-07-26 09:00:00',
			'finished_at'             => '2026-07-26 09:01:00',
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
		$attempts         = new DeploymentAttemptRepository(
			$database,
			'wp_ran_booster_deployment_attempts',
			static fn (): \DateTimeImmutable => new \DateTimeImmutable( '2026-07-26 09:02:00 UTC' ),
			databaseLifecycle: $this->createStub( Database::class )
		);
		self::assertTrue( DeploymentAttempt::fromDatabase( $database->rows[0] )->requiresOperatorResolution() );
		$_POST['ran_booster'] = array(
			'action'           => 'resolve-needs-attention',
			'attempt_id'       => '9',
			'correlation_id'   => str_repeat( 'a', 32 ),
			'confirm_reviewed' => '1',
			'package_type'     => 'theme',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addMessage' )
			->with( 'The deployment review was recorded. This package may now be retried.' );

		$this->dispatcher( $dashboard, attempts: $attempts )->dispatchPostRequests();

		self::assertSame( array( 'manage_options', 'update_plugins' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-resolve-needs-attention' ), $GLOBALS['ran_booster_test_nonce_checks'] );
		self::assertStringContainsString( "resolved_by = '7'", implode( "\n", $database->queries ) );
		self::assertSame( '7', $database->rows[0]['resolved_by'] );
		self::assertNotNull( $database->rows[0]['resolved_at'] );
	}

	private function dispatcher(
		Dashboard $dashboard,
		?TemporaryDebugCapture $capture = null,
		?DeploymentAttemptRepository $attempts = null
	): Dispatcher {
		$providers = new ProviderRegistry( new \Tests\Support\NullLoggingFacade() );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };

		return new DebugCaptureTestDispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
			$this->coordinator,
			null,
			null,
			null,
			$capture,
			deploymentAttempts: $attempts
		);
	}

	private function htmxDispatcher( Dashboard $dashboard, TemporaryDebugCapture $capture ): HtmxDebugCaptureTestDispatcher {
		$providers = new ProviderRegistry( new \Tests\Support\NullLoggingFacade() );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };

		return new HtmxDebugCaptureTestDispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
			$this->coordinator,
			null,
			null,
			null,
			$capture
		);
	}

	private function capture(): TemporaryDebugCapture {
		$directory = sys_get_temp_dir() . '/ran-booster-dispatcher-capture-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$this->captureDirectories[] = $directory;

		return new TemporaryDebugCapture( $directory . '/secrets.json' );
	}

	private function dispatchCaptureOperation( TemporaryDebugCapture $capture, string $operation ): string {
		$_POST['ran_booster'] = array(
			'action'    => 'manage-debug-capture',
			'operation' => $operation,
		);

		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $capture )->dispatchPostRequests();
			self::fail( 'A successful capture operation must redirect.' );
		} catch ( DebugCaptureRedirect $redirect ) {
			return $redirect->url;
		}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused redirect and coordinator spies.
final class DebugCaptureRedirect extends \RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected.' );
	}
}

class DebugCaptureTestDispatcher extends Dispatcher {
	protected function currentUserId(): int {
		return 7;
	}

	protected function redirectTo( string $url ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test spy preserves the fixed redirect URL for assertions.
		throw new DebugCaptureRedirect( $url );
	}
}

final class HtmxDebugCaptureResponse extends \RuntimeException {
	public function __construct(
		public readonly ?string $toastMessage,
		public readonly ?string $error,
		public readonly int $status
	) {
		parent::__construct( 'HTMX response sent.' );
	}
}

final class HtmxDiagnosticsResponse extends \RuntimeException {
	public function __construct( public readonly bool $succeeded ) {
		parent::__construct( 'HTMX diagnostics response sent.' );
	}
}

final class HtmxDebugCaptureTestDispatcher extends DebugCaptureTestDispatcher {
	protected function respondToHtmxDebugCapture( ?string $message, ?string $error, int $status ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test spy captures fixed method arguments without output.
		throw new HtmxDebugCaptureResponse( $message, $error, $status );
	}

	protected function respondToHtmxDiagnostics( bool $succeeded ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test spy captures a fixed method argument without output.
		throw new HtmxDiagnosticsResponse( $succeeded );
	}
}

final class OperatorDispatcherCoordinator extends DeploymentCoordinator {
	public int $requests = 0;
	public function __construct() {}
	public function requestRunner(): string {
		++$this->requests;

		return 'scheduled';
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile

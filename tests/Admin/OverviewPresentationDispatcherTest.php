<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\OnboardingPresenter;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Storage/StorageTestEnvironment.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class OverviewPresentationDispatcherTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$_SERVER['REQUEST_METHOD']                   = 'POST';
		$_GET                                        = array( 'page' => 'ran-booster' );
		$_POST                                       = array(
			'ran_booster' => array(
				'action'       => 'overview-presentation',
				'presentation' => 'dashboard',
			),
		);
		$GLOBALS['ran_booster_storage_test_options'] = array( 'unrelated' => array( 'keep' => 'unchanged' ) );
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['ran_booster_storage_test_options'], $GLOBALS['ran_booster_test_capability_checks'], $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_nonce_checks'], $GLOBALS['ran_booster_test_nonce_valid'], $GLOBALS['ran_booster_storage_test_option_apply_write'], $GLOBALS['ran_booster_storage_test_option_write_result'] );
	}

	public function test_get_does_not_mutate(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['ran_booster']       = array(
			'action'       => 'overview-presentation',
			'presentation' => 'onboarding',
		);
		$this->dispatch();
		self::assertSame( array( 'unrelated' => array( 'keep' => 'unchanged' ) ), $GLOBALS['ran_booster_storage_test_options'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_denied_capability_and_invalid_nonce_do_not_mutate(): void {
		foreach ( array( 'capability', 'nonce' ) as $failure ) {
			$GLOBALS['ran_booster_test_capabilities']['manage_options'] = 'capability' !== $failure;
			$GLOBALS['ran_booster_test_nonce_valid']                    = 'nonce' !== $failure;
			try {
				$this->dispatch();
				self::fail( 'The protected request must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertArrayNotHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_storage_test_options'] );
			}
		}
	}

	public function test_invalid_values_do_not_mutate(): void {
		foreach ( array( array( 'dashboard' ), 'invalid', '' ) as $value ) {
			$_POST['ran_booster']['presentation'] = $value;
			try {
				$this->dispatch();
			} catch ( OverviewPresentationRedirect $redirect ) {
				self::fail( 'An invalid value must not redirect as success.' );
			} catch ( RuntimeException $exception ) {
				self::assertNotSame( '', $exception->getMessage() );
			}
			self::assertArrayNotHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_storage_test_options'] );
		}
	}

	public function test_wrong_route_and_extra_fields_are_rejected(): void {
		foreach ( array( 'wrong-route', 'array-route', 'extra-field' ) as $case ) {
			$_GET['page'] = 'wrong-route' === $case ? 'other' : ( 'array-route' === $case ? array( 'ran-booster' ) : 'ran-booster' );
			if ( 'extra-field' === $case ) {
				$_POST['ran_booster']['extra'] = 'unexpected';
			}
			try {
				$this->dispatch();
				self::fail( 'Invalid request must be rejected.' );
			} catch ( OverviewPresentationRedirect $redirect ) {
				self::fail( 'Invalid request must not redirect.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Invalid Overview preference. Reload Overview and try again.', $exception->getMessage() );
			}
			self::assertArrayNotHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_storage_test_options'] );
		}
	}

	public function test_skip_return_and_repeated_choice_preserve_other_options(): void {
		foreach ( array( 'dashboard', 'onboarding', 'onboarding' ) as $index => $value ) {
			$GLOBALS['ran_booster_package_operation_user_id'] = 0 === $index ? 101 : 202;
			self::assertSame( 0 === $index ? 101 : 202, get_current_user_id() );
			$GLOBALS['ran_booster_storage_test_option_write_result'] = 2 !== $index;
			$_POST['ran_booster']['presentation']                    = $value;
			try {
				$this->dispatch();
				self::fail( 'A successful preference change must redirect.' );
			} catch ( OverviewPresentationRedirect $redirect ) {
				self::assertStringContainsString( 'tab=overview', $redirect->url );
			}
			self::assertArrayHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_storage_test_options'] );
			self::assertSame( $value, $GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] );
			self::assertSame( array( 'keep' => 'unchanged' ), $GLOBALS['ran_booster_storage_test_options']['unrelated'] );
		}
		self::assertContains( 'manage_options', $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array_fill( 0, 3, 'ran-booster-overview-presentation' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_failed_write_does_not_report_success(): void {
		$GLOBALS['ran_booster_storage_test_option_apply_write']  = false;
		$GLOBALS['ran_booster_storage_test_option_write_result'] = false;
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		try {
			$this->dispatch( $dashboard );
			self::fail( 'The failed write must report a failure.' );
		} catch ( OverviewPresentationRedirect $redirect ) {
			self::fail( 'A failed write must not redirect as success.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The Overview preference could not be saved. Try again.', $exception->getMessage() );
		}
		self::assertArrayNotHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_storage_test_options'] );
	}

	private function dispatch( ?Dashboard $dashboard = null ): void {
		$providers  = new ProviderRegistry();
		$plugins    = new PluginRepository();
		$themes     = new ThemeRepository();
		$dispatcher = new OverviewPresentationTestDispatcher(
			$dashboard ?? $this->createStub( Dashboard::class ),
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class )
		);
		$dispatcher->dispatch_post_requests();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Redirect spy belongs to this dispatcher test.
final class OverviewPresentationTestDispatcher extends Dispatcher {
	protected function redirect_to( string $url ): never {
		throw new OverviewPresentationRedirect( $url );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Redirect spy belongs to this dispatcher test.
final class OverviewPresentationRedirect extends RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected.' );
	}
}

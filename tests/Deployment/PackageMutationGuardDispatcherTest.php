<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use WP_Error;

final class PackageMutationGuardDispatcherTest extends TestCase {

	/** @return array<string, array{string, list<string>}> */
	public static function packageActionCapabilities(): array {
		return array(
			'install plugin'           => array( 'install-plugin', array( 'install_plugins' ) ),
			'install theme'            => array( 'install-theme', array( 'install_themes' ) ),
			'edit plugin'              => array( 'edit-plugin', array( 'update_plugins' ) ),
			'edit theme'               => array( 'edit-theme', array( 'update_themes' ) ),
			'update plugin'            => array( 'update-plugin', array( 'update_plugins' ) ),
			'update theme'             => array( 'update-theme', array( 'update_themes' ) ),
			'unlink plugin'            => array( 'unlink-plugin', array( 'update_plugins' ) ),
			'unlink theme'             => array( 'unlink-theme', array( 'update_themes' ) ),
			'unlink and delete plugin' => array( 'unlink-delete-plugin', array( 'update_plugins', 'delete_plugins', 'activate_plugins' ) ),
			'unlink and delete theme'  => array( 'unlink-delete-theme', array( 'update_themes', 'delete_themes' ) ),
		);
	}

	/** @return array<string, array{string, string, string}> */
	public static function bulkActionCapabilities(): array {
		return array(
			'bulk plugin update'       => array( 'bulk-plugin', 'queue-update', 'update_plugins' ),
			'bulk plugin activation'   => array( 'bulk-plugin', 'activate-plugins', 'activate_plugins' ),
			'bulk plugin deactivation' => array( 'bulk-plugin', 'deactivate-plugins', 'deactivate_plugins' ),
			'bulk theme update'        => array( 'bulk-theme', 'queue-update', 'update_themes' ),
		);
	}

	protected function setUp(): void {
		$_POST = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_nonce_checks']                = array();
		$GLOBALS['ran_booster_test_capabilities']                = array();
		$GLOBALS['ran_booster_test_nonce_valid']                 = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	#[DataProvider( 'bulkActionCapabilities' )]
	public function testBulkRoutesUseTheirExactCapabilityBeforeTheirNonce( string $action, string $operation, string $capability ): void {
		$_SERVER['REQUEST_METHOD']               = 'POST';
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$_POST['ran_booster']                    = array(
			'action'      => $action,
			'bulk_action' => $operation,
			'identifiers' => array( 'example/example.php' ),
		);
		$dashboard                               = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'bulkPackageRedirect' );

		try {
			$this->dispatcher( $dashboard )->dispatchPostRequests();
			self::fail( 'Expected the invalid bulk nonce to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( array( $capability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testAcceptedBulkPostAlwaysUsesSignedRedirectFlow(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['ran_booster']      = array(
			'action'      => 'bulk-plugin',
			'bulk_action' => 'queue-update',
			'identifiers' => array( 'example/example.php' ),
		);
		$target                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&signed=bulk';
		$dashboard                 = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'bulkPackageRedirect' )
			->with(
				'plugin',
				self::callback(
					static fn ( \RAN\Admin\BulkPackageResult $result ): bool => 'unavailable' === $result->errorCode
						&& 'queue-update' === $result->operation
				)
			)
			->willReturn( $target );

		try {
			$this->dispatcher( $dashboard, true )->dispatchPostRequests();
			self::fail( 'Expected the test redirect interceptor to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'redirect:' . $target, $exception->getMessage() );
		}
	}

	public function testMultisiteBlocksInstallBeforeAnInvalidProviderCanBeResolved(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = true;
		$_POST['ran_booster']                                    = array(
			'action'   => 'install-plugin',
			'provider' => 'not-registered',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with( self::callback( static fn ( mixed $message ): bool => $message instanceof WP_Error && 'ran_booster_unsupported_package_operation' === $message->get_error_code() ) );

		$this->dispatcher( $dashboard )->dispatchPostRequests();

		self::assertSame( array( 'install-plugin' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testExactSelfUpdateIsBlockedBeforeItsMissingRepositoryCanBeLookedUp(): void {
		$_POST['ran_booster'] = array(
			'action' => 'update-plugin',
			'file'   => 'ran-booster/ran-booster.php',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with( self::callback( static fn ( mixed $message ): bool => $message instanceof WP_Error && 'ran_booster_unsupported_package_operation' === $message->get_error_code() ) );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$this->dispatcher( $dashboard )->dispatchPostRequests();
	}

	public function testRepositoryResolutionWarningKeepsSafeFailureContextForLogging(): void {
		$_POST['ran_booster'] = array(
			'action'     => 'install-plugin',
			'provider'   => 'gh',
			'repository' => 'RocketsAreNostalgic/tnyGmaps',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'ran_booster_repository_error' === $message->get_error_code()
				),
				self::isInstanceOf( \Throwable::class ),
				array(
					'operation' => 'install-plugin',
					'step'      => 'package_repository_resolve',
					'provider'  => 'gh',
				)
			);
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$this->dispatcher( $dashboard )->dispatchPostRequests();
	}

	#[DataProvider( 'packageActionCapabilities' )]
	public function testEachPackageActionUsesItsExactCapabilityAndNonce( string $action, array $capabilities ): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$_POST['ran_booster']                    = array( 'action' => $action );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		try {
			$this->dispatcher( $dashboard )->dispatchPostRequests();
			self::fail( 'Expected the invalid nonce to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( $capabilities, $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'packageActionCapabilities' )]
	public function testEachPackageActionFailsBeforeNonceWithoutItsExactCapability( string $action, array $capabilities ): void {
		$deniedCapability = $capabilities[0];
		$GLOBALS['ran_booster_test_capabilities'][ $deniedCapability ] = false;
		$_POST['ran_booster'] = array( 'action' => $action );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		try {
			$this->dispatcher( $dashboard )->dispatchPostRequests();
			self::fail( 'Expected the missing capability to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'sufficient permissions', $exception->getMessage() );
		}

		self::assertSame( array( $deniedCapability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testReinstallAfterSaveRequiresBothEditAndUpdateNoncesBeforeMutation(): void {
		$_POST['ran_booster'] = array(
			'action'               => 'edit-plugin',
			'reinstall_after_save' => '1',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		try {
			$this->dispatcher( $dashboard )->dispatchPostRequests();
			self::fail( 'Expected the missing reinstall nonce to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( array( 'update_plugins' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'edit-plugin', 'update-plugin' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testUnknownPackageActionDoesNotEnterAnAuthorizationOrMutationRoute(): void {
		$_POST['ran_booster'] = array( 'action' => 'remove-plugin' );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );
		$this->dispatcher( $dashboard )->dispatchPostRequests();

		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testSuccessfulPackageOperationRedirectsToTheDashboardTarget(): void {
		$input                = array(
			'action' => 'update-plugin',
			'file'   => 'example/example.php',
		);
		$_POST['ran_booster'] = $input;
		$target               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&signed=1';
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postPackageOperation' )
			->with( 'update-plugin', $input )
			->willReturn( $target );

		try {
			$this->dispatcher( $dashboard, true )->dispatchPostRequests();
			self::fail( 'Expected the test redirect interceptor to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'redirect:' . $target, $exception->getMessage() );
		}
	}

	public function testFailedPackageOperationDoesNotRedirect(): void {
		$_POST['ran_booster'] = array(
			'action'     => 'update-theme',
			'stylesheet' => 'example',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'postPackageOperation' )->willReturn( false );

		$this->dispatcher( $dashboard, true )->dispatchPostRequests();

		self::assertSame( array( 'update_themes' ), $GLOBALS['ran_booster_test_capability_checks'] );
	}

	private function dispatcher(
		Dashboard $dashboard,
		bool $interceptRedirect = false
	): Dispatcher {
		$providers = new ProviderRegistry( new \Tests\Support\NullLoggingFacade() );
		$secrets   = new SecretsFile( null, array() );
		$plugins   = $this->createStub( PluginRepository::class );
		$themes    = $this->createStub( ThemeRepository::class );
		$args      = array(
			$dashboard,
			$providers,
			$secrets,
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
		);
		if ( $interceptRedirect ) {
				return new class( ...$args ) extends Dispatcher {
					protected function redirectTo( string $url ): never {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only interception preserves the exact redirect target.
						throw new \RuntimeException( 'redirect:' . $url );
					}
				};
		}

		return new Dispatcher( ...$args );
	}
}

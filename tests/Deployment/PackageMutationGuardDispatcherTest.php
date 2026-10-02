<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';

use RAN\AbstractPackage;
use RAN\Admin\BulkPackageActionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\Dispatcher;
use RAN\ManagedRepository;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use WP_Error;

final class PackageMutationGuardDispatcherTest extends TestCase {

	/** @return array<string, array{string, list<string>}> */
	public static function package_action_capabilities(): array {
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
	public static function bulk_action_capabilities(): array {
		return array(
			'bulk plugin update'       => array( 'bulk-plugin', 'queue-update', 'update_plugins' ),
			'bulk plugin activation'   => array( 'bulk-plugin', 'activate-plugins', 'activate_plugins' ),
			'bulk plugin deactivation' => array( 'bulk-plugin', 'deactivate-plugins', 'deactivate_plugins' ),
			'bulk theme update'        => array( 'bulk-theme', 'queue-update', 'update_themes' ),
		);
	}

	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_nonce_checks']                = array();
		$GLOBALS['ran_booster_test_capabilities']                = array();
		$GLOBALS['ran_booster_test_nonce_valid']                 = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HX_REQUEST'] );
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	public function test_package_route_rejects_a_non_post_request_before_authority_or_mutation(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST['ran_booster']      = array(
			'action' => 'update-plugin',
			'file'   => 'example/example.php',
		);
		$dashboard                 = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$this->dispatcher( $dashboard )->dispatch_post_requests();

		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_bulk_route_rejects_a_non_post_request_before_authority_or_mutation(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST['ran_booster']      = array(
			'action'      => 'bulk-plugin',
			'bulk_action' => 'activate-plugins',
			'identifiers' => array( 'example/example.php' ),
		);
		$dashboard                 = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'bulk_package_redirect' );

		$this->dispatcher( $dashboard )->dispatch_post_requests();

		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_controller_rejects_an_unknown_bulk_route_before_authority_or_request_parsing(): void {
		$dashboard  = $this->createMock( Dashboard::class );
		$controller = new PackageAdminController();

		self::assertNull(
			$controller->manage_bulk(
				$dashboard,
				'bulk-plugin-unknown',
				array(
					'bulk_action' => new \stdClass(),
					'identifiers' => array( new \stdClass() ),
				),
				true
			)
		);
		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'bulk_action_capabilities' )]
	public function test_bulk_routes_use_their_exact_capability_before_their_nonce( string $action, string $operation, string $capability ): void {
		$_SERVER['REQUEST_METHOD']               = 'POST';
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$_POST['ran_booster']                    = array(
			'action'      => $action,
			'bulk_action' => $operation,
			'identifiers' => array( 'example/example.php' ),
		);
		$dashboard                               = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'bulk_package_redirect' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the invalid bulk nonce to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( array( $capability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'bulk_action_capabilities' )]
	public function test_bulk_routes_stop_before_nonce_without_their_exact_capability( string $action, string $operation, string $capability ): void {
		$GLOBALS['ran_booster_test_capabilities'][ $capability ] = false;
		$_POST['ran_booster']                                    = array(
			'action'      => $action,
			'bulk_action' => $operation,
			'identifiers' => array( 'example/example.php' ),
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'bulk_package_redirect' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the missing bulk capability to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'sufficient permissions', $exception->getMessage() );
		}

		self::assertSame( array( $capability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/** @return array<string, array{string, string, string, bool}> */
	public static function bulk_policy_journeys(): array {
		return array(
			'plugin native' => array( 'plugin', 'bulk-plugin', 'example/example.php', false ),
			'plugin htmx'   => array( 'plugin', 'bulk-plugin', 'example/example.php', true ),
			'theme native'  => array( 'theme', 'bulk-theme', 'example-theme', false ),
			'theme htmx'    => array( 'theme', 'bulk-theme', 'example-theme', true ),
		);
	}

	#[DataProvider( 'bulk_policy_journeys' )]
	public function test_real_bulk_policy_journeys_keep_authority_mutation_readback_and_signed_transport_together(
		string $type,
		string $action,
		string $identifier,
		bool $htmx
	): void {
		if ( $htmx ) {
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
		}
		$_POST['ran_booster'] = array(
			'action'      => $action,
			'bulk_action' => 'policy-disabled',
			'identifiers' => array( $identifier ),
		);
		$package              = new class( $identifier ) extends AbstractPackage {
			public function __construct( private readonly string $identifier ) {
				$this->set_installation_slug( str_contains( $identifier, '/' ) ? dirname( $identifier ) : $identifier );
				$this->set_repository( new ManagedRepository( 'fixture', 'owner/repository', 'R_fixture', 'main' ) );
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
		$plugins              = $this->createMock( PluginRepository::class );
		$themes               = $this->createMock( ThemeRepository::class );
		if ( 'plugin' === $type ) {
			$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->with( $identifier )->willReturn( $package );
			$plugins->expects( self::once() )
				->method( 'set_plugin_deployment_policies' )
				->with(
					self::callback( static fn ( array $snapshots ): bool => $identifier === ( $snapshots[0]['package'] ?? null ) ),
					DeploymentPolicy::DISABLED
				)
				->willReturnCallback(
					static function ( array $snapshots, DeploymentPolicy $policy ) use ( $package ): array {
						$package->set_deployment_policy( $policy );

						return array(
							'selected'  => count( $snapshots ),
							'changed'   => count( $snapshots ),
							'unchanged' => 0,
						);
					}
				);
			$themes->expects( self::never() )->method( 'set_theme_deployment_policies' );
		} else {
			$themes->expects( self::once() )->method( 'booster_theme_from_stylesheet' )->with( $identifier )->willReturn( $package );
			$themes->expects( self::once() )
				->method( 'set_theme_deployment_policies' )
				->with(
					self::callback( static fn ( array $snapshots ): bool => $identifier === ( $snapshots[0]['package'] ?? null ) ),
					DeploymentPolicy::DISABLED
				)
				->willReturnCallback(
					static function ( array $snapshots, DeploymentPolicy $policy ) use ( $package ): array {
						$package->set_deployment_policy( $policy );

						return array(
							'selected'  => count( $snapshots ),
							'changed'   => count( $snapshots ),
							'unchanged' => 0,
						);
					}
				);
			$plugins->expects( self::never() )->method( 'set_plugin_deployment_policies' );
		}
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'bulk-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'bulk-lock' )->willReturn( true );
		$service    = new BulkPackageActionService(
			$plugins,
			$themes,
			new ProviderRegistry(),
			new SecretsFile( null, array() ),
			$this->createStub( DeploymentCoordinator::class ),
			$lock
		);
		$controller = new PackageAdminController( bulk_actions: $service );
		$dashboard  = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'bulk_package_redirect' )
			->with(
				$type,
				self::callback(
					static fn ( \RAN\Admin\BulkPackageResult $result ): bool => 1 === $result->changed
						&& 1 === $result->selected
						&& '' === $result->error_code
				)
			)
			->willReturnCallback(
				static fn ( string $actual_type, \RAN\Admin\BulkPackageResult $result ): string => $controller->bulk_redirect(
					$actual_type,
					$result,
					array( 's' => 'preserved-filter' )
				)
			);

		try {
			$this->dispatcher( $dashboard, true, $controller )->dispatch_post_requests();
			self::fail( 'Expected the test redirect interceptor to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			$target = str_replace( 'redirect:', '', $exception->getMessage() );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused redirect-query assertion.
			parse_str( (string) parse_url( $target, PHP_URL_QUERY ), $query );
			self::assertSame( 'ran-booster-' . $type . 's', $query['page'] ?? null );
			self::assertSame( 'preserved-filter', $query['s'] ?? null );
			self::assertSame( '1', $query['ran_booster_bulk_changed'] ?? null );
			self::assertStringStartsWith( 'nonce-for-', (string) ( $query['_ran_booster_bulk_notice_nonce'] ?? '' ) );
		}

		self::assertSame( DeploymentPolicy::DISABLED, $package->get_deployment_policy() );
		self::assertSame( array( 'plugin' === $type ? 'update_plugins' : 'update_themes' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_accepted_bulk_post_always_uses_signed_redirect_flow(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['ran_booster']      = array(
			'action'      => 'bulk-plugin',
			'bulk_action' => 'queue-update',
			'identifiers' => array( 'example/example.php' ),
		);
		$target                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&signed=bulk';
		$dashboard                 = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'bulk_package_redirect' )
			->with(
				'plugin',
				self::callback(
					static fn ( \RAN\Admin\BulkPackageResult $result ): bool => 'unavailable' === $result->error_code
						&& 'queue-update' === $result->operation
				)
			)
			->willReturn( $target );

		try {
			$this->dispatcher( $dashboard, true )->dispatch_post_requests();
			self::fail( 'Expected the test redirect interceptor to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'redirect:' . $target, $exception->getMessage() );
		}
	}

	public function test_multisite_blocks_install_before_an_invalid_provider_can_be_resolved(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = true;
		$_POST['ran_booster']                                    = array(
			'action'   => 'install-plugin',
			'provider' => 'not-registered',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
			->with( self::callback( static fn ( mixed $message ): bool => $message instanceof WP_Error && 'ran_booster_unsupported_package_operation' === $message->get_error_code() ) );

		$this->dispatcher( $dashboard )->dispatch_post_requests();

		self::assertSame( array( 'install-plugin' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_exact_self_update_is_blocked_before_its_missing_repository_can_be_looked_up(): void {
		$_POST['ran_booster'] = array(
			'action' => 'update-plugin',
			'file'   => 'ran-booster/ran-booster.php',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
			->with( self::callback( static fn ( mixed $message ): bool => $message instanceof WP_Error && 'ran_booster_unsupported_package_operation' === $message->get_error_code() ) );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$this->dispatcher( $dashboard )->dispatch_post_requests();
	}

	public function test_repository_resolution_warning_keeps_safe_failure_context_for_logging(): void {
		$_POST['ran_booster'] = array(
			'action'     => 'install-plugin',
			'provider'   => 'gh',
			'repository' => 'RocketsAreNostalgic/tnyGmaps',
		);

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
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
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$this->dispatcher( $dashboard )->dispatch_post_requests();
	}

	#[DataProvider( 'package_action_capabilities' )]
	public function test_each_package_action_uses_its_exact_capability_and_nonce( string $action, array $capabilities ): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$_POST['ran_booster']                    = array( 'action' => $action );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the invalid nonce to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( $capabilities, $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	#[DataProvider( 'package_action_capabilities' )]
	public function test_each_package_action_fails_before_nonce_without_its_exact_capability( string $action, array $capabilities ): void {
		$denied_capability = $capabilities[0];
		$GLOBALS['ran_booster_test_capabilities'][ $denied_capability ] = false;
		$_POST['ran_booster'] = array( 'action' => $action );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the missing capability to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'sufficient permissions', $exception->getMessage() );
		}

		self::assertSame( array( $denied_capability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/** @return array<string, array{string, list<string>, string}> */
	public static function later_capability_denials(): array {
		return array(
			'plugin delete'     => array( 'unlink-delete-plugin', array( 'update_plugins', 'delete_plugins' ), 'delete_plugins' ),
			'plugin activation' => array( 'unlink-delete-plugin', array( 'update_plugins', 'delete_plugins', 'activate_plugins' ), 'activate_plugins' ),
			'theme delete'      => array( 'unlink-delete-theme', array( 'update_themes', 'delete_themes' ), 'delete_themes' ),
		);
	}

	#[DataProvider( 'later_capability_denials' )]
	public function test_later_delete_capabilities_independently_stop_before_nonce_and_mutation(
		string $action,
		array $expected_checks,
		string $denied_capability
	): void {
		$GLOBALS['ran_booster_test_capabilities'][ $denied_capability ] = false;
		$_POST['ran_booster'] = array( 'action' => $action );
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the later missing capability to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'sufficient permissions', $exception->getMessage() );
		}

		self::assertSame( $expected_checks, $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/** @return array<string, array{string, string, string}> */
	public static function reinstall_nonce_actions(): array {
		return array(
			'plugin' => array( 'edit-plugin', 'update-plugin', 'update_plugins' ),
			'theme'  => array( 'edit-theme', 'update-theme', 'update_themes' ),
		);
	}

	#[DataProvider( 'reinstall_nonce_actions' )]
	public function test_reinstall_after_save_requires_both_edit_and_update_nonces_before_mutation(
		string $action,
		string $update_action,
		string $capability
	): void {
		$_POST['ran_booster'] = array(
			'action'               => $action,
			'reinstall_after_save' => '1',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'Expected the missing reinstall nonce to stop package dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Invalid nonce.', $exception->getMessage() );
		}

		self::assertSame( array( $capability ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( $action, $update_action ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_unknown_package_action_does_not_enter_an_authorization_or_mutation_route(): void {
		$_POST['ran_booster'] = array( 'action' => 'remove-plugin' );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$this->dispatcher( $dashboard )->dispatch_post_requests();

		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/** @return array<string, array{bool}> */
	public static function package_transports(): array {
		return array(
			'native'   => array( false ),
			'enhanced' => array( true ),
		);
	}

	#[DataProvider( 'package_transports' )]
	public function test_successful_package_operation_uses_the_same_signed_dashboard_target_for_native_and_htmx( bool $htmx ): void {
		if ( $htmx ) {
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
		}
		$input                = array(
			'action' => 'update-plugin',
			'file'   => 'example/example.php',
		);
		$_POST['ran_booster'] = $input;
		$target               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&signed=1';
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'post_package_operation' )
			->with( 'update-plugin', $input )
			->willReturn( $target );

		try {
			$this->dispatcher( $dashboard, true )->dispatch_post_requests();
			self::fail( 'Expected the test redirect interceptor to stop dispatch.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'redirect:' . $target, $exception->getMessage() );
		}
	}

	public function test_failed_package_operation_does_not_redirect(): void {
		$_POST['ran_booster'] = array(
			'action'     => 'update-theme',
			'stylesheet' => 'example',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->willReturn( false );

		$this->dispatcher( $dashboard, true )->dispatch_post_requests();

		self::assertSame( array( 'update_themes' ), $GLOBALS['ran_booster_test_capability_checks'] );
	}

	private function dispatcher(
		Dashboard $dashboard,
		bool $intercept_redirect = false,
		?PackageAdminController $package_admin = null
	): Dispatcher {
		$providers = new ProviderRegistry();
		$secrets   = new SecretsFile( null, array() );
		$plugins   = $this->createStub( PluginRepository::class );
		$themes    = $this->createStub( ThemeRepository::class );
		$args      = array(
			$dashboard,
			$providers,
			$secrets,
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			$package_admin ?? new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
		);
		if ( $intercept_redirect ) {
				return new class( ...$args ) extends Dispatcher {
					protected function redirect_to( string $url ): never {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only interception preserves the exact redirect target.
						throw new \RuntimeException( 'redirect:' . $url );
					}
				};
		}

		return new Dispatcher( ...$args );
	}
}

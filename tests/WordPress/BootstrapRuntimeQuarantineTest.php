<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Runtime\UnsupportedMultisiteBootstrap;

final class BootstrapRuntimeQuarantineTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_converted_multisite_boots_only_the_recovery_allowlist(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$plugin_file = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $plugin_file;

		self::assertSame( 'multisite_unsupported', RAN_BOOSTER_RUNTIME_MODE );
		self::assertArrayHasKey( $plugin_file, $GLOBALS['ran_booster_activation_callbacks'] );
		self::assertArrayHasKey( $plugin_file, $GLOBALS['ran_booster_deactivation_callbacks'] );
		self::assertFalse( function_exists( 'ran_booster' ) );
		self::assertArrayNotHasKey( 'ran_booster_instance', $GLOBALS );

		$actions = $GLOBALS['ran_booster_bootstrap_actions'];
		self::assertCount( 2, $actions );
		self::assertSame(
			array( 'init', 'network_admin_notices' ),
			array_column( $actions, 'hook' )
		);
		self::assertInstanceOf( UnsupportedMultisiteBootstrap::class, $actions[1]['callback'][0] );
		self::assertSame( 'render_notice', $actions[1]['callback'][1] );
		self::assertSame( array(), $GLOBALS['ran_booster_bootstrap_filters'] );
		self::assertSame( array(), $GLOBALS['ran_booster_fired_actions'] );

		$actions[0]['callback']();
		self::assertSame(
			array(
				array(
					'domain'     => 'ran-booster',
					'deprecated' => false,
					'path'       => dirname( plugin_basename( $plugin_file ) ) . '/languages',
				),
			),
			$GLOBALS['ran_booster_loaded_textdomains']
		);
		$action_hooks = array_column( $GLOBALS['ran_booster_bootstrap_actions'], 'hook' );
		$filter_hooks = array_column( $GLOBALS['ran_booster_bootstrap_filters'], 'hook' );
		foreach (
			array(
				'admin_init',
				'admin_menu',
				'network_admin_menu',
				'rest_api_init',
				WordPressWorkerWakeup::HOOK,
			) as $prohibited_hook
		) {
			self::assertNotContains( $prohibited_hook, $action_hooks );
		}
		self::assertSame(
			array(),
			array_values(
				array_filter(
					$action_hooks,
					static fn ( string $hook ): bool => str_starts_with( $hook, 'wp_ajax_' )
				)
			)
		);
		self::assertNotContains( 'http_request_args', $filter_hooks );
		self::assertSame( array(), $GLOBALS['ran_booster_fired_actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsupported_lifecycle_refuses_activation_and_clears_only_the_worker_schedule(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$plugin_file = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $plugin_file;

		try {
			$GLOBALS['ran_booster_activation_callbacks'][ $plugin_file ]();
			self::fail( 'Unsupported Multisite activation must stop through wp_die().' );
		} catch ( \RuntimeException $failure ) {
			self::assertStringContainsString( 'does not support WordPress Multisite', $failure->getMessage() );
		}

		$GLOBALS['ran_booster_deactivation_callbacks'][ $plugin_file ]();

		self::assertSame(
			array(
				array(
					'hook'      => WordPressWorkerWakeup::HOOK,
					'arguments' => array(),
				),
			),
			$GLOBALS['ran_booster_cleared_cron_hooks']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsupported_notice_is_restricted_to_network_plugin_managers(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$plugin_file = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $plugin_file;
		$notice = $GLOBALS['ran_booster_bootstrap_actions'][1]['callback'];

		ob_start();
		$notice();
		$notice();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'does not support WordPress Multisite', $output );
		self::assertStringContainsString( 'does not intentionally change shared plugin or theme files', $output );
		self::assertStringContainsString( 'Do not delete Booster tables', $output );
		self::assertStringContainsString( 'may still be updated manually through WordPress Updates', $output );
		self::assertStringContainsString( 'is-dismissible', $output );
		self::assertStringContainsString(
			plugin_dir_url( $plugin_file ) . 'views/multisite-recovery.html',
			$output
		);
		self::assertSame( 1, substr_count( $output, 'data-ran-booster-unsupported-multisite-notice' ) );
		self::assertStringNotContainsString( 'ran-booster-plugins', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsupported_notice_is_hidden_from_unauthorized_administrators(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
		$GLOBALS['ran_booster_bootstrap_manage_network_plugins'] = false;

		require dirname( __DIR__, 2 ) . '/ran-booster.php';
		$notice = $GLOBALS['ran_booster_bootstrap_actions'][1]['callback'];

		ob_start();
		$notice();
		self::assertSame( '', ob_get_clean() );
	}
}

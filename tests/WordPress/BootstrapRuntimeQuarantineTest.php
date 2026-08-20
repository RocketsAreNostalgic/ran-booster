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
	public function testConvertedMultisiteBootsOnlyTheRecoveryAllowlist(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$pluginFile = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $pluginFile;

		self::assertSame( 'multisite_unsupported', RAN_BOOSTER_RUNTIME_MODE );
		self::assertArrayHasKey( $pluginFile, $GLOBALS['ran_booster_activation_callbacks'] );
		self::assertArrayHasKey( $pluginFile, $GLOBALS['ran_booster_deactivation_callbacks'] );
		self::assertFalse( function_exists( 'ran_booster' ) );
		self::assertArrayNotHasKey( 'ran_booster_instance', $GLOBALS );

		$actions = $GLOBALS['ran_booster_bootstrap_actions'];
		self::assertCount( 2, $actions );
		self::assertSame(
			array( 'plugins_loaded', 'network_admin_notices' ),
			array_column( $actions, 'hook' )
		);
		self::assertSame( PHP_INT_MAX - 1, $actions[0]['priority'] );
		self::assertInstanceOf( UnsupportedMultisiteBootstrap::class, $actions[1]['callback'][0] );
		self::assertSame( 'renderNotice', $actions[1]['callback'][1] );
		self::assertCount( 1, $GLOBALS['ran_booster_bootstrap_filters'] );
		$noticeFilter = $GLOBALS['ran_booster_bootstrap_filters'][0];
		self::assertSame( 'ran_wp_github_release_updater_notice', $noticeFilter['hook'] );
		self::assertSame( 10, $noticeFilter['priority'] );
		self::assertSame( 2, $noticeFilter['acceptedArgs'] );
		self::assertIsCallable( $noticeFilter['callback'] );
		$notice  = array(
			'message'     => 'Raw diagnostic.',
			'remediation' => 'Raw remediation.',
		);
		$context = array(
			'type'       => 'plugin',
			'package'    => plugin_basename( $pluginFile ),
			'repository' => 'RocketsAreNostalgic/ran-booster',
		);
		self::assertNull( $noticeFilter['callback']( $notice, $context ) );
		self::assertSame(
			$notice,
			$noticeFilter['callback']( $notice, array_merge( $context, array( 'package' => 'different-install/ran-booster.php' ) ) )
		);
		self::assertSame( array(), $GLOBALS['ran_booster_fired_actions'] );

		// Complete the request-local updater arbitration. Only Booster's isolated,
		// forced-manual self-updater may add native WordPress update hooks.
		$actions[0]['callback']();

		$actionHooks = array_column( $GLOBALS['ran_booster_bootstrap_actions'], 'hook' );
		$filterHooks = array_column( $GLOBALS['ran_booster_bootstrap_filters'], 'hook' );
		foreach (
			array(
				'admin_init',
				'admin_menu',
				'network_admin_menu',
				'rest_api_init',
				WordPressWorkerWakeup::HOOK,
			) as $prohibitedHook
		) {
			self::assertNotContains( $prohibitedHook, $actionHooks );
		}
		self::assertSame(
			array(),
			array_values(
				array_filter(
					$actionHooks,
					static fn ( string $hook ): bool => str_starts_with( $hook, 'wp_ajax_' )
				)
			)
		);
		self::assertNotContains( 'http_request_args', $filterHooks );
		self::assertSame( array(), $GLOBALS['ran_booster_fired_actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnsupportedLifecycleRefusesActivationAndClearsOnlyTheWorkerSchedule(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$pluginFile = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $pluginFile;

		try {
			$GLOBALS['ran_booster_activation_callbacks'][ $pluginFile ]();
			self::fail( 'Unsupported Multisite activation must stop through wp_die().' );
		} catch ( \RuntimeException $failure ) {
			self::assertStringContainsString( 'does not support WordPress Multisite', $failure->getMessage() );
		}

		$GLOBALS['ran_booster_deactivation_callbacks'][ $pluginFile ]();

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
	public function testUnsupportedNoticeIsRestrictedToNetworkPluginManagers(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		define( 'WPINC', 'wp-includes' );
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

		$pluginFile = dirname( __DIR__, 2 ) . '/ran-booster.php';
		require $pluginFile;
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
			plugin_dir_url( $pluginFile ) . 'views/multisite-recovery.html',
			$output
		);
		self::assertSame( 1, substr_count( $output, 'data-ran-booster-unsupported-multisite-notice' ) );
		self::assertStringNotContainsString( 'ran-booster-plugins', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnsupportedNoticeIsHiddenFromUnauthorizedAdministrators(): void {
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

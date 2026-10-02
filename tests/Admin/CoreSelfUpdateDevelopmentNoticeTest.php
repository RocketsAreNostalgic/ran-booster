<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\WordPress\CoreSelfUpdatePolicy;

final class CoreSelfUpdateDevelopmentNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed']       = true;
		$GLOBALS['ran_booster_repository_admin_inline_styles'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_inline_styles']
		);
	}

	public function test_source_checkout_renders_one_friendly_scoped_notice(): void {
		$policy = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		$notice = new CoreSelfUpdateDevelopmentNotice( policy: $policy, screen_id: 'plugins' );

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-core-development-notice' ) );
		self::assertStringContainsString( 'RAN Booster development detected:', $html );
		self::assertStringContainsString( 'Core updates are disabled to protect this source checkout.', $html );
		self::assertStringContainsString( 'notice notice-info', $html );
		self::assertStringNotContainsString( 'github_updater_', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
	}

	public function test_source_checkout_loads_its_scoped_tint_on_every_allowed_screen(): void {
		$policy = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		$notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );

		$notice->enqueue_style();

		self::assertSame(
			array( '[data-ran-booster-core-development-notice] { background-color: #e5f3ff; }' ),
			$GLOBALS['ran_booster_repository_admin_inline_styles']['common']
		);
	}

	public function test_plugins_screen_uses_the_global_callback_and_booster_screen_uses_the_shell_callback(): void {
		$policy = CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' );

		$plugins_notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );
		ob_start();
		$plugins_notice->render_global();
		$plugins_notice->render_shell_inline();
		$plugins_html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $plugins_html, 'data-ran-booster-core-development-notice' ) );
		self::assertStringContainsString( 'class="notice notice-info"', $plugins_html );

		$booster_notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'toplevel_page_ran-booster' );
		ob_start();
		$booster_notice->render_global();
		$booster_notice->render_shell_inline();
		$booster_html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $booster_html, 'data-ran-booster-core-development-notice' ) );
		self::assertStringContainsString( 'class="notice notice-info inline"', $booster_html );
	}

	public function test_unverified_non_source_unauthorized_and_unrelated_screens_render_nothing(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-development-notice-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$unverified = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.0.0' );

		self::assertFalse(
			( new CoreSelfUpdateDevelopmentNotice( $unverified, 'plugins' ) )->should_render()
		);

		$source = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		self::assertFalse(
			( new CoreSelfUpdateDevelopmentNotice( $source, 'dashboard' ) )->should_render()
		);

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		$unauthorized                                    = new CoreSelfUpdateDevelopmentNotice( $source, 'plugins' );
		self::assertFalse( $unauthorized->should_render() );
		$unauthorized->enqueue_style();
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_inline_styles'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}
}

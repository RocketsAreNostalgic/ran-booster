<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\WordPress\CoreSelfUpdatePolicy;

final class CoreSelfUpdateDevelopmentNoticeTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed']       = true;
		$GLOBALS['ran_booster_repository_admin_inline_styles'] = array();
		$GLOBALS['ran_booster_repository_admin_user_id']       = 17;
		$GLOBALS['ran_booster_repository_admin_user_meta']     = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_inline_styles'],
			$GLOBALS['ran_booster_repository_admin_user_id'],
			$GLOBALS['ran_booster_repository_admin_user_meta']
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
		self::assertStringContainsString( 'is-dismissible', $html );
		self::assertStringContainsString( 'data-ran-booster-notice-state="source-checkout-v1"', $html );
		self::assertSame( 1, substr_count( $html, 'class="notice-dismiss" hidden' ) );
		self::assertStringContainsString( 'Dismiss this notice.', $html );
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

	public function test_hidden_notice_loads_tint_only_for_authorized_booster_screen(): void {
		$policy = CoreSelfUpdatePolicy::detect( '/missing/ran-booster.php', '1.0.0' );
		( new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' ) )->enqueue_style();
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_inline_styles'] );
		( new CoreSelfUpdateDevelopmentNotice( $policy, 'toplevel_page_ran-booster' ) )->enqueue_style();
		self::assertCount( 1, $GLOBALS['ran_booster_repository_admin_inline_styles']['common'] );
		$GLOBALS['ran_booster_repository_admin_allowed']       = false;
		$GLOBALS['ran_booster_repository_admin_inline_styles'] = array();
		( new CoreSelfUpdateDevelopmentNotice( $policy, 'toplevel_page_ran-booster' ) )->enqueue_style();
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_inline_styles'] );
	}

	public function test_plugins_screen_uses_the_global_callback_and_booster_screen_uses_the_shell_callback(): void {
		$policy = CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' );

		$plugins_notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );
		ob_start();
		$plugins_notice->render_global();
		$plugins_notice->render_shell_inline();
		$plugins_html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $plugins_html, 'data-ran-booster-core-development-notice' ) );
		self::assertStringContainsString( 'class="notice notice-info is-dismissible ran-wp-ui-notice ran-wp-ui-box-sizing is-info"', $plugins_html );

		$booster_notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'toplevel_page_ran-booster' );
		ob_start();
		$booster_notice->render_global();
		$booster_notice->render_shell_inline();
		$booster_html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $booster_html, 'data-ran-booster-core-development-notice' ) );
		self::assertStringContainsString( 'class="notice notice-info inline is-dismissible ran-wp-ui-notice ran-wp-ui-box-sizing is-info"', $booster_html );
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

	/** @return array<string, mixed> */
	private function dismiss( string $state ): array {
		$_POST = array( 'state' => $state );
		$GLOBALS['ran_booster_repository_admin_nonce_valid'] = true;

		try {
			$result = ( new DevelopmentSafetyNoticeController() )->handle();
			self::assertIsArray( $result );

			return $result;
		} finally {
			$_POST = array();
			unset( $GLOBALS['ran_booster_repository_admin_nonce_valid'] );
		}
	}

	public function test_dismissal_hides_the_notice_for_that_user_only_and_keeps_the_fact(): void {
		$policy = CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' );
		$result = $this->dismiss( CoreSelfUpdateDevelopmentNotice::NOTICE_STATE );
		self::assertTrue( $result['success'] );

		$notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );
		ob_start();
		$notice->render();
		self::assertSame( '', (string) ob_get_clean() );
		self::assertFalse( $notice->should_show() );
		self::assertTrue( $notice->should_render(), 'Detection and the local-development fact are unchanged.' );
		self::assertSame( 'source-checkout-v1', $GLOBALS['ran_booster_repository_admin_user_meta'][17][ CoreSelfUpdateDevelopmentNotice::USER_META_KEY ] );

		$GLOBALS['ran_booster_repository_admin_user_id'] = 18;
		ob_start();
		( new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' ) )->render();
		self::assertSame( 1, substr_count( (string) ob_get_clean(), 'data-ran-booster-core-development-notice' ) );
	}

	public function test_a_newer_notice_state_shows_again_and_local_fact_ignores_dismissal(): void {
		$GLOBALS['ran_booster_repository_admin_user_meta'][17][ CoreSelfUpdateDevelopmentNotice::USER_META_KEY ] = 'source-checkout-v0';
		$policy = CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' );
		self::assertTrue( ( new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' ) )->should_show() );

		$GLOBALS['ran_booster_repository_admin_user_meta'][17][ CoreSelfUpdateDevelopmentNotice::USER_META_KEY ] = CoreSelfUpdateDevelopmentNotice::NOTICE_STATE;
		$notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'toplevel_page_ran-booster' );
		ob_start();
		$notice->render_local_fact();
		self::assertStringContainsString( 'Core updates are disabled to protect this source checkout.', (string) ob_get_clean() );
	}
}

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

	public function testSourceCheckoutRendersOneFriendlyScopedNotice(): void {
		$policy = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		$notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );

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

	public function testSourceCheckoutLoadsItsScopedTintOnEveryAllowedScreen(): void {
		$policy = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		$notice = new CoreSelfUpdateDevelopmentNotice( $policy, 'plugins' );

		$notice->enqueueStyle();

		self::assertSame(
			array( '[data-ran-booster-core-development-notice] { background-color: #e5f3ff; }' ),
			$GLOBALS['ran_booster_repository_admin_inline_styles']['common']
		);
	}

	public function testUnverifiedNonSourceUnauthorizedAndUnrelatedScreensRenderNothing(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-development-notice-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$unverified = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.0.0' );

		self::assertFalse(
			( new CoreSelfUpdateDevelopmentNotice( $unverified, 'plugins' ) )->shouldRender()
		);

		$source = CoreSelfUpdatePolicy::detect(
			dirname( __DIR__, 2 ) . '/ran-booster.php',
			'0.1.0-alpha.23'
		);
		self::assertFalse(
			( new CoreSelfUpdateDevelopmentNotice( $source, 'dashboard' ) )->shouldRender()
		);

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		$unauthorized                                    = new CoreSelfUpdateDevelopmentNotice( $source, 'plugins' );
		self::assertFalse( $unauthorized->shouldRender() );
		$unauthorized->enqueueStyle();
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_inline_styles'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}
}

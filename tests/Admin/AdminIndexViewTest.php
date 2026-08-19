<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class AdminIndexViewTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_admin_view_year'],
			$GLOBALS['ran_booster_admin_view_plugin_headers']
		);
		unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_after_admin_shell'] );
	}

	public function testStaticPageRendersScopedRootLabelledNavigationAndOnePageHeading(): void {
		$GLOBALS['ran_booster_admin_view_year']           = '2042';
		$GLOBALS['ran_booster_admin_view_plugin_headers'] = array(
			'author'     => 'Header Author',
			'author_uri' => 'https://example.test/header-author',
		);
		$messages                = array();
		$name                    = 'RAN Booster';
		$view                    = 'index';
		$developmentSafetyNotice = true;
		$tab                     = 'documentation';
		$tabView                 = 'documentation.php';
		$tabs                    = array(
			array(
				'key'    => 'overview',
				'label'  => 'Overview',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				'active' => false,
			),
			array(
				'key'    => 'gh',
				'label'  => 'GitHub',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
				'active' => false,
			),
			array(
				'key'    => 'bb',
				'label'  => 'Bitbucket',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=bb',
				'active' => false,
			),
			array(
				'key'    => 'portability',
				'label'  => 'Transporter',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
				'active' => false,
			),
			array(
				'key'    => 'documentation',
				'label'  => 'Documentation',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
				'active' => true,
			),
			array(
				'key'    => 'troubleshooting',
				'label'  => 'Troubleshooting',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
				'active' => false,
			),
		);
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_after_admin_shell'] = array(
			static function (): void {
				echo '<div data-ran-booster-core-development-notice></div>';
			},
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<div class="wrap ran-booster-admin">', $html );
		self::assertStringContainsString( 'id="ran-booster-admin-feedback-toast"', $html );
		self::assertStringContainsString( 'data-ran-booster-feedback-timeout="6000"', $html );
		self::assertMatchesRegularExpression(
			'/<div class="ran-booster-footer">\s*<div id="ran-booster-admin-feedback-toast"/',
			$html
		);
		self::assertSame( 1, preg_match_all( '/<h1\b/', $html ) );
		self::assertStringContainsString(
			'<a href="https://example.test/wp-admin/admin.php?page=ran-booster">',
			$html
		);
		self::assertMatchesRegularExpression(
			'/<h1 class="ran-admin-shell__title">\\s*<a href="https:\\/\\/example\\.test\\/wp-admin\\/admin\\.php\\?page=ran-booster">RAN Booster<\\/a>\\s*<\\/h1>/',
			$html
		);
		self::assertStringContainsString( 'class="ran-admin-shell__logo"', $html );
		self::assertStringContainsString( 'assets/ran-booster-mark.svg" width="56" height="56" alt="" aria-hidden="true"', $html );
		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		self::assertStringContainsString( '<nav class="ran-admin-shell__navigation" aria-label="RAN Booster sections">', $html );
		self::assertStringNotContainsString( 'nav-tab-wrapper', $html );
		self::assertStringNotContainsString( 'class="nav-tab', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins">Plugins</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-themes">Themes</a>', $html );
		self::assertStringNotContainsString( '>Transporter</a>', $html );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringNotContainsString( 'aria-current="false"', $html );
		self::assertStringContainsString( 'id="ran-booster-documentation-heading"', $html );
		self::assertStringNotContainsString( 'Set up GitHub deployments', $html );
		self::assertStringContainsString( 'Deploy themes and plugins straight from your Git repos.', $html );
		self::assertStringContainsString( 'Install from a public repository first. Add private access and Push-to-Deploy only when you need them.', $html );
		self::assertStringNotContainsString( 'Start with a repository', $html );
		self::assertStringContainsString( 'class="notice notice-warning inline is-dismissible"', $html );
		self::assertStringContainsString( 'data-ran-booster-development-safety', $html );
		self::assertStringContainsString( '<strong>Development safety:</strong>', $html );
		self::assertStringContainsString( 'set Updates to Disabled', $html );
		$straplinePosition  = strpos( $html, 'Deploy themes and plugins straight from your Git repos.' );
		$navigationPosition = strpos( $html, '<nav class="ran-admin-shell__navigation"' );
		$coreNoticePosition = strpos( $html, 'data-ran-booster-core-development-notice' );
		$wrapPosition       = strpos( $html, '<div class="wrap ran-booster-admin">' );
		$markerPosition     = strpos( $html, '<hr class="wp-header-end">' );
		$noticePosition     = strpos( $html, '<strong>Development safety:</strong>' );
		foreach ( array( $straplinePosition, $navigationPosition, $coreNoticePosition, $wrapPosition, $markerPosition, $noticePosition ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $straplinePosition < $navigationPosition );
		self::assertTrue( $navigationPosition < $wrapPosition );
		self::assertTrue( $wrapPosition < $markerPosition );
		self::assertTrue( $markerPosition < $coreNoticePosition );
		self::assertTrue( $coreNoticePosition < $noticePosition );
		self::assertStringContainsString( 'Copyright © 2042', $html );
		self::assertStringContainsString(
			'<a href="https://example.test/header-author">Header Author</a>',
			$html
		);

		$developmentSafetyNotice = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$withoutNotice = (string) ob_get_clean();

		self::assertStringNotContainsString( '<strong>Development safety:</strong>', $withoutNotice );

		$tab     = 'overview';
		$tabView = 'onboarding.php';
		foreach ( $tabs as &$adminTab ) {
			$adminTab['active'] = 'overview' === $adminTab['key'];
		}
		unset( $adminTab );
		$onboarding = array(
			'provider_links'      => array(),
			'install_plugin_url'  => '/plugins',
			'install_theme_url'   => '/themes',
			'portability_url'     => '/portability',
			'documentation_url'   => '/documentation',
			'troubleshooting_url' => '/troubleshooting',
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$overview = (string) ob_get_clean();

		self::assertSame( 1, preg_match_all( '/<h1\b/', $overview ) );
		self::assertSame( 1, substr_count( $overview, 'aria-current="page"' ) );
		self::assertStringContainsString( '>Overview</a>', $overview );
		self::assertStringContainsString( '<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title">Start with a repository</h2>', $overview );
	}
}

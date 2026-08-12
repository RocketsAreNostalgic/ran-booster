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
			'<a class="ran-booster-brand__link" href="https://example.test/wp-admin/admin.php?page=ran-booster">',
			$html
		);
		self::assertStringContainsString( 'RAN Booster</a></h1>', $html );
		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		self::assertStringContainsString( '<nav class="nav-tab-wrapper" aria-label="RAN Booster sections">', $html );
		self::assertSame( 6, preg_match_all( '/class="nav-tab(?: nav-tab-active)?"/', $html ) );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringNotContainsString( 'aria-current="false"', $html );
		self::assertStringContainsString( 'id="ran-booster-documentation-heading"', $html );
		self::assertStringNotContainsString( 'Set up GitHub deployments', $html );
		self::assertStringContainsString( 'Safe, portable and extensible repository deployment for WordPress — modern and independent.', $html );
		self::assertStringContainsString( 'Install from a public repository first. Add private access and Push-to-Deploy only when you need them.', $html );
		self::assertStringNotContainsString( 'Start with a repository', $html );
		self::assertStringContainsString( 'class="notice notice-warning inline is-dismissible"', $html );
		self::assertStringContainsString( 'data-ran-booster-development-safety', $html );
		self::assertStringContainsString( '<strong>Development safety:</strong>', $html );
		self::assertStringContainsString( 'set Updates to Disabled', $html );
		$straplinePosition = strpos( $html, 'Safe, portable and extensible repository deployment' );
		$markerPosition    = strpos( $html, '<hr class="wp-header-end">' );
		$noticePosition    = strpos( $html, '<strong>Development safety:</strong>' );
		$tabsPosition      = strpos( $html, '<nav class="nav-tab-wrapper"' );
		foreach ( array( $straplinePosition, $markerPosition, $noticePosition, $tabsPosition ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $straplinePosition < $markerPosition );
		self::assertTrue( $markerPosition < $noticePosition );
		self::assertTrue( $noticePosition < $tabsPosition );
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

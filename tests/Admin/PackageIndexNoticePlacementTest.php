<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PackageIndexNoticePlacementTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations']   = array();
		$GLOBALS['ran_booster_package_view_translations'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'], $GLOBALS['ran_booster_package_view_translations'] );
	}

	public function testStructuredContentionInfoNoticeKeepsItsProtectedActivityLink(): void {
		$messages = array(
			array(
				'type'    => 'info',
				'code'    => 'ran_booster_deployment_active',
				'message' => 'A deployment is active. <a href="https://example.test/activity?attempt=42">Review activity</a>.',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/notices.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-info inline', $html );
		self::assertStringContainsString( 'attempt=42', $html );
		self::assertStringContainsString( 'Review activity', $html );
	}

	public function testPackageIndexCanReorderTheCompleteManagedPackageHeadingWithoutChangingPackageIdentity(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Managed %s'] = '%s administrés';
		$packageView             = PackagePagePresenter::plugin();
		$messages                = array();
		$name                    = 'RAN Booster';
		$view                    = 'packages/index';
		$developmentSafetyNotice = false;
		$packages                = array();
		$packageProviders        = array();
		$packageActivity         = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                    = array();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Plugins administrés</h2>', $html );
		self::assertStringNotContainsString( '>Managed Plugins</h2>', $html );
		self::assertSame( 'plugin', $packageView->getType() );
		self::assertSame( 'ran-booster-plugins', $packageView->getPageSlug() );
		self::assertStringContainsString( 'ran-booster-admin--packages', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
	}

	/** @return array<string, array{PackagePagePresenter, string}> */
	public static function packageTypes(): array {
		return array(
			'plugins' => array( PackagePagePresenter::plugin(), 'Managed Plugins' ),
			'themes'  => array( PackagePagePresenter::theme(), 'Managed Themes' ),
		);
	}

	#[DataProvider( 'packageTypes' )]
	public function testPackageIndexPlacesNoticesAfterItsHeadingAndDescription( PackagePagePresenter $packageView, string $heading ): void {
		$messages                = array(
			array(
				'type'            => 'success',
				'message'         => 'Scoped package result.',
				'code'            => 'bulk_update_queue',
				'queued_updates'  => 2,
				'skipped_updates' => 1,
			),
		);
		$name                    = 'RAN Booster';
		$view                    = 'packages/index';
		$developmentSafetyNotice = true;
		$packages                = array();
		$packageProviders        = array();
		$packageActivity         = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                    = array(
			array(
				'key'    => 'overview',
				'label'  => 'Overview',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster',
				'active' => false,
			),
			array(
				'key'    => 'portability',
				'label'  => 'Transporter',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
				'active' => false,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		$mastheadPosition    = strpos( $html, 'Deploy themes and plugins straight from your Git repos.' );
		$headingPosition     = strpos( $html, $heading );
		$descriptionPosition = strpos( $html, 'Review package health, deploy saved branches and hand published releases to WordPress.' );
		$resultPosition      = strpos( $html, 'Scoped package result.' );
		$safetyPosition      = strpos( $html, '<strong>Development safety:</strong>' );
		$tablePosition       = strpos( $html, 'ran-booster-package-table' );

		foreach ( array( $mastheadPosition, $headingPosition, $descriptionPosition, $resultPosition, $safetyPosition, $tablePosition ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $mastheadPosition < $headingPosition );
		self::assertTrue( $headingPosition < $descriptionPosition );
		self::assertTrue( $descriptionPosition < $resultPosition );
		self::assertTrue( $resultPosition < $safetyPosition );
		self::assertTrue( $safetyPosition < $tablePosition );
		self::assertSame( 1, substr_count( $html, 'Scoped package result.' ) );
		self::assertSame( 1, substr_count( $html, '<strong>Development safety:</strong>' ) );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-package-intro"' ) );
		self::assertSame( 1, substr_count( $html, 'notice notice-warning inline is-dismissible' ) );
		self::assertSame( 2, substr_count( $html, 'is-dismissible' ) );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-development-safety' ) );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-update-summary data-queued' ) );
		self::assertStringContainsString( 'data-queued="2" data-skipped="1"', $html );
		self::assertStringContainsString( 'data-ran-booster-update-summary-message', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-success', $html );
		self::assertStringNotContainsString( 'class="nav-tab-wrapper"', $html );
		self::assertStringContainsString( 'class="ran-admin-shell__navigation"', $html );
		self::assertStringContainsString( 'class="ran-admin-shell__logo"', $html );
		self::assertStringContainsString( '>Overview</a>', $html );
		self::assertStringContainsString( '>Plugins</a>', $html );
		self::assertStringContainsString( '>Themes</a>', $html );
		self::assertStringNotContainsString( '>Transporter</a>', $html );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringContainsString(
			'plugin' === $packageView->getType()
				? 'href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins" aria-current="page">Plugins</a>'
				: 'href="https://example.test/wp-admin/admin.php?page=ran-booster-themes" aria-current="page">Themes</a>',
			$html
		);
	}
}

<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PackageIndexNoticePlacementTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations']   = array();
		$GLOBALS['ran_booster_package_view_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'], $GLOBALS['ran_booster_package_view_translations'] );
	}

	public function test_structured_contention_info_notice_keeps_its_protected_activity_link(): void {
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

	public function test_package_index_can_reorder_the_complete_managed_package_heading_without_changing_package_identity(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Managed %s'] = '%s administrés';
		$package_view              = PackagePagePresenter::plugin();
		$messages                  = array();
		$name                      = 'RAN Booster';
		$view                      = 'packages/index';
		$development_safety_notice = false;
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                      = array();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Plugins administrés</h2>', $html );
		self::assertStringNotContainsString( '>Managed Plugins</h2>', $html );
		self::assertSame( 'plugin', $package_view->get_type() );
		self::assertSame( 'ran-booster-plugins', $package_view->get_page_slug() );
		self::assertStringContainsString( 'ran-booster-admin--packages', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
	}

	/** @return array<string, array{PackagePagePresenter, string}> */
	public static function package_types(): array {
		return array(
			'plugins' => array( PackagePagePresenter::plugin(), 'Managed Plugins' ),
			'themes'  => array( PackagePagePresenter::theme(), 'Managed Themes' ),
		);
	}

	#[DataProvider( 'package_types' )]
	public function test_package_index_places_notices_after_its_heading_and_description( PackagePagePresenter $package_view, string $heading ): void {
		$messages                  = array(
			array(
				'type'            => 'success',
				'message'         => 'Scoped package result.',
				'code'            => 'bulk_update_queue',
				'queued_updates'  => 2,
				'skipped_updates' => 1,
			),
		);
		$name                      = 'RAN Booster';
		$view                      = 'packages/index';
		$development_safety_notice = true;
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                      = array(
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

		$masthead_position    = strpos( $html, 'Deploy themes and plugins straight from your Git repos.' );
		$heading_position     = strpos( $html, $heading );
		$description_position = strpos( $html, 'Review package health, deploy saved branches and hand published releases to WordPress.' );
		$result_position      = strpos( $html, 'Scoped package result.' );
		$safety_position      = strpos( $html, '<strong>Development safety:</strong>' );
		$table_position       = strpos( $html, 'ran-booster-package-table' );

		foreach ( array( $masthead_position, $heading_position, $description_position, $result_position, $safety_position, $table_position ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $masthead_position < $heading_position );
		self::assertTrue( $heading_position < $description_position );
		self::assertTrue( $description_position < $result_position );
		self::assertTrue( $result_position < $safety_position );
		self::assertTrue( $safety_position < $table_position );
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
			'plugin' === $package_view->get_type()
				? 'href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins" aria-current="page">Plugins</a>'
				: 'href="https://example.test/wp-admin/admin.php?page=ran-booster-themes" aria-current="page">Themes</a>',
			$html
		);
	}
}

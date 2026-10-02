<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

final class PackageIndexFilterControlsTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_view_multisite'],
			$GLOBALS['ran_booster_dashboard_test_multisite']
		);
	}

	/**
	 * @return array<string, array{PackagePagePresenter, string, string, string}>
	 */
	public static function package_types(): array {
		return array(
			'plugins' => array(
				PackagePagePresenter::plugin(),
				'ran-booster-plugins',
				'plugin',
				'plugins',
			),
			'themes'  => array(
				PackagePagePresenter::theme(),
				'ran-booster-themes',
				'theme',
				'themes',
			),
		);
	}

	#[DataProvider( 'package_types' )]
	public function test_it_renders_the_shared_selected_query_contract(
		PackagePagePresenter $package_view,
		string $page_slug,
		string $type,
		string $plural
	): void {
		$html = $this->render(
			$package_view,
			array(
				'search'   => 'Release Plugin',
				'provider' => 'gh',
				'source'   => 'release_asset',
				'policy'   => 'automatic',
			),
			4,
			array(
				array(
					'code'  => 'gh',
					'label' => 'GitHub',
				),
				array(
					'code'  => 'bb',
					'label' => 'Bitbucket',
				),
			)
		);

		self::assertStringContainsString(
			'<h2 class="wp-heading-inline ran-booster-package-heading">Managed ' . ucfirst( $plural ) . '</h2>',
			$html
		);
		self::assertStringContainsString(
			'class="page-title-action" href="https://example.test/wp-admin/admin.php?page=' . $page_slug . '-create"',
			$html
		);
		self::assertStringContainsString( 'class="ran-booster-package-list-filters"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-list-search search-form"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-list-controls"', $html );
		self::assertStringContainsString( 'method="get"', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin.php"', $html );
		self::assertStringContainsString( 'name="page" value="' . $page_slug . '"', $html );
		self::assertStringContainsString( 'type="hidden" name="s" value="Release Plugin"', $html );
		self::assertStringContainsString( 'type="search" name="s" value="Release Plugin"', $html );
		self::assertMatchesRegularExpression( '/name="provider"[\s\S]*value="gh"\s+selected="selected"[\s\S]*>GitHub</', $html );
		self::assertMatchesRegularExpression( '/name="source"[\s\S]*value="release_asset"\s+selected="selected"/', $html );
		self::assertMatchesRegularExpression( '/name="policy"[\s\S]*value="automatic"\s+selected="selected"/', $html );
		self::assertStringContainsString( '>Filter</button>', $html );
		self::assertStringContainsString( '>Search</button>', $html );
		self::assertStringContainsString(
			'href="https://example.test/wp-admin/admin.php?page=' . $page_slug . '">Clear filters</a>',
			$html
		);
		self::assertStringContainsString( 'class="tablenav top ran-booster-package-toolbar"', $html );
		self::assertStringContainsString( 'class="tablenav-pages one-page"', $html );
		self::assertStringContainsString( '<span class="displaying-num">0 of 4 items</span>', $html );
		self::assertStringContainsString( 'Install another ' . $type, $html );
		self::assertStringNotContainsString( 'data-ran-booster-bulk-form', $html );
		self::assertStringContainsString( 'No managed ' . $plural . ' match the current filters.', $html );
		self::assertStringNotContainsString( 'ran-booster-package-empty-state', $html );
		self::assertStringNotContainsString( 'Add your first ' . $type, $html );
	}

	#[DataProvider( 'package_types' )]
	public function test_raw_empty_inventory_offers_prominent_first_package_onboarding(
		PackagePagePresenter $package_view,
		string $page_slug,
		string $type,
		string $plural
	): void {
		$html = $this->render(
			$package_view,
			array(
				'search'   => '',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			0,
			array()
		);

		self::assertStringNotContainsString( 'ran-booster-package-list-controls', $html );
		self::assertStringNotContainsString( 'class="page-title-action"', $html );
		self::assertStringContainsString(
			'<td colspan="4" class="ran-booster-package-empty-state">',
			$html
		);
		self::assertStringContainsString( '<h3>Add your first ' . $type . '</h3>', $html );
		self::assertStringContainsString( 'No ' . $plural . ' are managed by RAN Booster yet.', $html );
		self::assertStringContainsString(
			'class="button button-primary" href="https://example.test/wp-admin/admin.php?page=' . $page_slug . '-create">Add your first ' . $type . '</a>',
			$html
		);
		self::assertStringNotContainsString( 'ran-booster-package-toolbar', $html );
		self::assertStringNotContainsString( 'data-ran-booster-bulk-form', $html );
		self::assertStringNotContainsString( 'Install another ' . $type, $html );
		self::assertStringNotContainsString( 'match the current filters', $html );
	}

	#[DataProvider( 'package_types' )]
	public function test_network_package_indexes_keep_every_package_route_on_the_network_admin_base(
		PackagePagePresenter $package_view,
		string $page_slug,
		string $type,
		string $plural
	): void {
		unset( $type, $plural );
		$GLOBALS['ran_booster_package_view_multisite']   = true;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = true;
		$html = $this->render(
			$package_view,
			array(
				'search'   => 'release',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			1,
			array()
		);

		self::assertStringContainsString( 'href="https://example.test/wp-admin/network/admin.php?page=' . $page_slug . '-create"', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/network/admin.php"', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/network/admin.php?page=' . $page_slug . '">Clear filters</a>', $html );
		self::assertStringNotContainsString( 'https://example.test/wp-admin/admin.php?page=' . $page_slug, $html );
	}

	/**
	 * @param array{search:string,provider:string,source:string,policy:string} $package_list_state
	 * @param list<array{code:string,label:string}>                           $package_provider_options
	 */
	private function render(
		PackagePagePresenter $package_view,
		array $package_list_state,
		int $package_list_total,
		array $package_provider_options
	): string {
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$package_extension_rows    = array();
		$package_extension_actions = array();
		$messages                  = array();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';

		return (string) ob_get_clean();
	}
}

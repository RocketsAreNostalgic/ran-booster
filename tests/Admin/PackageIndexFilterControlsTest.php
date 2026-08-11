<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

final class PackageIndexFilterControlsTest extends TestCase {
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_view_multisite'],
			$GLOBALS['ran_booster_dashboard_test_multisite']
		);
	}

	/**
	 * @return array<string, array{PackagePagePresenter, string, string, string}>
	 */
	public static function packageTypes(): array {
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

	#[DataProvider( 'packageTypes' )]
	public function testItRendersTheSharedSelectedQueryContract(
		PackagePagePresenter $packageView,
		string $pageSlug,
		string $type,
		string $plural
	): void {
		$html = $this->render(
			$packageView,
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
			'class="page-title-action" href="https://example.test/wp-admin/admin.php?page=' . $pageSlug . '-create"',
			$html
		);
		self::assertStringContainsString( 'class="ran-booster-package-list-filters"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-list-search search-form"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-list-controls"', $html );
		self::assertStringContainsString( 'method="get"', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin.php"', $html );
		self::assertStringContainsString( 'name="page" value="' . $pageSlug . '"', $html );
		self::assertStringContainsString( 'type="hidden" name="s" value="Release Plugin"', $html );
		self::assertStringContainsString( 'type="search" name="s" value="Release Plugin"', $html );
		self::assertMatchesRegularExpression( '/name="provider"[\s\S]*value="gh"\s+selected="selected"[\s\S]*>GitHub</', $html );
		self::assertMatchesRegularExpression( '/name="source"[\s\S]*value="release_asset"\s+selected="selected"/', $html );
		self::assertMatchesRegularExpression( '/name="policy"[\s\S]*value="automatic"\s+selected="selected"/', $html );
		self::assertStringContainsString( '>Filter</button>', $html );
		self::assertStringContainsString( '>Search</button>', $html );
		self::assertStringContainsString(
			'href="https://example.test/wp-admin/admin.php?page=' . $pageSlug . '">Clear filters</a>',
			$html
		);
		self::assertStringContainsString( 'class="tablenav top ran-booster-package-toolbar"', $html );
		self::assertStringContainsString( 'class="tablenav-pages one-page"', $html );
		self::assertStringContainsString( '<span class="displaying-num">0 of 4 items</span>', $html );
		self::assertStringContainsString( 'Install another ' . $type, $html );
		self::assertStringNotContainsString( 'data-ran-booster-bulk-form', $html );
		self::assertStringContainsString( 'No managed ' . $plural . ' match the current filters.', $html );
	}

	#[DataProvider( 'packageTypes' )]
	public function testRawEmptyInventoryKeepsItsInstallActionAndDistinctMessage(
		PackagePagePresenter $packageView,
		string $pageSlug,
		string $type,
		string $plural
	): void {
		$html = $this->render(
			$packageView,
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
		self::assertStringContainsString(
			'class="page-title-action" href="https://example.test/wp-admin/admin.php?page=' . $pageSlug . '-create"',
			$html
		);
		self::assertStringContainsString( 'Install another ' . $type, $html );
		self::assertStringNotContainsString( 'ran-booster-package-toolbar', $html );
		self::assertStringNotContainsString( 'data-ran-booster-bulk-form', $html );
		self::assertStringContainsString( 'No ' . $plural . ' managed by RAN Booster yet.', $html );
		self::assertStringNotContainsString( 'match the current filters', $html );
	}

	#[DataProvider( 'packageTypes' )]
	public function testNetworkPackageIndexesKeepEveryPackageRouteOnTheNetworkAdminBase(
		PackagePagePresenter $packageView,
		string $pageSlug,
		string $type,
		string $plural
	): void {
		unset( $type, $plural );
		$GLOBALS['ran_booster_package_view_multisite']   = true;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = true;
		$html = $this->render(
			$packageView,
			array(
				'search'   => 'release',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			1,
			array()
		);

		self::assertStringContainsString( 'href="https://example.test/wp-admin/network/admin.php?page=' . $pageSlug . '-create"', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/network/admin.php"', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/network/admin.php?page=' . $pageSlug . '">Clear filters</a>', $html );
		self::assertStringNotContainsString( 'https://example.test/wp-admin/admin.php?page=' . $pageSlug, $html );
	}

	/**
	 * @param array{search:string,provider:string,source:string,policy:string} $packageListState
	 * @param list<array{code:string,label:string}>                           $packageProviderOptions
	 */
	private function render(
		PackagePagePresenter $packageView,
		array $packageListState,
		int $packageListTotal,
		array $packageProviderOptions
	): string {
		$packages                = array();
		$packageProviders        = array();
		$packageActivity         = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$packageExtensionRows    = array();
		$packageExtensionActions = array();
		$messages                = array();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';

		return (string) ob_get_clean();
	}
}

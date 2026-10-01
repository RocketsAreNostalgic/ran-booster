<?php

declare(strict_types=1);

namespace Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PackagePagePresenterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_view_filters']            = array();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_package_view_translations']     = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}


	public function testPluginConfigurationPreservesPluginRouting(): void {
		$config = PackagePagePresenter::plugin();

		self::assertSame( 'plugin', $config->get_type() );
		self::assertSame( 'Plugin', $config->get_singular_label() );
		self::assertSame( 'Plugins', $config->get_plural_label() );
		self::assertSame( 'file', $config->get_identifier_field() );
		self::assertSame( 'ran-booster-plugins', $config->get_page_slug() );
		self::assertSame( 'ran-booster-plugins-create', $config->get_create_page_slug() );
		self::assertSame( 'install-plugin', $config->get_action( 'install' ) );
		self::assertSame( 'update-plugin', $config->get_action( 'update' ) );
		self::assertSame( 'unlink-plugin', $config->get_action( 'unlink' ) );
		self::assertSame( 'unlink-delete-plugin', $config->get_action( 'unlink-delete' ) );
		self::assertSame( 'bulk-plugin', $config->get_action( 'bulk' ) );
	}

	public function testThemeConfigurationPreservesThemeRouting(): void {
		$config = PackagePagePresenter::theme();

		self::assertSame( 'theme', $config->get_type() );
		self::assertSame( 'Theme', $config->get_singular_label() );
		self::assertSame( 'Themes', $config->get_plural_label() );
		self::assertSame( 'stylesheet', $config->get_identifier_field() );
		self::assertSame( 'ran-booster-themes', $config->get_page_slug() );
		self::assertSame( 'ran-booster-themes-create', $config->get_create_page_slug() );
		self::assertSame( 'edit-theme', $config->get_action( 'edit' ) );
		self::assertSame( 'unlink-theme', $config->get_action( 'unlink' ) );
		self::assertSame( 'unlink-delete-theme', $config->get_action( 'unlink-delete' ) );
		self::assertSame( 'bulk-theme', $config->get_action( 'bulk' ) );
	}

	public function testPackageTypeLabelsUseContextualTranslationsWithoutChangingMachineValues(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'] = array(
			"Managed package type singular label\004Plugin" => 'Extension',
			"Managed package type plural label\004Plugins" => 'Extensions',
			"Managed package type singular label\004Theme" => 'Habillage',
			"Managed package type plural label\004Themes"  => 'Habillages',
		);

		$plugin = PackagePagePresenter::plugin();
		$theme  = PackagePagePresenter::theme();

		self::assertSame( 'Extension', $plugin->get_singular_label() );
		self::assertSame( 'Extensions', $plugin->get_plural_label() );
		self::assertSame( 'Habillage', $theme->get_singular_label() );
		self::assertSame( 'Habillages', $theme->get_plural_label() );
		self::assertSame( 'plugin', $plugin->get_type() );
		self::assertSame( 'file', $plugin->get_identifier_field() );
		self::assertSame( 'ran-booster-plugins', $plugin->get_page_slug() );
		self::assertSame( 'theme', $theme->get_type() );
		self::assertSame( 'stylesheet', $theme->get_identifier_field() );
		self::assertSame( 'ran-booster-themes', $theme->get_page_slug() );
	}

	public function testPackageTypeLabelsResolveTranslationsAvailableAfterConstruction(): void {
		$plugin = PackagePagePresenter::plugin();
		$theme  = PackagePagePresenter::theme();

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'] = array(
			"Managed package type singular label\004Plugin" => 'Extension',
			"Managed package type plural label\004Plugins" => 'Extensions',
			"Managed package type singular label\004Theme" => 'Habillage',
			"Managed package type plural label\004Themes"  => 'Habillages',
		);

		self::assertSame( 'Extension', $plugin->get_singular_label() );
		self::assertSame( 'Extensions', $plugin->get_plural_label() );
		self::assertSame( 'Habillage', $theme->get_singular_label() );
		self::assertSame( 'Habillages', $theme->get_plural_label() );
	}

	public function testUnsupportedActionsAreRejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PackagePagePresenter::plugin()->get_action( 'publish' );
	}

	public function testAdvancedSourceSummaryProjectionFailureFallsBackToCoreSummary(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_advanced_source_summary_projection'] = array(
			static function (): array {
				throw new \RuntimeException( 'Extension unavailable.' );
			},
		);

		$view       = PackagePagePresenter::plugin()->create( array(), false, false, 'branch' );
		$projection = $view['packageSource']['advanced_summary_projection'];

		self::assertSame( 'Branch', $projection['heading'] );
		self::assertSame( array(), $projection['badges'] );
		self::assertSame( '', $projection['status'] );
	}
}

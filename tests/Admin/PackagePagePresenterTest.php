<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

final class PackagePagePresenterTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_view_filters']            = array();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_package_view_translations']     = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}


	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->setUp();
	}

	public function test_plugin_configuration_preserves_plugin_routing(): void {
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

	public function test_theme_configuration_preserves_theme_routing(): void {
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

	public function test_package_type_labels_use_contextual_translations_without_changing_machine_values(): void {
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

	public function test_package_type_labels_resolve_translations_available_after_construction(): void {
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

	public function test_unsupported_actions_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PackagePagePresenter::plugin()->get_action( 'publish' );
	}

	public function test_advanced_source_summary_projection_failure_falls_back_to_core_summary(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_advanced_source_summary_projection'] = array(
			static function (): array {
				throw new \RuntimeException( 'Extension unavailable.' );
			},
		);

		$view       = PackagePagePresenter::plugin()->create( array(), false, false, 'branch' );
		$projection = $view['package_source']['advanced_summary_projection'];

		self::assertSame( 'Branch', $projection['heading'] );
		self::assertSame( array(), $projection['badges'] );
		self::assertSame( '', $projection['status'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_malformed_public_summary_projections_render_the_plain_core_fallback(): void {
		foreach ( array( null, false, 17, array(), array( 'heading' => null ), array( 'heading' => false ), array( 'heading' => 17 ), array( 'heading' => array() ) ) as $projection ) {
			$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_advanced_source_summary_projection'] = array( static fn (): mixed => $projection );
			$html = $this->render_summary( PackagePagePresenter::plugin()->create( array(), false, false, 'branch' ) );
			self::assertStringContainsString( 'Branch · provider default', $html );
			self::assertStringNotContainsString( 'ran-booster-advanced-source-summary__heading', $html );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_public_summary_projection_is_normalized_and_escaped_before_rendering(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_advanced_source_summary_projection'] = array(
			static fn (): array => array(
				'heading' => ' <b>Heading & title</b> ',
				'badges'  => array( false, array( 'label' => array() ), array( 'label' => ' ' ), array( 'label' => ' <i>Badge & label</i> ' ) ),
				'status'  => ' <b>Active & ready</b> ',
			),
		);
		$html = $this->render_summary( PackagePagePresenter::plugin()->create( array(), false, false, 'branch' ) );
		self::assertStringContainsString( 'Heading &amp; title', $html );
		self::assertStringContainsString( 'Badge &amp; label', $html );
		self::assertStringContainsString( 'Active &amp; ready', $html );
		self::assertSame( 1, substr_count( $html, 'ran-booster-advanced-source-summary__badge' ) );
		self::assertStringNotContainsString( '<b>', $html );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_raw_translated_baseline_cleanup_survives_failed_public_projection(): void {
		$package = \RAN\Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$package->set_source( \RAN\PackageSource::RELEASE_ASSET, 2 );
		foreach ( array( false, true ) as $throws ) {
			$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_advanced_source_summary_projection'] = array(
				static function () use ( $throws ): bool {
					if ( $throws ) {
						throw new \RuntimeException( 'Projection unavailable.' );
					}
					return false;
				},
			);
			foreach ( array( '  ', ' <b>Translated & value</b> ' ) as $translation ) {
				$GLOBALS['ran_booster_admin_test_translations']['ran-booster'] = array(
					'Stable' => $translation,
					'Active' => $translation,
				);
				$data = PackagePagePresenter::plugin()->edit( $package, array(), null, 'release_asset' );
				self::assertSame( $translation, $data['package_source']['advanced_summary_projection']['status'] );
				$html = $this->render_summary( $data );
				if ( '' === trim( $translation ) ) {
					self::assertStringNotContainsString( 'ran-booster-advanced-source-summary__heading', $html );
					self::assertStringNotContainsString( 'ran-booster-advanced-source-summary__badge', $html );
				} else {
					self::assertStringContainsString( '&lt;b&gt;Translated &amp; value&lt;/b&gt;', $html );
				}
			}
		}
	}

	/** @param array<string, mixed> $data Presenter-owned view data. */
	private function render_summary( array $data ): string {
		$package_source           = $data['package_source'];
		$package_advanced_summary = $package_source['advanced_summary'];
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/advanced-source-settings.php';
		return (string) ob_get_clean();
	}
}

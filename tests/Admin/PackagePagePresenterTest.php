<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PackagePagePresenterTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_view_filters']            = array();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_package_view_translations']     = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}


	public function test_status_filter_keeps_failed_and_review_updates_from_one_read(): void {
		require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
		require_once __DIR__ . '/../Activity/ActivityConnection.php';
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$token                                   = 'gh:repository-1:11111111-1111-4111-8111-111111111111';
		$packages                                = array();
		foreach ( array( 'failed', 'review', 'clear', 'untracked' ) as $slug ) {
			$plugin = \RAN\Plugin::from_wp_array(
				"$slug/$slug.php",
				array_fill_keys( array( 'Name', 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Title', 'AuthorName' ), '' ) + array( 'Network' => false )
			);
			$plugin->set_repository( new \RAN\ManagedRepository( 'gh', 'owner/' . $slug, 'repository-1', 'main' ) );
			$plugin->set_deployment_policy( 'review' === $slug ? \RAN\Deployment\DeploymentPolicy::MANUAL : \RAN\Deployment\DeploymentPolicy::AUTOMATIC );
			if ( 'untracked' !== $slug ) {
				$plugin->set_adoption_token( $token );
			}
			$packages[] = $plugin;
		}
		$row        = static fn ( string $slug, string $status, string $facts ): object => (object) array(
			'context_key'      => hash( 'sha256', "plugin\0$slug/$slug.php\0$token\0" ),
			'subject_type'     => 'plugin',
			'subject_identity' => "$slug/$slug.php",
			'adoption_token'   => $token,
			'status'           => $status,
			'facts'            => $facts,
		);
		$rows       = array(
			$row( 'review', 'incomplete', '{"deployment_attempt_id":2,"deployment_resolved_at":null}' ),
			$row( 'failed', 'failed', '{"deployment_attempt_id":1}' ),
			$row( 'clear', 'succeeded', '{"deployment_attempt_id":3}' ),
		);
		$connection = new \RAN\Tests\Activity\ActivityConnection();
		$view       = new \RAN\Admin\DeploymentAdminPresenter( activity_store: new \RAN\Activity\ActivityStore( $connection ) );
		$state      = array(
			'search'    => '',
			'provider'  => '',
			'source'    => '',
			'policy'    => '',
			'attention' => 'failed',
		);
		$names      = static fn ( array $index ): array => array_map( static fn ( \RAN\Package $package ): mixed => $package->get_identifier(), $index['packages'] );

		$connection->read_results = array( $rows, $rows, $rows );
		$index                    = PackagePagePresenter::plugin()->index( $packages, array(), $state, $view );
		self::assertSame( array( 'failed/failed.php', 'review/review.php' ), $names( $index ) );
		self::assertTrue( $index['package_attention_enabled'] );
		self::assertSame( 'failed', $index['package_list_state']['attention'] );
		self::assertCount( 1, $connection->queries, 'One decisive read serves the whole list.' );
		self::assertSame( array( 'review/review.php' ), $names( PackagePagePresenter::plugin()->index( $packages, array(), array( 'policy' => 'manual' ) + $state, $view ) ) );
		self::assertSame( array(), $names( PackagePagePresenter::plugin()->index( $packages, array(), array( 'search' => 'clear' ) + $state, $view ) ) );

		$GLOBALS['ran_booster_activity_options'] = array();
		$index                                   = PackagePagePresenter::plugin()->index( $packages, array(), $state, $view );
		self::assertFalse( $index['package_attention_enabled'] );
		self::assertSame( '', $index['package_list_state']['attention'] );
		self::assertCount( 4, $index['packages'], 'Unreadable Activity drops the filter instead of claiming none failed.' );
	}

	public function test_paused_filter_needs_no_activity_and_branch_cannot_be_chosen_while_paused(): void {
		require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
		require_once __DIR__ . '/../Activity/ActivityConnection.php';
		require_once __DIR__ . '/../Support/PackageSourceGateWordPressFunctions.php';
		require_once __DIR__ . '/../Settings/GeneralPreferenceStoreWordPressFunctions.php';
		$GLOBALS['ran_booster_activity_options']       = array();
		$GLOBALS['ran_booster_test_environment_type']  = 'production';
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( array() );
		unset( $GLOBALS['ran_booster_general_preference_cached_option'] );
		$packages = array();
		foreach ( array( 'paused', 'disabled', 'release' ) as $slug ) {
			$plugin = \RAN\Plugin::from_wp_array(
				"$slug/$slug.php",
				array_fill_keys( array( 'Name', 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Title', 'AuthorName' ), '' ) + array( 'Network' => false )
			);
			$plugin->set_repository( new \RAN\ManagedRepository( 'gh', 'owner/' . $slug, 'repository-1', 'main' ) );
			$plugin->set_deployment_policy( 'disabled' === $slug ? \RAN\Deployment\DeploymentPolicy::DISABLED : \RAN\Deployment\DeploymentPolicy::MANUAL );
			if ( 'release' === $slug ) {
				$plugin->set_source( \RAN\PackageSource::RELEASE_ASSET, 1 );
			}
			$packages[] = $plugin;
		}
		$view = new \RAN\Admin\DeploymentAdminPresenter( activity_store: new \RAN\Activity\ActivityStore( new \RAN\Tests\Activity\ActivityConnection() ) );
		try {
			$index = PackagePagePresenter::plugin()->index(
				$packages,
				array(),
				array(
					'search'    => '',
					'provider'  => '',
					'source'    => '',
					'policy'    => '',
					'attention' => 'paused',
				),
				$view
			);
			self::assertFalse( $index['package_attention_enabled'] );
			self::assertSame( 'paused', $index['package_list_state']['attention'], 'Paused stays usable when Activity is unreadable.' );
			self::assertSame( array( 'paused/paused.php' ), $index['package_paused'] );
			self::assertSame( array( 'paused/paused.php' ), array_map( static fn ( \RAN\Package $package ): mixed => $package->get_identifier(), $index['packages'] ) );

			$edit = PackagePagePresenter::plugin()->edit( $packages[2], array(), null, 'branch' );
			self::assertTrue( $edit['package_source']['choices']['branch']['disabled'] );
			self::assertSame( 'Updates are paused by a site setting.', $edit['package_source']['choices']['branch']['description'] );
			self::assertSame( 'release_asset', $edit['package_source']['selected'], 'A paused branch cannot be selected.' );
			self::assertFalse( PackagePagePresenter::plugin()->edit( $packages[0], array(), null, 'branch' )['package_source']['choices']['branch']['disabled'], 'A branch package keeps its own card.' );
			self::assertTrue( PackagePagePresenter::plugin()->create( array(), false, false, 'branch' )['package_branch_paused'] );
			self::assertTrue( PackagePagePresenter::theme()->create( array(), false, false, 'branch' )['package_branch_paused'] );
			$GLOBALS['ran_booster_test_environment_type'] = 'local';
			self::assertFalse( PackagePagePresenter::plugin()->create( array(), false, false, 'branch' )['package_branch_paused'], 'A development site never warns.' );
		} finally {
			unset( $GLOBALS['ran_booster_test_environment_type'], $GLOBALS['ran_booster_general_preference_raw'], $GLOBALS['ran_booster_general_preference_cached_option'] );
			// The release read above fills the gate's request statics; later in-process tests start clean.
			( new \ReflectionProperty( \RAN\Deployment\PackageSourceGate::class, 'releases' ) )->setValue( null, null );
			( new \ReflectionProperty( \RAN\Deployment\PackageSourceGate::class, 'logged_unreadable' ) )->setValue( null, array() );
		}
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

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_index_lists_working_copy_packages_from_the_updater_target_folders(): void {
		$base          = sys_get_temp_dir() . '/ran-working-copy-presenter-' . bin2hex( random_bytes( 6 ) );
		$plugin_root   = $base . '/plugins';
		$theme_root    = $base . '/themes';
		$alt_theme_dir = $base . '/alt-themes';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Emulate the WordPress-owned path in a separate process.
		define( 'WP_PLUGIN_DIR', $plugin_root );
		$GLOBALS['ran_booster_presenter_theme_roots'] = array(
			''          => $theme_root,
			'bzr-theme' => $alt_theme_dir,
		);
		foreach ( array( $plugin_root . '/git-plugin/.git', $plugin_root . '/svn-plugin/.svn', $plugin_root . '/hello/.git', $plugin_root . '/nested/.git', $plugin_root . '/clean-plugin', $theme_root . '/hg-theme/.hg', $theme_root . '/group/child/.git', $alt_theme_dir . '/bzr-theme/.bzr', $theme_root . '/clean-theme' ) as $dir ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable test directory tree.
			mkdir( $dir, 0700, true );
		}
		$plugin = static function ( string $file ): \RAN\Plugin {
			$item = \RAN\Plugin::from_wp_array( $file, array_fill_keys( array( 'Name', 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Title', 'AuthorName' ), '' ) + array( 'Network' => false ) );
			$item->set_repository( new \RAN\ManagedRepository( 'gh', 'owner/repo', 'repository-1', 'main' ) );
			return $item;
		};
		$theme  = static function ( string $slug ): \RAN\Theme {
			$item = new \RAN\Theme();
			( new \ReflectionProperty( \RAN\Theme::class, 'stylesheet' ) )->setValue( $item, $slug );
			$item->set_repository( new \RAN\ManagedRepository( 'gh', 'owner/repo', 'repository-1', 'main' ) );
			return $item;
		};
		$state  = array(
			'search'    => '',
			'provider'  => '',
			'source'    => '',
			'policy'    => '',
			'attention' => '',
		);
		$view   = new \RAN\Admin\DeploymentAdminPresenter();
		try {
			$plugin_items = array( $plugin( 'git-plugin/git-plugin.php' ), $plugin( 'svn-plugin/svn-plugin.php' ), $plugin( 'hello.php' ), $plugin( 'nested/sub/plugin.php' ), $plugin( 'clean-plugin/clean-plugin.php' ) );
			$plugins      = PackagePagePresenter::plugin()->index( $plugin_items, array(), $state, $view );
			$filtered     = PackagePagePresenter::plugin()->index( $plugin_items, array(), array( 'search' => 'git-plugin' ) + $state, $view );
			$themes       = PackagePagePresenter::theme()->index( array( $theme( 'hg-theme' ), $theme( 'bzr-theme' ), $theme( 'group/child' ), $theme( 'clean-theme' ) ), array(), $state, $view );
			self::assertSame( array( 'git-plugin/git-plugin.php', 'svn-plugin/svn-plugin.php' ), $plugins['package_working_copies'] );
			self::assertSame( array( 'git-plugin/git-plugin.php' ), $filtered['package_working_copies'] );
			self::assertSame( array( 'hg-theme', 'bzr-theme' ), $themes['package_working_copies'] );
		} finally {
			unset( $GLOBALS['ran_booster_presenter_theme_roots'] );
			foreach ( array( $plugin_root . '/git-plugin/.git', $plugin_root . '/git-plugin', $plugin_root . '/svn-plugin/.svn', $plugin_root . '/svn-plugin', $plugin_root . '/hello/.git', $plugin_root . '/hello', $plugin_root . '/nested/.git', $plugin_root . '/nested', $plugin_root . '/clean-plugin', $plugin_root, $theme_root . '/hg-theme/.hg', $theme_root . '/hg-theme', $theme_root . '/group/child/.git', $theme_root . '/group/child', $theme_root . '/group', $theme_root . '/clean-theme', $theme_root, $alt_theme_dir . '/bzr-theme/.bzr', $alt_theme_dir . '/bzr-theme', $alt_theme_dir, $base ) as $dir ) {
				if ( is_dir( $dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable test directory cleanup.
					rmdir( $dir );
				}
			}
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The presenter's WordPress function double stays with its target-folder tests.
namespace RAN\Admin;

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- This bounded WordPress double supplies per-stylesheet theme roots for the presenter.
function get_theme_root( string $stylesheet = '' ): string {
	if ( isset( $GLOBALS['ran_booster_presenter_theme_roots'] ) && is_array( $GLOBALS['ran_booster_presenter_theme_roots'] ) ) {
		return (string) ( $GLOBALS['ran_booster_presenter_theme_roots'][ $stylesheet ] ?? ( $GLOBALS['ran_booster_presenter_theme_roots'][''] ?? '' ) );
	}

	return function_exists( 'get_theme_root' ) ? (string) ( new \ReflectionFunction( 'get_theme_root' ) )->invoke( $stylesheet ) : '';
}

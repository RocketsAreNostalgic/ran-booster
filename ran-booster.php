<?php

/**
 * Plugin Name: RAN Booster
 * Plugin URI: https://github.com/RocketsAreNostalgic/ran-booster
 * Description: Repository deployment management for WordPress themes and plugins.
 * x-release-please-start-version
 * Version: 1.0.0-beta.41
 * x-release-please-end
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Author: Rockets Are Nostalgic
 * Author URI: https://github.com/RocketsAreNostalgic
 * License: GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ran-booster
 * Domain Path: /languages
 * Update URI: https://github.com/RocketsAreNostalgic/ran-booster
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 15 );
} elseif ( 15 !== constant( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) ) {
	throw new LogicException( 'RAN Booster Provider API 15 conflicts with an existing API version marker.' );
}

if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );
} elseif ( 17 !== constant( 'RAN_BOOSTER_ADDON_API_VERSION' ) ) {
	throw new LogicException( 'RAN Booster Add-on API 17 conflicts with an existing API version marker.' );
}

if ( ! defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 );
} elseif ( 3 !== constant( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' ) ) {
	throw new LogicException( 'RAN Booster Admin Interaction API 3 conflicts with an existing API version marker.' );
}

require __DIR__ . '/autoload.php';

use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Dashboard;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\PackageArtifactLimit;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedMultisiteBootstrap;
use RAN\Storage\Database;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\BranchUpdaterBootstrap;
use RAN\WordPress\CoreSelfUpdateNativeTarget;
use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\WordPress\CoreSelfUpdateCheck;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use RAN\WordPress\ReleaseUpdaterBootstrap;
use RAN\WordPress\WordPressOrgUpdateRequestFilter;

if ( ! function_exists( 'ran_booster_table_name' ) ) {
	/** @return string */
	function ran_booster_table_name() {
		global $wpdb;
		$db_prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;

		return $db_prefix . 'ran_booster_packages';
	}
}

$ran_booster_version            = (string) ( get_file_data( __FILE__, array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' );
$ran_booster_self_update_policy = CoreSelfUpdatePolicy::detect( __FILE__, $ran_booster_version );
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'ran-booster', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

$ran_booster_runtime_support = RuntimeSupport::current();
if ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) ) {
	define( 'RAN_BOOSTER_RUNTIME_MODE', $ran_booster_runtime_support->value );
} elseif ( RAN_BOOSTER_RUNTIME_MODE !== $ran_booster_runtime_support->value ) {
	throw new LogicException( 'RAN Booster runtime mode conflicts with an existing runtime marker.' );
}

if ( ! $ran_booster_runtime_support->allows_managed_operations() ) {
	( new UnsupportedMultisiteBootstrap( __FILE__ ) )->register();

	return;
}
BranchUpdaterBootstrap::register();
$ran_booster_release_updater = ReleaseUpdaterBootstrap::register();
if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );
} elseif ( PortabilityFacade::API_VERSION !== constant( 'RAN_BOOSTER_PORTABILITY_API_VERSION' ) ) {
	throw new LogicException( 'RAN Booster Portability API 3 conflicts with an existing API version marker.' );
}
$ran_booster_core_development_notice = new CoreSelfUpdateDevelopmentNotice( $ran_booster_self_update_policy );
$ran_booster_core_development_notice->register();

( static function () use ( $ran_booster_core_development_notice, $ran_booster_self_update_policy, $ran_booster_release_updater ): void {
	$ran_booster_container             = new CoreContainer();
	$ran_booster_runtime               = new Booster( $ran_booster_container );
	$ran_booster_runtime->booster_path = plugin_dir_path( __FILE__ );
	$ran_booster_runtime->booster_url  = plugin_dir_url( __FILE__ );
	( new BoosterServiceProvider() )->register( $ran_booster_container, $ran_booster_runtime, $ran_booster_release_updater, plugin_basename( __FILE__ ) );
	$ran_booster_container->bind( CoreSelfUpdatePolicy::class, $ran_booster_self_update_policy );
	$ran_booster_container->bind( CoreSelfUpdateDevelopmentNotice::class, $ran_booster_core_development_notice );
	register_activation_hook( __FILE__, array( $ran_booster_runtime, 'activate' ) );
	register_deactivation_hook( __FILE__, array( $ran_booster_runtime, 'deactivate' ) );

	add_action(
		'plugins_loaded',
		static function () use ( $ran_booster_container, $ran_booster_runtime, $ran_booster_self_update_policy ): void {
			// All plugins have now had an opportunity to attach their provider
			// registration callback. No provider consumer is resolved before this
			// extension seam closes and the registry is sealed.
			$provider_registry = $ran_booster_container->make( ProviderRegistry::class );
			do_action( 'ran_booster_register_providers', $provider_registry );
			$ran_booster_container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
			$provider_registry->seal();

			$core_version        = (string) ( get_file_data( __FILE__, array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' );
			$core_channel        = str_contains( $core_version, '-' ) ? 'prerelease' : 'stable';
			$core_policy         = static fn (): CoreSelfUpdatePolicy => CoreSelfUpdatePolicy::detect( __FILE__, $core_version, true );
			$core_release_target = null;
			$core_check          = null;
			$current_policy      = $ran_booster_self_update_policy;
			try {
				$current_policy = $core_policy();
				$update_policy  = $current_policy->allows_native_discovery()
					? 'manual'
					: ( defined( CoreSelfUpdatePolicy::CONFIGURATION ) ? 'forced-off' : 'disabled' );
				$registrar      = $ran_booster_container->make( ManagedReleaseUpdaterRegistrar::class )->with_descriptor_admission(
					static fn (): bool => $core_policy()->allows_native_discovery()
				);
				if ( null === $registrar ) {
					throw new LogicException( 'The selected release updater cannot enforce Core self-update admission.' );
				}
				$core_updater            = $registrar->plugin(
					'github',
					__FILE__,
					'RocketsAreNostalgic/ran-booster',
					'1319710173',
					$core_channel,
					$update_policy,
					null,
					PackageArtifactLimit::resolve()
				);
					$core_release_target = new CoreSelfUpdateNativeTarget( $core_updater );
				if ( ! $core_release_target->register() ) {
					$core_release_target = null;
				} else {
					$ran_booster_container
						->make( ManagedReleaseTargetRegistrar::class )
						->reserve_core_self_update_target( plugin_basename( __FILE__ ) );
					$core_check = new CoreSelfUpdateCheck( $core_release_target, static fn (): bool => $core_policy()->allows_native_discovery(), plugin_basename( __FILE__ ), $core_channel );
					$ran_booster_container->bind( CoreSelfUpdateCheck::class, $core_check );
				}
			} catch ( Throwable $exception ) {
				\RAN\Logging\BoosterLogger::log_exception(
					'core self-update target registration unavailable',
					$exception,
					array( 'step' => 'core_self_update_target_registration' )
				);
			}
			if ( null === $core_release_target && function_exists( 'add_filter' ) ) {
				$core_identifier = plugin_basename( __FILE__ );
				$matches_core    = static function ( array $hook_extra ) use ( $core_identifier ): bool {
					if ( 'update' !== ( $hook_extra['action'] ?? null ) || 'plugin' !== ( $hook_extra['type'] ?? null ) ) {
						return false;
					}
					return ( $hook_extra['plugin'] ?? null ) === $core_identifier
						|| ( is_array( $hook_extra['plugins'] ?? null ) && in_array( $core_identifier, $hook_extra['plugins'], true ) );
				};
				$denied_update   = static fn (): \WP_Error => new \WP_Error(
					'ran_booster_native_update_core_registration_unavailable',
					__( 'The Core update is unavailable because its updater registration failed.', 'ran-booster' )
				);
				add_filter(
					'site_transient_update_plugins',
					static function ( mixed $transient ) use ( $core_identifier ): mixed {
						if ( is_object( $transient ) && is_array( $transient->response ?? null ) ) {
							unset( $transient->response[ $core_identifier ] );
						}
						return $transient;
					},
					PHP_INT_MAX
				);
				add_filter(
					'upgrader_pre_download',
					static function ( mixed $reply, string $package, object $upgrader, array $hook_extra ) use ( $matches_core, $denied_update ): mixed {
						unset( $package, $upgrader );
						return $matches_core( $hook_extra ) ? $denied_update() : $reply;
					},
					PHP_INT_MIN,
					4
				);
				add_filter(
					'upgrader_pre_install',
					static function ( mixed $reply, array $hook_extra ) use ( $matches_core, $denied_update ): mixed {
						return $matches_core( $hook_extra ) ? $denied_update() : $reply;
					},
					PHP_INT_MIN,
					2
				);
			}
			$ran_booster_container->bind(
				CoreSelfUpdateStatus::class,
				new CoreSelfUpdateStatus( $current_policy, $core_release_target, $core_check )
			);

			// Release targets join the package broker after every provider is
			// available and after the request-local updater selects its runtime.
			try {
				$ran_booster_container->make( ManagedReleaseTargetRegistrar::class )->register();
			} catch ( Throwable $exception ) {
				\RAN\Logging\BoosterLogger::log_exception(
					'managed release target registration unavailable',
					$exception,
					array( 'step' => 'managed_release_target_registration' )
				);
			}
			$ran_booster_runtime->register_extension_updates();

			$portability       = $ran_booster_container->make( PortabilityFacade::class );
			$admin_interaction = $ran_booster_container->make( AdminInteractionFacade::class );
			add_action(
				'plugins_loaded',
				static function () use ( $ran_booster_container ): void {
					// Provider release controls remain available even when native updater activation fails.
					$ran_booster_container->make( ReleaseWorkflowControls::class )->register();
					$ran_booster_container->make( ReleaseManagementControls::class )->register();
				},
				PHP_INT_MAX
			);
			$ran_booster_container->make( RepositoryWebhookManagementControls::class )->register();
			$add_on_registry = new AdminAddOnRegistry(
				array(),
				RAN_BOOSTER_ADDON_API_VERSION,
				RAN_BOOSTER_ADDON_API_VERSION
			);
			do_action( 'ran_booster_register_admin_tabs', $add_on_registry );
			$add_on_registry->seal();
			$ran_booster_container->bind( AdminAddOnRegistry::class, $add_on_registry );

			try {
				do_action( 'ran_booster_admin_interaction_ready', $admin_interaction );
			} catch ( Throwable $failure ) {
				\RAN\Logging\BoosterLogger::log_exception(
					'add-on service listener failed',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'add_on_service_ready',
						'event'  => 'ran_booster_admin_interaction_ready',
					)
				);
			}

			try {
				do_action( 'ran_booster_portability_ready', $portability );
			} catch ( Throwable $failure ) {
				\RAN\Logging\BoosterLogger::log_exception(
					'add-on service listener failed',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'add_on_service_ready',
						'event'  => 'ran_booster_portability_ready',
					)
				);
			}

			$ran_booster_container->bind( Dashboard::class, $ran_booster_container->make( Dashboard::class ) );
			$ran_booster_runtime->init();
		},
		100
	);

	$ran_booster_update_request_filter = new WordPressOrgUpdateRequestFilter(
		$ran_booster_container->make( Database::class ),
		$ran_booster_container->make( 'RAN\Storage\PluginRepository' ),
		$ran_booster_container->make( 'RAN\Storage\ThemeRepository' ),
		plugin_basename( __FILE__ )
	);
	add_filter( 'http_request_args', array( $ran_booster_update_request_filter, 'plugins' ), 5, 2 );
	add_filter( 'http_request_args', array( $ran_booster_update_request_filter, 'themes' ), 5, 2 );
} )();

<?php

/**
 * Plugin Name: RAN Booster
 * Plugin URI: https://github.com/RocketsAreNostalgic/ran-booster
 * Description: Repository deployment management for WordPress themes and plugins.
 * x-release-please-start-version
 * Version: 1.0.0-beta.4
 * x-release-please-end
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Author: Rockets Are Nostalgic
 * Author URI: https://github.com/RocketsAreNostalgic
 * License: GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ran-booster
 * Update URI: https://github.com/RocketsAreNostalgic/ran-booster
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 8 );
} elseif ( 8 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
	throw new LogicException( 'RAN Booster Provider API 8 conflicts with an existing API version marker.' );
}

if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 14 );
} elseif ( 14 !== RAN_BOOSTER_ADDON_API_VERSION ) {
	throw new LogicException( 'RAN Booster Add-on API 14 conflicts with an existing API version marker.' );
}

if ( ! defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
} elseif ( 2 !== RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION ) {
	throw new LogicException( 'RAN Booster Admin Interaction API 2 conflicts with an existing API version marker.' );
}

require __DIR__ . '/autoload.php';

use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\Admin\GitHubReleaseUpdateNotice;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Dashboard;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedMultisiteBootstrap;
use RAN\Storage\Database;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\WordPress\GitHubReleaseUpdaterBootstrap;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressOrgUpdateRequestFilter;

if ( ! function_exists( 'ran_booster_table_name' ) ) {
	function ran_booster_table_name() {
		global $wpdb;
		$dbPrefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;

		return $dbPrefix . 'ran_booster_packages';
	}
}

$ran_booster_version                    = (string) ( get_file_data( __FILE__, array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' );
$ran_booster_self_update_policy         = CoreSelfUpdatePolicy::detect( __FILE__, $ran_booster_version );
$GLOBALS['ran_booster_release_updater'] = GitHubReleaseUpdaterBootstrap::register(
	pluginFile: __FILE__,
	pluginVersion: $ran_booster_version,
	nativeDiscovery: $ran_booster_self_update_policy->allowsNativeDiscovery()
);
GitHubReleaseUpdateNotice::register();

$ran_booster_runtime_support = RuntimeSupport::current();
if ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) ) {
	define( 'RAN_BOOSTER_RUNTIME_MODE', $ran_booster_runtime_support->value );
} elseif ( $ran_booster_runtime_support->value !== RAN_BOOSTER_RUNTIME_MODE ) {
	throw new LogicException( 'RAN Booster runtime mode conflicts with an existing runtime marker.' );
}

if ( ! $ran_booster_runtime_support->allowsManagedOperations() ) {
	( new UnsupportedMultisiteBootstrap( __FILE__ ) )->register();

	return;
}
if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' ) ) {
	define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );
} elseif ( PortabilityFacade::API_VERSION !== RAN_BOOSTER_PORTABILITY_API_VERSION ) {
	throw new LogicException( 'RAN Booster Portability API 2 conflicts with an existing API version marker.' );
}
( new CoreSelfUpdateDevelopmentNotice( $ran_booster_self_update_policy ) )->register();

$ran_booster_updater_prospective_api_version = null;
add_action(
	'plugins_loaded',
	static function () use ( &$ran_booster_updater_prospective_api_version ): void {
		$updater                                     = $GLOBALS['ran_booster_release_updater'] ?? null;
		$ran_booster_updater_prospective_api_version = is_object( $updater )
			? GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $updater )
			: null;
		if ( GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION
			=== $ran_booster_updater_prospective_api_version
			&& defined( 'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION' ) ) {
			if ( ProspectiveReleaseFacade::API_VERSION
				!== RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION ) {
				\RAN\Logging\BoosterLogger::log(
					'prospective release API marker conflict',
					array( 'step' => 'prospective_release_api_negotiation' )
				);
			}
		} elseif ( GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION
			=== $ran_booster_updater_prospective_api_version ) {
			define(
				'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION',
				ProspectiveReleaseFacade::API_VERSION
			);
		}
	},
	PHP_INT_MIN + 1
);

( static function () use ( $ran_booster_self_update_policy, &$ran_booster_updater_prospective_api_version ): void {
	$ran_booster_container            = new CoreContainer();
	$ran_booster_runtime              = new Booster( $ran_booster_container );
	$ran_booster_runtime->boosterPath = plugin_dir_path( __FILE__ );
	$ran_booster_runtime->boosterUrl  = plugin_dir_url( __FILE__ );
	( new BoosterServiceProvider() )->register( $ran_booster_container, $ran_booster_runtime );
	$ran_booster_container->bind( CoreSelfUpdatePolicy::class, $ran_booster_self_update_policy );
	$ran_booster_container->bind(
		CoreSelfUpdateStatus::class,
		new CoreSelfUpdateStatus(
			$ran_booster_self_update_policy,
			$GLOBALS['ran_booster_release_updater']
		)
	);

	// Release targets must join the package broker before its earliest
	// plugins_loaded callback fixes the request-local runtime selection.
	try {
		$ran_booster_container->make( ManagedReleaseTargetRegistrar::class )->register();
	} catch ( Throwable $exception ) {
		\RAN\Logging\BoosterLogger::logException(
			'managed release target registration unavailable',
			$exception,
			array( 'step' => 'managed_release_target_registration' )
		);
	}

	register_activation_hook( __FILE__, array( $ran_booster_runtime, 'activate' ) );
	register_deactivation_hook( __FILE__, array( $ran_booster_runtime, 'deactivate' ) );

	add_action(
		'plugins_loaded',
		static function () use ( $ran_booster_container, $ran_booster_runtime, &$ran_booster_updater_prospective_api_version ): void {
			// All plugins have now had an opportunity to attach their provider
			// registration callback. No provider consumer is resolved before this
			// extension seam closes and the registry is sealed.
			$providerRegistry = $ran_booster_container->make( ProviderRegistry::class );
			do_action( 'ran_booster_register_providers', $providerRegistry );
			$providerRegistry->seal();
			$webhookAssistance = $ran_booster_container->make( WebhookAssistanceFacade::class );
			$releaseTracking   = $ran_booster_container->make( ReleaseTrackingFacade::class );
			$portability       = $ran_booster_container->make( PortabilityFacade::class );
			$adminInteraction  = $ran_booster_container->make( AdminInteractionFacade::class );
			$addOnRegistry     = new AdminAddOnRegistry(
				array(
					'webhook_assistance' => $webhookAssistance,
					'release_tracking'   => $releaseTracking,
				),
				RAN_BOOSTER_ADDON_API_VERSION,
				RAN_BOOSTER_ADDON_API_VERSION
			);
			do_action( 'ran_booster_register_admin_tabs', $addOnRegistry );
			$addOnRegistry->seal();
			$ran_booster_container->bind( AdminAddOnRegistry::class, $addOnRegistry );

			try {
				do_action( 'ran_booster_admin_interaction_ready', $adminInteraction );
			} catch ( Throwable $failure ) {
				\RAN\Logging\BoosterLogger::logException(
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
				\RAN\Logging\BoosterLogger::logException(
					'add-on service listener failed',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'add_on_service_ready',
						'event'  => 'ran_booster_portability_ready',
					)
				);
			}

			try {
				do_action( 'ran_booster_webhook_assistance_ready', $webhookAssistance );
			} catch ( Throwable $failure ) {
				\RAN\Logging\BoosterLogger::logException(
					'add-on service listener failed',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'add_on_service_ready',
						'event'  => 'ran_booster_webhook_assistance_ready',
					)
				);
			}

			try {
				do_action( 'ran_booster_release_tracking_ready', $releaseTracking );
			} catch ( Throwable $failure ) {
				\RAN\Logging\BoosterLogger::logException(
					'add-on service listener failed',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'add_on_service_ready',
						'event'  => 'ran_booster_release_tracking_ready',
					)
				);
			}

			if ( GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION
				=== $ran_booster_updater_prospective_api_version
				&& defined( 'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION' )
				&& ProspectiveReleaseFacade::API_VERSION
				=== RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION ) {
				try {
					$prospectiveRelease = $ran_booster_container->make( ProspectiveReleaseFacade::class );
					do_action( 'ran_booster_prospective_release_ready', $prospectiveRelease );
				} catch ( Throwable $failure ) {
					\RAN\Logging\BoosterLogger::logException(
						'add-on service listener failed',
						$failure,
						array(
							'source' => 'admin',
							'step'   => 'add_on_service_ready',
							'event'  => 'ran_booster_prospective_release_ready',
						)
					);
				}
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

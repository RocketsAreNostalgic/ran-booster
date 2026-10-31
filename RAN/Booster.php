<?php

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use RAN\Deployment\DeploymentWorker;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Internal\CoreContainer;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Portability\WpPusherCoexistencePolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

class Booster {
	private const NATIVE_UPDATE_WARNING_HEARTBEAT_KEY = 'ran_booster_native_update_warning';

	private CoreContainer $container;

	private const ADMIN_PAGE_HOOKS = array(
		'toplevel_page_ran-booster',
		'ran-booster_page_ran-booster-transporter',
		'ran-booster_page_ran-booster-plugins-create',
		'ran-booster_page_ran-booster-plugins',
		'ran-booster_page_ran-booster-themes-create',
		'ran-booster_page_ran-booster-themes',
		'ran-booster_page_ran-booster-extensions',
		'ran-booster_page_ran-booster-settings',
	);

	private const PACKAGE_PAGE_HOOKS = array(
		'ran-booster_page_ran-booster-plugins-create',
		'ran-booster_page_ran-booster-plugins',
		'ran-booster_page_ran-booster-themes-create',
		'ran-booster_page_ran-booster-themes',
	);

	private const PACKAGE_INDEX_PAGE_HOOKS = array(
		'ran-booster_page_ran-booster-plugins',
		'ran-booster_page_ran-booster-themes',
	);

	private const ADMIN_STYLE_COMPONENTS = array(
		'00-foundations.css',
		'10-buttons.css',
		'15-enhanced-mutations.css',
		'20-repository-picker.css',
		'25-admin-primitives.css',
		'30-provider-cards.css',
		'35-status-utilities.css',
		'40-tables-and-pills.css',
		'50-troubleshooting-and-activity.css',
		'55-extensions.css',
		'60-packages.css',
		'65-package-settings.css',
		'70-credential-dialog.css',
		'80-responsive.css',
	);


	/** @var string|null Bootstrap assigns the plugin location before runtime use. */
	public $booster_path;


	/** @var string|null Bootstrap assigns the plugin location before runtime use. */
	public $booster_url;

	/** @internal Core constructs the live runtime with its request-local container. */
	public function __construct( ?CoreContainer $container = null ) {
		$this->container = $container ?? new CoreContainer();
	}

	/** @return void */
	public function init() {
		add_action( 'admin_init', $this->service_callback( \RAN\Admin\CredentialSelfDestructPurger::class, 'purge' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'wp_scheduled_delete', array( $this, 'prune_activity' ) );
		add_action( 'admin_init', array( $this, 'register_plugin_action_links' ) );
		add_action( 'admin_init', $this->service_callback( 'RAN\Dispatcher', 'dispatch_post_requests' ) );
		add_action(
			'wp_ajax_' . \RAN\Admin\RepositoryPickerController::AJAX_ACTION,
			$this->service_callback( 'RAN\Admin\RepositoryPickerController', 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\DevelopmentSafetyNoticeController::AJAX_ACTION,
			$this->service_callback( \RAN\Admin\DevelopmentSafetyNoticeController::class, 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\CredentialExpiryNoticeController::AJAX_ACTION,
			$this->service_callback( \RAN\Admin\CredentialExpiryNoticeController::class, 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\DeploymentAdminController::AJAX_ACTION,
			$this->service_callback( \RAN\Admin\DeploymentAdminController::class, 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PackageUpdateProgressController::AJAX_ACTION,
			$this->service_callback( \RAN\Admin\PackageUpdateProgressController::class, 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::EXPORT_ACTION,
			$this->service_callback( \RAN\Admin\PortabilityController::class, 'handle_export' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::PREVIEW_ACTION,
			$this->service_callback( \RAN\Admin\PortabilityController::class, 'handle_preview' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::APPLY_ACTION,
			$this->service_callback( \RAN\Admin\PortabilityController::class, 'handle_apply' )
		);
		add_action( 'admin_post_ran_booster_extension_install', array( $this, 'install_extension' ) );
		add_action( 'admin_post_ran_booster_extension_activate', array( $this, 'activate_extension' ) );
		add_action( 'activate_plugin', array( WpPusherCoexistencePolicy::class, 'block_wp_pusher_activation' ) );
		add_action( 'rest_api_init', array( $this, 'register_webhook_routes' ) );
		add_action( WordPressWorkerWakeup::HOOK, array( $this, 'run_deployment_worker' ), 10, 0 );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'admin_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		}

		// Add styles and scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_credential_expiry_notice_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_background_deployment_failure_notice_script' ) );
		add_action(
			'admin_enqueue_scripts',
			function ( $hook ): void {
				$this->load_native_update_warning_script( $hook );
			}
		);
		$expiry_renderer = $this->service_callback( \RAN\Admin\CredentialExpiryNotice::class, 'render' );
		$expiry_notice   = static function () use ( $expiry_renderer ): void {
			\RAN\Admin\BoosterNoticeScope::render( $expiry_renderer );
		};
		add_action( 'admin_notices', $expiry_notice );
		add_action( 'network_admin_notices', $expiry_notice );
		$failure_renderer = $this->service_callback( \RAN\Admin\DeploymentAdminPresenter::class, 'render' );
		$failure_notice   = static function () use ( $failure_renderer ): void {
			\RAN\Admin\BoosterNoticeScope::render( $failure_renderer );
		};
		add_action( 'admin_notices', $failure_notice );
		add_action( 'network_admin_notices', $failure_notice );
		$runtime_renderer = $this->service_callback( \RAN\Admin\SecretsRuntimeAvailabilityNotice::class, 'render' );
		$runtime_notice   = static function () use ( $runtime_renderer ): void {
			\RAN\Admin\BoosterNoticeScope::render( $runtime_renderer );
		};
		add_action( 'admin_notices', $runtime_notice );
		add_action( 'network_admin_notices', $runtime_notice );
		$database_renderer = $this->service_callback( \RAN\Admin\DatabaseCompatibilityNotice::class, 'render' );
		$database_notice   = static function () use ( $database_renderer ): void {
			\RAN\Admin\BoosterNoticeScope::render( $database_renderer );
		};
		add_action( 'admin_notices', $database_notice );
		add_action( 'network_admin_notices', $database_notice );
		$native_update_warning = function (): void {
			\RAN\Admin\BoosterNoticeScope::render( fn () => $this->render_native_update_warning() );
		};
		add_action( 'admin_notices', $native_update_warning );
		add_action( 'network_admin_notices', $native_update_warning );
		add_filter(
			'heartbeat_received',
			function ( $response, $data ) {
				return $this->native_update_warning_heartbeat( $response, $data );
			},
			10,
			2
		);
		add_action( 'load-plugins.php', $this->service_callback( \RAN\Admin\ManagedPluginFailureRows::class, 'register' ) );
	}

	/** @return null */
	public function activate() {
		if ( ! $this->sodium_available() ) {
			return wp_die(
				esc_html__(
					'RAN Booster requires the PHP Sodium extension for encrypted credential storage. Ask your hosting provider to enable Sodium, then activate the plugin again.',
					'ran-booster'
				)
			);
		}
		if ( $this->is_multisite_installation() ) {
			return wp_die(
				esc_html__(
					'RAN Booster encrypted credential storage is not available on multisite in this Beta release. Use a single-site WordPress installation.',
					'ran-booster'
				)
			);
		}
		try {
			WpPusherCoexistencePolicy::assert_package_mutation_allowed();
		} catch ( \RuntimeException $failure ) {
			return wp_die( esc_html( $failure->getMessage() ) );
		}

		try {
			$this->service( 'RAN\Storage\Database' )->install();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure $exception ) {
			BoosterLogger::log_exception( 'plugin activation database unsupported', $exception, array( 'step' => 'plugin_activation' ) );
			return wp_die( esc_html( $exception->getMessage() ) );
		} catch ( \Throwable $exception ) {
			BoosterLogger::log_exception( 'plugin activation failed', $exception, array( 'step' => 'plugin_activation' ) );
			return wp_die(
				esc_html__(
					'RAN Booster could not complete its database setup, so WordPress left the plugin inactive. Confirm that WordPress can create and update plugin tables, then try again or contact your hosting provider.',
					'ran-booster'
				)
			);
		}

		$this->upgrade_activity();
		$this->service( WordPressWorkerWakeup::class )->request();

		return null;
	}

	protected function sodium_available(): bool {
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
	}

	protected function is_multisite_installation(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	public function deactivate(): void {
		try {
			$this->service( TemporaryDebugCapture::class )->stop();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Deactivation must continue when the optional capture is unavailable.
		} catch ( \Throwable ) {
			// Deactivation must continue when an optional capture is unavailable.
		}

		$this->service( WordPressWorkerWakeup::class )->clear();
	}

	public function run_deployment_worker(): void {
		try {
			$this->service( 'RAN\Storage\Database' )->maybe_upgrade();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An active incompatible site must remain bootable without running the worker.
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}
		$this->service( DeploymentWorker::class )->run_once();
	}

	public function register_webhook_routes(): void {
		$this->service( 'RAN\Webhook\WebhookController' )->register_routes();
	}

	/** @return void */
	public function admin_menu() {
		add_menu_page( $this->get_name(), $this->get_name(), 'manage_options', 'ran-booster', '', $this->get_menu_icon() );
		add_submenu_page( 'ran-booster', $this->get_name(), __( 'Overview', 'ran-booster' ), 'manage_options', 'ran-booster', $this->service_callback( 'RAN\Dashboard', 'get_index' ) );
		add_submenu_page( 'ran-booster', __( 'Install Plugin', 'ran-booster' ), __( 'Install Plugin', 'ran-booster' ), 'manage_options', 'ran-booster-plugins-create', $this->service_callback( 'RAN\Dashboard', 'get_plugins_create' ) );
		add_submenu_page( 'ran-booster', __( 'Managed Plugins', 'ran-booster' ), __( 'Plugins', 'ran-booster' ), 'manage_options', 'ran-booster-plugins', $this->service_callback( 'RAN\Dashboard', 'get_plugins' ) );
		add_submenu_page( 'ran-booster', __( 'Install Theme', 'ran-booster' ), __( 'Install Theme', 'ran-booster' ), 'manage_options', 'ran-booster-themes-create', $this->service_callback( 'RAN\Dashboard', 'get_themes_create' ) );
		add_submenu_page( 'ran-booster', __( 'Managed Themes', 'ran-booster' ), __( 'Themes', 'ran-booster' ), 'manage_options', 'ran-booster-themes', $this->service_callback( 'RAN\Dashboard', 'get_themes' ) );
		add_submenu_page( 'ran-booster', __( 'Transporter', 'ran-booster' ), __( 'Transporter', 'ran-booster' ), 'manage_options', 'ran-booster-transporter', $this->service_callback( 'RAN\Dashboard', 'get_transporter' ) );
		add_submenu_page( 'ran-booster', __( 'Extensions', 'ran-booster' ), __( 'Extensions', 'ran-booster' ), 'manage_options', 'ran-booster-extensions', array( $this, 'render_extensions_page' ) );
		add_submenu_page( 'ran-booster', __( 'Settings', 'ran-booster' ), __( 'Settings', 'ran-booster' ), 'manage_options', 'ran-booster-settings', $this->service_callback( 'RAN\Dashboard', 'get_settings' ) );
	}

	/**
	 * Render the fixed, release-bundled Extensions catalogue.
	 */
	public function render_extensions_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		try {
			if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) && ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed_plugins = get_plugins();
			$extensions        = $this->extension_cards( is_array( $installed_plugins ) ? $installed_plugins : array() );
			$plugins_url       = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
			$this->service( 'RAN\Dashboard' )->get_extensions( $extensions, $plugins_url );
		} catch ( \Throwable $failure ) {
			BoosterLogger::log_exception(
				'Extensions page unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'extensions_page',
				)
			);
			?>
			<div class="wrap ran-booster-admin">
				<h1><?php esc_html_e( 'RAN Booster Extensions', 'ran-booster' ); ?></h1>
				<div class="notice notice-error"><p><?php esc_html_e( 'Extensions are temporarily unavailable. Reload the page or check the Booster installation.', 'ran-booster' ); ?></p></div>
			</div>
			<?php
		}
	}

	/** Install only a selected, fixed-catalogue extension. */
	public function install_extension(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You cannot install plugins.', 'ran-booster' ) );
		}
		$id = isset( $_POST['extension_id'] ) && is_string( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		check_admin_referer( 'ran_booster_extension_install_' . $id );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installer = new \RAN\Admin\ExtensionInstaller(
			$this->service( \RAN\WordPress\WordPressUpdaterLock::class ),
			$this->service( \RAN\WordPress\CorePackageExecutor::class ),
			fn(): array => $this->extension_cards( get_plugins() )
		);
		$result    = $installer->install( $id );
		$url       = add_query_arg(
			array(
				'page'                         => 'ran-booster-extensions',
				'ran_booster_extension_id'     => $id,
				'ran_booster_extension_result' => $result,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}


	/** Activate only an installed catalogue extension using WordPress's native checks. */
	public function activate_extension(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot activate plugins.', 'ran-booster' ) );
		}
		$id = isset( $_POST['extension_id'] ) && is_string( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$extension = null;
		foreach ( $this->extension_cards( get_plugins() ) as $candidate ) {
			if ( $candidate['id'] === $id ) {
				$extension = $candidate;
				break;
			}
		}
		if ( null === $extension || ! current_user_can( 'activate_plugin', $extension['plugin'] ) ) {
			wp_die( esc_html__( 'You cannot activate this plugin.', 'ran-booster' ) );
		}
		check_admin_referer( 'activate-plugin_' . $extension['plugin'] );
		$url = add_query_arg(
			array(
				'page'                         => 'ran-booster-extensions',
				'ran_booster_extension_id'     => $id,
				'ran_booster_extension_result' => 'activation_failed',
			),
			admin_url( 'admin.php' )
		);
		if ( $extension['active'] ) {
			$url = add_query_arg( 'ran_booster_extension_result', 'activated', $url );
		} elseif ( 'Installed, inactive' === $extension['state'] && ! ( is_multisite() && is_network_only_plugin( $extension['plugin'] ) ) ) {
			$result = activate_plugin( $extension['plugin'], $url, false );
			if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'plugin_php_incompatible', 'plugin_wp_incompatible', 'plugin_wp_php_incompatible', 'plugin_missing_dependencies' ), true ) ) {
				$url = add_query_arg( 'ran_booster_extension_result', $result->get_error_code(), $url );
			} elseif ( ! is_wp_error( $result ) && is_plugin_active( $extension['plugin'] ) ) {
				$url = add_query_arg( 'ran_booster_extension_result', 'activated', $url );
			}
		}
		wp_safe_redirect( $url );
		exit;
	}

	/** Register native updates for installed fixed-catalogue extensions. */
	public function register_extension_updates(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		( new \RAN\WordPress\ExtensionUpdates(
			$this->service( \RAN\WordPress\ManagedReleaseUpdaterRegistrar::class ),
			function ( string $plugin ): bool {
				try {
					return null !== $this->service( 'RAN\\Storage\\PluginRepository' )->native_recovery_record( $plugin );
				} catch ( \Throwable ) {
					return true;
				}
			},
			fn (): array => $this->extension_cards( get_plugins() )
		) )->register();
	}

	/** @param array<string, array<string, mixed>> $installed_plugins
	 *  @return list<array<string, mixed>>
	 */
	private function extension_cards( array $installed_plugins ): array {
		$catalogue = array(
			array(
				'id'                   => 'ran-booster-bitbucket',
				'name'                 => 'Bitbucket Cloud',
				'description'          => __( 'Connect Booster to Bitbucket Cloud repositories for managed deployments.', 'ran-booster' ),
				'details'              => __( 'Add Bitbucket Cloud as a first-party Git provider while Booster continues to own credentials, webhook verification, and deployment policy.', 'ran-booster' ),
				'features'             => array(
					__( 'Connect and configure Bitbucket Cloud repositories in Booster.', 'ran-booster' ),
					__( 'Use package and credential guidance for your Git provider.', 'ran-booster' ),
					__( 'Carry eligible file-stored credentials through Transporter for explicit import on the target site.', 'ran-booster' ),
				),
				'requirements'         => array(
					__( 'WordPress 7.0 or later and PHP 8.2 or later.', 'ran-booster' ),
					__( 'A version of Booster compatible with this extension.', 'ran-booster' ),
					__( 'Manual Bitbucket webhook setup for Push-to-Deploy.', 'ran-booster' ),
				),
				'plugin'               => 'ran-booster-bitbucket/ran-booster-bitbucket.php',
				'image'                => 'bitbucket-cloud.svg',
				'availability'         => __( 'Free', 'ran-booster' ),
				'required_apis'        => array(
					'RAN_BOOSTER_PROVIDER_API_VERSION' => 15,
					'RAN_BOOSTER_ADDON_API_VERSION'    => 17,
				),
				'release_version'      => '0.1.0-beta.17',
				'manual_install_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/releases/tag/v0.1.0-beta.17',
				'asset_url'            => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/releases/download/v0.1.0-beta.17/ran-booster-bitbucket-0.1.0-beta.17.zip',
				'asset_sha256'         => 'b7963f335c624cf08124040a24a9d51d80c26640faee46e75334783f0547bc5a',
				'repository'           => 'RocketsAreNostalgic/ran-booster-bitbucket',
				'repository_id'        => '1312284489',
				'canonical_update_uri' => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
				'docs_url'             => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme',
				'support_url'          => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues',
			),
			array(
				'id'                   => 'ran-booster-wp-pusher-migrator',
				'name'                 => 'WP Pusher Migrator',
				'description'          => __( 'Move existing WP Pusher-managed plugins and themes into Booster without reinstalling them.', 'ran-booster' ),
				'details'              => __( 'Review and adopt supported packages from an inactive WP Pusher installation. Adopted packages start with deployments disabled, so enabling deployment remains an explicit decision.', 'ran-booster' ),
				'features'             => array(
					__( 'Review retained WP Pusher package records before migration.', 'ran-booster' ),
					__( 'Adopt supported GitHub and Bitbucket Cloud packages through Booster Transporter.', 'ran-booster' ),
					__( 'Migrator removes each WP Pusher entry only after Booster verifies the adopted package with deployments disabled.', 'ran-booster' ),
				),
				'requirements'         => array(
					__( 'WordPress 7.0 or later, PHP 8.2 or later, and a compatible version of Booster.', 'ran-booster' ),
					__( 'A single site with a supported WP Pusher installation that is inactive.', 'ran-booster' ),
					__( 'Existing Booster credentials for private repositories; GitLab packages are not supported.', 'ran-booster' ),
				),
				'plugin'               => 'ran-booster-wp-pusher-migrator/ran-booster-wp-pusher-migrator.php',
				'image'                => 'wp-pusher-migrator.svg',
				'availability'         => __( 'Free', 'ran-booster' ),
				'required_apis'        => array(
					'RAN_BOOSTER_PORTABILITY_API_VERSION' => 4,
					'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 3,
				),
				'release_version'      => '0.1.0-beta.11',
				'manual_install_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/releases/tag/v0.1.0-beta.11',
				'asset_url'            => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/releases/download/v0.1.0-beta.11/ran-booster-wp-pusher-migrator-0.1.0-beta.11.zip',
				'asset_sha256'         => 'ff5c581b14ea12b0ee45880c6d3e7b43785be84e39e6193e908fd2573d822d9d',
				'repository'           => 'RocketsAreNostalgic/ran-booster-wp-pusher-migrator',
				'repository_id'        => '1316639618',
				'canonical_update_uri' => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator',
				'docs_url'             => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator#readme',
				'support_url'          => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/issues',
			),
		);

		foreach ( $catalogue as &$extension ) {
			$installed                = array_key_exists( $extension['plugin'], $installed_plugins );
			$installed_version        = $installed && is_string( $installed_plugins[ $extension['plugin'] ]['Version'] ?? null ) ? $installed_plugins[ $extension['plugin'] ]['Version'] : '';
			$release_version          = $extension['release_version'];
			$network_active_available = function_exists( __NAMESPACE__ . '\\is_plugin_active_for_network' ) || function_exists( 'is_plugin_active_for_network' );
			$active                   = $installed && ( is_plugin_active( $extension['plugin'] ) || ( $network_active_available && is_plugin_active_for_network( $extension['plugin'] ) ) );
			$compatible               = true;
			foreach ( $extension['required_apis'] as $marker => $version ) {
				if ( ! defined( $marker ) || constant( $marker ) !== $version ) {
					$compatible = false;
					break;
				}
			}

			$installed_release_status = ! $installed || $release_version === $installed_version
				? 'selected'
				: ( '' === $installed_version ? 'unknown' : ( version_compare( $installed_version, $release_version, '<' ) ? 'older' : 'newer' ) );

			$extension['installed_name']                = $installed && is_string( $installed_plugins[ $extension['plugin'] ]['Name'] ?? null ) ? $installed_plugins[ $extension['plugin'] ]['Name'] : $extension['name'];
			$extension['installed']                     = $installed;
			$extension['active']                        = $active;
			$extension['image_url']                     = trailingslashit( $this->booster_url ?? '' ) . 'assets/extensions/' . $extension['image'];
			$extension['compatible']                    = $compatible;
			$extension['installed_version']             = $installed_version;
			$extension['installed_release_status']      = $installed_release_status;
			$extension['selected_release_is_installed'] = 'selected' === $installed_release_status;
			if ( ! $compatible ) {
				$extension['compatibility_label'] = sprintf(
					/* translators: %s: Selected extension release version. */
					__( 'The selected version %s release requires a different version of Booster. Review its documentation before installing or updating.', 'ran-booster' ),
					$release_version
				);
			} elseif ( 'older' === $installed_release_status ) {
				$extension['compatibility_label'] = sprintf(
					/* translators: 1: Selected extension release version, 2: Installed extension version. */
					__( 'The selected version %1$s release is compatible with your version of Booster. Your installed version %2$s is older; update it in Plugins.', 'ran-booster' ),
					$release_version,
					$installed_version
				);
			} elseif ( 'newer' === $installed_release_status ) {
				$extension['compatibility_label'] = sprintf(
					/* translators: 1: Selected extension release version, 2: Installed extension version. */
					__( 'The selected version %1$s release is compatible with your version of Booster. Your installed version %2$s is newer and has not been qualified for this catalogue. Review it in Plugins.', 'ran-booster' ),
					$release_version,
					$installed_version
				);
			} elseif ( 'unknown' === $installed_release_status ) {
				$extension['compatibility_label'] = sprintf(
					/* translators: %s: Selected extension release version. */
					__( 'The selected version %s release is compatible with your version of Booster. The installed extension version could not be verified; review it in Plugins before updating.', 'ran-booster' ),
					$release_version
				);
			} else {
				$extension['compatibility_label'] = __( 'Compatible with your version of Booster', 'ran-booster' );
			}
			$extension['state']      = ! $installed
				? 'Not installed'
				: ( ! $compatible ? 'Incompatible' : ( $active ? 'Active' : 'Installed, inactive' ) );
			$extension['state_kind'] = $installed && ! $compatible ? 'error' : ( $active ? 'ok' : 'neutral' );
		}
		unset( $extension );

		return $catalogue;
	}

	/** @return string */
	public function get_name() {
		return 'RAN Booster';
	}

	/**
	 * A monochrome rocket silhouette for the admin menu icon. WordPress renders
	 * custom SVG menu icons at reduced opacity rather than recoloring them, so a
	 * solid black fill matches the muted look of the other Dashicons.
	 */
	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">'
			. '<path d="M10,2 C11.5,2 13,5 13,8 L13,15 L7,15 L7,8 C7,5 8.5,2 10,2 Z '
			. 'M7,12 L4,17 L7,15 Z M13,12 L16,17 L13,15 Z M8,15 L10,18.5 L12,15 Z" /></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is required for this SVG data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/** @return void */
	public function register_plugin_action_links() {
		if ( current_user_can( 'manage_options' ) && ! is_multisite() ) {
			$plugin_file = plugin_basename( $this->booster_path . 'ran-booster.php' );
			$export_url  = admin_url( 'admin.php?page=ran-booster-transporter#ran-booster-portability-export' );
			$export_link = '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export settings before deleting', 'ran-booster' ) . '</a>';

			add_filter(
				'plugin_action_links_' . $plugin_file,
				function ( $links ) use ( $export_link ) {
					$links[] = $export_link;
					return $links;
				}
			);
		}

		if ( $this->is_passive_troubleshooting_request() ) {
			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}

		$repository = $this->service( 'RAN\Storage\PluginRepository' );
		try {
			$plugins = $repository->all_booster_plugins();
		} catch ( \RAN\Storage\PackageStorageFailure | \InvalidArgumentException ) {
			// Optional links must not prevent access to damaged-settings review and removal.
			return;
		}
		$url = is_multisite()
			? network_admin_url( 'admin.php?page=ran-booster-plugins' )
			: get_admin_url( null, 'admin.php?page=ran-booster-plugins' );

		$prefix = is_multisite()
			? 'network_admin_plugin_action_links_'
			: 'plugin_action_links_';

		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage with RAN Booster', 'ran-booster' ) . '</a>';

		foreach ( $plugins as $plugin ) {
			add_filter(
				$prefix . $plugin->file,
				function ( $links ) use ( $link ) {
					$links[] = $link;
					return $links;
				}
			);
		}
	}

	/**
	 * Keep the passive Troubleshooting GET free of database-backed bootstrap work.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database() {
		if ( $this->is_passive_troubleshooting_request() ) {
			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->maybe_upgrade();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The persistent notice reports the active safe state.
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			// An active plugin stays active but enters the storage-safe state.
		}
		$this->upgrade_activity();
	}

	/** Optional history has its own schema version and cannot poison Core readiness. */
	private function upgrade_activity(): void {
		$activity = $this->service( \RAN\Activity\ActivityStore::class );
		if ( ! $activity->available() ) {
			$activity->install();
		}
	}

	/** Reuse WordPress's daily maintenance hook; never prune from a rendered history. */
	public function prune_activity(): void {
		$activity = $this->service( \RAN\Activity\ActivityStore::class );
		if ( ! $activity->available() ) {
			return;
		}
		$plugins = $this->service( \RAN\Storage\PluginRepository::class )->all_deployment_plugins();
		$themes  = $this->service( \RAN\Storage\ThemeRepository::class )->all_deployment_themes();
		if ( ! is_array( $plugins ) || ! is_array( $themes ) ) {
			return;
		}
		$contexts = array();
		$evidence = null;
		foreach ( array(
			'plugin' => $plugins,
			'theme'  => $themes,
		) as $type => $packages ) {
			foreach ( $packages as $package ) {
				if ( ! $package instanceof Package || null === AdoptionIdentity::of( $package ) ) {
					return;
				}
				$evidence ??= $this->service( \RAN\Admin\RepositoryBranchCheckEvidenceStore::class );
				foreach ( array( 'branch.check', 'release.check', 'workflow.assessment' ) as $purpose ) {
					$contexts[] = $evidence->activity_context( $type, $package, $purpose );
				}
			}
		}
		for ( $batch = 0; $batch < 20; ++$batch ) {
			$removed = $activity->prune( $contexts );
			if ( false === $removed || $removed < 100 ) {
				break;
			}
		}
	}

	/**
	 * Whether this fixed passive admin route defers storage/schema bootstrap.
	 */
	public function is_passive_troubleshooting_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Exact route classification only; Dispatcher retains authorization.
		$fixed_page = $_GET['page'] ?? null;
		if ( 'GET' === $method && 'ran-booster-settings' === $fixed_page ) {
			return true;
		}
		if ( 'GET' !== $method ) {
			return false;
		}

		// Read-only routing state; this guard prevents state access and performs no action.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing defers database/sidecar bootstrap; it does not authorize an action.
		$page_input = $_GET['page'] ?? '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing defers database/sidecar bootstrap; it does not authorize an action.
		$tab_input = $_GET['tab'] ?? '';
		if ( ! is_string( $page_input ) || ! is_string( $tab_input ) ) {
			return false;
		}

		if ( 'ran-booster' !== sanitize_key( wp_unslash( $page_input ) )
			|| 'troubleshooting' !== sanitize_key( wp_unslash( $tab_input ) ) ) {
			return false;
		}

		// Activity reads durable journals. Diagnostics and Logging
		// defer sidecar validation during bootstrap; Logging reads
		// only its bounded file when rendered.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The allowlisted panel decides whether bootstrap may read durable journals, not whether a mutation is authorized.
		$panel_input = $_GET['panel'] ?? 'diagnostics';
		$panel       = is_string( $panel_input )
			? sanitize_key( wp_unslash( $panel_input ) )
			: 'diagnostics';

		return ! in_array( $panel, array( 'activity', 'deployment-activity' ), true );
	}

	/**
	 * @param mixed $hook Screen identifier, validated before loading assets.
	 * @return void
	 */
	public function load_scripts( $hook ) {
		if ( ! is_string( $hook ) || ! in_array( $hook, self::ADMIN_PAGE_HOOKS, true ) ) {
			return;
		}

		$script_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster.js';

		$secure_inputs_script_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-secure-inputs.js';

		$portability_script_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-portability.js';

		$enhanced_mutation_script_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-enhanced-mutations.js';

		$package_script_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-packages.js';

		$repository_picker_script_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-repository-picker.js';
		$script_version                   = $this->asset_version( $script_path );
		$secure_inputs_script_version     = $this->asset_version( $secure_inputs_script_path );
		$portability_script_version       = $this->asset_version( $portability_script_path );
		$enhanced_mutation_script_version = $this->asset_version( $enhanced_mutation_script_path );
		$package_script_version           = $this->asset_version( $package_script_path );
		$repository_picker_script_version = $this->asset_version( $repository_picker_script_path );
		$script_dependencies              = array();
		$requested_tab                    = null;
		$is_transporter_page              = 'ran-booster_page_ran-booster-transporter' === $hook;
		$should_enqueue_portability       = false;
		$should_enqueue_htmx              = $is_transporter_page || in_array( $hook, self::PACKAGE_PAGE_HOOKS, true );

		if ( 'toplevel_page_ran-booster' === $hook || $is_transporter_page ) {
			// Read-only allowlisted navigation state; no action is performed from this value.
			if ( $is_transporter_page ) {
				$requested_tab = 'portability';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			} elseif ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
					$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			}

			if ( $this->is_provider_admin_tab( $requested_tab ) || in_array( $requested_tab, array( 'portability', 'troubleshooting' ), true ) ) {
				$should_enqueue_htmx = true;
			}
		}

		if ( $should_enqueue_htmx ) {

			$htmx_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/lib/htmx/htmx.min.js';
			$htmx_version = $this->asset_version( $htmx_path );

			wp_register_script(
				'ran-booster-htmx',
				trailingslashit( $this->booster_url ?? '' ) . 'assets/lib/htmx/htmx.min.js',
				array(),
				$htmx_version,
				true
			);
			wp_enqueue_script( 'ran-booster-htmx' );
			$script_dependencies[] = 'ran-booster-htmx';
		}

		$style_dependencies = array();

		$admin_shell_style_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-admin-shell.css';
		wp_register_style(
			'ran-booster-admin-shell',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-admin-shell.css',
			array(),
			$this->asset_version( $admin_shell_style_path )
		);
		wp_enqueue_style( 'ran-booster-admin-shell' );
		$style_dependencies[] = 'ran-booster-admin-shell';
		foreach ( self::ADMIN_STYLE_COMPONENTS as $style_component ) {

			$style_component_path = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster/' . $style_component;
			$style_handle         = '80-responsive.css' === $style_component
				? 'ran-booster-styles'
				: 'ran-booster-' . basename( $style_component, '.css' );

			wp_register_style(
				$style_handle,
				trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster/' . $style_component,
				$style_dependencies,
				$this->asset_version( $style_component_path )
			);
			$style_dependencies = array( $style_handle );
		}
		wp_enqueue_style( 'ran-booster-styles' );


		if ( 'ran-booster_page_ran-booster-extensions' === $hook ) {

			$extension_details_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-extension-details.js';
			$extension_details_version = $this->asset_version( $extension_details_path );

			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script( 'thickbox' );
			wp_register_script(
				'ran-booster-extension-details',
				trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-extension-details.js',
				array( 'jquery', 'thickbox' ),
				$extension_details_version,
				true
			);
			wp_enqueue_script( 'ran-booster-extension-details' );
			return;
		}

		wp_register_script( 'ran-booster-js', trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster.js', array_merge( $script_dependencies, array( 'wp-i18n' ) ), $script_version, true );

		wp_set_script_translations( 'ran-booster-js', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
		wp_register_script(
			'ran-booster-secure-inputs',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-secure-inputs.js',
			array( 'ran-booster-js', 'wp-i18n' ),
			$secure_inputs_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-secure-inputs', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
		wp_register_script(
			'ran-booster-enhanced-mutations',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-enhanced-mutations.js',
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$enhanced_mutation_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-enhanced-mutations', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
		wp_register_script(
			'ran-booster-packages',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-packages.js',
			array( 'ran-booster-enhanced-mutations', 'wp-i18n' ),
			$package_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-packages', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
		wp_register_script(
			'ran-booster-repository-picker',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-repository-picker.js',
			array( 'ran-booster-js', 'wp-i18n' ),
			$repository_picker_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-repository-picker', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );

		if ( 'toplevel_page_ran-booster' === $hook || $is_transporter_page || 'ran-booster_page_ran-booster-settings' === $hook ) {

			$onboarding_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-onboarding.css';
			$onboarding_version = $this->asset_version( $onboarding_path );

			wp_register_style( 'ran-booster-onboarding', trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-onboarding.css', array( 'ran-booster-styles' ), $onboarding_version );
			wp_enqueue_style( 'ran-booster-onboarding' );

			if ( 'documentation' === $requested_tab ) {

				$documentation_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/ran-booster-documentation.css';
				$documentation_version = $this->asset_version( $documentation_path );

				wp_register_style( 'ran-booster-documentation', trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-documentation.css', array( 'ran-booster-styles' ), $documentation_version );
				wp_enqueue_style( 'ran-booster-documentation' );
			}

			if ( 'portability' === $requested_tab ) {
				wp_register_script(
					'ran-booster-portability',
					trailingslashit( $this->booster_url ?? '' ) . 'assets/ran-booster-portability.js',
					array( 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'wp-i18n' ),
					$portability_script_version,
					true
				);

				wp_set_script_translations( 'ran-booster-portability', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
				wp_localize_script(
					'ran-booster-portability',
					'ranBoosterPortability',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					)
				);
				$should_enqueue_portability = true;
			}
		}

		if ( in_array( $hook, self::PACKAGE_PAGE_HOOKS, true ) ) {
			$package_settings = $this->service( 'RAN\\Admin\\ProviderSettingsPresenter' )->build_package_form();

			$this->load_notice_preference_script(
				'ranBoosterDevelopmentSafetyNotice',
				\RAN\Admin\DevelopmentSafetyNoticeController::AJAX_ACTION,
				\RAN\Admin\DevelopmentSafetyNoticeController::NONCE_ACTION
			);
			wp_localize_script(
				'ran-booster-repository-picker',
				'ranBoosterRepoPicker',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'action'          => \RAN\Admin\RepositoryPickerController::AJAX_ACTION,
					'nonce'           => wp_create_nonce( \RAN\Admin\RepositoryPickerController::NONCE_ACTION ),
					'defaultProvider' => $package_settings['default_provider'],
					'providers'       => $package_settings['providers'],
				)
			);
		}

		if ( in_array( $hook, self::PACKAGE_INDEX_PAGE_HOOKS, true ) ) {
			wp_localize_script(
				'ran-booster-packages',
				'ranBoosterPackageProgress',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'action'   => \RAN\Admin\PackageUpdateProgressController::AJAX_ACTION,
					'nonce'    => wp_create_nonce( \RAN\Admin\PackageUpdateProgressController::NONCE_ACTION ),
					'interval' => 3000,
					'maxPolls' => 200,
					'labels'   => array(
						'queued'             => __( 'Queued', 'ran-booster' ),
						'running'            => __( 'Running', 'ran-booster' ),
						'succeeded'          => __( 'Succeeded', 'ran-booster' ),
						'failed'             => __( 'Failed', 'ran-booster' ),
						'needsAttention'     => __( 'Needs attention', 'ran-booster' ),
						'queuedButton'       => __( 'Reinstall queued', 'ran-booster' ),
						'updatingButton'     => __( 'Reinstall in progress…', 'ran-booster' ),
						'successMessage'     => __( 'Package reinstalled.', 'ran-booster' ),
						'failureMessage'     => __( 'Package reinstall failed. Review deployment activity.', 'ran-booster' ),
						'attentionMessage'   => __( 'Package reinstall needs attention. Review deployment activity before retrying.', 'ran-booster' ),
						'unavailableMessage' => __( 'Live reinstall progress is unavailable. Refresh this page to check the latest state.', 'ran-booster' ),
						'summaryActive'      => __( 'Booster reinstalls are in progress. Queued: {queued}. Reinstalling: {running}. Skipped: {skipped}.', 'ran-booster' ),
						'summaryFinished'    => __( 'Booster reinstalls have finished. Review the package statuses below. Skipped: {skipped}.', 'ran-booster' ),
					),
				)
			);
		}

		wp_enqueue_script( 'ran-booster-js' );
		wp_enqueue_script( 'ran-booster-secure-inputs' );
		wp_enqueue_script( 'ran-booster-enhanced-mutations' );
		if ( $should_enqueue_portability ) {
			wp_enqueue_script( 'ran-booster-portability' );
		}
		if ( in_array( $hook, self::PACKAGE_PAGE_HOOKS, true ) ) {
			wp_enqueue_script( 'ran-booster-packages' );
			wp_enqueue_script( 'ran-booster-repository-picker' );
		}
	}

	/**
	 * Whether a top-level tab is owned by a registered repository provider.
	 */
	protected function is_provider_admin_tab( ?string $tab ): bool {
		if ( null === $tab || '' === $tab ) {
			return false;
		}

		try {
			$code = ProviderCode::parse( $tab );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		try {
			foreach ( $this->service( ProviderRegistry::class )->administration_metadata() as $metadata ) {
				if ( $metadata->code->equals( $code ) ) {
					return true;
				}
			}
		} catch ( \Throwable ) {
			return false;
		}

		return false;
	}

	/**
	 * Load the dedicated dismissal asset on any administration screen where
	 * the current administrator will receive an expiry notice.
	 *
	 * @param mixed $hook Unused screen identifier supplied by WordPress.
	 */
	public function load_credential_expiry_notice_script( $hook ): void {
		unset( $hook );

		$notice = $this->service( \RAN\Admin\CredentialExpiryNotice::class );
		if ( ! $notice->should_load_dismissal_script() ) {
			return;
		}

		$this->load_notice_preference_script(
			'ranBoosterCredentialExpiryNotice',
			\RAN\Admin\CredentialExpiryNoticeController::AJAX_ACTION,
			\RAN\Admin\CredentialExpiryNoticeController::NONCE_ACTION
		);
	}

	/**
	 * Load the small dismissal asset only when a background failure is visible.
	 *
	 * @param mixed $hook Unused screen identifier supplied by WordPress.
	 */
	public function load_background_deployment_failure_notice_script( $hook ): void {
		unset( $hook );

		$notice = $this->service( \RAN\Admin\DeploymentAdminPresenter::class );
		if ( ! $notice->should_render() ) {
			return;
		}

		$this->load_notice_preference_script(
			'ranBoosterBackgroundFailureNotice',
			\RAN\Admin\DeploymentAdminController::AJAX_ACTION,
			\RAN\Admin\DeploymentAdminController::NONCE_ACTION
		);
	}

	private function load_notice_preference_script( string $object_name, string $action, string $nonce_action ): void {
		$path = trailingslashit( $this->booster_path ?? '' ) . 'assets/notice-preference.js';
		wp_register_script( 'ran-booster-notice-preference', trailingslashit( $this->booster_url ?? '' ) . 'assets/notice-preference.js', array(), $this->asset_version( $path ), true );
		wp_localize_script(
			'ran-booster-notice-preference',
			$object_name,
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => $action,
				'nonce'        => wp_create_nonce( $nonce_action ),
				'errorMessage' => __( 'RAN Booster could not save this notice preference. Please try again.', 'ran-booster' ),
			)
		);
		wp_enqueue_script( 'ran-booster-notice-preference' );
	}

	/**
	 * Load the native-update warning listener only on the screens that can show it.
	 *
	 * @param mixed $hook Unused screen identifier supplied by WordPress.
	 */
	private function load_native_update_warning_script( $hook ): void {
		if ( ! $this->native_update_warning_scope( $hook ) || ! $this->can_check_native_update_warning() ) {
			return;
		}

		$script_path    = trailingslashit( $this->booster_path ?? '' ) . 'assets/native-update-warning.js';
		$script_version = $this->asset_version( $script_path );
		wp_enqueue_script(
			'ran-booster-native-update-warning',
			trailingslashit( $this->booster_url ?? '' ) . 'assets/native-update-warning.js',
			array( 'jquery', 'heartbeat' ),
			$script_version,
			true
		);
		wp_set_script_translations( 'ran-booster-native-update-warning', 'ran-booster', trailingslashit( $this->booster_path ?? '' ) . 'languages' );
	}

	private function render_native_update_warning(): void {
		if ( ! $this->native_update_warning_scope( null ) || ! $this->can_check_native_update_warning() ) {
			return;
		}

		$warning    = $this->native_update_warning_state();
		$can_manage = current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
		?>
		<div class="notice notice-warning<?php echo \RAN\Admin\BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?>" data-ran-booster-native-update-warning<?php echo true === $warning ? '' : ' hidden'; ?>><p>
			<?php esc_html_e( 'A managed GitHub update has not been verified. Further GitHub-managed updates are paused until verification is complete or recovery is performed.', 'ran-booster' ); ?>
			<?php if ( $can_manage ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=gh' ) ); ?>"><?php esc_html_e( 'Review GitHub management.', 'ran-booster' ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'Contact a site administrator.', 'ran-booster' ); ?>
			<?php endif; ?>
		</p></div>
		<?php
	}

	/**
	 * Adds a bounded boolean status to an authenticated WordPress heartbeat response.
	 *
	 * @param mixed $response Heartbeat response assembled by WordPress and other plugins.
	 * @param mixed $data Heartbeat request payload.
	 * @return mixed
	 */
	private function native_update_warning_heartbeat( $response, $data ) {
		if ( ! is_array( $response ) || ! is_array( $data ) || ! array_key_exists( self::NATIVE_UPDATE_WARNING_HEARTBEAT_KEY, $data ) ) {
			return $response;
		}
		if ( ! $this->can_check_native_update_warning() ) {
			return $response;
		}

		$warning = $this->native_update_warning_state();
		if ( is_bool( $warning ) ) {
			$response[ self::NATIVE_UPDATE_WARNING_HEARTBEAT_KEY ] = $warning;
		}

		return $response;
	}

	/** @return bool|null True only for a retained native mutation; false only for an exact available selection. */
	private function native_update_warning_state(): ?bool {
		try {
			$status = $this->service( 'RAN\\Admin\\ProviderSettingsPresenter' )->github_selection_diagnostics();
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_array( $status ) || ! isset( $status['state'] ) || ! is_string( $status['state'] ) ) {
			return null;
		}
		if ( 'recovery_required' === $status['state'] ) {
			return true;
		}

		return 'available' === $status['state'] && true === ( $status['available'] ?? null ) ? false : null;
	}

	private function can_check_native_update_warning(): bool {
		return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' );
	}

	/** Keep this warning local: shared notice scope deliberately excludes Updates screens. */
	private function native_update_warning_scope( mixed $hook ): bool {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id         = is_object( $screen ) && is_string( $screen->id ) ? $screen->id : '';
		$booster_id = str_ends_with( $id, '-network' ) ? substr( $id, 0, -8 ) : $id;

		return in_array( $id, array( 'plugins', 'plugins-network', 'themes', 'themes-network', 'update-core', 'update-core-network' ), true )
			|| in_array( $booster_id, self::ADMIN_PAGE_HOOKS, true )
			|| ( is_string( $hook ) && in_array( $hook, self::ADMIN_PAGE_HOOKS, true ) );
	}

	/** Preserve WordPress's false/null fallbacks while supplying string cache tokens. */
	private function asset_version( string $path ): string|false|null {
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$modified = filemtime( $path );
		return false === $modified ? false : (string) $modified;
	}

	/**
	 * @param string $alias Internal service alias or class name.
	 * @return mixed The registered factory result or resolved instance.
	 */
	private function service( $alias ) {
		return $this->container->make( $alias );
	}

	private function service_callback( string $alias, string $method ): callable {
		$callback = array( $this->service( $alias ), $method );
		if ( ! is_callable( $callback ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal diagnostic; escaping belongs at the output boundary.
			throw new \LogicException( 'Core service does not provide a callable hook.' );
		}

		return $callback;
	}
}

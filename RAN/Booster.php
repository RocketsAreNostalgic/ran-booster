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
	private CoreContainer $container;

	private const ADMIN_PAGE_HOOKS = array(
		'toplevel_page_ran-booster',
		'ran-booster_page_ran-booster-transporter',
		'ran-booster_page_ran-booster-plugins-create',
		'ran-booster_page_ran-booster-plugins',
		'ran-booster_page_ran-booster-themes-create',
		'ran-booster_page_ran-booster-themes',
		'ran-booster_page_ran-booster-extensions',
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
		add_action( 'admin_init', array( $this->service( \RAN\Admin\CredentialSelfDestructPurger::class ), 'purge' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'admin_init', array( $this, 'register_plugin_action_links' ) );
		add_action( 'admin_init', array( $this->service( 'RAN\Dispatcher' ), 'dispatch_post_requests' ) );
		add_action(
			'wp_ajax_' . \RAN\Admin\RepositoryPickerController::AJAX_ACTION,
			array( $this->service( 'RAN\Admin\RepositoryPickerController' ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\DevelopmentSafetyNoticeController::AJAX_ACTION,
			array( $this->service( \RAN\Admin\DevelopmentSafetyNoticeController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\CredentialExpiryNoticeController::AJAX_ACTION,
			array( $this->service( \RAN\Admin\CredentialExpiryNoticeController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\DeploymentAdminController::AJAX_ACTION,
			array( $this->service( \RAN\Admin\DeploymentAdminController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PackageUpdateProgressController::AJAX_ACTION,
			array( $this->service( \RAN\Admin\PackageUpdateProgressController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::EXPORT_ACTION,
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handle_export' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::PREVIEW_ACTION,
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handle_preview' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::APPLY_ACTION,
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handle_apply' )
		);
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
		$expiry_notice = $this->service( \RAN\Admin\CredentialExpiryNotice::class );
		add_action( 'admin_notices', array( $expiry_notice, 'render' ) );
		add_action( 'network_admin_notices', array( $expiry_notice, 'render' ) );
		$failure_notice = $this->service( \RAN\Admin\DeploymentAdminPresenter::class );
		add_action( 'admin_notices', array( $failure_notice, 'render' ) );
		add_action( 'network_admin_notices', array( $failure_notice, 'render' ) );
		$runtime_notice = $this->service( \RAN\Admin\SecretsRuntimeAvailabilityNotice::class );
		add_action( 'admin_notices', array( $runtime_notice, 'render' ) );
		add_action( 'network_admin_notices', array( $runtime_notice, 'render' ) );
		$database_notice = $this->service( \RAN\Admin\DatabaseCompatibilityNotice::class );
		add_action( 'admin_notices', array( $database_notice, 'render' ) );
		add_action( 'network_admin_notices', array( $database_notice, 'render' ) );
		add_action( 'load-plugins.php', array( $this->service( \RAN\Admin\ManagedPluginFailureRows::class ), 'register' ) );
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
		add_submenu_page( 'ran-booster', $this->get_name(), __( 'Overview', 'ran-booster' ), 'manage_options', 'ran-booster', array( $this->service( 'RAN\Dashboard' ), 'get_index' ) );
		add_submenu_page( 'ran-booster', __( 'Install Plugin', 'ran-booster' ), __( 'Install Plugin', 'ran-booster' ), 'manage_options', 'ran-booster-plugins-create', array( $this->service( 'RAN\Dashboard' ), 'get_plugins_create' ) );
		add_submenu_page( 'ran-booster', __( 'Managed Plugins', 'ran-booster' ), __( 'Plugins', 'ran-booster' ), 'manage_options', 'ran-booster-plugins', array( $this->service( 'RAN\Dashboard' ), 'get_plugins' ) );
		add_submenu_page( 'ran-booster', __( 'Install Theme', 'ran-booster' ), __( 'Install Theme', 'ran-booster' ), 'manage_options', 'ran-booster-themes-create', array( $this->service( 'RAN\Dashboard' ), 'get_themes_create' ) );
		add_submenu_page( 'ran-booster', __( 'Managed Themes', 'ran-booster' ), __( 'Themes', 'ran-booster' ), 'manage_options', 'ran-booster-themes', array( $this->service( 'RAN\Dashboard' ), 'get_themes' ) );
		add_submenu_page( 'ran-booster', __( 'Transporter', 'ran-booster' ), __( 'Transporter', 'ran-booster' ), 'manage_options', 'ran-booster-transporter', array( $this->service( 'RAN\Dashboard' ), 'get_transporter' ) );
		add_submenu_page( 'ran-booster', __( 'Extensions', 'ran-booster' ), __( 'Extensions', 'ran-booster' ), 'manage_options', 'ran-booster-extensions', array( $this, 'render_extensions_page' ) );
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

	/** @param array<string, array<string, mixed>> $installed_plugins
	 *  @return list<array<string, mixed>>
	 */
	private function extension_cards( array $installed_plugins ): array {
		$catalogue = array(
			array(
				'id'            => 'ran-booster-bitbucket',
				'name'          => 'Bitbucket Cloud',
				'description'   => __( 'Connect Booster to Bitbucket Cloud repositories for managed deployments.', 'ran-booster' ),
				'details'       => __( 'Add Bitbucket Cloud as a first-party repository provider while Booster continues to own credentials, webhook verification, and deployment policy.', 'ran-booster' ),
				'features'      => array(
					__( 'Connect and configure Bitbucket Cloud repositories in Booster.', 'ran-booster' ),
					__( 'Use provider-specific package and credential guidance.', 'ran-booster' ),
					__( 'Carry eligible file-stored credentials through Transporter for explicit import on the target site.', 'ran-booster' ),
				),
				'requirements'  => array(
					__( 'WordPress 7.0 or later and PHP 8.2 or later.', 'ran-booster' ),
					__( 'A version of Booster compatible with this extension.', 'ran-booster' ),
					__( 'Manual Bitbucket webhook setup for Push-to-Deploy.', 'ran-booster' ),
				),
				'plugin'        => 'ran-booster-bitbucket/ran-booster-bitbucket.php',
				'image'         => 'bitbucket-cloud.svg',
				'availability'  => __( 'Free', 'ran-booster' ),
				'required_apis' => array(
					'RAN_BOOSTER_PROVIDER_API_VERSION' => 14,
					'RAN_BOOSTER_ADDON_API_VERSION'    => 17,
				),
				'docs_url'      => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme',
				'support_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues',
			),
			array(
				'id'            => 'ran-booster-wp-pusher-migrator',
				'name'          => 'WP Pusher Migrator',
				'description'   => __( 'Move existing WP Pusher-managed plugins and themes into Booster without reinstalling them.', 'ran-booster' ),
				'details'       => __( 'Review and adopt supported packages from an inactive WP Pusher 3.0.13 installation. Adopted packages start with deployments disabled, so enabling deployment remains an explicit decision.', 'ran-booster' ),
				'features'      => array(
					__( 'Review retained WP Pusher package records before migration.', 'ran-booster' ),
					__( 'Adopt supported GitHub and Bitbucket Cloud packages through Booster Transporter.', 'ran-booster' ),
					__( 'Keep the source records in place until you verify the result and remove them yourself.', 'ran-booster' ),
				),
				'requirements'  => array(
					__( 'WordPress 7.0 or later, PHP 8.2 or later, and a compatible version of Booster.', 'ran-booster' ),
					__( 'A single site with WP Pusher 3.0.13 installed but inactive.', 'ran-booster' ),
					__( 'Existing Booster credentials for private repositories; GitLab packages are not supported.', 'ran-booster' ),
				),
				'plugin'        => 'ran-booster-wp-pusher-migrator/ran-booster-wp-pusher-migrator.php',
				'image'         => 'wp-pusher-migrator.svg',
				'availability'  => __( 'Free', 'ran-booster' ),
				'required_apis' => array(
					'RAN_BOOSTER_PORTABILITY_API_VERSION' => 3,
					'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 3,
				),
				'docs_url'      => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator#readme',
				'support_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/issues',
			),
		);

		foreach ( $catalogue as &$extension ) {
			$installed                = array_key_exists( $extension['plugin'], $installed_plugins );
			$network_active_available = function_exists( __NAMESPACE__ . '\\is_plugin_active_for_network' ) || function_exists( 'is_plugin_active_for_network' );
			$active                   = $installed && ( is_plugin_active( $extension['plugin'] ) || ( $network_active_available && is_plugin_active_for_network( $extension['plugin'] ) ) );
			$compatible               = true;
			foreach ( $extension['required_apis'] as $marker => $version ) {
				if ( ! defined( $marker ) || constant( $marker ) !== $version ) {
					$compatible = false;
					break;
				}
			}

			$extension['image_url']  = trailingslashit( $this->booster_url ) . 'assets/extensions/' . $extension['image'];
			$extension['compatible'] = $compatible;
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
		if ( $this->is_passive_troubleshooting_request() ) {
			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}

		$repository = $this->service( 'RAN\Storage\PluginRepository' );
		$plugins    = $repository->all_booster_plugins();
		$url        = is_multisite()
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
	}

	/**
	 * Whether this Troubleshooting request defers sidecar validation during bootstrap.
	 */
	public function is_passive_troubleshooting_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: '';
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

		$script_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster.js';

		$secure_inputs_script_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster-secure-inputs.js';

		$portability_script_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster-portability.js';

		$enhanced_mutation_script_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster-enhanced-mutations.js';

		$package_script_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster-packages.js';

		$repository_picker_script_path    = trailingslashit( $this->booster_path ) . 'assets/ran-booster-repository-picker.js';
		$script_version                   = file_exists( $script_path ) ? filemtime( $script_path ) : null;
		$secure_inputs_script_version     = file_exists( $secure_inputs_script_path ) ? filemtime( $secure_inputs_script_path ) : null;
		$portability_script_version       = file_exists( $portability_script_path ) ? filemtime( $portability_script_path ) : null;
		$enhanced_mutation_script_version = file_exists( $enhanced_mutation_script_path ) ? filemtime( $enhanced_mutation_script_path ) : null;
		$package_script_version           = file_exists( $package_script_path ) ? filemtime( $package_script_path ) : null;
		$repository_picker_script_version = file_exists( $repository_picker_script_path ) ? filemtime( $repository_picker_script_path ) : null;
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

			$htmx_path    = trailingslashit( $this->booster_path ) . 'assets/lib/htmx/htmx.min.js';
			$htmx_version = file_exists( $htmx_path ) ? filemtime( $htmx_path ) : null;

			wp_register_script(
				'ran-booster-htmx',
				trailingslashit( $this->booster_url ) . 'assets/lib/htmx/htmx.min.js',
				array(),
				$htmx_version,
				true
			);
			wp_enqueue_script( 'ran-booster-htmx' );
			$script_dependencies[] = 'ran-booster-htmx';
		}

		$style_dependencies = array();

		$admin_shell_style_path = trailingslashit( $this->booster_path ) . 'assets/ran-admin-shell.css';
		wp_register_style(
			'ran-booster-admin-shell',
			trailingslashit( $this->booster_url ) . 'assets/ran-admin-shell.css',
			array(),
			file_exists( $admin_shell_style_path ) ? filemtime( $admin_shell_style_path ) : null
		);
		wp_enqueue_style( 'ran-booster-admin-shell' );
		$style_dependencies[] = 'ran-booster-admin-shell';
		foreach ( self::ADMIN_STYLE_COMPONENTS as $style_component ) {

			$style_component_path = trailingslashit( $this->booster_path ) . 'assets/ran-booster/' . $style_component;
			$style_handle         = '80-responsive.css' === $style_component
				? 'ran-booster-styles'
				: 'ran-booster-' . basename( $style_component, '.css' );

			wp_register_style(
				$style_handle,
				trailingslashit( $this->booster_url ) . 'assets/ran-booster/' . $style_component,
				$style_dependencies,
				file_exists( $style_component_path ) ? filemtime( $style_component_path ) : null
			);
			$style_dependencies = array( $style_handle );
		}
		wp_enqueue_style( 'ran-booster-styles' );
		if ( 'ran-booster_page_ran-booster-extensions' === $hook ) {

			$extension_details_path    = trailingslashit( $this->booster_path ) . 'assets/ran-booster-extension-details.js';
			$extension_details_version = file_exists( $extension_details_path ) ? filemtime( $extension_details_path ) : null;

			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script( 'thickbox' );
			wp_register_script(
				'ran-booster-extension-details',
				trailingslashit( $this->booster_url ) . 'assets/ran-booster-extension-details.js',
				array( 'jquery', 'thickbox' ),
				$extension_details_version,
				true
			);
			wp_enqueue_script( 'ran-booster-extension-details' );
			return;
		}

		wp_register_script( 'ran-booster-js', trailingslashit( $this->booster_url ) . 'assets/ran-booster.js', array_merge( $script_dependencies, array( 'wp-i18n' ) ), $script_version, true );

		wp_set_script_translations( 'ran-booster-js', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );
		wp_register_script(
			'ran-booster-secure-inputs',
			trailingslashit( $this->booster_url ) . 'assets/ran-booster-secure-inputs.js',
			array( 'ran-booster-js', 'wp-i18n' ),
			$secure_inputs_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-secure-inputs', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );
		wp_register_script(
			'ran-booster-enhanced-mutations',
			trailingslashit( $this->booster_url ) . 'assets/ran-booster-enhanced-mutations.js',
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$enhanced_mutation_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-enhanced-mutations', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );
		wp_register_script(
			'ran-booster-packages',
			trailingslashit( $this->booster_url ) . 'assets/ran-booster-packages.js',
			array( 'ran-booster-enhanced-mutations', 'wp-i18n' ),
			$package_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-packages', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );
		wp_register_script(
			'ran-booster-repository-picker',
			trailingslashit( $this->booster_url ) . 'assets/ran-booster-repository-picker.js',
			array( 'ran-booster-js', 'wp-i18n' ),
			$repository_picker_script_version,
			true
		);

		wp_set_script_translations( 'ran-booster-repository-picker', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );

		if ( 'toplevel_page_ran-booster' === $hook || $is_transporter_page ) {

			$onboarding_path    = trailingslashit( $this->booster_path ) . 'assets/ran-booster-onboarding.css';
			$onboarding_version = file_exists( $onboarding_path ) ? filemtime( $onboarding_path ) : null;

			wp_register_style( 'ran-booster-onboarding', trailingslashit( $this->booster_url ) . 'assets/ran-booster-onboarding.css', array( 'ran-booster-styles' ), $onboarding_version );
			wp_enqueue_style( 'ran-booster-onboarding' );

			if ( 'documentation' === $requested_tab ) {

				$documentation_path    = trailingslashit( $this->booster_path ) . 'assets/ran-booster-documentation.css';
				$documentation_version = file_exists( $documentation_path ) ? filemtime( $documentation_path ) : null;

				wp_register_style( 'ran-booster-documentation', trailingslashit( $this->booster_url ) . 'assets/ran-booster-documentation.css', array( 'ran-booster-styles' ), $documentation_version );
				wp_enqueue_style( 'ran-booster-documentation' );
			}

			if ( 'portability' === $requested_tab ) {
				wp_register_script(
					'ran-booster-portability',
					trailingslashit( $this->booster_url ) . 'assets/ran-booster-portability.js',
					array( 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'wp-i18n' ),
					$portability_script_version,
					true
				);

				wp_set_script_translations( 'ran-booster-portability', 'ran-booster', trailingslashit( $this->booster_path ) . 'languages' );
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

			wp_localize_script(
				'ran-booster-packages',
				'ranBoosterDevelopmentSafetyNotice',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => \RAN\Admin\DevelopmentSafetyNoticeController::AJAX_ACTION,
					'nonce'   => wp_create_nonce( \RAN\Admin\DevelopmentSafetyNoticeController::NONCE_ACTION ),
				)
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

		$script_path    = trailingslashit( $this->booster_path ) . 'assets/credential-expiry-notice.js';
		$script_version = file_exists( $script_path ) ? filemtime( $script_path ) : null;
		wp_register_script(
			'ran-booster-credential-expiry-notice',
			trailingslashit( $this->booster_url ) . 'assets/credential-expiry-notice.js',
			array(),
			$script_version,
			true
		);
		wp_localize_script(
			'ran-booster-credential-expiry-notice',
			'ranBoosterCredentialExpiryNotice',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => \RAN\Admin\CredentialExpiryNoticeController::AJAX_ACTION,
				'nonce'   => wp_create_nonce( \RAN\Admin\CredentialExpiryNoticeController::NONCE_ACTION ),
			)
		);
		wp_enqueue_script( 'ran-booster-credential-expiry-notice' );
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

		$script_path    = trailingslashit( $this->booster_path ) . 'assets/background-deployment-failure-notice.js';
		$script_version = file_exists( $script_path ) ? filemtime( $script_path ) : null;
		wp_register_script(
			'ran-booster-background-deployment-failure-notice',
			trailingslashit( $this->booster_url ) . 'assets/background-deployment-failure-notice.js',
			array(),
			$script_version,
			true
		);
		wp_localize_script(
			'ran-booster-background-deployment-failure-notice',
			'ranBoosterBackgroundFailureNotice',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => \RAN\Admin\DeploymentAdminController::AJAX_ACTION,
				'nonce'   => wp_create_nonce( \RAN\Admin\DeploymentAdminController::NONCE_ACTION ),
			)
		);
		wp_enqueue_script( 'ran-booster-background-deployment-failure-notice' );
	}

	/**
	 * @param string $alias Internal service alias or class name.
	 * @return mixed The registered factory result or resolved instance.
	 */
	private function service( $alias ) {
		return $this->container->make( $alias );
	}
}

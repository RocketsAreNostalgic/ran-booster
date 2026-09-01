<?php

namespace RAN;

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

	public $boosterPath;

	public $boosterUrl;

	/** @internal Core constructs the live runtime with its request-local container. */
	public function __construct( ?CoreContainer $container = null ) {
		$this->container = $container ?? new CoreContainer();
	}

	public function init() {
		add_action( 'admin_init', array( $this->service( \RAN\Admin\CredentialSelfDestructPurger::class ), 'purge' ), 1 );
		add_action( 'admin_init', array( $this, 'maybeUpgradeDatabase' ) );
		add_action( 'admin_init', array( $this, 'registerPluginActionLinks' ) );
		add_action( 'admin_init', array( $this->service( 'RAN\Dispatcher' ), 'dispatchPostRequests' ) );
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
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handleExport' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::PREVIEW_ACTION,
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handlePreview' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::APPLY_ACTION,
			array( $this->service( \RAN\Admin\PortabilityController::class ), 'handleApply' )
		);
		add_action( 'activate_plugin', array( WpPusherCoexistencePolicy::class, 'blockWpPusherActivation' ) );
		add_action( 'rest_api_init', array( $this, 'registerWebhookRoutes' ) );
		add_action( WordPressWorkerWakeup::HOOK, array( $this, 'runDeploymentWorker' ), 10, 0 );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'adminMenu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'adminMenu' ) );
		}

		// Add styles and scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'loadScripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'loadCredentialExpiryNoticeScript' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'loadBackgroundDeploymentFailureNoticeScript' ) );
		$expiryNotice = $this->service( \RAN\Admin\CredentialExpiryNotice::class );
		add_action( 'admin_notices', array( $expiryNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $expiryNotice, 'render' ) );
		$failureNotice = $this->service( \RAN\Admin\DeploymentAdminPresenter::class );
		add_action( 'admin_notices', array( $failureNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $failureNotice, 'render' ) );
		$runtimeNotice = $this->service( \RAN\Admin\SecretsRuntimeAvailabilityNotice::class );
		add_action( 'admin_notices', array( $runtimeNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $runtimeNotice, 'render' ) );
		$databaseNotice = $this->service( \RAN\Admin\DatabaseCompatibilityNotice::class );
		add_action( 'admin_notices', array( $databaseNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $databaseNotice, 'render' ) );
		add_action( 'load-plugins.php', array( $this->service( \RAN\Admin\ManagedPluginFailureRows::class ), 'register' ) );
	}

	public function activate() {
		if ( ! $this->sodiumAvailable() ) {
			wp_die(
				esc_html__(
					'RAN Booster requires the PHP Sodium extension for encrypted credential storage. Ask your hosting provider to enable Sodium, then activate the plugin again.',
					'ran-booster'
				)
			);

			return;
		}
		if ( $this->isMultisiteInstallation() ) {
			wp_die(
				esc_html__(
					'RAN Booster encrypted credential storage is not available on multisite in this Beta release. Use a single-site WordPress installation.',
					'ran-booster'
				)
			);

			return;
		}
		try {
			WpPusherCoexistencePolicy::assertPackageMutationAllowed();
		} catch ( \RuntimeException $failure ) {
			wp_die( esc_html( $failure->getMessage() ) );

			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->install();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure $exception ) {
			BoosterLogger::logException( 'plugin activation database unsupported', $exception, array( 'step' => 'plugin_activation' ) );
			wp_die( esc_html( $exception->getMessage() ) );

			return;
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException( 'plugin activation failed', $exception, array( 'step' => 'plugin_activation' ) );
			wp_die(
				esc_html__(
					'RAN Booster could not complete its database setup, so WordPress left the plugin inactive. Confirm that WordPress can create and update plugin tables, then try again or contact your hosting provider.',
					'ran-booster'
				)
			);

			return;
		}

		$this->service( WordPressWorkerWakeup::class )->request();
	}

	protected function sodiumAvailable(): bool {
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
	}

	protected function isMultisiteInstallation(): bool {
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

	public function runDeploymentWorker(): void {
		try {
			$this->service( 'RAN\Storage\Database' )->maybeUpgrade();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An active incompatible site must remain bootable without running the worker.
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}
		$this->service( DeploymentWorker::class )->runOnce();
	}

	public function registerWebhookRoutes(): void {
		$this->service( 'RAN\Webhook\WebhookController' )->registerRoutes();
	}

	public function adminMenu() {
		add_menu_page( $this->getName(), $this->getName(), 'manage_options', 'ran-booster', null, $this->getMenuIcon() );
		add_submenu_page( 'ran-booster', $this->getName(), 'Overview', 'manage_options', 'ran-booster', array( $this->service( 'RAN\Dashboard' ), 'getIndex' ) );
		add_submenu_page( 'ran-booster', 'Install Plugin', 'Install Plugin', 'manage_options', 'ran-booster-plugins-create', array( $this->service( 'RAN\Dashboard' ), 'getPluginsCreate' ) );
		add_submenu_page( 'ran-booster', 'Managed Plugins', 'Plugins', 'manage_options', 'ran-booster-plugins', array( $this->service( 'RAN\Dashboard' ), 'getPlugins' ) );
		add_submenu_page( 'ran-booster', 'Install Theme', 'Install Theme', 'manage_options', 'ran-booster-themes-create', array( $this->service( 'RAN\Dashboard' ), 'getThemesCreate' ) );
		add_submenu_page( 'ran-booster', 'Managed Themes', 'Themes', 'manage_options', 'ran-booster-themes', array( $this->service( 'RAN\Dashboard' ), 'getThemes' ) );
		add_submenu_page( 'ran-booster', 'Transporter', 'Transporter', 'manage_options', 'ran-booster-transporter', array( $this->service( 'RAN\Dashboard' ), 'getTransporter' ) );
		add_submenu_page( 'ran-booster', 'Extensions', 'Extensions', 'manage_options', 'ran-booster-extensions', array( $this, 'renderExtensionsPage' ) );
	}

	/**
	 * Render the fixed, release-bundled Extensions catalogue.
	 */
	public function renderExtensionsPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		try {
			if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) && ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installedPlugins = get_plugins();
			$extensions       = $this->extensionCards( is_array( $installedPlugins ) ? $installedPlugins : array() );
			$pluginsUrl       = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
			$this->service( 'RAN\Dashboard' )->getExtensions( $extensions, $pluginsUrl );
		} catch ( \Throwable $failure ) {
			BoosterLogger::logException(
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

	/** @param array<string, array<string, mixed>> $installedPlugins
	 *  @return list<array<string, mixed>>
	 */
	private function extensionCards( array $installedPlugins ): array {
		$catalogue = array(
			array(
				'id'            => 'ran-booster-bitbucket',
				'name'          => 'Bitbucket Cloud',
				'description'   => 'Connect Booster to Bitbucket Cloud repositories for managed deployments.',
				'details'       => 'Add Bitbucket Cloud as a first-party repository provider while Booster continues to own credentials, webhook verification, and deployment policy.',
				'features'      => array(
					'Connect and configure Bitbucket Cloud repositories in Booster.',
					'Use provider-specific package and credential guidance.',
					'Carry eligible file-stored credentials through Transporter for explicit import on the target site.',
				),
				'requirements'  => array(
					'WordPress 7.0 or later and PHP 8.2 or later.',
					'A version of Booster compatible with this extension.',
					'Manual Bitbucket webhook setup for Push-to-Deploy.',
				),
				'plugin'        => 'ran-booster-bitbucket/ran-booster-bitbucket.php',
				'image'         => 'bitbucket-cloud.svg',
				'availability'  => 'Free',
				'required_apis' => array(
					'RAN_BOOSTER_PROVIDER_API_VERSION' => 10,
					'RAN_BOOSTER_ADDON_API_VERSION'    => 16,
				),
				'docs_url'      => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme',
				'support_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues',
			),
			array(
				'id'            => 'ran-booster-wp-pusher-migrator',
				'name'          => 'WP Pusher Migrator',
				'description'   => 'Move existing WP Pusher-managed plugins and themes into Booster without reinstalling them.',
				'details'       => 'Review and adopt supported packages from an inactive WP Pusher 3.0.13 installation. Adopted packages start with deployments disabled, so enabling deployment remains an explicit decision.',
				'features'      => array(
					'Review retained WP Pusher package records before migration.',
					'Adopt supported GitHub and Bitbucket Cloud packages through Booster Transporter.',
					'Keep the source records in place until you verify the result and remove them yourself.',
				),
				'requirements'  => array(
					'WordPress 7.0 or later, PHP 8.2 or later, and a compatible version of Booster.',
					'A single site with WP Pusher 3.0.13 installed but inactive.',
					'Existing Booster credentials for private repositories; GitLab packages are not supported.',
				),
				'plugin'        => 'ran-booster-wp-pusher-migrator/ran-booster-wp-pusher-migrator.php',
				'image'         => 'wp-pusher-migrator.svg',
				'availability'  => 'Free',
				'required_apis' => array(
					'RAN_BOOSTER_PORTABILITY_API_VERSION' => 2,
					'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 2,
				),
				'docs_url'      => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator#readme',
				'support_url'   => 'https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/issues',
			),
		);

		foreach ( $catalogue as &$extension ) {
			$installed              = array_key_exists( $extension['plugin'], $installedPlugins );
			$networkActiveAvailable = function_exists( __NAMESPACE__ . '\\is_plugin_active_for_network' ) || function_exists( 'is_plugin_active_for_network' );
			$active                 = $installed && ( is_plugin_active( $extension['plugin'] ) || ( $networkActiveAvailable && is_plugin_active_for_network( $extension['plugin'] ) ) );
			$compatible             = true;
			foreach ( $extension['required_apis'] as $marker => $version ) {
				if ( ! defined( $marker ) || constant( $marker ) !== $version ) {
					$compatible = false;
					break;
				}
			}

			$extension['image_url']  = trailingslashit( $this->boosterUrl ) . 'assets/extensions/' . $extension['image'];
			$extension['compatible'] = $compatible;
			$extension['state']      = ! $installed
				? 'Not installed'
				: ( ! $compatible ? 'Incompatible' : ( $active ? 'Active' : 'Installed, inactive' ) );
			$extension['state_kind'] = $installed && ! $compatible ? 'error' : ( $active ? 'ok' : 'neutral' );
		}
		unset( $extension );

		return $catalogue;
	}

	public function getName() {
		return 'RAN Booster';
	}

	/**
	 * A monochrome rocket silhouette for the admin menu icon. WordPress renders
	 * custom SVG menu icons at reduced opacity rather than recoloring them, so a
	 * solid black fill matches the muted look of the other Dashicons.
	 */
	private function getMenuIcon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">'
			. '<path d="M10,2 C11.5,2 13,5 13,8 L13,15 L7,15 L7,8 C7,5 8.5,2 10,2 Z '
			. 'M7,12 L4,17 L7,15 Z M13,12 L16,17 L13,15 Z M8,15 L10,18.5 L12,15 Z" /></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is required for this SVG data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function registerPluginActionLinks() {
		if ( $this->isPassiveTroubleshootingRequest() ) {
			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}

		$repository = $this->service( 'RAN\Storage\PluginRepository' );
		$plugins    = $repository->allBoosterPlugins();
		$url        = is_multisite()
			? network_admin_url( 'admin.php?page=ran-booster-plugins' )
			: get_admin_url( null, 'admin.php?page=ran-booster-plugins' );

		$prefix = is_multisite()
			? 'network_admin_plugin_action_links_'
			: 'plugin_action_links_';

		$link = '<a href="' . $url . '">Manage with RAN Booster</a>';

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
	 */
	public function maybeUpgradeDatabase() {
		if ( $this->isPassiveTroubleshootingRequest() ) {
			return;
		}

		try {
			$this->service( 'RAN\Storage\Database' )->maybeUpgrade();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The persistent notice reports the active safe state.
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			// An active plugin stays active but enters the storage-safe state.
		}
	}

	/**
	 * Whether this Troubleshooting request defers sidecar validation during bootstrap.
	 */
	public function isPassiveTroubleshootingRequest(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: '';
		if ( 'GET' !== $method ) {
			return false;
		}

		// Read-only routing state; this guard prevents state access and performs no action.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pageInput = $_GET['page'] ?? '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabInput = $_GET['tab'] ?? '';
		if ( ! is_string( $pageInput ) || ! is_string( $tabInput ) ) {
			return false;
		}

		if ( 'ran-booster' !== sanitize_key( wp_unslash( $pageInput ) )
			|| 'troubleshooting' !== sanitize_key( wp_unslash( $tabInput ) ) ) {
			return false;
		}

		// Activity reads durable journals. Diagnostics and Logging
		// defer sidecar validation during bootstrap; Logging reads
		// only its bounded file when rendered.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$panelInput = $_GET['panel'] ?? 'diagnostics';
		$panel      = is_string( $panelInput )
			? sanitize_key( wp_unslash( $panelInput ) )
			: 'diagnostics';

		return ! in_array( $panel, array( 'activity', 'deployment-activity' ), true );
	}

	public function loadScripts( $hook ) {
		if ( ! is_string( $hook ) || ! in_array( $hook, self::ADMIN_PAGE_HOOKS, true ) ) {
			return;
		}

		$scriptPath                    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster.js';
		$secureInputsScriptPath        = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-secure-inputs.js';
		$portabilityScriptPath         = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-portability.js';
		$enhancedMutationScriptPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-enhanced-mutations.js';
		$packageScriptPath             = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-packages.js';
		$repositoryPickerScriptPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-repository-picker.js';
		$scriptVersion                 = file_exists( $scriptPath ) ? filemtime( $scriptPath ) : null;
		$secureInputsScriptVersion     = file_exists( $secureInputsScriptPath ) ? filemtime( $secureInputsScriptPath ) : null;
		$portabilityScriptVersion      = file_exists( $portabilityScriptPath ) ? filemtime( $portabilityScriptPath ) : null;
		$enhancedMutationScriptVersion = file_exists( $enhancedMutationScriptPath ) ? filemtime( $enhancedMutationScriptPath ) : null;
		$packageScriptVersion          = file_exists( $packageScriptPath ) ? filemtime( $packageScriptPath ) : null;
		$repositoryPickerScriptVersion = file_exists( $repositoryPickerScriptPath ) ? filemtime( $repositoryPickerScriptPath ) : null;
		$scriptDependencies            = array();
		$requestedTab                  = null;
		$isTransporterPage             = 'ran-booster_page_ran-booster-transporter' === $hook;
		$shouldEnqueuePortability      = false;
		$shouldEnqueueHtmx             = $isTransporterPage || in_array( $hook, self::PACKAGE_PAGE_HOOKS, true );

		if ( 'toplevel_page_ran-booster' === $hook || $isTransporterPage ) {
			// Read-only allowlisted navigation state; no action is performed from this value.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
			if ( $isTransporterPage ) {
				$requestedTab = 'portability';
			} elseif ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
					$requestedTab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $this->isProviderAdminTab( $requestedTab ) || in_array( $requestedTab, array( 'portability', 'troubleshooting' ), true ) ) {
				$shouldEnqueueHtmx = true;
			}
		}

		if ( $shouldEnqueueHtmx ) {
			$htmxPath    = trailingslashit( $this->boosterPath ) . 'assets/lib/htmx/htmx.min.js';
			$htmxVersion = file_exists( $htmxPath ) ? filemtime( $htmxPath ) : null;

			wp_register_script(
				'ran-booster-htmx',
				trailingslashit( $this->boosterUrl ) . 'assets/lib/htmx/htmx.min.js',
				array(),
				$htmxVersion,
				true
			);
			wp_enqueue_script( 'ran-booster-htmx' );
			$scriptDependencies[] = 'ran-booster-htmx';
		}

		$styleDependencies   = array();
		$adminShellStylePath = trailingslashit( $this->boosterPath ) . 'assets/ran-admin-shell.css';
		wp_register_style(
			'ran-booster-admin-shell',
			trailingslashit( $this->boosterUrl ) . 'assets/ran-admin-shell.css',
			array(),
			file_exists( $adminShellStylePath ) ? filemtime( $adminShellStylePath ) : null
		);
		wp_enqueue_style( 'ran-booster-admin-shell' );
		$styleDependencies[] = 'ran-booster-admin-shell';
		foreach ( self::ADMIN_STYLE_COMPONENTS as $styleComponent ) {
			$styleComponentPath = trailingslashit( $this->boosterPath ) . 'assets/ran-booster/' . $styleComponent;
			$styleHandle        = '80-responsive.css' === $styleComponent
				? 'ran-booster-styles'
				: 'ran-booster-' . basename( $styleComponent, '.css' );

			wp_register_style(
				$styleHandle,
				trailingslashit( $this->boosterUrl ) . 'assets/ran-booster/' . $styleComponent,
				$styleDependencies,
				file_exists( $styleComponentPath ) ? filemtime( $styleComponentPath ) : null
			);
			$styleDependencies = array( $styleHandle );
		}
		wp_enqueue_style( 'ran-booster-styles' );
		if ( 'ran-booster_page_ran-booster-extensions' === $hook ) {
			$extensionDetailsPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-extension-details.js';
			$extensionDetailsVersion = file_exists( $extensionDetailsPath ) ? filemtime( $extensionDetailsPath ) : null;

			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script( 'thickbox' );
			wp_register_script(
				'ran-booster-extension-details',
				trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-extension-details.js',
				array( 'jquery', 'thickbox' ),
				$extensionDetailsVersion,
				true
			);
			wp_enqueue_script( 'ran-booster-extension-details' );
			return;
		}
		wp_register_script( 'ran-booster-js', trailingslashit( $this->boosterUrl ) . 'assets/ran-booster.js', $scriptDependencies, $scriptVersion, true );
		wp_register_script(
			'ran-booster-secure-inputs',
			trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-secure-inputs.js',
			array( 'ran-booster-js' ),
			$secureInputsScriptVersion,
			true
		);
		wp_register_script(
			'ran-booster-enhanced-mutations',
			trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-enhanced-mutations.js',
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$enhancedMutationScriptVersion,
			true
		);
		wp_set_script_translations( 'ran-booster-enhanced-mutations', 'ran-booster', trailingslashit( $this->boosterPath ) . 'languages' );
		wp_register_script(
			'ran-booster-packages',
			trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-packages.js',
			array( 'ran-booster-enhanced-mutations' ),
			$packageScriptVersion,
			true
		);
		wp_register_script(
			'ran-booster-repository-picker',
			trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-repository-picker.js',
			array( 'ran-booster-js' ),
			$repositoryPickerScriptVersion,
			true
		);

		if ( 'toplevel_page_ran-booster' === $hook || $isTransporterPage ) {
			$onboardingPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-onboarding.css';
			$onboardingVersion = file_exists( $onboardingPath ) ? filemtime( $onboardingPath ) : null;
			wp_register_style( 'ran-booster-onboarding', trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-onboarding.css', array( 'ran-booster-styles' ), $onboardingVersion );
			wp_enqueue_style( 'ran-booster-onboarding' );

			if ( 'documentation' === $requestedTab ) {
				$documentationPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-documentation.css';
				$documentationVersion = file_exists( $documentationPath ) ? filemtime( $documentationPath ) : null;
				wp_register_style( 'ran-booster-documentation', trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-documentation.css', array( 'ran-booster-styles' ), $documentationVersion );
				wp_enqueue_style( 'ran-booster-documentation' );
			}

			if ( 'portability' === $requestedTab ) {
				wp_register_script(
					'ran-booster-portability',
					trailingslashit( $this->boosterUrl ) . 'assets/ran-booster-portability.js',
					array( 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
					$portabilityScriptVersion,
					true
				);
				wp_localize_script(
					'ran-booster-portability',
					'ranBoosterPortability',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					)
				);
				$shouldEnqueuePortability = true;
			}
		}

		if ( in_array( $hook, self::PACKAGE_PAGE_HOOKS, true ) ) {
			$packageSettings = $this->service( 'RAN\\Admin\\ProviderSettingsPresenter' )->buildPackageForm();

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
					'defaultProvider' => $packageSettings['default_provider'],
					'providers'       => $packageSettings['providers'],
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
		if ( $shouldEnqueuePortability ) {
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
	protected function isProviderAdminTab( ?string $tab ): bool {
		if ( null === $tab || '' === $tab ) {
			return false;
		}

		try {
			$code = ProviderCode::parse( $tab );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		try {
			foreach ( $this->service( ProviderRegistry::class )->administrationMetadata() as $metadata ) {
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
	 */
	public function loadCredentialExpiryNoticeScript( $hook ): void {
		unset( $hook );

		$notice = $this->service( \RAN\Admin\CredentialExpiryNotice::class );
		if ( ! $notice->shouldLoadDismissalScript() ) {
			return;
		}

		$scriptPath    = trailingslashit( $this->boosterPath ) . 'assets/credential-expiry-notice.js';
		$scriptVersion = file_exists( $scriptPath ) ? filemtime( $scriptPath ) : null;
		wp_register_script(
			'ran-booster-credential-expiry-notice',
			trailingslashit( $this->boosterUrl ) . 'assets/credential-expiry-notice.js',
			array(),
			$scriptVersion,
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
	 */
	public function loadBackgroundDeploymentFailureNoticeScript( $hook ): void {
		unset( $hook );

		$notice = $this->service( \RAN\Admin\DeploymentAdminPresenter::class );
		if ( ! $notice->shouldRender() ) {
			return;
		}

		$scriptPath    = trailingslashit( $this->boosterPath ) . 'assets/background-deployment-failure-notice.js';
		$scriptVersion = file_exists( $scriptPath ) ? filemtime( $scriptPath ) : null;
		wp_register_script(
			'ran-booster-background-deployment-failure-notice',
			trailingslashit( $this->boosterUrl ) . 'assets/background-deployment-failure-notice.js',
			array(),
			$scriptVersion,
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

	private function service( $alias ) {
		return $this->container->make( $alias );
	}
}

<?php

namespace RAN;

use ReflectionClass;
use RAN\Deployment\DeploymentWorker;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Portability\WpPusherCoexistencePolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

class Booster {

	private const ADMIN_PAGE_HOOKS = array(
		'toplevel_page_ran-booster',
		'ran-booster_page_ran-booster-plugins-create',
		'ran-booster_page_ran-booster-plugins',
		'ran-booster_page_ran-booster-themes-create',
		'ran-booster_page_ran-booster-themes',
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

	private static $instance;

	public $boosterPath;

	public $boosterUrl;

	protected $services = array();

	public static function getInstance() {
		return static::$instance;
	}

	public static function setInstance( Booster $booster ) {
		static::$instance = $booster;
	}

	public function init() {
		add_action( 'admin_init', array( $this->make( \RAN\Admin\CredentialSelfDestructPurger::class ), 'purge' ), 1 );
		add_action( 'admin_init', array( $this, 'maybeUpgradeDatabase' ) );
		add_action( 'admin_init', array( $this, 'registerPluginActionLinks' ) );
		add_action( 'admin_init', array( $this->make( 'RAN\Dispatcher' ), 'dispatchPostRequests' ) );
		add_action(
			'wp_ajax_' . \RAN\Admin\RepositoryPickerController::AJAX_ACTION,
			array( $this->make( 'RAN\Admin\RepositoryPickerController' ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\DevelopmentSafetyNoticeController::AJAX_ACTION,
			array( $this->make( \RAN\Admin\DevelopmentSafetyNoticeController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\CredentialExpiryNoticeController::AJAX_ACTION,
			array( $this->make( \RAN\Admin\CredentialExpiryNoticeController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\BackgroundDeploymentFailureNoticeController::AJAX_ACTION,
			array( $this->make( \RAN\Admin\BackgroundDeploymentFailureNoticeController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PackageUpdateProgressController::AJAX_ACTION,
			array( $this->make( \RAN\Admin\PackageUpdateProgressController::class ), 'handle' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::EXPORT_ACTION,
			array( $this->make( \RAN\Admin\PortabilityController::class ), 'handleExport' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::PREVIEW_ACTION,
			array( $this->make( \RAN\Admin\PortabilityController::class ), 'handlePreview' )
		);
		add_action(
			'wp_ajax_' . \RAN\Admin\PortabilityController::APPLY_ACTION,
			array( $this->make( \RAN\Admin\PortabilityController::class ), 'handleApply' )
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
		$expiryNotice = $this->make( \RAN\Admin\CredentialExpiryNotice::class );
		add_action( 'admin_notices', array( $expiryNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $expiryNotice, 'render' ) );
		$failureNotice = $this->make( \RAN\Admin\BackgroundDeploymentFailureNotice::class );
		add_action( 'admin_notices', array( $failureNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $failureNotice, 'render' ) );
		$runtimeNotice = $this->make( \RAN\Admin\SecretsRuntimeAvailabilityNotice::class );
		add_action( 'admin_notices', array( $runtimeNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $runtimeNotice, 'render' ) );
		$databaseNotice = $this->make( \RAN\Admin\DatabaseCompatibilityNotice::class );
		add_action( 'admin_notices', array( $databaseNotice, 'render' ) );
		add_action( 'network_admin_notices', array( $databaseNotice, 'render' ) );
		add_action( 'load-plugins.php', array( $this->make( \RAN\Admin\ManagedPluginFailureRows::class ), 'register' ) );
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
					'RAN Booster encrypted credential storage is not available on multisite in this Alpha release. Use a single-site WordPress installation.',
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
			$this->make( 'RAN\Storage\Database' )->install();
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

		$this->make( WordPressWorkerWakeup::class )->request();
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
			$this->make( TemporaryDebugCapture::class )->stop();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Deactivation must continue when the optional capture is unavailable.
		} catch ( \Throwable ) {
			// Deactivation must continue when an optional capture is unavailable.
		}

		$this->make( WordPressWorkerWakeup::class )->clear();
	}

	public function runDeploymentWorker(): void {
		try {
			$this->make( 'RAN\Storage\Database' )->maybeUpgrade();
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An active incompatible site must remain bootable without running the worker.
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}
		$this->make( DeploymentWorker::class )->runOnce();
	}

	public function registerWebhookRoutes(): void {
		try {
			$this->make( 'RAN\Storage\Database' )->maybeUpgrade();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure $failure ) {
			// Keep the authenticated route present; guarded storage returns a
			// retry-safe unavailable response without touching custom tables.
			BoosterLogger::log(
				'webhook route retained in database safe state',
				array(
					'step'   => 'webhook_route_registration',
					'reason' => $failure->reason(),
				)
			);
		}
		$this->make( 'RAN\Webhook\WebhookController' )->registerRoutes();
	}

	public function adminMenu() {
		add_menu_page( $this->getName(), $this->getName(), 'manage_options', 'ran-booster', array( $this->make( 'RAN\Dashboard' ), 'getIndex' ), $this->getMenuIcon() );
		add_submenu_page( 'ran-booster', 'Install Plugin', 'Install Plugin', 'manage_options', 'ran-booster-plugins-create', array( $this->make( 'RAN\Dashboard' ), 'getPluginsCreate' ) );
		add_submenu_page( 'ran-booster', 'Managed Plugins', 'Plugins', 'manage_options', 'ran-booster-plugins', array( $this->make( 'RAN\Dashboard' ), 'getPlugins' ) );
		add_submenu_page( 'ran-booster', 'Install Theme', 'Install Theme', 'manage_options', 'ran-booster-themes-create', array( $this->make( 'RAN\Dashboard' ), 'getThemesCreate' ) );
		add_submenu_page( 'ran-booster', 'Managed Themes', 'Themes', 'manage_options', 'ran-booster-themes', array( $this->make( 'RAN\Dashboard' ), 'getThemes' ) );
		add_submenu_page( 'ran-booster', 'Pro', 'Pro', 'manage_options', 'ran-booster-pro', array( $this, 'renderProPage' ) );
	}

	/**
	 * Render the fixed add-on-owned Pro page body within a Core-owned route.
	 */
	public function renderProPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$proUrl      = $this->proPageUrl();
		$bufferLevel = ob_get_level();
		ob_start();

		try {
			do_action( 'ran_booster_pro_page_body', $proUrl, 'administration' );
			$content = (string) ob_get_clean();
		} catch ( \Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}

			BoosterLogger::logException(
				'Pro page body action unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'pro_page_body',
					'event'  => 'ran_booster_pro_page_body',
				)
			);
			$this->renderProPageUnavailable();

			return;
		}

		if ( '' === trim( $content ) ) {
			$this->renderProPageFallback();

			return;
		}

		echo wp_kses_post( $content );
	}

	private function proPageUrl(): string {
		$path = 'admin.php?page=ran-booster-pro';

		return is_multisite() ? network_admin_url( $path ) : admin_url( $path );
	}

	private function renderProPageFallback(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RAN Booster Pro', 'ran-booster' ); ?></h1>
			<p><?php esc_html_e( 'Support RAN Booster to help fund compatible add-ons and ongoing maintenance.', 'ran-booster' ); ?></p>
			<p><a href="https://github.com/sponsors/RocketsAreNostalgic"><?php esc_html_e( 'Support RAN Booster on GitHub Sponsors', 'ran-booster' ); ?></a></p>
			<p><?php esc_html_e( 'A compatible supporter manager can provide its controls here. Install it through its approved distribution, then activate it from the Plugins screen.', 'ran-booster' ); ?></p>
			<p><a href="<?php echo esc_url( is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Open Plugins', 'ran-booster' ); ?></a></p>
		</div>
		<?php
	}

	private function renderProPageUnavailable(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RAN Booster Pro', 'ran-booster' ); ?></h1>
			<div class="notice notice-error"><p><?php esc_html_e( 'The Pro page is temporarily unavailable. Check the compatible add-on and try again.', 'ran-booster' ); ?></p></div>
		</div>
		<?php
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
			$this->make( 'RAN\Storage\Database' )->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return;
		}

		$repository = $this->make( 'RAN\Storage\PluginRepository' );
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
			$this->make( 'RAN\Storage\Database' )->maybeUpgrade();
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

	public function register( ProviderInterface $provider ) {
		$provider->register( $this );
	}

	public function loadScripts( $hook ) {
		if ( ! is_string( $hook ) || ! in_array( $hook, self::ADMIN_PAGE_HOOKS, true ) ) {
			return;
		}

		$stylePath                     = trailingslashit( $this->boosterPath ) . 'assets/ran-booster.css';
		$scriptPath                    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster.js';
		$secureInputsScriptPath        = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-secure-inputs.js';
		$portabilityScriptPath         = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-portability.js';
		$enhancedMutationScriptPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-enhanced-mutations.js';
		$packageScriptPath             = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-packages.js';
		$repositoryPickerScriptPath    = trailingslashit( $this->boosterPath ) . 'assets/ran-booster-repository-picker.js';
		$styleVersion                  = file_exists( $stylePath ) ? filemtime( $stylePath ) : null;
		$scriptVersion                 = file_exists( $scriptPath ) ? filemtime( $scriptPath ) : null;
		$secureInputsScriptVersion     = file_exists( $secureInputsScriptPath ) ? filemtime( $secureInputsScriptPath ) : null;
		$portabilityScriptVersion      = file_exists( $portabilityScriptPath ) ? filemtime( $portabilityScriptPath ) : null;
		$enhancedMutationScriptVersion = file_exists( $enhancedMutationScriptPath ) ? filemtime( $enhancedMutationScriptPath ) : null;
		$packageScriptVersion          = file_exists( $packageScriptPath ) ? filemtime( $packageScriptPath ) : null;
		$repositoryPickerScriptVersion = file_exists( $repositoryPickerScriptPath ) ? filemtime( $repositoryPickerScriptPath ) : null;
		$scriptDependencies            = array();
		$requestedTab                  = null;
		$shouldEnqueuePortability      = false;
		$shouldEnqueueHtmx             = in_array( $hook, self::PACKAGE_PAGE_HOOKS, true );

		if ( 'toplevel_page_ran-booster' === $hook ) {
			// Read-only allowlisted navigation state; no action is performed from this value.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
				$requestedTab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			}

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

		wp_register_style( 'ran-booster-styles', trailingslashit( $this->boosterUrl ) . 'assets/ran-booster.css', array(), $styleVersion );
		wp_enqueue_style( 'ran-booster-styles' );
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
			array( 'ran-booster-js', 'wp-a11y' ),
			$enhancedMutationScriptVersion,
			true
		);
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

		if ( 'toplevel_page_ran-booster' === $hook ) {
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
			$packageSettings = $this->make( 'RAN\\Admin\\ProviderSettingsPresenter' )->buildPackageForm();

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
			foreach ( $this->make( ProviderRegistry::class )->administrationMetadata() as $metadata ) {
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

		$notice = $this->make( \RAN\Admin\CredentialExpiryNotice::class );
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

		$notice = $this->make( \RAN\Admin\BackgroundDeploymentFailureNotice::class );
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
				'action'  => \RAN\Admin\BackgroundDeploymentFailureNoticeController::AJAX_ACTION,
				'nonce'   => wp_create_nonce( \RAN\Admin\BackgroundDeploymentFailureNoticeController::NONCE_ACTION ),
			)
		);
		wp_enqueue_script( 'ran-booster-background-deployment-failure-notice' );
	}

	/**
	 * Bind a service to the container.
	 *
	 * @param $alias
	 * @param $concrete
	 * @return mixed
	 */
	public function bind( $alias, $concrete ) {
		$this->services[ $alias ] = $concrete;
	}

	/**
	 * Request a service from the container.
	 *
	 * @param $alias
	 * @return mixed
	 */
	public function make( $alias ) {
		if ( isset( $this->services[ $alias ] ) && is_callable( $this->services[ $alias ] ) ) {
			return call_user_func_array( $this->services[ $alias ], array( $this ) );
		}

		if ( isset( $this->services[ $alias ] ) && is_object( $this->services[ $alias ] ) ) {
			return $this->services[ $alias ];
		}

		if ( isset( $this->services[ $alias ] ) && class_exists( $this->services[ $alias ] ) ) {
			return $this->resolve( $this->services[ $alias ] );
		}

		return $this->resolve( $alias );
	}

	private function resolve( $class ) {
		$reflection = new ReflectionClass( $class );

		$constructor = $reflection->getConstructor();

		// Constructor is null
		if ( ! $constructor ) {
			return new $class();
		}

		// Constructor with no parameters
		$params = $constructor->getParameters();

		if ( count( $params ) === 0 ) {
			return new $class();
		}

		$newInstanceParams = array();

		foreach ( $params as $param ) {
			$type = $param->getType();
			if ( null === $type ) {
				$newInstanceParams[] = null;
				continue;
			}

			$newInstanceParams[] = $this->make( $type->getName() );
		}

		return $reflection->newInstanceArgs(
			$newInstanceParams
		);
	}
}

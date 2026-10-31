<?php

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use LogicException;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminTab;
use RAN\Admin\AdminTabRegistry;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\Admin\DevelopmentEnvironmentDetector;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\OnboardingPresenter;
use RAN\Admin\PackagePagePresenter;
use RAN\Admin\PackageAdminController;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\Admin\ProviderRepositoryRowsNormalizer;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\Component\AdminStatusSummaryRenderer;
use RAN\Admin\Component\ProviderManagementTableRenderer;
use RAN\Admin\Component\RepositoryDetailRenderer;
use RAN\Admin\Component\RepositoryTableRenderer;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsStorageProvisioner;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\Troubleshooting\TroubleshootingService;
use Throwable;
use WP_Error;

class Dashboard {
	/** @var list<mixed> */
	public $messages = array();

	/** @var Booster */
	private $booster;

	/**
	 * @var Database
	 */
	private $db;

	/**
	 * @var PluginRepository
	 */
	private $plugins;

	/**
	 * @var ThemeRepository
	 */
	private $themes;

	/** @var array{provider:string,id:string,message:?string,error:?string}|null */
	private ?array $credential_validation_feedback = null;

	private ProviderSettingsPresenter $provider_settings;
	private TroubleshootingService $troubleshooting;
	/** @var array{providers: array<string, string>, provider_locator_hints: array<string, string>, credentials: array<string, list<array{id: string, label: string}>>, selected_provider: string, credential_id: string, repository: string, ran: bool, results: list<array{status: string, code: string, message: string, remediation: string}>, partial: bool, partial_reason: string|null, report: string, core_self_update: array<string, mixed>}|null */
	private ?array $troubleshooting_payload = null;

	/** @var array{text:string,filename:string}|null */
	private ?array $support_report_payload = null;

	private ?AdminTabRegistry $admin_tabs;
	private ?AdminAddOnRegistry $admin_add_ons;
	private ?RepositoryWebhookManagementControls $webhook_management;
	private ?ReleaseWorkflowControls $release_workflow;
	private ?CoreSelfUpdateDevelopmentNotice $core_self_update_development_notice;

	private ?ProviderDocumentationPresenter $provider_documentation;
	private ?PackageAdminController $package_admin = null;
	private DeploymentAdminPresenter $deployment_admin;
	private ?\RAN\Activity\ActivityStore $overview_activity = null;
	private PackagePagePresenter $plugin_pages;
	private PackagePagePresenter $theme_pages;
	private ?TemporaryDebugCapture $debug_capture                      = null;
	private ?\RAN\Settings\GeneralPreferenceStore $general_preferences = null;
	private ?\RAN\Admin\GitHubVisibility $github_visibility            = null;
	private ?\RAN\Troubleshooting\CoreSelfUpdateStatus $core_updates   = null;
	/** @var array<string,string> */
	private array $general_attempted = array();
	/** @var array<string,string> */
	private array $general_errors                                     = array();
	private ?string $general_feedback                                 = null;
	private ?SecretsStorageProvisioner $secrets_storage               = null;
	private ?SecretsStorageProvisioningResult $secrets_storage_result = null;
	/** @var array<string,mixed>|null */
	private ?array $storage_payload = null;

	/**
	 * @param Database $db
	 * @param PluginRepository $plugins
	 * @param Booster $booster
	 * @param ThemeRepository               $themes           Theme packages.
	 * @param ProviderSettingsPresenter $provider_settings Provider settings presenter.
	 * @param TroubleshootingService    $troubleshooting Bounded same-request diagnostics.
	 * @param AdminTabRegistry|null      $admin_tabs        Allowlisted admin navigation.
	 * @param ProviderDocumentationPresenter|null $provider_documentation Display-safe provider documentation.
	 * @param PackageOperationService|null      $package_operations Explicit package-operation boundary.
	 * @param DeploymentAttemptRepository|null $deployment_attempts    Bounded operator history reads.
	 * @param TemporaryDebugCapture|null        $debug_capture          Bounded Booster-only event capture.
	 * @param AdminAddOnRegistry|null            $admin_add_ons           Registered public add-on tabs.
	 * @param CoreSelfUpdateDevelopmentNotice|null $core_self_update_development_notice Core-owned source-checkout notice.
	 */
	public function __construct(
		Database $db,
		PluginRepository $plugins,
		Booster $booster,
		ThemeRepository $themes,
		ProviderSettingsPresenter $provider_settings,
		TroubleshootingService $troubleshooting,
		?AdminTabRegistry $admin_tabs = null,
		?ProviderDocumentationPresenter $provider_documentation = null,
		?PackageOperationService $package_operations = null,
		?DeploymentAttemptRepository $deployment_attempts = null,
		?TemporaryDebugCapture $debug_capture = null,
		?SecretsStorageProvisioner $secrets_storage = null,
		?AdminAddOnRegistry $admin_add_ons = null,
		?RepositoryWebhookManagementControls $webhook_management = null,
		?CoreSelfUpdateDevelopmentNotice $core_self_update_development_notice = null,
		?ReleaseWorkflowControls $release_workflow = null,
		private ?\RAN\PackageRemoval\PackageRemovalService $removals = null,
		?\RAN\Activity\ActivityStore $activity = null,
		?\RAN\Settings\GeneralPreferenceStore $general_preferences = null,
		?\RAN\Admin\GitHubVisibility $github_visibility = null,
		?\RAN\Troubleshooting\CoreSelfUpdateStatus $core_updates = null,
		private ?\RAN\Admin\CredentialExpiryNotice $credential_expiry_notice = null
	) {
		$this->overview_activity   = $activity;
		$this->general_preferences = $general_preferences;
		$this->github_visibility   = $github_visibility;
		$this->core_updates        = $core_updates;
		$this->db                  = $db;
		$this->plugins             = $plugins;
		$this->booster             = $booster;
		$this->themes              = $themes;

		$this->provider_settings = $provider_settings;
		$this->troubleshooting   = $troubleshooting;

		$this->admin_tabs = $admin_tabs;

		$this->provider_documentation = $provider_documentation;
		$this->deployment_admin       = new DeploymentAdminPresenter(
			attempts: $deployment_attempts,
			plugins: $plugins,
			themes: $themes,
			activity_store: $activity
		);

		$this->package_admin = new PackageAdminController( $package_operations, deployments: $this->deployment_admin, removals: $this->removals );
		$this->plugin_pages  = PackagePagePresenter::plugin();
		$this->theme_pages   = PackagePagePresenter::theme();

		$this->debug_capture = $debug_capture;

		$this->secrets_storage = $secrets_storage;

		$this->admin_add_ons = $admin_add_ons;

		$this->webhook_management = $webhook_management;

		$this->release_workflow = $release_workflow;

		$this->core_self_update_development_notice = $core_self_update_development_notice;
	}


	/**
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	public function get_index( ?string $forced_tab = null ) {
		if ( null === $this->admin_tabs ) {
			throw new LogicException( 'Booster admin tabs are not configured.' );
		}

		$requested_tab = null;

		if ( null !== $forced_tab && '' !== $forced_tab ) {

			$requested_tab = $forced_tab;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		} elseif ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
			// Read-only navigation state; no action is performed from this query value.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			$requested_tab = wp_unslash( $_GET['tab'] );
		}

		$requested_add_on_key = is_string( $requested_tab ) ? strtolower( trim( $requested_tab ) ) : '';
		$selected_add_on      = '' === $requested_add_on_key || null === $this->admin_add_ons
			? null
			: $this->admin_add_ons->get( $requested_add_on_key );
		$selected_tab         = null;
		if ( null === $selected_add_on ) {
			$selected_tab = $this->admin_tabs->resolve( $requested_tab );
			$selected_key = $selected_tab->get_key();
		} else {
			$selected_key = $selected_add_on->key();
		}
		$tabs      = $this->tab_navigation( $selected_key );
		$admin_url = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$data      = array(
			'tab'  => $selected_key,
			'tabs' => $tabs,
		);
		if ( null !== $selected_add_on && null !== $this->admin_add_ons ) {
			$data['add_on_tab']     = $selected_add_on;
			$data['add_on_context'] = $this->admin_add_ons->context_for(
				$selected_add_on,
				$admin_url . '?page=ran-booster&tab=' . rawurlencode( $selected_add_on->key() ),
				is_multisite() ? 'network' : 'site'
			);
		} elseif ( null !== $selected_tab ) {
			$data['tab_view'] = $selected_tab->get_view();
		}

		if ( null !== $selected_tab && 'overview' === $selected_tab->get_key() ) {
			$data['onboarding']                      = ( new OnboardingPresenter() )->build_overview(
				$tabs,
				$admin_url . '?page=ran-booster-plugins-create',
				$admin_url . '?page=ran-booster-themes-create',
				$this->plugins,
				$this->themes,
				$this->overview_activity,
				$this->storage_summary(),
				$this->provider_settings->overview_provider_facts()
			);
			$data['onboarding']['credential_expiry'] = $this->credential_expiry_notice?->overview_summary() ?? array(
				'status' => 'unknown',
				'total'  => null,
				'items'  => array(),
			);
		}

		if ( null !== $selected_tab && $selected_tab->is_provider() ) {
			$provider                               = $selected_tab->get_provider();
			$data                                   = array_merge(
				$data,
				$this->provider_settings->build( null === $provider ? null : $provider->value )
			);
			$data['credential_validation_feedback'] = $this->credential_validation_feedback;
			$data['provider_view']                  = $this->requested_provider_view();
			$data['provider_task']                  = $this->requested_provider_task();
			$data['repository_view']                = $this->requested_provider_repository_view();
			$data['provider_list_state']            = $this->requested_provider_list_state();
			$data['replace_credential']             = $this->requested_provider_repository_id( 'replace_credential' );

			$data['requested_repository_id']            = $this->requested_provider_repository_id();
			$data                                       = array_merge( $data, ( new ProviderRepositoryRowsNormalizer() )->project_page( $data, $this->webhook_management, $this->release_workflow ) );
			$data                                       = array_merge( $data, $this->provider_settings->build_profile_list_projection( $data ) );
			$data['webhook_management']                 = $this->webhook_management;
			$data['releaseWorkflow']                    = $this->release_workflow;
			$data['status_summary_renderer']            = new AdminStatusSummaryRenderer();
			$data['provider_management_table_renderer'] = new ProviderManagementTableRenderer();
			$data['repository_detail_renderer']         = new RepositoryDetailRenderer();
			$data['repository_table_renderer']          = new RepositoryTableRenderer();
		} elseif ( null !== $selected_tab && 'portability' === $selected_tab->get_key() ) {
			try {
				$export                                 = $this->portability_export_data();
				$data['portability_export_rows']        = $export['rows'];
				$data['portability_export_unavailable'] = false;
				try {
					$data['portability_export_credential_groups']       = $this->provider_settings->build_portability_credentials( $export['credentials'] );
					$data['portability_export_credentials_unavailable'] = false;
				} catch ( Throwable $failure ) {
					BoosterLogger::log_exception(
						'portability export credentials unavailable',
						$failure,
						array(
							'source' => 'admin',
							'step'   => 'portability_export_credentials',
						)
					);
					$data['portability_export_credential_groups']       = array();
					$data['portability_export_credentials_unavailable'] = true;
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::log_exception(
					'portability export rows unavailable',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'portability_export_rows',
					)
				);
				$data['portability_export_rows']                    = array();
				$data['portability_export_unavailable']             = true;
				$data['portability_export_credential_groups']       = array();
				$data['portability_export_credentials_unavailable'] = false;
			}
		} elseif ( null !== $selected_tab && 'documentation' === $selected_tab->get_key() ) {
			$data['provider_documentation'] = null === $this->provider_documentation
				? array()
				: $this->provider_documentation->build();
			$data['documentation_url']      = $admin_url . '?page=ran-booster&tab=documentation';
			$data['documentation_scope']    = is_multisite() ? 'network' : 'site';
		} elseif ( null !== $selected_tab && 'troubleshooting' === $selected_tab->get_key() ) {
			$panel                         = $this->requested_troubleshooting_panel();
			$data['troubleshooting_panel'] = $panel;
			if ( 'installation' === $panel ) {
				$data['troubleshooting'] = $this->troubleshooting->installation_payload( $this->provider_settings->github_selection_diagnostics(), $this->storage_installation_projection() );
			} elseif ( 'support-report' === $panel ) {
				$data['troubleshooting'] = array(
					'report'           => $this->support_report_payload['text'] ?? '',
					'report_filename'  => $this->support_report_payload['filename'] ?? '',
					'report_generated' => null !== $this->support_report_payload,
				);
			} elseif ( 'debug-capture' === $panel ) {
				$data['troubleshooting'] = array();
				$data['debug_capture']   = $this->debug_capture_payload();
			} elseif ( 'activity' === $panel ) {
				$data['troubleshooting'] = array();
				$provider_labels         = array();
				foreach ( $this->admin_tabs->all() as $tab ) {
					if ( $tab->is_provider() ) {
						$provider_labels[ $tab->get_key() ] = $tab->get_label();
					}
				}
				$data['deployment_activity'] = $this->deployment_admin->activity( $provider_labels );
			} else {
				$data = array_merge( $data, $this->troubleshooting_view_data() );
			}
		}

		return $this->render( 'index', $data );
	}

	/**
	 * Render the native sidebar route through the canonical Transporter tab.
	 *
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	public function get_transporter() {
		return $this->get_index( 'portability' );
	}

	/**
	 * Render the one Core-owned region affected by a public repository lookup
	 * preference mutation. The Dispatcher may return this fragment only after it
	 * has performed the action's ordinary capability and nonce checks.
	 */

	public function render_public_lookup_profile_region( string $provider_code, ?string $error = null ): string {

		$settings                                = $this->provider_settings->build( $provider_code );
		$settings['public_lookup_profile_error'] = $error;

		ob_start();
		// Internal controllers provide the fixed presentation payload. No request
		// input reaches extract().
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed keys from the internal provider-settings builder bind this Core-owned included view; request keys are not extracted.
		extract( $settings );
		require __DIR__ . '/../views/provider-public-lookup-profile.php';

		return (string) ob_get_clean();
	}

	/**
	 * Render the one Core-owned logging region affected by an explicit capture
	 * start or stop. The Dispatcher calls this only after capability and nonce
	 * checks; an error remains in this persistent local region.
	 */
	public function render_debug_capture_region( ?string $error = null ): string {

		$debug_capture = $this->debug_capture_payload();

		$debug_capture_error = $error;

		ob_start();
		require __DIR__ . '/../views/debug-capture.php';

		return (string) ob_get_clean();
	}

	public function set_secrets_storage_provisioning_result( SecretsStorageProvisioningResult $result ): void {
		$this->secrets_storage_result = $result;
		$this->storage_payload        = null;
	}

	private function log_secrets_storage_diagnostic( SecretsStorageProvisioningResult $result ): void {
		if ( ! in_array(
			$result->status(),
			array(
				SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION,
				SecretsStorageProvisioningResult::MANUAL_REQUIRED,
				SecretsStorageProvisioningResult::UNSUPPORTED,
			),
			true
		) ) {
			return;
		}

		BoosterLogger::log(
			'secrets storage diagnostic reported',
			array(
				'diagnostic_id' => $result->code(),
				'event'         => 'secrets_storage_diagnostic',
				'outcome_code'  => $result->code(),
				'source'        => 'admin',
				'state'         => $result->status(),
				'step'          => 'storage_status',
			)
		);
	}

	/** @return array{rows:list<array{name:string,identifier:string,type:string}>,credentials:array<string,array<string,list<array{index:int,name:string,type:string}>>>} */
	private function portability_export_data(): array {
		$rows        = array();
		$credentials = array();
		foreach ( array(
			'plugin' => $this->plugins->all_deployment_plugins(),
			'theme'  => $this->themes->all_deployment_themes(),
		) as $type => $packages ) {
			foreach ( $packages as $package ) {
				if ( ! $package instanceof Package ) {
					throw new \UnexpectedValueException();
				}
				$provider      = (string) $package->get_provider_code();
				$index         = count( $rows );
				$rows[]        = array(

					'name'       => $package->get_display_name(),
					'identifier' => (string) $package->get_identifier(),
					'type'       => $type,
				);
				$credential_id = $package->get_credential_id();
				if ( '' !== $provider && '' !== $credential_id ) {
					$credentials[ $provider ][ $credential_id ][] = array(
						'index' => $index,

						'name'  => $package->get_display_name(),
						'type'  => $type,
					);
				}
			}
		}

		return compact( 'rows', 'credentials' );
	}

	/** @param array{provider: string, credential_id?: string|null, repository?: string|null} $request */
	public function post_run_troubleshooting( array $request ): void {
		$this->troubleshooting_payload = $this->troubleshooting->diagnose(
			$request['provider'],
			$request['credential_id'] ?? null,
			$request['repository'] ?? null
		);
	}

	/** Capture once; subsequent rendering and browser tools consume this frozen text. */
	public function post_generate_support_report(): void {
		$this->support_report_payload = $this->troubleshooting->generate_support_report( $this->storage_installation_projection() );
	}

	/** Render the Core-owned Diagnostics panel after an explicit HTMX request. */
	public function render_troubleshooting_diagnostics_region(): string {

		$troubleshooting_panel = 'diagnostics';
		$troubleshooting       = $this->troubleshooting_view_data()['troubleshooting'];

		ob_start();
		require __DIR__ . '/../views/troubleshooting.php';

		return (string) ob_get_clean();
	}

	/** @return array{troubleshooting:array<string,mixed>,github_selection_diagnostics:array<string,mixed>|null} */
	private function troubleshooting_view_data(): array {
		$selection = $this->provider_settings->github_selection_diagnostics();
		return array(
			'troubleshooting'              => $this->troubleshooting->with_installation_context( $this->troubleshooting_payload ?? $this->troubleshooting->form_payload(), $selection ),
			'github_selection_diagnostics' => $selection,
		);
	}

	/** Whether the most recent diagnostics result completed without a warning or failure. */
	public function troubleshooting_succeeded(): bool {
		$results = $this->troubleshooting_payload['results'] ?? null;
		if ( ! is_array( $results ) || array() === $results ) {
			return false;
		}

		foreach ( $results as $result ) {
			if ( 'pass' !== $result['status'] ) {
				return false;
			}
		}

		return empty( $this->troubleshooting_payload['partial'] );
	}

	/**
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	public function get_plugins() {
		return $this->render_package_page( $this->plugin_pages );
	}

	/**
	 * @return mixed The package renderer result.
	 */
	public function get_plugins_create() {
		return $this->render_package_create( $this->plugin_pages );
	}

	/**
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	public function get_themes() {
		return $this->render_package_page( $this->theme_pages );
	}

	/**
	 * @return mixed The package renderer result.
	 */
	public function get_themes_create() {
		return $this->render_package_create( $this->theme_pages );
	}

	/** Render the fixed Settings destination without selecting a horizontal item.
	 *
	 * @return mixed The included template result.
	 */
	public function get_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot access Booster Settings.', 'ran-booster' ) );
		}
		$result  = $this->storage_status();
		$storage = $this->storage_payload;
		if ( null === $storage && null !== $result ) {
			$private = current_user_can( 'activate_plugins' );
			try {
				$recovery = $private && null !== $this->secrets_storage ? $this->secrets_storage->recovery_state( $result ) : null;
			} catch ( Throwable ) {
				$recovery = null;
			}
			$storage = ( new SecretsStorageSetupPresenter() )->build(
				$result,
				admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' ),
				defined( 'ABSPATH' ) ? ABSPATH : '',
				$private,
				$recovery
			);
		}
		$this->storage_payload = $storage;
		return $this->render(
			'settings',
			array_merge(
				$this->general_settings_data(),
				array(
					'secrets_storage'                      => $storage,
					'credential_storage_documentation_url' => admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-credential-storage' ),
				)
			)
		);
	}

	/** @return array<string,mixed> */
	private function general_settings_data(): array {
		$preferences = $this->general_preferences ?? new \RAN\Settings\GeneralPreferenceStore();
		try {
			$snapshot   = $preferences->view_snapshot();
			$projection = $snapshot['fields'];
			$token      = $snapshot['token'];
			if ( $snapshot['malformed'] ) {
				$this->general_feedback = __( 'The saved Settings document is malformed. Runtime safety defaults remain in effect; no values were repaired.', 'ran-booster' );
			}
		} catch ( Throwable ) {
			$projection = array();
			$token      = '';
		}
		$visibility = array(
			'hidden'   => false,
			'eligible' => false,
			'reason'   => 'github_selection_unknown',
		);
		try {
			$visibility = $this->github_visibility?->projection() ?? $visibility;
		} catch ( Throwable ) {
			$visibility['hidden'] = false;
			// Unavailable local evidence keeps ordinary GitHub access visible.
		}
		$status                      = $this->core_updates?->diagnostics() ?? array();
		$version                     = (string) ( get_file_data( dirname( __DIR__ ) . '/ran-booster.php', array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' );
		$status['installed_version'] = $version;
		$url                         = null;
		if ( 'enabled' === ( $status['effective_mode'] ?? null ) && 'active' === ( $status['updater_state'] ?? null )
			&& is_string( $status['offered_version'] ?? null ) && version_compare( $status['offered_version'], $version, '>' ) && current_user_can( 'update_plugins' ) ) {
			$url = wp_nonce_url( admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( plugin_basename( dirname( __DIR__ ) . '/ran-booster.php' ) ) ), 'upgrade-plugin_' . plugin_basename( dirname( __DIR__ ) . '/ran-booster.php' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed feedback flag conveys no private data and performs no mutation.
		$saved = '1' === ( $_GET['general_saved'] ?? null );
		return array(
			'general_settings'   => $projection,
			'general_token'      => $token,
			'general_feedback'   => $this->general_feedback ?? ( $saved ? __( 'Settings saved.', 'ran-booster' ) : null ),
			'general_errors'     => $this->general_errors,
			'general_attempted'  => $this->general_attempted,
			'github_visibility'  => $visibility,
			'core_update_status' => $status,
			'check_available'    => null !== $this->core_updates && $this->core_updates->can_check() && current_user_can( 'update_plugins' ),
			'native_update_url'  => $url,
		);
	}

	/** @param array<string,mixed> $request */
	public function post_save_general_settings( array $request ): bool {
		$preferences = $this->general_preferences ?? new \RAN\Settings\GeneralPreferenceStore();
		$values      = $request['preferences'] ?? null;
		$token       = $request['token'] ?? null;
		if ( ! is_array( $values ) || ! is_string( $token ) || null === $this->github_visibility ) {
			$this->general_feedback = __( 'Settings could not be saved. Reload the page and try again.', 'ran-booster' );
			return false;
		}
		$fields = $preferences->projection();
		foreach ( $fields as $key => $field ) {
			if ( ! $field['locked'] && is_string( $values[ $key ] ?? null ) ) {
				$this->general_attempted[ $key ] = substr( $values[ $key ], 0, 32 );
				if ( null === $preferences->parse_submission( array( $key => $values[ $key ] ), array( $key ) ) ) {
					$this->general_errors[ $key ] = __( 'Enter a valid value within the displayed limits.', 'ran-booster' );
				}
			}
		}
		$result = $preferences->save( $token, $values, $this->github_visibility->with_management_admission_lock( ... ), $this->github_visibility->github_hide_eligible( ... ) );
		if ( in_array( $result, array( 'saved', 'unchanged' ), true ) ) {
			// The live descriptor admission fence remains authoritative even if local offer removal fails.
			if ( ! \RAN\WordPress\CoreSelfUpdatePolicy::detect( dirname( __DIR__ ) . '/ran-booster.php', (string) ( get_file_data( dirname( __DIR__ ) . '/ran-booster.php', array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' ), true )->allows_native_discovery() ) {
				try {
					delete_site_transient( 'update_plugins' );
				} catch ( Throwable ) {
					$this->general_feedback = __( 'Settings saved; cached update offers could not be cleared. Discovery remains disabled.', 'ran-booster' );
					return false;
				}
			}
			return true;
		}
		$this->general_feedback = 'stale' === $result
			? __( 'Settings changed or GitHub eligibility changed. Review the current values and save again.', 'ran-booster' )
			: __( 'Settings were not saved. Check the fields or reload the page and try again.', 'ran-booster' );
		return false;
	}

	public function post_check_core_updates(): void {
		$result                 = $this->core_updates?->check() ?? array(
			'outcome' => 'failed',
			'code'    => 'unavailable',
		);
		$this->general_feedback = 'success' === $result['outcome']
			? __( 'The Booster update check completed.', 'ran-booster' )
			: __( 'The Booster update check did not complete successfully. Review the check status below.', 'ran-booster' );
	}

	private function storage_status(): ?SecretsStorageProvisioningResult {
		if ( null === $this->secrets_storage_result && null !== $this->secrets_storage ) {
			try {
				$this->secrets_storage_result = $this->secrets_storage->status();
				$this->log_secrets_storage_diagnostic( $this->secrets_storage_result );
			} catch ( Throwable ) {
				$this->secrets_storage_result = SecretsStorageProvisioningResult::unsupported(
					'storage_status_unavailable',
					__( 'Storage status is unavailable. No setup or recovery action has been performed.', 'ran-booster' )
				);
			}
		}
		return $this->secrets_storage_result;
	}

	/** @return array{status:string,reason_code:string,message:string,url:string}|null */
	public function storage_summary(): ?array {
		$result = $this->storage_status();
		return null === $result ? null : array(
			'status'      => $result->status(),
			'reason_code' => $result->code(),
			'message'     => $result->message(),
			'url'         => admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' ),
		);
	}

	/** Read-only configuration projection; never starts authentication or recovery.
	 *
	 * @return array{state:string,message:string,url?:string,details?:array<string,string>}
	 */
	public function storage_installation_projection(): array {
		$result     = $this->secrets_storage_result;
		$projection = array(
			'state'   => 'unknown',
			'message' => __( 'Storage health has not been checked in this request.', 'ran-booster' ),
		);
		if ( null !== $result ) {
			$projection['state'] = match ( $result->status() ) {
				'path_configured' => 'configured', 'storage_healthy' => 'healthy',
				'setup_available', 'storage_needs_attention', 'manual_required' => 'actionable',
				default => 'unknown',
			};
			$projection['message'] = $result->message();
			if ( 'actionable' === $projection['state'] ) {
				$projection['url'] = admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' );
			}
		}
		if ( current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' ) ) {
			foreach ( array( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' ) as $constant ) {
				if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
					$projection['details'] = array(
						'configuration'       => $constant,
						'configured_location' => constant( $constant ),
					);
					break;
				}
			}
		}
		return $projection;
	}

	/**
	 * @param list<array<string, mixed>> $extensions
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	public function get_extensions( array $extensions, string $plugins_url ) {
		return $this->render(
			'extensions',
			array(
				'extensions'  => $extensions,

				'plugins_url' => $plugins_url,
			)
		);
	}

	/**
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	private function render_package_page( PackagePagePresenter $package_view ) {
		$type = $package_view->get_type();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only recovery navigation; mutations verify exact confirmation nonces.
		if ( '1' === ( $_GET['recovery'] ?? null ) ) {
			return $this->render_recovery( $package_view );
		}
		( $this->package_admin ?? throw new \Error( 'The package administration controller is unavailable.' ) )->add_success_notice( $this, $type );
		$this->add_bulk_package_notice( $type );

		// Read-only package selection; malformed navigation never diagnoses damaged settings.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only package navigation; mutations verify their own action nonce.
		if ( isset( $_GET['package'] ) && is_string( $_GET['package'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only package navigation.
			$identifier = sanitize_text_field( wp_unslash( $_GET['package'] ) );
			try {
				new \RAN\Storage\PackageModel( array( 'package' => $identifier ) );
			} catch ( \InvalidArgumentException ) {
				$identifier = '';
			}
			$package = null;
			if ( '' !== $identifier ) {
				try {
					$package = 'plugin' === $type
						? $this->plugins->booster_plugin_from_file( $identifier )
						: $this->themes->booster_theme_from_stylesheet( $identifier );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A missing package intentionally falls back to the index.
				} catch ( PluginNotFound | ThemeNotFound ) {
					// An absent package returns to the matching list.
				} catch ( PackageStorageFailure | \InvalidArgumentException $failure ) {
					return $this->render_recovery( $package_view, $identifier, $failure );
				}
			}
			if ( null !== $package ) {
				try {
					$repository_branch_check_outcome               = $this->requested_package_repository_branch_check( $package, $type );
					$repository_branch_check_evidence              = null === $repository_branch_check_outcome
						? $this->provider_settings->package_repository_branch_evidence( $type, $package )
						: null;
					$edit_data                                     = $package_view->edit(
						$package,
						$this->provider_settings->build_existing_package_form( (string) ( $package->get_provider_code() ?? '' ) ),
						$this->provider_settings->build_package_branch_readiness( $package ),
						$this->requested_package_source_view(),
						$this->requested_advanced_settings_open()
					);
					$edit_data['repository_branch_check_outcome']  = $repository_branch_check_outcome;
					$edit_data['repository_branch_check_evidence'] = $repository_branch_check_evidence;
					return $this->render(
						'packages/edit',
						$edit_data
					);
				} catch ( PackageStorageFailure $failure ) {
					return $this->package_storage_failure_index( $package_view, $type, $failure );
				}
			}
		}

		try {
			$packages = 'plugin' === $type
				? $this->plugins->all_booster_plugins()
				: $this->themes->all_booster_themes();
		} catch ( PackageStorageFailure | \InvalidArgumentException $failure ) {
			return $this->package_storage_failure_index( $package_view, $type, $failure );
		}

		return $this->render( 'packages/index', $this->package_index_data( $packages, $package_view ) );
	}

	private function render_recovery( PackagePagePresenter $package_view, ?string $identifier = null, ?Throwable $failure = null ): mixed {
		$type = $package_view->get_type();
		if ( ! current_user_can( 'update_' . $type . 's' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage these packages.', 'ran-booster' ) );
		}
		if ( null === $failure ) {
			$this->package_admin?->add_success_notice( $this, $type );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only target selection; POST verifies the full confirmation nonce.
		$identifier ??= isset( $_GET['recovery_target'] ) && is_string( $_GET['recovery_target'] ) ? wp_unslash( $_GET['recovery_target'] ) : '';
		$name         = $identifier;
		$state        = 'unknown';
		$confirmation = null;
		$reason       = __( 'Open the package from Managed Plugins or Managed Themes. If its saved settings cannot be opened, Booster will explain the problem for that package.', 'ran-booster' );
		$blocker      = null;
		if ( '' !== $identifier ) {
			$state = 'blocked';
			try {
				new \RAN\Storage\PackageModel( array( 'package' => $identifier ) );
				if ( null === $failure ) {
					try {
						$package = 'plugin' === $type ? $this->plugins->booster_plugin_from_file( $identifier ) : $this->themes->booster_theme_from_stylesheet( $identifier );
						if ( ! $package instanceof Package ) {
							throw new LogicException( 'The management record is unavailable.' );
						}
					} catch ( PackageStorageFailure | \InvalidArgumentException $read_failure ) {
						$failure = $read_failure;
					}
				}
				if ( null === $failure ) {
					$state  = 'healthy';
					$name   = (string) $package->name;
					$reason = __( 'Booster can open this package’s saved management settings. Use its settings page to review its status or stop managing it.', 'ran-booster' );
				} else {
					$reason   = $this->package_settings_failure_reason( $failure );
					$removals = $this->removals ?? throw new LogicException( 'Recovery is unavailable.' );
					$targets  = $removals->recovery_targets( $type );
					if ( ! isset( $targets[ $identifier ] ) ) {
						throw new LogicException( 'The installed package is unavailable.' );
					}
					$name = $targets[ $identifier ];
					if ( $failure instanceof \InvalidArgumentException ) {
						try {
							$confirmation = $removals->recovery_confirmation( $type, $identifier );
							$state        = 'damaged';
						} catch ( Throwable $unsafe ) {
							$blocker = 'Unresolved deployment work prevents recovery.' === $unsafe->getMessage()
								? __( 'Queued, running or unresolved deployment work prevents disconnection. Review the package’s deployment activity before trying again.', 'ran-booster' )
								: __( 'Booster cannot verify the exact management record, installed files or operation state needed for safe disconnection. Resolve this uncertainty before trying again.', 'ran-booster' );
						}
					}
				}
			} catch ( Throwable $unsafe ) {
				$blocker = __( 'Booster cannot verify this installed package and its management record. No management changes have been made.', 'ran-booster' );
				if ( null === $failure ) {
					$reason = $blocker;
				}
			}
		}
		return $this->render(
			'packages/recovery',
			array(
				'package_view'          => $package_view,
				'recovery_identifier'   => $identifier,
				'recovery_name'         => $name,
				'recovery_state'        => $state,
				'recovery_reason'       => $reason,
				'recovery_blocker'      => $blocker,
				'recovery_confirmation' => $confirmation,
			)
		);
	}

	private function package_settings_failure_reason( Throwable $failure ): string {
		if ( $failure instanceof PackageStorageFailure ) {
			return $failure->getMessage();
		}
		return match ( $failure->getMessage() ) {
			'The managed package source revision is invalid.' => __( 'Booster’s saved update revision is invalid. It cannot safely use this package’s management settings.', 'ran-booster' ),
			'The managed package source is invalid.' => __( 'Booster’s saved update source is invalid.', 'ran-booster' ),
			'The deployment policy is not recognised.', 'A deployment policy must be a string.' => __( 'Booster’s saved deployment policy is invalid.', 'ran-booster' ),
			'The repository credential identity is invalid.' => __( 'Booster’s saved repository credential reference is invalid.', 'ran-booster' ),
			'The repository privacy setting is invalid.' => __( 'Booster’s saved repository privacy setting is invalid.', 'ran-booster' ),
			default => __( 'Booster’s saved management configuration for this package is invalid and cannot be opened safely.', 'ran-booster' ),
		};
	}

	/** @return 'verified'|'subdirectory_unavailable'|'subdirectory_unverified'|'unable_to_check'|'provider_unavailable'|null */
	private function requested_package_repository_branch_check( Package $package, string $type ): ?string {
		if ( ! isset( $_GET['ran_booster_repository_branch_check'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This request flag is followed by capability and action-specific nonce verification before the provider check.
			|| '1' !== (string) $_GET['ran_booster_repository_branch_check']
			|| ! current_user_can( 'manage_options' )
			|| ! isset( $_GET['_ran_booster_repository_branch_nonce'] )
			|| ! is_string( $_GET['_ran_booster_repository_branch_nonce'] )
		) {
			return null;
		}
		$action = PackageAdminController::repository_branch_check_action( $package, $type );
		$nonce  = wp_unslash( $_GET['_ran_booster_repository_branch_nonce'] );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return null;
		}
		$marker    = 'ran_booster_branch_check_' . hash(
			'sha256',
			get_current_user_id() . "\0" . $action . "\0" . $nonce . "\0"
			. $this->provider_settings->package_repository_branch_check_access_fingerprint( $package )
		);
		$completed = get_transient( $marker );
		if ( is_string( $completed ) && in_array( $completed, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified', 'unable_to_check', 'provider_unavailable' ), true ) ) {
			if ( 'verified' !== $completed || null !== $this->provider_settings->package_repository_branch_evidence( $type, $package ) ) {
				return $completed;
			}
		}

		$outcome = $this->provider_settings->check_package_repository_branch( $type, $package );
		set_transient( $marker, $outcome, 3600 );
		BoosterLogger::log(
			'repository branch check completed',
			array(
				'event'        => 'repository_branch_checked',
				'operation'    => 'repository_branch_check',
				'outcome_code' => $outcome,
				'package_slug' => (string) $package->get_slug(),
				'provider'     => (string) $package->get_provider_code(),
				'source'       => $package->get_source()->value,
				'step'         => 'package_branch_check',
			)
		);
		return $outcome;
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, mixed>
	 */
	private function package_index_data( array $packages, PackagePagePresenter $package_view ): array {
		return $package_view->index(
			$packages,
			$this->provider_settings->build_package_list(),
			$this->requested_package_list_state(),
			$this->deployment_admin
		);
	}

	/**
	 * Normalize read-only package-list filters without granting mutation authority.
	 *
	 * @return array{search: string, provider: string, source: string, policy: string}
	 */
	private function requested_package_list_state(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$provider = isset( $_GET['provider'] ) && is_string( $_GET['provider'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['provider'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$source = isset( $_GET['source'] ) && is_string( $_GET['source'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['source'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$policy = isset( $_GET['policy'] ) && is_string( $_GET['policy'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['policy'] ) )
			: '';

		return array(
			'search'   => substr( trim( $search ), 0, 100 ),
			'provider' => substr( $provider, 0, 64 ),
			'source'   => in_array( $source, array( PackageSource::BRANCH->value, PackageSource::RELEASE_ASSET->value ), true )
				? $source
				: '',
			'policy'   => in_array( $policy, array( DeploymentPolicy::AUTOMATIC->value, DeploymentPolicy::MANUAL->value, DeploymentPolicy::DISABLED->value ), true )
				? $policy
				: '',
		);
	}

	/** @return array<string, string> */
	private function package_list_query_arguments(): array {
		$state = $this->requested_package_list_state();

		return array_filter(
			array(
				's'        => $state['search'],
				'provider' => $state['provider'],
				'source'   => $state['source'],
				'policy'   => $state['policy'],
			),
			static fn ( string $value ): bool => '' !== $value
		);
	}

	private function requested_package_source_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
		$value = isset( $_GET['source_view'] ) && is_string( $_GET['source_view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
			? sanitize_key( wp_unslash( $_GET['source_view'] ) )
			: '';

		return in_array( $value, array( 'branch', 'release_asset' ), true ) ? $value : '';
	}

	private function requested_advanced_settings_open(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
		$value = isset( $_GET['ran_booster_open_advanced'] ) && is_string( $_GET['ran_booster_open_advanced'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
			? sanitize_key( wp_unslash( $_GET['ran_booster_open_advanced'] ) )
			: '';

		return '1' === $value || '' !== $this->requested_package_source_view();
	}

	private function render_package_create( PackagePagePresenter $package_view ): mixed {
		try {
			$this->db->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return $this->database_unavailable_create( $package_view, $package_view->get_type() );
		}
		$success = ( $this->package_admin ?? throw new \Error( 'The package administration controller is unavailable.' ) )->add_success_notice( $this, $package_view->get_type() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only installed-target prefill; normal adoption validates the target and nonce.
		$relink = isset( $_GET['relink'] ) && is_string( $_GET['relink'] ) ? wp_unslash( $_GET['relink'] ) : '';
		return $this->render(
			'packages/create',
			array_merge(
				array( 'recovery_identifier' => $relink ),
				$package_view->create(
					$this->provider_settings->build_package_form( $this->requested_provider() ),
					$this->has_requested_provider(),
					$this->requested_open_picker(),
					$this->requested_package_source_view(),
					in_array( $success['operation'] ?? null, array( 'install', 'already-managed' ), true ) ? $success['identifier'] : null,
					$this->requested_advanced_settings_open()
				)
			)
		);
	}

	/** Keep validation feedback only for this response, never in site storage. */
	public function set_credential_validation_feedback( string $provider, string $id, ?string $message, ?string $error ): void {
		$this->credential_validation_feedback = array(
			'provider' => $provider,
			'id'       => $id,
			'message'  => $message,
			'error'    => $error,
		);
	}

	/**
	 * @param mixed $message Notice input is classified defensively before storage.
	 * @return void
	 */
	public function add_message( $message ) {
		$this->record_message( $message );
	}

	/**
	 * @param mixed $message Notice input is classified defensively before storage.
	 * @param array<string, mixed> $context
	 */
	public function add_failure_message( $message, Throwable $failure, array $context = array() ): void {
		$this->record_message( $message, $failure, $context );
	}

	/**
	 * @param mixed $message Notice input is classified defensively before storage.
	 * @param array<string, mixed> $context
	 */
	private function add_message_with_context( $message, array $context ): void {
		$this->record_message( $message, null, $context );
	}

	/**
	 * @param mixed $message Notice input is classified defensively before storage.
	 * @param array<string, mixed> $context
	 */
	private function record_message( $message, ?Throwable $failure = null, array $context = array() ): void {
		if ( is_wp_error( $message ) ) {
			$message = array(
				'type'    => 'error',
				'code'    => $message->get_error_code(),
				'data'    => $message->get_error_data(),
				'message' => $message->get_error_message(),
			);
		} elseif ( is_string( $message ) ) {
			$message = array(
				'type'    => 'success',
				'message' => $message,
			);
		}
		$severity = $this->message_severity( $message );
		if ( null !== $severity ) {
			$context = array(
				'diagnostic_id' => $this->message_diagnostic_id( $message, $severity ),
				'event'         => 'admin_notice',
				'source'        => 'admin',
			) + $context;

			if ( null === $failure ) {
				BoosterLogger::log( 'admin ' . $severity . ' notice emitted', $context );
			} else {
				BoosterLogger::log_exception( 'admin ' . $severity . ' notice emitted', $failure, $context );
			}
		}

		$this->messages[] = $message;
	}

	/** @param array<string, mixed> $request */
	public function post_package_operation( string $action, array $request ): bool|string {
		$package_admin = $this->package_admin ?? new PackageAdminController();

		return $package_admin->perform(
			$this,
			$action,
			$request,
			$this->package_list_query_arguments(),
			function ( WP_Error|array $message, array $context ): void {
				$this->add_message_with_context( $message, $context );
			}
		);
	}

	private function package_storage_failure_index( PackagePagePresenter $package_view, string $type, PackageStorageFailure|\InvalidArgumentException $failure ): mixed {
		$this->add_failure_message(
			$failure instanceof PackageStorageFailure
				? $this->package_storage_error( $failure )
				: new WP_Error( 'ran_booster_package_settings_unreadable', __( 'Booster could not open a package’s saved management settings. No package changes were made.', 'ran-booster' ) ),
			$failure,
			array(
				'operation' => 'read-' . $type . '-packages',
				'step'      => 'package_storage',
			)
		);
		$packages = array();

		$data                             = $this->package_index_data( $packages, $package_view );
		$data['package_list_unavailable'] = true;
		return $this->render( 'packages/index', $data );
	}

	private function database_unavailable_create( PackagePagePresenter $package_view, string $type ): mixed {
		$failure = PackageStorageFailure::unsupported_database();
		$this->add_failure_message(
			$this->package_storage_error( $failure ),
			$failure,
			array(
				'operation' => 'create-' . $type . '-package',
				'step'      => 'database_compatibility',
			)
		);

		return $this->render(
			'packages/create',
			$package_view->unavailable_create(
				$this->provider_settings->build_package_form( $this->requested_provider() ),
				$this->has_requested_provider()
			)
		);
	}

	private function package_storage_error( PackageStorageFailure $failure ): WP_Error {
		return new WP_Error(
			$failure->get_diagnostic_id(),
			$failure->getMessage(),
			array( 'recovery_required' => $failure->is_recovery_required() )
		);
	}

	public function bulk_package_redirect( string $type, BulkPackageResult $result ): string {
		return ( $this->package_admin ?? throw new \Error( 'The package administration controller is unavailable.' ) )->bulk_redirect( $type, $result, $this->package_list_query_arguments() );
	}

	private function add_bulk_package_notice( string $type ): void {
		( $this->package_admin ?? throw new \Error( 'The package administration controller is unavailable.' ) )->add_bulk_notice(
			$this,
			$type,
			function ( array $message, array $context ): void {
				$this->add_message_with_context( $message, $context );
			}
		);
	}

	private function message_severity( mixed $message ): ?string {
		if ( $message instanceof WP_Error ) {
			return 'error';
		}
		if ( is_array( $message ) && in_array( $message['type'] ?? null, array( 'info', 'warning', 'error' ), true ) ) {
			return $message['type'];
		}

		return null;
	}

	private function message_diagnostic_id( mixed $message, string $severity ): string {
		$diagnostic_id = $message instanceof WP_Error
			? $message->get_error_code()
			: ( is_array( $message ) ? ( $message['code'] ?? '' ) : '' );

		return is_string( $diagnostic_id ) && preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $diagnostic_id ) === 1
			? $diagnostic_id
			: 'ran_booster_admin_' . $severity;
	}

	private function requested_provider(): ?string {
		// Read-only provider selection for package setup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider selection is string-checked and sanitized before building package setup.
		return isset( $_GET['provider'] ) && is_string( $_GET['provider'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitize the read-only provider selector; this value does not authorize package mutation.
			? sanitize_key( wp_unslash( $_GET['provider'] ) )
			: null;
	}

	private function has_requested_provider(): bool {
		// Read-only provider selection for package setup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test only whether the read-only provider selector is present and nonempty after sanitization.
		return isset( $_GET['provider'] ) && is_string( $_GET['provider'] ) && '' !== sanitize_key( wp_unslash( $_GET['provider'] ) );
	}

	private function requested_open_picker(): bool {
		// Read-only presentation state. Repository mutations still require their POST nonce.
		$posted_package = isset( $_POST['ran_booster'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only suppresses a presentation flag after any package form submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation flag.
		$open_picker = isset( $_GET['open_picker'] ) && is_scalar( $_GET['open_picker'] ) && '1' === (string) wp_unslash( $_GET['open_picker'] );

		return ! $posted_package && $open_picker;
	}

	private function requested_troubleshooting_panel(): string {
		if ( null !== $this->support_report_payload ) {
			return 'support-report';
		}
		if ( null !== $this->troubleshooting_payload ) {
			return 'diagnostics';
		}
		// Read-only allowlisted routing state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only panel selection is sanitized and restricted to the fixed diagnostics, debug-capture and activity routes.
		$panel = isset( $_GET['panel'] ) && is_string( $_GET['panel'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitize the panel before the fixed route allowlist; no mutation is performed.
			? sanitize_key( wp_unslash( $_GET['panel'] ) )
			: 'installation';

		if ( 'deployment-activity' === $panel ) {
			return 'activity';
		}

		return in_array( $panel, array( 'installation', 'diagnostics', 'debug-capture', 'activity', 'support-report' ), true ) ? $panel : 'installation';
	}

	private function requested_provider_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
			? sanitize_key( wp_unslash( $_GET['view'] ) )
			: '';

		return in_array( $view, array( 'credentials', 'secrets' ), true ) ? $view : 'overview';
	}

	private function requested_provider_task(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
		$task = isset( $_GET['panel'] ) && is_string( $_GET['panel'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
			? sanitize_key( wp_unslash( $_GET['panel'] ) )
			: '';

		return in_array( $task, array( 'repositories', 'setup' ), true ) ? $task : 'status';
	}

	private function requested_provider_repository_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded repository presentation selector.
		$view = isset( $_GET['repository_view'] ) && is_string( $_GET['repository_view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded repository presentation selector.
			? sanitize_key( wp_unslash( $_GET['repository_view'] ) )
			: '';

		return in_array( $view, array( 'status', 'branch', 'releases' ), true ) ? $view : 'status';
	}

	private function requested_provider_repository_id( string $key = 'repository' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$value = $_GET[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			return '';
		}

		$candidate = trim( wp_unslash( $value ) );

		return '' !== $candidate
			&& strlen( $candidate ) <= 191
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $candidate )
			? $candidate
			: '';
	}

	/**
	 * Normalize the focused provider-list query without granting mutation authority.
	 *
	 * @return array{
	 *   search: string,
	 *   kind: string,
	 *   scope: string,
	 *   status: string,
	 *   orderby: string,
	 *   order: string,
	 *   paged: int,
	 *   per_page: int
	 * }
	 */
	private function requested_provider_list_state(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$kind = isset( $_GET['kind'] ) && is_string( $_GET['kind'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['kind'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$scope = isset( $_GET['scope'] ) && is_string( $_GET['scope'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['scope'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$status = isset( $_GET['status'] ) && is_string( $_GET['status'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'name';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$order = isset( $_GET['order'] ) && is_string( $_GET['order'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
			? sanitize_key( wp_unslash( $_GET['order'] ) )
			: 'asc';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded list/navigation state does not authorize a mutation.
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;

		return array(
			'search'   => substr( trim( $search ), 0, 100 ),
			'kind'     => $kind,
			'scope'    => $scope,
			'status'   => $status,
			'orderby'  => in_array( $orderby, array( 'name', 'kind', 'scope', 'usage', 'health' ), true )
				? $orderby
				: 'name',
			'order'    => 'desc' === $order ? 'desc' : 'asc',
			'paged'    => max( 1, $paged ),
			'per_page' => in_array( $per_page, array( 20, 50 ), true ) ? $per_page : 20,
		);
	}

	/** @return array<string, mixed> */
	private function debug_capture_payload(): array {
		$fallback = array(
			'state'         => 'unavailable',
			'filename'      => 'ran-booster-debug.php',
			'capture_until' => '',
			'delete_after'  => '',
			'content'       => '',
		);
		if ( null === $this->debug_capture ) {
			return $fallback;
		}

		try {
			$snapshot = $this->debug_capture->snapshot();
		} catch ( Throwable $failure ) {
			BoosterLogger::log_exception(
				'debug capture snapshot unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'debug_capture_snapshot',
				)
			);
			return $fallback;
		}

		$lines = array();
		foreach ( $snapshot['entries'] as $entry ) {
			$lines[] = $entry['at'] . ' ' . $entry['line'];
		}

		return array(
			'state'         => $snapshot['state'],
			'filename'      => $snapshot['filename'],
			'capture_until' => $snapshot['active_until'] ?? '',
			'delete_after'  => $snapshot['expires_at'] ?? '',
			'content'       => implode( "\n", $lines ),
		);
	}

	/**
	 * @return list<array{key: string, label: string, url: string, active: bool, provider: bool}>
	 */
	private function tab_navigation( string $selected_key ): array {
		if ( null === $this->admin_tabs ) {
			return array();
		}

		$admin_url = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$tabs      = array();

		$hidden_provider_key = null;
		try {
			$hidden_provider_key = $this->github_visibility?->hidden_navigation_key();
		} catch ( Throwable ) {
			$hidden_provider_key = null;
		}
		foreach ( $this->admin_tabs->all() as $tab ) {
			if ( null !== $hidden_provider_key && $hidden_provider_key === $tab->get_key() ) {
				continue;
			}
			$tab_key = $tab->get_key();
			$tabs[]  = array(
				'key'      => $tab_key,
				'label'    => $tab->get_label(),
				'url'      => 'portability' === $tab_key
					? $admin_url . '?page=ran-booster-transporter'
					: $admin_url . '?page=ran-booster&tab=' . rawurlencode( $tab_key ),
				'active'   => $selected_key === $tab_key,
				'provider' => $tab->is_provider(),
			);
		}
		if ( null !== $this->admin_add_ons ) {
			foreach ( $this->admin_add_ons->all() as $tab ) {
				$tabs[] = array(
					'key'      => $tab->key(),
					'label'    => $tab->label(),
					'url'      => $admin_url . '?page=ran-booster&tab=' . rawurlencode( $tab->key() ),
					'active'   => $selected_key === $tab->key(),
					'provider' => false,
				);
			}
		}
		return $tabs;
	}

	/**
	 * @param string $view
	 * @param array<string, mixed> $data
	 * @return mixed The included template result, or an overridden renderer result.
	 */
	protected function render( $view, $data = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ran-booster' ) );
		}

		$development_environment_detected = DevelopmentEnvironmentDetector::is_likely();

		$data['development_environment_detected'] = $development_environment_detected;

		$data['development_safety_notice'] = $this->should_show_development_safety_notice( $view, $development_environment_detected );
		$data['messages']                  = $this->messages;
		$data['name']                      = $this->booster->get_name();

		$data['core_self_update_development_notice'] = $this->core_self_update_development_notice;
		if ( ! isset( $data['tabs'] ) ) {
			$data['tabs'] = $this->tab_navigation( '' );
		}

		// Internal controllers provide a fixed set of view locals; no request keys reach extract().
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Internal controllers supply the fixed view-local keys after the manage_options capability check; request keys are not extracted.
		extract( $data );

		return include __DIR__ . '/../views/base.php';
	}

	private function should_show_development_safety_notice( string $view, bool $development_environment_detected ): bool {
		$relevant_view = 'packages/index' === $view;
		$user_id       = get_current_user_id();
		$dismissed     = $user_id > 0
			&& '1' === get_user_meta( $user_id, DevelopmentSafetyNoticeController::USER_META_KEY, true );

		return $relevant_view && ! $dismissed && $development_environment_detected;
	}
}

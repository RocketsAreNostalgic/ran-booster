<?php

namespace RAN;

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
use RAN\Portability\BlueprintPackage;
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
	public $messages = array();

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

	private ProviderSettingsPresenter $provider_settings;
	private TroubleshootingService $troubleshooting;
	private ?array $troubleshooting_payload = null;

	private ?AdminTabRegistry $admin_tabs;
	private ?AdminAddOnRegistry $admin_add_ons;
	private ?RepositoryWebhookManagementControls $webhook_management;
	private ?ReleaseWorkflowControls $release_workflow;
	private ?CoreSelfUpdateDevelopmentNotice $core_self_update_development_notice;

	private ?ProviderDocumentationPresenter $provider_documentation;
	private ?PackageAdminController $package_admin = null;
	private DeploymentAdminPresenter $deployment_admin;
	private PackagePagePresenter $plugin_pages;
	private PackagePagePresenter $theme_pages;
	private ?TemporaryDebugCapture $debug_capture                     = null;
	private ?SecretsStorageProvisioner $secrets_storage               = null;
	private ?SecretsStorageProvisioningResult $secrets_storage_result = null;

	/**
	 * @param Database $db
	 * @param PluginRepository $plugins
	 * @param Booster $booster
	 * @param ThemeRepository               $themes           Theme packages.
	 * @param ProviderSettingsPresenter $providerSettings Provider settings presenter.
	 * @param TroubleshootingService    $troubleshooting Bounded same-request diagnostics.
	 * @param AdminTabRegistry|null      $adminTabs        Allowlisted admin navigation.
	 * @param ProviderDocumentationPresenter|null $providerDocumentation Display-safe provider documentation.
	 * @param PackageOperationService|null      $packageOperations Explicit package-operation boundary.
	 * @param DeploymentAttemptRepository|null $deploymentAttempts    Bounded operator history reads.
	 * @param TemporaryDebugCapture|null        $debugCapture          Bounded Booster-only event capture.
	 * @param AdminAddOnRegistry|null            $adminAddOns           Registered public add-on tabs.
	 * @param CoreSelfUpdateDevelopmentNotice|null $coreSelfUpdateDevelopmentNotice Core-owned source-checkout notice.
	 */
	public function __construct(
		Database $db,
		PluginRepository $plugins,
		Booster $booster,
		ThemeRepository $themes,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		ProviderSettingsPresenter $providerSettings,
		TroubleshootingService $troubleshooting,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?AdminTabRegistry $adminTabs = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?ProviderDocumentationPresenter $providerDocumentation = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?PackageOperationService $packageOperations = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?DeploymentAttemptRepository $deploymentAttempts = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?TemporaryDebugCapture $debugCapture = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?SecretsStorageProvisioner $secretsStorage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?AdminAddOnRegistry $adminAddOns = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?RepositoryWebhookManagementControls $webhookManagement = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?CoreSelfUpdateDevelopmentNotice $coreSelfUpdateDevelopmentNotice = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?ReleaseWorkflowControls $releaseWorkflow = null
	) {
		$this->db      = $db;
		$this->plugins = $plugins;
		$this->booster = $booster;
		$this->themes  = $themes;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->provider_settings = $providerSettings;
		$this->troubleshooting   = $troubleshooting;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->admin_tabs = $adminTabs;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->provider_documentation = $providerDocumentation;
		$this->deployment_admin       = new DeploymentAdminPresenter(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			attempts: $deploymentAttempts,
			plugins: $plugins,
			themes: $themes
		);
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->package_admin = new PackageAdminController( $packageOperations, deployments: $this->deployment_admin );
		$this->plugin_pages  = PackagePagePresenter::plugin();
		$this->theme_pages   = PackagePagePresenter::theme();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->debug_capture = $debugCapture;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->secrets_storage = $secretsStorage;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->admin_add_ons = $adminAddOns;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->webhook_management = $webhookManagement;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->release_workflow = $releaseWorkflow;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->core_self_update_development_notice = $coreSelfUpdateDevelopmentNotice;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public callback and caller contract. Retain the public named-parameter contract.
	public function getIndex( ?string $forcedTab = null ) {
		if ( null === $this->admin_tabs ) {
			throw new LogicException( 'Booster admin tabs are not configured.' );
		}

		$requested_tab = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted navigation state.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( null !== $forcedTab && '' !== $forcedTab ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$requested_tab = $forcedTab;
		} elseif ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
			// Read-only navigation state; no action is performed from this query value.
			$requested_tab = wp_unslash( $_GET['tab'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$requested_add_on_key = is_string( $requested_tab ) ? strtolower( trim( $requested_tab ) ) : '';
		$selected_add_on      = '' === $requested_add_on_key || null === $this->admin_add_ons
			? null
			: $this->admin_add_ons->get( $requested_add_on_key );
		$selected_tab         = null === $selected_add_on ? $this->admin_tabs->resolve( $requested_tab ) : null;
		$selected_key         = null === $selected_add_on ? $selected_tab->getKey() : $selected_add_on->key();
		$tabs                 = $this->tab_navigation( $selected_key );
		$admin_url            = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$data                 = array(
			'tab'  => $selected_key,
			'tabs' => $tabs,
		);
		if ( null !== $selected_add_on && null !== $this->admin_add_ons ) {
			$data['addOnTab']     = $selected_add_on;
			$data['addOnContext'] = $this->admin_add_ons->contextFor(
				$selected_add_on,
				$admin_url . '?page=ran-booster&tab=' . rawurlencode( $selected_add_on->key() ),
				is_multisite() ? 'network' : 'site'
			);
		} else {
			$data['tabView'] = $selected_tab->getView();
		}

		if ( null !== $selected_tab && 'overview' === $selected_tab->getKey() ) {
			$data['onboarding'] = ( new OnboardingPresenter() )->build(
				$tabs,
				$admin_url . '?page=ran-booster-plugins-create',
				$admin_url . '?page=ran-booster-themes-create'
			);
			if ( null !== $this->secrets_storage ) {
				$include_storage_details = current_user_can( 'manage_options' )
					&& current_user_can( 'activate_plugins' );
				$wordpress_root          = defined( 'ABSPATH' ) && is_string( ABSPATH )
					? ABSPATH
					: '';
				$result                  = $this->secrets_storage_result ?? $this->secrets_storage->status();
				$this->log_secrets_storage_diagnostic( $result );
				$recovery                              = $include_storage_details
					? $this->secrets_storage->recoveryState( $result )
					: null;
				$data['onboarding']['secrets_storage'] = ( new SecretsStorageSetupPresenter() )->build(
					$result,
					$admin_url . '?page=ran-booster&tab=overview',
					$wordpress_root,
					$include_storage_details,
					$recovery
				);
			}
		}

		if ( null !== $selected_tab && $selected_tab->isProvider() ) {
			$provider                  = $selected_tab->getProvider();
			$data                      = array_merge(
				$data,
				$this->provider_settings->build( null === $provider ? null : $provider->value )
			);
			$data['providerView']      = $this->requested_provider_view();
			$data['providerTask']      = $this->requested_provider_task();
			$data['repositoryView']    = $this->requested_provider_repository_view();
			$data['providerListState'] = $this->requested_provider_list_state();

			$data['requestedRepositoryId']           = $this->requested_provider_repository_id();
			$data                                    = array_merge( $data, ( new ProviderRepositoryRowsNormalizer() )->projectPage( $data, $this->webhook_management, $this->release_workflow ) );
			$data                                    = array_merge( $data, $this->provider_settings->buildProfileListProjection( $data ) );
			$data['webhookManagement']               = $this->webhook_management;
			$data['releaseWorkflow']                 = $this->release_workflow;
			$data['statusSummaryRenderer']           = new AdminStatusSummaryRenderer();
			$data['providerManagementTableRenderer'] = new ProviderManagementTableRenderer();
			$data['repositoryDetailRenderer']        = new RepositoryDetailRenderer();
			$data['repositoryTableRenderer']         = new RepositoryTableRenderer();
		} elseif ( null !== $selected_tab && 'portability' === $selected_tab->getKey() ) {
			try {
				$export                               = $this->portability_export_data();
				$data['portabilityExportRows']        = $export['rows'];
				$data['portabilityExportUnavailable'] = false;
				try {
					$data['portabilityExportCredentialGroups']       = $this->provider_settings->buildPortabilityCredentials( $export['credentials'] );
					$data['portabilityExportCredentialsUnavailable'] = false;
				} catch ( Throwable $failure ) {
					BoosterLogger::logException(
						'portability export credentials unavailable',
						$failure,
						array(
							'source' => 'admin',
							'step'   => 'portability_export_credentials',
						)
					);
					$data['portabilityExportCredentialGroups']       = array();
					$data['portabilityExportCredentialsUnavailable'] = true;
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
					'portability export rows unavailable',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'portability_export_rows',
					)
				);
				$data['portabilityExportRows']                   = array();
				$data['portabilityExportUnavailable']            = true;
				$data['portabilityExportCredentialGroups']       = array();
				$data['portabilityExportCredentialsUnavailable'] = false;
			}
		} elseif ( null !== $selected_tab && 'documentation' === $selected_tab->getKey() ) {
			$data['providerDocumentation'] = null === $this->provider_documentation
				? array()
				: $this->provider_documentation->build();
			$data['documentationUrl']      = $admin_url . '?page=ran-booster&tab=documentation';
			$data['documentationScope']    = is_multisite() ? 'network' : 'site';
		} elseif ( null !== $selected_tab && 'troubleshooting' === $selected_tab->getKey() ) {
			$panel                        = $this->requested_troubleshooting_panel();
			$data['troubleshootingPanel'] = $panel;
			if ( 'debug-capture' === $panel ) {
				$data['troubleshooting'] = array();
				$data['debugCapture']    = $this->debug_capture_payload();
			} elseif ( 'activity' === $panel ) {
				$data['troubleshooting']    = array();
				$data['deploymentActivity'] = $this->deployment_admin->activity();
			} else {
				$data['troubleshooting'] = $this->troubleshooting_payload ?? $this->troubleshooting->formPayload();
			}
		}

		return $this->render( 'index', $data );
	}

	/** Render the native sidebar route through the canonical Transporter tab. */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function getTransporter() {
		return $this->getIndex( 'portability' );
	}

	/**
	 * Render the one Core-owned region affected by a public repository lookup
	 * preference mutation. The Dispatcher may return this fragment only after it
	 * has performed the action's ordinary capability and nonce checks.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public callback and caller contract. Retain the public named-parameter contract.
	public function renderPublicLookupProfileRegion( string $providerCode, ?string $error = null ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$settings                             = $this->provider_settings->build( $providerCode );
		$settings['publicLookupProfileError'] = $error;

		ob_start();
		// Internal controllers provide the fixed presentation payload. No request
		// input reaches extract().
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $settings );
		require __DIR__ . '/../views/provider-public-lookup-profile.php';

		return (string) ob_get_clean();
	}

	/**
	 * Render the one Core-owned logging region affected by an explicit capture
	 * start or stop. The Dispatcher calls this only after capability and nonce
	 * checks; an error remains in this persistent local region.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function renderDebugCaptureRegion( ?string $error = null ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$debugCapture = $this->debug_capture_payload();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$debugCaptureError = $error;

		ob_start();
		require __DIR__ . '/../views/debug-capture.php';

		return (string) ob_get_clean();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function setSecretsStorageProvisioningResult( SecretsStorageProvisioningResult $result ): void {
		$this->secrets_storage_result = $result;
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
				'step'          => 'overview_status',
			)
		);
	}

	/** @return array{rows:list<array{name:string,identifier:string,type:string}>,credentials:array<string,array<string,list<array{index:int,name:string,type:string}>>>} */
	private function portability_export_data(): array {
		$rows        = array();
		$credentials = array();
		foreach ( array(
			'plugin' => $this->plugins->allDeploymentPlugins(),
			'theme'  => $this->themes->allDeploymentThemes(),
		) as $type => $packages ) {
			foreach ( $packages as $package ) {
				if ( ! $package instanceof Package ) {
					throw new \UnexpectedValueException();
				}
				$blueprint     = BlueprintPackage::fromManagedPackage( $type, $package );
				$index         = count( $rows );
				$rows[]        = array(
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					'name'       => $blueprint->displayName,
					'identifier' => $blueprint->identifier,
					'type'       => $blueprint->type,
				);
				$credential_id = $package->get_credential_id();
				if ( '' !== $blueprint->provider && '' !== $credential_id ) {
					$credentials[ $blueprint->provider ][ $credential_id ][] = array(
						'index' => $index,
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
						'name'  => $blueprint->displayName,
						'type'  => $blueprint->type,
					);
				}
			}
		}

		return compact( 'rows', 'credentials' );
	}

	/** @param array{provider: string, credential_id?: string|null, repository?: string|null} $request */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function postRunTroubleshooting( array $request ): void {
		$this->troubleshooting_payload = $this->troubleshooting->diagnose(
			$request['provider'],
			$request['credential_id'] ?? null,
			$request['repository'] ?? null
		);
	}

	/** Render the Core-owned Diagnostics panel after an explicit HTMX request. */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function renderTroubleshootingDiagnosticsRegion(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$troubleshootingPanel = 'diagnostics';
		$troubleshooting      = $this->troubleshooting_payload ?? $this->troubleshooting->formPayload();

		ob_start();
		require __DIR__ . '/../views/troubleshooting.php';

		return (string) ob_get_clean();
	}

	/** Whether the most recent diagnostics result completed without a warning or failure. */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function troubleshootingSucceeded(): bool {
		$results = $this->troubleshooting_payload['results'] ?? null;
		if ( ! is_array( $results ) || array() === $results ) {
			return false;
		}

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || 'pass' !== ( $result['status'] ?? null ) ) {
				return false;
			}
		}

		return empty( $this->troubleshooting_payload['partial'] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function getPlugins() {
		return $this->render_package_page( $this->plugin_pages );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function getPluginsCreate() {
		return $this->render_package_create( $this->plugin_pages );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function getThemes() {
		return $this->render_package_page( $this->theme_pages );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function getThemesCreate() {
		return $this->render_package_create( $this->theme_pages );
	}

	/** @param list<array<string, mixed>> $extensions */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public callback and caller contract. Retain the public named-parameter contract.
	public function getExtensions( array $extensions, string $pluginsUrl ) {
		return $this->render(
			'extensions',
			array(
				'extensions' => $extensions,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'pluginsUrl' => $pluginsUrl,
			)
		);
	}

	private function render_package_page( PackagePagePresenter $package_view ) {
		$type = $package_view->getType();
		$this->package_admin->addSuccessNotice( $this, $type );
		$this->add_bulk_package_notice( $type );

		// Read-only package selection; mutations use separately nonce-protected forms.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['package'] ) ) {
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only package selection.
				$identifier                                 = sanitize_text_field( wp_unslash( $_GET['package'] ) );
				$package                                    = 'plugin' === $type
					? $this->plugins->boosterPluginFromFile( $identifier )
					: $this->themes->boosterThemeFromStylesheet( $identifier );
				$repository_branch_check_outcome            = $this->requested_package_repository_branch_check( $package, $type );
				$repository_branch_check_evidence           = null === $repository_branch_check_outcome
					? $this->provider_settings->packageRepositoryBranchEvidence( $type, $package )
					: null;
				$edit_data                                  = $package_view->edit(
					$package,
					$this->provider_settings->buildExistingPackageForm( (string) ( $package->get_provider_code() ?? '' ) ),
					$this->provider_settings->buildPackageBranchReadiness( $package ),
					$this->requested_package_source_view(),
					$this->requested_advanced_settings_open()
				);
				$edit_data['repositoryBranchCheckOutcome']  = $repository_branch_check_outcome;
				$edit_data['repositoryBranchCheckEvidence'] = $repository_branch_check_evidence;
				return $this->render(
					'packages/edit',
					$edit_data
				);
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A missing package intentionally falls back to the index.
			} catch ( PluginNotFound | ThemeNotFound $missing ) {
				// The selected package is absent, so show the matching index instead.
			} catch ( PackageStorageFailure $failure ) {
				return $this->package_storage_failure_index( $package_view, $type, $failure );
			}
		}

		try {
			$packages = 'plugin' === $type
				? $this->plugins->allBoosterPlugins()
				: $this->themes->allBoosterThemes();
		} catch ( PackageStorageFailure $failure ) {
			return $this->package_storage_failure_index( $package_view, $type, $failure );
		}

		return $this->render( 'packages/index', $this->package_index_data( $packages, $package_view ) );
	}

	/** @return 'verified'|'subdirectory_unavailable'|'subdirectory_unverified'|'unable_to_check'|'provider_unavailable'|null */
	private function requested_package_repository_branch_check( Package $package, string $type ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This boundary verifies the action-specific nonce below.
		if ( ! isset( $_GET['ran_booster_repository_branch_check'] )
			|| '1' !== (string) $_GET['ran_booster_repository_branch_check']
			|| ! current_user_can( 'manage_options' )
			|| ! isset( $_GET['_ran_booster_repository_branch_nonce'] )
			|| ! is_string( $_GET['_ran_booster_repository_branch_nonce'] )
		) {
			return null;
		}
		$action = PackageAdminController::repositoryBranchCheckAction( $package, $type );
		$nonce  = wp_unslash( $_GET['_ran_booster_repository_branch_nonce'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return null;
		}
		$marker = 'ran_booster_branch_check_' . hash(
			'sha256',
			get_current_user_id() . "\0" . $action . "\0" . $nonce . "\0"
			. $this->provider_settings->packageRepositoryBranchCheckAccessFingerprint( $package )
		);
		if ( function_exists( __NAMESPACE__ . '\\get_transient' ) || function_exists( 'get_transient' ) ) {
			$completed = get_transient( $marker );
			if ( is_string( $completed ) && in_array( $completed, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified', 'unable_to_check', 'provider_unavailable' ), true ) ) {
				if ( 'verified' !== $completed || null !== $this->provider_settings->packageRepositoryBranchEvidence( $type, $package ) ) {
					return $completed;
				}
			}
		}

		$outcome = $this->provider_settings->checkPackageRepositoryBranch( $type, $package );
		if ( function_exists( __NAMESPACE__ . '\\set_transient' ) || function_exists( 'set_transient' ) ) {
			set_transient( $marker, $outcome, 3600 );
		}
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
			$this->provider_settings->buildPackageList(),
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only package list filtering.
		$search   = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$provider = isset( $_GET['provider'] ) && is_string( $_GET['provider'] )
			? sanitize_key( wp_unslash( $_GET['provider'] ) )
			: '';
		$source   = isset( $_GET['source'] ) && is_string( $_GET['source'] )
			? sanitize_key( wp_unslash( $_GET['source'] ) )
			: '';
		$policy   = isset( $_GET['policy'] ) && is_string( $_GET['policy'] )
			? sanitize_key( wp_unslash( $_GET['policy'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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
			$this->db->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return $this->database_unavailable_create( $package_view, $package_view->getType() );
		}
		$success = $this->package_admin->addSuccessNotice( $this, $package_view->getType() );

		return $this->render(
			'packages/create',
			$package_view->create(
				$this->provider_settings->buildPackageForm( $this->requested_provider() ),
				$this->has_requested_provider(),
				$this->requested_open_picker(),
				$this->requested_package_source_view(),
				in_array( $success['operation'] ?? null, array( 'install', 'already-managed' ), true ) ? $success['identifier'] : null,
				$this->requested_advanced_settings_open()
			)
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function addMessage( $message ) {
		$this->record_message( $message );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function addFailureMessage( $message, Throwable $failure, array $context = array() ): void {
		$this->record_message( $message, $failure, $context );
	}

	private function add_message_with_context( $message, array $context ): void {
		$this->record_message( $message, null, $context );
	}

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
				BoosterLogger::logException( 'admin ' . $severity . ' notice emitted', $failure, $context );
			}
		}

		$this->messages[] = $message;
	}

	/** @param array<string, mixed> $request */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function postPackageOperation( string $action, array $request ): bool|string {
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

	/** @return array{operation: string, identifier: string}|null */
	private function add_package_success_notice( string $type ): ?array {
		return $this->package_admin->addSuccessNotice( $this, $type );
	}

	private function package_storage_failure_index( PackagePagePresenter $package_view, string $type, PackageStorageFailure $failure ): mixed {
		$this->addFailureMessage(
			$this->package_storage_error( $failure ),
			$failure,
			array(
				'operation' => 'read-' . $type . '-packages',
				'step'      => 'package_storage',
			)
		);
		$packages = array();

		return $this->render( 'packages/index', $this->package_index_data( $packages, $package_view ) );
	}

	private function database_unavailable_create( PackagePagePresenter $package_view, string $type ): mixed {
		$failure = PackageStorageFailure::unsupported_database();
		$this->addFailureMessage(
			$this->package_storage_error( $failure ),
			$failure,
			array(
				'operation' => 'create-' . $type . '-package',
				'step'      => 'database_compatibility',
			)
		);

		return $this->render(
			'packages/create',
			$package_view->unavailableCreate(
				$this->provider_settings->buildPackageForm( $this->requested_provider() ),
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the public callback and caller contract.
	public function bulkPackageRedirect( string $type, BulkPackageResult $result ): string {
		return $this->package_admin->bulkRedirect( $type, $result, $this->package_list_query_arguments() );
	}

	private function add_bulk_package_notice( string $type ): void {
		$this->package_admin->addBulkNotice(
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['provider'] ) && is_string( $_GET['provider'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['provider'] ) )
			: null;
	}

	private function has_requested_provider(): bool {
		// Read-only provider selection for package setup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		// Read-only allowlisted routing state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$panel = isset( $_GET['panel'] ) && is_string( $_GET['panel'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['panel'] ) )
			: 'diagnostics';

		if ( 'deployment-activity' === $panel ) {
			return 'activity';
		}

		return in_array( $panel, array( 'diagnostics', 'debug-capture', 'activity' ), true ) ? $panel : 'diagnostics';
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

	private function requested_provider_repository_id(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only bounded repository selection.
		$value = $_GET['repository'] ?? null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filtering and pagination.
		$search   = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$kind     = isset( $_GET['kind'] ) && is_string( $_GET['kind'] )
			? sanitize_key( wp_unslash( $_GET['kind'] ) )
			: '';
		$scope    = isset( $_GET['scope'] ) && is_string( $_GET['scope'] )
			? sanitize_key( wp_unslash( $_GET['scope'] ) )
			: '';
		$status   = isset( $_GET['status'] ) && is_string( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';
		$orderby  = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'name';
		$order    = isset( $_GET['order'] ) && is_string( $_GET['order'] )
			? sanitize_key( wp_unslash( $_GET['order'] ) )
			: 'asc';
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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
			BoosterLogger::logException(
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

		foreach ( $this->admin_tabs->all() as $tab ) {
			$tab_key = $tab->getKey();
			$tabs[]  = array(
				'key'      => $tab_key,
				'label'    => $tab->getLabel(),
				'url'      => 'portability' === $tab_key
					? $admin_url . '?page=ran-booster-transporter'
					: $admin_url . '?page=ran-booster&tab=' . rawurlencode( $tab_key ),
				'active'   => $selected_key === $tab_key,
				'provider' => $tab->isProvider(),
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

	protected function render( $view, $data = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ran-booster' ) );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$developmentEnvironmentDetected = DevelopmentEnvironmentDetector::is_likely();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$data['developmentEnvironmentDetected'] = $developmentEnvironmentDetected;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the include-scope view input contract.
		$data['developmentSafetyNotice'] = $this->should_show_development_safety_notice( $view, $data, $developmentEnvironmentDetected );
		$data['messages']                = $this->messages;
		$data['name']                    = $this->booster->getName();

		$data['coreSelfUpdateDevelopmentNotice'] = $this->core_self_update_development_notice;
		if ( ! isset( $data['tabs'] ) ) {
			$data['tabs'] = $this->tab_navigation( '' );
		}

		// Internal controllers provide a fixed set of view locals; no request keys reach extract().
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data );

		return include __DIR__ . '/../views/base.php';
	}

	/** @param array<string, mixed> $data */
	private function should_show_development_safety_notice( string $view, array $data, bool $development_environment_detected ): bool {
		$relevant_view = 'packages/index' === $view;
		$user_id       = get_current_user_id();
		$dismissed     = $user_id > 0
			&& '1' === get_user_meta( $user_id, DevelopmentSafetyNoticeController::USER_META_KEY, true );

		return $relevant_view && ! $dismissed && $development_environment_detected;
	}
}

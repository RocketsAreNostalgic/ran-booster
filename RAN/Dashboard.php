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
use RAN\Admin\Component\RepositoryTableRenderer;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
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

	private ProviderSettingsPresenter $providerSettings;
	private TroubleshootingService $troubleshooting;
	private ?array $troubleshootingPayload = null;

	private ?AdminTabRegistry $adminTabs;
	private ?AdminAddOnRegistry $adminAddOns;
	private ?RepositoryWebhookManagementControls $webhookManagement;
	private ?CoreSelfUpdateDevelopmentNotice $coreSelfUpdateDevelopmentNotice;

	private ?ProviderDocumentationPresenter $providerDocumentation;
	private ?PackageAdminController $packageAdmin = null;
	private DeploymentAdminPresenter $deploymentAdmin;
	private PackagePagePresenter $pluginPages;
	private PackagePagePresenter $themePages;
	private ?TemporaryDebugCapture $debugCapture                    = null;
	private ?SecretsStorageProvisioner $secretsStorage              = null;
	private ?SecretsStorageProvisioningResult $secretsStorageResult = null;

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
		ProviderSettingsPresenter $providerSettings,
		TroubleshootingService $troubleshooting,
		?AdminTabRegistry $adminTabs = null,
		?ProviderDocumentationPresenter $providerDocumentation = null,
		?PackageOperationService $packageOperations = null,
		?DeploymentAttemptRepository $deploymentAttempts = null,
		?TemporaryDebugCapture $debugCapture = null,
		?SecretsStorageProvisioner $secretsStorage = null,
		?AdminAddOnRegistry $adminAddOns = null,
		?RepositoryWebhookManagementControls $webhookManagement = null,
		?CoreSelfUpdateDevelopmentNotice $coreSelfUpdateDevelopmentNotice = null
	) {
		$this->db                    = $db;
		$this->plugins               = $plugins;
		$this->booster               = $booster;
		$this->themes                = $themes;
		$this->providerSettings      = $providerSettings;
		$this->troubleshooting       = $troubleshooting;
		$this->adminTabs             = $adminTabs;
		$this->providerDocumentation = $providerDocumentation;
		$this->deploymentAdmin       = new DeploymentAdminPresenter(
			attempts: $deploymentAttempts,
			plugins: $plugins,
			themes: $themes
		);
		$this->packageAdmin          = new PackageAdminController( $packageOperations, deployments: $this->deploymentAdmin );
		$this->pluginPages           = PackagePagePresenter::plugin();
		$this->themePages            = PackagePagePresenter::theme();
		$this->debugCapture          = $debugCapture;
		$this->secretsStorage        = $secretsStorage;
		$this->adminAddOns           = $adminAddOns;
		$this->webhookManagement     = $webhookManagement;

		$this->coreSelfUpdateDevelopmentNotice = $coreSelfUpdateDevelopmentNotice;
	}

	public function getIndex( ?string $forcedTab = null ) {
		if ( null === $this->adminTabs ) {
			throw new LogicException( 'Booster admin tabs are not configured.' );
		}

		$requestedTab = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted navigation state.
		if ( null !== $forcedTab && '' !== $forcedTab ) {
			$requestedTab = $forcedTab;
		} elseif ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
			// Read-only navigation state; no action is performed from this query value.
			$requestedTab = wp_unslash( $_GET['tab'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$requestedAddOnKey = is_string( $requestedTab ) ? strtolower( trim( $requestedTab ) ) : '';
		$selectedAddOn     = '' === $requestedAddOnKey || null === $this->adminAddOns
			? null
			: $this->adminAddOns->get( $requestedAddOnKey );
		$selectedTab       = null === $selectedAddOn ? $this->adminTabs->resolve( $requestedTab ) : null;
		$selectedKey       = null === $selectedAddOn ? $selectedTab->getKey() : $selectedAddOn->key();
		$tabs              = $this->tabNavigation( $selectedKey );
		$adminUrl          = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$data              = array(
			'tab'  => $selectedKey,
			'tabs' => $tabs,
		);
		if ( null !== $selectedAddOn && null !== $this->adminAddOns ) {
			$data['addOnTab']     = $selectedAddOn;
			$data['addOnContext'] = $this->adminAddOns->contextFor(
				$selectedAddOn,
				$adminUrl . '?page=ran-booster&tab=' . rawurlencode( $selectedAddOn->key() ),
				is_multisite() ? 'network' : 'site'
			);
		} else {
			$data['tabView'] = $selectedTab->getView();
		}

		if ( null !== $selectedTab && 'overview' === $selectedTab->getKey() ) {
			$data['onboarding'] = ( new OnboardingPresenter() )->build(
				$tabs,
				$adminUrl . '?page=ran-booster-plugins-create',
				$adminUrl . '?page=ran-booster-themes-create'
			);
			if ( null !== $this->secretsStorage ) {
				$includeStorageDetails = current_user_can( 'manage_options' )
					&& current_user_can( 'activate_plugins' );
				$wordpressRoot         = defined( 'ABSPATH' ) && is_string( ABSPATH )
					? ABSPATH
					: '';
				$result                = $this->secretsStorageResult ?? $this->secretsStorage->status();
				$this->logSecretsStorageDiagnostic( $result );
				$recovery                              = $includeStorageDetails
					? $this->secretsStorage->recoveryState( $result )
					: null;
				$data['onboarding']['secrets_storage'] = ( new SecretsStorageSetupPresenter() )->build(
					$result,
					$adminUrl . '?page=ran-booster&tab=overview',
					$wordpressRoot,
					$includeStorageDetails,
					$recovery
				);
			}
		}

		if ( null !== $selectedTab && $selectedTab->isProvider() ) {
			$provider                  = $selectedTab->getProvider();
			$data                      = array_merge(
				$data,
				$this->providerSettings->build( null === $provider ? null : $provider->value )
			);
			$data['providerView']      = $this->requestedProviderView();
			$data['providerTask']      = $this->requestedProviderTask();
			$data['providerListState'] = $this->requestedProviderListState();

			$data['requestedRepositoryId']           = $this->requestedProviderRepositoryId();
			$data                                    = array_merge( $data, ( new ProviderRepositoryRowsNormalizer() )->projectPage( $data, $this->webhookManagement ) );
			$data                                    = array_merge( $data, $this->providerSettings->buildProfileListProjection( $data ) );
			$data['webhookManagement']               = $this->webhookManagement;
			$data['statusSummaryRenderer']           = new AdminStatusSummaryRenderer();
			$data['providerManagementTableRenderer'] = new ProviderManagementTableRenderer();
			$data['repositoryTableRenderer']         = new RepositoryTableRenderer();
		} elseif ( null !== $selectedTab && 'portability' === $selectedTab->getKey() ) {
			try {
				$export                               = $this->portabilityExportData();
				$data['portabilityExportRows']        = $export['rows'];
				$data['portabilityExportUnavailable'] = false;
				try {
					$data['portabilityExportCredentialGroups']       = $this->providerSettings->buildPortabilityCredentials( $export['credentials'] );
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
		} elseif ( null !== $selectedTab && 'documentation' === $selectedTab->getKey() ) {
			$data['providerDocumentation'] = null === $this->providerDocumentation
				? array()
				: $this->providerDocumentation->build();
			$data['documentationUrl']      = $adminUrl . '?page=ran-booster&tab=documentation';
			$data['documentationScope']    = is_multisite() ? 'network' : 'site';
		} elseif ( null !== $selectedTab && 'troubleshooting' === $selectedTab->getKey() ) {
			$panel                        = $this->requestedTroubleshootingPanel();
			$data['troubleshootingPanel'] = $panel;
			if ( 'debug-capture' === $panel ) {
				$data['troubleshooting'] = array();
				$data['debugCapture']    = $this->debugCapturePayload();
			} elseif ( 'activity' === $panel ) {
				$data['troubleshooting']    = array();
				$data['deploymentActivity'] = $this->deploymentAdmin->activity();
			} else {
				$data['troubleshooting'] = $this->troubleshootingPayload ?? $this->troubleshooting->formPayload();
			}
		}

		return $this->render( 'index', $data );
	}

	/** Render the native sidebar route through the canonical Transporter tab. */
	public function getTransporter() {
		return $this->getIndex( 'portability' );
	}

	/**
	 * Render the one Core-owned region affected by a public repository lookup
	 * preference mutation. The Dispatcher may return this fragment only after it
	 * has performed the action's ordinary capability and nonce checks.
	 */
	public function renderPublicLookupProfileRegion( string $providerCode, ?string $error = null ): string {
		$settings                             = $this->providerSettings->build( $providerCode );
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
	public function renderDebugCaptureRegion( ?string $error = null ): string {
		$debugCapture      = $this->debugCapturePayload();
		$debugCaptureError = $error;

		ob_start();
		require __DIR__ . '/../views/debug-capture.php';

		return (string) ob_get_clean();
	}

	public function setSecretsStorageProvisioningResult( SecretsStorageProvisioningResult $result ): void {
		$this->secretsStorageResult = $result;
	}

	private function logSecretsStorageDiagnostic( SecretsStorageProvisioningResult $result ): void {
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
	private function portabilityExportData(): array {
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
				$blueprint    = BlueprintPackage::fromManagedPackage( $type, $package );
				$index        = count( $rows );
				$rows[]       = array(
					'name'       => $blueprint->displayName,
					'identifier' => $blueprint->identifier,
					'type'       => $blueprint->type,
				);
				$credentialId = $package->getCredentialId();
				if ( '' !== $blueprint->provider && '' !== $credentialId ) {
					$credentials[ $blueprint->provider ][ $credentialId ][] = array(
						'index' => $index,
						'name'  => $blueprint->displayName,
						'type'  => $blueprint->type,
					);
				}
			}
		}

		return compact( 'rows', 'credentials' );
	}

	/** @param array{provider: string, credential_id?: string|null, repository?: string|null} $request */
	public function postRunTroubleshooting( array $request ): void {
		$this->troubleshootingPayload = $this->troubleshooting->diagnose(
			$request['provider'],
			$request['credential_id'] ?? null,
			$request['repository'] ?? null
		);
	}

	/** Render the Core-owned Diagnostics panel after an explicit HTMX request. */
	public function renderTroubleshootingDiagnosticsRegion(): string {
		$troubleshootingPanel = 'diagnostics';
		$troubleshooting      = $this->troubleshootingPayload ?? $this->troubleshooting->formPayload();

		ob_start();
		require __DIR__ . '/../views/troubleshooting.php';

		return (string) ob_get_clean();
	}

	/** Whether the most recent diagnostics result completed without a warning or failure. */
	public function troubleshootingSucceeded(): bool {
		$results = $this->troubleshootingPayload['results'] ?? null;
		if ( ! is_array( $results ) || array() === $results ) {
			return false;
		}

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || 'pass' !== ( $result['status'] ?? null ) ) {
				return false;
			}
		}

		return empty( $this->troubleshootingPayload['partial'] );
	}

	public function getPlugins() {
		return $this->renderPackagePage( $this->pluginPages );
	}

	public function getPluginsCreate() {
		return $this->renderPackageCreate( $this->pluginPages );
	}

	public function getThemes() {
		return $this->renderPackagePage( $this->themePages );
	}

	public function getThemesCreate() {
		return $this->renderPackageCreate( $this->themePages );
	}

	/** @param list<array<string, mixed>> $extensions */
	public function getExtensions( array $extensions, string $pluginsUrl ) {
		return $this->render(
			'extensions',
			array(
				'extensions' => $extensions,
				'pluginsUrl' => $pluginsUrl,
			)
		);
	}

	private function renderPackagePage( PackagePagePresenter $packageView ) {
		$type = $packageView->getType();
		$this->packageAdmin->addSuccessNotice( $this, $type );
		$this->addBulkPackageNotice( $type );

		// Read-only package selection; mutations use separately nonce-protected forms.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['package'] ) ) {
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only package selection.
				$identifier                                = sanitize_text_field( wp_unslash( $_GET['package'] ) );
				$package                                   = 'plugin' === $type
					? $this->plugins->boosterPluginFromFile( $identifier )
					: $this->themes->boosterThemeFromStylesheet( $identifier );
				$repositoryBranchCheckOutcome              = $this->requestedPackageRepositoryBranchCheck( $package, $type );
				$repositoryBranchCheckEvidence             = null === $repositoryBranchCheckOutcome
					? $this->providerSettings->packageRepositoryBranchEvidence( $type, $package )
					: null;
				$editData                                  = $packageView->edit(
					$package,
					$this->providerSettings->buildExistingPackageForm( (string) ( $package->getProviderCode() ?? '' ) ),
					$this->providerSettings->buildPackageBranchReadiness( $package ),
					$this->providerSettings->buildPackageWebhookRetention( $package ),
					$this->requestedPackageSourceView(),
					$this->requestedAdvancedSettingsOpen()
				);
				$editData['repositoryBranchCheckOutcome']  = $repositoryBranchCheckOutcome;
				$editData['repositoryBranchCheckEvidence'] = $repositoryBranchCheckEvidence;
				return $this->render(
					'packages/edit',
					$editData
				);
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A missing package intentionally falls back to the index.
			} catch ( PluginNotFound | ThemeNotFound $missing ) {
				// The selected package is absent, so show the matching index instead.
			} catch ( PackageStorageFailure $failure ) {
				return $this->packageStorageFailureIndex( $packageView, $type, $failure );
			}
		}

		try {
			$packages = 'plugin' === $type
				? $this->plugins->allBoosterPlugins()
				: $this->themes->allBoosterThemes();
		} catch ( PackageStorageFailure $failure ) {
			return $this->packageStorageFailureIndex( $packageView, $type, $failure );
		}

		return $this->render( 'packages/index', $this->packageIndexData( $packages, $packageView ) );
	}

	/** @return 'verified'|'subdirectory_unavailable'|'subdirectory_unverified'|'unable_to_check'|'provider_unavailable'|null */
	private function requestedPackageRepositoryBranchCheck( Package $package, string $type ): ?string {
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
		$marker = 'ran_booster_branch_check_' . hash( 'sha256', get_current_user_id() . "\0" . $action . "\0" . $nonce );
		if ( function_exists( __NAMESPACE__ . '\\get_transient' ) || function_exists( 'get_transient' ) ) {
			$completed = get_transient( $marker );
			if ( is_string( $completed ) && in_array( $completed, array( 'verified', 'unable_to_check', 'provider_unavailable' ), true ) ) {
				return $completed;
			}
		}

		$outcome = $this->providerSettings->checkPackageRepositoryBranch( $type, $package );
		if ( function_exists( __NAMESPACE__ . '\\set_transient' ) || function_exists( 'set_transient' ) ) {
			set_transient( $marker, $outcome, 3600 );
		}
		BoosterLogger::log(
			'repository branch check completed',
			array(
				'event'        => 'repository_branch_checked',
				'operation'    => 'repository_branch_check',
				'outcome_code' => $outcome,
				'package_slug' => (string) $package->getSlug(),
				'provider'     => (string) $package->getProviderCode(),
				'source'       => $package->getSource()->value,
				'step'         => 'package_branch_check',
			)
		);
		return $outcome;
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, mixed>
	 */
	private function packageIndexData( array $packages, PackagePagePresenter $packageView ): array {
		return $packageView->index(
			$packages,
			$this->providerSettings->buildPackageList(),
			$this->requestedPackageListState(),
			$this->deploymentAdmin
		);
	}

	/**
	 * Normalize read-only package-list filters without granting mutation authority.
	 *
	 * @return array{search: string, provider: string, source: string, policy: string}
	 */
	private function requestedPackageListState(): array {
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
	private function packageListQueryArguments(): array {
		$state = $this->requestedPackageListState();

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

	private function requestedPackageSourceView(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
		$value = isset( $_GET['source_view'] ) && is_string( $_GET['source_view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
			? sanitize_key( wp_unslash( $_GET['source_view'] ) )
			: '';

		return in_array( $value, array( 'branch', 'release_asset' ), true ) ? $value : '';
	}

	private function requestedAdvancedSettingsOpen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
		$value = isset( $_GET['ran_booster_open_advanced'] ) && is_string( $_GET['ran_booster_open_advanced'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
			? sanitize_key( wp_unslash( $_GET['ran_booster_open_advanced'] ) )
			: '';

		return '1' === $value;
	}

	private function renderPackageCreate( PackagePagePresenter $packageView ): mixed {
		try {
			$this->db->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return $this->databaseUnavailableCreate( $packageView, $packageView->getType() );
		}
		$success = $this->packageAdmin->addSuccessNotice( $this, $packageView->getType() );

		return $this->render(
			'packages/create',
			$packageView->create(
				$this->providerSettings->buildPackageForm( $this->requestedProvider() ),
				$this->hasRequestedProvider(),
				$this->requestedOpenPicker(),
				$this->requestedPackageSourceView(),
				in_array( $success['operation'] ?? null, array( 'install', 'already-managed' ), true ) ? $success['identifier'] : null,
				$this->requestedAdvancedSettingsOpen()
			)
		);
	}

	public function addMessage( $message ) {
		$this->recordMessage( $message );
	}

	public function addFailureMessage( $message, Throwable $failure, array $context = array() ): void {
		$this->recordMessage( $message, $failure, $context );
	}

	private function addMessageWithContext( $message, array $context ): void {
		$this->recordMessage( $message, null, $context );
	}

	private function recordMessage( $message, ?Throwable $failure = null, array $context = array() ): void {
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
		$severity = $this->messageSeverity( $message );
		if ( null !== $severity ) {
			$context = array(
				'diagnostic_id' => $this->messageDiagnosticId( $message, $severity ),
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
	public function postPackageOperation( string $action, array $request ): bool|string {
		$packageAdmin = $this->packageAdmin ?? new PackageAdminController();

		return $packageAdmin->perform(
			$this,
			$action,
			$request,
			$this->packageListQueryArguments(),
			function ( WP_Error|array $message, array $context ): void {
				$this->addMessageWithContext( $message, $context );
			}
		);
	}

	/** @return array{operation: string, identifier: string}|null */
	private function addPackageSuccessNotice( string $type ): ?array {
		return $this->packageAdmin->addSuccessNotice( $this, $type );
	}

	private function packageStorageFailureIndex( PackagePagePresenter $packageView, string $type, PackageStorageFailure $failure ): mixed {
		$this->addFailureMessage(
			$this->packageStorageError( $failure ),
			$failure,
			array(
				'operation' => 'read-' . $type . '-packages',
				'step'      => 'package_storage',
			)
		);
		$packages = array();

		return $this->render( 'packages/index', $this->packageIndexData( $packages, $packageView ) );
	}

	private function databaseUnavailableCreate( PackagePagePresenter $packageView, string $type ): mixed {
		$failure = PackageStorageFailure::unsupportedDatabase();
		$this->addFailureMessage(
			$this->packageStorageError( $failure ),
			$failure,
			array(
				'operation' => 'create-' . $type . '-package',
				'step'      => 'database_compatibility',
			)
		);

		return $this->render(
			'packages/create',
			$packageView->unavailableCreate(
				$this->providerSettings->buildPackageForm( $this->requestedProvider() ),
				$this->hasRequestedProvider()
			)
		);
	}

	private function packageStorageError( PackageStorageFailure $failure ): WP_Error {
		return new WP_Error(
			$failure->getDiagnosticId(),
			$failure->getMessage(),
			array( 'recovery_required' => $failure->isRecoveryRequired() )
		);
	}

	public function bulkPackageRedirect( string $type, BulkPackageResult $result ): string {
		return $this->packageAdmin->bulkRedirect( $type, $result, $this->packageListQueryArguments() );
	}

	private function addBulkPackageNotice( string $type ): void {
		$this->packageAdmin->addBulkNotice(
			$this,
			$type,
			function ( array $message, array $context ): void {
				$this->addMessageWithContext( $message, $context );
			}
		);
	}

	private function messageSeverity( mixed $message ): ?string {
		if ( $message instanceof WP_Error ) {
			return 'error';
		}
		if ( is_array( $message ) && in_array( $message['type'] ?? null, array( 'info', 'warning', 'error' ), true ) ) {
			return $message['type'];
		}

		return null;
	}

	private function messageDiagnosticId( mixed $message, string $severity ): string {
		$diagnosticId = $message instanceof WP_Error
			? $message->get_error_code()
			: ( is_array( $message ) ? ( $message['code'] ?? '' ) : '' );

		return is_string( $diagnosticId ) && preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $diagnosticId ) === 1
			? $diagnosticId
			: 'ran_booster_admin_' . $severity;
	}

	private function requestedProvider(): ?string {
		// Read-only provider selection for package setup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['provider'] ) && is_string( $_GET['provider'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['provider'] ) )
			: null;
	}

	private function hasRequestedProvider(): bool {
		// Read-only provider selection for package setup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['provider'] ) && is_string( $_GET['provider'] ) && '' !== sanitize_key( wp_unslash( $_GET['provider'] ) );
	}

	private function requestedOpenPicker(): bool {
		// Read-only presentation state. Repository mutations still require their POST nonce.
		$postedPackage = isset( $_POST['ran_booster'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only suppresses a presentation flag after any package form submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation flag.
		$openPicker = isset( $_GET['open_picker'] ) && is_scalar( $_GET['open_picker'] ) && '1' === (string) wp_unslash( $_GET['open_picker'] );

		return ! $postedPackage && $openPicker;
	}

	private function requestedTroubleshootingPanel(): string {
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

	private function requestedProviderView(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
			? sanitize_key( wp_unslash( $_GET['view'] ) )
			: '';

		return in_array( $view, array( 'credentials', 'secrets' ), true ) ? $view : 'overview';
	}

	private function requestedProviderTask(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
		$task = isset( $_GET['panel'] ) && is_string( $_GET['panel'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider presentation selector.
			? sanitize_key( wp_unslash( $_GET['panel'] ) )
			: '';

		return in_array( $task, array( 'repositories', 'setup' ), true ) ? $task : 'status';
	}

	private function requestedProviderRepositoryId(): string {
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
	private function requestedProviderListState(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filtering and pagination.
		$search  = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$kind    = isset( $_GET['kind'] ) && is_string( $_GET['kind'] )
			? sanitize_key( wp_unslash( $_GET['kind'] ) )
			: '';
		$scope   = isset( $_GET['scope'] ) && is_string( $_GET['scope'] )
			? sanitize_key( wp_unslash( $_GET['scope'] ) )
			: '';
		$status  = isset( $_GET['status'] ) && is_string( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'name';
		$order   = isset( $_GET['order'] ) && is_string( $_GET['order'] )
			? sanitize_key( wp_unslash( $_GET['order'] ) )
			: 'asc';
		$paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$perPage = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;
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
			'per_page' => in_array( $perPage, array( 20, 50 ), true ) ? $perPage : 20,
		);
	}

	/** @return array<string, mixed> */
	private function debugCapturePayload(): array {
		$fallback = array(
			'state'         => 'unavailable',
			'filename'      => 'ran-booster-debug.php',
			'capture_until' => '',
			'delete_after'  => '',
			'content'       => '',
		);
		if ( null === $this->debugCapture ) {
			return $fallback;
		}

		try {
			$snapshot = $this->debugCapture->snapshot();
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
	private function tabNavigation( string $selectedKey ): array {
		if ( null === $this->adminTabs ) {
			return array();
		}

		$adminUrl = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$tabs     = array();

		foreach ( $this->adminTabs->all() as $tab ) {
			$tabKey = $tab->getKey();
			$tabs[] = array(
				'key'      => $tabKey,
				'label'    => $tab->getLabel(),
				'url'      => 'portability' === $tabKey
					? $adminUrl . '?page=ran-booster-transporter'
					: $adminUrl . '?page=ran-booster&tab=' . rawurlencode( $tabKey ),
				'active'   => $selectedKey === $tabKey,
				'provider' => $tab->isProvider(),
			);
		}
		if ( null !== $this->adminAddOns ) {
			foreach ( $this->adminAddOns->all() as $tab ) {
				$tabs[] = array(
					'key'      => $tab->key(),
					'label'    => $tab->label(),
					'url'      => $adminUrl . '?page=ran-booster&tab=' . rawurlencode( $tab->key() ),
					'active'   => $selectedKey === $tab->key(),
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

		$developmentEnvironmentDetected         = DevelopmentEnvironmentDetector::isLikely();
		$data['developmentEnvironmentDetected'] = $developmentEnvironmentDetected;
		$data['developmentSafetyNotice']        = $this->shouldShowDevelopmentSafetyNotice( $view, $data, $developmentEnvironmentDetected );
		$data['messages']                       = $this->messages;
		$data['name']                           = $this->booster->getName();

		$data['coreSelfUpdateDevelopmentNotice'] = $this->coreSelfUpdateDevelopmentNotice;
		if ( ! isset( $data['tabs'] ) ) {
			$data['tabs'] = $this->tabNavigation( '' );
		}

		// Internal controllers provide a fixed set of view locals; no request keys reach extract().
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data );

		return include __DIR__ . '/../views/base.php';
	}

	/** @param array<string, mixed> $data */
	private function shouldShowDevelopmentSafetyNotice( string $view, array $data, bool $developmentEnvironmentDetected ): bool {
		$relevantView = 'packages/index' === $view;
		$userId       = get_current_user_id();
		$dismissed    = $userId > 0
			&& '1' === get_user_meta( $userId, DevelopmentSafetyNoticeController::USER_META_KEY, true );

		return $relevantView && ! $dismissed && $developmentEnvironmentDetected;
	}
}

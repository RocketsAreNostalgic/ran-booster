<?php

namespace RAN;

use LogicException;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminPackageProjection;
use RAN\Admin\AdminTab;
use RAN\Admin\AdminTabRegistry;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\Component\AdminPackageSourceChoiceNormalizer;
use RAN\Admin\DevelopmentEnvironmentDetector;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Admin\OnboardingPresenter;
use RAN\Admin\PackageViewConfig;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\Admin\ProviderRepositoryCompositionRenderer;
use RAN\Admin\ProviderRepositoryRowsNormalizer;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\Component\AdminStatusSummaryRenderer;
use RAN\Admin\Component\ProviderManagementTableRenderer;
use RAN\Admin\Component\RepositoryTableRenderer;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Admin\WebhookCleanupContext;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\RejectedAdmissionAuditRepository;
use RAN\Deployment\DeploymentStorageFailure;
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
	private const DEPLOYMENT_ACTIVITY_PAGE_SIZE = 50;

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

	private ?ProviderDocumentationPresenter $providerDocumentation;
	private ?PackageOperationService $packageOperations               = null;
	private ?DeploymentAttemptRepository $deploymentAttempts          = null;
	private ?RejectedAdmissionAuditRepository $rejectedAdmissionAudit = null;
	private ?TemporaryDebugCapture $debugCapture                      = null;
	private ?SecretsStorageProvisioner $secretsStorage                = null;
	private ?SecretsStorageProvisioningResult $secretsStorageResult   = null;

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
	 * @param RejectedAdmissionAuditRepository|null $rejectedAdmissionAudit Bounded non-mutation retry audit.
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
		?RejectedAdmissionAuditRepository $rejectedAdmissionAudit = null
	) {
		$this->db                     = $db;
		$this->plugins                = $plugins;
		$this->booster                = $booster;
		$this->themes                 = $themes;
		$this->providerSettings       = $providerSettings;
		$this->troubleshooting        = $troubleshooting;
		$this->adminTabs              = $adminTabs;
		$this->providerDocumentation  = $providerDocumentation;
		$this->packageOperations      = $packageOperations;
		$this->deploymentAttempts     = $deploymentAttempts;
		$this->rejectedAdmissionAudit = $rejectedAdmissionAudit;
		$this->debugCapture           = $debugCapture;
		$this->secretsStorage         = $secretsStorage;
		$this->adminAddOns            = $adminAddOns;
	}

	public function getIndex() {
		if ( null === $this->adminTabs ) {
			throw new LogicException( 'Booster admin tabs are not configured.' );
		}

		$requestedTab = null;
		// Read-only navigation state; no action is performed from this query value.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted navigation state.
			$requestedTab = wp_unslash( $_GET['tab'] );
		}

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

			$repositoryComposition                   = new ProviderRepositoryCompositionRenderer();
			$data['requestedRepositoryId']           = $this->requestedProviderRepositoryId();
			$data                                    = array_merge( $data, ( new ProviderRepositoryRowsNormalizer() )->projectPage( $data, $repositoryComposition ) );
			$data                                    = array_merge( $data, $this->providerSettings->buildProfileListProjection( $data ) );
			$data['repositoryComposition']           = $repositoryComposition;
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
				$data['deploymentActivity'] = $this->activity();
			} else {
				$data['troubleshooting'] = $this->troubleshootingPayload ?? $this->troubleshooting->formPayload();
			}
		}

		return $this->render( 'index', $data );
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
		return $this->renderPackagePage( PackageViewConfig::plugin() );
	}

	public function getPluginsCreate() {
		return $this->renderPackageCreate( PackageViewConfig::plugin() );
	}

	public function getThemes() {
		return $this->renderPackagePage( PackageViewConfig::theme() );
	}

	public function getThemesCreate() {
		return $this->renderPackageCreate( PackageViewConfig::theme() );
	}

	private function renderPackagePage( PackageViewConfig $packageView ) {
		$type = $packageView->getType();
		$this->addPackageSuccessNotice( $type );
		$this->addBulkPackageNotice( $type );

		// Read-only package selection; mutations use separately nonce-protected forms.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['package'] ) ) {
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only package selection.
				$identifier = sanitize_text_field( wp_unslash( $_GET['package'] ) );
				$package    = 'plugin' === $type
					? $this->plugins->boosterPluginFromFile( $identifier )
					: $this->themes->boosterThemeFromStylesheet( $identifier );
				return $this->render(
					'packages/edit',
					array_merge(
						$this->existingPackageProviderData( $package, $packageView ),
						array(
							'package'                => $package,
							'packageView'            => $packageView,
							'packageExtensionPanels' => $this->packageExtensionPanels( $package, $packageView ),
							'packageSource'          => $this->packageSourceComposition( 'edit', $packageView, $package ),
						)
					)
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

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, mixed>
	 */
	private function packageIndexData( array $packages, PackageViewConfig $packageView ): array {
		$packageProviders       = $this->providerSettings->buildPackageList();
		$packageListState       = $this->requestedPackageListState();
		$packageProviderOptions = $this->packageProviderFilterOptions( $packages, $packageProviders );
		if ( '' !== $packageListState['provider']
			&& ! in_array( $packageListState['provider'], array_column( $packageProviderOptions, 'code' ), true )
		) {
			$packageListState['provider'] = '';
		}
		$filteredPackages = $this->filterPackages( $packages, $packageListState, $packageProviderOptions );

		return array(
			'packages'                => $filteredPackages,
			'packageListTotal'        => count( $packages ),
			'packageListState'        => $packageListState,
			'packageProviderOptions'  => $packageProviderOptions,
			'packageView'             => $packageView,
			'packageProviders'        => $packageProviders,
			'packageActivity'         => $this->packageActivity( $filteredPackages, $packageView->getType() ),
			'packageExtensionRows'    => $this->packageExtensionRows( $filteredPackages, $packageView ),
			'packageExtensionActions' => $this->packageExtensionActions( $filteredPackages, $packageView ),
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

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param list<array<string, mixed>> $packageProviders
	 * @return list<array{code: string, label: string}>
	 */
	private function packageProviderFilterOptions( array $packages, array $packageProviders ): array {
		$providerLabels = array();
		foreach ( $packageProviders as $provider ) {
			if ( is_string( $provider['code'] ?? null ) && is_string( $provider['label'] ?? null ) ) {
				$providerLabels[ $provider['code'] ] = $provider['label'];
			}
		}

		$options = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package ) {
				continue;
			}
			$code = (string) ( $package->getProviderCode() ?? '' );
			if ( '' === $code || isset( $options[ $code ] ) ) {
				continue;
			}
			$options[ $code ] = array(
				'code'  => $code,
				'label' => $providerLabels[ $code ] ?? $code,
			);
		}

		uasort(
			$options,
			static fn ( array $left, array $right ): int => strnatcasecmp( $left['label'], $right['label'] )
		);

		return array_values( $options );
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param array{search: string, provider: string, source: string, policy: string} $state
	 * @param list<array{code: string, label: string}> $providerOptions
	 * @return list<Package>
	 */
	private function filterPackages( array $packages, array $state, array $providerOptions ): array {
		$providerLabels = array_column( $providerOptions, 'label', 'code' );
		$search         = strtolower( $state['search'] );

		return array_values(
			array_filter(
				$packages,
				static function ( mixed $package ) use ( $state, $providerLabels, $search ): bool {
					if ( ! $package instanceof Package ) {
						return false;
					}

					$provider = (string) ( $package->getProviderCode() ?? '' );
					if ( '' !== $state['provider'] && $provider !== $state['provider'] ) {
						return false;
					}
					if ( '' !== $state['source'] && $package->getSource()->value !== $state['source'] ) {
						return false;
					}
					if ( '' !== $state['policy'] && $package->getDeploymentPolicy()->value !== $state['policy'] ) {
						return false;
					}
					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							"\n",
							array(
								$package->getDisplayName(),
								(string) $package->getIdentifier(),
								(string) $package->getRepository(),
								$provider,
								$providerLabels[ $provider ] ?? '',
								(string) $package->getBranch(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
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

	/** @return list<string> */
	private function packageExtensionPanels( Package $package, PackageViewConfig $packageView ): array {
		$projection  = $this->packageProjection( $package, $packageView );
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			do_action( 'ran_booster_admin_package_settings_sections', $projection, $projection->settingsUrl() );
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			BoosterLogger::logException(
				'package settings action unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'package_settings_action',
					'operation' => $packageView->getType(),
				)
			);
		}

		return array();
	}

	/**
	 * Build the Core-rendered source selector and bounded add-on section.
	 *
	 * @return array{
	 *   choices: array<string, array<string, mixed>>,
	 *   advanced_sections: list<string>,
	 *   advanced_summary: string,
	 *   sections: list<string>,
	 *   selected: string,
	 *   current: string,
	 *   advanced_open: bool,
	 *   unavailable: bool
	 * }
	 */
	private function packageSourceComposition(
		string $mode,
		PackageViewConfig $packageView,
		?Package $package = null
	): array {
		$projection = null === $package ? null : $this->packageProjection( $package, $packageView );
		$pageUrl    = null === $projection
			? add_query_arg( 'page', $packageView->getCreatePageSlug(), $this->packageAdminUrl() )
			: $projection->settingsUrl();
		$base       = array(
			'branch'        => array(
				'key'               => 'branch',
				'heading'           => __( 'Branch', 'ran-booster' ),
				'description'       => __( 'Deploy a saved repository branch manually or when a signed push webhook arrives.', 'ran-booster' ),
				'meta'              => __( 'Included with Booster', 'ran-booster' ),
				'url'               => add_query_arg( 'source_view', 'branch', $pageUrl ),
				'disabled'          => false,
				'hydrated'          => true,
				'client_hydratable' => false,
			),
			'release_asset' => array(
				'key'               => 'release_asset',
				'heading'           => __( 'Subscriber release deployments', 'ran-booster' ),
				'description'       => __( 'Install verified published packages with the optional Release Deployments add-on.', 'ran-booster' ),
				'meta'              => __( 'Subscriber feature', 'ran-booster' ),
				'url'               => '',
				'disabled'          => true,
				'hydrated'          => false,
				'client_hydratable' => false,
			),
		);

		try {
			$filtered = apply_filters(
				'ran_booster_admin_package_source_choices',
				$base,
				$mode,
				$packageView->getType(),
				$projection,
				$pageUrl
			);
			$choices  = ( new AdminPackageSourceChoiceNormalizer() )->normalize( $filtered );
		} catch ( Throwable $failure ) {
			$choices = ( new AdminPackageSourceChoiceNormalizer() )->normalize( $base );
			BoosterLogger::logException(
				'package source choices unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'package_source_choices',
					'operation' => $packageView->getType(),
				)
			);
		}

		$current   = null === $package ? PackageSource::BRANCH->value : $package->getSource()->value;
		$requested = $this->requestedPackageSourceView();
		$selected  = $current;
		if ( null === $package ) {
			$selected = PackageSource::BRANCH->value;
		} elseif ( isset( $choices[ $requested ] )
			&& ! $choices[ $requested ]['disabled']
			&& ( PackageSource::BRANCH->value === $current || $choices[ $current ]['hydrated'] ) ) {
			$selected = $requested;
		}
		$unavailable = PackageSource::BRANCH->value !== $current
			&& ( ! isset( $choices[ $current ] ) || ! $choices[ $current ]['hydrated'] );

		return array(
			'choices'           => $choices,
			'advanced_sections' => $this->packageAdvancedSourceSections(
				$mode,
				$packageView->getType(),
				$selected,
				$projection,
				$pageUrl
			),
			'advanced_summary'  => $this->packageAdvancedSourceSummary(
				$mode,
				$packageView->getType(),
				$selected,
				$choices,
				$projection,
				$package
			),
			'selected'          => $selected,
			'current'           => $current,
			'advanced_open'     => '' !== $requested,
			'unavailable'       => $unavailable,
		);
	}

	/** @return list<string> */
	private function packageAdvancedSourceSections(
		string $mode,
		string $type,
		string $selected,
		?AdminPackageProjection $projection,
		string $pageUrl
	): array {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			do_action(
				'ran_booster_admin_package_advanced_source_sections',
				$mode,
				$type,
				$selected,
				$projection,
				$pageUrl
			);
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			BoosterLogger::logException(
				'advanced package source section unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'advanced_package_source_section',
					'operation' => $type,
				)
			);
		}

		return array();
	}

	/**
	 * @param array<string, array<string, mixed>> $choices
	 */
	private function packageAdvancedSourceSummary(
		string $mode,
		string $type,
		string $selected,
		array $choices,
		?AdminPackageProjection $projection,
		?Package $package
	): string {
		$sourceLabel = is_string( $choices[ $selected ]['heading'] ?? null )
			? $choices[ $selected ]['heading']
			: __( 'Package source', 'ran-booster' );
		$summary     = PackageSource::BRANCH->value === $selected
			? sprintf(
				/* translators: 1: source label, 2: branch. */
				__( '%1$s · %2$s', 'ran-booster' ),
				$sourceLabel,
				null !== $package && '' !== (string) $package->getBranch()
					? (string) $package->getBranch()
					: __( 'provider default', 'ran-booster' )
			)
			: $sourceLabel;

		try {
			$filtered = apply_filters(
				'ran_booster_admin_package_advanced_source_summary',
				$summary,
				$mode,
				$type,
				$selected,
				$projection
			);
			if ( is_string( $filtered ) ) {
				$filtered = trim( wp_strip_all_tags( $filtered, true ) );
				if ( '' !== $filtered && strlen( $filtered ) <= 180 ) {
					return $filtered;
				}
			}
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'advanced package source summary unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'advanced_package_source_summary',
					'operation' => $type,
				)
			);
		}

		return $summary;
	}

	private function requestedPackageSourceView(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
		$value = isset( $_GET['source_view'] ) && is_string( $_GET['source_view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation selector.
			? sanitize_key( wp_unslash( $_GET['source_view'] ) )
			: '';

		return in_array( $value, array( 'branch', 'release_asset' ), true ) ? $value : '';
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function packageExtensionRows( array $packages, PackageViewConfig $packageView ): array {
		if ( array() === $packages ) {
			return array();
		}

		$projections = array();
		$baseRows    = array();
		foreach ( $packages as $package ) {
			if ( $package instanceof Package ) {
				$projection                               = $this->packageProjection( $package, $packageView );
				$projections[ $projection->identifier() ] = $projection;
				$baseRows[ $projection->identifier() ]    = array(
					'badges' => array(),
					'status' => '',
				);
			}
		}

		try {
			$presented = apply_filters(
				'ran_booster_admin_package_management_rows',
				$baseRows,
				$packageView->getType(),
				$projections
			);

			return $this->normalizePackageExtensionRows( $presented, $projections, $baseRows );
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'package management filter unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'package_management_filter',
					'operation' => $packageView->getType(),
				)
			);
		}

		return array();
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function packageExtensionActions( array $packages, PackageViewConfig $packageView ): array {
		$actions    = array();
		$normalizer = new \RAN\Admin\Component\AdminActionNormalizer();

		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package ) {
				continue;
			}
			$projection = $this->packageProjection( $package, $packageView );
			try {
				$presented                            = apply_filters(
					'ran_booster_admin_package_management_actions',
					array(),
					$packageView->getType(),
					$projection
				);
				$actions[ $projection->identifier() ] = $normalizer->normalize( $presented );
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
					'package management actions unavailable',
					$failure,
					array(
						'source'    => 'admin',
						'step'      => 'package_management_actions',
						'operation' => $packageView->getType(),
					)
				);
			}
		}

		return $actions;
	}

	/**
	 * @param mixed $presented
	 * @param array<string, AdminPackageProjection> $projections
	 * @param array<string, array{badges: array<mixed>, status: string}> $baseRows
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function normalizePackageExtensionRows( mixed $presented, array $projections, array $baseRows ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Package management rows must be a keyed array.' );
		}
		if ( array_diff_key( $presented, $baseRows ) !== array()
			|| array_diff_key( $baseRows, $presented ) !== array() ) {
			throw new LogicException( 'Package management filters must preserve every projected package row.' );
		}

		$normalized = array();
		foreach ( $presented as $identifier => $row ) {
			if ( ! is_string( $identifier ) || ! isset( $projections[ $identifier ] ) || ! is_array( $row ) ) {
				throw new LogicException( 'Package management rows may address only projected packages.' );
			}
			if ( array_diff( array_keys( $row ), array( 'badges', 'status' ) ) !== array() ) {
				throw new LogicException( 'Package management rows may contain only badges and status.' );
			}

			$badges          = array();
			$presentedBadges = $row['badges'] ?? array();
			if ( ! is_array( $presentedBadges ) || count( $presentedBadges ) > 20 ) {
				throw new LogicException( 'Package management badges must be a bounded list.' );
			}
			foreach ( $presentedBadges as $badge ) {
				if ( ! is_array( $badge )
					|| ! is_string( $badge['label'] ?? null )
					|| '' === trim( $badge['label'] )
					|| strlen( $badge['label'] ) > 96
					|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $badge['label'] )
					|| ! in_array( $badge['tone'] ?? null, array( 'neutral', 'ok', 'pending', 'warning', 'error' ), true ) ) {
					throw new LogicException( 'Package management badges must be bounded display values.' );
				}
				$badges[] = array(
					'label' => $badge['label'],
					'tone'  => $badge['tone'],
				);
			}

			if ( isset( $row['status'] ) && ! is_string( $row['status'] ) ) {
				throw new LogicException( 'Package management status must be a string.' );
			}
			$status = trim( $row['status'] ?? '' );
			if ( strlen( $status ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $status ) ) {
				throw new LogicException( 'Package management status must be bounded.' );
			}
			$normalized[ $identifier ] = array(
				'badges' => $badges,
				'status' => $status,
			);
		}

		return $normalized;
	}

	private function packageProjection( Package $package, PackageViewConfig $packageView ): AdminPackageProjection {
		$settingsUrl = $this->packageSettingsUrl( $package, $packageView );

		return new AdminPackageProjection(
			$packageView->getType(),
			(string) $package->getIdentifier(),
			$package->getDisplayName(),
			(string) ( $package->getProviderCode() ?? '' ),
			$package->getSource()->value,
			$package->getSourceRevision(),
			$package->getDeploymentPolicy()->value,
			$settingsUrl
		);
	}

	private function packageAdminUrl(): string {
		return is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
	}

	private function packageSettingsUrl( Package $package, PackageViewConfig $packageView ): string {
		return add_query_arg(
			array(
				'page'    => $packageView->getPageSlug(),
				'package' => (string) $package->getIdentifier(),
			),
			$this->packageAdminUrl()
		);
	}

	private function renderPackageCreate( PackageViewConfig $packageView ): mixed {
		try {
			$this->db->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			return $this->databaseUnavailableCreate( $packageView, $packageView->getType() );
		}
		$this->addPackageSuccessNotice( $packageView->getType() );

		return $this->render(
			'packages/create',
			array_merge(
				$this->packageProviderData( $this->requestedProvider() ),
				array(
					'packageView'          => $packageView,
					'explicitProvider'     => $this->hasRequestedProvider(),
					'openRepositoryPicker' => $this->requestedOpenPicker(),
					'packageSource'        => $this->packageSourceComposition( 'create', $packageView ),
				)
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
		try {
			if ( null === $this->packageOperations ) {
				throw new LogicException( 'Package operations are not configured.' );
			}
			$operation          = PackageOperation::fromInput( $action, $request );
			$reinstallAfterSave = 'edit' === $operation->operation
				&& isset( $request['reinstall_after_save'] )
				&& is_scalar( $request['reinstall_after_save'] )
				&& '1' === (string) $request['reinstall_after_save'];
			$result             = $this->packageOperations->execute( $operation );
			if ( $reinstallAfterSave
				&& 'edited' === $result['status']
				&& isset( $result['package'] )
				&& $result['package'] instanceof Package
			) {
				$this->addMessage(
					array(
						'type'    => 'info',
						'message' => __( 'Package settings were saved before the reinstall.', 'ran-booster' ),
					)
				);
				$operation = PackageOperation::updateFromSavedPackage( $operation, $result['package'] );
				$action    = 'update-' . $operation->packageType;
				$result    = $this->packageOperations->execute( $operation );
			}
		} catch ( PackageStorageFailure $failure ) {
			status_header( 400 );
			$this->addFailureMessage(
				$this->packageStorageError( $failure ),
				$failure,
				array(
					'operation' => $action,
					'step'      => 'package_storage',
				)
			);
			return false;
		} catch ( DeploymentStorageFailure $failure ) {
			if ( null !== $failure->getActiveCorrelationId() ) {
				return $this->reportActiveDeployment( $failure, $action );
			}

			return $this->reportManualFailure( $failure, $action );
		} catch ( Throwable $failure ) {
			return $this->reportManualFailure( $failure, $action );
		}

		$installAnother   = 'install' === $operation->operation
			&& isset( $request['install_another'] )
			&& is_scalar( $request['install_another'] )
			&& '1' === (string) $request['install_another'];
		$returnToSettings = $reinstallAfterSave || ( 'update' === $operation->operation
			&& isset( $request['return_to_settings'] )
			&& is_scalar( $request['return_to_settings'] )
			&& '1' === (string) $request['return_to_settings'] );

		if ( 'succeeded' === $result['status'] && isset( $result['package'] ) && $result['package'] instanceof Package ) {
			return $this->packageSuccessRedirect( $operation, $result['package'], $installAnother, $returnToSettings );
		} elseif ( 'conflict' === $result['status'] ) {
			status_header( 409 );
			$this->addMessageWithContext(
				new \WP_Error(
					'ran_booster_package_edit_conflict',
					'Package settings changed after this page was loaded. No settings were saved. Review the refreshed current settings, then resubmit your attempted changes.'
				),
				array(
					'operation'    => $operation->operation,
					'package_type' => $operation->packageType,
					'step'         => 'package_edit_conflict',
				)
			);

			return false;
		} elseif ( 'failed' === $result['status'] && isset( $result['correlation_id'] ) ) {
			$this->reportDeploymentFailure( $result['outcome_code'], $result['correlation_id'], $action );

			return false;
		} elseif ( 'failed' === $result['status'] && isset( $result['outcome_code'] ) ) {
			$this->reportPackageRemovalFailure( $operation, $result['outcome_code'] );

			return false;
		} elseif ( 'edited' === $result['status'] && isset( $result['package'] ) && $result['package'] instanceof Package ) {
			return $this->packageSuccessRedirect( $operation, $result['package'], false, true );
		} elseif ( 'unlinked' === $result['status'] ) {
			return $this->packageSuccessRedirect( $operation, (string) $operation->identifier );
		} elseif ( 'deleted' === $result['status'] ) {
			return $this->packageSuccessRedirect( $operation, (string) $operation->identifier );
		} elseif ( 'linked' === $result['status'] && isset( $result['package'] ) && $result['package'] instanceof Package ) {
			return $this->packageSuccessRedirect( $operation, $result['package'], $installAnother );
		} else {
			return $this->reportManualFailure( null, $action );
		}

		return true;
	}

	private function reportPackageRemovalFailure( PackageOperation $operation, string $outcomeCode ): void {
		$type    = 'plugin' === $operation->packageType ? 'Plugin' : 'Theme';
		$message = match ( $outcomeCode ) {
			'active_dependents'          => 'Plugin was not removed because an active plugin depends on it.',
			'deactivation_failed'        => 'Plugin was disabled in Booster, but WordPress could not deactivate it. No files were deleted.',
			'deletion_failed'            => sprintf( '%s was disabled in Booster, but WordPress could not delete it.', $type ),
			'files_still_present'        => sprintf( '%s was disabled in Booster, but its files are still present.', $type ),
			'management_state_uncertain' => sprintf( '%s files were deleted, but Booster could not verify removal of its management record.', $type ),
			'operation_in_progress'      => sprintf( '%s was not removed because another Booster operation still owns it.', $type ),
			'operation_lock_failed'      => sprintf( '%s removal could not safely acquire or release the WordPress updater lock.', $type ),
			'shared_plugin_directory'    => 'Plugin was not removed because its directory contains another registered plugin.',
			'stale'                      => sprintf( '%s settings changed before this request. Refresh the package settings and try again.', $type ),
			'theme_active'               => 'Theme was not removed because it is the active theme.',
			'theme_has_children'         => 'Theme was not removed because an installed child theme depends on it.',
			'theme_parent_in_use'        => 'Theme was not removed because the active theme depends on it.',
			'unsafe_path'                => sprintf( '%s was not removed because WordPress could not verify a safe installed path.', $type ),
			default                      => sprintf( '%s removal could not be completed safely.', $type ),
		};

		status_header( 400 );
		$this->addMessageWithContext(
			new \WP_Error( 'ran_booster_package_removal_' . $outcomeCode, $message ),
			array(
				'operation'    => $operation->operation,
				'package_type' => $operation->packageType,
				'step'         => 'package_removal',
			)
		);
	}

	private function packageStorageFailureIndex( PackageViewConfig $packageView, string $type, PackageStorageFailure $failure ): mixed {
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

	private function databaseUnavailableCreate( PackageViewConfig $packageView, string $type ): mixed {
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
			array_merge(
				$this->packageProviderData( $this->requestedProvider() ),
				array(
					'packageView'              => $packageView,
					'explicitProvider'         => $this->hasRequestedProvider(),
					'openRepositoryPicker'     => false,
					'packageMutationAvailable' => false,
				)
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

	private function packageSuccessRedirect( PackageOperation $operation, Package|string $package, bool $installAnother = false, bool $returnToSettings = false ): string {
		$identifier = $package instanceof Package ? $package->getIdentifier() : $package;
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new LogicException( 'The deployed package identity is unavailable.' );
		}
		$noticeAction = $this->packageSuccessNonceAction( $operation->packageType, $operation->operation, $identifier );
		$adminUrl     = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		$packageView  = 'plugin' === $operation->packageType ? PackageViewConfig::plugin() : PackageViewConfig::theme();
		$args         = array(
			'page'                      => $installAnother ? $packageView->getCreatePageSlug() : $packageView->getPageSlug(),
			'ran_booster_result'        => $operation->operation,
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( $noticeAction ),
		);

		if ( $installAnother ) {
			$args['provider']    = (string) $operation->providerCode;
			$args['open_picker'] = '1';
		} elseif ( in_array( $operation->operation, array( 'install', 'edit' ), true ) || $returnToSettings ) {
			$args['package'] = $identifier;
		} elseif ( 'update' === $operation->operation ) {
			$args = array_merge( $this->packageListQueryArguments(), $args );
		}

		return $adminUrl . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	private function addPackageSuccessNotice( string $type ): void {
		foreach ( array( 'ran_booster_result', 'ran_booster_package', '_ran_booster_notice_nonce' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
				return;
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only success marker is verified before use.
		$operation  = sanitize_key( wp_unslash( (string) $_GET['ran_booster_result'] ) );
		$identifier = sanitize_text_field( wp_unslash( (string) $_GET['ran_booster_package'] ) );
		$nonce      = wp_unslash( (string) $_GET['_ran_booster_notice_nonce'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $operation, array( 'install', 'update', 'edit', 'unlink', 'unlink-and-delete' ), true )
			|| false === wp_verify_nonce( $nonce, $this->packageSuccessNonceAction( $type, $operation, $identifier ) )
		) {
			return;
		}

		$label     = 'plugin' === $type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' );
		$completed = match ( $operation ) {
			'install' => __( 'installed', 'ran-booster' ),
			'update' => __( 'updated', 'ran-booster' ),
			'edit' => __( 'saved', 'ran-booster' ),
			'unlink' => __( 'unlinked', 'ran-booster' ),
			default => __( 'unlinked and deleted', 'ran-booster' ),
		};
		$this->messages[] = array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: package type, 2: completed operation. */
				__( '%1$s was successfully %2$s.', 'ran-booster' ),
				$label,
				$completed
			),
		);
	}

	private function packageSuccessNonceAction( string $type, string $operation, string $identifier ): string {
		return 'ran-booster-package-success|' . $type . '|' . $operation . '|' . $identifier;
	}

	public function bulkPackageRedirect( string $type, BulkPackageResult $result ): string {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			throw new LogicException( 'The bulk package redirect type is invalid.' );
		}

		$data = $result->noticeData();
		$args = array();
		foreach ( $data as $key => $value ) {
			$args[ 'ran_booster_bulk_' . $key ] = $value;
		}
		$args['_ran_booster_bulk_notice_nonce'] = wp_create_nonce( $this->bulkPackageNoticeAction( $type, $data ) );

		$adminUrl = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		return $adminUrl . '?' . http_build_query(
			array( 'page' => 'ran-booster-' . ( 'plugin' === $type ? 'plugins' : 'themes' ) ) + $this->packageListQueryArguments() + $args,
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	private function addBulkPackageNotice( string $type ): void {
		$data = array();
		foreach ( array( 'operation', 'selected', 'changed', 'unchanged', 'queued', 'skips', 'runner', 'error' ) as $key ) {
			$queryKey = 'ran_booster_bulk_' . $key;
			if ( ! isset( $_GET[ $queryKey ] ) || ! is_scalar( $_GET[ $queryKey ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
			$data[ $key ] = wp_unslash( (string) $_GET[ $queryKey ] );
		}
		if ( ! isset( $_GET['_ran_booster_bulk_notice_nonce'] ) || ! is_scalar( $_GET['_ran_booster_bulk_notice_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification is the purpose of this read.
		$nonce = wp_unslash( (string) $_GET['_ran_booster_bulk_notice_nonce'] );
		if ( false === wp_verify_nonce( $nonce, $this->bulkPackageNoticeAction( $type, $data ) ) ) {
			return;
		}

		try {
			$result = BulkPackageResult::fromNoticeData( $data );
		} catch ( \InvalidArgumentException ) {
			return;
		}

		$plural = 'plugin' === $type ? __( 'plugins', 'ran-booster' ) : __( 'themes', 'ran-booster' );
		if ( '' !== $result->errorCode ) {
			$errors = array(
				'credential_unavailable' => __( 'A selected package does not have its required repository credential.', 'ran-booster' ),
				'invalid_request'        => __( 'Choose a bulk action and a supported number of managed packages.', 'ran-booster' ),
				'provider_unavailable'   => __( 'A selected package uses an unavailable repository provider.', 'ran-booster' ),
				'stale'                  => __( 'A selected managed package changed or is no longer available. Refresh and try again.', 'ran-booster' ),
				'unavailable'            => __( 'Booster could not safely complete this bulk action. No success was reported.', 'ran-booster' ),
				'webhook_unavailable'    => __( 'A selected package provider does not support Automatic deployment.', 'ran-booster' ),
			);
			$this->addMessageWithContext(
				array(
					'type'    => 'error',
					'message' => $errors[ $result->errorCode ] ?? $errors['unavailable'],
					'code'    => 'ran_booster_bulk_' . $result->errorCode,
				),
				array(
					'operation'    => $result->operation,
					'outcome_code' => $result->errorCode,
					'step'         => 'bulk_package_action',
				)
			);

			return;
		}

		if ( BulkPackageAction::QUEUE_UPDATE === $result->operation ) {
			$message = sprintf(
				/* translators: 1: queued count, 2: package type, 3: skipped count. */
				__( 'Queued %1$d %2$s for sequential branch reinstall. Skipped: %3$d.', 'ran-booster' ),
				$result->queued,
				$plural,
				$result->skipped()
			);
			$reasonLabels = array(
				'busy'                   => __( 'already queued, running, or needs attention', 'ran-booster' ),
				'credential_unavailable' => __( 'credential unavailable', 'ran-booster' ),
				'disabled'               => __( 'deployment disabled', 'ran-booster' ),
				'provider_unavailable'   => __( 'provider unavailable', 'ran-booster' ),
				'release_source'         => __( 'published-release source', 'ran-booster' ),
				'self_update'            => __( 'Booster self-update blocked', 'ran-booster' ),
				'stale'                  => __( 'selection stale', 'ran-booster' ),
			);
			if ( array() !== $result->skippedByReason ) {
				$details = array();
				foreach ( $result->skippedByReason as $reason => $count ) {
					$details[] = ( $reasonLabels[ $reason ] ?? $reason ) . ': ' . $count;
				}
				$message .= ' ' . implode( '; ', $details ) . '.';
			}
			if ( 'unavailable' === $result->runnerStatus && $result->queued > 0 ) {
				$message .= ' ' . __( 'The updates remain queued, but WordPress could not schedule the deployment runner. Open Troubleshooting to request it.', 'ran-booster' );
			}
			$this->addMessageWithContext(
				array(
					'type'            => $result->skipped() > 0 || 'unavailable' === $result->runnerStatus ? 'warning' : 'success',
					'message'         => $message,
					'code'            => 'bulk_update_queue',
					'queued_updates'  => $result->queued,
					'skipped_updates' => $result->skipped(),
				),
				array(
					'operation' => $result->operation,
					'step'      => 'bulk_package_action',
				)
			);

			return;
		}

		if ( in_array( $result->operation, BulkPackageAction::pluginActivationOperations(), true ) ) {
			$enabled = BulkPackageAction::ACTIVATE_PLUGINS === $result->operation;
			$message = sprintf(
				/* translators: 1: changed count, 2: enabled or disabled label, 3: unchanged count, 4: skipped count. */
				__( 'Changed %1$d plugins to %2$s in WordPress. Already in that state: %3$d. Skipped: %4$d.', 'ran-booster' ),
				$result->changed,
				$enabled ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ),
				$result->unchanged,
				$result->skipped()
			);
			$reasonLabels = array(
				'active_dependents'   => __( 'required by active plugins', 'ran-booster' ),
				'activation_failed'   => __( 'activation failed', 'ran-booster' ),
				'deactivation_failed' => __( 'deactivation failed', 'ran-booster' ),
				'permission'          => __( 'permission denied', 'ran-booster' ),
				'self_deactivation'   => __( 'Booster cannot disable itself', 'ran-booster' ),
				'stale'               => __( 'selection stale', 'ran-booster' ),
			);
			if ( array() !== $result->skippedByReason ) {
				$details = array();
				foreach ( $result->skippedByReason as $reason => $count ) {
					$details[] = ( $reasonLabels[ $reason ] ?? $reason ) . ': ' . $count;
				}
				$message .= ' ' . implode( '; ', $details ) . '.';
			}
			$this->addMessageWithContext(
				array(
					'type'    => $result->skipped() > 0 ? 'warning' : 'success',
					'message' => $message,
					'code'    => 'bulk_plugin_state',
				),
				array(
					'operation' => $result->operation,
					'step'      => 'bulk_package_action',
				)
			);

			return;
		}

		$policyLabel = match ( $result->operation ) {
			BulkPackageAction::POLICY_DISABLED => __( 'Disabled', 'ran-booster' ),
			BulkPackageAction::POLICY_AUTOMATIC => __( 'Automatic', 'ran-booster' ),
			default => __( 'Manual', 'ran-booster' ),
		};
		$this->messages[] = array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: changed count, 2: package type, 3: policy label, 4: unchanged count. */
				__( 'Changed %1$d %2$s to %3$s. Already in that state: %4$d.', 'ran-booster' ),
				$result->changed,
				$plural,
				$policyLabel,
				$result->unchanged
			),
		);
	}

	/** @param array<string, string> $data */
	private function bulkPackageNoticeAction( string $type, array $data ): string {
		ksort( $data, SORT_STRING );

		return 'ran-booster-bulk-result|' . $type . '|' . hash(
			'sha256',
			http_build_query( $data, '', '&', PHP_QUERY_RFC3986 )
		);
	}

	private function reportDeploymentFailure( mixed $outcomeCode, mixed $reference, string $operation ): void {
		if ( ! is_string( $outcomeCode ) || ! is_string( $reference ) || preg_match( '/^[a-f0-9]{32}$/D', $reference ) !== 1 ) {
			$this->reportManualFailure( null, $operation );

			return;
		}
		$message     = DeploymentOutcomeMessage::forCode( $outcomeCode );
		$activityUrl = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' );
		status_header( 400 );
		$this->addMessageWithContext(
			array(
				'type'    => 'error',
				'code'    => 'ran_booster_deployment_failed',
				'message' => sprintf(
					/* translators: 1: safe deployment result, 2: random support reference, 3: activity page URL. */
					__( '%1$s Reference: <code>%2$s</code>. <a href="%3$s">View deployment activity</a>.', 'ran-booster' ),
					$message,
					$reference,
					$activityUrl
				),
			),
			array(
				'correlation_id' => $reference,
				'operation'      => $operation,
				'outcome_code'   => $outcomeCode,
				'step'           => 'manual_package_operation',
			)
		);
	}

	private function reportManualFailure( ?Throwable $failure = null, string $operation = '' ): bool {
		$diagnosticId = 'ran_booster_manual_action_failed';
		status_header( 400 );
		$message = new WP_Error(
			$diagnosticId,
			__( 'RAN Booster could not complete this action. Reference: ran_booster_manual_action_failed.', 'ran-booster' )
		);
		$context = array(
			'operation' => $operation,
			'step'      => 'manual_package_operation',
		);
		if ( null === $failure ) {
			$this->addMessageWithContext( $message, $context );
		} else {
			$this->addFailureMessage( $message, $failure, $context );
		}
		return false;
	}

	private function reportActiveDeployment( DeploymentStorageFailure $failure, string $operation ): bool {
		$attempt = $failure->getActiveAttempt();
		if ( null === $attempt ) {
			return $this->reportManualFailure( $failure, $operation );
		}

		$reference   = (string) $attempt['correlation_id'];
		$state       = (string) $attempt['state'];
		$packageType = (string) $attempt['package_type'];
		$packageSlug = (string) $attempt['package_slug'];
		$activityUrl = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' )
			. '&attempt=' . rawurlencode( (string) $attempt['id'] )
			. '&reference=' . rawurlencode( $reference );
		$severity    = 'needs_attention' === $state ? 'error' : 'info';
		if ( 'needs_attention' === $state ) {
			$this->recordRejectedNeedsAttentionRetry( $attempt, $operation );
			$message = sprintf(
				/* translators: 1: package type, 2: package slug, 3: activity record link. */
				__( 'An earlier deployment for the %1$s %2$s could not be verified and must be acknowledged before retrying. It is not currently running. <a href="%3$s">Open its recovery details</a>.', 'ran-booster' ),
				esc_html( $packageType ),
				esc_html( $packageSlug ),
				esc_url( $activityUrl )
			);
		} else {
			$message = sprintf(
				/* translators: 1: package type, 2: package slug, 3: deployment state, 4: activity record link. */
				__( 'Booster is already tracking the %1$s %2$s in state %3$s. <a href="%4$s">Review this deployment activity record</a> before trying again.', 'ran-booster' ),
				esc_html( $packageType ),
				esc_html( $packageSlug ),
				esc_html( $state ),
				esc_url( $activityUrl )
			);
		}

		status_header( 409 );
		$this->addFailureMessage(
			array(
				'type'    => $severity,
				'code'    => 'ran_booster_deployment_active',
				'message' => $message,
			),
			$failure,
			array(
				'correlation_id' => $reference,
				'operation'      => $operation,
				'step'           => 'manual_package_operation',
			)
		);

		return false;
	}

	/** @param array<string, bool|int|string|null> $attempt */
	private function recordRejectedNeedsAttentionRetry( array $attempt, string $operation ): void {
		if ( null === $this->rejectedAdmissionAudit || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}

		$userId = (int) get_current_user_id();
		if ( $userId < 1 ) {
			return;
		}

		try {
			$this->rejectedAdmissionAudit->recordBlockedByNeedsAttention( $attempt, $userId, $operation );
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'rejected deployment admission audit unavailable',
				$failure,
				array(
					'attempt_id' => $attempt['id'] ?? null,
					'operation'  => $operation,
					'step'       => 'rejected_admission_audit',
				)
			);
		}
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

	/** @return array{packageProviderSettings: array<string, mixed>} */
	private function packageProviderData( ?string $defaultProvider = null ): array {
		return array(
			'packageProviderSettings' => $this->providerSettings->buildPackageForm( $defaultProvider ),
		);
	}

	/** @return array{packageProviderSettings: array<string, mixed>, packageBranchReadiness: array<string, mixed>|null, packageWebhookCleanup: array<string, mixed>|null} */
	private function existingPackageProviderData( Package $package, PackageViewConfig $packageView ): array {
		return array(
			'packageProviderSettings' => $this->providerSettings->buildExistingPackageForm(
				(string) ( $package->getProviderCode() ?? '' )
			),
			'packageBranchReadiness'  => $this->providerSettings->buildPackageBranchReadiness( $package ),
			'packageWebhookCleanup'   => $this->packageWebhookCleanup( $package, $packageView ),
		);
	}

	/** @return array{context: WebhookCleanupContext, actions: list<string>}|null */
	private function packageWebhookCleanup( Package $package, PackageViewConfig $packageView ): ?array {
		$retention = $this->providerSettings->buildPackageWebhookRetention( $package );
		if ( null === $retention ) {
			return null;
		}

		try {
			$adminUrl = $this->packageAdminUrl();
			$context  = new WebhookCleanupContext(
				$packageView->getType(),
				(string) $package->getIdentifier(),
				(string) $retention['provider_code'],
				(string) $retention['repository_id'],
				(string) $retention['repository'],
				(string) $retention['local_secret_coverage'],
				true === $retention['available'],
				true === $retention['branch_evidence_available'],
				$retention['branch_package_references'],
				(string) $retention['provider_webhooks_url'],
				add_query_arg(
					array(
						'page' => 'ran-booster',
						'tab'  => (string) $retention['provider_code'],
						'view' => 'secrets',
					),
					$adminUrl
				),
				add_query_arg(
					array(
						'page' => 'ran-booster',
						'tab'  => 'documentation',
					),
					$adminUrl
				) . '#ran-booster-webhook-cleanup',
				add_query_arg(
					array(
						'page'    => $packageView->getPageSlug(),
						'package' => (string) $package->getIdentifier(),
					),
					$adminUrl
				)
			);
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'package webhook cleanup context unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'package_webhook_cleanup_context',
					'operation' => $packageView->getType(),
				)
			);

			return null;
		}

		$bufferLevel = ob_get_level();
		ob_start();
		try {
			do_action( 'ran_booster_admin_package_webhook_cleanup_actions', $context );
			$content = (string) ob_get_clean();
			$actions = '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			$actions = array();
			BoosterLogger::logException(
				'package webhook cleanup action unavailable',
				$failure,
				array(
					'source'    => 'admin',
					'step'      => 'package_webhook_cleanup_action',
					'operation' => $packageView->getType(),
				)
			);
		}

		return array(
			'context' => $context,
			'actions' => $actions,
		);
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
		$value = $_GET['repository'] ?? $_GET['assisted_repository'] ?? null;
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

	/** @return array<string, mixed> */
	private function activity(): array {
		$base       = array(
			'mode'                      => 'list',
			'items'                     => array(),
			'unavailable'               => null === $this->deploymentAttempts,
			'has_cursor'                => false,
			'next_cursor'               => null,
			'rejected_admission_events' => array(),
			'later_verified_attempt'    => null,
			'package_settings_urls'     => array(),
		);
		$hasAttempt = $this->queryHasKey( 'attempt' );
		$hasRef     = $this->queryHasKey( 'reference' );
		if ( $hasAttempt || $hasRef ) {
			$base['mode'] = 'detail';
		}
		if ( null === $this->deploymentAttempts ) {
			return $base;
		}
		$base['package_settings_urls'] = $this->activityPackageSettingsUrls();

		$attemptId = $this->positiveQueryInteger( 'attempt' );
		$reference = $this->hexQueryValue( 'reference', 32 );
		if ( $hasAttempt || $hasRef ) {
			if ( null === $attemptId || null === $reference ) {
				return $base;
			}
			try {
				$detail         = $this->deploymentAttempts->findExact( $attemptId );
				$base['detail'] = null !== $detail && hash_equals( $detail->getCorrelationId(), $reference ) ? $detail : null;
				if ( null !== $base['detail'] && 'restoration_uncertain' === $base['detail']->getOutcome()?->getCode() ) {
					$detailData      = $base['detail']->safeData();
					$packageActivity = $this->deploymentAttempts->packageActivitySummary( (string) $detailData['package_type'], (string) $detailData['package_slug'] );
					$laterSuccess    = $packageActivity['last_successful'];
					if ( null !== $laterSuccess && $laterSuccess->getId() > $base['detail']->getId() ) {
						$base['later_verified_attempt'] = $laterSuccess;
					}
				}
				$base['rejected_admission_events'] = $this->rejectedAdmissionEvents( $attemptId );
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
					'deployment activity detail unavailable',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'deployment_activity_detail',
					)
				);
				$base['unavailable'] = true;
			}

			return $base;
		}

		$hasBefore          = $this->queryHasKey( 'before' );
		$before             = $this->queryScalarValue( 'before' );
		$base['has_cursor'] = $hasBefore;
		if ( $hasBefore && ( null === $before || '' === $before ) ) {
			$base['unavailable'] = true;

			return $base;
		}
		try {
			$beforeId = null === $before ? null : ( ctype_digit( $before ) && (string) (int) $before === $before && (int) $before > 0 ? (int) $before : null );
			if ( $hasBefore && null === $beforeId ) {
				$base['unavailable'] = true;
				return $base;
			}
			$items   = $this->deploymentAttempts->recentHistory( self::DEPLOYMENT_ACTIVITY_PAGE_SIZE + 1, $beforeId );
			$hasMore = count( $items ) > self::DEPLOYMENT_ACTIVITY_PAGE_SIZE;
			if ( $hasMore ) {
				$items = array_slice( $items, 0, self::DEPLOYMENT_ACTIVITY_PAGE_SIZE );
			}
			$base['items']                     = $items;
			$last                              = end( $items );
			$base['next_cursor']               = $hasMore && false !== $last ? $last->getId() : null;
			$base['rejected_admission_events'] = $this->rejectedAdmissionEvents();
			$base['unavailable']               = false;
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'deployment activity history unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'deployment_activity_history',
				)
			);
			$base['unavailable'] = true;
		}

		return $base;
	}

	/** @return array<'plugin'|'theme', array<string, string>> */
	private function activityPackageSettingsUrls(): array {
		$urls = array(
			'plugin' => array(),
			'theme'  => array(),
		);
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			try {
				$packages    = 'plugin' === $type
					? $this->plugins->allDeploymentPlugins()
					: $this->themes->allDeploymentThemes();
				$packageView = 'plugin' === $type ? PackageViewConfig::plugin() : PackageViewConfig::theme();
				$seen        = array();
				foreach ( $packages as $package ) {
					if ( ! $package instanceof Package ) {
						continue;
					}
					$slug = (string) $package->getSlug();
					if ( '' === $slug || isset( $seen[ $slug ] ) ) {
						unset( $urls[ $type ][ $slug ] );
						$seen[ $slug ] = true;
						continue;
					}
					$seen[ $slug ]          = true;
					$urls[ $type ][ $slug ] = $this->packageSettingsUrl( $package, $packageView );
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
					'deployment activity package settings links unavailable',
					$failure,
					array(
						'source' => 'admin',
						'step'   => 'deployment_activity_package_links',
					)
				);
			}
		}

		return $urls;
	}

	/** @return list<array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string}> */
	private function rejectedAdmissionEvents( ?int $attemptId = null ): array {
		if ( null === $this->rejectedAdmissionAudit ) {
			return array();
		}

		try {
			$events = $this->rejectedAdmissionAudit->recent();
			if ( null === $attemptId ) {
				return $events;
			}

			return array_values(
				array_filter(
					$events,
					static fn ( array $event ): bool => $attemptId === $event['attempt_id']
				)
			);
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'rejected deployment admission audit history unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'rejected_admission_audit_history',
				)
			);

			return array();
		}
	}

	/**
	 * @param list<Package> $packages
	 * @return array{items: array<string, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null}>, unavailable: bool}
	 */
	private function packageActivity( array $packages, string $type ): array {
		if ( null === $this->deploymentAttempts || count( $packages ) > 50 ) {
			return array(
				'items'       => array(),
				'unavailable' => true,
			);
		}
		$items = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || ! is_string( $package->getIdentifier() ) ) {
				return array(
					'items'       => array(),
					'unavailable' => true,
				);
			}
			if ( PackageSource::RELEASE_ASSET === $package->getSource() ) {
				continue;
			}
			try {
				$items[ $package->getIdentifier() ] = $this->deploymentAttempts->packageActivitySummary(
					$type,
					(string) $package->getSlug()
				);
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
					'package deployment activity unavailable',
					$failure,
					array(
						'operation' => 'read-' . $type . '-package-activity',
						'source'    => 'admin',
						'step'      => 'package_activity_summary',
					)
				);
				return array(
					'items'       => array(),
					'unavailable' => true,
				);
			}
		}

		return array(
			'items'       => $items,
			'unavailable' => false,
		);
	}

	private function queryHasScalar( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] );
	}

	private function queryScalarValue( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, subsequently validated paging state.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : null;
	}

	private function queryHasKey( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence keeps malformed detail identities from broadening into a list query.
		return array_key_exists( $key, $_GET );
	}

	private function positiveQueryInteger( string $key ): ?int {
		if ( ! $this->queryHasScalar( $key ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		$value = wp_unslash( (string) $_GET[ $key ] );
		if ( preg_match( '/^[1-9][0-9]*$/D', $value ) !== 1 || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			return null;
		}
		$integer = (int) $value;

		return $integer > 0 && (string) $integer === $value ? $integer : null;
	}

	private function hexQueryValue( string $key, int $length ): ?string {
		if ( ! $this->queryHasScalar( $key ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		$value = wp_unslash( (string) $_GET[ $key ] );

		return preg_match( sprintf( '/^[a-f0-9]{%d}$/D', $length ), $value ) === 1 ? $value : null;
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
			$tabs[] = array(
				'key'      => $tab->getKey(),
				'label'    => $tab->getLabel(),
				'url'      => $adminUrl . '?page=ran-booster&tab=' . rawurlencode( $tab->getKey() ),
				'active'   => $selectedKey === $tab->getKey(),
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

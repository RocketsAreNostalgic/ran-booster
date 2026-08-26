<?php

declare(strict_types=1);

namespace RAN;

use RAN\AddOn\Portability\NativePortabilityFacade;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\WebhookAssistance\AssistedWebhookFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentArchivePreflight;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentWorker;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Admin\PortabilityController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\PackageUpdateProgressController;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\BulkPackageActionService;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryNoticeController;
use RAN\Admin\CredentialExpiryReminder;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\BackgroundDeploymentFailureEmail;
use RAN\Admin\BackgroundDeploymentFailureMonitor;
use RAN\Admin\ManagedPluginFailureRows;
use RAN\Admin\SecretsRuntimeAvailabilityNotice;
use RAN\Admin\DatabaseCompatibilityNotice;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Internal\CoreContainer;
use RAN\Internal\ReleaseManagement\ProspectiveReleaseCandidateReader;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowControls;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;
use RAN\Secrets\SecretsStorageProvisioner;
use RAN\Secrets\SecretsStorageUnavailable;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PortabilityApplicationService;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\PackageRemoval\WordPressPackageRemovalGateway;
use RAN\Storage\Database;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RAN\Troubleshooting\TroubleshootingService;
use RAN\Webhook\SignedWebhookVerifier;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;

final class BoosterServiceProvider {
	private readonly ?\Closure $secretsFactory;

	/** @param callable(ProviderSecretPolicyCatalog): SecretsFile|null $secretsFactory */
	public function __construct( ?callable $secretsFactory = null ) {
		$this->secretsFactory = null === $secretsFactory ? null : \Closure::fromCallable( $secretsFactory );
	}

	/** @internal Core bootstrap composition only. */
	public function register( CoreContainer $container, Booster $runtime ): void {
		$database       = new Database();
		$secretsRuntime = new SecretsRuntimeAvailability();
		$secretPolicies = new ProviderSecretPolicyCatalog();
		$secrets        = null === $this->secretsFactory
			? new SecretsFile( providerPolicies: $secretPolicies, availability: $secretsRuntime )
			: ( $this->secretsFactory )( $secretPolicies );
		if ( ! $secrets instanceof SecretsFile ) {
			throw new \LogicException( 'The Booster secrets factory must return a SecretsFile.' );
		}
		$debugCapture = new TemporaryDebugCapture( $secrets->path() );
		BoosterLogger::configureCapture( $debugCapture );
		$adminInteraction = new CoreAdminInteractionFacade();
		$adminInteraction->register();

		add_action(
			'admin_init',
			static function () use ( $container, $runtime, $secrets, $debugCapture ): void {
				// Validate only when an administrator opens Booster. Routine front-end
				// requests and unrelated admin/AJAX traffic must remain read-only.
				if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
					return;
				}

				// Read-only routing state; sidecar verification is protected by the
				// sidecar lock and performs no WordPress/database mutation.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing determines whether to inspect the sidecar.
				$pageInput = $_GET['page'] ?? '';
				$page      = is_string( $pageInput )
					? sanitize_key( wp_unslash( $pageInput ) )
					: '';
				if ( ! str_starts_with( $page, 'ran-booster' ) ) {
					return;
				}

				// Capture expiry is file-only and lazy; no cron or database state is needed.
				try {
					$debugCapture->snapshot();
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The panel reports optional capture availability.
				} catch ( \Throwable ) {
					// The troubleshooting panel reports capture availability.
				}

				if ( $runtime->isPassiveTroubleshootingRequest() ) {
					return;
				}

				try {
					$secrets->verifyAndSecure();
				} catch ( SecretsStorageUnavailable ) {
					// The typed, pathless storage notice and Overview status own this state.
					return;
				} catch ( \Throwable $exception ) {
					$runtimeDashboard = $container->make( Dashboard::class );
					$runtimeDashboard->addFailureMessage(
						new \WP_Error(
							'ran_booster_secrets_validation_error',
							__( 'Booster could not validate the credentials sidecar.', 'ran-booster' )
						),
						$exception,
						array( 'step' => 'secrets_sidecar_validation' )
					);
				}
			}
		);

		$container->bind( Database::class, $database );
		$container->bind( AdminInteractionFacade::class, $adminInteraction );
		$container->bind( CoreAdminInteractionFacade::class, $adminInteraction );
		$container->bind(
			WebhookAssistanceReadinessEvaluator::class,
			static fn ( CoreContainer $container ): WebhookAssistanceReadinessEvaluator => new WebhookAssistanceReadinessEvaluator(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( SecretsFile::class ),
				$container->make( Database::class )
			)
		);
		$expiryObservations = new CredentialExpiryObservationStore();
		$container->bind( SecretsFile::class, $secrets );
		$container->bind( SecretsRuntimeAvailability::class, $secretsRuntime );
		$container->bind(
			SecretsStorageProvisioner::class,
			new SecretsStorageProvisioner( secrets: $secrets )
		);
		$container->bind( SecretsRuntimeAvailabilityNotice::class, new SecretsRuntimeAvailabilityNotice( $secretsRuntime ) );
		$container->bind( DatabaseCompatibilityNotice::class, new DatabaseCompatibilityNotice( $database ) );
		$container->bind( TemporaryDebugCapture::class, $debugCapture );
		$container->bind( CredentialExpiryObservationStore::class, $expiryObservations );
		$container->bind(
			CredentialSelfDestructPurger::class,
			static fn ( CoreContainer $container ): CredentialSelfDestructPurger => new CredentialSelfDestructPurger(
				$container->make( SecretsFile::class ),
				$container->make( CredentialExpiryObservationStore::class ),
				$container->make( PublicRepositoryLookupProfileStore::class )
			)
		);
		$container->bind(
			ManagedPackageBlueprintExporter::class,
			static fn ( CoreContainer $container ): ManagedPackageBlueprintExporter => new ManagedPackageBlueprintExporter(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( SecretsFile::class )
			)
		);
		$container->bind( BlueprintArchive::class, new BlueprintArchive() );
		$container->bind(
			BlueprintReviewer::class,
			static fn ( CoreContainer $container ): BlueprintReviewer => new BlueprintReviewer(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class )
			)
		);
		$container->bind(
			BlueprintRepositoryVerifier::class,
			static fn ( CoreContainer $container ): BlueprintRepositoryVerifier => new BlueprintRepositoryVerifier(
				$container->make( ProviderRegistry::class ),
				$container->make( SecretsFile::class )
			)
		);
		$container->bind( CredentialUsageReader::class, new CredentialUsageReader( null, null, $database ) );
		$container->bind( SignedWebhookVerifier::class, new SignedWebhookVerifier( $secrets ) );
		$container->bind(
			DeploymentAttemptRepository::class,
			static function ( CoreContainer $container ): DeploymentAttemptRepository {
				global $wpdb;

				return new DeploymentAttemptRepository(
					$wpdb,
					Database::attemptTableName(),
					null,
					null,
					$container->make( Database::class )
				);
			}
		);
		$providers = new ProviderRegistry(
			array(),
			$secretPolicies,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code ),
			static fn ( ProviderCode $code ): \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader => new \RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader(
				$code,
				static fn ( ProviderCode $boundCode ): ?\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence => $container
					->make( DeploymentAttemptRepository::class )
					->latestAuthenticatedDelivery( $boundCode )
			)
		);
		$providers->registerWithCredentialStore(
			'gh',
			static fn ( ProviderCredentialStore $credentials, \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence ): RepositoryProvider => GitHubProvider::create(
				$credentials,
				$deliveryEvidence
			)
		);
		$container->bind( ProviderRegistry::class, $providers );
		$container->bind(
			GitHubReleaseWorkflowControls::class,
			static fn ( CoreContainer $container ): GitHubReleaseWorkflowControls => new GitHubReleaseWorkflowControls(
				$container->make( ReleaseTrackingFacade::class ),
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$secrets->credentialsFor( 'gh' )
			)
		);
		$container->bind(
			WebhookAssistanceFacade::class,
			static fn ( CoreContainer $container ): WebhookAssistanceFacade => new AssistedWebhookFacade(
				$container->make( WebhookAssistanceReadinessEvaluator::class ),
				$container->make( SecretsFile::class ),
				$container->make( ProviderRegistry::class )
			)
		);
		$webhookControls = new RepositoryWebhookManagementControls(
			$container->make( WebhookAssistanceFacade::class ),
			$container->make( AdminInteractionFacade::class ),
			$container->make( ProviderRegistry::class ),
			(string) $runtime->boosterPath,
			(string) $runtime->boosterUrl
		);
		$container->bind( RepositoryWebhookManagementControls::class, $webhookControls );
		$expiryReminders = new CredentialExpiryReminder(
			$container->make( ProviderRegistry::class ),
			$secrets,
			$expiryObservations
		);
		$container->bind( CredentialExpiryReminder::class, $expiryReminders );
		$container->bind( CredentialExpiryNotice::class, new CredentialExpiryNotice( $expiryReminders ) );
		$container->bind( CredentialExpiryNoticeController::class, new CredentialExpiryNoticeController( $expiryReminders ) );
		$backgroundFailureMonitor = new BackgroundDeploymentFailureMonitor(
			$container->make( DeploymentAttemptRepository::class ),
			$container->make( ProviderRegistry::class )
		);
		$container->bind( BackgroundDeploymentFailureMonitor::class, $backgroundFailureMonitor );
		$container->bind( BackgroundDeploymentFailureEmail::class, new BackgroundDeploymentFailureEmail() );
		$container->bind(
			ManagedPluginFailureRows::class,
			new ManagedPluginFailureRows(
				$container->make( PluginRepository::class ),
				$backgroundFailureMonitor
			)
		);
		$container->bind(
			PackageUpdateProgressController::class,
			static fn ( CoreContainer $container ): PackageUpdateProgressController => new PackageUpdateProgressController(
				$container->make( DeploymentAttemptRepository::class )
			)
		);
		$container->bind( DeploymentArchivePreflight::class, new DeploymentArchivePreflight() );
		$container->bind( CorePackageExecutor::class, new CorePackageExecutor() );
		$container->bind( WordPressUpdaterLock::class, new WordPressUpdaterLock() );
		$container->bind(
			WordPressWorkerWakeup::class,
			static fn ( CoreContainer $container ): WordPressWorkerWakeup => new WordPressWorkerWakeup(
				$container->make( DeploymentAttemptRepository::class )
			)
		);
		$container->bind(
			DeploymentCoordinator::class,
			static function ( CoreContainer $container ): DeploymentCoordinator {
				return new DeploymentCoordinator(
					$container->make( DeploymentAttemptRepository::class ),
					$container->make( PluginRepository::class ),
					$container->make( ThemeRepository::class ),
					$container->make( ProviderRegistry::class ),
					$container->make( DeploymentArchivePreflight::class ),
					$container->make( CorePackageExecutor::class ),
					$container->make( WordPressWorkerWakeup::class ),
					ABSPATH . '.maintenance',
					$container->make( WordPressUpdaterLock::class ),
					$container->make( BackgroundDeploymentFailureEmail::class )
				);
			}
		);
		$container->bind(
			DeploymentWorker::class,
			static fn ( CoreContainer $container ): DeploymentWorker => new DeploymentWorker(
				$container->make( DeploymentAttemptRepository::class ),
				$container->make( DeploymentCoordinator::class ),
				$container->make( WordPressWorkerWakeup::class )
			)
		);
		$container->bind(
			PackageRemovalGateway::class,
			new WordPressPackageRemovalGateway()
		);
		$container->bind(
			PackageRemovalService::class,
			static fn ( CoreContainer $container ): PackageRemovalService => new PackageRemovalService(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( PackageRemovalGateway::class ),
				$container->make( DeploymentAttemptRepository::class ),
				$container->make( WordPressUpdaterLock::class )
			)
		);
		$container->bind(
			PackageOperationService::class,
			static fn ( CoreContainer $container ): PackageOperationService => new PackageOperationService(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( DeploymentCoordinator::class ),
				$container->make( PackageRemovalService::class ),
				$container->make( WordPressUpdaterLock::class )
			)
		);
		$container->bind(
			BulkPackageActionService::class,
			static fn ( CoreContainer $container ): BulkPackageActionService => new BulkPackageActionService(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( ProviderRegistry::class ),
				$container->make( SecretsFile::class ),
				$container->make( DeploymentCoordinator::class ),
				$container->make( WordPressUpdaterLock::class )
			)
		);
		$container->bind(
			TroubleshootingService::class,
			static function ( CoreContainer $container ): TroubleshootingService {
				return new TroubleshootingService(
					$container->make( LocalTroubleshootingService::class ),
					$container->make( ProviderRegistry::class ),
					null,
					$container->make( SecretsFile::class ),
					$container->make( \RAN\Troubleshooting\CoreSelfUpdateStatus::class )
				);
			}
		);
		$container->bind(
			PortabilityApplicationService::class,
			static fn ( CoreContainer $container ): PortabilityApplicationService => new PortabilityApplicationService(
				$container->make( BlueprintReviewer::class ),
				$container->make( BlueprintRepositoryVerifier::class ),
				$container->make( PackageOperationService::class ),
				$container->make( SecretsFile::class )
			)
		);
		$container->bind(
			PortabilityFacade::class,
			static fn ( CoreContainer $container ): PortabilityFacade => new NativePortabilityFacade(
				$container->make( PortabilityApplicationService::class )
			)
		);
		$container->bind(
			PortabilityController::class,
			static fn ( CoreContainer $container ): PortabilityController => new PortabilityController(
				$container->make( ManagedPackageBlueprintExporter::class ),
				$container->make( BlueprintArchive::class ),
				$container->make( PortabilityApplicationService::class ),
				$container->make( ProviderSettingsPresenter::class )
			)
		);
		$releaseStore = new ManagedReleaseStore( null, $database );
		$container->bind( ManagedReleaseStore::class, $releaseStore );
		$releaseRegistrar = new ManagedReleaseTargetRegistrar(
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$releaseStore,
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class )
		);
		$container->bind( ManagedReleaseTargetRegistrar::class, $releaseRegistrar );
		$releaseFacade = new NativeReleaseTrackingFacade(
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$releaseStore,
			$releaseRegistrar,
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class ),
			publicLookupProfile: static fn ( string $provider ): ?string => $container->make( PublicRepositoryLookupProfileStore::class )->get( $provider )
		);
		$container->bind( NativeReleaseTrackingFacade::class, $releaseFacade );
		$container->bind( ReleaseTrackingFacade::class, $releaseFacade );
		$prospectiveFacade = new NativeProspectiveReleaseFacade(
			$container->make( PackageRepositoryRequestResolver::class ),
			$container->make( CorePackageExecutor::class ),
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class )
		);
		$container->bind( NativeProspectiveReleaseFacade::class, $prospectiveFacade );
		$container->bind( ProspectiveReleaseFacade::class, $prospectiveFacade );
		$container->bind(
			ReleaseManagementControls::class,
			static fn ( CoreContainer $container ): ReleaseManagementControls => new ReleaseManagementControls(
				$container->make( ReleaseTrackingFacade::class ),
				$container->make( ProspectiveReleaseFacade::class ),
				array(
					new ProspectiveReleaseCandidateReader(
						$container->make( PackageRepositoryRequestResolver::class ),
						$container->make( ProviderRegistry::class )
					),
					'read',
				),
				new \RAN\Admin\ReleaseManagement\NativeManagedReleaseBrowser( $container->make( NativeReleaseTrackingFacade::class ) )
			)
		);
	}
}

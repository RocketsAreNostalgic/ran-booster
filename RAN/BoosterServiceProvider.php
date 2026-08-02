<?php

declare(strict_types=1);

namespace RAN;

use RAN\AddOn\Portability\NativePortabilityFacade;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\Logging\CoreLoggingFacade;
use RAN\AddOn\Logging\LoggingFacade;
use RAN\AddOn\WebhookAssistance\AssistedWebhookFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookCleanupFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\CoreProviderProfileInteraction;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\RejectedAdmissionAuditRepository;
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
use RAN\Admin\BackgroundDeploymentFailureNotice;
use RAN\Admin\BackgroundDeploymentFailureNoticeController;
use RAN\Admin\ManagedPluginFailureRows;
use RAN\Admin\SecretsRuntimeAvailabilityNotice;
use RAN\Admin\DatabaseCompatibilityNotice;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;
use RAN\Secrets\SecretsStorageProvisioner;
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
use RAN\WordPress\ManagedReleasePreflight;
use RAN\WordPress\WordPressUpdaterLock;

final class BoosterServiceProvider implements ProviderInterface {
	private readonly ?\Closure $secretsFactory;

	/** @param callable(ProviderSecretPolicyCatalog): SecretsFile|null $secretsFactory */
	public function __construct( ?callable $secretsFactory = null ) {
		$this->secretsFactory = null === $secretsFactory ? null : \Closure::fromCallable( $secretsFactory );
	}

	public function register( Booster $booster ): void {
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
		$logging          = new CoreLoggingFacade();
		$githubBrowser    = new GitHubRepositoryBrowser( $secrets );
		$adminInteraction = new CoreAdminInteractionFacade();
		$adminInteraction->register();

		add_action(
			'admin_init',
			static function () use ( $booster, $secrets, $debugCapture ): void {
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

				if ( $booster->isPassiveTroubleshootingRequest() ) {
					return;
				}

				try {
					$secrets->verifyAndSecure();
				} catch ( \Throwable $exception ) {
					$booster->make( Dashboard::class )->addFailureMessage(
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

		// Bind the Booster instance itself to the container
		$booster->bind( 'RAN\Booster', $booster );
		$booster->bind( Database::class, $database );
		$booster->bind( LoggingFacade::class, $logging );
		$booster->bind( AdminInteractionFacade::class, $adminInteraction );
		$booster->bind( CoreProviderProfileInteraction::class, $adminInteraction );
		$booster->bind(
			WebhookAssistanceReadinessEvaluator::class,
			static fn ( Booster $booster ): WebhookAssistanceReadinessEvaluator => new WebhookAssistanceReadinessEvaluator(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class ),
				$booster->make( SecretsFile::class ),
				$booster->make( Database::class )
			)
		);
		$booster->bind(
			WebhookAssistanceFacade::class,
			static fn ( Booster $booster ): WebhookAssistanceFacade => new AssistedWebhookFacade(
				$booster->make( WebhookAssistanceReadinessEvaluator::class ),
				$booster->make( SecretsFile::class ),
			)
		);
		$booster->bind(
			WebhookCleanupFacade::class,
			static fn ( Booster $booster ): WebhookCleanupFacade => new AssistedWebhookFacade(
				$booster->make( WebhookAssistanceReadinessEvaluator::class ),
				$booster->make( SecretsFile::class ),
			)
		);
		$expiryObservations = new CredentialExpiryObservationStore();
		$booster->bind( SecretsFile::class, $secrets );
		$booster->bind( SecretsRuntimeAvailability::class, $secretsRuntime );
		$booster->bind(
			SecretsStorageProvisioner::class,
			new SecretsStorageProvisioner( secrets: $secrets )
		);
		$booster->bind( SecretsRuntimeAvailabilityNotice::class, new SecretsRuntimeAvailabilityNotice( $secretsRuntime ) );
		$booster->bind( DatabaseCompatibilityNotice::class, new DatabaseCompatibilityNotice( $database ) );
		$booster->bind( TemporaryDebugCapture::class, $debugCapture );
		$booster->bind( CredentialExpiryObservationStore::class, $expiryObservations );
		$booster->bind(
			CredentialSelfDestructPurger::class,
			static fn ( Booster $booster ): CredentialSelfDestructPurger => new CredentialSelfDestructPurger(
				$booster->make( SecretsFile::class ),
				$booster->make( CredentialExpiryObservationStore::class ),
				$booster->make( PublicRepositoryLookupProfileStore::class )
			)
		);
		$booster->bind(
			ManagedPackageBlueprintExporter::class,
			static fn ( Booster $booster ): ManagedPackageBlueprintExporter => new ManagedPackageBlueprintExporter(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class ),
				$booster->make( SecretsFile::class )
			)
		);
		$booster->bind( BlueprintArchive::class, new BlueprintArchive() );
		$booster->bind(
			BlueprintReviewer::class,
			static fn ( Booster $booster ): BlueprintReviewer => new BlueprintReviewer(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class )
			)
		);
		$booster->bind(
			BlueprintRepositoryVerifier::class,
			static fn ( Booster $booster ): BlueprintRepositoryVerifier => new BlueprintRepositoryVerifier(
				$booster->make( ProviderRegistry::class ),
				$booster->make( SecretsFile::class )
			)
		);
		$booster->bind( CredentialUsageReader::class, new CredentialUsageReader( null, null, $database ) );
		$booster->bind( SignedWebhookVerifier::class, new SignedWebhookVerifier( $secrets ) );
		$booster->bind(
			DeploymentAttemptRepository::class,
			static function ( Booster $booster ): DeploymentAttemptRepository {
				global $wpdb;

				return new DeploymentAttemptRepository(
					$wpdb,
					Database::attemptTableName(),
					null,
					null,
					$booster->make( Database::class )
				);
			}
		);
		$booster->bind(
			RejectedAdmissionAuditRepository::class,
			static function ( Booster $booster ): RejectedAdmissionAuditRepository {
				global $wpdb;

				return new RejectedAdmissionAuditRepository(
					$wpdb,
					Database::rejectedAdmissionAuditTableName(),
					null,
					$booster->make( Database::class )
				);
			}
		);
		$githubWebhooks = new GitHubWebhookNormalizer(
			$secrets,
			$booster->make( DeploymentAttemptRepository::class )
		);
		$booster->bind(
			ProviderRegistry::class,
			new ProviderRegistry(
				$logging,
				array(
					new GitHubProvider( $secrets, $githubBrowser, $githubWebhooks ),
				),
				$secretPolicies,
				static fn ( \RAN\RepositoryProvider\ProviderCode $code ): \RAN\RepositoryProvider\ProviderCredentialStore => $secrets->credentialsFor( $code )
			)
		);
		$expiryReminders = new CredentialExpiryReminder(
			$booster->make( ProviderRegistry::class ),
			$secrets,
			$expiryObservations
		);
		$booster->bind( CredentialExpiryReminder::class, $expiryReminders );
		$booster->bind( CredentialExpiryNotice::class, new CredentialExpiryNotice( $expiryReminders ) );
		$booster->bind( CredentialExpiryNoticeController::class, new CredentialExpiryNoticeController( $expiryReminders ) );
		$backgroundFailureMonitor = new BackgroundDeploymentFailureMonitor(
			$booster->make( DeploymentAttemptRepository::class ),
			$booster->make( ProviderRegistry::class )
		);
		$booster->bind( BackgroundDeploymentFailureMonitor::class, $backgroundFailureMonitor );
		$booster->bind( BackgroundDeploymentFailureNotice::class, new BackgroundDeploymentFailureNotice( $backgroundFailureMonitor ) );
		$booster->bind( BackgroundDeploymentFailureNoticeController::class, new BackgroundDeploymentFailureNoticeController( $backgroundFailureMonitor ) );
		$booster->bind( BackgroundDeploymentFailureEmail::class, new BackgroundDeploymentFailureEmail() );
		$booster->bind(
			ManagedPluginFailureRows::class,
			new ManagedPluginFailureRows(
				$booster->make( PluginRepository::class ),
				$backgroundFailureMonitor
			)
		);
		$booster->bind(
			PackageUpdateProgressController::class,
			static fn ( Booster $booster ): PackageUpdateProgressController => new PackageUpdateProgressController(
				$booster->make( DeploymentAttemptRepository::class )
			)
		);
		$booster->bind( DeploymentArchivePreflight::class, new DeploymentArchivePreflight() );
		$booster->bind( CorePackageExecutor::class, new CorePackageExecutor() );
		$booster->bind( WordPressUpdaterLock::class, new WordPressUpdaterLock() );
		$booster->bind(
			WordPressWorkerWakeup::class,
			static fn ( Booster $booster ): WordPressWorkerWakeup => new WordPressWorkerWakeup(
				$booster->make( DeploymentAttemptRepository::class )
			)
		);
		$booster->bind(
			DeploymentCoordinator::class,
			static function ( Booster $booster ): DeploymentCoordinator {
				return new DeploymentCoordinator(
					$booster->make( DeploymentAttemptRepository::class ),
					$booster->make( PluginRepository::class ),
					$booster->make( ThemeRepository::class ),
					$booster->make( ProviderRegistry::class ),
					$booster->make( DeploymentArchivePreflight::class ),
					$booster->make( CorePackageExecutor::class ),
					$booster->make( WordPressWorkerWakeup::class ),
					ABSPATH . '.maintenance',
					$booster->make( WordPressUpdaterLock::class ),
					$booster->make( BackgroundDeploymentFailureEmail::class )
				);
			}
		);
		$booster->bind(
			DeploymentWorker::class,
			static fn ( Booster $booster ): DeploymentWorker => new DeploymentWorker(
				$booster->make( DeploymentAttemptRepository::class ),
				$booster->make( DeploymentCoordinator::class ),
				$booster->make( WordPressWorkerWakeup::class )
			)
		);
		$booster->bind(
			PackageRemovalGateway::class,
			new WordPressPackageRemovalGateway()
		);
		$booster->bind(
			PackageRemovalService::class,
			static fn ( Booster $booster ): PackageRemovalService => new PackageRemovalService(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class ),
				$booster->make( PackageRemovalGateway::class ),
				$booster->make( DeploymentAttemptRepository::class ),
				$booster->make( WordPressUpdaterLock::class )
			)
		);
		$booster->bind(
			PackageOperationService::class,
			static fn ( Booster $booster ): PackageOperationService => new PackageOperationService(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class ),
				$booster->make( DeploymentCoordinator::class ),
				$booster->make( PackageRemovalService::class ),
				$booster->make( WordPressUpdaterLock::class )
			)
		);
		$booster->bind(
			BulkPackageActionService::class,
			static fn ( Booster $booster ): BulkPackageActionService => new BulkPackageActionService(
				$booster->make( PluginRepository::class ),
				$booster->make( ThemeRepository::class ),
				$booster->make( ProviderRegistry::class ),
				$booster->make( SecretsFile::class ),
				$booster->make( DeploymentCoordinator::class ),
				$booster->make( WordPressUpdaterLock::class )
			)
		);
		$booster->bind(
			TroubleshootingService::class,
			static function ( Booster $booster ): TroubleshootingService {
				return new TroubleshootingService(
					$booster->make( LocalTroubleshootingService::class ),
					$booster->make( ProviderRegistry::class ),
					null,
					$booster->make( SecretsFile::class ),
					$booster->make( \RAN\Troubleshooting\CoreSelfUpdateStatus::class )
				);
			}
		);
		$booster->bind(
			PortabilityApplicationService::class,
			static fn ( Booster $booster ): PortabilityApplicationService => new PortabilityApplicationService(
				$booster->make( BlueprintReviewer::class ),
				$booster->make( BlueprintRepositoryVerifier::class ),
				$booster->make( PackageOperationService::class ),
				$booster->make( SecretsFile::class )
			)
		);
		$booster->bind(
			PortabilityFacade::class,
			static fn ( Booster $booster ): PortabilityFacade => new NativePortabilityFacade(
				$booster->make( PortabilityApplicationService::class )
			)
		);
		$booster->bind(
			PortabilityController::class,
			static fn ( Booster $booster ): PortabilityController => new PortabilityController(
				$booster->make( ManagedPackageBlueprintExporter::class ),
				$booster->make( BlueprintArchive::class ),
				$booster->make( PortabilityApplicationService::class ),
				$booster->make( ProviderSettingsPresenter::class )
			)
		);
		$releaseStore = new ManagedReleaseStore( null, $database );
		$booster->bind( ManagedReleaseStore::class, $releaseStore );
		$releaseRegistrar = new ManagedReleaseTargetRegistrar(
			$booster->make( PluginRepository::class ),
			$booster->make( ThemeRepository::class ),
			$secrets,
			$releaseStore,
			$booster->make( WordPressUpdaterLock::class )
		);
		$booster->bind( ManagedReleaseTargetRegistrar::class, $releaseRegistrar );
		$releaseFacade = new NativeReleaseTrackingFacade(
			$booster->make( PluginRepository::class ),
			$booster->make( ThemeRepository::class ),
			$releaseStore,
			$releaseRegistrar,
			$booster->make( WordPressUpdaterLock::class ),
			releasePreflight: new ManagedReleasePreflight( $secrets ),
		);
		$booster->bind( NativeReleaseTrackingFacade::class, $releaseFacade );
		$booster->bind( ReleaseTrackingFacade::class, $releaseFacade );
		$prospectivePreflight = new ManagedReleasePreflight( $secrets );
		$prospectiveFacade    = new NativeProspectiveReleaseFacade(
			$booster->make( PackageRepositoryRequestResolver::class ),
			$prospectivePreflight,
			$booster->make( CorePackageExecutor::class ),
			$booster->make( PluginRepository::class ),
			$booster->make( ThemeRepository::class ),
			$booster->make( WordPressUpdaterLock::class )
		);
		$booster->bind( NativeProspectiveReleaseFacade::class, $prospectiveFacade );
		$booster->bind( ProspectiveReleaseFacade::class, $prospectiveFacade );
	}
}

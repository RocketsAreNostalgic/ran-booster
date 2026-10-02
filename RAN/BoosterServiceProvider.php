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
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\BackgroundDeploymentFailureEmail;
use RAN\Admin\BackgroundDeploymentFailureMonitor;
use RAN\Admin\ManagedPluginFailureRows;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\SecretsRuntimeAvailabilityNotice;
use RAN\Admin\DatabaseCompatibilityNotice;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Internal\CoreContainer;
use RAN\Internal\ReleaseManagement\ProspectiveReleaseCandidateReader;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
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
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use RAN\WordPress\WordPressUpdaterLock;

final class BoosterServiceProvider {
	private readonly ?\Closure $secrets_factory;

	/** @param callable(ProviderSecretPolicyCatalog): SecretsFile|null $secrets_factory */
	public function __construct( ?callable $secrets_factory = null ) {
		$this->secrets_factory = null === $secrets_factory ? null : \Closure::fromCallable( $secrets_factory );
	}

	/** @internal Core bootstrap composition only. */
	public function register( CoreContainer $container, Booster $runtime, object $release_updater, string $self_plugin_identifier ): void {
		$database       = new Database();
		$secrets_runtime = new SecretsRuntimeAvailability();
		$secret_policies = new ProviderSecretPolicyCatalog();
		$secrets        = null === $this->secrets_factory
			? new SecretsFile( provider_policies: $secret_policies, availability: $secrets_runtime )
			: ( $this->secrets_factory )( $secret_policies );
		if ( ! $secrets instanceof SecretsFile ) {
			throw new \LogicException( 'The Booster secrets factory must return a SecretsFile.' );
		}
		$debug_capture = new TemporaryDebugCapture( $secrets->path() );
		BoosterLogger::configure_capture( $debug_capture );
		$admin_interaction = new CoreAdminInteractionFacade();
		$admin_interaction->register();

		add_action(
			'admin_init',
			static function () use ( $container, $runtime, $secrets, $debug_capture ): void {
				// Validate only when an administrator opens Booster. Routine front-end
				// requests and unrelated admin/AJAX traffic must remain read-only.
				if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
					return;
				}

				// Read-only routing state; sidecar verification is protected by the
				// sidecar lock and performs no WordPress/database mutation.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing determines whether to inspect the sidecar.
				$page_input = $_GET['page'] ?? '';
				$page      = is_string( $page_input )
					? sanitize_key( wp_unslash( $page_input ) )
					: '';
				if ( ! str_starts_with( $page, 'ran-booster' ) ) {
					return;
				}

				// Capture expiry is file-only and lazy; no cron or database state is needed.
				try {
					$debug_capture->snapshot();
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The panel reports optional capture availability.
				} catch ( \Throwable ) {
					// The troubleshooting panel reports capture availability.
				}

				if ( $runtime->is_passive_troubleshooting_request() ) {
					return;
				}

				try {
					$secrets->verify_and_secure();
				} catch ( SecretsStorageUnavailable ) {
					// The typed, pathless storage notice and Overview status own this state.
					return;
				} catch ( \Throwable $exception ) {
					$runtime_dashboard = $container->make( Dashboard::class );
					$runtime_dashboard->add_failure_message(
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
		$container->bind( AdminInteractionFacade::class, $admin_interaction );
		$container->bind( CoreAdminInteractionFacade::class, $admin_interaction );
		$container->bind(
			WebhookAssistanceReadinessEvaluator::class,
			static fn ( CoreContainer $container ): WebhookAssistanceReadinessEvaluator => new WebhookAssistanceReadinessEvaluator(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( SecretsFile::class ),
				$container->make( Database::class )
			)
		);
		$expiry_observations = new CredentialExpiryObservationStore();
		$container->bind( SecretsFile::class, $secrets );
		$container->bind( SecretsRuntimeAvailability::class, $secrets_runtime );
		$container->bind(
			SecretsStorageProvisioner::class,
			new SecretsStorageProvisioner( secrets: $secrets )
		);
		$container->bind( SecretsRuntimeAvailabilityNotice::class, new SecretsRuntimeAvailabilityNotice( $secrets_runtime ) );
		$container->bind( DatabaseCompatibilityNotice::class, new DatabaseCompatibilityNotice( $database ) );
		$container->bind( TemporaryDebugCapture::class, $debug_capture );
		$container->bind( CredentialExpiryObservationStore::class, $expiry_observations );
		$container->bind(
			CredentialSelfDestructPurger::class,
			static fn ( CoreContainer $container ): CredentialSelfDestructPurger => new CredentialSelfDestructPurger(
				$container->make( SecretsFile::class ),
				$container->make( CredentialExpiryObservationStore::class ),
				$container->make( PublicRepositoryLookupProfileStore::class ),
				$container->make( RepositoryBranchCheckEvidenceStore::class )
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
					Database::attempt_table_name(),
					null,
					null,
					$container->make( Database::class )
				);
			}
		);
		$release_registrar = new ManagedReleaseUpdaterRegistrar( $release_updater );
		$container->bind( ManagedReleaseUpdaterRegistrar::class, $release_registrar );
		$provider_registration_context = new \RAN\RepositoryProvider\ProviderRegistrationContext(
			static fn (): int => PackageArtifactLimit::resolve()
		);
		$providers                   = new ProviderRegistry(
			array(),
			$secret_policies,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentials_for( $code ),
			static fn ( ProviderCode $code ): \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader => new \RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader(
				$code,
				static fn ( ProviderCode $bound_code ): ?\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence => $container
					->make( DeploymentAttemptRepository::class )
					->latest_authenticated_delivery( $bound_code )
			),
			$provider_registration_context
		);
		$providers->register_with_credential_store(
			'gh',
			static fn (
				ProviderCredentialStore $credentials,
				\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				\RAN\RepositoryProvider\ProviderRegistrationContext $registration_context
			): RepositoryProvider => GitHubProvider::create(
				$credentials,
				$delivery_evidence,
				$release_registrar,
				static fn (): int => $registration_context->maximum_artifact_bytes()
			)
		);
		$container->bind( ProviderRegistry::class, $providers );
		$container->bind(
			ReleaseWorkflowControls::class,
			static fn ( CoreContainer $container ): ReleaseWorkflowControls => new ReleaseWorkflowControls(
				$container->make( ReleaseTrackingFacade::class ),
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class ),
				$container->make( ProviderRegistry::class )
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
		$webhook_controls = new RepositoryWebhookManagementControls(
			$container->make( WebhookAssistanceFacade::class ),
			$container->make( AdminInteractionFacade::class ),
			$container->make( ProviderRegistry::class ),
			(string) $runtime->booster_path,
			(string) $runtime->booster_url,
			new ManagedPackageWebhookAuthorityResolver(
				$container->make( PluginRepository::class ),
				$container->make( ThemeRepository::class )
			)
		);
		$container->bind( RepositoryWebhookManagementControls::class, $webhook_controls );
		$expiry_reminders = new CredentialExpiryReminder(
			$container->make( ProviderRegistry::class ),
			$secrets,
			$expiry_observations
		);
		$container->bind( CredentialExpiryReminder::class, $expiry_reminders );
		$container->bind( CredentialExpiryNotice::class, new CredentialExpiryNotice( $expiry_reminders ) );
		$container->bind( CredentialExpiryNoticeController::class, new CredentialExpiryNoticeController( $expiry_reminders ) );
		$background_failure_monitor = new BackgroundDeploymentFailureMonitor(
			$container->make( DeploymentAttemptRepository::class ),
			$container->make( ProviderRegistry::class )
		);
		$container->bind( BackgroundDeploymentFailureMonitor::class, $background_failure_monitor );
		$container->bind( BackgroundDeploymentFailureEmail::class, new BackgroundDeploymentFailureEmail() );
		$container->bind(
			ManagedPluginFailureRows::class,
			new ManagedPluginFailureRows(
				$container->make( PluginRepository::class ),
				$background_failure_monitor
			)
		);
		$container->bind(
			PackageUpdateProgressController::class,
			static fn ( CoreContainer $container ): PackageUpdateProgressController => new PackageUpdateProgressController(
				$container->make( DeploymentAttemptRepository::class )
			)
		);
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
				$container->make( WordPressUpdaterLock::class ),
				$container->make( RepositoryBranchCheckEvidenceStore::class )
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
		$release_store = new ManagedReleaseStore( null, $database );
		$container->bind( ManagedReleaseStore::class, $release_store );
		$release_registrar = new ManagedReleaseTargetRegistrar(
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$release_store,
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class ),
			bulk_forbidden_plugin_identifier: $self_plugin_identifier
		);
		$container->bind( ManagedReleaseTargetRegistrar::class, $release_registrar );
		$release_facade = new NativeReleaseTrackingFacade(
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$release_store,
			$release_registrar,
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class ),
			public_lookup_profile: static fn ( string $provider ): ?string => $container->make( PublicRepositoryLookupProfileStore::class )->get( $provider ),
			source_guard: new \RAN\Storage\RepositorySourceGuard( null, $database )
		);
		$container->bind( NativeReleaseTrackingFacade::class, $release_facade );
		$container->bind( ReleaseTrackingFacade::class, $release_facade );
		$prospective_facade = new NativeProspectiveReleaseFacade(
			$container->make( PackageRepositoryRequestResolver::class ),
			$container->make( CorePackageExecutor::class ),
			$container->make( PluginRepository::class ),
			$container->make( ThemeRepository::class ),
			$container->make( WordPressUpdaterLock::class ),
			$container->make( ProviderRegistry::class ),
			source_guard: new \RAN\Storage\RepositorySourceGuard( null, $database )
		);
		$container->bind( NativeProspectiveReleaseFacade::class, $prospective_facade );
		$container->bind( ProspectiveReleaseFacade::class, $prospective_facade );
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

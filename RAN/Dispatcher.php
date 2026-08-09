<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageActionFailure;
use RAN\Admin\BulkPackageActionService;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\CredentialRequestException;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\Interaction\CoreProviderProfileInteraction;
use RAN\Admin\Interaction\SignedAdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Deployment\PackageMutationGuard;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLocator;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageProvisioner;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Storage\CredentialUsageReader;
use RAN\WordPress\WordPressUpdaterLock;

class Dispatcher {

	/**
	 * @var Dashboard
	 */

	private $dashboard;
	private ProviderRegistry $providers;

	private SecretsFile $secrets;

	private PackageRepositoryRequestResolver $packageRepositories;

	private ManagedPackageWebhookAuthorityResolver $webhookAuthorities;

	private PackageEditProviderGuard $packageEdits;
	private WordPressUpdaterLock $updaterLock;
	private CredentialUsageReader $credentialUsage;
	private ?DeploymentCoordinator $deploymentCoordinator;
	private ?BulkPackageActionService $bulkPackageActions;
	private PublicRepositoryLookupProfileStore $publicLookupProfiles;
	private ?TemporaryDebugCapture $debugCapture;
	private CredentialExpiryObservationStore $expiryObservations;
	private ?SecretsStorageProvisioner $secretsStorage;
	private ?DeploymentAttemptRepository $deploymentAttempts;
	private ?CoreProviderProfileInteraction $providerProfileInteraction;

	/**
	 * @param Dashboard             $dashboard Dashboard message target.
	 * @param ProviderRegistry $providers Provider catalog.
	 * @param SecretsFile      $secrets   Provider credential store.
	 * @param PackageRepositoryRequestResolver       $packageRepositories Package request resolver.
	 * @param ManagedPackageWebhookAuthorityResolver $webhookAuthorities  Stable webhook authority resolver.
	 * @param PackageEditProviderGuard                $packageEdits        Stored package provider guard.
	 * @param WordPressUpdaterLock                    $updaterLock         Shared package-authority mutation lock.
	 * @param DeploymentCoordinator|null              $deploymentCoordinator Protected operator boundary.
	 * @param CredentialUsageReader|null              $credentialUsage     Fail-closed managed-package usage reader.
	 * @param TemporaryDebugCapture|null               $debugCapture        Bounded Booster-only event capture.
	 */
	public function __construct(
		Dashboard $dashboard,
		ProviderRegistry $providers,
		SecretsFile $secrets,
		PackageRepositoryRequestResolver $packageRepositories,
		ManagedPackageWebhookAuthorityResolver $webhookAuthorities,
		PackageEditProviderGuard $packageEdits,
		WordPressUpdaterLock $updaterLock,
		?DeploymentCoordinator $deploymentCoordinator = null,
		?CredentialUsageReader $credentialUsage = null,
		?BulkPackageActionService $bulkPackageActions = null,
		?PublicRepositoryLookupProfileStore $publicLookupProfiles = null,
		?TemporaryDebugCapture $debugCapture = null,
		?CredentialExpiryObservationStore $expiryObservations = null,
		?SecretsStorageProvisioner $secretsStorage = null,
		?DeploymentAttemptRepository $deploymentAttempts = null,
		?CoreProviderProfileInteraction $providerProfileInteraction = null
	) {
		$this->dashboard                  = $dashboard;
		$this->providers                  = $providers;
		$this->secrets                    = $secrets;
		$this->packageRepositories        = $packageRepositories;
		$this->webhookAuthorities         = $webhookAuthorities;
		$this->packageEdits               = $packageEdits;
		$this->updaterLock                = $updaterLock;
		$this->credentialUsage            = $credentialUsage ?? new CredentialUsageReader();
		$this->deploymentCoordinator      = $deploymentCoordinator;
		$this->bulkPackageActions         = $bulkPackageActions;
		$this->publicLookupProfiles       = $publicLookupProfiles ?? new PublicRepositoryLookupProfileStore();
		$this->debugCapture               = $debugCapture;
		$this->expiryObservations         = $expiryObservations ?? new CredentialExpiryObservationStore();
		$this->secretsStorage             = $secretsStorage;
		$this->deploymentAttempts         = $deploymentAttempts;
		$this->providerProfileInteraction = $providerProfileInteraction;
	}

	public function dispatchPostRequests() {
		// The selected action determines which nonce is verified before any mutation occurs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['ran_booster'] ) && is_array( $_POST['ran_booster'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the selected action branch below.
			$request = $_POST['ran_booster'];
			$action  = isset( $request['action'] ) && is_string( $request['action'] )
				? $request['action']
				: '';

			if ( 'run-troubleshooting' === $action ) {
				$this->runTroubleshooting( $request );

				return;
			}

			if ( 'manage-debug-capture' === $action ) {
				$this->manageDebugCapture( $request );

				return;
			}

			if ( 'create-secure-storage' === $action ) {
				$this->createSecureStorage();

				return;
			}

			if ( 'adopt-secure-storage' === $action ) {
				$this->adoptSecureStorage( $request );

				return;
			}

			if ( 'reset-empty-storage' === $action ) {
				$this->resetEmptyStorage( $request );

				return;
			}

			if ( 'save-public-lookup-profile' === $action ) {
				$this->managePublicLookupProfile( $request );

				return;
			}

			if ( 'validate-access-profile' === $action ) {
				$this->manageCredentialValidation( $request );

				return;
			}

			$credentialActions = array(
				'save-access-profile',
				'delete-access-profile',
				'save-webhook-profile',
				'delete-webhook-profile',
			);

			if ( in_array( $action, $credentialActions, true ) ) {
				$this->manageCredentialProfiles( $request );

				return;
			}

			$deploymentActions = array(
				'reconcile-deployment-worker',
				'request-deployment-runner',
				'resolve-needs-attention',
			);
			if ( in_array( $action, $deploymentActions, true ) ) {
				$this->manageDeploymentAttempt( $action, $request );

				return;
			}

			if ( in_array( $action, array( 'bulk-plugin', 'bulk-theme' ), true ) ) {
				$this->manageBulkPackageAction( $action, $request );

				return;
			}

			$packageActions = array(
				'install-plugin'       => array(
					'capability' => 'install_plugins',
					'resolve'    => true,
					'edit'       => false,
				),
				'install-theme'        => array(
					'capability' => 'install_themes',
					'resolve'    => true,
					'edit'       => false,
				),
				'edit-plugin'          => array(
					'capability' => 'update_plugins',
					'resolve'    => true,
					'edit'       => true,
				),
				'edit-theme'           => array(
					'capability' => 'update_themes',
					'resolve'    => true,
					'edit'       => true,
				),
				'update-plugin'        => array(
					'capability' => 'update_plugins',
					'resolve'    => false,
					'edit'       => false,
				),
				'update-theme'         => array(
					'capability' => 'update_themes',
					'resolve'    => false,
					'edit'       => false,
				),
				'unlink-plugin'        => array(
					'capability' => 'update_plugins',
					'resolve'    => false,
					'edit'       => false,
				),
				'unlink-theme'         => array(
					'capability' => 'update_themes',
					'resolve'    => false,
					'edit'       => false,
				),
				'unlink-delete-plugin' => array(
					'capability' => array( 'update_plugins', 'delete_plugins', 'activate_plugins' ),
					'resolve'    => false,
					'edit'       => false,
				),
				'unlink-delete-theme'  => array(
					'capability' => array( 'update_themes', 'delete_themes' ),
					'resolve'    => false,
					'edit'       => false,
				),
			);
			if ( ! isset( $packageActions[ $action ] ) ) {
				return;
			}
			$capabilities = is_array( $packageActions[ $action ]['capability'] )
				? $packageActions[ $action ]['capability']
				: array( $packageActions[ $action ]['capability'] );
			foreach ( $capabilities as $capability ) {
				if ( ! current_user_can( $capability ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ran-booster' ) );
				}
			}

			check_admin_referer( $action );
			$reinstallAfterSave = in_array( $action, array( 'edit-plugin', 'edit-theme' ), true )
				&& isset( $request['reinstall_after_save'] )
				&& is_scalar( $request['reinstall_after_save'] )
				&& '1' === (string) $request['reinstall_after_save'];
			if ( $reinstallAfterSave ) {
				check_admin_referer( str_replace( 'edit-', 'update-', $action ), '_ran_booster_reinstall_nonce' );
			}
			if ( ! $this->guardPackageAction( $action, $request ) ) {
				return;
			}
			if ( $packageActions[ $action ]['edit'] && ! $this->guardPackageEdit( $action, $request ) ) {
				return;
			}
			if ( $packageActions[ $action ]['resolve'] ) {
				$request = $this->resolvePackageRequest( $request );
				if ( null === $request ) {
					return;
				}
			}
			$redirect = $this->dashboard->postPackageOperation( $action, $request );
			if ( is_string( $redirect ) ) {
				$this->redirectTo( $redirect );
			}
		}
	}

	protected function redirectTo( string $url ): never {
		if ( $this->isHtmxRequest() ) {
			$location = wp_json_encode(
				array(
					'path'   => $url,
					'target' => '#wpbody-content',
					'select' => '#wpbody-content',
					'swap'   => 'outerHTML show:none',
				)
			);
			if ( is_string( $location ) ) {
				header( 'HX-Location: ' . $location );
				exit;
			}
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function createSecureStorage(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		foreach ( array( 'manage_options', 'activate_plugins' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to configure Booster storage.', 'ran-booster' ) );
			}
		}

		check_admin_referer( 'ran-booster-create-secure-storage' );

		try {
			$result = null === $this->secretsStorage
				? SecretsStorageProvisioningResult::manualRequired(
					'provisioner_unavailable',
					'Automatic secure storage setup is unavailable.'
				)
				: $this->secretsStorage->provision();
		} catch ( \Throwable $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'secrets storage setup failed',
				$failure,
				array(
					'diagnostic_id' => 'provisioning_failed',
					'event'         => 'secrets_storage_setup_failed',
					'operation'     => 'create_secure_storage',
					'outcome_code'  => 'provisioning_failed',
					'source'        => 'admin',
					'state'         => 'failed',
					'step'          => 'provision',
				)
			);
			$result = SecretsStorageProvisioningResult::manualRequired(
				'provisioning_failed',
				'Automatic secure storage setup could not be completed.'
			);
		}

		if ( $result->requiresNextRequestVerification() ) {
			$adminUrl = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $adminUrl . '?page=ran-booster&tab=overview' );
		}

		// Keep a failed attempt local to this protected POST response. Paths and
		// failure details never enter redirects, logs, transients or global notices.
		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function adoptSecureStorage( array $request ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		foreach ( array( 'manage_options', 'activate_plugins' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to recover Booster storage.', 'ran-booster' ) );
			}
		}

		check_admin_referer( 'ran-booster-adopt-secure-storage' );
		$request = wp_unslash( $request );
		$token   = is_string( $request['recovery_token'] ?? null )
			? sanitize_text_field( $request['recovery_token'] )
			: '';

		try {
			$result = null === $this->secretsStorage
				? SecretsStorageProvisioningResult::manualRequired(
					'provisioner_unavailable',
					'Automatic storage recovery is unavailable.'
				)
				: $this->secretsStorage->adoptRecovery( $token );
		} catch ( \Throwable $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'secrets storage recovery failed',
				$failure,
				array(
					'diagnostic_id' => 'recovery_failed',
					'event'         => 'secrets_storage_recovery_failed',
					'operation'     => 'adopt_secure_storage',
					'outcome_code'  => 'recovery_failed',
					'source'        => 'admin',
					'state'         => 'failed',
					'step'          => 'adopt',
				)
			);
			$result = SecretsStorageProvisioningResult::manualRequired(
				'recovery_failed',
				'Automatic storage recovery could not be completed.'
			);
		}

		if ( $result->requiresNextRequestVerification() ) {
			$adminUrl = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $adminUrl . '?page=ran-booster&tab=overview' );
		}

		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function resetEmptyStorage( array $request ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		foreach ( array( 'manage_options', 'activate_plugins' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to reset Booster storage.', 'ran-booster' ) );
			}
		}

		check_admin_referer( 'ran-booster-reset-empty-storage' );
		$request      = wp_unslash( $request );
		$confirmation = is_string( $request['reset_confirmation'] ?? null )
			? sanitize_text_field( $request['reset_confirmation'] )
			: '';

		try {
			$result = null === $this->secretsStorage
				? SecretsStorageProvisioningResult::manualRequired(
					'provisioner_unavailable',
					'Empty credential storage reset is unavailable.'
				)
				: $this->secretsStorage->resetOrphanedStorage( $confirmation );
		} catch ( \Throwable $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'secrets storage reset failed',
				$failure,
				array(
					'diagnostic_id' => 'storage_reset_failed',
					'event'         => 'secrets_storage_reset_failed',
					'operation'     => 'reset_empty_storage',
					'outcome_code'  => 'storage_reset_failed',
					'source'        => 'admin',
					'state'         => 'failed',
					'step'          => 'reset',
				)
			);
			$result = SecretsStorageProvisioningResult::manualRequired(
				'storage_reset_failed',
				'Empty credential storage could not be reset safely.'
			);
		}

		if ( 'storage_reset' === $result->code() ) {
			$adminUrl = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $adminUrl . '?page=ran-booster&tab=overview' );
		}

		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function manageDebugCapture( array $request ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage logging capture.', 'ran-booster' ) );
		}

		check_admin_referer( 'ran-booster-manage-debug-capture' );
		$request   = wp_unslash( $request );
		$operation = is_string( $request['operation'] ?? null )
			? sanitize_key( $request['operation'] )
			: '';
		if ( ! in_array( $operation, array( 'start', 'stop', 'delete' ), true ) ) {
			return;
		}
		$htmxRequest = $this->isHtmxRequest() && in_array( $operation, array( 'start', 'stop' ), true );

		try {
			if ( null === $this->debugCapture ) {
				throw new \RuntimeException();
			}

			match ( $operation ) {
				'start' => $this->debugCapture->start(),
				'stop' => $this->debugCapture->stop(),
				'delete' => $this->debugCapture->delete(),
			};
		} catch ( \Throwable $failure ) {
			if ( $htmxRequest ) {
				$this->respondToHtmxDebugCapture(
					null,
					__( 'Booster could not update the temporary logging capture. No deployment was interrupted.', 'ran-booster' ),
					500
				);
			}

			$this->dashboard->addFailureMessage(
				new \WP_Error(
					'ran_booster_debug_capture_unavailable',
					__( 'Booster could not update the temporary logging capture. No deployment was interrupted.', 'ran-booster' )
				),
				$failure,
				array(
					'operation' => $operation,
					'step'      => 'debug_capture',
				)
			);

			return;
		}

		if ( $htmxRequest ) {
			$message = 'start' === $operation
				? __( 'Temporary logging capture started.', 'ran-booster' )
				: __( 'Temporary logging capture stopped.', 'ran-booster' );
			$this->respondToHtmxDebugCapture( $message, null, 200 );
		}

		$adminUrl = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$this->redirectTo( $adminUrl . '?page=ran-booster&tab=troubleshooting&panel=debug-capture' );
	}

	/** @param array<string, mixed> $request */
	private function manageBulkPackageAction( string $action, array $request ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		$packageType = 'bulk-plugin' === $action ? 'plugin' : 'theme';
		$request     = wp_unslash( $request );
		$operation   = is_string( $request['bulk_action'] ?? null )
			? sanitize_key( $request['bulk_action'] )
			: BulkPackageAction::QUEUE_UPDATE;
		$capability  = 'plugin' === $packageType
			? match ( $operation ) {
				BulkPackageAction::ACTIVATE_PLUGINS => 'activate_plugins',
				BulkPackageAction::DEACTIVATE_PLUGINS => 'deactivate_plugins',
				default => 'update_plugins',
			}
			: 'update_themes';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage these packages.', 'ran-booster' ) );
		}

		check_admin_referer( $action );
		$selected = is_array( $request['identifiers'] ?? null )
			? min( count( $request['identifiers'] ), BulkPackageAction::MAX_IDENTIFIERS )
			: 0;

		try {
			if ( null === $this->bulkPackageActions ) {
				throw new \RuntimeException( 'Bulk package actions are unavailable.' );
			}
			$command = BulkPackageAction::fromInput( $packageType, $request );
			$result  = $this->bulkPackageActions->execute( $command );
		} catch ( InvalidArgumentException $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'bulk package action rejected',
				$failure,
				array(
					'operation' => $operation,
					'step'      => 'bulk_package_action',
				)
			);
			$result = BulkPackageResult::error( $operation, $selected, 'invalid_request' );
		} catch ( BulkPackageActionFailure $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'bulk package action failed',
				$failure,
				array(
					'operation'    => $operation,
					'outcome_code' => $failure->reason,
					'step'         => 'bulk_package_action',
				)
			);
			$result = BulkPackageResult::error( $operation, $selected, $failure->reason );
		} catch ( \Throwable $exception ) {
			\RAN\Logging\BoosterLogger::logException(
				'bulk package action failed',
				$exception,
				array( 'step' => 'bulk_package_action' )
			);
			$result = BulkPackageResult::error( $operation, $selected, 'unavailable' );
		}

		$this->redirectTo( $this->dashboard->bulkPackageRedirect( $packageType, $result ) );
	}

	/** @param array<string, mixed> $request */
	private function manageDeploymentAttempt( string $action, array $request ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| ! is_string( $_SERVER['REQUEST_METHOD'] )
			|| 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster deployments.', 'ran-booster' ) );
		}

		check_admin_referer( 'ran-booster-' . $action );

		try {
			if ( 'resolve-needs-attention' === $action ) {
				if ( null === $this->deploymentAttempts ) {
					throw new \RuntimeException( 'The deployment attempt repository is unavailable.' );
				}

				$attemptId     = $this->canonicalAttemptId( $request['attempt_id'] ?? null );
				$correlationId = $this->canonicalCorrelationId( $request['correlation_id'] ?? null );
				if ( '1' !== ( $request['confirm_reviewed'] ?? null ) ) {
					throw new \RuntimeException( 'Explicit uncertainty-review confirmation is required.' );
				}

				$attempt = $this->deploymentAttempts->findExact( $attemptId );
				if ( null === $attempt || ! hash_equals( $attempt->getCorrelationId(), $correlationId ) ) {
					throw new \RuntimeException( 'The deployment activity identity no longer matches.' );
				}

				$attemptData = $attempt->safeData();
				$capability  = 'plugin' === $attemptData['package_type'] ? 'update_plugins' : 'update_themes';
				if ( ! current_user_can( $capability ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to manage this package.', 'ran-booster' ) );
				}

				$this->deploymentAttempts->resolveNeedsAttention(
					$attemptId,
					$correlationId,
					$this->currentUserId()
				);
				$this->dashboard->addMessage( __( 'The deployment review was recorded. This package may now be retried.', 'ran-booster' ) );

				return;
			}

			if ( null === $this->deploymentCoordinator ) {
				throw new \RuntimeException( 'The deployment coordinator is unavailable.' );
			}
			if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to manage this package.', 'ran-booster' ) );
			}
			if ( 'request-deployment-runner' === $action ) {
				$this->deploymentCoordinator->requestRunner();
				$this->dashboard->addMessage( __( 'The deployment runner was requested.', 'ran-booster' ) );

				return;
			}
			$attemptId     = $this->canonicalAttemptId( $request['attempt_id'] ?? null );
			$correlationId = $this->canonicalCorrelationId( $request['correlation_id'] ?? null );
			if ( '1' !== ( $request['confirm_stopped'] ?? null ) ) {
				throw new \RuntimeException( 'Explicit stopped-worker confirmation is required.' );
			}
			$this->deploymentCoordinator->reconcileConfirmedStopped( $attemptId, $correlationId );

			$this->dashboard->addMessage( __( 'The protected deployment action was accepted.', 'ran-booster' ) );
		} catch ( \Throwable $exception ) {
			$this->dashboard->addFailureMessage(
				new \WP_Error(
					'ran_booster_deployment_action_unavailable',
					__( 'Booster could not safely accept this deployment action. Refresh the activity record and try again.', 'ran-booster' )
				),
				$exception,
				array(
					'operation' => $action,
					'step'      => 'deployment_action_dispatch',
				)
			);
		}
	}

	protected function currentUserId(): int {
		return (int) get_current_user_id();
	}

	private function canonicalAttemptId( mixed $value ): int {
		if ( ! is_string( $value ) || preg_match( '/^[1-9][0-9]*$/D', $value ) !== 1 || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}
		$attemptId = (int) $value;
		if ( $attemptId <= 0 || (string) $attemptId !== $value ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}

		return $attemptId;
	}

	private function canonicalCorrelationId( mixed $value ): string {
		if ( ! is_string( $value ) || preg_match( '/^[a-f0-9]{32}$/D', $value ) !== 1 ) {
			throw new \RuntimeException( 'The deployment activity reference is invalid.' );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private function guardPackageAction( string $action, array $request ): bool {
		try {
			PackageMutationGuard::assertAdminActionAllowed( $action, $request );

			return true;
		} catch ( \RuntimeException $exception ) {
			$this->dashboard->addFailureMessage(
				new \WP_Error( 'ran_booster_unsupported_package_operation', $exception->getMessage() ),
				$exception,
				array(
					'operation' => $action,
					'step'      => 'package_mutation_guard',
				)
			);

			return false;
		}
	}

	/** @param array<string, mixed> $request */
	private function guardPackageEdit( string $action, array $request ): bool {
		try {
			$this->packageEdits->assertStoredProviderAvailable( $action, $request );

			return true;
		} catch ( InvalidProviderCode | UnknownProvider $failure ) {
			$message = 'This package cannot be edited until its stored repository provider is registered again.';
		} catch ( \Throwable $exception ) {
			$failure = $exception;
			$message = 'Booster could not verify the managed package provider. No changes were made.';
		}

		$this->dashboard->addFailureMessage(
			new \WP_Error( 'ran_booster_unavailable_package_provider', $message ),
			$failure,
			array(
				'operation' => $action,
				'step'      => 'package_edit_guard',
			)
		);

		return false;
	}

	/** @param array<string, mixed> $request */
	private function runTroubleshooting( array $request ): void {
		foreach ( array( 'manage_options', 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to run troubleshooting.', 'ran-booster' ) );
			}
		}

		check_admin_referer( 'ran-booster-run-troubleshooting' );

		if ( ! isset( $request['provider'] ) || ! is_string( $request['provider'] ) ) {
			return;
		}

		foreach ( array( 'credential_id', 'repository' ) as $optionalField ) {
			if ( isset( $request[ $optionalField ] ) && ! is_string( $request[ $optionalField ] ) ) {
				return;
			}
		}

		$provider     = wp_unslash( $request['provider'] );
		$credentialId = isset( $request['credential_id'] ) ? trim( wp_unslash( $request['credential_id'] ) ) : null;
		$repository   = isset( $request['repository'] ) ? wp_unslash( $request['repository'] ) : null;
		$credentialId = '' === $credentialId ? null : $credentialId;
		$repository   = '' === $repository ? null : $repository;

		if ( strlen( $provider ) > 32
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $provider )
			|| ( null !== $credentialId && ( strlen( $credentialId ) > ProviderDiagnosticRequest::MAX_CREDENTIAL_ID_BYTES || 1 === preg_match( '/[\x00-\x1F\x7F]/', $credentialId ) ) )
		) {
			return;
		}

		if ( null !== $repository ) {
			try {
				$repository = RepositoryLocator::requireValid( $repository );
			} catch ( \InvalidArgumentException ) {
				return;
			}
		}

		$this->dashboard->postRunTroubleshooting(
			array(
				'provider'      => $provider,
				'credential_id' => $credentialId,
				'repository'    => $repository,
			)
		);

		if ( $this->isHtmxRequest() ) {
			$this->respondToHtmxDiagnostics( $this->dashboard->troubleshootingSucceeded() );
		}
	}

	private function manageCredentialProfiles( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster credentials.', 'ran-booster' ) );
		}

		check_admin_referer( 'ran-booster-save-secrets' );

		$action             = isset( $request['action'] ) && is_string( $request['action'] )
			? $request['action']
			: '';
		$id                 = null;
		$interactionRequest = null;
		try {
			$provider = $this->providerCode( $request );
			$secrets  = $this->secrets;
			if ( null !== $this->providerProfileInteraction ) {
				$interactionRequest = $this->providerProfileInteraction->providerProfileRequest(
					$action,
					$provider->value
				);
			}
			if ( array_key_exists( 'id', $request ) ) {
				if ( ! is_string( $request['id'] ) ) {
					throw new CredentialRequestException( 'Choose a valid credential profile.' );
				}
				$id = trim( wp_unslash( $request['id'] ) );
				if ( '' !== $id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $id ) ) {
					throw new CredentialRequestException( 'Choose a valid credential profile.' );
				}
				$id = '' === $id ? null : $id;
			}

			if ( $action === 'delete-access-profile' ) {
				if ( null === $id || '' === $id ) {
					throw new CredentialRequestException( 'Choose a repository credential to remove.' );
				}

				$message = $this->updaterLock->run(
					function () use ( $secrets, $provider, $id ): string {
						$profile = $secrets->credentialProfiles( $provider )[ $id ] ?? null;
						if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
							throw new CredentialRequestException( 'Choose a saved repository credential to remove.' );
						}

						$usageCount = $this->credentialUsage->read( $provider, $id )['total'];
						if ( $usageCount > 0 ) {
							throw new CredentialRequestException(
								sprintf(
									'This repository access token is used by %d managed package%s. Assign another credential before deleting it.',
									$usageCount, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal count is escaped at the response boundary.
									$usageCount === 1 ? '' : 's'
								)
							);
						}

						$clearedPublicLookupDefault = $id === $this->publicLookupProfiles->get( $provider->value );
						if ( ! $secrets->deleteCredential( $provider, $id ) || isset( $secrets->credentialProfiles( $provider )[ $id ] ) ) {
							throw new CredentialRequestException( 'Booster could not verify that the repository credential was removed.' );
						}
						if ( $clearedPublicLookupDefault ) {
							$this->publicLookupProfiles->set( $provider->value, null );
						}
						$this->expiryObservations->clear( $provider->value, $id );

						return $clearedPublicLookupDefault
							? 'Repository access token removed. Public repository lookup now uses anonymous access.'
							: 'Repository access token removed.';
					}
				);
				$this->completeCredentialProfileMutation( $message, $interactionRequest );

				return;
			}

			if ( $action === 'delete-webhook-profile' ) {
				if ( null === $id || '' === $id ) {
					throw new CredentialRequestException( 'Choose a Push-to-Deploy secret to remove.' );
				}

				$profile = $secrets->webhookProfiles( $provider )[ $id ] ?? null;
				if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
					throw new CredentialRequestException( 'Choose a saved Push-to-Deploy secret to remove.' );
				}
				if ( ! $secrets->deleteWebhook( $provider, $id ) || isset( $secrets->webhookProfiles( $provider )[ $id ] ) ) {
					throw new CredentialRequestException( 'Booster could not verify that the Push-to-Deploy secret was removed.' );
				}
				$this->completeCredentialProfileMutation( 'Push-to-Deploy secret removed.', $interactionRequest );

				return;
			}

			$label = isset( $request['label'] ) && is_string( $request['label'] )
				? sanitize_text_field( wp_unslash( $request['label'] ) )
				: '';
			if ( '' === trim( $label ) ) {
				throw new CredentialRequestException( 'Enter a label for this credential.' );
			}

			if ( $action === 'save-access-profile' ) {
				$admin        = $this->providerAdmin( $provider );
				$kind         = isset( $request['kind'] ) && is_string( $request['kind'] )
					? sanitize_key( wp_unslash( $request['kind'] ) )
					: '';
				$kindMetadata = $admin->getCredentialKind( $kind );

				if ( null === $kindMetadata ) {
					throw new CredentialRequestException( 'Choose a supported credential type.' );
				}

				$submittedConfiguration = isset( $request['configuration'] ) && is_array( $request['configuration'] )
					? wp_unslash( $request['configuration'] )
					: array();
				$configuration          = array();
				foreach ( $kindMetadata->fields as $field ) {
					$value                        = $submittedConfiguration[ $field->key ] ?? '';
					$configuration[ $field->key ] = is_string( $value )
						? sanitize_text_field( $value )
						: '';
					if ( $field->required && '' === trim( $configuration[ $field->key ] ) ) {
						throw new CredentialRequestException( 'Complete every required credential field.' );
					}
					if ( 'email' === $field->type && false === filter_var( $configuration[ $field->key ], FILTER_VALIDATE_EMAIL ) ) {
						throw new CredentialRequestException( 'Enter a valid account email address.' );
					}
				}
				$secret = isset( $request['secret'] ) && is_string( $request['secret'] )
					? trim( wp_unslash( $request['secret'] ) )
					: '';
				if ( ( null === $id || '' === $id ) && '' === $secret ) {
					throw new CredentialRequestException( 'Enter the credential secret.' );
				}

				$manualExpirySubmitted = array_key_exists( 'expires_on', $request );
				$manualExpiry          = null;
				if ( $manualExpirySubmitted ) {
					if ( ! is_string( $request['expires_on'] ) ) {
						throw new CredentialRequestException( 'Enter a valid credential expiry date.' );
					}
					$manualExpiry = trim( wp_unslash( $request['expires_on'] ) );
					$manualExpiry = '' === $manualExpiry ? null : $manualExpiry;
					if ( null !== $manualExpiry
						&& ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $manualExpiry, $expiryParts )
							|| ! checkdate( (int) $expiryParts[2], (int) $expiryParts[3], (int) $expiryParts[1] ) )
					) {
						throw new CredentialRequestException( 'Enter a valid expiry / removal date.' );
					}
				}
				$existingManualExpiry = null;
				$providerExpiry       = null;
				if ( null !== $id && '' !== $id ) {
					try {
						$expiryObservation    = $this->expiryObservations->get( $provider->value, $id );
						$observedManualExpiry = $expiryObservation['manual_expires_on'] ?? null;
						$providerExpiresAt    = $expiryObservation['provider_expires_at'] ?? null;
						$existingManualExpiry = is_string( $observedManualExpiry ) ? $observedManualExpiry : null;
						$providerExpiry       = is_string( $providerExpiresAt ) ? substr( $providerExpiresAt, 0, 10 ) : null;
					} catch ( \RuntimeException ) {
						$existingManualExpiry = null;
						$providerExpiry       = null;
					}
				}
				if ( '' === $secret && null !== $manualExpiry && null !== $providerExpiry && $manualExpiry > $providerExpiry ) {
					throw new CredentialRequestException( 'The expiry / removal date cannot be later than the expiry reported by the provider.' );
				}
				$selfDestruct = isset( $request['self_destruct'] ) && '1' === $request['self_destruct'];
				if ( $selfDestruct && null === $manualExpiry ) {
					throw new CredentialRequestException( 'Enter an expiry / removal date before enabling automatic removal.' );
				}
				$manualExpiryIsProviderFallback = '' === $secret
					&& null === $existingManualExpiry
					&& null !== $providerExpiry
					&& $manualExpiry === $providerExpiry;

				$message = $this->updaterLock->run(
					function () use (
						$secrets,
						$provider,
						$id,
						$secret,
						$label,
						$kind,
						$configuration,
						$selfDestruct,
						$manualExpirySubmitted,
						$manualExpiry,
						$manualExpiryIsProviderFallback
					): string {
						$existingProfile = null === $id
							? null
							: ( $secrets->credentialProfiles( $provider )[ $id ] ?? null );
						$isReplacement   = is_array( $existingProfile ) && '' !== $secret;
						$savedId         = $secrets->saveCredential(
							$provider,
							$id,
							array(
								'label'         => $label,
								'kind'          => $kind,
								'configuration' => $configuration,
								'self_destruct' => $selfDestruct,
								'destroy_on'    => $selfDestruct ? $manualExpiry : null,
							),
							$secret,
							true
						);
						$savedProfile    = $secrets->credentialProfiles( $provider )[ $savedId ] ?? null;
						if ( ! is_array( $savedProfile )
							|| $label !== ( $savedProfile['label'] ?? null )
							|| $kind !== ( $savedProfile['kind'] ?? null )
							|| ! is_array( $savedProfile['configuration'] ?? null )
							|| array() !== array_diff_assoc( $configuration, $savedProfile['configuration'] )
							|| $selfDestruct !== ( $savedProfile['self_destruct'] ?? null )
							|| ( $selfDestruct ? $manualExpiry : null ) !== ( $savedProfile['destroy_on'] ?? null )
							|| empty( $savedProfile['configured'] ) ) {
							throw new CredentialRequestException( 'Booster could not verify that the repository credential was saved.' );
						}
						if ( $isReplacement ) {
							$this->expiryObservations->clear( $provider->value, $savedId );
						}
						if ( $manualExpirySubmitted && ! $manualExpiryIsProviderFallback ) {
							$this->expiryObservations->setManualExpiry( $provider->value, $savedId, $manualExpiry );
						}

						return $selfDestruct
							? 'Repository access token saved with automatic removal enabled.'
							: ( $isReplacement
								? 'Repository access token replaced. Validate it to refresh provider expiry information.'
								: 'Repository access token saved.' );
					}
				);
				$this->completeCredentialProfileMutation( $message, $interactionRequest );

				return;
			}

			if ( $action === 'save-webhook-profile' ) {
				$admin      = $this->providerAdmin( $provider );
				$normalizer = $this->providers->requireCapability( $provider, \RAN\RepositoryProvider\WebhookNormalizer::class );
				$scope      = isset( $request['scope'] ) && is_string( $request['scope'] )
					? sanitize_key( wp_unslash( $request['scope'] ) )
					: '';
				$target     = isset( $request['target'] ) && is_string( $request['target'] )
					? sanitize_text_field( wp_unslash( $request['target'] ) )
					: '';
				$secret     = isset( $request['secret'] ) && is_string( $request['secret'] )
					? trim( wp_unslash( $request['secret'] ) )
					: '';

				if ( null === $admin->getWebhookScope( $scope ) ) {
					throw new CredentialRequestException( 'Choose a supported Push-to-Deploy scope.' );
				}
				$scopeMetadata = $admin->getWebhookScope( $scope );
				if ( null !== $scopeMetadata && $scopeMetadata->requiresTarget && '' === trim( $target ) ) {
					throw new CredentialRequestException( 'Enter the target for this Push-to-Deploy scope.' );
				}
				if ( ( null === $id || '' === $id ) && '' === $secret ) {
					throw new CredentialRequestException( 'Enter the Push-to-Deploy secret.' );
				}
				$authorityId = '';
				if ( 'repository' === $scope ) {
					$authorityId = $this->webhookAuthorities->resolve( $provider, $normalizer->getWebhookPolicy(), $target );
				} elseif ( 'owner' === $scope && 'gh' === $provider->value ) {
					$target = $this->webhookAuthorities->resolveOwner( $provider, $target );
				}

				$savedId      = $secrets->saveWebhook(
					$provider,
					$id,
					array(
						'label'        => $label,
						'scope'        => $scope,
						'target'       => $target,
						'authority_id' => $authorityId,
						'origin'       => 'manual',
					),
					$secret
				);
				$savedProfile = $secrets->webhookProfiles( $provider )[ $savedId ] ?? null;
				if ( ! is_array( $savedProfile )
					|| $label !== ( $savedProfile['label'] ?? null )
					|| $scope !== ( $savedProfile['scope'] ?? null )
					|| $target !== ( $savedProfile['target'] ?? null )
					|| $authorityId !== ( $savedProfile['authority_id'] ?? null )
					|| 'manual' !== ( $savedProfile['origin'] ?? null )
					|| empty( $savedProfile['configured'] ) ) {
					throw new CredentialRequestException( 'Booster could not verify that the Push-to-Deploy secret was saved.' );
				}
				$this->completeCredentialProfileMutation( 'Push-to-Deploy secret saved.', $interactionRequest );
			}
		} catch ( \Throwable $exception ) {
			$error = $this->safeCredentialError( $exception );
			$this->dashboard->addFailureMessage(
				new \WP_Error( 'ran_booster_credentials_error', $error ),
				$exception,
				array(
					'operation' => $action,
					'step'      => 'credential_profile',
				)
			);
			if ( null !== $interactionRequest && null !== $this->providerProfileInteraction ) {
				if ( $exception instanceof CredentialRequestException || $exception instanceof InvalidCredentialInput || $exception instanceof InvalidWebhookInput ) {
					$this->providerProfileInteraction->respondToProviderProfileValidationFailure( $interactionRequest, $error );

					return;
				}
				$this->providerProfileInteraction->respondToProviderProfileUnexpectedFailure( $interactionRequest );
			}
		}
	}

	/**
	 * Validate one existing credential with the same capability, nonce, and
	 * ordinary POST behaviour as the original credential-management handler.
	 * HTMX requests additionally receive a bounded local error or success toast.
	 *
	 * @param array<string, mixed> $request Credential validation request.
	 */
	private function manageCredentialValidation( array $request ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster credentials.', 'ran-booster' ) );
		}

		check_admin_referer( 'ran-booster-save-secrets' );
		$htmxRequest = $this->isHtmxRequest();
		$provider    = null;
		$id          = null;
		$message     = null;
		$error       = null;
		$status      = 200;

		try {
			$provider = $this->providerCode( $request );
			if ( ! isset( $request['id'] ) || ! is_string( $request['id'] ) ) {
				throw new CredentialRequestException( 'Choose a repository credential to validate.' );
			}
			$id = trim( wp_unslash( $request['id'] ) );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $id ) ) {
				throw new CredentialRequestException( 'Choose a repository credential to validate.' );
			}

			try {
				$validator = $this->providers->requireCapability( $provider, CredentialValidator::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( 'Credential validation is unavailable for this repository provider.' );
			}

			$result = $validator->validateCredential( $id );
			if ( $result->isValid() ) {
				if ( null !== $result->expiry ) {
					$this->expiryObservations->recordProviderExpiry(
						$provider->value,
						$id,
						$result->expiry,
						gmdate( 'Y-m-d\\TH:i:s\\Z' )
					);
					if ( $result->expiry->isKnown() && is_string( $result->expiry->expiresAt ) ) {
						$this->secrets->recordCredentialProviderExpiry(
							$provider,
							$id,
							substr( $result->expiry->expiresAt, 0, 10 )
						);
					}
				}
				$message = 'Repository credential validated successfully.';
				if ( ! $htmxRequest ) {
					$this->dashboard->addMessage( $message );
				}
			} else {
				$error = $result->getDisplayMessage();
				if ( null === $error ) {
					throw new \LogicException( 'Invalid credential validation results require a core display message.' );
				}

				if ( $htmxRequest ) {
					$status = 422;
				} else {
					$this->dashboard->addMessage( new \WP_Error( 'ran_booster_credential_validation_error', $error ) );
				}
			}
		} catch ( \Throwable $exception ) {
			$error = $this->safeCredentialError( $exception );
			$this->dashboard->addFailureMessage(
				new \WP_Error( 'ran_booster_credentials_error', $error ),
				$exception,
				array(
					'operation' => 'validate-access-profile',
					'step'      => 'credential_validation',
				)
			);
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}

		if ( $htmxRequest && $provider instanceof ProviderCode && is_string( $id ) ) {
			$this->respondToHtmxCredentialValidation( $id, $message, $error, $status );
		}
	}

	/**
	 * @param array<string, mixed> $request Provider settings request.
	 */
	private function managePublicLookupProfile( array $request ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster provider settings.', 'ran-booster' ) );
		}

		check_admin_referer( 'ran-booster-save-public-lookup-profile' );
		$htmxRequest = $this->isHtmxRequest();
		$provider    = null;
		$message     = null;
		$error       = null;
		$status      = 200;

		try {
			$provider = $this->providerCode( $request );

			try {
				$browser = $this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( 'A default public repository lookup profile is unavailable for this provider.' );
			}
			if ( ! $browser->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile ) {
				throw new CredentialRequestException( 'A default public repository lookup profile is unavailable for this provider.' );
			}

			if ( ! array_key_exists( 'profile_id', $request ) || ! is_string( $request['profile_id'] ) ) {
				throw new CredentialRequestException( 'Choose Anonymous or a saved repository credential.' );
			}
			$profileId = wp_unslash( $request['profile_id'] );
			if ( '' !== $profileId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
				throw new CredentialRequestException( 'Choose Anonymous or a saved repository credential.' );
			}

			if ( '' !== $profileId ) {
				$profile = $this->secrets->credentialProfiles( $provider )[ $profileId ] ?? null;
				if ( ! is_array( $profile ) || empty( $profile['configured'] ) ) {
					throw new CredentialRequestException( 'Choose Anonymous or a saved repository credential.' );
				}
			}

			$this->publicLookupProfiles->set( $provider->value, '' === $profileId ? null : $profileId );
			$message = '' === $profileId
				? 'Public repository lookup will use anonymous access.'
				: 'Default public repository lookup profile saved.';
			if ( ! $htmxRequest ) {
				$this->dashboard->addMessage( $message );
			}
		} catch ( \Throwable $exception ) {
			$error = $this->safeCredentialError( $exception );
			$this->dashboard->addFailureMessage(
				new \WP_Error( 'ran_booster_credentials_error', $error ),
				$exception,
				array(
					'operation' => 'save-public-lookup-profile',
					'step'      => 'public_lookup_profile',
				)
			);
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}

		if ( $htmxRequest && $provider instanceof ProviderCode ) {
			$this->respondToHtmxPublicLookupProfile( $provider->value, $message, $error, $status );
		}
	}

	private function isHtmxRequest(): bool {
		$header = $_SERVER['HTTP_HX_REQUEST'] ?? null;

		return is_string( $header ) && 'true' === strtolower( trim( $header ) );
	}

	private function completeCredentialProfileMutation(
		string $message,
		?SignedAdminInteractionRequest $interactionRequest
	): void {
		if ( null === $interactionRequest || null === $this->providerProfileInteraction ) {
			$this->dashboard->addMessage( $message );

			return;
		}

		$this->providerProfileInteraction->respondToProviderProfileSuccess( $interactionRequest, $message );
	}

	/**
	 * Respond to the explicit logging capture enhancement. The named event is
	 * success-only; failures keep their explanation beside the capture controls.
	 */
	protected function respondToHtmxDebugCapture( ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		if ( null !== $message ) {
			header(
				'HX-Trigger-After-Swap: ' . wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message' => $message,
						),
					)
				)
			);
		}

		echo $this->dashboard->renderDebugCaptureRegion( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}

	/**
	 * Return the Core-owned diagnostics panel. A warning, partial, or failed
	 * result remains visible in that panel and deliberately emits no toast.
	 */
	protected function respondToHtmxDiagnostics( bool $succeeded ): never {
		if ( $succeeded ) {
			header(
				'HX-Trigger-After-Swap: ' . wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message' => __( 'Diagnostics completed successfully.', 'ran-booster' ),
						),
					)
				)
			);
		}

		echo $this->dashboard->renderTroubleshootingDiagnosticsRegion(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}

	/**
	 * Emit the bounded response used by the public lookup preference spike.
	 *
	 * The named event carries only the safe, operator-facing success message;
	 * the rendered fragment remains the authoritative display state. Expected
	 * validation failures deliberately omit an event so the caller keeps the
	 * persistent local error instead of showing a transient success notice.
	 */
	protected function respondToHtmxPublicLookupProfile( string $provider, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		if ( null !== $message ) {
			header(
				'HX-Trigger-After-Swap: ' . wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message' => $message,
						),
					)
				)
			);
		}

		echo $this->dashboard->renderPublicLookupProfileRegion( $provider, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}

	/**
	 * Respond to the explicit credential-validation enhancement without replacing
	 * the surrounding table. Successful validation needs only the Core-owned
	 * transient feedback; expected failures replace the action-local alert.
	 */
	protected function respondToHtmxCredentialValidation( string $credentialId, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		if ( null !== $message ) {
			header(
				'HX-Trigger-After-Swap: ' . wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message' => $message,
						),
					)
				)
			);
		}

		$errorId = 'ran-booster-credential-validation-error-' . $credentialId;
		echo '<div id="' . esc_attr( $errorId ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"' . ( null === $error ? ' hidden' : '' ) . '><p>' . esc_html( $error ?? '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned escaped fragment.
		exit;
	}

	private function safeCredentialError( \Throwable $exception ): string {
		if ( $exception instanceof CredentialRequestException || $exception instanceof InvalidCredentialInput || $exception instanceof InvalidWebhookInput ) {
			return $exception->getMessage();
		}

		return 'Booster could not complete the credential request.';
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>|null
	 */
	private function resolvePackageRequest( array $request ): ?array {
		try {
			return $this->packageRepositories->resolve( $request );
		} catch ( InvalidProviderCode | UnknownProvider $failure ) {
			$message = 'The selected repository provider is not available.';
		} catch ( UnsupportedProviderCapability $failure ) {
			$message = 'The selected repository provider does not yet support this package operation.';
		} catch ( \InvalidArgumentException $failure ) {
			$message = 'Check the repository provider, account, repository, and credential fields.';
		} catch ( \RuntimeException $failure ) {
			$message = match ( (int) $failure->getCode() ) {
				401 => 'The repository provider rejected the selected credential.',
				403 => 'The repository provider denied access. Check credential permissions or rate limits.',
				404 => 'The repository could not be found, or the selected credential cannot access it.',
				429 => 'The repository provider rate limit has been reached. Try again later.',
				default => 'Booster could not verify the repository. Please try again.',
			};
		} catch ( \Throwable $failure ) {
			$message = 'Booster could not verify the repository. Please try again.';
		}

		$context = array(
			'operation' => isset( $request['action'] ) && is_string( $request['action'] )
				? sanitize_key( wp_unslash( $request['action'] ) )
				: '',
			'step'      => 'package_repository_resolve',
		);
		if ( isset( $request['provider'] )
			&& is_string( $request['provider'] )
			&& preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/D', $request['provider'] ) === 1 ) {
			$context['provider'] = $request['provider'];
		}
		$this->dashboard->addFailureMessage(
			new \WP_Error( 'ran_booster_repository_error', $message ),
			$failure,
			$context
		);

		return null;
	}

	/** @param array<string, mixed> $request */
	private function providerCode( array $request ): ProviderCode {
		try {
			if ( ! isset( $request['provider'] ) || ! is_string( $request['provider'] ) ) {
				throw new CredentialRequestException( 'Choose a supported repository provider.' );
			}

			$provider = ProviderCode::parse( wp_unslash( $request['provider'] ) );
			$this->providers->get( $provider );

			return $provider;
		} catch ( \Throwable ) {
			throw new CredentialRequestException( 'Choose a supported repository provider.' );
		}
	}

	private function providerAdmin( ProviderCode $provider ): ProviderAdminMetadata {
		try {
			$admin = $this->providers->get( $provider )->getMetadata()->admin;
		} catch ( \Throwable ) {
			throw new CredentialRequestException( 'Choose a supported repository provider.' );
		}

		if ( null === $admin ) {
			throw new CredentialRequestException( 'Repository provider settings are unavailable.' );
		}

		return $admin;
	}
}

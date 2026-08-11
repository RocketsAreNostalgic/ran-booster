<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageActionFailure;
use RAN\Admin\BulkPackageActionService;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Deployment\PackageMutationGuard;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\InvalidProviderCode;
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
	private PackageRepositoryRequestResolver $packageRepositories;
	private PackageEditProviderGuard $packageEdits;
	private ?DeploymentCoordinator $deploymentCoordinator;
	private ?BulkPackageActionService $bulkPackageActions;
	private ?TemporaryDebugCapture $debugCapture;
	private ?SecretsStorageProvisioner $secretsStorage;
	private ?DeploymentAttemptRepository $deploymentAttempts;
	private ProviderProfileAdminController $providerProfiles;

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
		?ProviderProfileAdminController $providerProfileInteraction = null
	) {
		$this->dashboard             = $dashboard;
		$this->packageRepositories   = $packageRepositories;
		$this->packageEdits          = $packageEdits;
		$this->deploymentCoordinator = $deploymentCoordinator;
		$this->bulkPackageActions    = $bulkPackageActions;
		$this->debugCapture          = $debugCapture;
		$this->secretsStorage        = $secretsStorage;
		$this->deploymentAttempts    = $deploymentAttempts;
		$this->providerProfiles      = $providerProfileInteraction ?? new ProviderProfileAdminController(
			$dashboard,
			$providers,
			$secrets,
			$webhookAuthorities,
			$updaterLock,
			$credentialUsage ?? new CredentialUsageReader(),
			$publicLookupProfiles ?? new PublicRepositoryLookupProfileStore(),
			$expiryObservations ?? new CredentialExpiryObservationStore()
		);
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
				$this->providerProfiles->managePublicLookupProfile( $request, $this->isHtmxRequest() );

				return;
			}

			if ( 'validate-access-profile' === $action ) {
				$this->providerProfiles->manageCredentialValidation( $request, $this->isHtmxRequest() );

				return;
			}

			$credentialActions = array(
				'save-access-profile',
				'delete-access-profile',
				'save-webhook-profile',
				'delete-webhook-profile',
			);

			if ( in_array( $action, $credentialActions, true ) ) {
				$this->providerProfiles->manageCredentialProfiles( $request );

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

	private function isHtmxRequest(): bool {
		$header = $_SERVER['HTTP_HX_REQUEST'] ?? null;

		return is_string( $header ) && 'true' === strtolower( trim( $header ) );
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
}

<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;
use RAN\Admin\BulkPackageActionService;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\DeploymentAdminController;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLocator;
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
	private PackageAdminController $packageAdmin;
	private ?TemporaryDebugCapture $debugCapture;
	private ?SecretsStorageProvisioner $secretsStorage;
	private ProviderProfileAdminController $providerProfiles;
	private DeploymentAdminController $deploymentAdmin;

	/**
	 * @param Dashboard             $dashboard Dashboard message target.
	 * @param ProviderRegistry $providers Provider catalog.
	 * @param SecretsFile      $secrets   Provider credential store.
	 * @param PackageRepositoryRequestResolver       $packageRepositories Package request resolver.
	 * @param ManagedPackageWebhookAuthorityResolver $webhookAuthorities  Stable webhook authority resolver.
	 * @param PackageAdminController                  $packageAdmin        Single-package browser owner.
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
		PackageAdminController $packageAdmin,
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
		// Retained for positional container and test compatibility; their owners are injected below.
		unset( $packageRepositories, $bulkPackageActions );

		$this->dashboard        = $dashboard;
		$this->packageAdmin     = $packageAdmin;
		$this->debugCapture     = $debugCapture;
		$this->secretsStorage   = $secretsStorage;
		$this->providerProfiles = $providerProfileInteraction ?? new ProviderProfileAdminController(
			$dashboard,
			$providers,
			$secrets,
			$webhookAuthorities,
			$updaterLock,
			$credentialUsage ?? new CredentialUsageReader(),
			$publicLookupProfiles ?? new PublicRepositoryLookupProfileStore(),
			$expiryObservations ?? new CredentialExpiryObservationStore()
		);
		$this->deploymentAdmin  = new DeploymentAdminController(
			$dashboard,
			$deploymentCoordinator,
			$deploymentAttempts
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
				$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
				$this->deploymentAdmin->manageDeploymentAttempt(
					$action,
					$request,
					is_string( $requestMethod ) && 'POST' === strtoupper( $requestMethod )
				);

				return;
			}

			if ( in_array( $action, array( 'bulk-plugin', 'bulk-theme' ), true ) ) {
				$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
				$redirect      = $this->packageAdmin->manageBulk(
					$this->dashboard,
					$action,
					$request,
					is_string( $requestMethod ) && 'POST' === strtoupper( $requestMethod )
				);
				if ( is_string( $redirect ) ) {
					$this->redirectTo( $redirect );
				}

				return;
			}

			$packageActions = array(
				'install-plugin',
				'install-theme',
				'edit-plugin',
				'edit-theme',
				'update-plugin',
				'update-theme',
				'unlink-plugin',
				'unlink-theme',
				'unlink-delete-plugin',
				'unlink-delete-theme',
			);
			if ( ! in_array( $action, $packageActions, true ) ) {
				return;
			}
			$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
			$redirect      = $this->packageAdmin->manage(
				$this->dashboard,
				$action,
				$request,
				is_string( $requestMethod ) && 'POST' === strtoupper( $requestMethod )
			);
			if ( is_string( $redirect ) ) {
				$this->redirectTo( $redirect );
			}
		}
	}

	protected function redirectTo( string $url ): never {
		if ( $this->isHtmxRequest() ) {
			$location = wp_json_encode(
				array(
					'path'   => wp_make_link_relative( $url ),
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
}

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
	private PackageAdminController $package_admin;
	private ?TemporaryDebugCapture $debug_capture;
	private ?SecretsStorageProvisioner $secrets_storage;
	private ProviderProfileAdminController $provider_profiles;
	private DeploymentAdminController $deployment_admin;

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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		PackageRepositoryRequestResolver $packageRepositories,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		ManagedPackageWebhookAuthorityResolver $webhookAuthorities,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		PackageAdminController $packageAdmin,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		WordPressUpdaterLock $updaterLock,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?DeploymentCoordinator $deploymentCoordinator = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?CredentialUsageReader $credentialUsage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?BulkPackageActionService $bulkPackageActions = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?PublicRepositoryLookupProfileStore $publicLookupProfiles = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?TemporaryDebugCapture $debugCapture = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?CredentialExpiryObservationStore $expiryObservations = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?SecretsStorageProvisioner $secretsStorage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?DeploymentAttemptRepository $deploymentAttempts = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?ProviderProfileAdminController $providerProfileInteraction = null
	) {
		// Retained for positional container and test compatibility; their owners are injected below.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		unset( $packageRepositories, $bulkPackageActions );

		$this->dashboard = $dashboard;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->package_admin = $packageAdmin;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->debug_capture = $debugCapture;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->secrets_storage = $secretsStorage;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->provider_profiles = $providerProfileInteraction ?? new ProviderProfileAdminController(
			$dashboard,
			$providers,
			$secrets,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$webhookAuthorities,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$updaterLock,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$credentialUsage ?? new CredentialUsageReader(),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$publicLookupProfiles ?? new PublicRepositoryLookupProfileStore(),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$expiryObservations ?? new CredentialExpiryObservationStore()
		);
		$this->deployment_admin = new DeploymentAdminController(
			$dashboard,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$deploymentCoordinator,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$deploymentAttempts
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
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
				$this->run_troubleshooting( $request );

				return;
			}

			if ( 'manage-debug-capture' === $action ) {
				$this->manage_debug_capture( $request );

				return;
			}

			if ( 'create-secure-storage' === $action ) {
				$this->create_secure_storage();

				return;
			}

			if ( 'adopt-secure-storage' === $action ) {
				$this->adopt_secure_storage( $request );

				return;
			}

			if ( 'reset-empty-storage' === $action ) {
				$this->reset_empty_storage( $request );

				return;
			}

			if ( 'save-public-lookup-profile' === $action ) {
				$this->provider_profiles->managePublicLookupProfile( $request, $this->is_htmx_request() );

				return;
			}

			if ( 'validate-access-profile' === $action ) {
				$this->provider_profiles->manageCredentialValidation( $request, $this->is_htmx_request() );

				return;
			}

			$credential_actions = array(
				'save-access-profile',
				'delete-access-profile',
				'save-webhook-profile',
				'delete-webhook-profile',
			);

			if ( in_array( $action, $credential_actions, true ) ) {
				$this->provider_profiles->manageCredentialProfiles( $request );

				return;
			}

			$deployment_actions = array(
				'reconcile-deployment-worker',
				'request-deployment-runner',
				'resolve-needs-attention',
			);
			if ( in_array( $action, $deployment_actions, true ) ) {
				$request_method = $_SERVER['REQUEST_METHOD'] ?? null;
				$this->deployment_admin->manageDeploymentAttempt(
					$action,
					$request,
					is_string( $request_method ) && 'POST' === strtoupper( $request_method )
				);

				return;
			}

			if ( in_array( $action, array( 'bulk-plugin', 'bulk-theme' ), true ) ) {
				$request_method = $_SERVER['REQUEST_METHOD'] ?? null;
				$redirect       = $this->package_admin->manageBulk(
					$this->dashboard,
					$action,
					$request,
					is_string( $request_method ) && 'POST' === strtoupper( $request_method )
				);
				if ( is_string( $redirect ) ) {
					$this->redirectTo( $redirect );
				}

				return;
			}

			$package_actions = array(
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
			if ( ! in_array( $action, $package_actions, true ) ) {
				return;
			}
			$request_method = $_SERVER['REQUEST_METHOD'] ?? null;
			$redirect       = $this->package_admin->manage(
				$this->dashboard,
				$action,
				$request,
				is_string( $request_method ) && 'POST' === strtoupper( $request_method )
			);
			if ( is_string( $redirect ) ) {
				$this->redirectTo( $redirect );
			}
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
	protected function redirectTo( string $url ): never {
		if ( $this->is_htmx_request() ) {
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

	private function create_secure_storage(): void {
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
			$result = null === $this->secrets_storage
				? SecretsStorageProvisioningResult::manual_required(
					'provisioner_unavailable',
					__( 'Automatic secure storage setup is unavailable.', 'ran-booster' )
				)
				: $this->secrets_storage->provision();
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
			$result = SecretsStorageProvisioningResult::manual_required(
				'provisioning_failed',
				__( 'Automatic secure storage setup could not be completed.', 'ran-booster' )
			);
		}

		if ( $result->requires_next_request_verification() ) {
			$admin_url = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $admin_url . '?page=ran-booster&tab=overview' );
		}

		// Keep a failed attempt local to this protected POST response. Paths and
		// failure details never enter redirects, logs, transients or global notices.
		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function adopt_secure_storage( array $request ): void {
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
			$result = null === $this->secrets_storage
				? SecretsStorageProvisioningResult::manual_required(
					'provisioner_unavailable',
					__( 'Automatic storage recovery is unavailable.', 'ran-booster' )
				)
				: $this->secrets_storage->adoptRecovery( $token );
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
			$result = SecretsStorageProvisioningResult::manual_required(
				'recovery_failed',
				__( 'Automatic storage recovery could not be completed.', 'ran-booster' )
			);
		}

		if ( $result->requires_next_request_verification() ) {
			$admin_url = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $admin_url . '?page=ran-booster&tab=overview' );
		}

		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function reset_empty_storage( array $request ): void {
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
			$result = null === $this->secrets_storage
				? SecretsStorageProvisioningResult::manual_required(
					'provisioner_unavailable',
					__( 'Empty credential storage reset is unavailable.', 'ran-booster' )
				)
				: $this->secrets_storage->resetOrphanedStorage( $confirmation );
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
			$result = SecretsStorageProvisioningResult::manual_required(
				'storage_reset_failed',
				__( 'Empty credential storage could not be reset safely.', 'ran-booster' )
			);
		}

		if ( 'storage_reset' === $result->code() ) {
			$admin_url = is_multisite()
				? network_admin_url( 'admin.php' )
				: admin_url( 'admin.php' );
			$this->redirectTo( $admin_url . '?page=ran-booster&tab=overview' );
		}

		$this->dashboard->setSecretsStorageProvisioningResult( $result );
	}

	/** @param array<string, mixed> $request */
	private function manage_debug_capture( array $request ): void {
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
		$htmx_request = $this->is_htmx_request() && in_array( $operation, array( 'start', 'stop' ), true );

		try {
			if ( null === $this->debug_capture ) {
				throw new \RuntimeException();
			}

			match ( $operation ) {
				'start' => $this->debug_capture->start(),
				'stop' => $this->debug_capture->stop(),
				'delete' => $this->debug_capture->delete(),
			};
		} catch ( \Throwable $failure ) {
			if ( $htmx_request ) {
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

		if ( $htmx_request ) {
			$message = 'start' === $operation
				? __( 'Temporary logging capture started.', 'ran-booster' )
				: __( 'Temporary logging capture stopped.', 'ran-booster' );
			$this->respondToHtmxDebugCapture( $message, null, 200 );
		}

		$admin_url = is_multisite()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$this->redirectTo( $admin_url . '?page=ran-booster&tab=troubleshooting&panel=debug-capture' );
	}

	/** @param array<string, mixed> $request */
	private function run_troubleshooting( array $request ): void {
		foreach ( array( 'manage_options', 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to run troubleshooting.', 'ran-booster' ) );
			}
		}

		check_admin_referer( 'ran-booster-run-troubleshooting' );

		if ( ! isset( $request['provider'] ) || ! is_string( $request['provider'] ) ) {
			return;
		}

		foreach ( array( 'credential_id', 'repository' ) as $optional_field ) {
			if ( isset( $request[ $optional_field ] ) && ! is_string( $request[ $optional_field ] ) ) {
				return;
			}
		}

		$provider      = wp_unslash( $request['provider'] );
		$credential_id = isset( $request['credential_id'] ) ? trim( wp_unslash( $request['credential_id'] ) ) : null;
		$repository    = isset( $request['repository'] ) ? wp_unslash( $request['repository'] ) : null;
		$credential_id = '' === $credential_id ? null : $credential_id;
		$repository    = '' === $repository ? null : $repository;

		if ( strlen( $provider ) > 32
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $provider )
			|| ( null !== $credential_id && ( strlen( $credential_id ) > ProviderDiagnosticRequest::MAX_CREDENTIAL_ID_BYTES || 1 === preg_match( '/[\x00-\x1F\x7F]/', $credential_id ) ) )
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
				'credential_id' => $credential_id,
				'repository'    => $repository,
			)
		);

		if ( $this->is_htmx_request() ) {
			$this->respondToHtmxDiagnostics( $this->dashboard->troubleshootingSucceeded() );
		}
	}

	private function is_htmx_request(): bool {
		$header = $_SERVER['HTTP_HX_REQUEST'] ?? null;

		return is_string( $header ) && 'true' === strtolower( trim( $header ) );
	}

	/**
	 * Respond to the explicit logging capture enhancement. The named event is
	 * success-only; failures keep their explanation beside the capture controls.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
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

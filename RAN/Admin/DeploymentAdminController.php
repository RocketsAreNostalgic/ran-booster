<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Dashboard;
use RAN\Deployment\{DeploymentAttemptRepository, DeploymentCoordinator};

/** @internal Core deployment-recovery request and response owner. */
final class DeploymentAdminController {

	public const AJAX_ACTION  = 'ran_booster_dismiss_background_failure_notice';
	public const NONCE_ACTION = 'ran-booster-background-failure-notice';

	public function __construct(
		private Dashboard $dashboard,
		private ?DeploymentCoordinator $coordinator = null,
		private ?DeploymentAttemptRepository $attempts = null,
		private ?BackgroundDeploymentFailureMonitor $monitor = null
	) {
	}

	public function handle(): mixed {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_send_json_error( array( 'message' => __( 'You are not allowed to dismiss this notice.', 'ran-booster' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return wp_send_json_error( array( 'message' => __( 'The notice dismissal request expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}
		$user_id     = $this->current_user_id();
		$fingerprint = $this->monitor?->fingerprint();
		if ( $user_id < 1 || null === $fingerprint ) {
			return wp_send_json_error( array( 'message' => __( 'RAN Booster could not identify an active deployment failure.', 'ran-booster' ) ), 409 );
		}
		update_user_meta( $user_id, DeploymentAdminPresenter::USER_META_KEY, $fingerprint );
		if ( ! hash_equals( $fingerprint, (string) get_user_meta( $user_id, DeploymentAdminPresenter::USER_META_KEY, true ) ) ) {
			return wp_send_json_error( array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ), 500 );
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}

	/** @param array<string, mixed> $request */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public/protected caller contract; retain public named-parameter names.
	public function manageDeploymentAttempt( string $action, array $request, bool $postRequest ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( ! $postRequest ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster deployments.', 'ran-booster' ) );
		}
		check_admin_referer( 'ran-booster-' . $action );
		try {
			if ( 'resolve-needs-attention' === $action ) {
				$this->resolve_needs_attention( $request );
				return;
			}
			if ( null === $this->coordinator ) {
				throw new \RuntimeException( 'The deployment coordinator is unavailable.' );
			}
			if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to manage this package.', 'ran-booster' ) );
			}
			if ( 'request-deployment-runner' === $action ) {
				$this->coordinator->requestRunner();
				$this->dashboard->addMessage( __( 'The deployment runner was requested.', 'ran-booster' ) );
				return;
			}
			$attempt_id     = $this->canonical_attempt_id( $request['attempt_id'] ?? null );
			$correlation_id = $this->canonical_correlation_id( $request['correlation_id'] ?? null );
			if ( '1' !== ( $request['confirm_stopped'] ?? null ) ) {
				throw new \RuntimeException( 'Explicit stopped-worker confirmation is required.' );
			}
			$this->coordinator->reconcileConfirmedStopped( $attempt_id, $correlation_id );
			$this->dashboard->addMessage( __( 'The protected deployment action was accepted.', 'ran-booster' ) );
		} catch ( \Throwable $exception ) {
			$operation = $action;
			$step      = 'deployment_action_dispatch';
			$error     = new \WP_Error( 'ran_booster_deployment_action_unavailable', __( 'Booster could not safely accept this deployment action. Refresh the activity record and try again.', 'ran-booster' ) );
			$this->dashboard->addFailureMessage( $error, $exception, compact( 'operation', 'step' ) );
		}
	}

	/** @param array<string, mixed> $request */
	private function resolve_needs_attention( array $request ): void {
		if ( null === $this->attempts ) {
			throw new \RuntimeException( 'The deployment attempt repository is unavailable.' );
		}
		$attempt_id     = $this->canonical_attempt_id( $request['attempt_id'] ?? null );
		$correlation_id = $this->canonical_correlation_id( $request['correlation_id'] ?? null );
		if ( '1' !== ( $request['confirm_reviewed'] ?? null ) ) {
			throw new \RuntimeException( 'Explicit uncertainty-review confirmation is required.' );
		}
		$attempt = $this->attempts->findExact( $attempt_id );
		if ( null === $attempt || ! hash_equals( $attempt->get_correlation_id(), $correlation_id ) ) {
			throw new \RuntimeException( 'The deployment activity identity no longer matches.' );
		}
		$capability = 'plugin' === $attempt->safe_data()['package_type'] ? 'update_plugins' : 'update_themes';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage this package.', 'ran-booster' ) );
		}
		$this->attempts->resolveNeedsAttention( $attempt_id, $correlation_id, $this->current_user_id() );
		$this->dashboard->addMessage( __( 'Retry is allowed. No package files or settings were changed.', 'ran-booster' ) );
	}

	private function canonical_attempt_id( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}
		$attempt_id = (int) $value;
		if ( $attempt_id <= 0 || (string) $attempt_id !== $value ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}

		return $attempt_id;
	}

	private function canonical_correlation_id( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
			throw new \RuntimeException( 'The deployment activity reference is invalid.' );
		}

		return $value;
	}

	protected function current_user_id(): int {
		return (int) get_current_user_id();
	}
}

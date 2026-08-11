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
		$userId      = $this->currentUserId();
		$fingerprint = $this->monitor?->fingerprint();
		if ( $userId < 1 || null === $fingerprint ) {
			return wp_send_json_error( array( 'message' => __( 'RAN Booster could not identify an active deployment failure.', 'ran-booster' ) ), 409 );
		}
		update_user_meta( $userId, DeploymentAdminPresenter::USER_META_KEY, $fingerprint );
		if ( ! hash_equals( $fingerprint, (string) get_user_meta( $userId, DeploymentAdminPresenter::USER_META_KEY, true ) ) ) {
			return wp_send_json_error( array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ), 500 );
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}

	/** @param array<string, mixed> $request */
	public function manageDeploymentAttempt( string $action, array $request, bool $postRequest ): void {
		if ( ! $postRequest ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage Booster deployments.', 'ran-booster' ) );
		}
		check_admin_referer( 'ran-booster-' . $action );
		try {
			if ( 'resolve-needs-attention' === $action ) {
				$this->resolveNeedsAttention( $request );
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
			$attemptId     = $this->canonicalAttemptId( $request['attempt_id'] ?? null );
			$correlationId = $this->canonicalCorrelationId( $request['correlation_id'] ?? null );
			if ( '1' !== ( $request['confirm_stopped'] ?? null ) ) {
				throw new \RuntimeException( 'Explicit stopped-worker confirmation is required.' );
			}
			$this->coordinator->reconcileConfirmedStopped( $attemptId, $correlationId );
			$this->dashboard->addMessage( __( 'The protected deployment action was accepted.', 'ran-booster' ) );
		} catch ( \Throwable $exception ) {
			$operation = $action;
			$step      = 'deployment_action_dispatch';
			$error     = new \WP_Error( 'ran_booster_deployment_action_unavailable', __( 'Booster could not safely accept this deployment action. Refresh the activity record and try again.', 'ran-booster' ) );
			$this->dashboard->addFailureMessage( $error, $exception, compact( 'operation', 'step' ) );
		}
	}

	/** @param array<string, mixed> $request */
	private function resolveNeedsAttention( array $request ): void {
		if ( null === $this->attempts ) {
			throw new \RuntimeException( 'The deployment attempt repository is unavailable.' );
		}
		$attemptId     = $this->canonicalAttemptId( $request['attempt_id'] ?? null );
		$correlationId = $this->canonicalCorrelationId( $request['correlation_id'] ?? null );
		if ( '1' !== ( $request['confirm_reviewed'] ?? null ) ) {
			throw new \RuntimeException( 'Explicit uncertainty-review confirmation is required.' );
		}
		$attempt = $this->attempts->findExact( $attemptId );
		if ( null === $attempt || ! hash_equals( $attempt->getCorrelationId(), $correlationId ) ) {
			throw new \RuntimeException( 'The deployment activity identity no longer matches.' );
		}
		$capability = 'plugin' === $attempt->safeData()['package_type'] ? 'update_plugins' : 'update_themes';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage this package.', 'ran-booster' ) );
		}
		$this->attempts->resolveNeedsAttention( $attemptId, $correlationId, $this->currentUserId() );
		$this->dashboard->addMessage( __( 'The deployment review was recorded. This package may now be retried.', 'ran-booster' ) );
	}

	private function canonicalAttemptId( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}
		$attemptId = (int) $value;
		if ( $attemptId <= 0 || (string) $attemptId !== $value ) {
			throw new \RuntimeException( 'The deployment attempt identity is invalid.' );
		}

		return $attemptId;
	}

	private function canonicalCorrelationId( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
			throw new \RuntimeException( 'The deployment activity reference is invalid.' );
		}

		return $value;
	}

	protected function currentUserId(): int {
		return (int) get_current_user_id();
	}
}

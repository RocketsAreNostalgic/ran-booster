<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use RAN\Logging\BoosterLogger;
use RAN\Portability\PortabilityApplicationService;
use RAN\Runtime\RuntimeSupport;
use Throwable;

/** Core-owned authorization adapter over the canonical Portability service. */
final class NativePortabilityFacade extends PortabilityFacade {

	/** @var \Closure(string, bool): bool */
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/**
	 * @param callable(string, bool): bool|null $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 */
	public function __construct(
		private PortabilityApplicationService $application,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?callable $canManage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?callable $verifyNonce = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		$this->can_manage = null === $canManage
			? static fn ( string $type, bool $apply ): bool => current_user_can( 'manage_options' )
				&& ( ! $apply || current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			: \Closure::fromCallable( $canManage );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		$this->verify_nonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			: \Closure::fromCallable( $verifyNonce );
	}

	public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return $this->blocked_review(
				$candidate,
				'unsupported_runtime',
				__( 'Portability is unavailable on WordPress Multisite.', 'ran-booster' )
			);
		}
		if ( ! $this->authorized( 'review', $candidate, null, $nonce, false ) ) {
			return $this->blocked_review(
				$candidate,
				'forbidden',
				__( 'The package could not be reviewed.', 'ran-booster' )
			);
		}

		try {
			return $this->application->reviewCandidate( $candidate );
		} catch ( Throwable $failure ) {
			$this->log_failure( 'review', $failure );

			return $this->blocked_review(
				$candidate,
				'unexpected_failure',
				__( 'The package could not be reviewed safely.', 'ran-booster' )
			);
		}
	}

	public function apply(
		PortabilityCandidate $candidate,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		string $expectedFingerprint,
		string $nonce
	): PortabilityApplyResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::FAILED,
				'unsupported_runtime',
				__( 'Portability is unavailable on WordPress Multisite.', 'ran-booster' ),
				false
			);
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		if ( ! $this->authorized( 'apply', $candidate, $expectedFingerprint, $nonce, true ) ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::FAILED,
				'forbidden',
				__( 'The package could not be adopted.', 'ran-booster' ),
				false
			);
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			return $this->application->applyCandidate( $candidate, $expectedFingerprint );
		} catch ( Throwable $failure ) {
			$this->log_failure( 'apply', $failure );

			return new PortabilityApplyResult(
				PortabilityApplyResult::FAILED,
				'unexpected_failure',
				__( 'The package could not be adopted safely.', 'ran-booster' ),
				false
			);
		}
	}

	private function authorized(
		string $operation,
		PortabilityCandidate $candidate,
		?string $expected_fingerprint,
		string $nonce,
		bool $apply
	): bool {
		try {
			return ( $this->can_manage )( $candidate->type, $apply )
				&& ( $this->verify_nonce )(
					$nonce,
					$this->nonceAction( $operation, $candidate, $expected_fingerprint )
				);
		} catch ( Throwable ) {
			return false;
		}
	}

	private function blocked_review(
		PortabilityCandidate $candidate,
		string $reason,
		string $message
	): PortabilityReviewResult {
		return PortabilityReviewResult::fromResolved(
			$candidate,
			PortabilityReviewResult::BLOCKED,
			$reason,
			$message,
			null,
			null
		);
	}

	private function log_failure( string $operation, Throwable $failure ): void {
		BoosterLogger::logException(
			'portability facade failed',
			$failure,
			array(
				'operation' => 'portability_' . $operation,
				'step'      => $operation,
			)
		);
	}
}

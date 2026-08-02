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
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	/**
	 * @param callable(string, bool): bool|null $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 */
	public function __construct(
		private PortabilityApplicationService $application,
		?callable $canManage = null,
		?callable $verifyNonce = null
	) {
		$this->canManage   = null === $canManage
			? static fn ( string $type, bool $apply ): bool => current_user_can( 'manage_options' )
				&& ( ! $apply || current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' ) )
			: \Closure::fromCallable( $canManage );
		$this->verifyNonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
	}

	public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return $this->blockedReview(
				$candidate,
				'unsupported_runtime',
				__( 'Portability is unavailable on WordPress Multisite.', 'ran-booster' )
			);
		}
		if ( ! $this->authorized( 'review', $candidate, null, $nonce, false ) ) {
			return $this->blockedReview(
				$candidate,
				'forbidden',
				__( 'The package could not be reviewed.', 'ran-booster' )
			);
		}

		try {
			return $this->application->reviewCandidate( $candidate );
		} catch ( Throwable $failure ) {
			$this->logFailure( 'review', $failure );

			return $this->blockedReview(
				$candidate,
				'unexpected_failure',
				__( 'The package could not be reviewed safely.', 'ran-booster' )
			);
		}
	}

	public function apply(
		PortabilityCandidate $candidate,
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
		if ( ! $this->authorized( 'apply', $candidate, $expectedFingerprint, $nonce, true ) ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::FAILED,
				'forbidden',
				__( 'The package could not be adopted.', 'ran-booster' ),
				false
			);
		}

		try {
			return $this->application->applyCandidate( $candidate, $expectedFingerprint );
		} catch ( Throwable $failure ) {
			$this->logFailure( 'apply', $failure );

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
		?string $expectedFingerprint,
		string $nonce,
		bool $apply
	): bool {
		try {
			return ( $this->canManage )( $candidate->type, $apply )
				&& ( $this->verifyNonce )(
					$nonce,
					$this->nonceAction( $operation, $candidate, $expectedFingerprint )
				);
		} catch ( Throwable ) {
			return false;
		}
	}

	private function blockedReview(
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

	private function logFailure( string $operation, Throwable $failure ): void {
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

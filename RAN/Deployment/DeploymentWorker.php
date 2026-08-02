<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Portability\WpPusherCoexistencePolicy;
use RAN\Runtime\RuntimeSupport;
use Throwable;

/** Claims and processes at most one queued deployment per real WP-Cron run. */
final readonly class DeploymentWorker {

	public function __construct(
		private DeploymentAttemptRepository $attempts,
		private DeploymentCoordinator $coordinator,
		private WordPressWorkerWakeup $wakeup
	) {
	}

	/** @return array{status: 'empty'|'processed'|'contended'|'unavailable', runner_status: string, correlation_id?: string} */
	public function runOnce(): array {
		if ( ! RuntimeSupport::current()->allowsManagedOperations()
			|| WpPusherCoexistencePolicy::conflictActive()
			|| ! wp_doing_cron() ) {
			return array(
				'status'        => 'unavailable',
				'runner_status' => 'not_required',
			);
		}

		try {
			$attempt = $this->attempts->claimNext();
			if ( null === $attempt ) {
				return array(
					'status'        => 'empty',
					'runner_status' => 'not_required',
				);
			}
			$this->coordinator->executeClaimed( $attempt );

			return array(
				'status'         => 'processed',
				'runner_status'  => $this->wakeup->request(),
				'correlation_id' => $attempt->getCorrelationId(),
			);
		} catch ( DeploymentStorageFailure $failure ) {
			$reference = $failure->getActiveCorrelationId();
			$result    = array(
				'status'        => null === $reference ? 'unavailable' : 'contended',
				'runner_status' => 'not_required',
			);
			if ( null !== $reference ) {
				$result['correlation_id'] = $reference;
			}

			return $result;
		} catch ( Throwable ) {
			return array(
				'status'        => 'unavailable',
				'runner_status' => 'not_required',
			);
		}
	}
}

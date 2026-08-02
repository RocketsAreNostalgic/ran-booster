<?php

declare(strict_types=1);

namespace RAN\Deployment;

/**
 * Receives a durably finished attempt without owning deployment truth.
 */
interface DeploymentFailureNotifier {

	public function notify( DeploymentAttempt $attempt ): bool;
}

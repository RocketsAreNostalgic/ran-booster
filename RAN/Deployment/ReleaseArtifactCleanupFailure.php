<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

/**
 * Core cannot prove that provisional release-artifact cleanup completed.
 */
final class ReleaseArtifactCleanupFailure extends RuntimeException {
	public function __construct() {
		parent::__construct( 'The release artifact transfer could not be cleaned up safely.' );
	}
}

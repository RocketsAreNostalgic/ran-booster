<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

final class DeploymentArchiveLimitFailure extends RuntimeException {

	private function __construct( public readonly string $outcomeCode, string $message ) {
		parent::__construct( $message );
	}

	public static function compressed(): self {
		return new self( DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE, 'The deployment archive exceeds the compressed-size limit.' );
	}

	public static function expanded(): self {
		return new self( DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE, 'The deployment archive exceeds the expanded-size limit.' );
	}

	public static function configuration(): self {
		return new self( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID, 'The deployment archive size configuration is invalid.' );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;

/**
 * The only executable attempt states persisted by Booster.
 */
enum DeploymentState: string {
	case QUEUED          = 'queued';
	case RUNNING         = 'running';
	case SUCCEEDED       = 'succeeded';
	case FAILED          = 'failed';
	case NEEDS_ATTENTION = 'needs_attention';

	public function is_terminal(): bool {
		return match ( $this ) {
			self::SUCCEEDED, self::FAILED, self::NEEDS_ATTENTION => true,
			self::QUEUED, self::RUNNING => false,
		};
	}

	public function requires_operator_resolution(): bool {
		return self::NEEDS_ATTENTION === $this;
	}

	public static function from_database( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A deployment state must be a string.' );
		}

		return self::tryFrom( $value )
			?? throw new InvalidArgumentException( 'The deployment state is not recognised.' );
	}
}

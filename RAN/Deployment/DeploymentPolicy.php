<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;

/**
 * A managed package's explicit deployment authority.
 */
enum DeploymentPolicy: string {
	case DISABLED  = 'disabled';
	case MANUAL    = 'manual';
	case AUTOMATIC = 'automatic';

	public function allows_manual_mutation(): bool {
		return self::DISABLED !== $this;
	}

	public function allows_webhook_mutation(): bool {
		return self::AUTOMATIC === $this;
	}

	public static function from_database( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A deployment policy must be a string.' );
		}

		return self::tryFrom( $value )
			?? throw new InvalidArgumentException( 'The deployment policy is not recognised.' );
	}
}

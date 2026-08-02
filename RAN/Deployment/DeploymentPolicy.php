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

	public function allowsManualMutation(): bool {
		return self::DISABLED !== $this;
	}

	public function allowsWebhookMutation(): bool {
		return self::AUTOMATIC === $this;
	}

	public static function fromDatabase( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A deployment policy must be a string.' );
		}

		return self::tryFrom( $value )
			?? throw new InvalidArgumentException( 'The deployment policy is not recognised.' );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

/** A classified, internal deployment check failure with a closed durable code. */
final class DeploymentCheckFailure extends RuntimeException {

	public function __construct( public readonly string $outcomeCode, string $message ) {
		if ( \RAN\Deployment\DeploymentState::FAILED !== DeploymentOutcome::fromCode( $outcomeCode )->getState() ) {
			throw new \InvalidArgumentException( 'A deployment check failure must have a failed outcome code.' );
		}
		parent::__construct( $message );
	}

	public static function providerStatus( int $status, string $message ): self {
		return new self( DeploymentOutcome::fromProviderFailure( new RuntimeException( '', $status ) )->getCode(), $message );
	}
}

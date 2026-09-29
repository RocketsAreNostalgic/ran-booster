<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

/** A classified, internal deployment check failure with a closed durable code. */
final class DeploymentCheckFailure extends RuntimeException {

	public function __construct( public readonly string $outcome_code, string $message ) {
		if ( \RAN\Deployment\DeploymentState::FAILED !== DeploymentOutcome::from_code( $outcome_code )->get_state() ) {
			throw new \InvalidArgumentException( 'A deployment check failure must have a failed outcome code.' );
		}
		parent::__construct( $message );
	}

	public static function provider_status( int $status, string $message ): self {
		return new self( DeploymentOutcome::from_provider_failure( new RuntimeException( '', $status ) )->get_code(), $message );
	}
}

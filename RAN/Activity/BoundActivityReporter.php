<?php

declare(strict_types=1);

namespace RAN\Activity;

use Closure;

/** Core supplies the resolver after accepting provider registration. */
final class BoundActivityReporter implements ActivityReporter {

	/** @param Closure(ActivityRequest): (?array{subject_type:string,subject_identity:string,adoption_token:?string,context:string,purpose:?string,provenance:string,actor?:int}) $capture */
	public function __construct( private ActivityStore $store, private string $provider, private Closure $capture ) {
	}

	public function begin( ActivityRequest $request ): ActivityOperation {
		$id      = null;
		$capture = ( $this->capture )( $request );
		if ( null !== $capture ) {
			// Provider-selected codes cannot allocate protected Core observation slots.
			$capture['purpose']    = null;
			$capture['provenance'] = 'provider';
			$id                    = $this->store->begin( $this->provider, $request, $capture );
		}
		return new RecordedActivityOperation( $this->store, $id );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Activity;

/** A missing record deliberately behaves as a no-op, including after storage failure. */
final class RecordedActivityOperation implements ActivityOperation {

	public function __construct( private ActivityStore $store, private ?int $record_id ) {
	}

	public function progress( string $code, string $message = '' ): void {
		$this->store->progress( $this->record_id, $code, $message );
	}

	public function complete( string $status, string $reason, string $message = '', string $remediation = '' ): void {
		$this->store->complete( $this->record_id, $status, $reason, $message, $remediation );
	}
}

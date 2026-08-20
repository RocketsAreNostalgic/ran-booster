<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

/** @internal Closed persisted webhook observation; it is explicitly not live readiness. */
final readonly class WebhookHistoryView {
	public function __construct(
		private string $providerCode,
		private string $repositoryId,
		private string $recordedStatus,
		private string $checkedAt
	) {
	}

	/** @return array{provider_code:string,repository_id:string,recorded_status:string,checked_at:string,current_local_condition:null,historical_not_live:true} */
	public function toArray(): array {
		return array( 'provider_code' => $this->providerCode, 'repository_id' => $this->repositoryId, 'recorded_status' => $this->recordedStatus, 'checked_at' => $this->checkedAt, 'current_local_condition' => null, 'historical_not_live' => true );
	}
}

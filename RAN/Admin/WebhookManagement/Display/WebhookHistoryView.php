<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

/** @internal Closed persisted webhook observation; it is explicitly not live readiness. */
final readonly class WebhookHistoryView {
	public function __construct(
		private string $provider_code,
		private string $repository_id,
		private string $recorded_status,
		private string $checked_at
	) {
	}

	/** @return array{provider_code:string,repository_id:string,recorded_status:string,checked_at:string,current_local_condition:null,historical_not_live:true} */
	public function to_array(): array {
		return array(
			'provider_code'           => $this->provider_code,
			'repository_id'           => $this->repository_id,
			'recorded_status'         => $this->recorded_status,
			'checked_at'              => $this->checked_at,
			'current_local_condition' => null,
			'historical_not_live'     => true,
		);
	}
}

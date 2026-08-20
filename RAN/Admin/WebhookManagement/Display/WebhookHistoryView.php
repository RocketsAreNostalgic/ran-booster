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

	/** Presentation stays outside the closed persisted observation. */
	public static function statusLabel( string $status ): string {
		return match ( $status ) {
			'not_configured' => __( 'No managed hook recorded', 'ran-booster' ),
			'configured' => __( 'Configured at last check', 'ran-booster' ),
			'profile_revision_stale' => __( 'Signing secret changed; webhook update required', 'ran-booster' ),
			'local_profile_missing' => __( 'Secret needs attention', 'ran-booster' ),
			/* translators: %s: webhook status description. */
			default => sprintf( __( 'Needs attention: %s at last check', 'ran-booster' ), 'configuration_drift' === $status ? __( 'Configuration drift', 'ran-booster' ) : ucwords( str_replace( '_', ' ', $status ) ) ),
		};
	}

	public static function statusTone( string $status ): string {
		return match ( $status ) {
			'configured' => 'ok', 'not_configured' => 'warning', 'orphaned', 'removal_pending' => 'error', default => 'warning' };
	}
}

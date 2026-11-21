<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Operation;

/**
 * Runs the unchanged Check (Validate) or Reconfigure (Repair) operation and, only when the coordinator reports it
 * successful, the unchanged Test operation.
 *
 * Every successful Check outcome (configuration_confirmed, configured_pending_delivery, verified) confirms the
 * configuration, and a successful Reconfigure leaves the hook awaiting a delivery, so each one is followed by the ping;
 * any other outcome skips it.
 *
 * Each call goes through the coordinator on its own: separate authorization, nonce, Activity entry and
 * local record write. Nothing here is a new operation type, and the caller verifies capability and both nonces first.
 *
 * @phpstan-type Outcome array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool}
 */
final class WebhookValidationWrapper {
	public function __construct( private readonly WebhookOperationCoordinator $operations ) {
	}

	/**
	 * A null ping means the Test operation was not run.
	 *
	 * @param 'check'|'reconfigure' $operation
	 * @return array{operation:Outcome,ping:Outcome|null}
	 */
	public function then_ping( string $operation, string $provider_code, string $repository_id, ?string $credential_id, string $operation_nonce, string $test_nonce ): array {
		$first = $this->operations->execute( $operation, $provider_code, $repository_id, $credential_id, null, $operation_nonce );
		if ( true !== $first['successful'] ) {
			return array(
				'operation' => $first,
				'ping'      => null,
			);
		}

		// The coordinator re-reads the record, so this call sees the revision the first operation just wrote.
		return array(
			'operation' => $first,
			'ping'      => $this->operations->execute( 'test', $provider_code, $repository_id, $credential_id, null, $test_nonce ),
		);
	}
}

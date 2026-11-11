<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Operation;

/**
 * Runs the unchanged Check operation and, only when the coordinator reports it successful, the unchanged Test operation.
 *
 * Every successful Check outcome (configuration_confirmed, configured_pending_delivery, verified) confirms the
 * configuration, so each one is followed by the ping; any other outcome skips it.
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
	 * @return array{check:Outcome,ping:Outcome|null}
	 */
	public function validate( string $provider_code, string $repository_id, ?string $credential_id, string $check_nonce, string $test_nonce ): array {
		$check = $this->operations->execute( 'check', $provider_code, $repository_id, $credential_id, null, $check_nonce );
		if ( true !== $check['successful'] ) {
			return array(
				'check' => $check,
				'ping'  => null,
			);
		}

		// The coordinator re-reads the record, so this call sees the revision the Check just wrote.
		return array(
			'check' => $check,
			'ping'  => $this->operations->execute( 'test', $provider_code, $repository_id, $credential_id, null, $test_nonce ),
		);
	}
}

<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Operation;

use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

/** Owns one authorized webhook operation and its local recovery transition. */
final class WebhookOperationCoordinator {
	public function __construct(
		private readonly WebhookAssistanceFacade $facade,
		private readonly InstallationStore $records
	) {
	}

	/**
	 * @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool}
	 */
	public function execute(
		string $operation,
		string $provider_code,
		string $repository_id,
		?string $credential_id,
		?string $selected_profile_id,
		string $nonce
	): array {
		try {
			$target = $this->facade->target( $provider_code, $repository_id );
		} catch ( \Throwable ) {
			$target = null;
		}
		if ( ! $target instanceof AssistanceTarget
			|| ! hash_equals( $provider_code, $target->provider_code() )
			|| ! hash_equals( $repository_id, $target->repository_id() ) ) {
			return $this->outcome( 'invalid_request', inline_safe: true );
		}

		$record = $this->records->find( $provider_code, $repository_id );
		if ( null !== $record && $record->requires_hook_identification() ) {
			return $this->outcome( 'manual_recovery_required' );
		}
		if ( null === $credential_id
			|| ( 'setup' === $operation && null !== $record )
			|| ( 'setup' !== $operation && ( null === $record
				|| ! hash_equals( $target->repository(), $record->repository() ) ) ) ) {
			return $this->outcome( 'invalid_token', inline_safe: true );
		}
		if ( 'setup' === $operation && null !== $selected_profile_id ) {
			$selected = false;
			try {
				foreach ( $this->facade->webhook_profile_choices( $provider_code, $repository_id ) as $choice ) {
					if ( is_array( $choice ) && is_string( $choice['id'] ?? null ) && hash_equals( $selected_profile_id, $choice['id'] ) ) {
						$selected = true;
						break;
					}
				}
			} catch ( \Throwable ) {
				$selected = false;
			}
			if ( ! $selected ) {
				return $this->outcome( 'invalid_request', inline_safe: true );
			}
		}

		try {
			$result = match ( $operation ) {
				'setup'       => $this->facade->setup( $target, $credential_id, $nonce, $selected_profile_id ),
				'check'       => $this->facade->check( $target, $credential_id, $record->hook_id(), $record->webhook_profile_id(), $record->webhook_profile_revision(), $nonce ),
				'reconfigure' => $this->facade->reconfigure( $target, $credential_id, $record->hook_id(), $record->webhook_profile_id(), $record->webhook_profile_revision(), $nonce ),
				'remove'      => $this->facade->remove( $target, $credential_id, $record->hook_id(), $record->webhook_profile_id(), $record->webhook_profile_revision(), $nonce ),
				'test'        => $this->facade->test( $target, $credential_id, $record->hook_id(), $record->webhook_profile_id(), $record->webhook_profile_revision(), $nonce ),
			};
		} catch ( \Throwable ) {
			return $this->outcome( 'operation_failed' );
		}
		if ( ! $result instanceof RepositoryWebhookOperationResult ) {
			return $this->outcome( 'operation_failed' );
		}

		return $this->apply_result( $operation, $target, $record, $credential_id, $result );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function apply_result( string $operation, AssistanceTarget $target, ?InstallationRecord $record, string $credential_id, RepositoryWebhookOperationResult $result ): array {
		$projection    = $result->to_array();
		$state         = $projection['state'] ?? null;
		$code          = $this->safe_code( $projection['code'] ?? null, 'operation_failed' );
		$observed      = $projection['observed_at'] ?? null;
		$delivery      = $projection['delivery'] ?? null;
		$configuration = $projection['configuration'] ?? null;
		$remediation   = $projection['remediation'] ?? null;
		if ( ! in_array( $state, array( 'succeeded', 'partial', 'ambiguous', 'failed' ), true )
			|| ! is_string( $observed )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $observed )
			|| ! in_array( $delivery, array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ), true )
			|| ! is_array( $configuration )
			|| ! is_string( $remediation )
			|| '' === trim( $remediation )
			|| strlen( $remediation ) > 512
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $remediation ) ) {
			return $this->outcome( 'operation_failed' );
		}

		if ( 'setup' === $operation ) {
			$outcome    = $this->record_setup( $target, $result, $credential_id, $state, $code, $observed, $delivery );
			$successful = 'succeeded' === $state && $this->successful_code( $outcome['code'] );

			return $this->finalize_outcome( $outcome, $remediation, $successful, $successful || 'failed' === $state );
		}
		if ( ! $record instanceof InstallationRecord ) {
			return $this->outcome( 'invalid_request' );
		}

		$outcome = match ( $operation ) {
			'check'       => $this->outcome( $this->record_check( $record, $credential_id, $state, $code, $observed, $target->endpoint(), $delivery, $configuration ) ),
			'reconfigure' => $this->record_reconfigure( $record, $target, $result, $credential_id, $state, $code, $observed, $delivery ),
			'remove'      => $this->outcome( $this->record_remove( $record, $result, $state, $code, $observed ) ),
			'test'        => $this->outcome( $this->record_test( $record, $credential_id, $state, $code, $observed, $delivery, $configuration ) ),
		};

		$successful = match ( $operation ) {
			'check' => 'succeeded' === $state && (
				( 'verified' === $outcome['code'] && 'verified' === $delivery )
				|| ( 'configured_pending_delivery' === $outcome['code'] && 'configured_pending_delivery' === $delivery )
			),
			'reconfigure' => 'succeeded' === $state && $this->successful_code( $outcome['code'] ),
			'remove' => $result->confirms_absence() && 'removed' === $outcome['code'],
			'test' => false,
		};
		$inline_safe = match ( $operation ) {
			'check', 'remove', 'test' => $successful || 'failed' === $state,
			'reconfigure' => $successful || ( 'failed' === $state && 'absent' !== $delivery ),
		};

		return $this->finalize_outcome( $outcome, $remediation, $successful, $inline_safe );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function record_setup( AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $credential_id, string $state, string $code, string $observed, string $delivery ): array {
		if ( 'failed' === $state ) {
			return $this->outcome( $code );
		}

		$profile = $this->result_profile( $result, $target->provider_code() );
		$hook_id = $result->hook_id();
		if ( null === $profile ) {
			return $this->outcome( 'operation_failed' );
		}
		if ( ! is_string( $hook_id ) || '' === trim( $hook_id ) ) {
			if ( ! in_array( $state, array( 'partial', 'ambiguous' ), true ) ) {
				return $this->outcome( 'operation_failed' );
			}
			$hook_id = InstallationRecord::unknown_hook_id();
		}

		$status = 'succeeded' === $state
			? ( 'verified' === $delivery ? 'configured' : 'needs_verification' )
			: 'orphaned';
		$record = new InstallationRecord(
			$target->provider_code(),
			$target->repository_id(),
			$target->repository(),
			$hook_id,
			$credential_id,
			$profile->id(),
			$profile->scope(),
			$profile->revision(),
			$profile->disposition(),
			$target->endpoint(),
			$status,
			$observed,
			$observed
		);

		$write = $this->records->save_if_current( $record, null );
		if ( InstallationStore::WRITE_CONFLICT === $write ) {
			return $this->outcome( 'record_conflict', $hook_id, $profile->id() );
		}
		if ( ! $this->write_succeeded( $write ) ) {
			$recovery_write = $this->records->save_if_current( $record->with_check( 'orphaned', $observed ), null );
			if ( ! $this->write_succeeded( $recovery_write ) ) {
				return $this->outcome(
					InstallationStore::WRITE_CONFLICT === $recovery_write ? 'record_conflict' : 'recovery_record_failed',
					$hook_id,
					$profile->id()
				);
			}

			return $this->outcome( 'orphaned' );
		}

		return $this->outcome(
			'succeeded' === $state
				? ( 'verified' === $delivery ? 'verified' : 'configured_pending_delivery' )
				: $code
		);
	}

	/** @param array<string, mixed> $configuration */
	private function record_check( InstallationRecord $record, string $management_credential_id, string $state, string $code, string $observed, string $endpoint, string $delivery, array $configuration ): string {
		if ( 'failed' === $state ) {
			return $code;
		}

		$status = match ( true ) {
			'partial' === $state || 'ambiguous' === $state => 'needs_verification',
			'succeeded' === $state && 'absent' === $delivery => 'remote_missing',
			'succeeded' === $state && in_array( 'mismatched', $configuration, true ) => 'configuration_drift',
			'succeeded' === $state && 'verified' === $delivery => 'configured',
			'profile_revision_stale' === $code => 'profile_revision_stale',
			'local_profile_missing' === $code => 'local_profile_missing',
			default => 'needs_verification',
		};
		$next        = 'succeeded' === $state
			? $record->with_management_credential( $management_credential_id, $status, $observed, $endpoint )
			: $record->with_check( $status, $observed );
		$result_code = match ( true ) {
			'succeeded' === $state && 'absent' === $delivery => 'remote_missing',
			'configuration_drift' === $status => 'configuration_drift',
			'succeeded' === $state && 'verified' === $delivery => 'verified',
			default => $code,
		};

		return $this->write_result_code( $this->records->save_if_current( $next, $record ), $result_code );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function record_reconfigure( InstallationRecord $record, AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $management_credential_id, string $state, string $code, string $observed, string $delivery ): array {
		if ( 'absent' === $delivery ) {
			return $this->outcome( $this->write_result_code( $this->records->save_if_current( $record->with_check( 'remote_missing', $observed ), $record ), 'remote_missing' ) );
		}
		if ( 'failed' === $state ) {
			return $this->outcome( $code );
		}
		if ( 'succeeded' !== $state ) {
			return $this->outcome( $this->write_result_code( $this->records->save_if_current( $record->with_check( 'needs_verification', $observed ), $record ), $code ) );
		}

		$profile = $this->result_profile( $result, $target->provider_code() );
		$hook_id = $result->hook_id();
		if ( null === $profile || ! is_string( $hook_id ) || ! hash_equals( $record->hook_id(), $hook_id ) ) {
			return $this->outcome( 'operation_failed' );
		}
		$next = $record->with_profile(
			$management_credential_id,
			$profile->id(),
			$profile->scope(),
			$profile->revision(),
			$profile->disposition(),
			$target->endpoint(),
			'verified' === $delivery ? 'configured' : 'needs_verification',
			$observed
		);

		$write = $this->records->save_if_current( $next, $record );

		return $this->outcome(
			$this->write_result_code( $write, 'verified' === $delivery ? 'verified' : 'configured_pending_delivery' ),
			$this->write_succeeded( $write ) ? null : $hook_id,
			$this->write_succeeded( $write ) ? null : $profile->id()
		);
	}

	private function record_remove( InstallationRecord $record, RepositoryWebhookOperationResult $result, string $state, string $code, string $observed ): string {
		if ( $result->confirms_absence() ) {
			return match ( $this->records->delete_if_current( $record->provider_code(), $record->repository_id(), $record ) ) {
				InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED => 'removed',
				InstallationStore::WRITE_CONFLICT => 'record_conflict',
				default => 'record_retained',
			};
		}
		if ( in_array( $state, array( 'partial', 'ambiguous' ), true ) ) {
			return $this->write_result_code( $this->records->save_if_current( $record->with_check( 'removal_pending', $observed ), $record ), $code );
		}

		return $code;
	}

	/** @param array<string, mixed> $configuration */
	private function record_test( InstallationRecord $record, string $management_credential_id, string $state, string $code, string $observed, string $delivery, array $configuration ): string {
		if ( 'absent' === $delivery ) {
			return $this->write_result_code(
				$this->records->save_if_current( $record->with_management_credential( $management_credential_id, 'remote_missing', $observed ), $record ),
				'remote_missing'
			);
		}
		if ( in_array( 'mismatched', $configuration, true ) ) {
			return $this->write_result_code(
				$this->records->save_if_current( $record->with_management_credential( $management_credential_id, 'configuration_drift', $observed ), $record ),
				'configuration_drift'
			);
		}
		if ( ! ( ( 'succeeded' === $state && in_array( $code, array( 'ping_requested', 'ping_verified' ), true ) ) || ( 'failed' === $state && 'ping_delivery_failed' === $code ) ) ) {
			return $code;
		}

		$status = 'needs_verification';
		$code   = 'ping_verified' === $code ? 'ping_requested' : $code;

		return $this->write_result_code(
			$this->records->save_if_current( $record->with_management_credential( $management_credential_id, $status, $observed ), $record ),
			$code
		);
	}

	private function result_profile( RepositoryWebhookOperationResult $result, string $provider_code ): ?WebhookProfileMetadata {
		$profile = $result->profile();

		return $profile instanceof WebhookProfileMetadata && hash_equals( $provider_code, $profile->provider_code() )
			? $profile
			: null;
	}

	private function write_succeeded( string $result ): bool {
		return in_array( $result, array( InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED ), true );
	}

	private function write_result_code( string $result, string $success_code ): string {
		return match ( $result ) {
			InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED => $success_code,
			InstallationStore::WRITE_CONFLICT => 'record_conflict',
			default => 'record_update_failed',
		};
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function outcome( string $code, ?string $hook_id = null, ?string $profile_id = null, bool $successful = false, bool $inline_safe = false ): array {
		return array(
			'code'        => $code,
			'recovery'    => null !== $hook_id && null !== $profile_id
				? array(
					'hook_id'    => $hook_id,
					'profile_id' => $profile_id,
				)
					: null,
			'remediation' => null,
			'successful'  => $successful,
			'inline_safe' => $inline_safe,
		);
	}

	/**
	 * @param array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} $outcome
	 * @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool}
	 */
	private function finalize_outcome( array $outcome, string $remediation, bool $successful, bool $inline_safe ): array {
		if ( ! $successful && $this->successful_code( $outcome['code'] ) ) {
			$outcome['code'] = 'operation_failed';
		}
		$outcome['remediation'] = $remediation;
		$outcome['successful']  = $successful;
		$outcome['inline_safe'] = $inline_safe;

		return $outcome;
	}

	private function successful_code( string $code ): bool {
		return in_array( $code, array( 'configured_pending_delivery', 'verified', 'removed' ), true );
	}

	private function safe_code( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}
}

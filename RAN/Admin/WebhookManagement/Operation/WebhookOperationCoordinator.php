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
		string $providerCode,
		string $repositoryId,
		?string $credentialId,
		?string $selectedProfileId,
		string $nonce
	): array {
		try {
			$target = $this->facade->target( $providerCode, $repositoryId );
		} catch ( \Throwable ) {
			$target = null;
		}
		if ( ! $target instanceof AssistanceTarget
			|| ! hash_equals( $providerCode, $target->providerCode() )
			|| ! hash_equals( $repositoryId, $target->repositoryId() ) ) {
			return $this->outcome( 'invalid_request', inlineSafe: true );
		}

		$record = $this->records->find( $providerCode, $repositoryId );
		if ( null !== $record && $record->requiresHookIdentification() ) {
			return $this->outcome( 'manual_recovery_required' );
		}
		if ( null === $credentialId
			|| ( 'setup' === $operation && null !== $record )
			|| ( 'setup' !== $operation && ( null === $record
				|| ! hash_equals( $target->repository(), $record->repository() ) ) ) ) {
			return $this->outcome( 'invalid_token', inlineSafe: true );
		}
		if ( 'setup' === $operation && null !== $selectedProfileId ) {
			$selected = false;
			try {
				foreach ( $this->facade->webhookProfileChoices( $providerCode, $repositoryId ) as $choice ) {
					if ( is_array( $choice ) && is_string( $choice['id'] ?? null ) && hash_equals( $selectedProfileId, $choice['id'] ) ) {
						$selected = true;
						break;
					}
				}
			} catch ( \Throwable ) {
				$selected = false;
			}
			if ( ! $selected ) {
				return $this->outcome( 'invalid_request', inlineSafe: true );
			}
		}

		try {
			$result = match ( $operation ) {
				'setup'       => $this->facade->setup( $target, $credentialId, $nonce, $selectedProfileId ),
				'check'       => $this->facade->check( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce ),
				'reconfigure' => $this->facade->reconfigure( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce ),
				'remove'      => $this->facade->remove( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce ),
				'test'        => $this->facade->test( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce ),
			};
		} catch ( \Throwable ) {
			return $this->outcome( 'operation_failed' );
		}
		if ( ! $result instanceof RepositoryWebhookOperationResult ) {
			return $this->outcome( 'operation_failed' );
		}

		return $this->applyResult( $operation, $target, $record, $credentialId, $result );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function applyResult( string $operation, AssistanceTarget $target, ?InstallationRecord $record, string $credentialId, RepositoryWebhookOperationResult $result ): array {
		$projection    = $result->toArray();
		$state         = $projection['state'] ?? null;
		$code          = $this->safeCode( $projection['code'] ?? null, 'operation_failed' );
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
			$outcome    = $this->recordSetup( $target, $result, $credentialId, $state, $code, $observed, $delivery );
			$successful = 'succeeded' === $state && $this->successfulCode( $outcome['code'] );

			return $this->finalizeOutcome( $outcome, $remediation, $successful, $successful || 'failed' === $state );
		}
		if ( ! $record instanceof InstallationRecord ) {
			return $this->outcome( 'invalid_request' );
		}

		$outcome = match ( $operation ) {
			'check'       => $this->outcome( $this->recordCheck( $record, $credentialId, $state, $code, $observed, $target->endpoint(), $delivery, $configuration ) ),
			'reconfigure' => $this->recordReconfigure( $record, $target, $result, $credentialId, $state, $code, $observed, $delivery ),
			'remove'      => $this->outcome( $this->recordRemove( $record, $result, $state, $code, $observed ) ),
			'test'        => $this->outcome( $this->recordTest( $record, $credentialId, $state, $code, $observed ) ),
		};

		$successful = match ( $operation ) {
			'check' => 'succeeded' === $state && (
				( 'verified' === $outcome['code'] && 'verified' === $delivery )
				|| ( 'configured_pending_delivery' === $outcome['code'] && 'configured_pending_delivery' === $delivery )
			),
			'reconfigure' => 'succeeded' === $state && $this->successfulCode( $outcome['code'] ),
			'remove' => $result->confirmsAbsence() && 'removed' === $outcome['code'],
			'test' => false,
		};
		$inlineSafe = match ( $operation ) {
			'check', 'remove', 'test' => $successful || 'failed' === $state,
			'reconfigure' => $successful || ( 'failed' === $state && 'absent' !== $delivery ),
		};

		return $this->finalizeOutcome( $outcome, $remediation, $successful, $inlineSafe );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function recordSetup( AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $credentialId, string $state, string $code, string $observed, string $delivery ): array {
		if ( 'failed' === $state ) {
			return $this->outcome( $code );
		}

		$profile = $this->resultProfile( $result, $target->providerCode() );
		$hookId  = $result->hookId();
		if ( null === $profile ) {
			return $this->outcome( 'operation_failed' );
		}
		if ( ! is_string( $hookId ) || '' === trim( $hookId ) ) {
			if ( ! in_array( $state, array( 'partial', 'ambiguous' ), true ) ) {
				return $this->outcome( 'operation_failed' );
			}
			$hookId = InstallationRecord::unknownHookId();
		}

		$status = 'succeeded' === $state
			? ( 'verified' === $delivery ? 'configured' : 'needs_verification' )
			: 'orphaned';
		$record = new InstallationRecord(
			$target->providerCode(),
			$target->repositoryId(),
			$target->repository(),
			$hookId,
			$credentialId,
			$profile->id(),
			$profile->scope(),
			$profile->revision(),
			$profile->disposition(),
			$target->endpoint(),
			$status,
			$observed,
			$observed
		);

		$write = $this->records->saveIfCurrent( $record, null );
		if ( InstallationStore::WRITE_CONFLICT === $write ) {
			return $this->outcome( 'record_conflict', $hookId, $profile->id() );
		}
		if ( ! $this->writeSucceeded( $write ) ) {
			$recoveryWrite = $this->records->saveIfCurrent( $record->withCheck( 'orphaned', $observed ), null );
			if ( ! $this->writeSucceeded( $recoveryWrite ) ) {
				return $this->outcome(
					InstallationStore::WRITE_CONFLICT === $recoveryWrite ? 'record_conflict' : 'recovery_record_failed',
					$hookId,
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
	private function recordCheck( InstallationRecord $record, string $managementCredentialId, string $state, string $code, string $observed, string $endpoint, string $delivery, array $configuration ): string {
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
		$next       = 'succeeded' === $state
			? $record->withManagementCredential( $managementCredentialId, $status, $observed, $endpoint )
			: $record->withCheck( $status, $observed );
		$resultCode = match ( true ) {
			'succeeded' === $state && 'absent' === $delivery => 'remote_missing',
			'configuration_drift' === $status => 'configuration_drift',
			'succeeded' === $state && 'verified' === $delivery => 'verified',
			default => $code,
		};

		return $this->writeResultCode( $this->records->saveIfCurrent( $next, $record ), $resultCode );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function recordReconfigure( InstallationRecord $record, AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $managementCredentialId, string $state, string $code, string $observed, string $delivery ): array {
		if ( 'absent' === $delivery ) {
			return $this->outcome( $this->writeResultCode( $this->records->saveIfCurrent( $record->withCheck( 'remote_missing', $observed ), $record ), 'remote_missing' ) );
		}
		if ( 'failed' === $state ) {
			return $this->outcome( $code );
		}
		if ( 'succeeded' !== $state ) {
			return $this->outcome( $this->writeResultCode( $this->records->saveIfCurrent( $record->withCheck( 'needs_verification', $observed ), $record ), $code ) );
		}

		$profile = $this->resultProfile( $result, $target->providerCode() );
		$hookId  = $result->hookId();
		if ( null === $profile || ! is_string( $hookId ) || ! hash_equals( $record->hookId(), $hookId ) ) {
			return $this->outcome( 'operation_failed' );
		}
		$next = $record->withProfile(
			$managementCredentialId,
			$profile->id(),
			$profile->scope(),
			$profile->revision(),
			$profile->disposition(),
			$target->endpoint(),
			'verified' === $delivery ? 'configured' : 'needs_verification',
			$observed
		);

		$write = $this->records->saveIfCurrent( $next, $record );

		return $this->outcome(
			$this->writeResultCode( $write, 'verified' === $delivery ? 'verified' : 'configured_pending_delivery' ),
			$this->writeSucceeded( $write ) ? null : $hookId,
			$this->writeSucceeded( $write ) ? null : $profile->id()
		);
	}

	private function recordRemove( InstallationRecord $record, RepositoryWebhookOperationResult $result, string $state, string $code, string $observed ): string {
		if ( $result->confirmsAbsence() ) {
			return match ( $this->records->deleteIfCurrent( $record->providerCode(), $record->repositoryId(), $record ) ) {
				InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED => 'removed',
				InstallationStore::WRITE_CONFLICT => 'record_conflict',
				default => 'record_retained',
			};
		}
		if ( in_array( $state, array( 'partial', 'ambiguous' ), true ) ) {
			return $this->writeResultCode( $this->records->saveIfCurrent( $record->withCheck( 'removal_pending', $observed ), $record ), $code );
		}

		return $code;
	}

	private function recordTest( InstallationRecord $record, string $managementCredentialId, string $state, string $code, string $observed ): string {
		if ( ! ( ( 'succeeded' === $state && in_array( $code, array( 'ping_requested', 'ping_verified' ), true ) ) || ( 'failed' === $state && 'ping_delivery_failed' === $code ) ) ) {
			return $code;
		}

		$status = 'needs_verification';
		$code   = 'ping_verified' === $code ? 'ping_requested' : $code;

		return $this->writeResultCode(
			$this->records->saveIfCurrent( $record->withManagementCredential( $managementCredentialId, $status, $observed ), $record ),
			$code
		);
	}

	private function resultProfile( RepositoryWebhookOperationResult $result, string $providerCode ): ?WebhookProfileMetadata {
		$profile = $result->profile();

		return $profile instanceof WebhookProfileMetadata && hash_equals( $providerCode, $profile->providerCode() )
			? $profile
			: null;
	}

	private function writeSucceeded( string $result ): bool {
		return in_array( $result, array( InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED ), true );
	}

	private function writeResultCode( string $result, string $successCode ): string {
		return match ( $result ) {
			InstallationStore::WRITE_APPLIED, InstallationStore::WRITE_UNCHANGED => $successCode,
			InstallationStore::WRITE_CONFLICT => 'record_conflict',
			default => 'record_update_failed',
		};
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} */
	private function outcome( string $code, ?string $hookId = null, ?string $profileId = null, bool $successful = false, bool $inlineSafe = false ): array {
		return array(
			'code'        => $code,
			'recovery'    => null !== $hookId && null !== $profileId
				? array(
					'hook_id'    => $hookId,
					'profile_id' => $profileId,
				)
					: null,
			'remediation' => null,
			'successful'  => $successful,
			'inline_safe' => $inlineSafe,
		);
	}

	/**
	 * @param array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool} $outcome
	 * @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string,successful:bool,inline_safe:bool}
	 */
	private function finalizeOutcome( array $outcome, string $remediation, bool $successful, bool $inlineSafe ): array {
		if ( ! $successful && $this->successfulCode( $outcome['code'] ) ) {
			$outcome['code'] = 'operation_failed';
		}
		$outcome['remediation'] = $remediation;
		$outcome['successful']  = $successful;
		$outcome['inline_safe'] = $inlineSafe;

		return $outcome;
	}

	private function successfulCode( string $code ): bool {
		return in_array( $code, array( 'configured_pending_delivery', 'verified', 'removed' ), true );
	}

	private function safeCode( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}
}

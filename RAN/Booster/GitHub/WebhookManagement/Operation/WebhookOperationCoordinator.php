<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub\WebhookManagement\Operation;

use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationRecord;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationStore;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

/** Owns one authorized webhook operation and its local recovery transition. */
final class WebhookOperationCoordinator {
	public function __construct(
		private readonly WebhookAssistanceFacade $facade,
		private readonly InstallationStore $records
	) {
	}

	/**
	 * @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null}
	 */
	public function execute(
		string $operation,
		string $providerCode,
		string $repositoryId,
		?string $credentialId,
		#[\SensitiveParameter] ?string $requestCredential,
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
			return $this->outcome( 'invalid_request' );
		}

		$record = $this->records->find( $providerCode, $repositoryId );
		if ( null !== $record && $record->requiresHookIdentification() ) {
			return $this->outcome( 'manual_recovery_required' );
		}
		if ( ( null === $credentialId ) === ( null === $requestCredential )
			|| ( 'setup' === $operation && null !== $record )
			|| ( 'setup' !== $operation && ( null === $record
				|| ! hash_equals( $target->repository(), $record->repository() ) ) ) ) {
			return $this->outcome( 'invalid_token' );
		}

		try {
			$result = match ( $operation ) {
				'setup'       => $this->facade->setup( $target, $credentialId, $nonce, $requestCredential ),
				'check'       => $this->facade->check( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce, $requestCredential ),
				'reconfigure' => $this->facade->reconfigure( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce, $requestCredential ),
				'remove'      => $this->facade->remove( $target, $credentialId, $record->hookId(), $record->webhookProfileId(), $record->webhookProfileRevision(), $nonce, $requestCredential ),
			};
		} catch ( \Throwable ) {
			return $this->outcome( 'operation_failed' );
		}
		if ( ! $result instanceof RepositoryWebhookOperationResult ) {
			return $this->outcome( 'operation_failed' );
		}

		return $this->applyResult( $operation, $target, $record, $result );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null} */
	private function applyResult( string $operation, AssistanceTarget $target, ?InstallationRecord $record, RepositoryWebhookOperationResult $result ): array {
		$projection    = $result->toArray();
		$state         = $projection['state'] ?? null;
		$code          = $this->safeCode( $projection['code'] ?? null, 'operation_failed' );
		$observed      = $projection['observed_at'] ?? null;
		$delivery      = $projection['delivery'] ?? null;
		$configuration = $projection['configuration'] ?? null;
		if ( ! in_array( $state, array( 'succeeded', 'partial', 'ambiguous', 'failed' ), true )
			|| ! is_string( $observed )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $observed )
			|| ! in_array( $delivery, array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ), true )
			|| ! is_array( $configuration ) ) {
			return $this->outcome( 'operation_failed' );
		}

		if ( 'setup' === $operation ) {
			return $this->recordSetup( $target, $result, $state, $code, $observed, $delivery );
		}
		if ( ! $record instanceof InstallationRecord ) {
			return $this->outcome( 'invalid_request' );
		}

		return match ( $operation ) {
			'check'       => $this->outcome( $this->recordCheck( $record, $state, $code, $observed, $target->endpoint(), $delivery, $configuration ) ),
			'reconfigure' => $this->recordReconfigure( $record, $target, $result, $state, $code, $observed, $delivery ),
			'remove'      => $this->outcome( $this->recordRemove( $record, $result, $state, $code, $observed ) ),
		};
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null} */
	private function recordSetup( AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $state, string $code, string $observed, string $delivery ): array {
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
	private function recordCheck( InstallationRecord $record, string $state, string $code, string $observed, string $endpoint, string $delivery, array $configuration ): string {
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
		$next       = $record->withCheck( $status, $observed, 'succeeded' === $state ? $endpoint : null );
		$resultCode = match ( true ) {
			'succeeded' === $state && 'absent' === $delivery => 'remote_missing',
			'configuration_drift' === $status => 'configuration_drift',
			'succeeded' === $state && 'verified' === $delivery => 'verified',
			default => $code,
		};

		return $this->writeResultCode( $this->records->saveIfCurrent( $next, $record ), $resultCode );
	}

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null} */
	private function recordReconfigure( InstallationRecord $record, AssistanceTarget $target, RepositoryWebhookOperationResult $result, string $state, string $code, string $observed, string $delivery ): array {
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

	/** @return array{code:string,recovery:array{hook_id:string,profile_id:string}|null} */
	private function outcome( string $code, ?string $hookId = null, ?string $profileId = null ): array {
		return array(
			'code'     => $code,
			'recovery' => null !== $hookId && null !== $profileId
				? array(
					'hook_id'    => $hookId,
					'profile_id' => $profileId,
				)
				: null,
		);
	}

	private function safeCode( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}
}

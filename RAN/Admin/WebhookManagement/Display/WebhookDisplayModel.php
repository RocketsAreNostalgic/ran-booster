<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;

/** Builds complete display-safe models without rendering or request access. */
final class WebhookDisplayModel {
	/** @var array<string, string> */
	private array $projectedStatuses = array();

	public function __construct(
		private readonly WebhookAssistanceFacade $facade,
		private readonly InstallationStore $records
	) {
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRows( array $rows, string $providerCode, string $providerLabel, string $repositoryUrlBase, array $repositoryProjections, string $returnUrl ): array {
		$this->projectedStatuses = array();
		$readiness               = $this->readiness( $providerCode );
		if ( null === $readiness ) {
			return $rows;
		}

		$records           = array_filter(
			$this->records->all(),
			static fn ( InstallationRecord $record ): bool => hash_equals( $providerCode, $record->providerCode() )
		);
		$currentRecordKeys = array();

		foreach ( $repositoryProjections as $rowKey => $projection ) {
			$repositoryId = $this->projectionRepositoryId( $rowKey, $projection );
			if ( null === $repositoryId || ! isset( $rows[ $rowKey ], $readiness['repositories'][ $repositoryId ] ) ) {
				continue;
			}

			$recordKey                       = InstallationRecord::key( $providerCode, $repositoryId );
			$currentRecordKeys[ $recordKey ] = true;
			$record                          = $records[ $recordKey ] ?? null;
			$statusCode                      = $this->statusCode( $record, $readiness['callback_url'] );
			$existingDetails                 = is_array( $rows[ $rowKey ]['details'] ?? null ) ? $rows[ $rowKey ]['details'] : array();
			$rows[ $rowKey ]['details']      = array_merge( $existingDetails, $this->historyDetails( $statusCode, $record ) );

			$actions = is_array( $rows[ $rowKey ]['actions'] ?? null ) ? $rows[ $rowKey ]['actions'] : array();
			if ( isset( $actions['core:webhook-management'] ) && is_array( $actions['core:webhook-management'] ) ) {
				if ( $readiness['repositories'][ $repositoryId ]['eligible'] ) {
					$actions['core:webhook-management']['url']          = $this->panelUrl( $returnUrl, $repositoryId );
					$actions['core:webhook-management']['disabled']     = false;
					$actions['core:webhook-management']['described_by'] = '';
				}
				$rows[ $rowKey ]['actions'] = $actions;
			}
		}

		foreach ( $rows as $rowKey => &$row ) {
			if ( isset( $repositoryProjections[ $rowKey ] ) || 'release_asset' !== ( $row['source_key'] ?? null ) ) {
				continue;
			}
			$repositoryId = is_string( $row['repository_id'] ?? null ) ? trim( $row['repository_id'] ) : '';
			$recordKey    = InstallationRecord::key( $providerCode, $repositoryId );
			$record       = '' === $repositoryId ? null : ( $records[ $recordKey ] ?? null );
			if ( null === $record ) {
				continue;
			}
			$currentRecordKeys[ $recordKey ] = true;
			$existingDetails                 = is_array( $row['details'] ?? null ) ? $row['details'] : array();
			$row['details']                  = array_merge( $existingDetails, $this->historyDetails( $this->projectedStatus( $record, true ), $record ) );
		}
		unset( $row );

		foreach ( $records as $recordKey => $record ) {
			if ( ! isset( $currentRecordKeys[ $recordKey ] ) ) {
				$syntheticKey          = 'ran-booster-repository-webhook-management:historical:' . substr( hash( 'sha256', $recordKey ), 0, 16 );
				$rows[ $syntheticKey ] = $this->retainedRecordRow( $syntheticKey, $record, $providerLabel, $repositoryUrlBase );
			}
		}

		return $rows;
	}

	/** @param array<string,array<string,mixed>> $rows @param array<string,array<string,mixed>> $repositoryProjections @return array<string,array<string,mixed>> */
	public function enrichHistoricalRows( array $rows, string $providerCode, array $repositoryProjections ): array {
		foreach ( $repositoryProjections as $rowKey => $projection ) {
			$repositoryId = $this->projectionRepositoryId( $rowKey, $projection );
			$record = null === $repositoryId ? null : $this->records->find( $providerCode, $repositoryId );
			if ( null !== $record && isset( $rows[ $rowKey ] ) ) {
				$existing = is_array( $rows[ $rowKey ]['details'] ?? null ) ? $rows[ $rowKey ]['details'] : array();
				$rows[ $rowKey ]['details'] = array_merge( $existing, $this->historicalDetails( $record ) );
			}
		}

		return $rows;
	}

	/**
	 * @param array{hook_id:string,profile_id:string}|null $recovery
	 * @return array<string, mixed>|null
	 */
	public function panel( string $providerCode, string $providerLabel, string $repositoryId, string $returnUrl, ?string $resultCode, ?array $recovery, bool $canManage, ?string $remediation = null ): ?array {
		if ( ! $canManage || '' === trim( $repositoryId ) ) {
			return null;
		}

		try {
			$target = $this->facade->target( $providerCode, $repositoryId );
		} catch ( \Throwable ) {
			return null;
		}
		if ( null === $target || ! hash_equals( $repositoryId, $target->repositoryId() ) ) {
			return null;
		}

		$this->projectedStatuses = array();
		$record                  = $this->records->find( $providerCode, $repositoryId );
		$status                  = null === $record ? null : $this->projectedStatus( $record );
		$operations              = null === $recovery ? $this->availableOperations( $target, $record, $status, $providerLabel ) : array();
		$operationModels         = array();
		foreach ( $operations as $operation => $label ) {
			$operationModels[] = array(
				'key'     => $operation,
				'label'   => $label,
				'url'     => $this->operationUrl( $operation, $providerCode, $repositoryId ),
				'primary' => array_key_first( $operations ) === $operation,
			);
		}

		$help = null;
		if ( null !== $record && 'local_profile_missing' === $status ) {
			/* translators: %s: repository provider name. */
			$help = sprintf( __( 'The recorded secret is no longer available. Update the %s webhook to use the current applicable secret; Booster creates a repository secret when none applies.', 'ran-booster' ), $providerLabel );
		} elseif ( null !== $record && 'remote_missing' === $status ) {
			/* translators: %s: repository provider name. */
			$help = sprintf( __( 'Managed removal is unavailable because the recorded %1$s hook cannot be confirmed. Inspect %1$s manually before continuing.', 'ran-booster' ), $providerLabel );
		}
		$recoveryWarning = null;
		if ( null !== $record && $record->requiresHookIdentification() ) {
			/* translators: %s: repository provider name. */
			$recoveryWarning = sprintf( __( 'Provider state changed without a stable hook ID. Managed operations are disabled for this repository. Inspect its %s webhooks and the recorded Core signing profile manually; do not retry Setup until both sides are reconciled.', 'ran-booster' ), $providerLabel );
		} elseif ( null !== $recovery ) {
			/* translators: %s: repository provider name. */
			$recoveryWarning = sprintf( __( 'Repository webhook management could not persist the returned recovery references. Setup is disabled on this recovery view. Inspect %s and Core manually before leaving or retrying.', 'ran-booster' ), $providerLabel );
		}

		return array(
			'form_action'         => admin_url( 'admin-post.php' ),
			'admin_action'        => 'ran_booster_repository_webhook_management_operation',
			'provider_code'       => $providerCode,
			'provider_label'      => $providerLabel,
			'repository_id'       => $repositoryId,
			'repository'          => $target->repository(),
			'interaction_request' => AdminInteractionRequest::providerRepositories( 'repository-webhook-management:manage-webhook', $this->panelUrl( $returnUrl, $repositoryId ), 'repository-webhook-management-error' ),
			'result'              => null === $resultCode ? null : array(
				'class'   => $this->isSuccessfulResult( $resultCode ) ? 'notice-success' : 'notice-error',
				'message' => $this->notice( $resultCode, $recovery, $remediation ),
			),
			'recovery_warning'    => $recoveryWarning,
			'credential_choices'  => $this->credentialChoices( $providerCode ),
			'operations'          => $operationModels,
			'action_help'         => $help,
		);
	}

	/** @return list<array{heading:?string,body:string}> */
	public function documentation( string $providerLabel ): array {
		/* translators: %s: repository provider name. */
		$intro = sprintf( __( 'Booster can set up, check, reconfigure and remove one %s webhook per managed repository. Manual webhook setup remains available.', 'ran-booster' ), $providerLabel );
		/* translators: %s: repository provider name. */
		$credentialHeading = sprintf( __( 'Saved profile or request-only %s access', 'ran-booster' ), $providerLabel );
		/* translators: %s: repository provider name. */
		$readiness = sprintf( __( 'Current readiness verifies Booster storage, a public HTTPS callback and stable repository identity without contacting %s. Timestamped hook status is historical until an administrator runs Check.', 'ran-booster' ), $providerLabel );
		/* translators: %s: repository provider name. */
		$lifecycle = sprintf( __( 'Webhook management never enables Automatic deployment. Blueprint import, plugin deactivation and plugin deletion do not contact %s or remove remote hooks.', 'ran-booster' ), $providerLabel );
		/* translators: %s: repository provider name. */
		$cleanup = sprintf( __( 'Switching a package to Published releases does not remove the remote hook, its local recovery record or Core signing material. Remove the identified hook in %s first, then remove only unused local signing material in Core.', 'ran-booster' ), $providerLabel );

		return array(
			array(
				'heading' => null,
				'body'    => $intro,
			),
			array(
				'heading' => $credentialHeading,
				'body'    => __( 'Select an eligible saved Booster credential or provide fresh request-only access for the selected repository. A saved credential is resolved inside the fixed provider operation; a pasted credential is submitted for this operation only. Neither value is persisted or logged by webhook management.', 'ran-booster' ),
			),
			array(
				'heading' => __( 'Readiness and recorded status', 'ran-booster' ),
				'body'    => $readiness,
			),
			array(
				'heading' => __( 'Deployment and lifecycle boundaries', 'ran-booster' ),
				'body'    => $lifecycle,
			),
			array(
				'heading' => __( 'Cleanup after switching package source', 'ran-booster' ),
				'body'    => $cleanup,
			),
		);
	}

	public function notice( string $code, ?array $recovery = null, ?string $remediation = null ): string {
		if ( 'orphaned' === $code ) {
			return 'The remote hook may be active without a complete local record. Inspect it manually at the provider before retrying.';
		}
		if ( in_array( $code, array( 'recovery_record_failed', 'record_conflict', 'record_update_failed' ), true ) ) {
			return null === $recovery
				? ( 'record_conflict' === $code
						? 'A newer webhook-management record won the persistence race. Nothing was overwritten; inspect the current provider and Core state before retrying.'
						: 'Provider state may have changed, but webhook management could not save its non-secret recovery record. Inspect the provider and Core before retrying.' )
					: sprintf( 'Provider state may have changed, but the current webhook-management record was not overwritten. Inspect provider hook reference %1$s and Core signing profile %2$s before retrying.', $recovery['hook_id'], $recovery['profile_id'] );
		}
		if ( 'manual_recovery_required' === $code ) {
			return 'Managed operations are disabled because the prior setup did not return a stable hook ID. Inspect the provider and Core manually before retrying.';
		}

		return match ( $code ) {
			'configured_pending_delivery' => 'Webhook management configured the remote hook. Signed delivery verification is still pending.',
			'verified' => 'Webhook management confirmed the recorded remote configuration. Correlate provider delivery history with the Provider request ID in Booster Activity before treating signed delivery as established.',
			'removed' => 'Webhook management confirmed the remote hook is absent and cleared its local recovery record.',
			'forbidden' => 'You are not permitted to manage this repository webhook. Nothing was changed.',
			'invalid_request' => 'The webhook request was invalid or expired. Nothing was changed; reload this repository and try again.',
			'invalid_token' => 'Select one saved credential or provide one request-only credential, then try again.',
			'operation_unauthorized' => 'Core could not authorize this repository webhook operation. Nothing was changed.',
			'repository_identity_unconfirmed' => 'Core could not confirm the selected repository identity. Nothing was changed.',
			'operation_busy' => 'Another webhook operation is already in progress for this repository. Wait for it to finish, then check the recorded state.',
			'operation_failed' => 'Webhook management could not confirm the operation outcome. Inspect the provider and recorded status before retrying.',
			'setup_failed' => 'The provider rejected the webhook setup request. No remote hook was established.',
			'setup_compensated' => 'Webhook management could not verify the new remote hook, so it removed it. No webhook was established; setup may be tried again.',
			'setup_compensation_incomplete' => 'Webhook management could not verify or safely remove the new remote hook. Inspect the provider and Core records before retrying.',
			'setup_outcome_unknown' => 'Webhook management could not confirm whether setup changed the remote hook. Inspect the provider and Core records before retrying.',
			'hook_inventory_unavailable', 'hook_inventory_invalid', 'hook_inventory_incomplete', 'matching_hooks_ambiguous' => 'Webhook management could not establish the current remote hook state. Nothing should be treated as successful; inspect the provider before retrying.',
			'setup_response_invalid' => 'The provider response did not identify the new hook. Inspect the provider and Core records before retrying.',
			'preconfiguration_read_unavailable', 'reconfigure_readback_unavailable', 'reconfigure_outcome_unknown' => 'Webhook management could not confirm the remote hook state after the update request. Run Check or inspect the hook at the provider before retrying an update.',
			'reconfigure_failed' => 'The provider rejected the webhook update request. Run Check or inspect the hook at the provider before retrying.',
			'hook_ownership_unavailable' => 'Webhook management could not confirm that the recorded hook belongs to this site. Run Check or inspect the hook at the provider before retrying.',
			'predelete_read_unavailable', 'remove_readback_unavailable', 'remove_outcome_unknown' => 'Webhook management could not confirm whether the remote hook was removed. Run Check or inspect the hook at the provider before retrying removal.',
			'remove_failed' => 'The provider rejected the webhook removal request. Run Check or inspect the hook at the provider before retrying.',
			'operation_lock_release_failed' => 'The webhook operation completed, but Core could not release its coordination lock. Wait for the current request to end, then run Check before retrying.',
			'assessment_insufficient' => 'Core confirmed that the selected credential is insufficient for this repository webhook operation. Nothing was changed.',
			'assessment_stale' => 'The credential fitness assessment is stale. Nothing was changed; assess again with current repository authority.',
			'assessment_unsupported' => 'The bound provider does not support this fixed webhook operation. Nothing was changed.',
			'assessment_unavailable' => 'Core could not establish safe credential fitness for this operation. Nothing was changed.',
			default => null !== $remediation && strlen( $remediation ) <= 255
				? $remediation
				: 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.',
		};
	}

	public function isSuccessfulResult( string $code ): bool {
		return in_array( $code, array( 'configured_pending_delivery', 'verified', 'removed' ), true );
	}

	public function canRespondInlineToFailure( string $code ): bool {
		return in_array( $code, array( 'forbidden', 'invalid_request', 'invalid_token', 'operation_unauthorized', 'repository_identity_unconfirmed', 'operation_busy', 'setup_failed', 'setup_compensated', 'assessment_insufficient', 'assessment_stale', 'assessment_unsupported', 'assessment_unavailable' ), true );
	}

	/** @return array{callback_url:string,repositories:array<string,array{eligible:bool}>}|null */
	private function readiness( string $providerCode ): ?array {
		try {
			$projection = $this->facade->readiness( $providerCode )->toArray();
		} catch ( \Throwable ) {
			return null;
		}
		if ( array_keys( $projection ) !== array( 'site', 'repositories' )
			|| ! is_array( $projection['site'] )
			|| array_keys( $projection['site'] ) !== array( 'status', 'reason_codes', 'callback_url' )
			|| ! in_array( $projection['site']['status'], array( 'ready', 'blocked' ), true )
			|| ! is_array( $projection['site']['reason_codes'] )
			|| ! array_is_list( $projection['site']['reason_codes'] )
			|| ! is_string( $projection['site']['callback_url'] ?? null )
			|| '' === trim( $projection['site']['callback_url'] )
			|| strlen( $projection['site']['callback_url'] ) > 2048
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $projection['site']['callback_url'] )
			|| ! is_array( $projection['repositories'] )
			|| ! array_is_list( $projection['repositories'] ) ) {
			return null;
		}

		$repositories = array();
		foreach ( $projection['repositories'] as $repository ) {
			if ( ! is_array( $repository )
				|| $providerCode !== ( $repository['provider_code'] ?? null )
				|| ( null !== ( $repository['repository_id'] ?? null ) && ! is_string( $repository['repository_id'] ) )
				|| ! is_bool( $repository['eligible'] ?? null )
				|| ( 'blocked' === $projection['site']['status'] && $repository['eligible'] ) ) {
				return null;
			}
			if ( is_string( $repository['repository_id'] )
				&& '' !== trim( $repository['repository_id'] )
				&& strlen( $repository['repository_id'] ) <= 191
				&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $repository['repository_id'] ) ) {
				$repositories[ $repository['repository_id'] ] = array( 'eligible' => $repository['eligible'] );
			} elseif ( null !== $repository['repository_id'] ) {
				return null;
			}
		}

		return array(
			'callback_url' => $projection['site']['callback_url'],
			'repositories' => $repositories,
		);
	}

	/** @return list<array{id:string,label:string}> */
	private function credentialChoices( string $providerCode ): array {
		try {
			$choices = $this->facade->credentialChoices( $providerCode );
		} catch ( \Throwable ) {
			return array();
		}
		$normalized = array();
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! is_string( $choice['id'] ?? null ) || ! is_string( $choice['label'] ?? null ) || ! is_string( $choice['kind'] ?? null ) || ( null !== ( $choice['destroy_on'] ?? null ) && ! is_string( $choice['destroy_on'] ) ) ) {
				continue;
			}
			$label = $choice['label'] . ' (' . $choice['kind'] . ')';
			if ( is_string( $choice['destroy_on'] ) ) {
				/* translators: %s: credential removal date. */
				$label .= ' · ' . sprintf( __( 'removes after %s', 'ran-booster' ), $choice['destroy_on'] );
			}
			$normalized[] = array(
				'id'    => $choice['id'],
				'label' => $label,
			);
		}

		return $normalized;
	}

	/** @param array<string, mixed> $projection */
	private function projectionRepositoryId( string|int $rowKey, array $projection ): ?string {
		$repositoryId = $projection['repository_id'] ?? null;

		return is_string( $repositoryId ) && '' !== trim( $repositoryId )
			? $repositoryId
			: ( is_string( $rowKey ) && '' !== trim( $rowKey ) ? $rowKey : null );
	}

	private function statusCode( ?InstallationRecord $record, string $callbackUrl ): string {
		$status = null === $record ? 'not_configured' : $this->projectedStatus( $record );
		if ( null !== $record && ! in_array( $status, array( 'local_profile_missing', 'profile_revision_stale', 'needs_verification' ), true ) && ! hash_equals( $record->endpoint(), $callbackUrl ) ) {
			return 'configuration_drift';
		}

		return $status;
	}

	/** @return array<string, mixed> */
	private function retainedRecordRow( string $key, InstallationRecord $record, string $providerLabel, string $repositoryUrlBase ): array {
		$actions       = array();
		$parts         = explode( '/', $record->repository() );
		$repositoryUrl = 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1]
			? rtrim( $repositoryUrlBase, '/' ) . '/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] )
			: null;
		if ( null !== $repositoryUrl ) {
			$actions['ran-booster-repository-webhook-management:inspect'] = array(
				'key'           => 'ran-booster-repository-webhook-management:inspect',
				/* translators: %s: repository provider name. */
				'label'         => sprintf( __( 'Open %s repository', 'ran-booster' ), $providerLabel ),
				'type'          => 'link',
				'url'           => $repositoryUrl,
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => true,
				'described_by'  => '',
				'screen_reader' => $record->repository(),
			);
		}

		return array(
			'key'             => $key,
			'provider_code'   => $record->providerCode(),
			'provider_label'  => $providerLabel,
			'repository_id'   => $record->repositoryId(),
			'repository'      => $record->repository(),
			'repository_url'  => $repositoryUrl ?? '',
			'historical'      => true,
			'types'           => array(
				array(
					'label' => __( 'Package unavailable', 'ran-booster' ),
					'tone'  => 'neutral',
				),
			),
			'package_message' => __( 'Current managed-package details are unavailable.', 'ran-booster' ),
			'statuses'        => array(
				array(
					'label' => __( 'No longer managed', 'ran-booster' ),
					'tone'  => 'warning',
				),
			),
			'status_message'  => __( 'This retained record no longer matches a managed repository in Booster.', 'ran-booster' ),
			'action_message'  => __( 'Managed actions are unavailable. Inspect and remove the recorded hook manually if necessary.', 'ran-booster' ),
			'actions'         => $actions,
			'details'         => $this->historyDetails( $this->projectedStatus( $record ), $record ),
		);
	}

	/** @return list<array<string, string>> */
	private function historyDetails( string $statusCode, ?InstallationRecord $record ): array {
		$history = null === $record ? null : WebhookHistory::fromRecord( $record )->toArray();
		$details = array(
			array(
				'label' => __( 'Recorded hook status', 'ran-booster' ),
				'value' => null === $history ? __( 'Managed hook not yet set', 'ran-booster' ) : $this->historicalStatusLabel( $history['recorded_status'] ),
				'tone'  => null === $history ? 'warning' : $this->historicalStatusTone( $history['recorded_status'] ),
			),
			array(
				'label' => __( 'Observation', 'ran-booster' ),
				'value' => null === $history ? __( 'No historical observation', 'ran-booster' ) : __( 'Historical only; not live readiness or a signed delivery', 'ran-booster' ),
				'tone'  => 'neutral',
			),
		);

		if ( null !== $history && $statusCode !== $history['recorded_status'] ) {
			$details[] = array(
				'label' => __( 'Current local warning', 'ran-booster' ),
				'value' => $this->historicalStatusLabel( $statusCode ),
				'tone'  => $this->historicalStatusTone( $statusCode ),
			);
		}

		return array_merge( $details, array(
			array(
				'label' => __( 'Recorded hook profile', 'ran-booster' ),
				'value' => null === $record ? __( 'Managed hook not yet set', 'ran-booster' ) : $this->recordedProfileLabel( $statusCode, $record ),
			),
			array(
				'label'    => __( 'Last checked', 'ran-booster' ),
				'value'    => null === $history ? __( 'Never', 'ran-booster' ) : $history['checked_at'],
				'datetime' => null === $history ? '' : $history['checked_at'],
			),
		) );
	}

	/** @return list<array<string, string>> */
	private function historicalDetails( InstallationRecord $record ): array {
		$history = WebhookHistory::fromRecord( $record )->toArray();

		return array(
			array( 'label' => __( 'Recorded hook status', 'ran-booster' ), 'value' => $this->historicalStatusLabel( $history['recorded_status'] ), 'tone' => $this->historicalStatusTone( $history['recorded_status'] ) ),
			array( 'label' => __( 'Observation', 'ran-booster' ), 'value' => __( 'Historical only; not live readiness or a signed delivery', 'ran-booster' ), 'tone' => 'neutral' ),
			array( 'label' => __( 'Last checked', 'ran-booster' ), 'value' => $history['checked_at'], 'datetime' => $history['checked_at'] ),
		);
	}

	/** @return array<string, string> */
	private function availableOperations( AssistanceTarget $target, ?InstallationRecord $record, ?string $status, string $providerLabel ): array {
		if ( null === $record ) {
			/* translators: %s: repository provider name. */
			return array( 'setup' => sprintf( __( 'Set up in %s', 'ran-booster' ), $providerLabel ) );
		}
		if ( $record->requiresHookIdentification() ) {
			return array();
		}
		$operations = array();
		if ( in_array( $status, array( 'profile_revision_stale', 'configuration_drift', 'local_profile_missing' ), true ) || ( 'needs_verification' !== $status && ! hash_equals( $record->endpoint(), $target->endpoint() ) ) ) {
			/* translators: %s: repository provider name. */
			$operations['reconfigure'] = sprintf( __( 'Update %s webhook', 'ran-booster' ), $providerLabel );
		}
		/* translators: %s: repository provider name. */
		$operations['check'] = sprintf( __( 'Check %s', 'ran-booster' ), $providerLabel );
		if ( ! in_array( $status, array( 'local_profile_missing', 'remote_missing', 'removal_pending' ), true ) ) {
			/* translators: %s: repository provider name. */
			$operations['remove'] = sprintf( __( 'Remove from %s', 'ran-booster' ), $providerLabel );
		}

		return $operations;
	}

	private function panelUrl( string $returnUrl, string $repositoryId ): string {
		$url = 1 === preg_match( '/[?&]repository=/', $returnUrl ) ? $returnUrl : $returnUrl . ( str_contains( $returnUrl, '?' ) ? '&' : '?' ) . 'repository=' . rawurlencode( $repositoryId );

		return $url . '#ran-booster-repository-webhook-management-operation-heading';
	}

	private function operationUrl( string $operation, string $providerCode, string $repositoryId ): string {
		$action = 'ran_booster_repository_webhook_' . implode( '_', array( $operation, $providerCode, $repositoryId ) );

		return admin_url( 'admin-post.php?action=ran_booster_repository_webhook_management_operation&_wpnonce=' . rawurlencode( wp_create_nonce( $action ) ) );
	}

	private function historicalStatusLabel( string $status ): string {
		return match ( $status ) {
			'not_configured' => __( 'No managed hook recorded', 'ran-booster' ), 'configured' => __( 'Configured at last check', 'ran-booster' ), 'profile_revision_stale' => __( 'Signing secret changed; webhook update required', 'ran-booster' ), 'local_profile_missing' => __( 'Secret needs attention', 'ran-booster' ),
			default => sprintf( __( 'Needs attention: %s at last check', 'ran-booster' ), 'configuration_drift' === $status ? __( 'Configuration drift', 'ran-booster' ) : ucwords( str_replace( '_', ' ', $status ) ) ),
		};
	}

	private function historicalStatusTone( string $status ): string {
		return match ( $status ) { 'configured' => 'ok', 'not_configured' => 'warning', 'orphaned', 'removal_pending' => 'error', default => 'warning' };
	}

	private function recordedProfileLabel( string $status, InstallationRecord $record ): string {
		if ( 'local_profile_missing' === $status ) {
			return __( 'Secret needs attention', 'ran-booster' );
		}
		$scope  = 'owner' === $record->webhookProfileScope() ? __( 'Owner-shared secret', 'ran-booster' ) : __( 'Repository secret', 'ran-booster' );
		$source = 'created' === $record->webhookProfileDisposition() ? __( 'created for this hook', 'ran-booster' ) : __( 'reused from Booster', 'ran-booster' );

		/* translators: 1: signing secret scope, 2: signing secret source. */
		return sprintf( __( '%1$s; %2$s', 'ran-booster' ), $scope, $source );
	}

	private function projectedStatus( InstallationRecord $record, bool $retainedSource = false ): string {
		$key = implode( ':', array( $record->providerCode(), $record->repositoryId(), $record->webhookProfileId(), (string) $record->webhookProfileRevision() ) );
		if ( isset( $this->projectedStatuses[ $key ] ) ) {
			return $this->projectedStatuses[ $key ];
		}
		try {
			$profile = $this->facade->profile( $record->providerCode(), $record->repositoryId(), $record->webhookProfileId() );
		} catch ( \Throwable ) {
			$profile = null;
		}
		$status = match ( true ) {
			null === $profile && $retainedSource => $record->status(),
			null === $profile => 'local_profile_missing',
			$record->webhookProfileRevision() < $profile->revision() => 'profile_revision_stale',
			default => $record->status(),
		};

		$this->projectedStatuses[ $key ] = $status;

		return $status;
	}
}

<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub\WebhookManagement\Display;

use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationRecord;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationStore;

/** Builds complete display-safe models without rendering or request access. */
final class WebhookDisplayModel {
	private const PROVIDER_CODE = 'gh';

	private const PROVIDER_LABEL = 'GitHub';

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
	public function enrichRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		if ( ! hash_equals( self::PROVIDER_CODE, $providerCode ) ) {
			return $rows;
		}

		$this->projectedStatuses = array();
		$readiness               = $this->readiness( $providerCode );
		if ( null === $readiness ) {
			return $rows;
		}

		$records           = array_filter(
			$this->records->all(),
			static fn ( InstallationRecord $record ): bool => hash_equals( self::PROVIDER_CODE, $record->providerCode() )
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
				$syntheticKey          = 'ran-booster-github-webhook-management:historical:' . substr( hash( 'sha256', $recordKey ), 0, 16 );
				$rows[ $syntheticKey ] = $this->retainedRecordRow( $syntheticKey, $record );
			}
		}

		return $rows;
	}

	/**
	 * @param array{hook_id:string,profile_id:string}|null $recovery
	 * @return array<string, mixed>|null
	 */
	public function panel( string $providerCode, string $repositoryId, string $returnUrl, ?string $resultCode, ?array $recovery, bool $canManage ): ?array {
		if ( ! $canManage || ! hash_equals( self::PROVIDER_CODE, $providerCode ) || '' === trim( $repositoryId ) ) {
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
		$operations              = null === $recovery ? $this->availableOperations( $target, $record, $status ) : array();
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
			$help = sprintf( __( 'The recorded secret is no longer available. Update the %s webhook to use the current applicable secret; Booster creates a repository secret when none applies.', 'ran-booster' ), self::PROVIDER_LABEL );
		} elseif ( null !== $record && 'remote_missing' === $status ) {
			/* translators: %s: repository provider name. */
			$help = sprintf( __( 'Managed removal is unavailable because the recorded %1$s hook cannot be confirmed. Inspect %1$s manually before continuing.', 'ran-booster' ), self::PROVIDER_LABEL );
		}

		return array(
			'form_action'         => admin_url( 'admin-post.php' ),
			'admin_action'        => 'ran_booster_github_webhook_management_operation',
			'provider_code'       => $providerCode,
			'provider_label'      => self::PROVIDER_LABEL,
			'repository_id'       => $repositoryId,
			'repository'          => $target->repository(),
			'interaction_request' => AdminInteractionRequest::providerRepositories( 'github-webhook-management:manage-webhook', $this->panelUrl( $returnUrl, $repositoryId ), 'github-webhook-management-error' ),
			'result'              => null === $resultCode ? null : array(
				'class'   => $this->isSuccessfulResult( $resultCode ) ? 'notice-success' : 'notice-error',
				'message' => $this->notice( $resultCode, $recovery ),
			),
			'recovery_warning'    => null !== $record && $record->requiresHookIdentification()
				? __( 'Provider state changed without a stable hook ID. Managed operations are disabled for this repository. Inspect its GitHub webhooks and the recorded Core signing profile manually; do not retry Setup until both sides are reconciled.', 'ran-booster' )
				: ( null !== $recovery ? __( 'GitHub webhook management could not persist the returned recovery references. Setup is disabled on this recovery view. Inspect GitHub and Core manually before leaving or retrying.', 'ran-booster' ) : null ),
			'credential_choices'  => $this->credentialChoices( $providerCode ),
			'operations'          => $operationModels,
			'action_help'         => $help,
		);
	}

	/** @return list<array{heading:?string,body:string}> */
	public function documentation(): array {
		return array(
			array(
				'heading' => null,
				'body'    => __( 'Booster can set up, check, reconfigure and remove one GitHub webhook per managed repository. Manual webhook setup remains available.', 'ran-booster' ),
			),
			array(
				'heading' => __( 'Saved profile or request-only GitHub access', 'ran-booster' ),
				'body'    => __( 'Select an eligible saved Booster profile or use a fresh fine-grained personal access token restricted to the selected repository with Webhooks: Read and write permission. For a saved profile, GitHub webhook management sends only its display-safe ID and Core resolves the PAT inside the fixed GitHub operation. A pasted PAT is submitted for this one operation. Neither value is persisted or logged by GitHub webhook management.', 'ran-booster' ),
			),
			array(
				'heading' => __( 'Readiness and recorded status', 'ran-booster' ),
				'body'    => __( 'Current readiness verifies Booster storage, a public HTTPS callback and stable repository identity without contacting GitHub. Timestamped hook status is historical until an administrator runs Check. Check refreshes the identified remote configuration; signed delivery is established separately by correlating GitHub delivery history with the Provider request ID in Booster Activity. Reconfigure after callback, endpoint or signing-profile changes.', 'ran-booster' ),
			),
			array(
				'heading' => __( 'Deployment and lifecycle boundaries', 'ran-booster' ),
				'body'    => __( 'GitHub webhook management never enables Automatic deployment. A push can affect only packages already set to Automatic for the matching repository and branch. Blueprint import, plugin deactivation and plugin deletion do not contact GitHub or remove remote hooks. Remove or inspect the identified GitHub hook before deleting local recovery records.', 'ran-booster' ),
			),
			array(
				'heading' => null,
				'body'    => __( 'Removal fails closed unless the recorded hook is positively identified and confirmed absent after deletion. Exact repository signing profiles created for the hook may be released; reused owner-shared and Core-created profiles remain in Core.', 'ran-booster' ),
			),
			array(
				'heading' => __( 'Cleanup after switching package source', 'ran-booster' ),
				'body'    => __( 'Switching a package to Published releases does not remove an existing remote hook, GitHub webhook management record or Core signing-secret profile. The release-managed package ignores pushes, but another branch-managed package using the same repository may still need the hook. Keep the setup for a temporary source switch.', 'ran-booster' ),
			),
			array(
				'heading' => null,
				'body'    => __( 'For a long-term release source or a retired site or repository, first confirm that no branch-managed package still needs the hook. Retained-hook cleanup is manual: remove the identified hook in GitHub first, then remove only unused local signing material in Core.', 'ran-booster' ),
			),
			array(
				'heading' => null,
				'body'    => __( 'If GitHub webhook management is unavailable, remove the remote hook in GitHub first, then use Manage secrets in Booster to remove only unused local signing material. If ownership or remaining use is uncertain, leave the setup in place.', 'ran-booster' ),
			),
		);
	}

	public function notice( string $code, ?array $recovery = null ): string {
		if ( 'orphaned' === $code ) {
			return sprintf( 'The remote hook may be active without a complete local record. Inspect it manually in %s before retrying.', self::PROVIDER_LABEL );
		}
		if ( in_array( $code, array( 'recovery_record_failed', 'record_conflict', 'record_update_failed' ), true ) ) {
			return null === $recovery
				? ( 'record_conflict' === $code
					? 'A newer GitHub webhook management record won the persistence race. Nothing was overwritten; inspect the current GitHub and Core state before retrying.'
					: 'Provider state may have changed, but GitHub webhook management could not save its non-secret recovery record. Inspect GitHub and Core before retrying.' )
				: sprintf( 'Provider state may have changed, but the current GitHub webhook management record was not overwritten. Inspect GitHub hook reference %1$s and Core signing profile %2$s before retrying.', $recovery['hook_id'], $recovery['profile_id'] );
		}
		if ( 'manual_recovery_required' === $code ) {
			return 'Managed operations are disabled because the prior setup did not return a stable hook ID. Inspect GitHub and Core manually before retrying.';
		}

		return match ( $code ) {
			'configured_pending_delivery' => 'GitHub webhook management configured the remote hook. Signed delivery verification is still pending.',
			'verified' => 'GitHub webhook management confirmed the recorded remote configuration. Correlate provider delivery history with the Provider request ID in Booster Activity before treating signed delivery as established.',
			'removed' => 'GitHub webhook management confirmed the remote hook is absent and cleared its local recovery record.',
			'forbidden' => 'You are not permitted to manage this repository webhook. Nothing was changed.',
			'invalid_request' => 'The webhook request was invalid or expired. Nothing was changed; reload this repository and try again.',
			'invalid_token' => 'Select one saved credential or provide one request-only token, then try again.',
			'operation_unauthorized' => 'Core could not authorize this repository webhook operation. Nothing was changed.',
			'repository_identity_unconfirmed' => 'Core could not confirm the selected repository identity. Nothing was changed.',
			'operation_busy' => 'Another webhook operation is already in progress for this repository. Wait for it to finish, then check the recorded state.',
			'operation_failed' => 'GitHub webhook management could not confirm the operation outcome. Inspect the provider and recorded status before retrying.',
			'setup_failed' => 'The provider rejected the webhook setup request. No remote hook was established.',
			'setup_compensated' => 'GitHub webhook management could not verify the new remote hook, so it removed it. No webhook was established; setup may be tried again.',
			'setup_compensation_incomplete' => 'GitHub webhook management could not verify or safely remove the new remote hook. Inspect the provider and Core records before retrying.',
			'setup_outcome_unknown' => 'GitHub webhook management could not confirm whether setup changed the remote hook. Inspect the provider and Core records before retrying.',
			'hook_inventory_unavailable', 'hook_inventory_invalid', 'hook_inventory_incomplete', 'matching_hooks_ambiguous' => 'GitHub webhook management could not establish the current remote hook state. Nothing should be treated as successful; inspect the provider before retrying.',
			'setup_response_invalid' => 'The provider response did not identify the new hook. Inspect the provider and Core records before retrying.',
			'preconfiguration_read_unavailable', 'reconfigure_readback_unavailable', 'reconfigure_outcome_unknown' => 'GitHub webhook management could not confirm the remote hook state after the update request. Run Check or inspect the hook in GitHub before retrying an update.',
			'reconfigure_failed' => 'The provider rejected the webhook update request. Run Check or inspect the hook in GitHub before retrying.',
			'hook_ownership_unavailable' => 'GitHub webhook management could not confirm that the recorded hook belongs to this site. Run Check or inspect the hook in GitHub before retrying.',
			'predelete_read_unavailable', 'remove_readback_unavailable', 'remove_outcome_unknown' => 'GitHub webhook management could not confirm whether the remote hook was removed. Run Check or inspect the hook in GitHub before retrying removal.',
			'remove_failed' => 'The provider rejected the webhook removal request. Run Check or inspect the hook in GitHub before retrying.',
			'operation_lock_release_failed' => 'The webhook operation completed, but Core could not release its coordination lock. Wait for the current request to end, then run Check before retrying.',
			'assessment_insufficient' => 'Core confirmed that the selected credential is insufficient for this repository webhook operation. Nothing was changed.',
			'assessment_stale' => 'The credential fitness assessment is stale. Nothing was changed; assess again with current repository authority.',
			'assessment_unsupported' => 'The bound provider does not support this fixed webhook operation. Nothing was changed.',
			'assessment_unavailable' => 'Core could not establish safe credential fitness for this operation. Nothing was changed.',
			default => 'GitHub webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.',
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
	private function retainedRecordRow( string $key, InstallationRecord $record ): array {
		$actions       = array();
		$parts         = explode( '/', $record->repository() );
		$repositoryUrl = 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1]
			? 'https://github.com/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] )
			: null;
		$hookUrl       = null !== $repositoryUrl && ctype_digit( $record->hookId() )
			? $repositoryUrl . '/settings/hooks/' . rawurlencode( $record->hookId() )
			: null;
		if ( null !== $hookUrl ) {
			$actions['ran-booster-github-webhook-management:inspect'] = array(
				'key'           => 'ran-booster-github-webhook-management:inspect',
				/* translators: %s: repository provider name. */
				'label'         => sprintf( __( 'Open recorded %s hook', 'ran-booster' ), self::PROVIDER_LABEL ),
				'type'          => 'link',
				'url'           => $hookUrl,
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
			'provider_label'  => self::PROVIDER_LABEL,
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
		return array(
			array(
				'label' => __( 'Managed hook status', 'ran-booster' ),
				'value' => $this->historicalStatusLabel( $statusCode ),
				'tone'  => $this->historicalStatusTone( $statusCode ),
			),
			array(
				'label' => __( 'Recorded hook profile', 'ran-booster' ),
				'value' => null === $record ? __( 'Managed hook not yet set', 'ran-booster' ) : $this->recordedProfileLabel( $statusCode, $record ),
			),
			array(
				'label'    => __( 'Last checked', 'ran-booster' ),
				'value'    => null === $record ? __( 'Never', 'ran-booster' ) : $record->checkedAt(),
				'datetime' => null === $record ? '' : $record->checkedAt(),
			),
		);
	}

	/** @return array<string, string> */
	private function availableOperations( AssistanceTarget $target, ?InstallationRecord $record, ?string $status ): array {
		if ( null === $record ) {
			/* translators: %s: repository provider name. */
			return array( 'setup' => sprintf( __( 'Set up in %s', 'ran-booster' ), self::PROVIDER_LABEL ) );
		}
		if ( $record->requiresHookIdentification() ) {
			return array();
		}
		$operations = array();
		if ( in_array( $status, array( 'profile_revision_stale', 'configuration_drift', 'local_profile_missing' ), true ) || ( 'needs_verification' !== $status && ! hash_equals( $record->endpoint(), $target->endpoint() ) ) ) {
			/* translators: %s: repository provider name. */
			$operations['reconfigure'] = sprintf( __( 'Update %s webhook', 'ran-booster' ), self::PROVIDER_LABEL );
		}
		/* translators: %s: repository provider name. */
		$operations['check'] = sprintf( __( 'Check %s', 'ran-booster' ), self::PROVIDER_LABEL );
		if ( ! in_array( $status, array( 'local_profile_missing', 'remote_missing', 'removal_pending' ), true ) ) {
			/* translators: %s: repository provider name. */
			$operations['remove'] = sprintf( __( 'Remove from %s', 'ran-booster' ), self::PROVIDER_LABEL );
		}

		return $operations;
	}

	private function panelUrl( string $returnUrl, string $repositoryId ): string {
		$url = 1 === preg_match( '/[?&]repository=/', $returnUrl ) ? $returnUrl : $returnUrl . ( str_contains( $returnUrl, '?' ) ? '&' : '?' ) . 'repository=' . rawurlencode( $repositoryId );

		return $url . '#ran-booster-github-webhook-management-operation-heading';
	}

	private function operationUrl( string $operation, string $providerCode, string $repositoryId ): string {
		$action = 'ran_booster_repository_webhook_' . implode( '_', array( $operation, $providerCode, $repositoryId ) );

		return admin_url( 'admin-post.php?action=ran_booster_github_webhook_management_operation&_wpnonce=' . rawurlencode( wp_create_nonce( $action ) ) );
	}

	private function historicalStatusLabel( string $status ): string {
		return match ( $status ) {
			'not_configured' => __( 'No managed hook recorded', 'ran-booster' ),
			'configured' => __( 'Configured at last check', 'ran-booster' ),
			/* translators: %s: repository provider name. */
			'profile_revision_stale' => sprintf( __( 'Signing secret changed; %s update required', 'ran-booster' ), self::PROVIDER_LABEL ),
			'local_profile_missing' => __( 'Secret needs attention', 'ran-booster' ),
			/* translators: %s: webhook status description. */
			default => sprintf( __( 'Needs attention: %s at last check', 'ran-booster' ), 'configuration_drift' === $status ? __( 'Configuration drift', 'ran-booster' ) : ucwords( str_replace( '_', ' ', $status ) ) ),
		};
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

	private function historicalStatusTone( string $status ): string {
		return match ( $status ) {
			'configured' => 'ok', 'not_configured' => 'warning', 'orphaned', 'removal_pending' => 'error', default => 'warning' };
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

<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\WebhookManagement\WebhookManagementAdminUrl;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;

/** Builds complete display-safe models without rendering or request access. */
final class WebhookDisplayModel {
	/** @var array<string, string> */
	private array $projected_statuses = array();

	public function __construct(
		private readonly WebhookAssistanceFacade $facade,
		private readonly InstallationStore $records
	) {
	}

	/**
	 * @param array<array-key, array<string, mixed>> $rows
	 * @param array<array-key, array<string, mixed>> $repository_projections
	 * @return array<array-key, array<string, mixed>>
	 */
	public function enrich_rows( array $rows, string $provider_code, string $provider_label, string $repository_url_base, array $repository_projections, string $return_url ): array {
		$this->projected_statuses = array();
		$readiness                = $this->readiness( $provider_code );
		if ( null === $readiness ) {
			return $rows;
		}

		$records             = array_filter(
			$this->records->all(),
			static fn ( InstallationRecord $record ): bool => hash_equals( $provider_code, $record->provider_code() )
		);
		$current_record_keys = array();

		foreach ( $repository_projections as $row_key => $projection ) {
			$repository_id = $this->projection_repository_id( $row_key, $projection );
			if ( null === $repository_id || ! isset( $rows[ $row_key ], $readiness['repositories'][ $repository_id ] ) ) {
				continue;
			}

			$record_key                         = InstallationRecord::key( $provider_code, $repository_id );
			$current_record_keys[ $record_key ] = true;
			$record                             = $records[ $record_key ] ?? null;
			$status_code                        = $this->status_code( $record, $readiness['callback_url'] );
			$existing_details                   = is_array( $rows[ $row_key ]['details'] ?? null ) ? $rows[ $row_key ]['details'] : array();
			$rows[ $row_key ]['details']        = array_merge( $existing_details, $this->history_details( $status_code, $record ) );

			$actions = is_array( $rows[ $row_key ]['actions'] ?? null ) ? $rows[ $row_key ]['actions'] : array();
			if ( isset( $actions['core:webhook-management'] ) && is_array( $actions['core:webhook-management'] ) ) {
				if ( $readiness['repositories'][ $repository_id ]['eligible'] ) {
					$actions['core:webhook-management']['url']          = $this->panel_url( $return_url, $repository_id );
					$actions['core:webhook-management']['disabled']     = false;
					$actions['core:webhook-management']['described_by'] = '';
				}
				$rows[ $row_key ]['actions'] = $actions;
			}
		}

		foreach ( $rows as $row_key => &$row ) {
			if ( isset( $repository_projections[ $row_key ] ) || 'release_asset' !== ( $row['source_key'] ?? null ) ) {
				continue;
			}
			$repository_id = is_string( $row['repository_id'] ?? null ) ? trim( $row['repository_id'] ) : '';
			$record_key    = InstallationRecord::key( $provider_code, $repository_id );
			$record        = '' === $repository_id ? null : ( $records[ $record_key ] ?? null );
			if ( null === $record ) {
				continue;
			}
			$current_record_keys[ $record_key ] = true;
			$existing_details                   = is_array( $row['details'] ?? null ) ? $row['details'] : array();
			$row['details']                     = array_merge( $existing_details, $this->history_details( $this->projected_status( $record, true ), $record ) );
		}
		unset( $row );

		foreach ( $records as $record_key => $record ) {
			if ( ! isset( $current_record_keys[ $record_key ] ) ) {
				$synthetic_key          = 'ran-booster-repository-webhook-management:historical:' . substr( hash( 'sha256', $record_key ), 0, 16 );
				$rows[ $synthetic_key ] = $this->retained_record_row( $synthetic_key, $record, $provider_label, $repository_url_base );
			}
		}

		return $rows;
	}

	/**
	 * @param array<array-key,array<string,mixed>> $rows
	 * @param array<array-key,array<string,mixed>> $repository_projections
	 * @return array<array-key,array<string,mixed>>
	 */
	public function enrich_historical_rows( array $rows, string $provider_code, array $repository_projections ): array {
		$records = $this->records->all();

		foreach ( $repository_projections as $row_key => $projection ) {
			$repository_id = $this->projection_repository_id( $row_key, $projection );
			$record        = null === $repository_id ? null : ( $records[ InstallationRecord::key( $provider_code, $repository_id ) ] ?? null );
			if ( null !== $record && isset( $rows[ $row_key ] ) ) {
				$existing                    = is_array( $rows[ $row_key ]['details'] ?? null ) ? $rows[ $row_key ]['details'] : array();
				$rows[ $row_key ]['details'] = array_merge( $existing, $this->historical_details( $record ) );
			}
		}
		foreach ( $rows as $row_key => $row ) {
			if ( isset( $repository_projections[ $row_key ] ) || 'release_asset' !== ( $row['source_key'] ?? null ) ) {
				continue;
			}
			$repository_id = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
			$record        = '' === trim( $repository_id ) ? null : ( $records[ InstallationRecord::key( $provider_code, $repository_id ) ] ?? null );
			if ( null !== $record ) {
				$existing                    = is_array( $row['details'] ?? null ) ? $row['details'] : array();
				$rows[ $row_key ]['details'] = array_merge( $existing, $this->historical_details( $record ) );
			}
		}

		return $rows;
	}

	/**
	 * @param array{hook_id:string,profile_id:string}|null $recovery
	 * @return array<string, mixed>|null
	 */
	public function panel( string $provider_code, string $provider_label, string $repository_id, string $return_url, ?string $result_code, ?array $recovery, bool $can_manage, ?string $remediation = null, ?string $webhooks_url = null ): ?array {
		if ( ! $can_manage || '' === trim( $repository_id ) ) {
			return null;
		}

		try {
			$target = $this->facade->target( $provider_code, $repository_id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return null;
		}
		if ( null === $target || ! hash_equals( $repository_id, $target->repository_id() ) ) {
			return null;
		}

		$this->projected_statuses = array();
		$record                   = $this->records->find( $provider_code, $repository_id );
		$status                   = null === $record ? null : $this->projected_status( $record );
		$credentials              = $this->credential_choices( $provider_code );
		$operations               = null === $recovery ? $this->available_operations( $target, $record, $status, $provider_label, array() !== $credentials ) : array();
		$operation_models         = $this->operation_models( $operations, $provider_code, $repository_id );

		$help = null;
		if ( null !== $record && 'local_profile_missing' === $status ) {
			/* translators: %s: repository provider name. */
			$help = sprintf( __( 'The recorded secret is no longer available. Update the %s webhook to use the current applicable secret; Booster creates a repository secret when none applies.', 'ran-booster' ), $provider_label );
		} elseif ( null !== $record && 'remote_missing' === $status ) {
			/* translators: %s: repository provider name. */
			$help = sprintf( __( 'Managed removal is unavailable because the recorded %1$s hook cannot be confirmed. Inspect %1$s manually before continuing.', 'ran-booster' ), $provider_label );
		}
		$recovery_warning = null;
		if ( null !== $record && $record->requires_hook_identification() ) {
			/* translators: %s: repository provider name. */
			$recovery_warning = sprintf( __( 'Provider state changed without a stable hook ID. Managed operations are disabled for this repository. Inspect its %s webhooks and the recorded Core signing profile manually; do not retry Setup until both sides are reconciled.', 'ran-booster' ), $provider_label );
		} elseif ( null !== $recovery ) {
			/* translators: %s: repository provider name. */
			$recovery_warning = sprintf( __( 'Repository webhook management could not persist the returned recovery references. Setup is disabled on this recovery view. Inspect %s and Core manually before leaving or retrying.', 'ran-booster' ), $provider_label );
		}

		return array(
			'disabled'                    => false,
			'unavailable_reason'          => null,
			'webhook_profile_disabled'    => null !== $record,
			'webhook_profile_placeholder' => null === $record
				? __( 'Choose a signing secret', 'ran-booster' )
				: ( 'local_profile_missing' === $status ? __( 'Recorded signing secret is unavailable', 'ran-booster' ) : __( 'Recorded signing secret', 'ran-booster' ) ),
			'credentials_url'             => $this->provider_settings_url( $provider_code, 'credentials' ),
			'secrets_url'                 => $this->provider_settings_url( $provider_code, 'secrets' ),
			'form_action'                 => WebhookManagementAdminUrl::for_path( 'admin-post.php' ),
			'admin_action'                => 'ran_booster_repository_webhook_management_operation',
			'provider_code'               => $provider_code,
			'provider_label'              => $provider_label,
			'repository_id'               => $repository_id,
			'repository'                  => $target->repository(),
			'webhooks_url'                => $webhooks_url,
			'return_url'                  => $this->panel_url( $return_url, $repository_id ),
			'interaction_request'         => AdminInteractionRequest::provider_repositories( 'repository-webhook-management:manage-webhook', $this->panel_url( $return_url, $repository_id ), 'repository-webhook-management-error' ),
			'result'                      => null === $result_code ? null : array(
				'class'   => $this->result_notice_class( $result_code ),
				'message' => $this->notice( $result_code, $recovery, $remediation ),
			),
			'recovery_warning'            => $recovery_warning,
			'management_credential_id'    => null === $record ? null : $record->management_credential_id(),
			'credential_choices'          => $credentials,
			'webhook_profile_choices'     => null === $record ? $this->webhook_profile_choices( $provider_code, $repository_id ) : array(),
			'operations'                  => $operation_models,
			'action_help'                 => $help,
		);
	}

	/**
	 * @param array<string,string> $available
	 * @return list<array{key:string,label:string,url:string,primary:bool,disabled:bool}>
	 */
	private function operation_models( array $available, string $provider_code, string $repository_id ): array {
		$labels     = array(
			'setup'       => __( 'Set up webhook', 'ran-booster' ),
			'check'       => __( 'Check webhook', 'ran-booster' ),
			'reconfigure' => __( 'Update webhook', 'ran-booster' ),
			'test'        => __( 'Test webhook', 'ran-booster' ),
			'remove'      => __( 'Remove webhook', 'ran-booster' ),
		);
		$operations = array();
		foreach ( $labels as $operation => $label ) {
			$operations[] = array(
				'key'      => $operation,
				'label'    => $label,
				'url'      => $this->operation_url( $operation, $provider_code, $repository_id ),
				'primary'  => 'setup' === $operation,
				'disabled' => ! isset( $available[ $operation ] ),
			);
		}

		return $operations;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function unavailable_panel( string $provider_code, string $provider_label, string $repository_id, string $repository, string $return_url, string $reason, ?string $webhooks_url = null ): array {
		return array(
			'disabled'                    => true,
			'unavailable_reason'          => $reason,
			'webhook_profile_disabled'    => true,
			'webhook_profile_placeholder' => __( 'Choose a signing secret', 'ran-booster' ),
			'management_credential_id'    => null,
			'credentials_url'             => $this->provider_settings_url( $provider_code, 'credentials' ),
			'secrets_url'                 => $this->provider_settings_url( $provider_code, 'secrets' ),
			'form_action'                 => WebhookManagementAdminUrl::for_path( 'admin-post.php' ),
			'admin_action'                => 'ran_booster_repository_webhook_management_operation',
			'provider_code'               => $provider_code,
			'provider_label'              => $provider_label,
			'repository_id'               => $repository_id,
			'repository'                  => $repository,
			'webhooks_url'                => $webhooks_url,
			'return_url'                  => $this->panel_url( $return_url, $repository_id ),
			'interaction_request'         => null,
			'result'                      => null,
			'recovery_warning'            => null,
			'credential_choices'          => $this->credential_choices( $provider_code ),
			'webhook_profile_choices'     => array(),
			'operations'                  => $this->operation_models( array(), $provider_code, $repository_id ),
			'action_help'                 => null,
		);
	}

	/** @return list<array{heading:?string,body:string}> */
	public function documentation( string $provider_label ): array {
		/* translators: %s: repository provider name. */
		$intro = sprintf( __( 'Booster can set up, check, reconfigure and remove one %s webhook per managed repository. Manual webhook setup remains available.', 'ran-booster' ), $provider_label );
		/* translators: %s: repository provider name. */
		$credential_heading = sprintf( __( 'Saved %s access', 'ran-booster' ), $provider_label );
		/* translators: %s: repository provider name. */
		$readiness = sprintf( __( 'Current readiness verifies Booster storage, a public HTTPS callback and stable repository identity without contacting %s. Timestamped hook status is historical until an administrator runs Check.', 'ran-booster' ), $provider_label );
		/* translators: %s: repository provider name. */
		$lifecycle = sprintf( __( 'Webhook management never enables Automatic deployment. Blueprint import, plugin deactivation and plugin deletion do not contact %s or remove remote hooks.', 'ran-booster' ), $provider_label );
		/* translators: %s: repository provider name. */
		$cleanup = sprintf( __( 'Switching a package to Published releases does not remove the remote hook, its local recovery record or Core signing material. Remove the identified hook in %s first, then remove only unused local signing material in Core.', 'ran-booster' ), $provider_label );

		return array(
			array(
				'heading' => null,
				'body'    => $intro,
			),
			array(
				'heading' => $credential_heading,
				'body'    => __( 'Select an eligible saved Booster credential for the selected repository. Booster resolves the saved credential inside the fixed provider operation; no credential material is returned to webhook management.', 'ran-booster' ),
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

	/** @param array{hook_id: string, profile_id: string}|null $recovery */
	public function notice( string $code, ?array $recovery = null, ?string $remediation = null ): string {
		if ( 'orphaned' === $code ) {
			return __( 'The remote hook may be active without a complete local record. Inspect it manually at the provider before retrying.', 'ran-booster' );
		}
		if ( in_array( $code, array( 'recovery_record_failed', 'record_conflict', 'record_update_failed' ), true ) ) {
			return null === $recovery
				? ( 'record_conflict' === $code
						? __( 'A newer webhook-management record won the persistence race. Nothing was overwritten; inspect the current provider and Core state before retrying.', 'ran-booster' )
						: __( 'Provider state may have changed, but webhook management could not save its non-secret recovery record. Inspect the provider and Core before retrying.', 'ran-booster' ) )
					: sprintf(
						/* translators: 1: provider hook reference ID, 2: Core signing profile ID. */
						__( 'Provider state may have changed, but the current webhook-management record was not overwritten. Inspect provider hook reference %1$s and Core signing profile %2$s before retrying.', 'ran-booster' ),
						$recovery['hook_id'],
						$recovery['profile_id']
					);
		}
		if ( 'manual_recovery_required' === $code ) {
			return __( 'Managed operations are disabled because the prior setup did not return a stable hook ID. Inspect the provider and Core manually before retrying.', 'ran-booster' );
		}

		return match ( $code ) {
			'ping_requested', 'ping_verified' => __( 'GitHub accepted the ping request for the recorded hook. This does not prove an authenticated inbound delivery or verify the signing secret; recorded status remains needs verification.', 'ran-booster' ),
			'ping_delivery_failed' => __( 'GitHub recorded a new ping delivery for the exact hook, but it did not succeed. Signed delivery remains unverified; inspect the provider delivery details.', 'ran-booster' ),
			'configured_pending_delivery' => __( 'Webhook management configured the remote hook. Signed delivery verification is still pending.', 'ran-booster' ),
			'verified' => __( 'Webhook management confirmed the recorded remote configuration. Correlate provider delivery history with the Provider request ID in Booster Activity before treating signed delivery as established.', 'ran-booster' ),
			'removed' => __( 'Webhook management confirmed the remote hook is absent and cleared its local recovery record.', 'ran-booster' ),
			'forbidden' => __( 'You are not permitted to manage this repository webhook. Nothing was changed.', 'ran-booster' ),
			'invalid_request' => __( 'The webhook request was invalid or expired. Nothing was changed; reload this repository and try again.', 'ran-booster' ),
			'invalid_token' => __( 'Select one saved credential, then try again.', 'ran-booster' ),
			'operation_unauthorized' => __( 'Core could not authorize this repository webhook operation. Nothing was changed.', 'ran-booster' ),
			'repository_identity_unconfirmed' => __( 'Core could not confirm the selected repository identity. Nothing was changed.', 'ran-booster' ),
			'operation_busy' => __( 'Another webhook operation is already in progress for this repository. Wait for it to finish, then check the recorded state.', 'ran-booster' ),
			'operation_failed' => __( 'Webhook management could not confirm the operation outcome. Inspect the provider and recorded status before retrying.', 'ran-booster' ),
			'setup_failed' => __( 'The provider rejected the webhook setup request. No remote hook was established.', 'ran-booster' ),
			'setup_compensated' => __( 'Webhook management could not verify the new remote hook, so it removed it. No webhook was established; setup may be tried again.', 'ran-booster' ),
			'setup_compensation_incomplete' => __( 'Webhook management could not verify or safely remove the new remote hook. Inspect the provider and Core records before retrying.', 'ran-booster' ),
			'setup_outcome_unknown' => __( 'Webhook management could not confirm whether setup changed the remote hook. Inspect the provider and Core records before retrying.', 'ran-booster' ),
			'hook_inventory_unavailable', 'hook_inventory_invalid', 'hook_inventory_incomplete', 'matching_hooks_ambiguous' => __( 'Webhook management could not establish the current remote hook state. Nothing should be treated as successful; inspect the provider before retrying.', 'ran-booster' ),
			'setup_response_invalid' => __( 'The provider response did not identify the new hook. Inspect the provider and Core records before retrying.', 'ran-booster' ),
			'preconfiguration_read_unavailable', 'reconfigure_readback_unavailable', 'reconfigure_outcome_unknown' => __( 'Webhook management could not confirm the remote hook state after the update request. Run Check or inspect the hook at the provider before retrying an update.', 'ran-booster' ),
			'reconfigure_failed' => __( 'The provider rejected the webhook update request. Run Check or inspect the hook at the provider before retrying.', 'ran-booster' ),
			'hook_ownership_unavailable' => __( 'Webhook management could not confirm that the recorded hook belongs to this site. Run Check or inspect the hook at the provider before retrying.', 'ran-booster' ),
			'predelete_read_unavailable', 'remove_readback_unavailable', 'remove_outcome_unknown' => __( 'Webhook management could not confirm whether the remote hook was removed. Run Check or inspect the hook at the provider before retrying removal.', 'ran-booster' ),
			'remove_failed' => __( 'The provider rejected the webhook removal request. Run Check or inspect the hook at the provider before retrying.', 'ran-booster' ),
			'operation_lock_release_failed' => __( 'The webhook operation completed, but Core could not release its coordination lock. Wait for the current request to end, then run Check before retrying.', 'ran-booster' ),
			'assessment_insufficient' => __( 'Core confirmed that the selected credential is insufficient for this repository webhook operation. Nothing was changed.', 'ran-booster' ),
			'assessment_stale' => __( 'The credential fitness assessment is stale. Nothing was changed; assess again with current repository authority.', 'ran-booster' ),
			'assessment_unsupported' => __( 'The bound provider does not support this fixed webhook operation. Nothing was changed.', 'ran-booster' ),
			'assessment_unavailable' => __( 'Core could not establish safe credential fitness for this operation. Nothing was changed.', 'ran-booster' ),
			default => null !== $remediation && strlen( $remediation ) <= 255
				? $remediation
				: __( 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.', 'ran-booster' ),
		};
	}

	public function is_successful_result( string $code ): bool {
		return in_array( $code, array( 'configured_pending_delivery', 'verified', 'removed' ), true );
	}

	private function result_notice_class( string $code ): string {
		return in_array( $code, array( 'ping_requested', 'ping_verified' ), true )
			? 'notice-warning'
			: ( $this->is_successful_result( $code ) ? 'notice-success' : 'notice-error' );
	}

	public function can_respond_inline_to_failure( string $code ): bool {
		return in_array( $code, array( 'forbidden', 'invalid_request', 'invalid_token', 'operation_unauthorized', 'repository_identity_unconfirmed', 'operation_busy', 'setup_failed', 'setup_compensated', 'assessment_insufficient', 'assessment_stale', 'assessment_unsupported', 'assessment_unavailable' ), true );
	}

	/** @return array{callback_url:string,repositories:array<string,array{eligible:bool}>}|null */
	private function readiness( string $provider_code ): ?array {
		try {
			$projection = $this->facade->readiness( $provider_code )->to_array();
		} catch ( \Throwable $exception ) {
			unset( $exception );
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
				|| ( $repository['provider_code'] ?? null ) !== $provider_code
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
	private function credential_choices( string $provider_code ): array {
		try {
			$choices = $this->facade->credential_choices( $provider_code );
		} catch ( \Throwable ) {
			return array();
		}
		$normalized = array();
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! is_string( $choice['id'] ?? null ) || ! is_string( $choice['label'] ?? null ) || ! is_string( $choice['kind'] ?? null ) ) {
				continue;
			}
			$destroy_on = $choice['destroy_on'] ?? null;
			if ( null !== $destroy_on && ! is_string( $destroy_on ) ) {
				continue;
			}
			$label = $choice['label'] . ' (' . $choice['kind'] . ')';
			if ( is_string( $destroy_on ) ) {
				/* translators: %s: credential removal date. */
				$label .= ' · ' . sprintf( __( 'removes after %s', 'ran-booster' ), $destroy_on );
			}
			$normalized[] = array(
				'id'    => $choice['id'],
				'label' => $label,
			);
		}

		return $normalized;
	}

	/** @return list<array{id:string,label:string,scope:string}> */
	private function webhook_profile_choices( string $provider_code, string $repository_id ): array {
		try {
			$choices = $this->facade->webhook_profile_choices( $provider_code, $repository_id );
		} catch ( \Throwable ) {
			return array();
		}
		$normalized = array();
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! is_string( $choice['id'] ?? null ) || ! is_string( $choice['label'] ?? null ) || ! in_array( $choice['scope'] ?? null, array( 'repository', 'owner' ), true ) ) {
				continue;
			}
			$normalized[] = array(
				'id'    => $choice['id'],
				'label' => $choice['label'],
				'scope' => $choice['scope'],
			);
		}

		return $normalized;
	}

	/** @param array<string, mixed> $projection */
	private function projection_repository_id( string|int $row_key, array $projection ): ?string {
		$repository_id = $projection['repository_id'] ?? null;

		return is_string( $repository_id ) && '' !== trim( $repository_id )
			? $repository_id
			: ( is_string( $row_key ) && '' !== trim( $row_key ) ? $row_key : null );
	}

	private function status_code( ?InstallationRecord $record, string $callback_url ): string {
		$status = null === $record ? 'not_configured' : $this->projected_status( $record );
		if ( null !== $record && ! in_array( $status, array( 'local_profile_missing', 'profile_revision_stale', 'needs_verification' ), true ) && ! hash_equals( $record->endpoint(), $callback_url ) ) {
			return 'configuration_drift';
		}

		return $status;
	}

	/** @return array<string, mixed> */
	private function retained_record_row( string $key, InstallationRecord $record, string $provider_label, string $repository_url_base ): array {
		$actions        = array();
		$parts          = explode( '/', $record->repository() );
		$repository_url = 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1]
			? rtrim( $repository_url_base, '/' ) . '/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] )
			: null;
		if ( null !== $repository_url ) {
			$actions['ran-booster-repository-webhook-management:inspect'] = array(
				'key'           => 'ran-booster-repository-webhook-management:inspect',
				/* translators: %s: repository provider name. */
				'label'         => sprintf( __( 'Open %s repository', 'ran-booster' ), $provider_label ),
				'type'          => 'link',
				'url'           => $repository_url,
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => true,
				'described_by'  => '',
				'screen_reader' => $record->repository(),
			);
		}

		return array(
			'key'             => $key,
			'provider_code'   => $record->provider_code(),
			'provider_label'  => $provider_label,
			'repository_id'   => $record->repository_id(),
			'repository'      => $record->repository(),
			'repository_url'  => $repository_url ?? '',
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
			'details'         => $this->history_details( $this->projected_status( $record ), $record ),
		);
	}

	/** @return list<array{key:string,label:string,value:string,tone?:string,recorded?:bool,state?:string,datetime?:string}> */
	private function history_details( string $status_code, ?InstallationRecord $record ): array {
		$history = null === $record ? null : WebhookHistory::from_record( $record )->to_array();
		$details = array(
			array(
				'key'      => 'core:webhook-recorded-status',
				'label'    => __( 'Recorded hook status', 'ran-booster' ),
				'value'    => null === $history ? __( 'Managed hook not yet set', 'ran-booster' ) : $this->historical_status_label( $history['recorded_status'] ),
				'tone'     => null === $history ? 'warning' : $this->historical_status_tone( $history['recorded_status'] ),
				'recorded' => null !== $history,
				'state'    => $status_code,
			),
			array(
				'key'   => 'core:webhook-observation',
				'label' => __( 'Observation', 'ran-booster' ),
				'value' => null === $history ? __( 'No historical observation', 'ran-booster' ) : __( 'Historical only; not live readiness or a signed delivery', 'ran-booster' ),
				'tone'  => 'neutral',
			),
		);

		if ( null !== $history && $status_code !== $history['recorded_status'] ) {
			$details[] = array(
				'key'   => 'core:webhook-current-warning',
				'label' => __( 'Current local warning', 'ran-booster' ),
				'value' => $this->historical_status_label( $status_code ),
				'tone'  => $this->historical_status_tone( $status_code ),
			);
		}

		return array_merge(
			$details,
			array(
				array(
					'key'   => 'core:webhook-management-credential',
					'label' => __( 'Management credential', 'ran-booster' ),
					'value' => null === $record ? __( 'Webhook has not been managed yet', 'ran-booster' ) : $this->management_credential_label( $record ),
				),
				array(
					'key'   => 'core:webhook-signing-secret',
					'label' => __( 'Recorded signing secret', 'ran-booster' ),
					'value' => null === $record ? __( 'Managed hook not yet set', 'ran-booster' ) : $this->recorded_profile_label( $status_code, $record ),
				),
				array(
					'key'      => 'core:webhook-last-checked',
					'label'    => __( 'Last checked', 'ran-booster' ),
					'value'    => null === $history ? __( 'Never', 'ran-booster' ) : $history['checked_at'],
					'datetime' => null === $history ? '' : $history['checked_at'],
				),
			)
		);
	}

	/** @return list<array<string, string>> */
	private function historical_details( InstallationRecord $record ): array {
		$history = WebhookHistory::from_record( $record )->to_array();

		return array(
			array(
				'key'   => 'core:webhook-recorded-status',
				'label' => __( 'Recorded hook status', 'ran-booster' ),
				'value' => $this->historical_status_label( $history['recorded_status'] ),
				'tone'  => $this->historical_status_tone( $history['recorded_status'] ),
			),
			array(
				'key'   => 'core:webhook-observation',
				'label' => __( 'Observation', 'ran-booster' ),
				'value' => __( 'Historical only; not live readiness or a signed delivery', 'ran-booster' ),
				'tone'  => 'neutral',
			),
			array(
				'key'   => 'core:webhook-management-credential',
				'label' => __( 'Management credential', 'ran-booster' ),
				'value' => sprintf(
					/* translators: %s: saved credential profile ID. */
					__( 'Last managed with saved credential profile %s; current availability was not checked.', 'ran-booster' ),
					$record->management_credential_id()
				),
			),
			array(
				'key'   => 'core:webhook-signing-secret',
				'label' => __( 'Recorded signing secret', 'ran-booster' ),
				'value' => sprintf(
					/* translators: %s: signing secret profile ID. */
					__( 'Recorded signing secret profile %s; current availability was not checked.', 'ran-booster' ),
					$record->webhook_profile_id()
				),
			),
			array(
				'key'      => 'core:webhook-last-checked',
				'label'    => __( 'Last checked', 'ran-booster' ),
				'value'    => $history['checked_at'],
				'datetime' => $history['checked_at'],
			),
		);
	}

	/** @return array<string, string> */
	private function available_operations( AssistanceTarget $target, ?InstallationRecord $record, ?string $status, string $provider_label, bool $has_credential ): array {
		if ( ! $has_credential ) {
			return array();
		}
		if ( null === $record ) {
			/* translators: %s: repository provider name. */
			return array(
				/* translators: %s: repository provider name. */
				'setup'         => sprintf( __( 'Set up in %s', 'ran-booster' ), $provider_label ),
				'disabled:test' => __( 'Test webhook', 'ran-booster' ),
			);
		}
		if ( $record->requires_hook_identification() ) {
			return array();
		}
		$operations = array();
		if ( in_array( $status, array( 'profile_revision_stale', 'configuration_drift', 'local_profile_missing' ), true ) || ( 'needs_verification' !== $status && ! hash_equals( $record->endpoint(), $target->endpoint() ) ) ) {
			/* translators: %s: repository provider name. */
			$operations['reconfigure'] = sprintf( __( 'Update %s webhook', 'ran-booster' ), $provider_label );
		}
		/* translators: %s: repository provider name. */
		$operations['check'] = sprintf( __( 'Check %s', 'ran-booster' ), $provider_label );
		/* translators: %s: repository provider name. */
		$operations['test'] = sprintf( __( 'Test %s webhook', 'ran-booster' ), $provider_label );
		if ( ! in_array( $status, array( 'local_profile_missing', 'remote_missing', 'removal_pending' ), true ) ) {
			/* translators: %s: repository provider name. */
			$operations['remove'] = sprintf( __( 'Remove from %s', 'ran-booster' ), $provider_label );
		}

		return $operations;
	}

	private function panel_url( string $return_url, string $repository_id ): string {
		if ( 1 === preg_match( '/[?&]page=ran-booster-(?:plugins|themes)(?:&|$)/', $return_url ) ) {
			return $return_url;
		}
		$url = 1 === preg_match( '/[?&]repository=/', $return_url ) ? $return_url : $return_url . ( str_contains( $return_url, '?' ) ? '&' : '?' ) . 'repository=' . rawurlencode( $repository_id );

		return $url;
	}

	private function operation_url( string $operation, string $provider_code, string $repository_id ): string {
		$action = 'ran_booster_repository_webhook_' . implode( '_', array( $operation, $provider_code, $repository_id ) );

		return WebhookManagementAdminUrl::for_path( 'admin-post.php?action=ran_booster_repository_webhook_management_operation&_wpnonce=' . rawurlencode( wp_create_nonce( $action ) ) );
	}

	private function provider_settings_url( string $provider_code, string $view ): string {
		return WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) . '&view=' . rawurlencode( $view ) );
	}

	private function historical_status_label( string $status ): string {
		return match ( $status ) {
			'not_configured'          => __( 'No managed hook recorded', 'ran-booster' ),
			'configured'              => __( 'Configured at last check', 'ran-booster' ),
			'profile_revision_stale'  => __( 'Signing secret changed; webhook update required', 'ran-booster' ),
			'local_profile_missing'   => __( 'Secret needs attention', 'ran-booster' ),
			default                   => sprintf(
				/* translators: %s: webhook status label. */
				__( 'Needs attention: %s at last check', 'ran-booster' ),
				'configuration_drift' === $status ? __( 'Configuration drift', 'ran-booster' ) : ucwords( str_replace( '_', ' ', $status ) )
			),
		};
	}

	private function historical_status_tone( string $status ): string {
		return match ( $status ) {
			'configured' => 'ok', 'not_configured' => 'warning', 'orphaned', 'removal_pending' => 'error', default => 'warning' };
	}

	private function recorded_profile_label( string $status, InstallationRecord $record ): string {
		if ( 'local_profile_missing' === $status ) {
			return sprintf(
				/* translators: %s: signing secret profile ID. */
				__( 'Recorded signing secret profile %s is unavailable.', 'ran-booster' ),
				$record->webhook_profile_id()
			);
		}
		$label = $this->webhook_profile_label( $record );
		if ( null !== $label ) {
			return sprintf(
				/* translators: %s: current signing secret profile label. */
				__( '%s; current local profile metadata is available.', 'ran-booster' ),
				$label
			);
		}
		$scope  = 'owner' === $record->webhook_profile_scope() ? __( 'Owner-shared secret', 'ran-booster' ) : __( 'Repository secret', 'ran-booster' );
		$source = 'created' === $record->webhook_profile_disposition() ? __( 'created for this hook', 'ran-booster' ) : __( 'reused from Booster', 'ran-booster' );

		/* translators: 1: signing secret scope, 2: signing secret source. */
		return sprintf( __( '%1$s; %2$s', 'ran-booster' ), $scope, $source );
	}

	private function management_credential_label( InstallationRecord $record ): string {
		try {
			foreach ( $this->facade->credential_choices( $record->provider_code() ) as $choice ) {
				if ( is_array( $choice ) && is_string( $choice['id'] ?? null ) && is_string( $choice['label'] ?? null ) && hash_equals( $record->management_credential_id(), $choice['id'] ) ) {
					return sprintf(
						/* translators: %s: current saved credential profile label. */
						__( 'Last managed with %s; provider authority has not been revalidated.', 'ran-booster' ),
						$choice['label']
					);
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		return sprintf(
			/* translators: %s: saved credential profile ID. */
			__( 'Previously used saved credential profile %s is unavailable.', 'ran-booster' ),
			$record->management_credential_id()
		);
	}

	private function webhook_profile_label( InstallationRecord $record ): ?string {
		try {
			foreach ( $this->facade->webhook_profile_choices( $record->provider_code(), $record->repository_id() ) as $choice ) {
				if ( is_array( $choice ) && is_string( $choice['id'] ?? null ) && is_string( $choice['label'] ?? null ) && hash_equals( $record->webhook_profile_id(), $choice['id'] ) ) {
					return $choice['label'];
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		return null;
	}

	private function projected_status( InstallationRecord $record, bool $retained_source = false ): string {
		$key = implode( ':', array( $record->provider_code(), $record->repository_id(), $record->webhook_profile_id(), (string) $record->webhook_profile_revision() ) );
		if ( isset( $this->projected_statuses[ $key ] ) ) {
			return $this->projected_statuses[ $key ];
		}
		try {
			$profile = $this->facade->profile( $record->provider_code(), $record->repository_id(), $record->webhook_profile_id() );
		} catch ( \Throwable ) {
			$profile = null;
		}
		$status = match ( true ) {
			null === $profile && $retained_source => $record->status(),
			null === $profile => 'local_profile_missing',
			$record->webhook_profile_revision() < $profile->revision() => 'profile_revision_stale',
			default => $record->status(),
		};

		$this->projected_statuses[ $key ] = $status;

		return $status;
	}
}

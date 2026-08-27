<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement\GitHub;

/** @internal GitHub-specific release workflow presentation. */
final class GitHubReleaseWorkflowDisplay {
	public function workflow( array $view, bool $includeResultNotice = true ): string {
		$model = $this->workflowModel( $view );
		$html  = '<div class="ran-booster-release-workflow">';

		if ( $includeResultNotice ) {
			$html .= '<div class="ran-booster-release-workflow__notices">' . $model['notice'] . '</div>';
		}
		$html .= '<div class="ran-booster-release-workflow__body">';
		$html .= '<p>' . esc_html__( 'Booster can assess this repository and prepare one atomic draft pull request. Nothing is merged automatically.', 'ran-booster' ) . '</p>';
		$html .= $model['inspect_form'] . $model['detail'];
		$html .= '<hr><p><a href="' . esc_url( admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-documentation-published-releases' ) ) . '">'
			. esc_html__( 'Booster Published releases docs', 'ran-booster' ) . '</a> · <a href="https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'GitHub About releases', 'ran-booster' ) . '</a> · <a href="https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'GitHub immutable releases', 'ran-booster' ) . '</a></p>';

		return $html . '</div></div>';
	}

	/** @return array{notice:string,inspect_form:string,detail:string} */
	private function workflowModel( array $view ): array {
		$forms   = is_array( $view['forms'] ?? null ) ? $view['forms'] : array();
		$preview = is_array( $view['preview'] ?? null ) ? $view['preview'] : null;
		$record  = is_array( $view['record'] ?? null ) ? $view['record'] : null;
		$legacy  = is_array( $view['legacy'] ?? null ) ? $view['legacy'] : null;
		$detail  = '';
		if ( null !== $preview ) {
			$key     = 'template_update' === ( $preview['kind'] ?? null ) ? 'update_setup' : 'setup';
			$detail  = $this->preview( $preview );
			$detail .= $this->form( is_array( $forms[ $key ] ?? null ) ? $forms[ $key ] : array() );
		} elseif ( null !== $record && is_string( $record['repository'] ?? null ) && is_int( $record['pr_number'] ?? null ) ) {
			$url     = 'https://github.com/' . $record['repository'] . '/pull/' . $record['pr_number'];
			$detail  = '<hr><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review recorded setup pull request on GitHub', 'ran-booster' ) . '</a></p>';
			$detail .= $this->form( is_array( $forms['outcome'] ?? null ) ? $forms['outcome'] : array() );
			$detail .= $this->form( is_array( $forms['update_inspect'] ?? null ) ? $forms['update_inspect'] : array() );
		} elseif ( null !== $legacy ) {
			$detail = $this->legacyDetail( $legacy );
		}

		return array(
			'notice'       => $this->stateNotice( $view ),
			'inspect_form' => $this->form( is_array( $forms['inspect'] ?? null ) ? $forms['inspect'] : array() ),
			'detail'       => $detail,
		);
	}

	/** @param array<string,mixed> $legacy */
	private function legacyDetail( array $legacy ): string {
		$html = '<hr><p><strong>' . esc_html__( 'Legacy, unverified manual-reconciliation evidence.', 'ran-booster' ) . '</strong></p><p>' . esc_html__( 'Booster cannot prove the current remote, merged or managed-file state from this earlier record. No legacy outcome, update, retry or delete action is available.', 'ran-booster' ) . '</p>';
		if ( ! isset( $legacy['unsupported'] ) && is_string( $legacy['repository'] ?? null ) && is_int( $legacy['pr_number'] ?? null ) && is_string( $legacy['setup_branch'] ?? null ) ) {
			$url = 'https://github.com/' . $legacy['repository'] . '/pull/' . $legacy['pr_number'];
			return $html . '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review the earlier pull request manually on GitHub', 'ran-booster' ) . '</a></p><p><code>' . esc_html( $legacy['setup_branch'] ) . '</code></p>';
		}

		return $html . '<p>' . esc_html__( 'The earlier record is not authoritative for this package. Assess the current source again.', 'ran-booster' ) . '</p>';
	}

	/** Render the one state-specific notice for the stable workflow shell. */
	public function stateNotice( array $view ): string {
		$unavailable = true === ( $view['unavailable'] ?? false );
		$reason      = is_string( $view['unavailable_reason'] ?? null ) ? $view['unavailable_reason'] : '';
		if ( $unavailable && '' !== $reason ) {
			return '<div class="notice notice-warning inline"><p>' . esc_html( $reason ) . '</p></div>';
		}

		return $this->resultNotice( $view );
	}

	public function resultNotice( array $view ): string {
		$code                = is_string( $view['result_code'] ?? null ) ? $view['result_code'] : '';
		$successful          = true === ( $view['result_successful'] ?? false );
		$stage               = is_string( $view['failure_stage'] ?? null ) ? $view['failure_stage'] : '';
		$diagnostic          = is_string( $view['diagnostic_code'] ?? null ) ? $view['diagnostic_code'] : '';
		$diagnosticAvailable = true === ( $view['diagnostic_available'] ?? false );
		$reference           = is_string( $view['correlation_reference'] ?? null ) ? $view['correlation_reference'] : '';
		if ( ! str_starts_with( $code, 'workflow_' ) ) {
			return '';
		}

		$noticeTone = match ( $code ) {
			'workflow_release_automation_conflict' => 'notice-info',
			'workflow_rate_limited'                => 'notice-warning',
			default                                => $successful ? 'notice-success' : 'notice-error',
		};
		$html        = '<div class="notice ' . esc_attr( $noticeTone ) . ' inline"'
			. ( $successful ? ' data-ran-booster-package-success' : '' )
			. ' data-ran-booster-github-release-workflow-result><p>' . esc_html( $this->workflowMessage( $code ) ) . '</p>';
		$observation = in_array( $code, array( 'workflow_release_automation_conflict', 'workflow_release_automation_present', 'workflow_inspected' ), true );
		if ( ! $successful && ! $observation && in_array( $stage, array( 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ) {
			$html .= '<details><summary>' . esc_html__( 'Failure details', 'ran-booster' ) . '</summary><p>' . esc_html( $this->failureDiagnosticMessage( $diagnostic, $stage ) ) . '</p>';
			if ( $this->validDiagnosticCode( $diagnostic ) ) {
				$html .= '<p>' . esc_html__( 'Diagnostic code:', 'ran-booster' ) . ' <code>' . esc_html( $diagnostic ) . '</code></p>';
			}
			if ( $diagnosticAvailable && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $reference ) ) {
				$html .= '<p>' . esc_html__( 'Failure reference:', 'ran-booster' ) . ' <code>' . esc_html( $reference ) . '</code></p>';
			}
			$html .= '</details>';
		}

		return $html . '</div>';
	}

	private function validDiagnosticCode( string $code ): bool {
		if ( 'release_automation_detected' === $code ) {
			return true;
		}
		return in_array( $code, array( 'malformed_request', 'permissions_unavailable', 'package_source_changed', 'nonce_expired', 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_header_ambiguous', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' ), true );
	}

	/** @param array<string,mixed> $preview */
	private function preview( array $preview ): string {
		if ( ! is_string( $preview['repository'] ?? null ) || ! is_string( $preview['default_branch'] ?? null )
			|| ! is_string( $preview['base_sha'] ?? null ) || ! is_string( $preview['pack_version'] ?? null )
			|| ! is_array( $preview['new_template_identity'] ?? null ) || ! is_string( $preview['new_template_identity']['asset_sha256'] ?? null )
			|| ! is_array( $preview['changes'] ?? null ) ) {
			return '';
		}
		$html = '<p><strong>' . esc_html( $preview['repository'] ) . '</strong> · ' . esc_html( $preview['default_branch'] )
			. ' · <code>' . esc_html( substr( $preview['base_sha'], 0, 12 ) ) . '</code></p>';
		if ( 'template_update' === ( $preview['kind'] ?? null ) && is_array( $preview['old_template_identity'] ?? null ) ) {
			$html .= '<p>' . esc_html__( 'Template update:', 'ran-booster' ) . ' <strong>'
				. esc_html( (string) ( $preview['old_template_identity']['release_tag'] ?? '' ) ) . ' → '
				. esc_html( (string) ( $preview['new_template_identity']['release_tag'] ?? '' ) ) . '</strong></p>';
		}
		$html .= '<p>' . esc_html__( 'Template pack:', 'ran-booster' ) . ' <strong>' . esc_html( $preview['pack_version'] )
			. '</strong> · <code>' . esc_html( substr( $preview['new_template_identity']['asset_sha256'], 0, 16 ) ) . '</code></p><ul>';
		foreach ( $preview['changes'] as $change ) {
			if ( is_array( $change ) && is_string( $change['path'] ?? null ) && is_string( $change['operation'] ?? null ) && is_string( $change['sha256'] ?? null ) ) {
				$html .= '<li><code>' . esc_html( $change['path'] ) . '</code> — ' . esc_html( $change['operation'] )
					. ' — <code>' . esc_html( substr( $change['sha256'], 0, 12 ) ) . '</code></li>';
			}
		}

		return $html . '</ul>';
	}

	/** @param array<string,mixed> $form */
	private function form( array $form ): string {
		$operation      = is_string( $form['operation'] ?? null ) ? $form['operation'] : '';
		$action         = is_string( $form['action'] ?? null ) ? $form['action'] : '';
		$fields         = is_array( $form['fields'] ?? null ) ? $form['fields'] : array();
		$credentials    = is_array( $form['credentials'] ?? null ) ? $form['credentials'] : array();
		$credentialsUrl = is_string( $form['credentials_url'] ?? null ) ? $form['credentials_url'] : '';
		$anonymous      = true === ( $form['anonymous_inspection'] ?? false );
		$disabled       = true === ( $form['disabled'] ?? false );
		$confirm        = is_string( $form['confirm'] ?? null ) ? $form['confirm'] : '';
		$buttons        = array(
			'inspect'        => __( 'Assess release setup', 'ran-booster' ),
			'setup'          => __( 'Open draft pull request', 'ran-booster' ),
			'outcome'        => __( 'Check pull request outcome', 'ran-booster' ),
			'update_inspect' => __( 'Check for template updates', 'ran-booster' ),
			'update_setup'   => __( 'Open template update draft pull request', 'ran-booster' ),
		);
		if ( ! isset( $buttons[ $operation ] ) || ( ! $disabled && ( '' === $action || array() === $fields ) ) ) {
			return '';
		}
		$action = '' === $action ? admin_url( 'admin-post.php' ) : $action;
		$html   = '<form action="' . esc_url( $action ) . '" method="post" data-ran-booster-enhanced-mutation data-ran-booster-package-mutation hx-post="' . esc_url( wp_make_link_relative( $action ) ) . '" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-sync="this:drop">';
		foreach ( $fields as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		if ( '' !== $confirm ) {
			$html .= '<p><label>' . esc_html__( 'Type the exact repository name to confirm', 'ran-booster' )
				. '<br><input type="text" name="confirm_repository" required autocomplete="off" class="regular-text" placeholder="' . esc_attr( $confirm ) . '"></label></p>';
		}
		$write              = in_array( $operation, array( 'setup', 'update_setup' ), true );
		$credentialRequired = $write || ! $anonymous;
		$html              .= '<p><label>' . esc_html__( 'Saved GitHub credential', 'ran-booster' )
			. '<br><select name="booster_credential_id"' . ( $disabled ? ' disabled aria-disabled="true"' : ( $credentialRequired ? ' required' : '' ) ) . '>';
		$html              .= $anonymous && ! $write
			? '<option value="">' . esc_html__( 'Anonymous public inspection', 'ran-booster' ) . '</option>'
			: '<option value="" disabled selected>' . esc_html__( 'Choose a saved credential', 'ran-booster' ) . '</option>';
		foreach ( $credentials as $credential ) {
			if ( is_array( $credential ) && is_string( $credential['id'] ?? null ) && is_string( $credential['label'] ?? null ) ) {
				$html .= '<option value="' . esc_attr( $credential['id'] ) . '">' . esc_html( $credential['label'] ) . '</option>';
			}
		}
		$html .= '</select></label>';
		if ( '' !== $credentialsUrl ) {
			$html .= ' <a class="button" href="' . esc_url( $credentialsUrl ) . '">' . esc_html__( 'Manage credentials', 'ran-booster' ) . '</a>';
		}
		$html .= '</p>';
		$html .= '<p class="description">' . esc_html(
			$write
			? __( 'Choose a saved credential with Contents: write, Workflows: write and Pull requests: write. Its secret is never stored with this setup.', 'ran-booster' )
			: ( $anonymous
				? __( 'This public repository can be inspected anonymously, or with a saved credential for steadier API limits.', 'ran-booster' )
				: __( 'Private repository inspection needs a saved credential.', 'ran-booster' ) )
		) . '</p>';

		return $html . '<p><button type="submit" class="button' . ( $write ? ' button-primary' : '' ) . '"' . ( $disabled ? ' disabled aria-disabled="true"' : '' ) . '>'
			. esc_html( $buttons[ $operation ] ) . '</button></p></form>';
	}

	private function workflowMessage( string $code ): string {
		return match ( $code ) {
			'workflow_inspected' => __( 'Source-ready assessment passed. Review the exact template identity and changed paths.', 'ran-booster' ),
			'workflow_setup_open' => __( 'The atomic draft pull request is open. Booster did not merge or change the default branch.', 'ran-booster' ),
			'workflow_setup_recovered' => __( 'The existing exact draft pull request was recovered and verified.', 'ran-booster' ),
			'workflow_pr_open' => __( 'The recorded draft pull request remains open.', 'ran-booster' ),
			'workflow_pr_closed' => __( 'The recorded pull request was closed without a verified merge.', 'ran-booster' ),
			'workflow_pr_merged' => __( 'Booster verified that the recorded setup pull request was merged and its managed receipt is on the default branch. This does not prove a workflow ran or produced a release.', 'ran-booster' ),
			'workflow_template_current' => __( 'The managed template pack is current.', 'ran-booster' ),
			'workflow_template_update_available' => __( 'A newer compatible template pack is available. Review its exact changed paths before opening a draft.', 'ran-booster' ),
			'workflow_partial' => __( 'GitHub may have accepted only part of the request. Booster will not overwrite or repair the deterministic branch.', 'ran-booster' ),
			'workflow_unauthorised' => __( 'GitHub did not authorise the operation with the selected saved credential.', 'ran-booster' ),
			'workflow_rate_limited' => __( 'GitHub has temporarily rate-limited the release automation request. Booster made no change.', 'ran-booster' ),
			'workflow_invalid_response' => __( 'GitHub returned an incomplete or invalid response. Booster made no change.', 'ran-booster' ),
			'workflow_template_unavailable' => __( 'The canonical release template source is temporarily unavailable. Booster made no change.', 'ran-booster' ),
			'workflow_preflight_unavailable' => __( 'Booster could not validate the package release before continuing. No draft was opened.', 'ran-booster' ),
			'workflow_remote_unavailable' => __( 'GitHub or the canonical template source did not provide trustworthy current state. Booster made no change.', 'ran-booster' ),
			'workflow_release_ready' => __( 'Published releases are available, but Booster cannot tell whether a release workflow produced them.', 'ran-booster' ),
			'workflow_release_automation_conflict' => __( 'Existing release automation found. Booster will not overwrite it. Review it before using Booster setup.', 'ran-booster' ),
			'workflow_release_automation_present' => __( 'Booster verified an exact canonical release setup in this repository. No setup pull request is needed.', 'ran-booster' ),
			'workflow_release_path_conflict' => __( 'One or more files Booster would manage already exist. Review and reconcile them before setting up release automation.', 'ran-booster' ),
			'workflow_package_ambiguous' => __( 'Booster could not identify exactly one WordPress package header. Resolve the ambiguity before setting up release automation.', 'ran-booster' ),
			'workflow_version_mismatch' => __( 'The installed version does not match the repository package header. Reconcile the versions before setting up release automation.', 'ran-booster' ),
			'workflow_version_contract_custom' => __( 'Booster found version sources it cannot safely update. Review and reconcile the version contract before setting up release automation.', 'ran-booster' ),
			'workflow_runtime_paths_unknown' => __( 'Booster could not safely determine the package runtime files. Review and reconcile the package layout before setting up release automation.', 'ran-booster' ),
			'workflow_prettier_contract_custom' => __( 'Booster found a Prettier ignore contract it cannot safely change. Review and reconcile it before setting up release automation.', 'ran-booster' ),
			'workflow_repository_unsupported' => __( 'This repository does not match the supported WordPress release configuration. Review and reconcile it before setting up release automation.', 'ran-booster' ),
			'workflow_profile_missing', 'workflow_profile_modified' => __( 'The managed release profile is missing or modified. Booster made no change.', 'ran-booster' ),
			'workflow_target_changed', 'workflow_template_superseded' => __( 'The repository or template identity changed. Assess the current state again.', 'ran-booster' ),
			'workflow_invalid_request' => __( 'Booster stopped before contacting GitHub because this request no longer matched the current page or package.', 'ran-booster' ),
			default => __( 'The release automation request was refused, changed or expired. Assess the repository again.', 'ran-booster' ),
		};
	}

	private function failureDiagnosticMessage( string $diagnostic, string $stage ): string {
		return match ( $diagnostic ) {
			'release_automation_detected' => __( 'Booster found recognizable release automation in the inspected repository. It did not verify that setup as Booster-managed or prove that it produced the available releases.', 'ran-booster' ),
			'malformed_request' => __( 'The request was incomplete or malformed. Reload the release automation page and try again.', 'ran-booster' ),
			'permissions_unavailable' => __( 'Your current account no longer has the permissions required to manage this package. Sign in with an administrator account and try again.', 'ran-booster' ),
			'package_source_changed' => __( 'The saved package or source changed before Booster could act. Reload the current package state and assess it again.', 'ran-booster' ),
			'nonce_expired' => __( 'This form has expired. Reload the release automation page and try again.', 'ran-booster' ),
			'provider_unavailable' => __( "Booster could not read release data using the package's saved repository access. The credential selected for workflow setup is used only after this release check.", 'ran-booster' ),
			'preflight_contract_unavailable' => __( 'The page or request state expired or changed. Reload the page and retry.', 'ran-booster' ),
			default => $this->failureStageMessage( $stage ),
		};
	}

	private function failureStageMessage( string $stage ): string {
		return match ( $stage ) {
			'request_validation' => __( 'Booster refused the request before reading saved credentials or contacting GitHub.', 'ran-booster' ),
			'credential_authorisation' => __( 'Choose a saved GitHub credential with the required repository permissions, then retry.', 'ran-booster' ),
			'release_preflight' => __( 'Booster could not validate the package release before continuing. Review the release readiness details, then retry.', 'ran-booster' ),
			'repository_snapshot' => __( 'GitHub did not provide a trustworthy current repository snapshot. Check repository access and try the assessment again.', 'ran-booster' ),
			'template_pack' => __( 'The canonical release template pack changed or could not be verified. Assess the repository again before opening a draft.', 'ran-booster' ),
			'preview_storage' => __( 'Booster could not retain the review preview locally. No draft was opened; retry the assessment.', 'ran-booster' ),
			'repository_mutation' => __( 'GitHub may have accepted only part of the draft setup. Review the repository and use the failure reference before retrying.', 'ran-booster' ),
			'local_persistence' => __( 'The draft may exist on GitHub, but Booster could not retain its verified setup record. Review the repository and use the failure reference before retrying.', 'ran-booster' ),
			default => __( 'Booster stopped before it could verify the release-workflow request. Retry the assessment and use the failure reference when seeking support.', 'ran-booster' ),
		};
	}
}

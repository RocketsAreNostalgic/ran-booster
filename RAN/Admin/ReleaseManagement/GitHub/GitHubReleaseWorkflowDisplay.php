<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement\GitHub;

/** @internal GitHub-specific release workflow presentation. */
final class GitHubReleaseWorkflowDisplay {
	public function workflow( array $view ): string {
		$code        = is_string( $view['result_code'] ?? null ) ? $view['result_code'] : '';
		$successful  = true === ( $view['result_successful'] ?? false );
		$preview     = is_array( $view['preview'] ?? null ) ? $view['preview'] : null;
		$record      = is_array( $view['record'] ?? null ) ? $view['record'] : null;
		$legacy      = is_array( $view['legacy'] ?? null ) ? $view['legacy'] : null;
		$forms       = is_array( $view['forms'] ?? null ) ? $view['forms'] : array();
		$unavailable = true === ( $view['unavailable'] ?? false );
		$reason      = is_string( $view['unavailable_reason'] ?? null ) ? $view['unavailable_reason'] : '';
		$open        = $unavailable || null !== $preview || null !== $record || null !== $legacy || str_starts_with( $code, 'workflow_' );
		$html        = '<details class="ran-booster-release-workflow"' . ( $open ? ' open' : '' ) . '>';
		$html       .= '<summary><strong>' . esc_html__( 'Release automation', 'ran-booster' ) . '</strong></summary>';
		$html       .= '<div class="ran-booster-release-workflow__body"><p>'
			. esc_html__( 'Assess the exact source tree and immutable template pack, then open one atomic draft pull request for review.', 'ran-booster' ) . '</p>';

		if ( str_starts_with( $code, 'workflow_' ) ) {
			$html .= '<div class="notice ' . esc_attr( $successful ? 'notice-success' : 'notice-warning' ) . ' inline"'
				. ( $successful ? ' data-ran-booster-package-success' : '' ) . '><p>' . esc_html( $this->workflowMessage( $code ) ) . '</p></div>';
		}

		if ( $unavailable ) {
			$html .= '<p>' . esc_html__( 'Release automation cannot be assessed with the current package settings.', 'ran-booster' ) . '</p>';
			if ( '' !== $reason ) {
				$html .= '<p class="description">' . esc_html( $reason ) . '</p>';
			}
			$html .= '<p><button type="submit" class="button" disabled>' . esc_html__( 'Assess source-ready release setup', 'ran-booster' ) . '</button></p>';
		} elseif ( null !== $preview ) {
			$html .= $this->preview( $preview );
			$key   = 'template_update' === ( $preview['kind'] ?? null ) ? 'update_setup' : 'setup';
			$html .= $this->form( is_array( $forms[ $key ] ?? null ) ? $forms[ $key ] : array() );
		} elseif ( null === $record ) {
			$html .= $this->form( is_array( $forms['inspect'] ?? null ) ? $forms['inspect'] : array() );
		}

		if ( null !== $record && is_string( $record['repository'] ?? null ) && is_int( $record['pr_number'] ?? null ) ) {
			$url   = 'https://github.com/' . $record['repository'] . '/pull/' . $record['pr_number'];
			$html .= '<hr><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Review recorded draft pull request on GitHub', 'ran-booster' ) . '</a></p>';
			$html .= $this->form( is_array( $forms['outcome'] ?? null ) ? $forms['outcome'] : array() );
			$html .= $this->form( is_array( $forms['update_inspect'] ?? null ) ? $forms['update_inspect'] : array() );
		} elseif ( null !== $legacy ) {
			$html .= '<hr><p><strong>' . esc_html__( 'Legacy, unverified manual-reconciliation evidence.', 'ran-booster' ) . '</strong></p>';
			$html .= '<p>' . esc_html__( 'Booster cannot prove the current remote, merged or managed-file state from this earlier record. No legacy outcome, update, retry or delete action is available.', 'ran-booster' ) . '</p>';
			if ( ! isset( $legacy['unsupported'] ) && is_string( $legacy['repository'] ?? null ) && is_int( $legacy['pr_number'] ?? null ) && is_string( $legacy['setup_branch'] ?? null ) ) {
				$url   = 'https://github.com/' . $legacy['repository'] . '/pull/' . $legacy['pr_number'];
				$html .= '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Review the earlier pull request manually on GitHub', 'ran-booster' ) . '</a></p>';
				$html .= '<p><code>' . esc_html( $legacy['setup_branch'] ) . '</code></p>';
			} else {
				$html .= '<p>' . esc_html__( 'The earlier record is not authoritative for this package. Assess the current source again.', 'ran-booster' ) . '</p>';
			}
		}

		return $html . '</div></details>';
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
		$operation = is_string( $form['operation'] ?? null ) ? $form['operation'] : '';
		$action    = is_string( $form['action'] ?? null ) ? $form['action'] : '';
		$fields    = is_array( $form['fields'] ?? null ) ? $form['fields'] : array();
		$confirm   = is_string( $form['confirm'] ?? null ) ? $form['confirm'] : '';
		$buttons   = array(
			'inspect'        => __( 'Assess source-ready release setup', 'ran-booster' ),
			'setup'          => __( 'Open atomic draft pull request', 'ran-booster' ),
			'outcome'        => __( 'Check pull request outcome', 'ran-booster' ),
			'update_inspect' => __( 'Check for template updates', 'ran-booster' ),
			'update_setup'   => __( 'Open template update draft pull request', 'ran-booster' ),
		);
		if ( '' === $action || ! isset( $buttons[ $operation ] ) || array() === $fields ) {
			return '';
		}
		$html = '<form action="' . esc_url( $action ) . '" method="post" data-ran-booster-package-mutation>';
		foreach ( $fields as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		if ( '' !== $confirm ) {
			$html .= '<p><label>' . esc_html__( 'Type the exact repository name to confirm', 'ran-booster' )
				. '<br><input type="text" name="confirm_repository" required autocomplete="off" class="regular-text" placeholder="' . esc_attr( $confirm ) . '"></label></p>';
		}
		$write = in_array( $operation, array( 'setup', 'update_setup' ), true );
		$html .= '<p><label>' . esc_html__( 'Request-only GitHub token', 'ran-booster' )
			. '<br><input type="password" name="github_token" autocomplete="off" class="regular-text"' . ( $write ? ' required' : '' ) . '></label></p>';
		$html .= '<p class="description">' . esc_html(
			$write
			? __( 'Use Contents: write, Workflows: write and Pull requests: write. The token is never stored.', 'ran-booster' )
			: __( 'Public repositories need no token. Private reads need a request-only token.', 'ran-booster' )
		) . '</p>';

		return $html . '<p><button type="submit" class="button' . ( $write ? ' button-primary' : '' ) . '">'
			. esc_html( $buttons[ $operation ] ) . '</button></p></form>';
	}

	private function workflowMessage( string $code ): string {
		return match ( $code ) {
			'workflow_inspected' => __( 'Source-ready assessment passed. Review the exact template identity and changed paths.', 'ran-booster' ),
			'workflow_setup_open' => __( 'The atomic draft pull request is open. Booster did not merge or change the default branch.', 'ran-booster' ),
			'workflow_setup_recovered' => __( 'The existing exact draft pull request was recovered and verified.', 'ran-booster' ),
			'workflow_pr_open' => __( 'The recorded draft pull request remains open.', 'ran-booster' ),
			'workflow_pr_closed' => __( 'The recorded pull request was closed without a verified merge.', 'ran-booster' ),
			'workflow_pr_merged' => __( 'The pull request was merged and its exact managed receipt is on the default branch.', 'ran-booster' ),
			'workflow_template_current' => __( 'The managed template pack is current.', 'ran-booster' ),
			'workflow_template_update_available' => __( 'A newer compatible template pack is available. Review its exact changed paths before opening a draft.', 'ran-booster' ),
			'workflow_partial' => __( 'GitHub may have accepted only part of the request. Booster will not overwrite or repair the deterministic branch.', 'ran-booster' ),
			'workflow_unauthorised' => __( 'GitHub did not authorise the operation with the request-only token.', 'ran-booster' ),
			'workflow_remote_unavailable' => __( 'GitHub or the canonical template source did not provide trustworthy current state. Booster made no change.', 'ran-booster' ),
			'workflow_release_ready' => __( 'This repository already has a working published release. Bootstrap is not needed.', 'ran-booster' ),
			'workflow_release_automation_conflict' => __( 'Competing release automation was detected. Review and reconcile it before using Booster setup.', 'ran-booster' ),
			'workflow_profile_missing', 'workflow_profile_modified' => __( 'The managed release profile is missing or modified. Booster made no change.', 'ran-booster' ),
			'workflow_target_changed', 'workflow_template_superseded' => __( 'The repository or template identity changed. Assess the current state again.', 'ran-booster' ),
			default => __( 'The release automation request was refused, changed or expired. Assess the repository again.', 'ran-booster' ),
		};
	}
}

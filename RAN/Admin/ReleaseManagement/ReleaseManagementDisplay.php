<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\Feedback\FeedbackFlash;
use RAN\Admin\PackageDisplayProjection;

/** @internal Contributes release-specific state and actions to Core-owned package pages. */
final class ReleaseManagementDisplay {
	private const ELIGIBILITY_RECHECK_QUERY_KEY = 'ran_booster_release_recheck';

	private const ADMIN_POST_ACTIONS = array(
		'enable'           => 'ran_booster_release_enable',
		'refresh'          => 'ran_booster_release_refresh',
		'change_channel'   => 'ran_booster_release_change_channel',
		'return_to_branch' => 'ran_booster_release_return_to_branch',
	);


	/**
	 * @param array<string,string> $nonce_actions
	 * @param list<array{name:string,url:string,type:string,view_all?:bool}> $repository_conflict
	 * @param array{relationship:'newer'|'same'|'older',channel:'stable'|'prerelease',installed_version:string,release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,fallback_message:string,confirmed_installed_version?:string,matching_release?:array{relationship:'same',release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,confirmed_installed_version:string}}|null $switch_confirmation
	 * @param list<array{version:string,tag:string,prerelease:bool}> $browse_candidates
	 */
	public function render_settings(
		object $package,
		?ReleaseTrackingStatus $status,
		string $settings_url,
		string $selected_channel = '',
		array $nonce_actions = array(),
		bool $eligibility_recheck = false,
		string $operation_notice_html = '',
		array $repository_conflict = array(),
		bool $updates_forced_off = false,
		?array $switch_confirmation = null,
		string $browse_message = '',
		string $readiness_message = '',
		string $browse_channel = 'stable',
		array $browse_candidates = array()
	): void {
		unset( $eligibility_recheck ); // Retain the public named-parameter contract.
		if ( ! $this->is_projection( $package ) ) {
			return;
		}

		$eligibility      = $status?->eligibility();
		$status_available = null !== $status && null !== $eligibility;
		$source           = $status_available ? $status->source() : $package->source();

		$selected_channel = 'branch' === $source

			? ( $this->normalize_release_channel( $selected_channel ) ?? 'stable' )
			: ( $status_available ? $status->channel() : null );

		if ( 'branch' === $source && ( '' !== $browse_message || array() !== $browse_candidates ) ) {
			$selected_channel = $browse_channel;
		}

		$eligibility_code          = $status_available ? $eligibility->code() : '';
		$subdirectory_incompatible = 'subdirectory_not_supported' === $eligibility_code;
		$expected_update_uri       = $status_available ? $eligibility->expected_update_uri() : '';
		$update_uri_ready          = in_array( $eligibility_code, array( 'eligible', 'target_already_uses_ran_updater' ), true );

		$release_view_url  = add_query_arg( array( 'source_view' => 'release_asset' ), $settings_url );
		$recheck_url       = add_query_arg(
			array(
				self::ELIGIBILITY_RECHECK_QUERY_KEY => '1',
				'ran_booster_open_advanced'         => '1',
			),
			$release_view_url
		);
		$recheck_arguments = array();
		wp_parse_str( (string) wp_parse_url( $recheck_url, PHP_URL_QUERY ), $recheck_arguments );
		$recheck_query_position = strpos( $recheck_url, '?' );
		$recheck_action_url     = false === $recheck_query_position
			? $recheck_url
			: substr( $recheck_url, 0, $recheck_query_position );
		$track_form_id          = 'ran-booster-release-track-form';
		$track_nonce_action     = null;
		$refresh_nonce_action   = null;
		if ( $status_available && $eligibility->eligible() && 'branch' === $source ) {

			$track_nonce_action = $nonce_actions['enable'] ?? null;
		} elseif ( $status_available && 'release_asset' === $source && ! $subdirectory_incompatible ) {

			$track_nonce_action = $nonce_actions['change_channel'] ?? null;

			$refresh_nonce_action = $nonce_actions['refresh'] ?? null;
		}
		$track_mode                = 'branch' === $source ? 'branch' : 'managed';
		$failure_code              = $status_available ? $status->failure_code() : '';
		$repository_blocker        = ReleaseTrackingOperations::repository_blocker( $status );
		$repository_conflict_code  = 'release_repository_conflict' === $repository_blocker;
		$repository_source_blocked = 'repository_source_unavailable' === $repository_blocker;
		$repository_blocked        = '' !== $repository_blocker;
		$track_disabled            = null === $track_nonce_action || $repository_blocked;
		$policy_disabled           = $status_available && 'branch' === $source && 'disabled' === $status->deployment_policy();
		$switch_blocked            = $track_disabled || $updates_forced_off || $policy_disabled;
		$preflight_nonces          = array(
			'stable'     => (string) ( $nonce_actions['preflight_stable'] ?? '' ),
			'prerelease' => (string) ( $nonce_actions['preflight_prerelease'] ?? '' ),
		);
		$preflight_action          = ! $switch_blocked && 'branch' === $source && '' !== $preflight_nonces['stable'] && '' !== $preflight_nonces['prerelease']
			? 'ran_booster_release_switch_preflight'
			: null;
		$repository_status_heading = $repository_source_blocked ? __( 'Storage unavailable', 'ran-booster' ) : __( 'Repository shared', 'ran-booster' );
		$automatic_policy          = $status_available && 'automatic' === $status->deployment_policy();
		$recheck_enabled           = $status_available && 'branch' === $source;
		$refresh_enabled           = null !== $refresh_nonce_action && ! $repository_blocked;
		$recheck_reason            = ! $status_available
			? __( 'Available once release status is available.', 'ran-booster' )
			: __( 'Not needed while releases are tracked.', 'ran-booster' );
		$browser_enabled           = $status_available
			&& 'release_asset' === $source
			&& $eligibility->eligible()

			&& '' !== ( $nonce_actions['list_candidates'] ?? '' )

			&& '' !== ( $nonce_actions['inspect_candidate'] ?? '' )
			&& ! $repository_blocked;
		$offered_version       = $status_available && ! $updates_forced_off ? ManagedReleaseBrowserOperations::persisted_offer_version( $status ) : '';
		$status_notice_visible = $status_available && 'release_asset' === $source && '' !== $failure_code && ! $repository_blocked && ! $this->eligibility_explains_failure( $status );
		$release_unchecked     = $status_available && 'release_asset' === $source && '' === $failure_code && '' === $status->last_checked_at() && '' === $offered_version && ! $status->update_available() && ! $updates_forced_off;
		$gate_notice           = $repository_conflict_code
			? __( 'Releases require exclusive use of this repository. Stop managing the other packages in Booster before switching; their files can stay installed.', 'ran-booster' )
			: ( $repository_source_blocked
				? __( 'Booster could not safely read this package\'s repository storage. Check package storage and retry.', 'ran-booster' )
				: ( ! $status_available
					? __( 'Published release status is temporarily unavailable. Try again.', 'ran-booster' )
					: ( ! $eligibility->eligible()
						? ( 'branch' === $source && $this->requires_update_uri_remediation( $eligibility )
							? __( 'Published releases require an Update URI matching this repository. Use the header shown below, then recheck eligibility.', 'ran-booster' )
							: $this->eligibility_message( $eligibility, $source ) )
						: ( 'branch' === $source && $track_disabled
							? __( 'Published releases cannot be selected because source transition controls are temporarily unavailable.', 'ran-booster' )
							: '' ) ) ) );
		$release_track_summary = null === $selected_channel ? __( 'Unknown', 'ran-booster' ) : $this->release_track_label( $selected_channel );
		?>
		<section class="ran-booster-release-management" aria-labelledby="ran-booster-release-management-heading">
			<?php ob_start(); ?>
				<?php ob_start(); ?>
				<?php if ( '' !== $gate_notice ) { ?>
					<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-release-gate-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php echo esc_html( $gate_notice ); ?></p>
					<?php

					if ( $repository_conflict_code && array() !== $repository_conflict ) {
						?>
						<p><strong><?php esc_html_e( 'Conflicting packages', 'ran-booster' ); ?></strong></p><ul class="ul-disc">
						<?php

						foreach ( $repository_conflict as $other ) {
							?>
						<li><a href="<?php echo esc_url( (string) ( $other['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $other['name'] ?? '' ) ); ?></a>
							<?php
							if ( empty( $other['view_all'] ) ) {
								?>
							(<?php echo esc_html( (string) ( $other['type'] ?? '' ) ); ?>)<?php } ?></li><?php } ?></ul><?php } ?></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
				<?php } ?>
				<?php $gate_notice_html = trim( (string) ob_get_clean() ); ?>
				<?php if ( 'branch' !== $source ) { ?>
					<?php echo $gate_notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured markup rendered by this class. ?>
				<?php } ?>
				<?php if ( '' !== $operation_notice_html || $status_notice_visible ) { ?>
					<div class="ran-booster-release-notices" data-ran-booster-release-notices>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Retain the public named-parameter contract. Captured trusted admin operation notice. ?>
						<?php echo $operation_notice_html; ?>
						<?php if ( $status_notice_visible ) { ?>
							<?php $status_reason = null === $status->preflight() ? '' : $status->preflight()->reason_code(); ?>
							<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-release-status-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
								<p><?php echo esc_html( $this->diagnostic_message( $failure_code, $status_reason ) ); ?></p>
							</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?>
							<?php $this->render_failure_help( $failure_code, '', $status_reason ); ?></div>
						<?php } ?>
					</div>
				<?php } ?>
				<?php if ( 'branch' === $source ) { ?>
					<?php $this->render_use_releases_bar( $track_disabled, $release_track_summary, $track_form_id, $updates_forced_off, $policy_disabled ); ?>
					<?php if ( null !== $switch_confirmation && null !== $track_nonce_action && ! $switch_blocked ) { ?>
						<?php $this->render_switch_confirmation( $package, $switch_confirmation, $track_nonce_action, $release_view_url ); ?>
					<?php } ?>
					<?php if ( $automatic_policy ) { ?>
						<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
							<p><?php esc_html_e( 'Switching resets Automatic to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
						</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
					<?php } ?>
					<?php echo $gate_notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured markup rendered by this class. ?>
				<?php } elseif ( $status_available && 'release_asset' === $source && $subdirectory_incompatible ) { ?>
					<?php $this->render_return_to_branch( $package, $nonce_actions['return_to_branch'] ?? null, '', $automatic_policy ); ?>
				<?php } ?>
			<?php $pane_header = trim( (string) ob_get_clean() ); ?>
			<?php if ( '' !== $pane_header ) { ?>
				<header class="ran-booster-package-source-pane__header">
					<?php echo $pane_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured markup rendered by this class. ?>
				</header>
			<?php } ?>
			<div class="ran-booster-readiness-panel">

				<div class="ran-booster-readiness-panel__top">
					<div>
						<h3 id="ran-booster-release-management-heading"><?php esc_html_e( 'Release readiness', 'ran-booster' ); ?></h3>
					</div>
					<?php $readiness_ready = ! $repository_blocked && $status_available && $eligibility->eligible(); ?>
					<span id="ran-booster-release-readiness-status"><?php echo \RAN\Admin\Component\StatusBadge::for_intent( $repository_blocked ? $repository_status_heading : ( $readiness_ready ? __( 'Ready', 'ran-booster' ) : __( 'Not ready', 'ran-booster' ) ), $readiness_ready ? 'stable' : 'high' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></span>
				</div>
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item <?php echo $status_available && ! $subdirectory_incompatible && $update_uri_ready ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Installed identity and Update URI', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( ! $status_available ? __( 'Cannot be checked until release status is available.', 'ran-booster' ) : ( $subdirectory_incompatible ? __( 'This package uses a repository subdirectory. Published releases require the repository root.', 'ran-booster' ) : ( $update_uri_ready ? __( 'Package identity and Update URI match the repository.', 'ran-booster' ) : $this->update_uri_readiness_message( $eligibility_code ) ) ) ); ?></span>
					</li>
					<li class="ran-booster-readiness-item <?php echo $status_available && 'release_asset' === $source && '' === $status->failure_code() ? ( $release_unchecked ? 'is-neutral' : 'is-ok' ) : ( $status_available && 'branch' === $source && ! $repository_blocked ? 'is-neutral' : 'is-warning' ); ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Release status', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( ! $status_available ? __( 'Cannot be checked until release status is available.', 'ran-booster' ) : ( 'release_asset' === $source ? ( $release_unchecked && 'disabled' !== $status->deployment_policy() ? __( 'Use Check releases to look for a newer version.', 'ran-booster' ) : $this->release_status_message( $status, $offered_version, $updates_forced_off ) ) : __( 'Branch is active for updates.', 'ran-booster' ) . ' ' . ( '' !== $readiness_message ? $readiness_message : __( 'Use Recheck eligibility to check published versions on Stable and Preview.', 'ran-booster' ) ) ) ); ?></span>
					</li>
				</ul>
				<?php ob_start(); ?>
					<?php if ( $status_available && 'branch' === $source && ! $eligibility->eligible() && $this->requires_update_uri_remediation( $eligibility ) ) { ?>
						<div class="ran-booster-settings-section__body ran-booster-release-remediation">
							<p><strong><?php esc_html_e( 'Add this exact header, deploy the corrected package, then check again:', 'ran-booster' ); ?></strong></p>
							<p class="ran-booster-release-code"><code><?php echo esc_html( 'Update URI: ' . $expected_update_uri ); ?></code></p>
						</div>
					<?php } elseif ( $status_available && 'release_asset' === $source && '' === $failure_code && ! $browser_enabled && ! $repository_blocked ) { ?>
						<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Published release browsing is unavailable until the current package status and controls are available.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
					<?php } elseif ( $status_available && $track_disabled && 'release_asset' === $source && '' === $failure_code && ! $repository_blocked ) { ?>
						<?php $this->render_controls_unavailable(); ?>
					<?php } ?>
				<?php $panel_notices = trim( (string) ob_get_clean() ); ?>
				<?php if ( '' !== $panel_notices ) { ?>
					<div class="ran-booster-release-notices" data-ran-booster-release-notices>
						<?php echo $panel_notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured markup rendered by this class. ?>
					</div>
				<?php } ?>
				<div class="ran-booster-readiness-actions">
					<form
						action="<?php echo esc_url( $recheck_action_url ); ?>"
						method="get"
						class="ran-booster-release-recheck-form"
						<?php if ( $recheck_enabled ) { ?>
							data-ran-booster-enhanced-mutation
							data-ran-booster-package-mutation
							data-ran-booster-error-target="#ran-booster-package-mutation-error"
							hx-get="<?php echo esc_url( wp_make_link_relative( $recheck_url ) ); ?>"
							hx-target="#wpbody-content"
							hx-select="#wpbody-content"
							hx-swap="outerHTML show:#ran-booster-advanced-source-settings:top"
							hx-push-url="<?php echo esc_url( wp_make_link_relative( $release_view_url ) ); ?>"
							hx-sync="this:drop"
						<?php } ?>
					>
						<?php foreach ( $recheck_arguments as $name => $value ) { ?>
							<?php if ( is_scalar( $value ) ) { ?>
								<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
							<?php } ?>
						<?php } ?>
						<?php foreach ( array( 'stable', 'prerelease' ) as $channel ) { ?>
							<input type="hidden" name="_ran_booster_release_list_nonce[<?php echo esc_attr( $channel ); ?>]" value="<?php echo esc_attr( $nonce_actions[ 'branch_list_' . $channel ] ?? '' ); ?>">
						<?php } ?>
						<?php if ( $recheck_enabled ) { ?>
							<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" aria-disabled="false" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Rechecking…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Rechecking eligibility. This can take a few seconds.', 'ran-booster' ) ); ?>"><?php esc_html_e( 'Recheck eligibility', 'ran-booster' ); ?></button>
						<?php } else { ?>
							<span class="ran-booster-reason-tooltip" data-ran-booster-reason-tooltip>
								<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" aria-disabled="true" aria-describedby="ran-booster-release-recheck-reason" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Rechecking…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Rechecking eligibility. This can take a few seconds.', 'ran-booster' ) ); ?>"><?php esc_html_e( 'Recheck eligibility', 'ran-booster' ); ?></button>
								<span id="ran-booster-release-recheck-reason" class="ran-booster-reason-tooltip__bubble" role="tooltip" hidden><?php echo esc_html( $recheck_reason ); ?></span>
							</span>
						<?php } ?>
						<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					</form>
					<?php if ( 'release_asset' === $source ) { ?>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"<?php echo $refresh_enabled ? ' data-ran-booster-package-mutation' : ''; ?>>
							<?php if ( $refresh_enabled ) { ?>
								<?php $this->admin_post_fields( 'refresh', $package, $refresh_nonce_action ); ?>
								<input type="hidden" name="return_to_settings" value="1">
							<?php } ?>
							<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"<?php disabled( ! $refresh_enabled ); ?> aria-disabled="<?php echo $refresh_enabled ? 'false' : 'true'; ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Checking…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Checking for published releases. This can take a few seconds.', 'ran-booster' ) ); ?>"><?php esc_html_e( 'Check releases', 'ran-booster' ); ?></button>
							<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
						</form>
					<?php } ?>
					<?php
					ob_start();
					if ( $status_available ) {
						do_action( 'ran_booster_admin_package_release_readiness_actions', $package, $status );
					}
					$add_on_actions = trim( (string) ob_get_clean() );
					?>
					<?php if ( '' !== $add_on_actions ) { ?>
						<span class="ran-booster-readiness-actions__links">
							<?php echo $add_on_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-on hook output is rendered as-is, as before. ?>
						</span>
					<?php } ?>
				</div>
			</div>
		</section>
		<div data-ran-booster-ui-disclosure="package-release-track" data-ran-booster-ui-disclosure-density="prominent">
		<details id="ran-booster-release-track-settings" class="ran-booster-settings-disclosure ran-booster-release-track-section ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback data-ran-booster-package-disclosure<?php echo '' !== $browse_message || array() !== $browse_candidates ? ' open' : ''; ?>>
			<summary>
				<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></h3>
				<small class="ran-booster-advanced-source-summary" data-ran-booster-ui-disclosure-meta>
					<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $release_track_summary, 'none', array( 'data-ran-booster-release-track-summary' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
				</small>
			</summary>
			<div data-ran-booster-ui-disclosure-content class="ran-booster-settings-disclosure__body ran-booster-disclosure__body">
				<?php $this->render_release_track_settings( $track_mode, $track_disabled, $selected_channel, $package, $track_nonce_action, $track_form_id, $preflight_action, $preflight_nonces ); ?>
				<?php
				$branch_browser = 'branch' === $source && $status_available && ! $track_disabled && ! $repository_blocked && ! $subdirectory_incompatible && ! empty( $nonce_actions['branch_list_stable'] ) && ! empty( $nonce_actions['branch_list_prerelease'] );
				if ( $branch_browser || 'release_asset' === $source ) {
					$this->render_managed_candidate_browser( $status, $nonce_actions, $branch_browser || $browser_enabled, $branch_browser ? $selected_channel : null );
				}
				?>
				<?php if ( $branch_browser || '' !== $browse_message || array() !== $browse_candidates ) { ?>
				<noscript>
					<?php if ( $branch_browser ) { ?>
					<form action="<?php echo esc_url( $recheck_action_url ); ?>" method="get">
							<?php
							foreach ( $recheck_arguments as $name => $value ) {
								if ( is_scalar( $value ) && self::ELIGIBILITY_RECHECK_QUERY_KEY !== $name ) {
									?>
								<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
														<?php
								}
							}
							?>
							<?php foreach ( array( 'stable', 'prerelease' ) as $channel ) { ?>
								<input type="hidden" name="_ran_booster_release_list_nonce[<?php echo esc_attr( $channel ); ?>]" value="<?php echo esc_attr( $nonce_actions[ 'branch_list_' . $channel ] ); ?>">
							<?php } ?>
							<?php foreach ( array( 'stable', 'prerelease' ) as $channel ) { ?>
								<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" name="ran_booster_release_list" value="<?php echo esc_attr( $channel ); ?>"><?php echo esc_html( $this->release_track_label( $channel ) ); ?></button>
							<?php } ?>
						</form>
					<?php } ?>
					<?php if ( array() !== $browse_candidates ) { ?>
						<fieldset class="ran-booster-release-candidates" disabled>
							<legend><?php esc_html_e( 'Available published releases', 'ran-booster' ); ?></legend>
							<?php foreach ( $browse_candidates as $candidate ) { ?>
								<label class="ran-booster-release-candidate">
									<input type="radio" name="ran_booster_browse_candidate" disabled>
									<span><?php echo esc_html( sprintf( /* translators: 1: release version, 2: release tag, 3: release channel label. */ __( '%1$s (%2$s) · %3$s', 'ran-booster' ), $candidate['version'], $candidate['tag'], $this->release_track_label( $candidate['prerelease'] ? 'prerelease' : 'stable' ) ) ); ?></span>
								</label>
							<?php } ?>
						</fieldset>
					<?php } ?>
					<?php if ( '' !== $browse_message ) { ?>
						<p role="status"><?php echo esc_html( $browse_message ); ?></p>
					<?php } ?>
					</noscript>
				<?php } ?>
			</div>
		</details></div>
		<?php
	}

	/**
	 * Source switch bar for the Releases pane while Branch is active: the state
	 * on the left, one action at the end. The action keeps its association with
	 * the release track form, which lives further down the pane.
	 */
	private function render_use_releases_bar(
		bool $unavailable,
		string $track_label,
		string $track_form_id,
		bool $updates_forced_off = false,
		bool $policy_disabled = false
	): void {
		/* translators: %s is the selected release track, Stable or Preview. */
		$eligible_template   = __( 'Branch currently active. Switch to releases using the %s track.', 'ran-booster' );
		$unavailable_message = __( 'Releases aren’t ready. Fix the readiness checks below.', 'ran-booster' );
		$summary_text        = sprintf( $eligible_template, $track_label );
		$tooltip_text        = __( 'Available once the readiness checks pass.', 'ran-booster' );
		$blocked             = $unavailable || $updates_forced_off || $policy_disabled;
		if ( $unavailable ) {
			$summary_text = $unavailable_message;
		} elseif ( $updates_forced_off ) {
			$summary_text        = __( 'Updates are turned off by this site\'s configuration, so this package can\'t switch to releases.', 'ran-booster' );
			$unavailable_message = $summary_text;
			$tooltip_text        = $summary_text;
		} elseif ( $policy_disabled ) {
			$summary_text        = __( 'Updates are disabled for this package. Set Updates to Manual to switch.', 'ran-booster' );
			$unavailable_message = $summary_text;
			$tooltip_text        = $summary_text;
		}
		$reason_id = 'ran-booster-release-use-releases-reason';
		?>
		<div class="ran-booster-source-switch-bar ran-booster-release-switch-actions" data-ran-booster-source-switch-bar="branch">
			<p class="ran-booster-release-switch-actions__summary" data-ran-booster-use-releases-summary="<?php echo esc_attr( $eligible_template ); ?>" data-ran-booster-use-releases-summary-unavailable="<?php echo esc_attr( $unavailable_message ); ?>"><?php echo esc_html( $summary_text ); ?></p>
			<span class="ran-booster-reason-tooltip"<?php echo $blocked ? ' data-ran-booster-reason-tooltip' : ''; ?>>
				<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Switching…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Switching to published releases. This can take a few seconds.', 'ran-booster' ) ); ?>" form="<?php echo esc_attr( $track_form_id ); ?>" data-ran-booster-use-releases data-ran-booster-use-releases-ready="<?php echo $blocked ? 'false' : 'true'; ?>" aria-disabled="<?php echo $blocked ? 'true' : 'false'; ?>"<?php echo $blocked ? ' aria-describedby="' . esc_attr( $reason_id ) . '"' : ''; ?>><?php esc_html_e( 'Use releases', 'ran-booster' ); ?></button>
				<span id="<?php echo esc_attr( $reason_id ); ?>" class="ran-booster-reason-tooltip__bubble" role="tooltip" data-ran-booster-use-releases-reason hidden><?php echo esc_html( $tooltip_text ); ?></span>
			</span>
		</div>
		<?php
	}

	/**
	 * Inline fallback confirmation step when the switch runs without JavaScript.
	 *
	 * @param array{relationship:'newer'|'same'|'older',channel:'stable'|'prerelease',installed_version:string,release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,fallback_message:string,confirmed_installed_version?:string,matching_release?:array{relationship:'same',release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,confirmed_installed_version:string}} $confirmation
	 */
	private function render_switch_confirmation(
		PackageDisplayProjection $package,
		array $confirmation,
		string $nonce_action,
		string $cancel_url
	): void {
		$installed        = $this->bounded_string( $confirmation['installed_version'], 64 );
		$release          = $this->bounded_string( $confirmation['release_version'], 64 );
		$relationship     = $confirmation['relationship'];
		$installed_choice = ! empty( $confirmation['confirmed_installed_version'] );
		$version_line     = '' !== $installed && '' !== $release
			/* translators: 1: currently installed version, 2: published release version to install. */
			? sprintf( __( 'Installed: %1$s. Latest eligible release: %2$s.', 'ran-booster' ), $installed, $release )
			: ( '' !== $release
				/* translators: %s: published release version to install. */
				? sprintf( __( 'Latest eligible release: %s.', 'ran-booster' ), $release )
				: __( 'Branch currently active.', 'ran-booster' ) );
		?>
		<div class="ran-booster-switch-confirmation" data-ran-booster-switch-confirmation role="region" aria-labelledby="ran-booster-switch-confirmation-heading">
			<h4 id="ran-booster-switch-confirmation-heading" class="ran-booster-switch-confirmation__title"><?php esc_html_e( 'Switch to releases?', 'ran-booster' ); ?></h4>
			<p class="ran-booster-switch-confirmation__versions"><?php echo esc_html( $version_line ); ?></p>
			<?php if ( '' !== $confirmation['fallback_message'] ) { ?>
				<p class="ran-booster-switch-confirmation__note"><?php echo esc_html( $confirmation['fallback_message'] ); ?></p>
			<?php } ?>
			<p class="ran-booster-switch-confirmation__database"><?php esc_html_e( 'Installs immediately. After switching, Updates will be set to Manual.', 'ran-booster' ); ?></p>
			<?php if ( 'same' === $relationship || ! empty( $confirmation['matching_release'] ) ) { ?>
				<p class="ran-booster-switch-confirmation__note"><?php esc_html_e( 'Same version: this replaces the installed files with the release.', 'ran-booster' ); ?></p>
			<?php } elseif ( 'older' === $relationship ) { ?>
				<p class="ran-booster-switch-confirmation__note"><?php esc_html_e( 'This release is older than what\'s installed. Switching replaces the code but cannot roll back database changes. Have a recent database backup ready.', 'ran-booster' ); ?></p>
			<?php } ?>
			<div class="ran-booster-switch-confirmation__actions">
				<a href="<?php echo esc_url( $cancel_url . '#ran-booster-advanced-source-settings' ); ?>" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::for( 'neutral', 'outline', false, true ) ); ?>"><?php esc_html_e( 'Cancel', 'ran-booster' ); ?></a>
				<?php
				$choices = array();
				if ( ! empty( $confirmation['matching_release'] ) && is_array( $confirmation['matching_release'] ) ) {
					$choices[] = $confirmation['matching_release'];
				}
				$choices[] = $confirmation;
				foreach ( $choices as $choice ) {
					$choice_relationship = $choice['relationship'];
					$choice_installed    = 'same' === $choice_relationship;
					$choice_version      = $this->bounded_string( $choice['release_version'], 64 );
					?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ran-booster-switch-confirmation__form" data-ran-booster-source-transition data-ran-booster-package-mutation>
						<?php $this->admin_post_fields( 'enable', $package, $nonce_action ); ?>
						<input type="hidden" name="release_channel" value="<?php echo esc_attr( $confirmation['channel'] ); ?>">
						<input type="hidden" name="confirm_switch" value="1">
						<input type="hidden" name="switch_release_choice" value="<?php echo $choice_installed ? 'installed' : 'latest'; ?>">
						<?php if ( ! empty( $choice['confirmed_installed_version'] ) ) { ?>
							<input type="hidden" name="confirmed_installed_version" value="<?php echo esc_attr( $choice['confirmed_installed_version'] ); ?>">
						<?php } ?>
						<input type="hidden" name="confirmed_release_identity" value="<?php echo esc_attr( $choice['release_identity'] ); ?>">
						<input type="hidden" name="confirmed_release_tag" value="<?php echo esc_attr( $choice['release_tag'] ); ?>">
						<input type="hidden" name="confirmed_release_version" value="<?php echo esc_attr( $choice['release_version'] ); ?>">
						<input type="hidden" name="confirmed_release_nonce" value="<?php echo esc_attr( $choice['confirmed_release_nonce'] ); ?>">
						<input type="hidden" name="confirmed_release_older" value="<?php echo esc_attr( 'older' === $choice_relationship ? '1' : '0' ); ?>">
						<?php if ( 'older' === $choice_relationship ) { ?>
							<label class="ran-booster-switch-confirmation__consent">
								<input type="checkbox" name="confirm_older_release" value="1" required>
								<span><?php esc_html_e( 'I understand the database won\'t be rolled back.', 'ran-booster' ); ?></span>
							</label>
						<?php } ?>
						<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::for( 'neutral', 'outline', false, true ) ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Switching…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Switching to published releases. This can take a few seconds.', 'ran-booster' ) ); ?>">
						<?php
						/* translators: %s: release version to install. */
						$label = 'same' === $choice_relationship ? __( 'Reinstall %s (current)', 'ran-booster' ) : ( 'newer' === $choice_relationship ? __( 'Upgrade to %s (latest)', 'ran-booster' ) : __( 'Install %s (latest)', 'ran-booster' ) );
						echo esc_html( sprintf( $label, $choice_version ) );
						?>
						</button>
						<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					</form>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/** @param array<string,string> $nonce_actions */
	private function render_managed_candidate_browser( ?ReleaseTrackingStatus $status, array $nonce_actions, bool $enabled, ?string $branch_channel = null ): void {
		$list_nonce      = $enabled ? ( $nonce_actions['list_candidates'] ?? '' ) : '';
		$inspect_nonce   = $enabled ? ( $nonce_actions['inspect_candidate'] ?? '' ) : '';
		$screen_is_theme = null !== $status && 'theme' === $status->type();
		$screen_file     = $screen_is_theme ? 'themes.php' : 'plugins.php';
		$screen_cap      = $screen_is_theme ? ( is_multisite() ? 'manage_network_themes' : 'switch_themes' ) : ( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' );
		$screen_url      = null === $status || ! current_user_can( $screen_cap ) ? '' : ( is_multisite() ? network_admin_url( $screen_file ) : admin_url( $screen_file ) );
		?>
		<div
			class="ran-booster-managed-release-browser<?php echo null !== $branch_channel ? ' hide-if-no-js' : ''; ?><?php echo $enabled ? '' : ' is-disabled'; ?>"
			data-ran-booster-managed-release-browser
			data-ran-booster-managed-release-browser-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"
			<?php if ( $enabled && null !== $status ) { ?>
			data-ran-booster-managed-release-type="<?php echo esc_attr( $status->type() ); ?>"
			data-ran-booster-managed-release-identifier="<?php echo esc_attr( $status->identifier() ); ?>"
			data-ran-booster-managed-release-revision="<?php echo esc_attr( (string) $status->source_revision() ); ?>"
			data-ran-booster-managed-release-channel="<?php echo esc_attr( $branch_channel ?? $status->channel() ); ?>"
			data-ran-booster-managed-release-list-nonce="<?php echo esc_attr( null !== $branch_channel ? $nonce_actions[ 'branch_list_' . $branch_channel ] : $list_nonce ); ?>"
				<?php if ( null !== $branch_channel ) { ?>
				data-ran-booster-branch-stable="<?php echo esc_attr( $nonce_actions['branch_list_stable'] ); ?>"
				data-ran-booster-branch-preview="<?php echo esc_attr( $nonce_actions['branch_list_prerelease'] ); ?>"
				<?php } else { ?>
				data-ran-booster-managed-release-inspect-nonce="<?php echo esc_attr( $inspect_nonce ); ?>"
				<?php } ?>
			data-ran-booster-managed-release-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-ran-booster-managed-release-wordpress-url="<?php echo esc_url( $screen_url ); ?>"
			<?php } ?>
		>
			<div class="ran-booster-managed-release-browser__header">
				<h4><?php echo null !== $branch_channel ? esc_html__( 'Releases on the selected track', 'ran-booster' ) : esc_html__( 'Releases on the saved track', 'ran-booster' ); ?></h4>
				<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" data-ran-booster-managed-release-retry<?php disabled( ! $enabled ); ?> aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Refresh releases', 'ran-booster' ); ?></button>
			</div>
			<div class="notice notice-warning inline ran-booster-managed-release-browser__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-managed-release-error hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
				<p data-ran-booster-managed-release-error-message></p>
			</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
			<div class="ran-booster-release-candidates ran-booster-release-candidates--pending" aria-hidden="true" data-ran-booster-managed-release-placeholder>
				<p class="ran-booster-release-candidates__heading"><?php esc_html_e( 'Available published releases', 'ran-booster' ); ?></p>
				<label class="ran-booster-release-candidate ran-booster-release-candidate--placeholder">
					<input type="radio" disabled tabindex="-1">
					<span class="ran-booster-release-candidate__skeleton"></span>
				</label>
				<label class="ran-booster-release-candidate ran-booster-release-candidate--placeholder">
					<input type="radio" disabled tabindex="-1">
					<span class="ran-booster-release-candidate__skeleton"></span>
				</label>
				<p class="ran-booster-release-candidates__note"><?php echo null !== $branch_channel ? esc_html__( 'Checking published releases…', 'ran-booster' ) : esc_html__( 'Published releases appear here once releases are in use.', 'ran-booster' ); ?></p>
			</div>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-managed-release-candidates hidden>
				<legend class="screen-reader-text"><?php esc_html_e( 'Available published releases', 'ran-booster' ); ?></legend>
				<div data-ran-booster-managed-release-candidate-list></div>
			</fieldset>
			<?php if ( $enabled && null === $branch_channel ) { ?>
				<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" hidden aria-disabled="true" data-ran-booster-managed-release-review><?php esc_html_e( 'Review selected release', 'ran-booster' ); ?></button>
			<?php } ?>
			<div class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-managed-release-status>
				<h4 data-ran-booster-managed-release-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
				<p data-ran-booster-managed-release-message></p>
			</div>
			<div class="description" data-ran-booster-release-observations role="status" aria-live="polite" hidden></div>
		</div>
		<?php
	}

	/** Renders the current native offer in Package operations, including when scripts are unavailable. */
	public function render_native_update_action( ?ReleaseTrackingStatus $status ): void {
		$version = null === $status ? '' : ManagedReleaseBrowserOperations::persisted_offer_version( $status );
		$url     = null !== $status && '' !== $version ? $this->native_update_url( $status ) : '';
		$class   = \RAN\Admin\Component\ButtonClasses::secondary( false, true );
		if ( '' !== $url ) {
			/* translators: %s: release version. */
			$label = sprintf( __( 'Update to %s', 'ran-booster' ), $version );
			?>
			<a class="<?php echo esc_attr( $class . ' ran-booster-managed-release-native-update' ); ?>" href="<?php echo esc_url( $url ); ?>" data-ran-booster-managed-release-native-update><?php echo esc_html( $label ); ?></a>
			<?php
		} else {
			?>
			<a class="<?php echo esc_attr( $class . ' disabled ran-booster-managed-release-native-update' ); ?>" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update><?php esc_html_e( 'Update now', 'ran-booster' ); ?></a>
			<?php
		}
	}

	private function native_update_url( ReleaseTrackingStatus $status ): string {
		$type       = $status->type();
		$identifier = $status->identifier();
		if ( 'plugin' === $type ) {
			if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'update_plugins' ) ) {
				return '';
			}
			return wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'update-selected',
						'plugins' => rawurlencode( $identifier ),
					),
					self_admin_url( 'plugins.php' )
				),
				'bulk-plugins'
			);
		}

		if ( 'theme' !== $type || ! current_user_can( 'update_themes' ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'upgrade-theme',
					'theme'  => rawurlencode( $identifier ),
				),
				self_admin_url( 'update.php' )
			),
			'upgrade-theme_' . $identifier
		);
	}


	/**
	 * @param array<string,string> $nonce_actions
	 * @param array{providers?:list<string>,list_candidates?:string,inspect?:string,install?:string} $prospective
	 * @param list<array{name:string,url:string,type:string,view_all?:bool}> $repository_conflict
	 * @param array{relationship:'newer'|'same'|'older',channel:'stable'|'prerelease',installed_version:string,release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,fallback_message:string,confirmed_installed_version?:string,matching_release?:array{relationship:'same',release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,confirmed_installed_version:string}}|null $switch_confirmation
	 * @param list<array{version:string,tag:string,prerelease:bool}> $browse_candidates
	 */
	public function render_advanced_source_section(
		string $mode,
		string $type,
		string $selected_source,
		?object $package,
		?ReleaseTrackingStatus $status,
		string $page_url,
		string $selected_channel = '',
		array $nonce_actions = array(),
		array $prospective = array(),
		bool $eligibility_recheck = false,
		string $operation_notice_html = '',
		array $repository_conflict = array(),
		bool $updates_forced_off = false,
		?array $switch_confirmation = null,
		string $browse_message = '',
		string $readiness_message = '',
		string $browse_channel = 'stable',
		array $browse_candidates = array()
	): void {
		if ( ! in_array( $mode, array( 'create', 'edit' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( 'create' === $mode ) {
			$this->render_prospective_setup( $type, $prospective );
			return;
		}
		if ( null === $package ) {
			return;
		}

		if ( 'release_asset' === $selected_source ) {

			$this->render_settings( $package, $status, $page_url, $selected_channel, $nonce_actions, $eligibility_recheck, $operation_notice_html, $repository_conflict, $updates_forced_off, $switch_confirmation, $browse_message, $readiness_message, $browse_channel, $browse_candidates );

		} elseif ( 'branch' === $selected_source && $this->is_projection( $package ) ) {
			if ( 'release_asset' === $package->source() ) {

				$this->render_return_to_branch( $package, $nonce_actions['return_to_branch'] ?? null, $operation_notice_html, null !== $status && 'automatic' === $status->deployment_policy() );

			} elseif ( '' !== $operation_notice_html ) {
				?>
				<div class="ran-booster-release-notices" data-ran-booster-release-notices>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Retain the public named-parameter contract. Captured trusted admin operation notice. ?>
					<?php echo $operation_notice_html; ?>
				</div>
				<?php
			}
		}
	}

	private function render_return_to_branch( PackageDisplayProjection $package, ?string $nonce_action, string $operation_notice_html = '', bool $automatic_policy = false ): void {
		$enabled = null !== $nonce_action && 'release_asset' === $package->source();
		?>
		<div class="ran-booster-release-return">
			<div class="ran-booster-release-notices" data-ran-booster-release-notices>
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured trusted admin operation notice. ?>
				<?php echo $operation_notice_html; ?>
				<?php if ( ! $enabled ) { ?>
					<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Branch cannot be selected because source transition controls are temporarily unavailable. No package settings were changed.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
				<?php } ?>
			</div>
			<?php if ( $automatic_policy ) { ?>
				<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
					<p><?php esc_html_e( 'Returning resets Automatic to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
				</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
			<?php } ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ran-booster-source-switch-bar ran-booster-release-return-form ran-booster-source-transition" data-ran-booster-source-transition<?php echo $enabled ? ' data-ran-booster-package-mutation' : ''; ?>>
				<?php if ( $enabled ) { ?>
					<?php $this->admin_post_fields( 'return_to_branch', $package, $nonce_action ); ?>
				<?php } ?>
				<p><?php esc_html_e( 'Releases currently active.', 'ran-booster' ); ?></p>
				<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"<?php disabled( ! $enabled ); ?> aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Use branch', 'ran-booster' ); ?></button>
			</form>
		</div>
		<?php
	}


	public function advanced_source_summary(
		string $fallback,
		string $mode,
		string $selected_source,
		?object $package,
		?ReleaseTrackingStatus $status
	): string {
		if ( 'create' === $mode ) {

			return 'release_asset' === $selected_source
				? __( 'Releases · Stable', 'ran-booster' )
				: $fallback;
		}
		if ( null === $package ) {
			return $fallback;
		}
		if ( null === $status ) {
			return $fallback;
		}
		return 'branch' === $status->source()
			? __( 'Branch · Active', 'ran-booster' )
			: __( 'Releases · Active', 'ran-booster' );
	}

	/**
	 * @return array{heading:string,badges:list<array{label:string}>,status?:string}
	 * @param array{heading:string,badges:list<array{label:string}>,status?:string} $fallback
	 */
	public function advanced_source_summary_projection(
		array $fallback,
		string $mode,
		?object $package,
		?ReleaseTrackingStatus $status
	): array {
		if ( 'create' === $mode ) {
			return $fallback;
		}
		if ( null === $package || null === $status ) {
			return $fallback;
		}

		if ( 'branch' === $status->source() ) {
			$subdirectory = is_callable( array( $package, 'subdirectory' ) )
				&& is_string( $package->subdirectory() )
				? trim( (string) $package->subdirectory() )
				: '';
			$badges       = array();
			if ( '' !== $subdirectory ) {
				$badges[] = array(
					'label' => $subdirectory,
				);
			}
			return array(
				'heading' => __( 'Branch', 'ran-booster' ),
				'badges'  => $badges,
				'status'  => __( 'Active', 'ran-booster' ),
			);
		}

		return array(
			'heading' => __( 'Releases', 'ran-booster' ),
			'badges'  => array(
				array(
					'label' => 'prerelease' === $status->channel() ? __( 'Preview', 'ran-booster' ) : __( 'Stable', 'ran-booster' ),
				),
			),
			'status'  => __( 'Active', 'ran-booster' ),
		);
	}


	public function release_provider_supported( object $package, ?ReleaseTrackingStatus $status ): ?bool {
		if ( ! $this->is_projection( $package ) ) {
			return null;
		}

		if ( null === $status ) {
			return null;
		}
		return 'unsupported_provider' !== $status->eligibility()->code();
	}

	/** @param array{stable:string,prerelease:string} $preflight_nonces */
	private function render_release_track_settings(
		string $mode,
		bool $disabled,
		?string $selected_channel,
		PackageDisplayProjection $package,
		?string $nonce_action,
		string $form_id,
		?string $preflight_action = null,
		array $preflight_nonces = array(
			'stable'     => '',
			'prerelease' => '',
		)
	): void {
		$has_mutation  = ! $disabled && null !== $nonce_action;
		$has_preflight = 'branch' === $mode
			&& $has_mutation
			&& null !== $preflight_action
			&& '' !== $preflight_nonces['stable']
			&& '' !== $preflight_nonces['prerelease'];
		?>
		<form id="<?php echo esc_attr( $form_id ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="<?php echo esc_attr( 'branch' === $mode ? 'ran-booster-release-switch-form' : 'ran-booster-release-channel-form' ); ?>"<?php echo 'branch' === $mode ? ' data-ran-booster-source-transition' : ''; ?><?php echo $has_preflight ? ' data-ran-booster-switch-preflight-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-ran-booster-switch-preflight-action="' . esc_attr( $preflight_action ) . '" data-ran-booster-switch-preflight-nonces="' . esc_attr( (string) wp_json_encode( $preflight_nonces ) ) . '"' : ''; ?><?php echo $has_mutation ? ' data-ran-booster-package-mutation' : ''; ?><?php echo 'managed' === $mode ? ' data-ran-booster-submit-busy' : ''; ?>>
			<?php if ( $has_mutation ) { ?>
				<?php $this->admin_post_fields( 'branch' === $mode ? 'enable' : 'change_channel', $package, $nonce_action ); ?>
			<?php } ?>

		<?php
		$this->render_release_track( $selected_channel, $mode, $disabled, 'ran-booster-release-track-description', null, $form_id );

		if ( 'branch' === $mode ) {
			echo '<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>';
		}

		echo '</form>';
	}

	private function render_release_track( ?string $selected_channel, string $mode = 'branch', bool $disabled = false, string $description_id = 'ran-booster-release-track-description', ?string $custom_description = null, string $form_id = '' ): void {
		$managed      = 'managed' === $mode;
		$preview      = 'prerelease' === $selected_channel;
		$next_channel = $preview ? 'stable' : 'prerelease';
		$description  = $custom_description ?? ( $managed
			? __( 'Preview also includes alpha, beta and release-candidate builds. Switching affects future eligibility only and resets Automatic updates to Manual.', 'ran-booster' )
			: ( 'branch' === $mode
				? __( 'Stable follows final published releases. Preview also includes eligible alpha, beta and release-candidate builds. Drafts remain excluded.', 'ran-booster' )
					: __( 'Stable follows final published releases. Preview also includes prereleases.', 'ran-booster' ) ) );
		?>
		<fieldset class="ran-booster-release-track-control<?php echo $disabled ? ' is-disabled' : ''; ?>"<?php disabled( $disabled ); ?><?php echo 'branch' === $mode ? ' data-ran-booster-release-channel-control' : ''; ?>>
			<legend class="screen-reader-text"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></legend>
			<div class="button-group ran-booster-release-track-options">
				<?php foreach ( array( 'stable', 'prerelease' ) as $channel ) { ?>
					<?php if ( $managed && $channel === $selected_channel ) { ?>
						<span class="button button-primary ran-booster-release-track-option is-current" aria-current="true">
							<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Current release track', 'ran-booster' ); ?></span>
						</span>
					<?php } elseif ( $managed ) { ?>
						<?php /* translators: %s is the destination release track, Stable or Preview. */ ?>
						<button type="submit" class="button ran-booster-release-track-option" name="release_channel" value="<?php echo esc_attr( $next_channel ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Switch to %s releases', 'ran-booster' ), $this->release_track_label( $next_channel ) ) ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Switching…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Switching the release track. This can take a few seconds.', 'ran-booster' ) ); ?>">
							<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
						</button>
					<?php } else { ?>
						<label class="button ran-booster-release-track-option">
							<input type="radio" class="screen-reader-text" name="release_channel" value="<?php echo esc_attr( $channel ); ?>"<?php checked( $channel === $selected_channel ); ?><?php echo '' !== $form_id ? ' form="' . esc_attr( $form_id ) . '"' : ''; ?> aria-describedby="<?php echo esc_attr( $description_id ); ?>"<?php echo 'branch' === $mode ? ' data-ran-booster-release-channel' : ''; ?>>
							<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
						</label>
					<?php } ?>
				<?php } ?>
			</div>
			<?php if ( $managed ) { ?>
				<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
			<?php } ?>
			<p id="<?php echo esc_attr( $description_id ); ?>" class="description"><?php echo esc_html( $description ); ?></p>
		</fieldset>
		<?php
	}


	/**
	 * @param array{providers?:list<string>,list_candidates?:string,inspect?:string,install?:string} $prospective
	 */
	private function render_prospective_setup( string $type, array $prospective ): void {
		$candidates_nonce    = is_string( $prospective['list_candidates'] ?? null ) ? $prospective['list_candidates'] : null;
		$inspect_nonce       = is_string( $prospective['inspect'] ?? null ) ? $prospective['inspect'] : null;
		$install_nonce       = is_string( $prospective['install'] ?? null ) ? $prospective['install'] : null;
		$supported_providers = is_array( $prospective['providers'] ?? null ) ? $prospective['providers'] : array();
		?>
		<div
			id="ran-booster-source-pane-release_asset"
			class="ran-booster-package-source-pane ran-booster-release-pane"
			aria-labelledby="ran-booster-source-tab-release_asset"
			data-ran-booster-source-pane="release_asset"
			data-ran-booster-release-setup
			data-ran-booster-release-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-ran-booster-release-admin-post-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-ran-booster-release-type="<?php echo esc_attr( $type ); ?>"
			data-ran-booster-release-supported-providers="<?php echo esc_attr( implode( ',', $supported_providers ) ); ?>"
			data-ran-booster-release-candidates-nonce="<?php echo esc_attr( $candidates_nonce ?? '' ); ?>"
			data-ran-booster-release-inspect-nonce="<?php echo esc_attr( $inspect_nonce ?? '' ); ?>"
			data-ran-booster-release-install-nonce="<?php echo esc_attr( $install_nonce ?? '' ); ?>"
			hidden
		>
			<input type="hidden" name="expected_type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="ran_booster_release_install_nonce" value="<?php echo esc_attr( $install_nonce ?? '' ); ?>">
			<input type="hidden" name="release_id" value="">
			<input type="hidden" name="release_tag" value="">
			<input type="hidden" name="release_fingerprint" value="">
			<input type="hidden" name="provider_expectation" value="">
			<input type="hidden" name="release_channel" value="stable">
			<?php
			$this->render_release_track(
				'stable',
				'branch',
				false,
				'ran-booster-release-channel-description-' . $type,
				__( 'Preview also includes alpha, beta and release-candidate builds. Drafts remain excluded.', 'ran-booster' )
			);
			?>
			<header>
				<h3><?php esc_html_e( 'Published release selection', 'ran-booster' ); ?></h3>
			</header>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-release-candidates hidden>
				<legend><strong><?php esc_html_e( 'Choose a published release', 'ran-booster' ); ?></strong></legend>
				<div data-ran-booster-release-candidate-list></div>
			</fieldset>
			<div class="ran-booster-release-status" role="status" aria-live="polite" aria-atomic="true" data-ran-booster-release-status>
				<div>
					<h4 data-ran-booster-release-status-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
					<p>
						<span data-ran-booster-release-status-message><?php esc_html_e( 'Choose a repository, then select Releases.', 'ran-booster' ); ?></span>
						<button type="button" class="button-link" data-ran-booster-release-switch-branch hidden><?php esc_html_e( 'Use branch', 'ran-booster' ); ?></button>
					</p>
				</div>
				<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" data-ran-booster-release-retry hidden><?php esc_html_e( 'Retry release check', 'ran-booster' ); ?></button>
			</div>
			<div class="description" data-ran-booster-release-observations role="status" aria-live="polite" hidden></div>
			<section
				class="ran-booster-readiness-panel ran-booster-release-preflight"
				aria-labelledby="ran-booster-release-preflight-heading-<?php echo esc_attr( $type ); ?>"
				data-ran-booster-release-details
				hidden
			>
				<div class="ran-booster-readiness-panel__top">
					<div>
						<h4 id="ran-booster-release-preflight-heading-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Published release inspected', 'ran-booster' ); ?></h4>
						<p><?php esc_html_e( 'Checked. Nothing is installed yet.', 'ran-booster' ); ?></p>
					</div>
				</div>
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item is-ok">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Release', 'ran-booster' ); ?></strong>
						<span data-ran-booster-release-version></span>
					</li>
					<li class="ran-booster-readiness-item is-ok">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php echo esc_html( 'plugin' === $type ? __( 'Expected plugin identity', 'ran-booster' ) : __( 'Expected theme identity', 'ran-booster' ) ); ?></strong>
						<span data-ran-booster-release-package></span>
					</li>
				</ul>
				<div class="ran-booster-action-row ran-booster-readiness-actions">
					<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" name="action" value="ran_booster_release_install" data-ran-booster-release-install data-ran-booster-busy-label="<?php echo esc_attr( __( 'Installing…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Installing the published release. This can take a few seconds.', 'ran-booster' ) ); ?>" hidden><?php echo esc_html( 'plugin' === $type ? __( 'Install published plugin', 'ran-booster' ) : __( 'Install published theme', 'ran-booster' ) ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 * @param array<string,ReleaseTrackingStatus|null> $statuses
	 */
	public function present_management( array $rows, string $surface, array $packages, array $statuses ): array {
		if ( ! in_array( $surface, array( 'plugin', 'theme' ), true ) ) {
			return $rows;
		}

		$projected = array_filter(
			$packages,
			fn ( mixed $package ): bool => is_object( $package )
				&& $this->is_projection( $package )
				&& $surface === $package->type()
				&& 'release_asset' === $package->source()
				&& isset( $rows[ $package->identifier() ] )
		);
		if ( array() === $projected ) {
			return $rows;
		}

		foreach ( $projected as $package ) {
			$identifier = $package->identifier();
			$status     = $statuses[ $identifier ] ?? null;
			if ( null === $status || ! $this->status_matches( $status, $package ) ) {
				$rows[ $identifier ] = $this->append_exceptional_badge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Release status unavailable', 'ran-booster' ),
						'tone'  => 'warning',
					)
				);
				continue;
			}
			if ( '' === (string) ( $rows[ $identifier ]['status'] ?? '' ) ) {
				if ( '' !== $status->failure_code() ) {
					$rows[ $identifier ]['status'] = $this->eligibility_explains_failure( $status )
						? $this->eligibility_message( $status->eligibility(), $status->source() )
						: $this->diagnostic_message( $status->failure_code(), null === $status->preflight() ? '' : $status->preflight()->reason_code() );
				} else {
					$rows[ $identifier ]['status'] = $this->release_status_message( $status, $status->update_available() ? $status->latest_version() : '' );
				}
			}

			if ( 'release_asset' === $status->source() && '' !== $status->failure_code() ) {
				$rows[ $identifier ] = $this->append_exceptional_badge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Needs attention', 'ran-booster' ),
						'tone'  => 'error',
					)
				);
			} elseif ( 'release_asset' === $status->source() && $status->update_available() ) {
				$rows[ $identifier ] = $this->append_exceptional_badge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Update available', 'ran-booster' ),
						'tone'  => 'pending',
					)
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, array<string, mixed>> $actions
	 * @return array<string, array<string, mixed>>
	 */

	public function present_management_actions( array $actions, string $surface, object $package, ?ReleaseTrackingStatus $status, ?string $refresh_nonce_action ): array {
		if ( ! in_array( $surface, array( 'plugin', 'theme' ), true )
			|| ! $this->is_projection( $package )
			|| $surface !== $package->type()
			|| 'release_asset' !== $package->source() ) {
			return $actions;
		}

		$available = null !== $status && $status->eligible();
		$key       = 'ran-booster-release:refresh';
		if ( ! $status?->update_available() && ! isset( $actions[ $key ] ) ) {

			$nonce_action = $available ? $refresh_nonce_action : null;
			if ( null === $nonce_action ) {
				$actions[ $key ] = array(
					'key'           => $key,
					'label'         => __( 'Release check unavailable', 'ran-booster' ),
					'type'          => 'link',
					'url'           => '',
					'disabled'      => true,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->bounded_string( $package->display_name(), 96 ),
					'busy_label'    => __( 'Working…', 'ran-booster' ),
				);
			} else {
				$actions[ $key ] = array(
					'key'           => $key,
					'label'         => __( 'Check releases', 'ran-booster' ),
					'type'          => 'post',
					'url'           => admin_url( 'admin-post.php' ),
					'hidden'        => array(
						'action'                   => self::ADMIN_POST_ACTIONS['refresh'],
						'_wpnonce'                 => $nonce_action,
						'expected_type'            => $package->type(),
						'expected_identifier'      => $package->identifier(),
						'expected_source_revision' => (string) $package->source_revision(),
					),
					'disabled'      => false,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->bounded_string( $package->display_name(), 96 ),
					'busy_label'    => __( 'Working…', 'ran-booster' ),
				);
			}
		}

		$native_key = 'ran-booster-release:native-update';
		if ( null !== $status && $status->update_available() && ! isset( $actions[ $native_key ] ) ) {
			$actions[ $native_key ] = array(
				'key'           => $native_key,
				'label'         => __( 'Open WordPress updates', 'ran-booster' ),
				'type'          => 'link',
				'url'           => admin_url( 'update-core.php' ),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $this->bounded_string( $package->display_name(), 96 ),
			);
		}

		return $actions;
	}

	private function normalize_release_channel( mixed $channel ): ?string {
		return is_string( $channel )
			&& in_array( $channel, array( 'stable', 'prerelease' ), true )
				? $channel
				: null;
	}

	private function release_track_label( mixed $channel ): string {
		return 'prerelease' === $this->normalize_release_channel( $channel ) ? __( 'Preview', 'ran-booster' ) : __( 'Stable', 'ran-booster' );
	}

	private function status_matches( ReleaseTrackingStatus $status, PackageDisplayProjection $package ): bool {
		return hash_equals( $package->type(), $status->type() )
			&& hash_equals( $package->identifier(), $status->identifier() )
			&& $package->source_revision() === $status->source_revision();
	}

	/**
	 * @param array<string, mixed>              $row
	 * @param array{label:string,tone:string}   $badge
	 * @return array<string, mixed>
	 */
	private function append_exceptional_badge( array $row, array $badge ): array {
		$existing_badges = is_array( $row['badges'] ?? null ) ? $row['badges'] : array();
		$row['badges']   = array_merge( $existing_badges, array( $badge ) );

		return $row;
	}

	private function admin_post_fields( string $operation, PackageDisplayProjection $package, string $nonce ): void {
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_ACTIONS[ $operation ] ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="expected_type" value="<?php echo esc_attr( $package->type() ); ?>">
		<input type="hidden" name="expected_identifier" value="<?php echo esc_attr( $package->identifier() ); ?>">
		<input type="hidden" name="expected_source_revision" value="<?php echo esc_attr( (string) $package->source_revision() ); ?>">
		<?php
	}

	private function render_controls_unavailable(): void {
		?>
		<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Published release controls are temporarily unavailable. No package settings were changed.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>
		<?php
	}

	/** @param array<string, mixed>|null $native_hold */
	public function render_operation_notice(
		string $code,
		bool $successful,
		string $type = '',
		string $identifier = '',
		string $channel = '',
		?ReleaseTrackingStatus $status = null,
		string $reason = '',
		string $detail = '',
		string $reload_url = '',
		?array $native_hold = null
	): void {
		if ( '' === $code ) {
			return;
		}

		$message = $this->result_message( $code, $successful, $type, $channel, $status, $reason, $detail );
		if ( $successful && 'release_enabled' === $code ) {
			echo FeedbackFlash::container( FeedbackFlash::item( $code, 'success', $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FeedbackFlash escapes the settled message for its static fallback.
			return;
		}

		$message_html = $this->linked_switch_result_html( $code, $type, $identifier, $reload_url, $native_hold );
		$review       = ! $successful && in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed' ), true );
		$check        = $successful ? null : FeedbackFlash::check_result( $code, $detail );
		$tone         = $successful
			? 'notice-success'
			: ( null !== $check ? 'notice-' . $check[0] : ( in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed', 'release_repository_conflict', 'repository_release_owner_exists', 'refresh_unchecked', 'refresh_rejected', 'switch_not_confirmed', 'switch_superseded' ), true )
				? 'notice-warning'
				: 'notice-error' ) );
		?>
		<div<?php echo null === $check ? '' : ' data-feedback-key="' . esc_attr( $code ) . '" role="' . ( 'notice-error' === $tone ? 'alert' : 'status' ) . '"'; ?> class="notice <?php echo esc_attr( $tone ); ?><?php echo \RAN\Admin\BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( substr( $tone, 7 ) ); ?>"<?php echo $successful ? ' data-ran-booster-package-success' : ''; ?> data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( substr( $tone, 7 ) ); ?>" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<?php if ( $review ) { ?>
				<p><strong><?php echo esc_html( 'installed_but_unmanaged' === $code ? __( 'Package present, Booster management not established', 'ran-booster' ) : __( 'Installation outcome needs review', 'ran-booster' ) ); ?></strong></p>
			<?php } ?>
			<?php if ( null !== $message_html ) { ?>
				<p><?php echo $message_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every interpolated URL and text fragment inside is escaped with esc_url() and esc_html*(). ?></p>
			<?php } else { ?>
				<p><?php echo esc_html( $message ); ?></p>
			<?php } ?>
			<?php if ( null !== $check && '' !== $check[2] ) { ?>
				<p><?php echo esc_html( $check[2] ); ?></p>
			<?php } ?>
			<?php if ( $review ) { ?>
				<p><?php echo esc_html( 'installed_but_unmanaged' === $code || null === $status ? __( 'Inspect the installed version, activation state and Booster management before using Link an already installed plugin (or theme) or retrying.', 'ran-booster' ) : __( 'Inspect the installed version, activation state and Booster management before retrying.', 'ran-booster' ) ); ?></p>
			<?php } ?>
		</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( substr( $tone, 7 ) ); ?>
			<?php if ( ! $successful && $this->is_diagnostic_code( $code ) ) { ?>
				<?php $this->render_failure_help( $code, $message, $reason, $detail ); ?>
			<?php } ?>
		</div>
		<?php
	}

	public function switch_fallback_message( string $reason, string $skipped_version ): string {
		if ( '' === $reason ) {
			return '';
		}
		$explanation = match ( $reason ) {
			'digest_missing', 'package_asset_missing', 'package_asset_ambiguous', 'release_incompatible' => $this->preflight_failure_message( 'release_unavailable', $reason, $skipped_version ),
			'release_identity_mismatch' => __( 'The ZIP’s folder or main file does not match this package.', 'ran-booster' ),
			'package_header_missing' => $this->preflight_failure_message( 'release_header_missing' ),
			'package_header_invalid' => $this->preflight_failure_message( 'release_header_invalid' ),
			'package_archive_unreadable' => $this->preflight_failure_message( 'release_archive_unreadable' ),
			default => $this->diagnostic_message( $reason, default_message: __( 'Booster could not validate that release.', 'ran-booster' ) ),
		};
		if ( '' === $skipped_version ) {
			return sprintf(
				/* translators: %s: reason the newer release was skipped. */
				__( 'A newer release was skipped. %s', 'ran-booster' ),
				$explanation
			);
		}
		return sprintf(
			/* translators: 1: skipped release version, 2: reason it was skipped. */
			__( 'Release %1$s was skipped. %2$s', 'ran-booster' ),
			$skipped_version,
			$explanation
		);
	}

	public function preflight_failure_message( string $code, string $reason = '', string $detail = '' ): string {
		return $this->result_message( $code, false, '', '', null, $reason, $detail );
	}

	/**
	 * Build an interactive switch-preflight refusal response for an active GitHub hold.
	 *
	 * @param array<string, mixed> $native_hold
	 * @return array{successful:bool,code:string,data:array<string, mixed>}
	 */
	public function switch_hold_preflight_response( string $type, string $identifier, array $native_hold ): array {
		$same_package = ( $native_hold['type'] ?? null ) === $type && ( $native_hold['identifier'] ?? null ) === $identifier;
		if ( $same_package ) {
			$template   = $this->switch_held_template( $type );
			$link_label = __( 'release the hold', 'ran-booster' );
			$link_url   = ! empty( $native_hold['readable'] )
				? '#ran-booster-native-hold'
				: ( is_string( $native_hold['url'] ?? null ) ? (string) $native_hold['url'] : '' );

			return array(
				'successful' => false,
				'code'       => 'switch_held',
				'data'       => array(
					'message'          => sprintf( $template, $link_label ),
					'message_template' => $template,
					'link_label'       => $link_label,
					'link_url'         => $link_url,
				),
			);
		}

		$held_name = is_string( $native_hold['name'] ?? null ) && '' !== $native_hold['name']
			? $native_hold['name']
			: __( 'another package', 'ran-booster' );
		$link_url  = is_string( $native_hold['url'] ?? null ) ? $native_hold['url'] : '';
		$template  = $this->switch_other_held_template();

		return array(
			'successful' => false,
			'code'       => 'switch_other_held',
			'data'       => array(
				'message'          => sprintf( $template, $held_name ),
				'message_template' => $template,
				'link_label'       => $held_name,
				'link_url'         => $link_url,
			),
		);
	}

	private function switch_held_template( string $type ): string {
		if ( 'theme' === $type ) {
			/* translators: %s: link to release the hold. */
			return __( 'Install couldn\'t complete. Check this theme, then %s.', 'ran-booster' );
		}

		/* translators: %s: link to release the hold. */
		return __( 'Install couldn\'t complete. Check this plugin, then %s.', 'ran-booster' );
	}

	private function switch_other_held_template(): string {
		/* translators: %s: the held package's name, linked to the page that releases the hold. */
		return __( 'GitHub installs are on hold: the last install of %s couldn\'t complete.', 'ran-booster' );
	}

	/** @param array<string, mixed>|null $native_hold */
	private function linked_switch_result_html(
		string $code,
		string $type,
		string $identifier,
		string $reload_url,
		?array $native_hold
	): ?string {
		if ( 'branch_switch_held' === $code && is_string( $native_hold['url'] ?? null ) && '' !== $native_hold['url'] ) {
			$link = '<a href="' . esc_url( $native_hold['url'] ) . '">' . esc_html__( 'review the install hold', 'ran-booster' ) . '</a>';

			/* translators: %s: link to the install hold. */
			return sprintf( esc_html__( 'A GitHub install hold blocked the switch to Branch. Please %s before trying again.', 'ran-booster' ), $link );
		}

		if ( 'switch_held' === $code ) {
			$hold_url = null !== $native_hold && ! empty( $native_hold['readable'] )
				? '#ran-booster-native-hold'
				: ( is_string( $native_hold['url'] ?? null ) ? (string) $native_hold['url'] : '' );
			$label    = esc_html__( 'release the hold', 'ran-booster' );
			$link     = '' !== $hold_url
				? '<a href="' . esc_url( $hold_url ) . '">' . $label . '</a>'
				: $label;

			return sprintf(
				esc_html( $this->switch_held_template( $type ) ),
				$link
			);
		}

		if ( 'switch_other_held' === $code ) {
			$held_name = is_string( $native_hold['name'] ?? null ) && '' !== $native_hold['name']
				? $native_hold['name']
				: __( 'another package', 'ran-booster' );
			$held_url  = is_string( $native_hold['url'] ?? null ) ? $native_hold['url'] : '';
			$link      = '' !== $held_url
				? '<a href="' . esc_url( $held_url ) . '">' . esc_html( $held_name ) . '</a>'
				: esc_html( $held_name );

			return sprintf(
				esc_html( $this->switch_other_held_template() ),
				$link
			);
		}

		if ( 'switch_not_confirmed' === $code ) {
			if ( '' === $reload_url && '' !== $identifier && in_array( $type, array( 'plugin', 'theme' ), true ) ) {
				$reload_url = add_query_arg(
					array(
						'page'    => 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes',
						'package' => $identifier,
					),
					is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
				);
			}
			$label = esc_html__( 'Reload this page', 'ran-booster' );
			$link  = '' !== $reload_url
				? '<a href="' . esc_url( $reload_url ) . '">' . $label . '</a>'
				: $label;

			return sprintf(
				/* translators: %s: link to reload the package settings page. */
				esc_html__( 'Booster hasn\'t confirmed the switch yet. %s to check.', 'ran-booster' ),
				$link
			);
		}

		return null;
	}

	private function result_message( string $code, bool $successful, string $type, string $channel, ?ReleaseTrackingStatus $status, string $reason = '', string $detail = '' ): string {
		if ( ! $successful && $this->is_diagnostic_code( $code ) ) {
			return $this->diagnostic_message( $code, $reason, $detail );
		}

		return match ( $code ) {
			'release_repository_conflict',
			'repository_release_owner_exists' => __( 'Releases require exclusive use of this repository. Stop managing the other packages in Booster before switching; their files can stay installed.', 'ran-booster' ),
			'installed' => __( 'The published release was installed and is now managed by Booster. WordPress activation was not changed.', 'ran-booster' ),
			'release_enabled' => $this->switch_enabled_message( $status ),
			'switch_failed_unchanged' => $this->switch_failed_unchanged_message( $type, $status ),
			'switch_held' => sprintf(
				$this->switch_held_template( $type ),
				__( 'release the hold', 'ran-booster' )
			),
			'switch_other_held' => sprintf(
				$this->switch_other_held_template(),
				__( 'another package', 'ran-booster' )
			),
			'switch_superseded' => __( 'This switch result no longer matches the current package settings. Review the current source and installed version.', 'ran-booster' ),
			'switch_not_confirmed' => __( 'Booster hasn\'t confirmed the switch yet. Reload this page to check.', 'ran-booster' ),
			'policy_disabled' => __( 'Updates are disabled for this package. Set Updates to Manual to switch.', 'ran-booster' ),
			'policy_forced_off' => __( 'Updates are turned off by this site\'s configuration, so this package can\'t switch to releases.', 'ran-booster' ),
			'release_selection_changed' => __( 'The selected release changed or your confirmation expired. Choose Use releases to open a new confirmation.', 'ran-booster' ),
			'release_older' => __( 'This release is older than what\'s installed. Switching replaces the code but cannot roll back database changes. Have a recent database backup ready.', 'ran-booster' ),
			'release_channel_changed',
			'branch_restored' => FeedbackFlash::release_operation_success( $code ) ?? '',
			'release_channel_current' => sprintf(
				/* translators: 1: package type, 2: release track. */
				__( 'This %1$s is already using the %2$s release track. No settings were changed.', 'ran-booster' ),
				( 'theme' === $type ? __( 'theme', 'ran-booster' ) : __( 'plugin', 'ran-booster' ) ),
				$this->release_track_label( $channel )
			),
			'release_current',
			'release_update_available' => $this->refreshed_result_message( $status ),
			'refresh_unchecked',
			'refresh_failed',
			'refresh_skipped_disabled',
			'refresh_skipped_forced_off',
			'refresh_recent',
			'refresh_newer_while_off',
			'refresh_current_while_off',
			'refresh_rejected',
			'refresh_check_failed' => FeedbackFlash::check_result( $code, $detail )[1] ?? '',
			'source_changed' => __( 'Package settings changed after this browser page was opened. Refresh the page, review the current settings, then try again.', 'ran-booster' ),
			'lock_unavailable' => __( 'The WordPress package updater lock blocked the switch to Branch. Wait for any running update or deployment to finish, then choose Use branch again. If the lock remains, ask your site administrator to check for an interrupted update.', 'ran-booster' ),
			'branch_switch_held' => __( 'A GitHub install hold blocked the switch to Branch. Review the hold in Booster’s GitHub settings before trying again.', 'ran-booster' ),
			'branch_switch_unavailable' => __( 'Booster could not verify the switch to Branch. Reload this page to check the current source. If the problem continues, ask your site administrator to check Booster’s storage.', 'ran-booster' ),
			'source_paused' => __( 'Updates are paused by a site setting. No files were changed.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already registers its own release updater. Use one release owner only.', 'ran-booster' ),
			'forbidden' => __( 'You are not authorized to perform this published release operation. No package was installed or adopted.', 'ran-booster' ),
			'unsupported_provider' => __( 'Published releases are not available for this Git provider. Use Branch tracking instead.', 'ran-booster' ),
			'invalid_request' => __( 'The published release request was invalid. No package was installed or adopted. Reload and try again.', 'ran-booster' ),
			'service_unavailable' => __( 'Published release controls are temporarily unavailable. No package settings were changed. No package was installed.', 'ran-booster' ),
			'package_already_exists' => __( 'That plugin or theme is already installed or managed. No package was overwritten.', 'ran-booster' ),
			'installed_but_unmanaged' => __( 'A package is present, but Booster did not establish management for it.', 'ran-booster' ),
			'management_state_uncertain' => __( 'Booster could not confirm the final installation or management state.', 'ran-booster' ),
			'installation_cleanup_failed' => __( 'Booster could not confirm the installation or management state after finalization failed.', 'ran-booster' ),
			'wordpress_refused',
			'wordpress_failed',
			'wordpress_restored',
			'wordpress_uncertain',
			'operation_mismatch' => __( 'WordPress did not install the selected published release, and Booster did not adopt it.', 'ran-booster' ),
			'install_failed' => __( 'The install request could not be completed. Review installed packages before retrying because the final state was not reported.', 'ran-booster' ),
			'no_releases' => __( 'No eligible published release is available for the selected channel. No package was installed.', 'ran-booster' ),
			'release_invalid' => __( 'The selected release does not satisfy the selected channel requirements. No package was installed.', 'ran-booster' ),
			'release_changed' => __( 'The release changed after it was inspected. No package was installed. Use Check releases and try again.', 'ran-booster' ),
			'lookup_rate_limited' => __( 'The Git provider rate-limited this public release lookup. Try again later or choose a dedicated lookup credential in provider settings.', 'ran-booster' ),
			'version_comparison_unavailable' => __( 'Booster could not compare the installed version with the published release version. No package was installed.', 'ran-booster' ),
			'unable_to_check' => __( 'Published releases could not be checked. No package was installed and no package settings were changed.', 'ran-booster' ),
			default => $successful
				? __( 'The published release operation completed.', 'ran-booster' )
				: __( 'The published release operation could not be completed.', 'ran-booster' ),
		};
	}

	private function switch_enabled_message( ?ReleaseTrackingStatus $status ): string {
		$version = null !== $status ? $this->bounded_string( $status->installed_version(), 64 ) : '';

		$message = '' !== $version
			/* translators: %s: installed release version. */
			? sprintf( __( 'Switched to releases. Version %s is installed.', 'ran-booster' ), $version )
			: __( 'Switched to releases.', 'ran-booster' );
		return null !== $status && 'manual' === $status->deployment_policy()
			? $message . ' ' . __( 'Updates are set to Manual. You choose when to install the next release.', 'ran-booster' )
			: $message;
	}

	private function switch_failed_unchanged_message( string $type, ?ReleaseTrackingStatus $status ): string {
		$version = null !== $status ? $this->bounded_string( $status->installed_version(), 64 ) : '';
		if ( 'theme' === $type ) {
			return '' !== $version
				/* translators: %s: currently installed version. */
				? sprintf( __( 'Install couldn\'t complete before any files changed. This theme is still on Branch, version %s. Choose Use releases to try again.', 'ran-booster' ), $version )
				: __( 'Install couldn\'t complete before any files changed. This theme is still on Branch. Choose Use releases to try again.', 'ran-booster' );
		}

		return '' !== $version
			/* translators: %s: currently installed version. */
			? sprintf( __( 'Install couldn\'t complete before any files changed. This plugin is still on Branch, version %s. Choose Use releases to try again.', 'ran-booster' ), $version )
			: __( 'Install couldn\'t complete before any files changed. This plugin is still on Branch. Choose Use releases to try again.', 'ran-booster' );
	}

	private function diagnostic_message( string $code, string $reason = '', string $detail = '', ?string $default_message = null ): string {
		// The release or file name is only a fact the reason may carry. Without it the generic copy stays true.
		if ( '' === $detail && in_array( $reason, array( 'package_asset_missing', 'package_asset_ambiguous' ), true ) ) {
			$reason = 'release_incompatible';
		}
		if ( 'release_unavailable' === $code ) {
			return match ( $reason ) {
				'no_releases' => __( 'No eligible releases were found in this search.', 'ran-booster' ),
				'digest_missing' => __( 'The release ZIP has no published digest.', 'ran-booster' ),
				/* translators: %s: release tag, for example v1.2.0. */
				'package_asset_missing' => sprintf( __( 'Release %s has no .zip package asset.', 'ran-booster' ), $detail ),
				/* translators: %s: release tag, for example v1.2.0. */
				'package_asset_ambiguous' => sprintf( __( 'Release %s has more than one .zip package asset.', 'ran-booster' ), $detail ),
				'release_incompatible' => __( 'A published release was found, but it does not have exactly one package ZIP, or its header or Update URI does not match.', 'ran-booster' ),
				default => __( 'Booster could not confirm an eligible published release for this track.', 'ran-booster' ),
			};
		}
		if ( 'invalid_release_assets' === $code && 'release_identity_mismatch' === $reason && '' !== $detail ) {
			/* translators: %s: package folder and main file name found in the ZIP, for example example/example.php. */
			return sprintf( __( 'The ZIP’s folder or main file (%s) does not match this package.', 'ran-booster' ), $detail );
		}

		return match ( $code ) {
			'release_repository_conflict',
			'repository_release_owner_exists' => __( 'Releases require exclusive use of this repository. Stop managing the other packages in Booster before switching; their files can stay installed.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'Published releases require this plugin or theme to be at the repository root. Return to Branch to keep using its configured repository subdirectory.', 'ran-booster' ),
			'release_configuration_invalid',
			'target_registration_failed' => __( 'This package uses retired published-release settings, so Booster could not start its release checker. Return it to Branch, then validate and switch it back to Published releases.', 'ran-booster' ),
			'release_version_mismatch' => __( 'Release version mismatch — check published releases.', 'ran-booster' ),
			'release_header_missing' => __( 'The published package is missing its WordPress version header.', 'ran-booster' ),
			'release_header_invalid' => __( 'The published package has an invalid WordPress version header.', 'ran-booster' ),
			'release_archive_unreadable' => __( 'The published release archive could not be inspected.', 'ran-booster' ),
			'invalid_release_assets' => __( 'The published release assets could not be validated.', 'ran-booster' ),
			'preflight_unavailable' => __( 'Published release validation is temporarily unavailable.', 'ran-booster' ),
			'release_runtime_unavailable' => __( 'This server cannot currently run the published-release validator.', 'ran-booster' ),
			'package_update_uri_missing' => __( 'The published ZIP is missing the required Update URI header.', 'ran-booster' ),
			'package_update_uri_invalid' => __( 'The published ZIP’s Update URI does not match its configured repository.', 'ran-booster' ),
			'package_compatibility_missing',
			'package_compatibility_invalid' => __( 'The published release is not compatible with this WordPress site.', 'ran-booster' ),
			'download_digest_mismatch' => __( 'The downloaded ZIP does not match the published digest.', 'ran-booster' ),
			'download_size_mismatch' => __( 'The downloaded ZIP does not match the published size.', 'ran-booster' ),
			'archive_php_requirement_incompatible' => __( 'The release ZIP requires a different PHP version.', 'ran-booster' ),
			'archive_wordpress_requirement_incompatible' => __( 'The release ZIP requires a different WordPress version.', 'ran-booster' ),
			'package_zip_extension_unavailable' => __( 'This server cannot inspect ZIP packages because its ZIP extension is unavailable.', 'ran-booster' ),
			'package_archive_size_invalid',
			'package_archive_too_large' => __( 'The published ZIP has an invalid or excessive uncompressed size.', 'ran-booster' ),
			'package_archive_path_unsafe',
			'package_archive_path_duplicate',
			'package_archive_root_invalid',
			'package_archive_entry_duplicate',
			'package_archive_entry_limit' => __( 'The published ZIP has an unsafe or unsupported archive structure.', 'ran-booster' ),
			'package_header_ambiguous' => __( 'The published ZIP contains more than one possible WordPress package header.', 'ran-booster' ),
			'release_version_invalid' => __( 'The published release tag does not contain a valid package version.', 'ran-booster' ),
			default => $default_message ?? __( 'Published release status needs attention.', 'ran-booster' ),
		};
	}

	private function is_diagnostic_code( string $code ): bool {
		if ( in_array( $code, array( 'release_repository_conflict', 'repository_release_owner_exists', 'release_changed', 'release_selection_changed', 'release_invalid', 'release_older', 'package_already_exists' ), true ) ) {
			return false;
		}
		return str_starts_with( $code, 'package_' )
			|| str_starts_with( $code, 'release_' )
			|| in_array( $code, array( 'invalid_release_assets', 'preflight_unavailable', 'subdirectory_not_supported', 'target_registration_failed', 'download_digest_mismatch', 'download_size_mismatch', 'archive_php_requirement_incompatible', 'archive_wordpress_requirement_incompatible' ), true );
	}

	private function diagnostic_action( string $code, string $reason = '', string $detail = '' ): string {
		// Keep the action in step with the message: without the release tag the generic copy is used for both.
		if ( '' === $detail && in_array( $reason, array( 'package_asset_missing', 'package_asset_ambiguous' ), true ) ) {
			$reason = 'release_incompatible';
		}
		if ( 'release_unavailable' === $code ) {
			return match ( $reason ) {
				'digest_missing' => __( 'Publish the release ZIP again so its digest is available, then use Check releases.', 'ran-booster' ),
				'no_releases' => __( 'Review the release picker for skipped releases and the selected track, then use Check releases.', 'ran-booster' ),
				'package_asset_missing' => __( 'Attach exactly one .zip package to the release, then use Check releases.', 'ran-booster' ),
				'package_asset_ambiguous' => __( 'Keep exactly one .zip package on the release, then use Check releases.', 'ran-booster' ),
				'release_incompatible' => __( 'Make sure the release has exactly one package ZIP asset, and that its plugin or theme header and Update URI match this repository. Then use Check releases.', 'ran-booster' ),
				default => __( 'Publish a release for the selected Stable or Preview track, make sure it has one package ZIP whose header and Update URI match this repository, then use Check releases.', 'ran-booster' ),
			};
		}

		if ( 'invalid_release_assets' === $code && 'release_identity_mismatch' === $reason && '' !== $detail ) {
			return __( 'Publish a ZIP whose folder and main file name match this package, then use Check releases.', 'ran-booster' );
		}

		return match ( $code ) {
			'release_repository_conflict',
			'repository_release_owner_exists' => __( 'Stop managing the other packages in Booster before switching this package to releases. Their files can stay installed.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'Return the package to Branch to keep its configured repository subdirectory.', 'ran-booster' ),
			'package_update_uri_missing',
			'package_update_uri_invalid' => __( 'Correct the Update URI in the packaged plugin or theme header, publish a new ZIP, then recheck.', 'ran-booster' ),
			'download_digest_mismatch',
			'download_size_mismatch' => __( 'Check the uploaded ZIP on the release, then use Check releases to get a fresh offer.', 'ran-booster' ),
			'archive_php_requirement_incompatible',
			'archive_wordpress_requirement_incompatible',
			'package_compatibility_missing',
			'package_compatibility_invalid' => __( 'Publish a package whose WordPress and PHP compatibility headers include this site, or choose a compatible release track.', 'ran-booster' ),
			'release_version_mismatch',
			'release_version_invalid' => __( 'Make the release tag and packaged WordPress Version header describe the same valid version, then publish a corrected ZIP.', 'ran-booster' ),
			'release_header_missing',
			'release_header_invalid',
			'package_header_missing',
			'package_header_invalid',
			'package_header_ambiguous' => __( 'Correct the plugin or theme header in the distributable ZIP, publish a new release asset, then recheck.', 'ran-booster' ),
			'release_runtime_unavailable',
			'package_zip_extension_unavailable' => __( 'Ask the site administrator to restore the required PHP and temporary-file runtime support, then retry.', 'ran-booster' ),
			'release_configuration_invalid',
			'target_registration_failed' => __( 'Return the package to Branch, verify its repository settings, then validate and switch it back to Published releases.', 'ran-booster' ),
			default => __( 'Review the release, repository access and packaged WordPress headers, then retry. If it still fails, open Troubleshooting with the error code below.', 'ran-booster' ),
		};
	}

	private function refreshed_result_message( ?ReleaseTrackingStatus $status ): string {
		return null === $status
			? __( 'The release check completed, but its version result is unavailable. Review the package status below.', 'ran-booster' )
			: FeedbackFlash::release_checked();
	}

	private function release_status_message( ReleaseTrackingStatus $status, string $offered_version, bool $updates_forced_off = false ): string {
		$installed = $this->bounded_string( $status->installed_version(), 64 );
		$latest    = $this->bounded_string( $offered_version, 64 );
		if ( '' !== $latest ) {
			return sprintf(
				/* translators: 1: installed version, 2: available version. */
				__( 'You have version %1$s. An update to version %2$s is available.', 'ran-booster' ),
				'' !== $installed ? $installed : __( 'unknown', 'ran-booster' ),
				$latest
			);
		}

		$message = match ( true ) {
			/* translators: %s is the installed version. */
			$updates_forced_off, 'disabled' === $status->deployment_policy() => __( 'Version %s is installed. Updates are off for this package.', 'ran-booster' ),
			/* translators: %s is the installed version. */
			$status->update_available() => __( 'Version %s is installed. WordPress has no current update offer.', 'ran-booster' ),
			/* translators: %s is the installed version. */
			default => __( 'Version %s is installed. No newer eligible release was found.', 'ran-booster' ),
		};
		return sprintf( $message, '' !== $installed ? $installed : __( 'unknown', 'ran-booster' ) );
	}

	private function render_failure_help( string $code, string $message = '', string $reason = '', string $detail = '' ): void {
		$troubleshooting_url = add_query_arg(
			array(
				'page'  => 'ran-booster',
				'tab'   => 'troubleshooting',
				'panel' => 'logging',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div data-ran-booster-ui-disclosure="release-failure-help" data-ran-booster-ui-disclosure-density="compact">
		<details data-ran-booster-ui-disclosure-fallback class="ran-booster-disclosure">
			<summary><?php esc_html_e( 'Why this happened and how to fix it', 'ran-booster' ); ?></summary>
			<div data-ran-booster-ui-disclosure-content class="ran-booster-disclosure__body">
			<p><strong><?php esc_html_e( 'What Booster found:', 'ran-booster' ); ?></strong> <?php echo esc_html( '' !== $message ? $message : $this->diagnostic_message( $code, $reason ) ); ?></p>
			<p><strong><?php esc_html_e( 'What to do:', 'ran-booster' ); ?></strong> <?php echo esc_html( $this->diagnostic_action( $code, $reason, $detail ) ); ?></p>
			<p><a href="<?php echo esc_url( $troubleshooting_url ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a></p>
			<p><strong><?php esc_html_e( 'Technical reason:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $code ); ?></code></p>
			</div>
		</details></div>
		<?php
	}

	/** @param \RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility $eligibility */
	private function eligibility_message( object $eligibility, string $source ): string {
		return match ( $eligibility->code() ) {
			'subdirectory_not_supported' => 'branch' === $source
				? __( 'Published releases require this plugin or theme to be at the repository root. This package can continue using its configured repository subdirectory with Branch deployments.', 'ran-booster' )
				: __( 'Published releases require this plugin or theme to be at the repository root. Return to Branch to keep using its configured repository subdirectory.', 'ran-booster' ),
			'missing_update_uri' => __( 'This package header does not declare an Update URI for its configured repository.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'This package header Update URI does not match its configured repository.', 'ran-booster' ),
			'unsupported_provider' => __( 'The Git provider does not support published release tracking.', 'ran-booster' ),
			'invalid_repository' => __( 'The configured repository is invalid.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already registers its own release updater. Use either that updater or Booster published-release tracking, not both.', 'ran-booster' ),
			default => __( 'The installed plugin or theme identity is not eligible for published release tracking.', 'ran-booster' ),
		};
	}

	/** A status failure whose condition the package's own eligibility check already states, in its own sentence. */
	private function eligibility_explains_failure( ReleaseTrackingStatus $status ): bool {
		return match ( $status->failure_code() ) {
			'github_updater_installed_header_missing' => 'missing_update_uri' === $status->eligibility()->code(),
			'github_updater_installed_update_uri_mismatch' => 'mismatched_update_uri' === $status->eligibility()->code(),
			default => false,
		};
	}

	private function update_uri_readiness_message( string $eligibility_code ): string {
		return match ( $eligibility_code ) {
			'subdirectory_not_supported' => __( 'Published releases require the repository root.', 'ran-booster' ),
			'missing_update_uri' => __( 'Missing from the installed package header.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'Does not match the configured repository.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'Matches, but this package already registers its own updater.', 'ran-booster' ),
			default => __( 'Cannot be validated until the Git provider and repository are ready.', 'ran-booster' ),
		};
	}

	/** @param \RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility $eligibility */
	private function requires_update_uri_remediation( object $eligibility ): bool {
		return '' !== $eligibility->expected_update_uri()
			&& in_array( $eligibility->code(), array( 'missing_update_uri', 'mismatched_update_uri' ), true );
	}

	/** @phpstan-assert-if-true =PackageDisplayProjection $package */
	private function is_projection( object $package ): bool {
		if ( ! $package instanceof PackageDisplayProjection ) {
			return false;
		}

		return in_array( $package->type(), array( 'plugin', 'theme' ), true )
			&& in_array( $package->source(), array( 'branch', 'release_asset' ), true )
			&& '' !== $package->identifier()
			&& $package->source_revision() > 0;
	}

	private function bounded_string( string $value, int $maximum_bytes ): string {
		if ( strlen( $value ) <= $maximum_bytes ) {
			return $value;
		}

		return function_exists( 'mb_strcut' )
			? mb_strcut( $value, 0, $maximum_bytes, 'UTF-8' )
			: substr( $value, 0, $maximum_bytes );
	}
}

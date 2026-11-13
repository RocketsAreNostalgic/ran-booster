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
	 */
	public function render_settings(
		object $package,
		?ReleaseTrackingStatus $status,
		string $settings_url,
		string $selected_channel = '',
		array $nonce_actions = array(),
		bool $eligibility_recheck = false,
		string $operation_notice_html = '',
		array $repository_conflict = array()
	): void {
		if ( ! $this->is_projection( $package ) ) {
			return;
		}

		$eligibility      = $status?->eligibility();
		$status_available = null !== $status && null !== $eligibility;
		$source           = $status_available ? $status->source() : $package->source();

		$selected_channel = 'branch' === $source

			? ( $this->normalize_release_channel( $selected_channel ) ?? 'stable' )
			: ( $status_available ? $status->channel() : null );

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
		$repository_conflict_code  = $status_available && in_array( $failure_code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true );
		$repository_source_blocked = $status_available
			&& ( 'repository_source_unavailable' === $failure_code
				|| ( 'branch' === $source && 'release_unavailable' === $failure_code ) );
		$repository_blocked        = $repository_conflict_code || $repository_source_blocked;
		$track_disabled            = null === $track_nonce_action || $repository_blocked;
		$repository_status_heading = $repository_source_blocked ? __( 'Storage unavailable', 'ran-booster' ) : __( 'Repository shared', 'ran-booster' );
		$automatic_policy          = $status_available && 'automatic' === $status->deployment_policy();
		$recheck_enabled           = $status_available && 'branch' === $source && null === $track_nonce_action;
		$refresh_enabled           = null !== $refresh_nonce_action && ! $repository_blocked;
		$recheck_reason            = ! $status_available
			? __( 'Available once release status is available.', 'ran-booster' )
			: ( 'branch' === $source
				? __( 'This package is already eligible for releases.', 'ran-booster' )
				: __( 'Not needed while releases are tracked.', 'ran-booster' ) );
		$browser_enabled           = $status_available
			&& 'release_asset' === $source
			&& $eligibility->eligible()

			&& '' !== ( $nonce_actions['list_candidates'] ?? '' )

			&& '' !== ( $nonce_actions['inspect_candidate'] ?? '' )
			&& ! $repository_blocked;
		$status_notice_visible = $status_available && 'release_asset' === $source && '' !== $failure_code && ! $repository_blocked;
		$release_unchecked     = $status_available && 'release_asset' === $source && '' === $failure_code && '' === $status->last_checked_at() && ! $status->update_available();
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
					<?php $this->render_use_releases_bar( $track_disabled, $release_track_summary, $track_form_id ); ?>
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
				<?php if ( $eligibility_recheck ) { ?>
					<div class="notice notice-success inline ran-wp-ui-notice ran-wp-ui-box-sizing is-success" data-ran-booster-package-success data-ran-booster-eligibility-recheck data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="success"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
						<p><strong><?php esc_html_e( 'Eligibility recheck complete.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'The current eligibility evidence is shown below.', 'ran-booster' ); ?></p>
					</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'success' ); ?></div>
				<?php } ?>
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
						<span><?php echo esc_html( ! $status_available ? __( 'Cannot be checked until release status is available.', 'ran-booster' ) : ( 'release_asset' === $source ? ( $release_unchecked ? __( 'Use Check releases to look for a newer version.', 'ran-booster' ) : $this->release_status_message( $status ) ) : __( 'Branch is active; releases are not tracked.', 'ran-booster' ) ) ); ?></span>
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
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"<?php echo $refresh_enabled ? ' data-ran-booster-package-mutation' : ''; ?>>
						<?php if ( $refresh_enabled ) { ?>
							<?php $this->admin_post_fields( 'refresh', $package, $refresh_nonce_action ); ?>
							<input type="hidden" name="return_to_settings" value="1">
						<?php } ?>
						<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"<?php disabled( ! $refresh_enabled ); ?> aria-disabled="<?php echo $refresh_enabled ? 'false' : 'true'; ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Checking…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Checking for published releases. This can take a few seconds.', 'ran-booster' ) ); ?>"><?php esc_html_e( 'Check releases', 'ran-booster' ); ?></button>
						<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					</form>
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
		<details id="ran-booster-release-track-settings" class="ran-booster-settings-disclosure ran-booster-release-track-section ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback data-ran-booster-package-disclosure>
			<summary>
				<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></h3>
				<small class="ran-booster-advanced-source-summary" data-ran-booster-ui-disclosure-meta>
					<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $release_track_summary, 'none', array( 'data-ran-booster-release-track-summary' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
				</small>
			</summary>
			<div data-ran-booster-ui-disclosure-content class="ran-booster-settings-disclosure__body ran-booster-disclosure__body">
				<?php $this->render_release_track_settings( $track_mode, $track_disabled, $selected_channel, $package, $track_nonce_action, $track_form_id ); ?>
				<?php $this->render_managed_candidate_browser( $status, $nonce_actions, $browser_enabled ); ?>
			</div>
		</details></div>
		<?php
	}

	/**
	 * Source switch bar for the Releases pane while Branch is active: the state
	 * on the left, one action at the end. The action keeps its association with
	 * the release track form, which lives further down the pane.
	 */
	private function render_use_releases_bar( bool $unavailable, string $track_label, string $track_form_id ): void {
		/* translators: %s is the selected release track, Stable or Preview. */
		$eligible_template   = __( 'Branch currently active. Switch to releases using the %s track.', 'ran-booster' );
		$unavailable_message = __( 'Releases aren’t ready. Fix the readiness checks below.', 'ran-booster' );
		$reason_id           = 'ran-booster-release-use-releases-reason';
		?>
		<div class="ran-booster-source-switch-bar ran-booster-release-switch-actions" data-ran-booster-source-switch-bar="branch">
			<p class="ran-booster-release-switch-actions__summary" data-ran-booster-use-releases-summary="<?php echo esc_attr( $eligible_template ); ?>" data-ran-booster-use-releases-summary-unavailable="<?php echo esc_attr( $unavailable_message ); ?>"><?php echo esc_html( $unavailable ? $unavailable_message : sprintf( $eligible_template, $track_label ) ); ?></p>
			<span class="ran-booster-reason-tooltip"<?php echo $unavailable ? ' data-ran-booster-reason-tooltip' : ''; ?>>
				<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Switching…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Switching to published releases. This can take a few seconds.', 'ran-booster' ) ); ?>" form="<?php echo esc_attr( $track_form_id ); ?>" data-ran-booster-use-releases data-ran-booster-use-releases-ready="<?php echo $unavailable ? 'false' : 'true'; ?>" aria-disabled="<?php echo $unavailable ? 'true' : 'false'; ?>"<?php echo $unavailable ? ' aria-describedby="' . esc_attr( $reason_id ) . '"' : ''; ?>><?php esc_html_e( 'Use releases', 'ran-booster' ); ?></button>
				<span id="<?php echo esc_attr( $reason_id ); ?>" class="ran-booster-reason-tooltip__bubble" role="tooltip" data-ran-booster-use-releases-reason hidden><?php esc_html_e( 'Available once the readiness checks pass.', 'ran-booster' ); ?></span>
			</span>
		</div>
		<?php
	}

	/** @param array<string,string> $nonce_actions */
	private function render_managed_candidate_browser( ?ReleaseTrackingStatus $status, array $nonce_actions, bool $enabled ): void {
		$list_nonce        = $enabled ? ( $nonce_actions['list_candidates'] ?? '' ) : '';
		$inspect_nonce     = $enabled ? ( $nonce_actions['inspect_candidate'] ?? '' ) : '';
		$native_update_url = $enabled && null !== $status ? $this->native_update_url( $status ) : '';
		$offered_version   = $enabled && null !== $status ? ManagedReleaseBrowserOperations::persisted_offer_version( $status->type(), $status->identifier() ) : '';
		$screen_is_theme   = null !== $status && 'theme' === $status->type();
		$screen_file       = $screen_is_theme ? 'themes.php' : 'plugins.php';
		$screen_cap        = $screen_is_theme ? ( is_multisite() ? 'manage_network_themes' : 'switch_themes' ) : ( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' );
		$screen_url        = null === $status || ! current_user_can( $screen_cap ) ? '' : ( is_multisite() ? network_admin_url( $screen_file ) : admin_url( $screen_file ) );
		?>
		<div
			class="ran-booster-managed-release-browser<?php echo $enabled ? '' : ' is-disabled'; ?>"
			data-ran-booster-managed-release-browser
			data-ran-booster-managed-release-browser-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"
			<?php if ( $enabled && null !== $status ) { ?>
			data-ran-booster-managed-release-type="<?php echo esc_attr( $status->type() ); ?>"
			data-ran-booster-managed-release-identifier="<?php echo esc_attr( $status->identifier() ); ?>"
			data-ran-booster-managed-release-revision="<?php echo esc_attr( (string) $status->source_revision() ); ?>"
			data-ran-booster-managed-release-channel="<?php echo esc_attr( $status->channel() ); ?>"
			data-ran-booster-managed-release-list-nonce="<?php echo esc_attr( $list_nonce ); ?>"
			data-ran-booster-managed-release-inspect-nonce="<?php echo esc_attr( $inspect_nonce ); ?>"
			data-ran-booster-managed-release-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-ran-booster-managed-release-native-update-url="<?php echo esc_url( $native_update_url ); ?>"
			data-ran-booster-managed-release-native-update-version="<?php echo esc_attr( '' !== $offered_version ? $offered_version : $status->latest_version() ); ?>"
			data-ran-booster-managed-release-wordpress-url="<?php echo esc_url( $screen_url ); ?>"
			data-ran-booster-managed-release-native-update-release-id="<?php echo esc_attr( $status->native_offer_release_id() ); ?>"
			<?php } ?>
		>
			<div class="ran-booster-managed-release-browser__header">
				<h4><?php esc_html_e( 'Releases', 'ran-booster' ); ?></h4>
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
				<p class="ran-booster-release-candidates__note"><?php esc_html_e( 'Published releases appear here once releases are in use.', 'ran-booster' ); ?></p>
			</div>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-managed-release-candidates hidden>
				<legend class="screen-reader-text"><?php esc_html_e( 'Available published releases', 'ran-booster' ); ?></legend>
				<div data-ran-booster-managed-release-candidate-list></div>
			</fieldset>
			<div class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-managed-release-status>
				<h4 data-ran-booster-managed-release-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
				<p data-ran-booster-managed-release-message></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the disabled Update now anchor for the Package operations group.
	 * The managed release browser script finds it by its data attribute and enables it.
	 */
	public function render_native_update_action(): void {
		?>
		<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) . ' disabled ran-booster-managed-release-native-update' ); ?>" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update><?php esc_html_e( 'Update now', 'ran-booster' ); ?></a>
		<?php
	}

	private function native_update_url( ReleaseTrackingStatus $status ): string {
		$offered = ManagedReleaseBrowserOperations::persisted_offer_version( $status->type(), $status->identifier() );
		if ( '' === $offered && ( ! $status->update_available() || '' === $status->latest_version() ) ) {
			return '';
		}

		$type       = $status->type();
		$identifier = $status->identifier();
		if ( 'plugin' === $type ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'upgrade-plugin',
						'plugin' => $identifier,
					),
					self_admin_url( 'update.php' )
				),
				'upgrade-plugin_' . $identifier
			);
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'upgrade-theme',
					'theme'  => $identifier,
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
		array $repository_conflict = array()
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

			$this->render_settings( $package, $status, $page_url, $selected_channel, $nonce_actions, $eligibility_recheck, $operation_notice_html, $repository_conflict );

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

	private function render_release_track_settings( string $mode, bool $disabled, ?string $selected_channel, PackageDisplayProjection $package, ?string $nonce_action, string $form_id ): void {
		$has_mutation = ! $disabled && null !== $nonce_action;
		?>
		<form id="<?php echo esc_attr( $form_id ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="<?php echo esc_attr( 'branch' === $mode ? 'ran-booster-release-switch-form' : 'ran-booster-release-channel-form' ); ?>"<?php echo 'branch' === $mode ? ' data-ran-booster-source-transition' : ''; ?><?php echo $has_mutation ? ' data-ran-booster-package-mutation' : ''; ?><?php echo 'managed' === $mode ? ' data-ran-booster-submit-busy' : ''; ?>>
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
					$rows[ $identifier ]['status'] = $this->diagnostic_message( $status->failure_code(), null === $status->preflight() ? '' : $status->preflight()->reason_code() );
				} else {
					$rows[ $identifier ]['status'] = $this->release_status_message( $status );
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

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Retain the existing positional package identifier slot; naming does not alter notice arguments.
	public function render_operation_notice(
		string $code,
		bool $successful,
		string $type = '',
		string $identifier = '',
		string $channel = '',
		?ReleaseTrackingStatus $status = null,
		string $reason = '',
		string $detail = ''
	): void {
		if ( '' === $code ) {
			return;
		}

		$message = $this->result_message( $code, $successful, $type, $channel, $status, $reason, $detail );
		$review  = ! $successful && in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed' ), true );
		$check   = $successful ? null : FeedbackFlash::check_result( $code );
		$tone    = $successful
			? 'notice-success'
			: ( null !== $check ? 'notice-' . $check[0] : ( in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed', 'release_repository_conflict', 'repository_release_owner_exists', 'refresh_unchecked', 'refresh_rejected' ), true )
				? 'notice-warning'
				: 'notice-error' ) );
		?>
		<div<?php echo null === $check ? '' : ' id="' . esc_attr( FeedbackFlash::CHECK_RESULT_TARGET ) . '"'; ?> class="notice <?php echo esc_attr( $tone ); ?><?php echo \RAN\Admin\BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( substr( $tone, 7 ) ); ?>"<?php echo $successful ? ' data-ran-booster-package-success' : ''; ?> data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( substr( $tone, 7 ) ); ?>" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<?php if ( $review ) { ?>
				<p><strong><?php echo esc_html( 'installed_but_unmanaged' === $code ? __( 'Package present, Booster management not established', 'ran-booster' ) : __( 'Installation outcome needs review', 'ran-booster' ) ); ?></strong></p>
			<?php } ?>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( null !== $check && '' !== $check[2] ) { ?>
				<p><?php echo esc_html( $check[2] ); ?></p>
			<?php } ?>
			<?php if ( $review ) { ?>
				<p><?php esc_html_e( 'Inspect the installed version, activation state and Booster management before using Link an already installed plugin (or theme) or retrying.', 'ran-booster' ); ?></p>
			<?php } ?>
		</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( substr( $tone, 7 ) ); ?>
			<?php if ( ! $successful && $this->is_diagnostic_code( $code ) ) { ?>
				<?php $this->render_failure_help( $code, $message, $reason, $detail ); ?>
			<?php } ?>
		</div>
		<?php
	}

	private function result_message( string $code, bool $successful, string $type, string $channel, ?ReleaseTrackingStatus $status, string $reason = '', string $detail = '' ): string {
		if ( ! $successful && $this->is_diagnostic_code( $code ) ) {
			return $this->diagnostic_message( $code, $reason, $detail );
		}

		return match ( $code ) {
			'release_repository_conflict',
			'repository_release_owner_exists' => __( 'Releases require exclusive use of this repository. Stop managing the other packages in Booster before switching; their files can stay installed.', 'ran-booster' ),
			'installed' => __( 'The published release was installed and is now managed by Booster. WordPress activation was not changed.', 'ran-booster' ),
			'release_enabled' => __( 'Release tracking enabled.', 'ran-booster' ),
			'release_channel_changed' => __( 'Release track changed. The installed package was not changed and Automatic was reset to Manual.', 'ran-booster' ),
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
			'refresh_rejected',
			'refresh_check_failed' => FeedbackFlash::check_result( $code )[1] ?? '',
			'branch_restored' => __( 'Branch-based package management is restored.', 'ran-booster' ),
			'source_changed' => __( 'Package settings changed after this browser page was opened. Refresh the page, review the current settings, then try again.', 'ran-booster' ),
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
			'unable_to_check' => __( 'Published releases could not be checked. No package was installed and no package settings were changed.', 'ran-booster' ),
			'release_version_mismatch',
			'release_header_missing',
			'release_header_invalid',
			'release_archive_unreadable',
			'release_unavailable',
			'invalid_release_assets',
			'release_configuration_invalid',
			'target_registration_failed',
			'preflight_unavailable' => $this->diagnostic_message( $code ),
			default => $successful
				? __( 'The published release operation completed.', 'ran-booster' )
				: __( 'The published release operation could not be completed.', 'ran-booster' ),
		};
	}

	private function diagnostic_message( string $code, string $reason = '', string $detail = '' ): string {
		// The release or file name is only a fact the reason may carry. Without it the generic copy stays true.
		if ( '' === $detail && in_array( $reason, array( 'package_asset_missing', 'package_asset_ambiguous' ), true ) ) {
			$reason = 'release_incompatible';
		}
		if ( 'release_unavailable' === $code ) {
			return match ( $reason ) {
				'no_releases' => __( 'No published release was found for the selected track.', 'ran-booster' ),
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
			default => __( 'Published release status needs attention.', 'ran-booster' ),
		};
	}

	private function is_diagnostic_code( string $code ): bool {
		if ( in_array( $code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true ) ) {
			return false;
		}
		return str_starts_with( $code, 'package_' )
			|| str_starts_with( $code, 'release_' )
			|| in_array( $code, array( 'invalid_release_assets', 'preflight_unavailable', 'subdirectory_not_supported', 'target_registration_failed', 'repository_release_owner_exists' ), true );
	}

	private function diagnostic_action( string $code, string $reason = '', string $detail = '' ): string {
		// Keep the action in step with the message: without the release tag the generic copy is used for both.
		if ( '' === $detail && in_array( $reason, array( 'package_asset_missing', 'package_asset_ambiguous' ), true ) ) {
			$reason = 'release_incompatible';
		}
		if ( 'release_unavailable' === $code ) {
			return match ( $reason ) {
				'no_releases' => __( 'Publish a release for the selected Stable or Preview track, then use Check releases.', 'ran-booster' ),
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

	/** @param ReleaseTrackingStatus $status */
	private function release_status_message( object $status ): string {
		$installed = $this->bounded_string( $status->installed_version(), 64 );
		$latest    = $this->bounded_string( $status->latest_version(), 64 );
		if ( $status->update_available() && '' !== $latest ) {
			return sprintf(
				/* translators: 1: installed version, 2: available version. */
				__( 'Version %1$s is installed; version %2$s is available. WordPress controls installation.', 'ran-booster' ),
				'' !== $installed ? $installed : __( 'unknown', 'ran-booster' ),
				$latest
			);
		}

		return sprintf(
			/* translators: %s is the installed version. */
			__( 'Version %s is installed. No newer eligible release was found.', 'ran-booster' ),
			'' !== $installed ? $installed : __( 'unknown', 'ran-booster' )
		);
	}

	private function render_failure_help( string $code, string $message = '', string $reason = '', string $detail = '' ): void {
		$troubleshooting_url = add_query_arg(
			array(
				'page'  => 'ran-booster',
				'tab'   => 'troubleshooting',
				'panel' => 'debug-capture',
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

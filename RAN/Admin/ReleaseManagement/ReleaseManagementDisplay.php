<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

/** @internal Contributes release-specific state and actions to Core-owned package pages. */
final class ReleaseManagementDisplay {
	private const ELIGIBILITY_RECHECK_QUERY_KEY = 'ran_booster_release_recheck';

	private const ADMIN_POST_ACTIONS = array(
		'enable'           => 'ran_booster_release_enable',
		'refresh'          => 'ran_booster_release_refresh',
		'change_channel'   => 'ran_booster_release_change_channel',
		'return_to_branch' => 'ran_booster_release_return_to_branch',
	);

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function renderSettings(
		object $package,
		?ReleaseTrackingStatus $status,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $settingsUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $selectedChannel = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $nonceActions = array(),
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		bool $eligibilityRecheck = false,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $operationNoticeHtml = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $repositoryConflict = array()
	): void {
		if ( ! $this->is_projection( $package ) ) {
			return;
		}

		$status_available = null !== $status;
		$source           = $status_available ? $status->source() : $package->source();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$selectedChannel = 'branch' === $source
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			? ( $this->normalize_release_channel( $selectedChannel ) ?? 'stable' )
			: ( $status_available ? $status->channel() : null );

		$eligibility               = $status_available ? $status->eligibility() : null;
		$eligibility_code          = $status_available ? $eligibility->code() : '';
		$subdirectory_incompatible = 'subdirectory_not_supported' === $eligibility_code;
		$expected_update_uri       = $status_available ? $eligibility->expectedUpdateUri() : '';
		$update_uri_ready          = in_array( $eligibility_code, array( 'eligible', 'target_already_uses_ran_updater' ), true );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$release_view_url  = add_query_arg( array( 'source_view' => 'release_asset' ), $settingsUrl );
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
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$track_nonce_action = $nonceActions['enable'] ?? null;
		} elseif ( $status_available && 'release_asset' === $source && ! $subdirectory_incompatible ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$track_nonce_action = $nonceActions['change_channel'] ?? null;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$refresh_nonce_action = $nonceActions['refresh'] ?? null;
		}
		$track_mode                = 'branch' === $source ? 'branch' : 'managed';
		$failure_code              = $status_available ? $status->failureCode() : '';
		$repository_conflict_code  = $status_available && in_array( $failure_code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true );
		$repository_source_blocked = $status_available
			&& ( 'repository_source_unavailable' === $failure_code
				|| ( 'branch' === $source && 'release_unavailable' === $failure_code ) );
		$repository_blocked        = $repository_conflict_code || $repository_source_blocked;
		$track_disabled            = null === $track_nonce_action || $repository_blocked;
		$repository_status_heading = $repository_source_blocked ? __( 'Storage unavailable', 'ran-booster' ) : __( 'Repository shared', 'ran-booster' );
		$automatic_policy          = $status_available && 'automatic' === $status->deploymentPolicy();
		$recheck_enabled           = $status_available && 'branch' === $source && null === $track_nonce_action;
		$refresh_enabled           = null !== $refresh_nonce_action && ! $repository_blocked;
		$updates_enabled           = $status_available && ! $subdirectory_incompatible && ! $repository_blocked;
		$browser_enabled           = $status_available
			&& 'release_asset' === $source
			&& $eligibility->eligible()
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			&& '' !== ( $nonceActions['list_candidates'] ?? '' )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			&& '' !== ( $nonceActions['inspect_candidate'] ?? '' )
			&& ! $repository_blocked;
		$gate_notice = $repository_conflict_code
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
		?>
		<section class="ran-booster-release-management" aria-labelledby="ran-booster-release-management-heading">
			<header class="ran-booster-package-source-pane__header">
				<?php if ( '' !== $gate_notice ) { ?>
					<div class="notice notice-warning inline" data-ran-booster-release-gate-notice><p><?php echo esc_html( $gate_notice ); ?></p>
					<?php
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					if ( $repository_conflict_code && array() !== $repositoryConflict ) {
						?>
						<p><strong><?php esc_html_e( 'Conflicting packages', 'ran-booster' ); ?></strong></p><ul class="ul-disc">
						<?php
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
						foreach ( $repositoryConflict as $other ) {
							?>
						<li><a href="<?php echo esc_url( (string) ( $other['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $other['name'] ?? '' ) ); ?></a>
							<?php
							if ( empty( $other['view_all'] ) ) {
								?>
							(<?php echo esc_html( (string) ( $other['type'] ?? '' ) ); ?>)<?php } ?></li><?php } ?></ul><?php } ?></div>
				<?php } ?>
				<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ if ( '' !== $operationNoticeHtml ) { ?>
					<div class="ran-booster-release-notices" data-ran-booster-release-notices>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. Captured trusted admin operation notice. ?>
						<?php echo $operationNoticeHtml; ?>
					</div>
				<?php } ?>
				<?php if ( 'branch' === $source ) { ?>
					<?php if ( $automatic_policy ) { ?>
						<div class="notice notice-warning inline">
							<p><?php esc_html_e( 'Switching resets Automatic to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
						</div>
					<?php } ?>
					<div class="ran-booster-source-transition">
						<p><?php esc_html_e( 'Branch currently active.', 'ran-booster' ); ?></p>
						<button type="submit" class="button button-primary" form="<?php echo esc_attr( $track_form_id ); ?>"<?php disabled( $track_disabled ); ?> aria-disabled="<?php echo $track_disabled ? 'true' : 'false'; ?>"><?php esc_html_e( 'Use releases', 'ran-booster' ); ?></button>
					</div>
				<?php } elseif ( $status_available && 'release_asset' === $source && $subdirectory_incompatible ) { ?>
					<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ $this->render_return_to_branch( $package, $nonceActions['return_to_branch'] ?? null, '', $automatic_policy ); ?>
				<?php } ?>
				<h3 id="ran-booster-release-management-heading"><?php esc_html_e( 'Release readiness', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-readiness-panel">
				<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ if ( $eligibilityRecheck ) { ?>
					<div class="notice notice-success inline" data-ran-booster-package-success data-ran-booster-eligibility-recheck>
						<p><strong><?php esc_html_e( 'Eligibility recheck complete.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'The current eligibility evidence is shown below.', 'ran-booster' ); ?></p>
					</div>
				<?php } ?>
				<div class="ran-booster-readiness-panel__top">
					<div>
						<h4><?php echo esc_html( $repository_blocked ? $repository_status_heading : ( $status_available && $eligibility->eligible() ? __( 'Ready for releases', 'ran-booster' ) : __( 'Not ready for releases', 'ran-booster' ) ) ); ?></h4>
					</div>
					<?php if ( ! $status_available || ! $eligibility->eligible() || $repository_blocked ) { ?>
						<span class="ran-booster-badge ran-booster-badge--error"><?php esc_html_e( 'Unavailable', 'ran-booster' ); ?></span>
					<?php } ?>
				</div>
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item <?php echo $status_available && ! $subdirectory_incompatible && $update_uri_ready ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Installed identity and Update URI', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( ! $status_available ? __( 'Cannot be checked until release status is available.', 'ran-booster' ) : ( $subdirectory_incompatible ? __( 'This package uses a repository subdirectory. Published releases require the repository root.', 'ran-booster' ) : ( $update_uri_ready ? __( 'The installed package identity and Update URI match the configured repository.', 'ran-booster' ) : $this->update_uri_readiness_message( $eligibility_code ) ) ) ); ?></span>
					</li>
					<li class="ran-booster-readiness-item <?php echo $status_available && 'release_asset' === $source && '' === $status->failureCode() ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Release status', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( ! $status_available ? __( 'Cannot be checked until release status is available.', 'ran-booster' ) : ( 'release_asset' === $source ? $this->release_status_message( $status ) : __( 'Branch is active; releases are not tracked.', 'ran-booster' ) ) ); ?></span>
					</li>
				</ul>
				<div class="ran-booster-release-notices" data-ran-booster-release-notices>
					<?php if ( $status_available && 'branch' === $source && ! $eligibility->eligible() && $this->requires_update_uri_remediation( $eligibility ) ) { ?>
						<div class="ran-booster-settings-section__body ran-booster-release-remediation">
							<p><strong><?php esc_html_e( 'Add this exact header, deploy the corrected package, then check again:', 'ran-booster' ); ?></strong></p>
							<p class="ran-booster-release-code"><code><?php echo esc_html( 'Update URI: ' . $expected_update_uri ); ?></code></p>
						</div>
					<?php } elseif ( $status_available && 'release_asset' === $source && '' !== $status->failureCode() && ! $repository_blocked ) { ?>
						<div class="notice notice-warning inline">
						<p><?php echo esc_html( $this->diagnostic_message( $status->failureCode() ) ); ?></p>
						<?php $this->render_failure_help( $status->failureCode() ); ?>
					</div>
					<?php } elseif ( $status_available && 'release_asset' === $source && ! $browser_enabled && ! $repository_blocked ) { ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Published release browsing is unavailable until the current package status and controls are available.', 'ran-booster' ); ?></p></div>
					<?php } elseif ( $status_available && $track_disabled && 'release_asset' === $source && ! $repository_blocked ) { ?>
						<?php $this->render_controls_unavailable(); ?>
					<?php } ?>
				</div>
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
						<button type="submit" class="button button-primary"<?php disabled( ! $recheck_enabled ); ?> aria-disabled="<?php echo $recheck_enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Recheck eligibility', 'ran-booster' ); ?></button>
					</form>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"<?php echo $refresh_enabled ? ' data-ran-booster-package-mutation' : ''; ?>>
						<?php if ( $refresh_enabled ) { ?>
							<?php $this->admin_post_fields( 'refresh', $package, $refresh_nonce_action ); ?>
							<input type="hidden" name="return_to_settings" value="1">
						<?php } ?>
						<button type="submit" class="button button-primary"<?php disabled( ! $refresh_enabled ); ?> aria-disabled="<?php echo $refresh_enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Check releases', 'ran-booster' ); ?></button>
					</form>
					<a class="button<?php echo $updates_enabled ? '' : ' disabled'; ?>"<?php echo $updates_enabled ? ' href="' . esc_url( admin_url( 'update-core.php' ) ) . '"' : ' aria-disabled="true" tabindex="-1"'; ?>><?php esc_html_e( 'Open WordPress updates', 'ran-booster' ); ?></a>
					<?php if ( '' !== $expected_update_uri ) { ?>
						<span class="ran-booster-readiness-actions__links">
							<a href="<?php echo esc_url( $expected_update_uri ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open repository', 'ran-booster' ); ?></a>
						</span>
					<?php } ?>
					<?php if ( $status_available ) { ?>
						<?php do_action( 'ran_booster_admin_package_release_readiness_actions', $package, $status ); ?>
					<?php } ?>
				</div>
			</div>
		</section>
		<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ $release_track_summary = null === $selectedChannel ? __( 'Unknown', 'ran-booster' ) : $this->release_track_label( $selectedChannel ); ?>
		<details id="ran-booster-release-track-settings" class="ran-booster-settings-disclosure ran-booster-release-track-section" data-ran-booster-package-disclosure>
			<summary>
				<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Release Track', 'ran-booster' ); ?></h3>
				<small class="ran-booster-advanced-source-summary">
					<span class="ran-booster-advanced-source-summary__badge" data-ran-booster-release-track-summary><?php echo esc_html( $release_track_summary ); ?></span>
				</small>
			</summary>
			<div class="ran-booster-settings-disclosure__body">
				<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ $this->render_release_track_settings( $track_mode, $track_disabled, $selectedChannel, $package, $track_nonce_action, $track_form_id ); ?>
				<?php /* phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. */ $this->render_managed_candidate_browser( $status, $nonceActions, $browser_enabled ); ?>
			</div>
		</details>
		<?php
	}

	/** @param array<string,string> $nonce_actions */
	private function render_managed_candidate_browser( ?ReleaseTrackingStatus $status, array $nonce_actions, bool $enabled ): void {
		$list_nonce        = $enabled ? ( $nonce_actions['list_candidates'] ?? '' ) : '';
		$inspect_nonce     = $enabled ? ( $nonce_actions['inspect_candidate'] ?? '' ) : '';
		$native_update_url = $enabled && null !== $status ? $this->native_update_url( $status ) : '';
		?>
		<div
			class="ran-booster-managed-release-browser<?php echo $enabled ? '' : ' is-disabled'; ?>"
			data-ran-booster-managed-release-browser
			data-ran-booster-managed-release-browser-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"
			<?php if ( $enabled && null !== $status ) { ?>
			data-ran-booster-managed-release-type="<?php echo esc_attr( $status->type() ); ?>"
			data-ran-booster-managed-release-identifier="<?php echo esc_attr( $status->identifier() ); ?>"
			data-ran-booster-managed-release-revision="<?php echo esc_attr( (string) $status->sourceRevision() ); ?>"
			data-ran-booster-managed-release-channel="<?php echo esc_attr( $status->channel() ); ?>"
			data-ran-booster-managed-release-list-nonce="<?php echo esc_attr( $list_nonce ); ?>"
			data-ran-booster-managed-release-inspect-nonce="<?php echo esc_attr( $inspect_nonce ); ?>"
			data-ran-booster-managed-release-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-ran-booster-managed-release-native-update-url="<?php echo esc_url( $native_update_url ); ?>"
			data-ran-booster-managed-release-native-update-version="<?php echo esc_attr( $status->latestVersion() ); ?>"
			data-ran-booster-managed-release-native-update-release-id="<?php echo esc_attr( $status->nativeOfferReleaseId() ); ?>"
			<?php } ?>
		>
			<div class="ran-booster-managed-release-browser__header">
				<div>
					<h4><?php esc_html_e( 'Releases', 'ran-booster' ); ?></h4>
					<p><?php esc_html_e( 'Review the latest eligible release and the installed version.', 'ran-booster' ); ?></p>
				</div>
				<button type="button" class="button" data-ran-booster-managed-release-retry<?php disabled( ! $enabled ); ?> aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Refresh releases', 'ran-booster' ); ?></button>
			</div>
			<div class="notice notice-warning inline ran-booster-managed-release-browser__notice" data-ran-booster-managed-release-error hidden>
				<p data-ran-booster-managed-release-error-message></p>
			</div>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-managed-release-candidates hidden>
				<legend class="screen-reader-text"><?php esc_html_e( 'Available published releases', 'ran-booster' ); ?></legend>
				<div data-ran-booster-managed-release-candidate-list></div>
			</fieldset>
			<div class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-managed-release-status>
				<h4 data-ran-booster-managed-release-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
				<p data-ran-booster-managed-release-message><?php echo esc_html( $enabled ? __( 'Check the saved release track for eligible candidates.', 'ran-booster' ) : __( 'Published release browsing is unavailable until the current package status and controls are available.', 'ran-booster' ) ); ?></p>
			</div>
			<a class="button button-primary disabled ran-booster-managed-release-native-update" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update><?php esc_html_e( 'Install now', 'ran-booster' ); ?></a>
		</div>
		<?php
	}

	private function native_update_url( ReleaseTrackingStatus $status ): string {
		if ( ! $status->updateAvailable() || '' === $status->latestVersion() ) {
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function renderAdvancedSourceSection(
		string $mode,
		string $type,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $selectedSource,
		?object $package,
		?ReleaseTrackingStatus $status,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $pageUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $selectedChannel = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $nonceActions = array(),
		array $prospective = array(),
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		bool $eligibilityRecheck = false,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $operationNoticeHtml = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $repositoryConflict = array()
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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( 'release_asset' === $selectedSource ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$this->renderSettings( $package, $status, $pageUrl, $selectedChannel, $nonceActions, $eligibilityRecheck, $operationNoticeHtml, $repositoryConflict );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		} elseif ( 'branch' === $selectedSource && $this->is_projection( $package ) ) {
			if ( 'release_asset' === $package->source() ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$this->render_return_to_branch( $package, $nonceActions['return_to_branch'] ?? null, $operationNoticeHtml, null !== $status && 'automatic' === $status->deploymentPolicy() );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			} elseif ( '' !== $operationNoticeHtml ) {
				?>
				<div class="ran-booster-release-notices" data-ran-booster-release-notices>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. Captured trusted admin operation notice. ?>
					<?php echo $operationNoticeHtml; ?>
				</div>
				<?php
			}
		}
	}

	private function render_return_to_branch( object $package, ?string $nonce_action, string $operation_notice_html = '', bool $automatic_policy = false ): void {
		$enabled = null !== $nonce_action && 'release_asset' === $package->source();
		?>
		<div class="ran-booster-release-return">
			<div class="ran-booster-release-notices" data-ran-booster-release-notices>
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured trusted admin operation notice. ?>
				<?php echo $operation_notice_html; ?>
				<?php if ( ! $enabled ) { ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Branch cannot be selected because source transition controls are temporarily unavailable. No package settings were changed.', 'ran-booster' ); ?></p></div>
				<?php } ?>
			</div>
			<?php if ( $automatic_policy ) { ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Returning resets Automatic to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
				</div>
			<?php } ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ran-booster-release-return-form ran-booster-source-transition" data-ran-booster-source-transition<?php echo $enabled ? ' data-ran-booster-package-mutation' : ''; ?>>
				<?php if ( $enabled ) { ?>
					<?php $this->admin_post_fields( 'return_to_branch', $package, $nonce_action ); ?>
				<?php } ?>
				<p><?php esc_html_e( 'Releases currently active.', 'ran-booster' ); ?></p>
				<button type="submit" class="button button-primary"<?php disabled( ! $enabled ); ?> aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>"><?php esc_html_e( 'Use branch', 'ran-booster' ); ?></button>
			</form>
		</div>
		<?php
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function advancedSourceSummary(
		string $fallback,
		string $mode,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $selectedSource,
		?object $package,
		?ReleaseTrackingStatus $status
	): string {
		if ( 'create' === $mode ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			return 'release_asset' === $selectedSource
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
	 * @return array{heading:string,badges:list<array{label:string}>,status:string}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function advancedSourceSummaryProjection(
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function releaseTrackMeta( string $fallback, object $package, ?ReleaseTrackingStatus $status ): string {
		if ( ! $this->is_projection( $package ) || 'release_asset' !== $package->source() ) {
			return $fallback;
		}
		if ( null === $status ) {
			return $fallback;
		}

		return 'prerelease' === $status->channel()
			? __( 'Preview track', 'ran-booster' )
			: __( 'Stable track', 'ran-booster' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function releaseProviderSupported( object $package, ?ReleaseTrackingStatus $status ): ?bool {
		if ( ! $this->is_projection( $package ) ) {
			return null;
		}

		if ( null === $status ) {
			return null;
		}
		return 'unsupported_provider' !== $status->eligibility()->code();
	}

	private function render_release_track_settings( string $mode, bool $disabled, ?string $selected_channel, object $package, ?string $nonce_action, string $form_id ): void {
		$has_mutation = ! $disabled && null !== $nonce_action;
		?>
		<form id="<?php echo esc_attr( $form_id ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="<?php echo esc_attr( 'branch' === $mode ? 'ran-booster-release-switch-form' : 'ran-booster-release-channel-form' ); ?>"<?php echo 'branch' === $mode ? ' data-ran-booster-source-transition' : ''; ?><?php echo $has_mutation ? ' data-ran-booster-package-mutation' : ''; ?>>
			<?php if ( $has_mutation ) { ?>
				<?php $this->admin_post_fields( 'branch' === $mode ? 'enable' : 'change_channel', $package, $nonce_action ); ?>
			<?php } ?>

		<?php
		$this->render_release_track( $selected_channel, $mode, $disabled );

		echo '</form>';
	}

	private function render_release_track( ?string $selected_channel, string $mode = 'branch', bool $disabled = false, string $description_id = 'ran-booster-release-track-description', ?string $custom_description = null ): void {
		$managed      = 'managed' === $mode;
		$preview      = 'prerelease' === $selected_channel;
		$next_channel = $preview ? 'stable' : 'prerelease';
		$description  = $custom_description ?? ( $managed
			? __( 'Preview includes published alpha, beta, release-candidate, and stable releases; switching affects future eligibility only, resets Automatic to Manual, and does not install or downgrade.', 'ran-booster' )
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
						<button type="submit" class="button ran-booster-release-track-option" name="release_channel" value="<?php echo esc_attr( $next_channel ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Switch to %s releases', 'ran-booster' ), $this->release_track_label( $next_channel ) ) ); ?>">
							<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
						</button>
					<?php } else { ?>
						<label class="button ran-booster-release-track-option">
							<input type="radio" class="screen-reader-text" name="release_channel" value="<?php echo esc_attr( $channel ); ?>"<?php checked( $channel === $selected_channel ); ?> aria-describedby="<?php echo esc_attr( $description_id ); ?>"<?php echo 'branch' === $mode ? ' data-ran-booster-release-channel' : ''; ?>>
							<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
						</label>
					<?php } ?>
				<?php } ?>
			</div>
			<p id="<?php echo esc_attr( $description_id ); ?>" class="description"><?php echo esc_html( $description ); ?></p>
		</fieldset>
		<?php
	}

	private function render_ineligible_release_track( string $selected_channel ): void {
		?>
		<fieldset class="ran-booster-release-track-control is-disabled" disabled>
			<legend class="screen-reader-text"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></legend>
			<div class="button-group ran-booster-release-track-options">
				<?php foreach ( array( 'stable', 'prerelease' ) as $channel ) { ?>
					<label class="button ran-booster-release-track-option">
						<input type="radio" class="screen-reader-text" name="release_channel" value="<?php echo esc_attr( $channel ); ?>"<?php checked( $channel === $selected_channel ); ?>>
						<span><?php echo esc_html( $this->release_track_label( $channel ) ); ?></span>
					</label>
				<?php } ?>
			</div>
			<p class="description"><?php esc_html_e( 'Stable follows final published releases. Preview also includes prereleases.', 'ran-booster' ); ?></p>
			<div class="notice notice-warning inline ran-booster-release-track-notice"><p><?php esc_html_e( 'Complete the eligibility requirements above before choosing a release track.', 'ran-booster' ); ?></p></div>
		</fieldset>
		<?php
	}

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
			<input type="hidden" name="release_channel" value="stable">
			<?php
			$this->render_release_track(
				'stable',
				'branch',
				false,
				'ran-booster-release-channel-description-' . $type,
				__( 'Preview includes eligible alpha, beta and release-candidate builds as well as stable releases. Drafts remain excluded.', 'ran-booster' )
			);
			?>
			<header>
				<h3><?php esc_html_e( 'Published release selection', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Choose one exact release, inspect it, then install the reviewed package.', 'ran-booster' ); ?></p>
			</header>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-release-candidates hidden>
				<legend><strong><?php esc_html_e( 'Choose a published release', 'ran-booster' ); ?></strong></legend>
				<div data-ran-booster-release-candidate-list></div>
			</fieldset>
			<div class="ran-booster-release-status" role="status" aria-live="polite" aria-atomic="true" data-ran-booster-release-status>
				<div>
					<h4 data-ran-booster-release-status-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
					<p>
						<span data-ran-booster-release-status-message><?php esc_html_e( 'Choose a repository above, then select Releases to load eligible stable releases.', 'ran-booster' ); ?></span>
						<button type="button" class="button-link" data-ran-booster-release-switch-branch hidden><?php esc_html_e( 'Use branch', 'ran-booster' ); ?></button>
					</p>
				</div>
				<button type="button" class="button" data-ran-booster-release-retry hidden><?php esc_html_e( 'Retry release check', 'ran-booster' ); ?></button>
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
						<p><?php esc_html_e( 'The exact published release was checked. Its ZIP was downloaded, validated for repository identity, digest, headers and package identity, then discarded. Installation will freshly acquire and verify it again before WordPress changes files.', 'ran-booster' ); ?></p>
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
					<button type="submit" class="button button-primary" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" name="action" value="ran_booster_release_install" data-ran-booster-release-install hidden><?php echo esc_html( 'plugin' === $type ? __( 'Install published plugin', 'ran-booster' ) : __( 'Install published theme', 'ran-booster' ) ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function presentManagement( array $rows, string $surface, array $packages, array $statuses ): array {
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
				if ( '' !== $status->failureCode() ) {
					$rows[ $identifier ]['status'] = $this->diagnostic_message( $status->failureCode() );
				} else {
					$rows[ $identifier ]['status'] = $this->release_status_message( $status );
				}
			}

			if ( 'release_asset' === $status->source() && '' !== $status->failureCode() ) {
				$rows[ $identifier ] = $this->append_exceptional_badge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Needs attention', 'ran-booster' ),
						'tone'  => 'error',
					)
				);
			} elseif ( 'release_asset' === $status->source() && $status->updateAvailable() ) {
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. Public and protected methods retain the existing caller and override contracts.
	public function presentManagementActions( array $actions, string $surface, object $package, ?ReleaseTrackingStatus $status, ?string $refreshNonceAction ): array {
		if ( ! in_array( $surface, array( 'plugin', 'theme' ), true )
			|| ! $this->is_projection( $package )
			|| $surface !== $package->type()
			|| 'release_asset' !== $package->source() ) {
			return $actions;
		}

		$available = null !== $status && $status->eligible();
		$key       = 'ran-booster-release:refresh';
		if ( ! $status?->updateAvailable() && ! isset( $actions[ $key ] ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$nonce_action = $available ? $refreshNonceAction : null;
			if ( null === $nonce_action ) {
				$actions[ $key ] = array(
					'key'           => $key,
					'label'         => __( 'Release check unavailable', 'ran-booster' ),
					'type'          => 'link',
					'url'           => '',
					'disabled'      => true,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->bounded_string( $package->displayName(), 96 ),
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
						'expected_source_revision' => (string) $package->sourceRevision(),
					),
					'disabled'      => false,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->bounded_string( $package->displayName(), 96 ),
					'busy_label'    => __( 'Working…', 'ran-booster' ),
				);
			}
		}

		$native_key = 'ran-booster-release:native-update';
		if ( null !== $status && $status->updateAvailable() && ! isset( $actions[ $native_key ] ) ) {
			$actions[ $native_key ] = array(
				'key'           => $native_key,
				'label'         => __( 'Open WordPress updates', 'ran-booster' ),
				'type'          => 'link',
				'url'           => admin_url( 'update-core.php' ),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $this->bounded_string( $package->displayName(), 96 ),
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

	private function status_matches( ReleaseTrackingStatus $status, object $package ): bool {
		return hash_equals( $package->type(), $status->type() )
			&& hash_equals( $package->identifier(), $status->identifier() )
			&& $package->sourceRevision() === $status->sourceRevision();
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

	private function admin_post_fields( string $operation, object $package, string $nonce ): void {
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_ACTIONS[ $operation ] ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="expected_type" value="<?php echo esc_attr( $package->type() ); ?>">
		<input type="hidden" name="expected_identifier" value="<?php echo esc_attr( $package->identifier() ); ?>">
		<input type="hidden" name="expected_source_revision" value="<?php echo esc_attr( (string) $package->sourceRevision() ); ?>">
		<?php
	}

	private function render_controls_unavailable(): void {
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Published release controls are temporarily unavailable. No package settings were changed.', 'ran-booster' ); ?></p></div>
		<?php
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Public and protected methods retain the existing caller and override contracts. The public renderOperationNotice parameter preserves named-argument compatibility for package-specific notice callers.
	public function renderOperationNotice(
		string $code,
		bool $successful,
		string $type = '',
		string $identifier = '',
		string $channel = '',
		?ReleaseTrackingStatus $status = null
	): void {
		if ( '' === $code ) {
			return;
		}

		$message = $this->result_message( $code, $successful, $type, $channel, $status );
		$tone    = $successful
			? 'notice-success'
			: ( in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed', 'release_repository_conflict', 'repository_release_owner_exists' ), true )
				? 'notice-warning'
				: 'notice-error' );
		?>
		<div class="notice <?php echo esc_attr( $tone ); ?>"<?php echo $successful ? ' data-ran-booster-package-success' : ''; ?>>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( ! $successful && $this->is_diagnostic_code( $code ) ) { ?>
				<?php $this->render_failure_help( $code, $message ); ?>
			<?php } ?>
		</div>
		<?php
	}

	private function result_message( string $code, bool $successful, string $type, string $channel, ?ReleaseTrackingStatus $status ): string {
		if ( ! $successful && $this->is_diagnostic_code( $code ) ) {
			return $this->diagnostic_message( $code );
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
			'refresh_failed' => __( 'The release check could not start. Review this package’s published release settings, then retry.', 'ran-booster' ),
			'branch_restored' => __( 'Branch-based package management is restored.', 'ran-booster' ),
			'source_changed' => __( 'Package settings changed after this browser page was opened. Refresh the page, review the current settings, then try again.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already registers its own release updater. Use one release owner only.', 'ran-booster' ),
			'forbidden' => __( 'You are not authorized to perform this published release operation. No package was installed or adopted.', 'ran-booster' ),
			'unsupported_provider' => __( 'Published releases are not available for this repository provider. Use Branch tracking instead.', 'ran-booster' ),
			'invalid_request' => __( 'The published release request was invalid. No package was installed or adopted. Reload and try again.', 'ran-booster' ),
			'service_unavailable' => __( 'Published release controls are temporarily unavailable. No package settings were changed. No package was installed.', 'ran-booster' ),
			'package_already_exists' => __( 'That plugin or theme is already installed or managed. No package was overwritten.', 'ran-booster' ),
			'installed_but_unmanaged' => __( 'A package now exists but Booster did not adopt it. Verify the installed version and activation state before using Link installed or retrying.', 'ran-booster' ),
			'management_state_uncertain' => __( 'Booster could not confirm the final installed or managed state. Review installed packages and Booster management before retrying.', 'ran-booster' ),
			'installation_cleanup_failed' => __( 'Installation finalization failed while cleaning the temporary archive or releasing the updater lock. Review installed packages and Booster management before retrying.', 'ran-booster' ),
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

	private function diagnostic_message( string $code ): string {
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
			'release_unavailable' => __( 'No matching published release is available yet.', 'ran-booster' ),
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

	private function diagnostic_action( string $code ): string {
		return match ( $code ) {
			'release_repository_conflict',
			'repository_release_owner_exists' => __( 'Stop managing the other packages in Booster before switching this package to releases. Their files can stay installed.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'Return the package to Branch to keep its configured repository subdirectory.', 'ran-booster' ),
			'release_unavailable' => __( 'Publish a release for the selected Stable or Preview track, then recheck eligibility.', 'ran-booster' ),
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
			: $this->release_status_message( $status );
	}

	private function release_status_message( object $status ): string {
		$installed = $this->bounded_string( $status->installedVersion(), 64 );
		$latest    = $this->bounded_string( $status->latestVersion(), 64 );
		if ( $status->updateAvailable() && '' !== $latest ) {
			return sprintf(
				/* translators: 1: installed version, 2: available version. */
				__( 'Version %1$s is installed; version %2$s is available. WordPress controls installation.', 'ran-booster' ),
				'' !== $installed ? $installed : __( 'unknown', 'ran-booster' ),
				$latest
			);
		}

		return sprintf(
			/* translators: %s is the installed version. */
			__( 'Version %s is installed; no newer eligible release was found.', 'ran-booster' ),
			'' !== $installed ? $installed : __( 'unknown', 'ran-booster' )
		);
	}

	private function render_failure_help( string $code, string $message = '' ): void {
		$troubleshooting_url = add_query_arg(
			array(
				'page'  => 'ran-booster',
				'tab'   => 'troubleshooting',
				'panel' => 'debug-capture',
			),
			admin_url( 'admin.php' )
		);
		?>
		<details>
			<summary><?php esc_html_e( 'Why this happened and how to fix it', 'ran-booster' ); ?></summary>
			<p><strong><?php esc_html_e( 'What Booster found:', 'ran-booster' ); ?></strong> <?php echo esc_html( '' !== $message ? $message : $this->diagnostic_message( $code ) ); ?></p>
			<p><strong><?php esc_html_e( 'What to do:', 'ran-booster' ); ?></strong> <?php echo esc_html( $this->diagnostic_action( $code ) ); ?></p>
			<p><a href="<?php echo esc_url( $troubleshooting_url ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a></p>
			<p><strong><?php esc_html_e( 'Technical reason:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $code ); ?></code></p>
		</details>
		<?php
	}

	private function eligibility_message( object $eligibility, string $source ): string {
		return match ( $eligibility->code() ) {
			'subdirectory_not_supported' => 'branch' === $source
				? __( 'Published releases require this plugin or theme to be at the repository root. This package can continue using its configured repository subdirectory with Branch deployments.', 'ran-booster' )
				: __( 'Published releases require this plugin or theme to be at the repository root. Return to Branch to keep using its configured repository subdirectory.', 'ran-booster' ),
			'missing_update_uri' => __( 'This package header does not declare an Update URI for its configured repository.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'This package header Update URI does not match its configured repository.', 'ran-booster' ),
			'unsupported_provider' => __( 'The repository provider does not support published release tracking.', 'ran-booster' ),
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
			default => __( 'Cannot be validated until the provider and repository are ready.', 'ran-booster' ),
		};
	}

	private function requires_update_uri_remediation( object $eligibility ): bool {
		return '' !== $eligibility->expectedUpdateUri()
			&& in_array( $eligibility->code(), array( 'missing_update_uri', 'mismatched_update_uri' ), true );
	}

	private function is_projection( object $package ): bool {
		foreach ( array( 'type', 'identifier', 'displayName', 'source', 'sourceRevision', 'settingsUrl' ) as $method ) {
			if ( ! is_callable( array( $package, $method ) ) ) {
				return false;
			}
		}

		return in_array( $package->type(), array( 'plugin', 'theme' ), true )
			&& in_array( $package->source(), array( 'branch', 'release_asset' ), true )
			&& is_string( $package->identifier() )
			&& '' !== $package->identifier()
			&& $package->sourceRevision() > 0;
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

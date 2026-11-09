<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/** Renders the Core-owned selected-repository relationship view. */
final class RepositoryDetailRenderer {

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, string> $view_urls
	 * @param array<string, string> $view_request_urls
	 * @param callable():(bool|string)|null $render_webhook_panel
	 * @param callable():void|null $render_release_panel
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the existing public renderer argument slots and caller evaluation; receiver_message is not rendered by this component.
	public function render(
		array $row,
		string $provider_label,
		string $list_url,
		string $activity_url,
		bool $receiver_ready,
		string $receiver_message,
		string $active_view,
		array $view_urls,
		array $view_request_urls,
		?callable $render_webhook_panel,
		?callable $render_release_panel
	): void {
		$repository            = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$source                = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$source_key            = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
		$has_branch_consumer   = in_array( $source_key, array( 'branch', 'mixed' ), true ) || true === ( $row['has_branch_consumer'] ?? false );
		$packages              = $this->packages( $row );
		$omitted               = max( 0, (int) ( $row['package_summaries_omitted'] ?? 0 ) );
		$active_view           = in_array( $active_view, array( 'status', 'branch', 'releases' ), true ) ? $active_view : 'status';
		$rendered_webhooks_url = null;
		$webhook_panel         = 'branch' === $active_view ? $this->capture_webhook_panel( $render_webhook_panel, $rendered_webhooks_url ) : '';
		?>
		<div class="ran-booster-repository-detail">
			<header class="ran-booster-repository-detail__header">
				<div>
					<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title"><?php echo esc_html( $repository ); ?></h2>
					<p class="ran-booster-page-heading__description"><?php echo esc_html( $this->summary( $packages, $source, $omitted ) ); ?></p>
				</div>
				<?php if ( is_string( $row['repository_url'] ?? null ) && '' !== $row['repository_url'] ) { ?>
					<a class="button" href="<?php echo esc_url( $row['repository_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s is the repository provider name. */ __( 'Open on %s', 'ran-booster' ), $provider_label ) ); ?></a>
				<?php } ?>
			</header>
			<div class="notice notice-error inline ran-booster-provider-task-error" data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Booster could not load that repository view. The current view is unchanged; choose the view again to retry.', 'ran-booster' ); ?></p></div></div>
			<nav class="ran-booster-provider-task-tabs ran-booster-repository-detail__tabs" aria-label="<?php esc_attr_e( 'Repository integration views', 'ran-booster' ); ?>" hx-boost="true" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:false show:none" hx-push-url="true" hx-history="false" hx-sync="this:replace">
				<ul class="filter-links ran-section-navigation ran-section-navigation--thin">
				<?php
				foreach ( array(
					'status'   => __( 'Status', 'ran-booster' ),
					'branch'   => __( 'Branch', 'ran-booster' ),
					'releases' => __( 'Releases', 'ran-booster' ),
				) as $view => $label ) {
					$url         = is_string( $view_urls[ $view ] ?? null ) ? $view_urls[ $view ] : $list_url;
					$request_url = is_string( $view_request_urls[ $view ] ?? null ) ? $view_request_urls[ $view ] : $url;
					$has_source  = ( 'branch' === $view && in_array( $source_key, array( 'branch', 'mixed' ), true ) )
						|| ( 'releases' === $view && in_array( $source_key, array( 'release_asset', 'mixed' ), true ) );
					?>
					<li><a class="ran-booster-provider-task-tab ran-section-navigation__link<?php echo $active_view === $view ? ' current' : ''; ?>" href="<?php echo esc_url( $url ); ?>" hx-get="<?php echo esc_url( $request_url ); ?>" data-ran-booster-repository-view="<?php echo esc_attr( $view ); ?>" aria-controls="ran-booster-provider-profile-region" <?php echo $active_view === $view ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php if ( $has_source ) { ?>
						<span class="ran-booster-provider-task-tab__source-indicator" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Active for one or more packages in this repository.', 'ran-booster' ); ?></span>
					<?php } ?></a></li>
				<?php } ?>
				</ul>
				<p class="ran-booster-provider-task-progress" data-ran-booster-provider-task-progress role="status" aria-live="polite" hidden><span class="spinner is-active" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Loading repository details…', 'ran-booster' ); ?></span></p>
			</nav>

			<div class="ran-booster-repository-detail__layout">
				<main class="ran-booster-repository-detail__main">
					<?php if ( 'status' === $active_view ) { ?>
						<?php $this->render_status( $row, $packages, $omitted ); ?>
						<?php $this->render_status_links( $row ); ?>
					<?php } elseif ( 'branch' === $active_view && 0 < $omitted ) { ?>
						<?php $this->render_incomplete_workflow_controls( 'branch', $omitted ); ?>
					<?php } elseif ( 'branch' === $active_view ) { ?>
						<?php if ( '' !== $webhook_panel ) { ?>
							<?php echo $webhook_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The provider view supplies Core RepositoryWebhookManagementControls; its fixed panel template escapes model values. ?>
						<?php } else { ?>
							<?php $this->render_unavailable_webhook_guidance( $source_key, $receiver_ready, null !== $render_webhook_panel ); ?>
						<?php } ?>
						<?php $this->render_provider_actions( $row, $provider_label, $rendered_webhooks_url ); ?>
					<?php } elseif ( 0 < $omitted ) { ?>
						<?php $this->render_incomplete_workflow_controls( 'releases', $omitted ); ?>
					<?php } elseif ( null !== $render_release_panel ) { ?>
						<div id="ran-booster-repository-release-workflows"><?php $this->render_release_content( $render_release_panel, $packages ); ?></div>
					<?php } else { ?>
						<div id="ran-booster-repository-release-workflows"><?php $this->render_unavailable_release_guidance( $packages ); ?></div>
					<?php } ?>
				</main>

				<aside class="ran-booster-repository-detail__sidebar">
					<?php $this->render_activity( $row, $activity_url ); ?>
				</aside>
			</div>
			<?php if ( 'status' === $active_view ) { ?>
				<?php $this->render_provider_details( $row ); ?>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * @param callable():void $render_release_panel
	 * @param list<array<string,mixed>> $packages
	 */
	private function render_release_content( callable $render_release_panel, array $packages ): void {
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$render_release_panel();
			$output = (string) ob_get_clean();
		} catch ( \Throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$output = '';
		}
		if ( '' !== trim( $output ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The provider view invokes the repository-release action; Core ReleaseWorkflowControls renders its bounded presenter through the escaping display.
			echo $output;
			return;
		}

		$this->render_unavailable_release_guidance( $packages );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<array<string, mixed>> $packages
	 */
	private function render_status( array $row, array $packages, int $omitted ): void {
		$branch_count  = count( array_filter( $packages, static fn ( array $package ): bool => 'branch' === ( $package['source'] ?? null ) ) );
		$release_count = count( $packages ) - $branch_count;
		?>
		<?php if ( 'mixed' === ( $row['source_key'] ?? null ) ) { ?>
			<div class="notice notice-warning inline" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div data-ran-booster-ui-notice-content><p><strong><?php esc_html_e( 'Conflicting sources.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Review the package settings before using release workflow.', 'ran-booster' ); ?></p></div></div>
		<?php } ?>
		<section class="ran-booster-settings-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-packages-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-packages-heading"><?php esc_html_e( 'Packages using this repository', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Repository relationships are read-only here. Change package-owned settings from the linked plugin or theme.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body ran-booster-repository-detail__table-wrap">
				<table class="widefat striped ran-booster-repository-detail__packages">
					<thead><tr><th><?php esc_html_e( 'Package', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Source', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Updates', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Settings', 'ran-booster' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $packages as $package ) { ?>
						<?php $this->render_package( $package ); ?>
					<?php } ?>
					</tbody>
				</table>
			</div>
		</section>
		<section class="ran-booster-settings-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-integration-summary-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-integration-summary-heading"><?php esc_html_e( 'Integration status', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<dl class="ran-booster-repository-detail__facts">
					<?php if ( 0 < $omitted ) { ?>
						<div><dt><?php esc_html_e( 'Package sources', 'ran-booster' ); ?></dt><dd><?php esc_html_e( 'Exact counts are unavailable while package inventory is incomplete.', 'ran-booster' ); ?></dd></div>
					<?php } else { ?>
						<div><dt><?php esc_html_e( 'Branch demand', 'ran-booster' ); ?></dt><dd>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d is the number of Branch packages. */
									_n( '%d package uses Branch', '%d packages use Branch', $branch_count, 'ran-booster' ),
									$branch_count
								)
							);
							?>
						</dd></div>
						<div><dt><?php echo esc_html( _x( 'Recorded release usage', 'Repository integration status label', 'ran-booster' ) ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of Published-release packages. */ _n( '%d package tracks Releases', '%d packages track Releases', $release_count, 'ran-booster' ), $release_count ) ); ?></dd></div>
					<?php } ?>
					<?php foreach ( $this->integration_details( $row ) as $detail ) { ?>
						<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php $this->render_detail_value( $detail ); ?></dd></div>
					<?php } ?>
				</dl>
			</div>
		</section>
		<?php
	}

	/** @param list<array<string, mixed>> $packages */
	private function render_unavailable_release_guidance( array $packages ): void {
		?>
		<section class="ran-booster-settings-section ran-booster-repository-release-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-release-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-release-heading"><?php esc_html_e( 'Release publishing', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<p><?php esc_html_e( 'Release workflow status is unavailable in this view. Package release settings remain available.', 'ran-booster' ); ?></p>
				<?php foreach ( $packages as $package ) { ?>
					<p><a class="button" href="<?php echo esc_url( (string) ( $package['settings_url'] ?? '' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s is a managed package name. */ _x( 'Open %s settings', 'Managed package settings link', 'ran-booster' ), (string) ( $package['display_name'] ?? '' ) ) ); ?></a></p>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	private function render_incomplete_workflow_controls( string $view, int $omitted ): void {
		$label = 'branch' === $view ? __( 'Manage webhook', 'ran-booster' ) : __( 'Assess release setup', 'ran-booster' );
		?>
		<section class="ran-booster-settings-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-incomplete-inventory-heading">
			<header class="ran-booster-settings-section__header"><h3 id="ran-booster-repository-incomplete-inventory-heading"><?php esc_html_e( 'Package inventory incomplete', 'ran-booster' ); ?></h3></header>
			<div class="ran-booster-settings-section__body">
				<?php /* translators: %d is the number of package summaries omitted from repository inventory. */ ?>
				<p><?php echo esc_html( sprintf( __( '%d connected package is not shown. Refresh repository inventory before using repository-wide workflow controls.', 'ran-booster' ), $omitted ) ); ?></p>
				<p><button type="button" class="button" disabled aria-disabled="true"><?php echo esc_html( $label ); ?></button></p>
			</div>
		</section>
		<?php
	}

	private function render_unavailable_webhook_guidance( string $source, bool $receiver_ready, bool $management_supported ): void {
		$has_branch_consumer = in_array( $source, array( 'branch', 'mixed' ), true );
		if ( $management_supported ) {
			?>
			<section class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-webhook-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Push-to-deploy', 'ran-booster' ); ?></h3>
				</header>
				<div class="ran-booster-settings-section__body">
					<p><?php esc_html_e( 'Repository webhook management is temporarily unavailable for this repository.', 'ran-booster' ); ?></p>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></button></p>
				</div>
			</section>
			<?php
			return;
		}
		if ( $has_branch_consumer && $receiver_ready ) {
			?>
			<section class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-webhook-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Push-to-deploy', 'ran-booster' ); ?></h3>
				</header>
				<div class="ran-booster-settings-section__body">
					<p><?php esc_html_e( 'Assisted webhook management is unavailable in this view. Use the Git provider webhook settings when available.', 'ran-booster' ); ?></p>
				</div>
			</section>
			<?php
			return;
		}
		$message = $has_branch_consumer && ! $receiver_ready
			? __( 'Assisted webhook management is unavailable in this view. Receiver readiness has not been established.', 'ran-booster' )
			: __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' );
		?>
		<section class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-webhook-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Push-to-deploy', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Local readiness and the last recorded remote state.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body">
				<h4 id="ran-booster-repository-webhook-setup-heading"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4>
				<p><?php echo esc_html( $message ); ?></p>
				<?php if ( $has_branch_consumer && ! $receiver_ready ) { ?>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></button></p>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $package */
	private function render_package( array $package ): void {
		$release = 'release_asset' === $package['source'];
		$source  = $release ? __( 'Releases', 'ran-booster' ) : __( 'Branch', 'ran-booster' );
		if ( ! $release && '' !== $package['branch'] ) {
			$source .= ' · ' . $package['branch'];
		}
		if ( ! $release && '' !== $package['subdirectory'] ) {
			$source .= ' · ' . $package['subdirectory'];
		}
		?>
		<tr>
			<td><strong><?php echo esc_html( $package['display_name'] ); ?></strong><br><code><?php echo esc_html( $package['identifier'] ); ?></code></td>
			<td><?php echo esc_html( $source ); ?>
			<?php
			if ( $release ) {
				?>
				<br><span class="description"><?php esc_html_e( 'Ignores pushes', 'ran-booster' ); ?></span><?php } ?></td>
			<td><?php echo esc_html( $this->policy_label( $package['deployment_policy'] ?? null ) ); ?></td>
			<td><a href="<?php echo esc_url( $package['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $package['type'] ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a></td>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function render_activity( array $row, string $activity_url ): void {
		$details  = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), 'is_array' ) );
		$webhooks = array_values( array_filter( $details, fn ( array $detail ): bool => $this->is_webhook_activity_entry( $detail ) ) );
		$releases = array_values( array_filter( $details, fn ( array $detail ): bool => $this->is_release_detail( $detail ) ) );
		if ( array() === $webhooks ) {
			$webhooks = array(
				array(
					'label' => __( 'Recorded hook status', 'ran-booster' ),
					'value' => __( 'Managed hook not yet set', 'ran-booster' ),
				),
				array(
					'label' => __( 'Observation', 'ran-booster' ),
					'value' => __( 'No historical observation', 'ran-booster' ),
				),
				array(
					'label' => __( 'Recorded hook profile', 'ran-booster' ),
					'value' => __( 'Managed hook not yet set', 'ran-booster' ),
				),
				array(
					'label' => __( 'Last checked', 'ran-booster' ),
					'value' => __( 'Never', 'ran-booster' ),
				),
			);
		}
		?>
		<section class="ran-booster-settings-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-activity-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-activity-heading"><?php esc_html_e( 'Management history', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Repository-scoped records. This is local history, not live Git provider state.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body">
			<h4><?php esc_html_e( 'Webhook', 'ran-booster' ); ?></h4>
			<?php $this->render_facts( $webhooks ); ?>
			<?php if ( array() !== $releases ) { ?>
				<h4><?php esc_html_e( 'Release workflow', 'ran-booster' ); ?></h4>
				<?php $this->render_facts( $releases ); ?>
			<?php } ?>
			<p><a href="<?php echo esc_url( $activity_url ); ?>"><?php esc_html_e( 'View repository activity', 'ran-booster' ); ?></a></p>
			</div>
		</section>
		<?php
	}

	/** @param list<array<string, mixed>> $details */
	private function render_facts( array $details ): void {
		?>
		<dl class="ran-booster-repository-detail__facts">
		<?php foreach ( $details as $detail ) { ?>
			<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php $this->render_detail_value( $detail ); ?></dd></div>
		<?php } ?>
		</dl>
		<?php
	}

	/** @param array<string, mixed> $detail */
	private function is_release_detail( array $detail ): bool {
		return 'release_workflow' === ( $detail['category'] ?? null )
			|| $this->is_release_automation_key( $detail['key'] ?? null );
	}

	/** @param array<string, mixed> $row */
	private function render_provider_details( array $row ): void {
		$details = array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				fn ( mixed $detail ): bool => is_array( $detail )
					&& ! $this->is_release_detail( $detail )
					&& ! $this->is_webhook_activity_entry( $detail )
			)
		);
		if ( array() === $details ) {
			return;
		}
		?>
		<section class="ran-booster-settings-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-details-heading">
			<header class="ran-booster-settings-section__header"><h3 id="ran-booster-repository-details-heading"><?php esc_html_e( 'Repository details', 'ran-booster' ); ?></h3></header>
			<div class="ran-booster-settings-section__body"><?php $this->render_facts( $details ); ?></div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $entry */
	private function is_webhook_activity_entry( array $entry ): bool {
		$key = $entry['key'] ?? null;

		return is_string( $key ) && str_starts_with( $key, 'core:webhook-' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<array<string, mixed>>
	 */
	private function integration_details( array $row ): array {
		return array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				fn ( mixed $detail ): bool => is_array( $detail )
					&& ( in_array( $detail['category'] ?? null, array( 'webhook', 'release_workflow' ), true )
						|| str_starts_with( (string) ( $detail['key'] ?? '' ), 'core:webhook-' )
						|| $this->is_release_automation_key( $detail['key'] ?? null ) )
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<array<string, mixed>>
	 */
	private function packages( array $row ): array {
		return array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), static fn ( mixed $package ): bool => is_array( $package ) ) );
	}

	/** @param callable():(bool|string)|null $render_webhook_panel */
	private function capture_webhook_panel( ?callable $render_webhook_panel, ?string &$rendered_webhooks_url ): string {
		if ( null === $render_webhook_panel ) {
			return '';
		}

		$buffer_level = ob_get_level();
		ob_start();
		try {
			$rendered = $render_webhook_panel();
			$panel    = (string) ob_get_clean();
		} catch ( \Throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			return '';
		}

		if ( ! $rendered || '' === trim( $panel ) ) {
			return '';
		}
		$rendered_webhooks_url = is_string( $rendered ) ? $rendered : null;
		return $panel;
	}

	/** @param array<string, mixed> $detail */
	private function render_detail_value( array $detail ): void {
		$value    = is_string( $detail['value'] ?? null ) ? $detail['value'] : '';
		$tone     = is_string( $detail['tone'] ?? null ) ? $detail['tone'] : '';
		$datetime = is_string( $detail['datetime'] ?? null ) ? $detail['datetime'] : '';
		if ( '' !== $tone ) {
			$intent = array(
				'neutral' => 'none',
				'pending' => 'none',
				'ok'      => 'stable',
				'success' => 'stable',
				'warning' => 'medium',
				'error'   => 'high',
				'info'    => 'informational',
			)[ $tone ] ?? '';
			?>
			<?php echo StatusBadge::for_intent( (string) $value, (string) $intent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
			<?php
			return;
		}
		if ( '' !== $datetime ) {
			?>
			<time datetime="<?php echo esc_attr( $datetime ); ?>"><?php echo esc_html( $value ); ?></time>
			<?php
			return;
		}
		echo esc_html( $value );
	}

	/** @param list<array<string, mixed>> $packages */
	private function summary( array $packages, string $source, int $omitted ): string {
		if ( 0 < $omitted ) {
			return sprintf(
				/* translators: 1: shown package count, 2: omitted package count, 3: repository source summary. */
				__( '%1$d packages shown; %2$d more connected · %3$s', 'ran-booster' ),
				count( $packages ),
				$omitted,
				$source
			);
		}

		return sprintf(
			/* translators: 1: number of packages, 2: repository source summary. */
			_n( '%1$d package · %2$s', '%1$d packages · %2$s', count( $packages ), 'ran-booster' ),
			count( $packages ),
			$source
		);
	}

	private function policy_label( mixed $policy ): string {
		return match ( $policy ) {
			'automatic' => __( 'Automatic', 'ran-booster' ),
			'manual'    => __( 'Manual', 'ran-booster' ),
			default     => __( 'Disabled', 'ran-booster' ),
		};
	}

	private function is_release_automation_key( mixed $key ): bool {
		return is_string( $key ) && (
			str_starts_with( $key, 'core:release-workflow-' )
			|| 1 === preg_match( '/\A[a-z][a-z0-9_-]{0,63}:release-automation-/', $key )
		);
	}

	/** @param array<string, mixed> $row */
	private function render_status_links( array $row ): void {
		$links = array_values(
			array_filter(
				is_array( $row['status_links'] ?? null ) ? $row['status_links'] : array(),
				static fn ( mixed $link ): bool => is_array( $link )
					&& is_string( $link['label'] ?? null ) && '' !== $link['label']
					&& is_string( $link['url'] ?? null ) && '' !== $link['url']
			)
		);
		if ( array() === $links ) {
			return;
		}
		?>
		<p class="ran-booster-repository-detail__status-links">
		<?php foreach ( $links as $index => $link ) { ?>
			<?php
			if ( 0 < $index ) {
				?>
				<span aria-hidden="true">·</span><?php } ?>
			<a href="<?php echo esc_url( (string) $link['url'] ); ?>"><?php echo esc_html( (string) $link['label'] ); ?></a>
		<?php } ?>
		</p>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function render_provider_actions( array $row, string $provider_label, ?string $rendered_webhooks_url ): void {
		$actions = array_values(
			array_filter(
				is_array( $row['actions'] ?? null ) ? $row['actions'] : array(),
				fn ( mixed $action ): bool => is_array( $action ) && ! $this->is_release_automation_key( $action['key'] ?? null ) && ! $this->is_package_or_management_action( $action['key'] ?? null )
					&& ! ( null !== $rendered_webhooks_url && '' !== $rendered_webhooks_url && 'core:provider-webhooks' === ( $action['key'] ?? null )
						&& 'link' === ( $action['type'] ?? null ) && true === ( $action['external'] ?? false ) && true !== ( $action['disabled'] ?? false )
						&& ( $action['url'] ?? null ) === $rendered_webhooks_url )
			)
		);
		if ( array() === $actions ) {
			return;
		}
		?>
		<div class="ran-booster-repository-detail__actions">
		<?php foreach ( $actions as $action ) { ?>
			<?php $described_by = is_string( $action['described_by'] ?? null ) ? $action['described_by'] : ''; ?>
			<?php if ( 'post' === ( $action['type'] ?? null ) ) { ?>
				<form method="post" action="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>">
				<?php foreach ( is_array( $action['hidden'] ?? null ) ? $action['hidden'] : array() as $name => $value ) { ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php } ?>
					<button type="submit" class="button"<?php disabled( true === ( $action['disabled'] ?? false ) ); ?><?php echo '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : ''; ?>><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></button>
				</form>
			<?php } elseif ( true === ( $action['disabled'] ?? false ) ) { ?>
				<button type="button" class="button" disabled aria-disabled="true"<?php echo '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : ''; ?>><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></button>
			<?php } elseif ( 'link' === ( $action['type'] ?? null ) ) { ?>
				<?php $webhooks_link = 'core:provider-webhooks' === ( $action['key'] ?? null ) && true === ( $action['external'] ?? false ); ?>
				<?php /* translators: %s: repository provider name. */ ?>
				<a<?php echo $webhooks_link ? '' : ' class="button"'; ?> href="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>"<?php echo true === ( $action['external'] ?? false ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?><?php echo '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : ''; ?>><?php echo esc_html( $webhooks_link ? sprintf( __( 'Manage repo webhooks on %s', 'ran-booster' ), 'GitHub' === $provider_label ? 'Github' : $provider_label ) : (string) ( $action['label'] ?? '' ) ); ?>
				<?php if ( $webhooks_link ) { ?>
					<span aria-hidden="true">↗</span><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span>
				<?php } ?></a>
			<?php } ?>
		<?php } ?>
		</div>
		<?php
	}

	private function is_package_or_management_action( mixed $key ): bool {
		return is_string( $key ) && ( 'core:webhook-management' === $key || str_starts_with( $key, 'core:package-' ) );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/** Renders the Core-owned selected-repository relationship view. */
final class RepositoryDetailRenderer {

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, string> $viewUrls
	 * @param array<string, string> $viewRequestUrls
	 * @param callable():void|null $renderWebhookPanel
	 * @param callable():void|null $renderReleasePanel
	 */
	public function render(
		array $row,
		string $providerLabel,
		string $listUrl,
		string $activityUrl,
		bool $receiverReady,
		string $receiverMessage,
		string $activeView,
		array $viewUrls,
		array $viewRequestUrls,
		?callable $renderWebhookPanel,
		?callable $renderReleasePanel
	): void {
		$repository = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$source     = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$sourceKey  = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
		$packages   = $this->packages( $row );
		$omitted    = max( 0, (int) ( $row['package_summaries_omitted'] ?? 0 ) );
		$activeView = in_array( $activeView, array( 'status', 'branch', 'releases' ), true ) ? $activeView : 'status';
		?>
		<div class="ran-booster-repository-detail">
			<p class="ran-booster-repository-detail__back"><a href="<?php echo esc_url( $listUrl ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
			<header class="ran-booster-repository-detail__header">
				<div>
					<p class="ran-booster-eyebrow"><?php echo esc_html( $providerLabel ); ?></p>
					<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title"><?php echo esc_html( $repository ); ?></h2>
					<p><?php echo esc_html( $this->summary( $packages, $source, $omitted ) ); ?></p>
				</div>
				<?php if ( is_string( $row['repository_url'] ?? null ) && '' !== $row['repository_url'] ) { ?>
					<a class="button" href="<?php echo esc_url( $row['repository_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s is the repository provider name. */ __( 'Open on %s', 'ran-booster' ), $providerLabel ) ); ?></a>
				<?php } ?>
			</header>
			<p class="ran-booster-provider-task-progress" data-ran-booster-provider-task-progress role="status" aria-live="polite" hidden><span class="spinner is-active" aria-hidden="true"></span><span><?php esc_html_e( 'Loading repository details…', 'ran-booster' ); ?></span></p>
			<div class="notice notice-error inline ran-booster-provider-task-error" data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden><p><?php esc_html_e( 'Booster could not load that repository view. The current view is unchanged; choose the view again to retry.', 'ran-booster' ); ?></p></div>
			<nav class="ran-booster-provider-task-tabs ran-booster-repository-detail__tabs" aria-label="<?php esc_attr_e( 'Repository integration views', 'ran-booster' ); ?>" hx-boost="true" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-push-url="true" hx-history="false" hx-sync="this:replace">
				<?php
				foreach ( array(
					'status'   => __( 'Status', 'ran-booster' ),
					'branch'   => __( 'Branch', 'ran-booster' ),
					'releases' => __( 'Releases', 'ran-booster' ),
				) as $view => $label ) {
					$url        = is_string( $viewUrls[ $view ] ?? null ) ? $viewUrls[ $view ] : $listUrl;
					$requestUrl = is_string( $viewRequestUrls[ $view ] ?? null ) ? $viewRequestUrls[ $view ] : $url;
					$hasSource  = ( 'branch' === $view && in_array( $sourceKey, array( 'branch', 'mixed' ), true ) )
						|| ( 'releases' === $view && 'release_asset' === $sourceKey );
					?>
					<a class="ran-booster-provider-task-tab" href="<?php echo esc_url( $url ); ?>" hx-get="<?php echo esc_url( $requestUrl ); ?>" data-ran-booster-repository-view="<?php echo esc_attr( $view ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $activeView === $view ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php if ( $hasSource ) { ?>
						<span class="ran-booster-provider-task-tab__source-indicator" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Active for one or more packages in this repository.', 'ran-booster' ); ?></span>
					<?php } ?></a>
				<?php } ?>
			</nav>

			<div class="ran-booster-repository-detail__layout">
				<main class="ran-booster-repository-detail__main">
					<?php if ( 'status' === $activeView ) { ?>
						<?php $this->renderStatus( $row, $packages ); ?>
						<?php $this->renderStatusLinks( $row ); ?>
					<?php } elseif ( 'branch' === $activeView && 0 < $omitted ) { ?>
						<?php $this->renderIncompleteWorkflowControls( 'branch', $omitted ); ?>
					<?php } elseif ( 'branch' === $activeView ) { ?>
						<?php if ( null !== $renderWebhookPanel ) { ?>
							<?php $renderWebhookPanel(); ?>
						<?php } else { ?>
							<?php $this->renderUnavailableWebhookGuidance( $sourceKey, $receiverReady ); ?>
						<?php } ?>
						<?php $this->renderProviderActions( $row ); ?>
					<?php } elseif ( 0 < $omitted ) { ?>
						<?php $this->renderIncompleteWorkflowControls( 'releases', $omitted ); ?>
					<?php } elseif ( null !== $renderReleasePanel ) { ?>
						<div id="ran-booster-repository-release-workflows"><?php $this->renderReleaseContent( $renderReleasePanel, $packages ); ?></div>
					<?php } else { ?>
						<div id="ran-booster-repository-release-workflows"><?php $this->renderUnavailableReleaseGuidance( $packages ); ?></div>
					<?php } ?>
				</main>

				<aside class="ran-booster-repository-detail__sidebar">
					<?php $this->renderActivity( $row, $activityUrl ); ?>
				</aside>
			</div>
			<?php if ( 'status' === $activeView ) { ?>
				<?php $this->renderProviderDetails( $row ); ?>
			<?php } ?>
		</div>
		<?php
	}

	/** @param callable():void $renderReleasePanel @param list<array<string,mixed>> $packages */
	private function renderReleaseContent( callable $renderReleasePanel, array $packages ): void {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			$renderReleasePanel();
			$output = (string) ob_get_clean();
		} catch ( \Throwable ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			$output = '';
		}
		if ( '' !== trim( $output ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core owns escaping inside this bounded callback composition seam.
			echo $output;
			return;
		}

		$this->renderUnavailableReleaseGuidance( $packages );
	}

	/** @param array<string, mixed> $row @param list<array<string, mixed>> $packages */
	private function renderStatus( array $row, array $packages ): void {
		$branchCount  = count( array_filter( $packages, static fn ( array $package ): bool => 'branch' === ( $package['source'] ?? null ) ) );
		$releaseCount = count( $packages ) - $branchCount;
		?>
		<?php if ( 'mixed' === ( $row['source_key'] ?? null ) ) { ?>
			<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Conflicting sources.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Review the package settings before using release workflow.', 'ran-booster' ); ?></p></div>
		<?php } ?>
		<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-packages-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-packages-heading"><?php esc_html_e( 'Packages using this repository', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Repository relationships are read-only here. Change package-owned settings from the linked plugin or theme.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body ran-booster-repository-detail__table-wrap">
				<table class="widefat striped ran-booster-repository-detail__packages">
					<thead><tr><th><?php esc_html_e( 'Package', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Source', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Updates', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Settings', 'ran-booster' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $packages as $package ) { ?>
						<?php $this->renderPackage( $package ); ?>
					<?php } ?>
					</tbody>
				</table>
			</div>
		</section>
		<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-integration-summary-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-integration-summary-heading"><?php esc_html_e( 'Integration status', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<dl class="ran-booster-repository-detail__facts">
					<div><dt><?php esc_html_e( 'Branch demand', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of Branch packages. */ _n( '%d package uses Branch', '%d packages use Branch', $branchCount, 'ran-booster' ), $branchCount ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Releases', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of Published-release packages. */ _n( '%d package tracks Releases', '%d packages track Releases', $releaseCount, 'ran-booster' ), $releaseCount ) ); ?></dd></div>
					<?php foreach ( $this->integrationDetails( $row ) as $detail ) { ?>
						<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div>
					<?php } ?>
				</dl>
			</div>
		</section>
		<?php
	}

	/** @param list<array<string, mixed>> $packages */
	private function renderUnavailableReleaseGuidance( array $packages ): void {
		?>
		<section class="ran-booster-settings-section ran-booster-repository-release-section" aria-labelledby="ran-booster-repository-release-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-release-heading"><?php esc_html_e( 'Release publishing', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<p><?php esc_html_e( 'Release workflow setup is unavailable for this repository provider. Package release settings remain available.', 'ran-booster' ); ?></p>
				<?php foreach ( $packages as $package ) { ?>
					<p><a class="button" href="<?php echo esc_url( (string) ( $package['settings_url'] ?? '' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s is a managed package name. */ __( 'Open %s settings', 'ran-booster' ), (string) ( $package['display_name'] ?? '' ) ) ); ?></a></p>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	private function renderIncompleteWorkflowControls( string $view, int $omitted ): void {
		$label = 'branch' === $view ? __( 'Manage webhook', 'ran-booster' ) : __( 'Assess release setup', 'ran-booster' );
		?>
		<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-incomplete-inventory-heading">
			<header class="ran-booster-settings-section__header"><h3 id="ran-booster-repository-incomplete-inventory-heading"><?php esc_html_e( 'Package inventory incomplete', 'ran-booster' ); ?></h3></header>
			<div class="ran-booster-settings-section__body">
				<?php /* translators: %d is the number of package summaries omitted from repository inventory. */ ?>
				<p><?php echo esc_html( sprintf( __( '%d connected package is not shown. Refresh repository inventory before using repository-wide workflow controls.', 'ran-booster' ), $omitted ) ); ?></p>
				<p><button type="button" class="button" disabled aria-disabled="true"><?php echo esc_html( $label ); ?></button></p>
			</div>
		</section>
		<?php
	}

	private function renderUnavailableWebhookGuidance( string $source, bool $receiverReady ): void {
		$hasBranchConsumer = in_array( $source, array( 'branch', 'mixed' ), true );
		if ( $hasBranchConsumer && $receiverReady ) {
			?>
			<section class="ran-booster-settings-section ran-booster-repository-webhook-section" aria-labelledby="ran-booster-repository-webhook-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Repository webhook', 'ran-booster' ); ?></h3>
				</header>
				<div class="ran-booster-settings-section__body">
					<p><?php esc_html_e( 'Core-assisted webhook management is unavailable for this provider. Use the provider webhook settings when available.', 'ran-booster' ); ?></p>
				</div>
			</section>
			<?php
			return;
		}
		$message = $hasBranchConsumer && ! $receiverReady
			? __( 'Repository webhook management is unavailable until this site can receive provider deliveries.', 'ran-booster' )
			: ( $hasBranchConsumer ? __( 'Assisted webhook setup is unavailable for this provider.', 'ran-booster' ) : __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' ) );
		?>
		<section class="ran-booster-settings-section ran-booster-repository-webhook-section" aria-labelledby="ran-booster-repository-webhook-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Push-to-deploy', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Local readiness and the last recorded remote state.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body">
				<h4 id="ran-booster-repository-webhook-setup-heading"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4>
				<p><?php echo esc_html( $message ); ?></p>
				<?php if ( $hasBranchConsumer && ! $receiverReady ) { ?>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></button></p>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $package */
	private function renderPackage( array $package ): void {
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
			<td><?php echo esc_html( $this->policyLabel( $package['deployment_policy'] ?? null ) ); ?></td>
			<td><a href="<?php echo esc_url( $package['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $package['type'] ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a></td>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderActivity( array $row, string $activityUrl ): void {
		$details  = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), 'is_array' ) );
		$webhooks = array_values( array_filter( $details, fn ( array $detail ): bool => $this->isWebhookActivityEntry( $detail ) ) );
		$releases = array_values( array_filter( $details, fn ( array $detail ): bool => $this->isReleaseDetail( $detail ) ) );
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
		<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-activity-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-activity-heading"><?php esc_html_e( 'Management history', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Repository-scoped records. This is local history, not live provider state.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body">
			<h4><?php esc_html_e( 'Webhook', 'ran-booster' ); ?></h4>
			<?php $this->renderFacts( $webhooks ); ?>
			<?php if ( array() !== $releases ) { ?>
				<h4><?php esc_html_e( 'Release workflow', 'ran-booster' ); ?></h4>
				<?php $this->renderFacts( $releases ); ?>
			<?php } ?>
			<p><a href="<?php echo esc_url( $activityUrl ); ?>"><?php esc_html_e( 'View repository activity', 'ran-booster' ); ?></a></p>
			</div>
		</section>
		<?php
	}

	/** @param list<array<string, mixed>> $details */
	private function renderFacts( array $details ): void {
		?>
		<dl class="ran-booster-repository-detail__facts">
		<?php foreach ( $details as $detail ) { ?>
			<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div>
		<?php } ?>
		</dl>
		<?php
	}

	/** @param array<string, mixed> $detail */
	private function isReleaseDetail( array $detail ): bool {
		return 'release_workflow' === ( $detail['category'] ?? null )
			|| $this->isReleaseAutomationKey( $detail['key'] ?? null );
	}

	/** @param array<string, mixed> $row */
	private function renderProviderDetails( array $row ): void {
		$details = array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				fn ( mixed $detail ): bool => is_array( $detail )
					&& ! $this->isReleaseDetail( $detail )
					&& ! $this->isWebhookActivityEntry( $detail )
			)
		);
		if ( array() === $details ) {
			return;
		}
		?>
		<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-details-heading">
			<header class="ran-booster-settings-section__header"><h3 id="ran-booster-repository-details-heading"><?php esc_html_e( 'Repository details', 'ran-booster' ); ?></h3></header>
			<div class="ran-booster-settings-section__body"><?php $this->renderFacts( $details ); ?></div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $entry */
	private function isWebhookActivityEntry( array $entry ): bool {
		$key = $entry['key'] ?? null;

		return is_string( $key ) && str_starts_with( $key, 'core:webhook-' );
	}

	/** @param array<string, mixed> $row @return list<array<string, mixed>> */
	private function integrationDetails( array $row ): array {
		return array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				fn ( mixed $detail ): bool => is_array( $detail )
					&& ( in_array( $detail['category'] ?? null, array( 'webhook', 'release_workflow' ), true )
						|| str_starts_with( (string) ( $detail['key'] ?? '' ), 'core:webhook-' )
						|| $this->isReleaseAutomationKey( $detail['key'] ?? null ) )
			)
		);
	}

	/** @param array<string, mixed> $row @return list<array<string, mixed>> */
	private function packages( array $row ): array {
		return array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), static fn ( mixed $package ): bool => is_array( $package ) ) );
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

	private function policyLabel( mixed $policy ): string {
		return match ( $policy ) {
			'automatic' => __( 'Automatic', 'ran-booster' ),
			'manual'    => __( 'Manual', 'ran-booster' ),
			default     => __( 'Disabled', 'ran-booster' ),
		};
	}

	private function isReleaseAutomationKey( mixed $key ): bool {
		return is_string( $key ) && (
			str_starts_with( $key, 'core:release-workflow-' )
			|| 1 === preg_match( '/\A[a-z][a-z0-9_-]{0,63}:release-automation-/', $key )
		);
	}

	/** @param array<string, mixed> $row */
	private function renderStatusLinks( array $row ): void {
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
	private function renderProviderActions( array $row ): void {
		$actions = array_values(
			array_filter(
				is_array( $row['actions'] ?? null ) ? $row['actions'] : array(),
				fn ( mixed $action ): bool => is_array( $action ) && ! $this->isReleaseAutomationKey( $action['key'] ?? null ) && ! $this->isPackageOrManagementAction( $action['key'] ?? null )
			)
		);
		if ( array() === $actions ) {
			return;
		}
		?>
		<div class="ran-booster-repository-detail__actions">
		<?php foreach ( $actions as $action ) { ?>
			<?php $describedBy = is_string( $action['described_by'] ?? null ) ? $action['described_by'] : ''; ?>
			<?php if ( 'post' === ( $action['type'] ?? null ) ) { ?>
				<form method="post" action="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>">
				<?php foreach ( is_array( $action['hidden'] ?? null ) ? $action['hidden'] : array() as $name => $value ) { ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php } ?>
					<button type="submit" class="button"<?php disabled( true === ( $action['disabled'] ?? false ) ); ?><?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></button>
				</form>
			<?php } elseif ( true === ( $action['disabled'] ?? false ) ) { ?>
				<button type="button" class="button" disabled aria-disabled="true"<?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></button>
			<?php } elseif ( 'link' === ( $action['type'] ?? null ) ) { ?>
				<a class="button" href="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>"<?php echo true === ( $action['external'] ?? false ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?><?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></a>
			<?php } ?>
		<?php } ?>
		</div>
		<?php
	}

	private function isPackageOrManagementAction( mixed $key ): bool {
		return is_string( $key ) && ( 'core:webhook-management' === $key || str_starts_with( $key, 'core:package-' ) );
	}
}

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
		unset( $receiverReady, $receiverMessage );
		$repository = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$source     = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$sourceKey  = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
		$packages   = $this->packages( $row );
		$activeView = in_array( $activeView, array( 'status', 'branch', 'releases' ), true ) ? $activeView : 'status';
		?>
		<div class="ran-booster-repository-detail">
			<p class="ran-booster-repository-detail__back"><a href="<?php echo esc_url( $listUrl ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
			<header class="ran-booster-repository-detail__header">
				<div>
					<p class="ran-booster-eyebrow"><?php echo esc_html( $providerLabel ); ?></p>
					<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title"><?php echo esc_html( $repository ); ?></h2>
					<p><?php echo esc_html( $this->summary( $packages, $source ) ); ?></p>
				</div>
				<?php if ( is_string( $row['repository_url'] ?? null ) && '' !== $row['repository_url'] ) { ?>
					<a class="button" href="<?php echo esc_url( $row['repository_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s is the repository provider name. */ __( 'Open on %s', 'ran-booster' ), $providerLabel ) ); ?></a>
				<?php } ?>
			</header>
			<nav class="ran-booster-provider-task-tabs ran-booster-repository-detail__tabs" aria-label="<?php esc_attr_e( 'Repository integration views', 'ran-booster' ); ?>" hx-boost="true" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-push-url="true" hx-history="false" hx-sync="this:replace">
				<?php
				foreach ( array(
					'status'   => __( 'Status', 'ran-booster' ),
					'branch'   => __( 'Branch deployments', 'ran-booster' ),
					'releases' => __( 'Published releases', 'ran-booster' ),
				) as $view => $label ) {
					$url        = is_string( $viewUrls[ $view ] ?? null ) ? $viewUrls[ $view ] : $listUrl;
					$requestUrl = is_string( $viewRequestUrls[ $view ] ?? null ) ? $viewRequestUrls[ $view ] : $url;
					?>
					<a class="ran-booster-provider-task-tab" href="<?php echo esc_url( $url ); ?>" hx-get="<?php echo esc_url( $requestUrl ); ?>" data-ran-booster-repository-view="<?php echo esc_attr( $view ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $activeView === $view ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php } ?>
			</nav>

			<div class="ran-booster-repository-detail__layout">
				<main class="ran-booster-repository-detail__main">
					<?php if ( 'status' === $activeView ) { ?>
						<?php $this->renderStatus( $row, $packages ); ?>
					<?php } elseif ( 'branch' === $activeView ) { ?>
						<?php if ( null !== $renderWebhookPanel ) { ?>
							<?php $renderWebhookPanel(); ?>
						<?php } else { ?>
							<?php $this->renderUnavailableWebhookCards( $sourceKey ); ?>
						<?php } ?>
					<?php } elseif ( null !== $renderReleasePanel ) { ?>
						<div id="ran-booster-repository-release-workflows"><?php $renderReleasePanel(); ?></div>
					<?php } else { ?>
						<div id="ran-booster-repository-release-workflows"><?php $this->renderUnavailableReleaseCards( $packages ); ?></div>
					<?php } ?>
				</main>

				<aside class="ran-booster-repository-detail__sidebar">
					<?php $this->renderActivity( $row, $activityUrl ); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row @param list<array<string, mixed>> $packages */
	private function renderStatus( array $row, array $packages ): void {
		$branchCount  = count( array_filter( $packages, static fn ( array $package ): bool => 'branch' === ( $package['source'] ?? null ) ) );
		$releaseCount = count( $packages ) - $branchCount;
		?>
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
					<div><dt><?php esc_html_e( 'Branch demand', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of Branch packages. */ _n( '%d package uses Branch deployments', '%d packages use Branch deployments', $branchCount, 'ran-booster' ), $branchCount ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Published releases', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of Published-release packages. */ _n( '%d package tracks Published releases', '%d packages track Published releases', $releaseCount, 'ran-booster' ), $releaseCount ) ); ?></dd></div>
					<?php foreach ( $this->integrationDetails( $row ) as $detail ) { ?>
						<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div>
					<?php } ?>
				</dl>
			</div>
		</section>
		<?php
	}

	/** @param list<array<string, mixed>> $packages */
	private function renderUnavailableReleaseCards( array $packages ): void {
		?>
		<section class="ran-booster-settings-section ran-booster-repository-release-section" aria-labelledby="ran-booster-repository-release-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-release-heading"><?php esc_html_e( 'Published releases', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<p><?php esc_html_e( 'Release automation is unavailable for this repository provider. Package release settings remain available.', 'ran-booster' ); ?></p>
				<?php foreach ( $packages as $package ) { ?>
					<p><a class="button" href="<?php echo esc_url( (string) ( $package['settings_url'] ?? '' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s is a managed package name. */ __( 'Open %s settings', 'ran-booster' ), (string) ( $package['display_name'] ?? '' ) ) ); ?></a></p>
				<?php } ?>
				<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Assess release automation', 'ran-booster' ); ?></button></p>
			</div>
		</section>
		<?php
	}

	private function renderUnavailableWebhookCards( string $source ): void {
		$hasBranchConsumer = in_array( $source, array( 'branch', 'mixed' ), true );
		?>
		<section class="ran-booster-settings-section ran-booster-repository-webhook-section" aria-labelledby="ran-booster-repository-webhook-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Repository webhook', 'ran-booster' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Local readiness and the last recorded remote state.', 'ran-booster' ); ?></p>
			</header>
			<div class="ran-booster-settings-section__body">
				<p><?php echo esc_html( $hasBranchConsumer ? __( 'Assisted webhook setup is unavailable for this provider.', 'ran-booster' ) : __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' ) ); ?></p>
				<section class="ran-booster-repository-webhook-setup" aria-labelledby="ran-booster-repository-webhook-setup-heading">
					<header class="ran-booster-repository-webhook-setup__header">
						<h4 id="ran-booster-repository-webhook-setup-heading"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4>
					</header>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Set up webhook', 'ran-booster' ); ?></button></p>
				</section>
			</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $package */
	private function renderPackage( array $package ): void {
		$release = 'release_asset' === $package['source'];
		$source  = $release ? __( 'Published releases', 'ran-booster' ) : __( 'Branch', 'ran-booster' );
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
			<td><?php echo esc_html( ucfirst( $package['deployment_policy'] ) ); ?></td>
			<td><a href="<?php echo esc_url( $package['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $package['type'] ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a></td>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderActivity( array $row, string $activityUrl ): void {
		$details  = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), 'is_array' ) );
		$webhooks = array_values( array_filter( $details, fn ( array $detail ): bool => ! $this->isReleaseDetail( $detail ) ) );
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
				<h4><?php esc_html_e( 'Release automation', 'ran-booster' ); ?></h4>
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
		return str_starts_with( (string) ( $detail['key'] ?? '' ), 'gh:release-automation-' )
			|| str_starts_with( (string) ( $detail['label'] ?? '' ), 'Release automation' );
	}

	/** @param array<string, mixed> $row @return list<array<string, mixed>> */
	private function integrationDetails( array $row ): array {
		return array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				static fn ( mixed $detail ): bool => is_array( $detail )
					&& ( str_starts_with( (string) ( $detail['key'] ?? '' ), 'core:webhook-' )
						|| str_starts_with( (string) ( $detail['key'] ?? '' ), 'gh:release-automation-' ) )
			)
		);
	}

	/** @param array<string, mixed> $row @return list<array<string, mixed>> */
	private function packages( array $row ): array {
		return array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), static fn ( mixed $package ): bool => is_array( $package ) ) );
	}

	/** @param list<array<string, mixed>> $packages */
	private function summary( array $packages, string $source ): string {
		return sprintf(
			/* translators: 1: number of packages, 2: repository source summary. */
			_n( '%1$d package · %2$s', '%1$d packages · %2$s', count( $packages ), 'ran-booster' ),
			count( $packages ),
			$source
		);
	}
}

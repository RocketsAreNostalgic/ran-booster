<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/** Renders the Core-owned selected-repository relationship view. */
final class RepositoryDetailRenderer {

	/**
	 * @param array<string, mixed> $row
	 * @param callable():void|null $renderWebhookPanel
	 */
	public function render(
		array $row,
		string $providerLabel,
		string $listUrl,
		string $activityUrl,
		bool $receiverReady,
		string $receiverMessage,
		?callable $renderWebhookPanel
	): void {
		$repository = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$source     = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$packages   = $this->packages( $row );
		$omitted    = max( 0, (int) ( $row['package_summaries_omitted'] ?? 0 ) );
		?>
		<div class="ran-booster-repository-detail">
			<p class="ran-booster-repository-detail__back"><a href="<?php echo esc_url( $listUrl ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
			<header class="ran-booster-repository-detail__header">
				<div>
					<p class="ran-booster-eyebrow"><?php echo esc_html( $providerLabel ); ?></p>
					<h4><?php echo esc_html( $repository ); ?></h4>
					<p><?php echo esc_html( $this->summary( $packages, $source, $omitted ) ); ?></p>
				</div>
				<?php if ( is_string( $row['repository_url'] ?? null ) && '' !== $row['repository_url'] ) { ?>
					<a class="button" href="<?php echo esc_url( $row['repository_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s is the repository provider name. */ __( 'Open on %s', 'ran-booster' ), $providerLabel ) ); ?></a>
				<?php } ?>
			</header>

			<section class="ran-booster-repository-detail__receiver" aria-labelledby="ran-booster-repository-receiver-heading">
				<h5 id="ran-booster-repository-receiver-heading"><?php esc_html_e( 'Provider receiver', 'ran-booster' ); ?></h5>
				<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $receiverReady ? 'ok' : 'warning' ); ?>"><?php echo esc_html( $receiverReady ? __( 'Ready', 'ran-booster' ) : __( 'Needs attention', 'ran-booster' ) ); ?></span>
				<p><?php echo esc_html( $receiverMessage ); ?></p>
			</section>

			<section aria-labelledby="ran-booster-repository-packages-heading">
				<h5 id="ran-booster-repository-packages-heading"><?php esc_html_e( 'Packages using this repository', 'ran-booster' ); ?></h5>
				<p class="description"><?php esc_html_e( 'Repository relationships are read-only here. Change package-owned settings from the linked plugin or theme.', 'ran-booster' ); ?></p>
				<div class="ran-booster-repository-detail__table-wrap">
					<table class="widefat striped ran-booster-repository-detail__packages">
						<thead><tr><th><?php esc_html_e( 'Package', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Source', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Updates', 'ran-booster' ); ?></th><th><?php esc_html_e( 'Settings', 'ran-booster' ); ?></th></tr></thead>
						<tbody>
						<?php
						foreach ( $packages as $package ) {
							$this->renderPackage( $package ); }
						?>
						</tbody>
					</table>
				</div>
			</section>

			<section aria-labelledby="ran-booster-repository-webhook-heading">
				<h5 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Repository webhook', 'ran-booster' ); ?></h5>
				<?php if ( null !== $renderWebhookPanel ) { ?>
					<p class="description"><?php esc_html_e( 'One repository webhook is shared by eligible Branch packages. Published-release packages are shown for context and ignore pushes.', 'ran-booster' ); ?></p>
					<?php $renderWebhookPanel(); ?>
				<?php } else { ?>
					<p class="description"><?php esc_html_e( 'No eligible Branch package uses this repository. Published-release packages ignore pushes, so webhook operations are unavailable.', 'ran-booster' ); ?></p>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></button></p>
				<?php } ?>
			</section>

			<?php $this->renderReleaseAutomation( $row ); ?>
			<?php $this->renderActivity( $row, $activityUrl ); ?>
		</div>
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
			<td><?php echo esc_html( $this->policyLabel( $package['deployment_policy'] ) ); ?></td>
			<td><a href="<?php echo esc_url( $package['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $package['type'] ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a></td>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderReleaseAutomation( array $row ): void {
		$details = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), static fn ( mixed $detail ): bool => is_array( $detail ) && str_starts_with( (string) ( $detail['key'] ?? '' ), 'gh:release-automation-' ) ) );
		$actions = array_values( array_filter( is_array( $row['actions'] ?? null ) ? $row['actions'] : array(), static fn ( mixed $action ): bool => is_array( $action ) && str_starts_with( (string) ( $action['key'] ?? '' ), 'gh:release-automation-' ) ) );
		if ( array() === $details && array() === $actions ) {
			return;
		}
		?>
		<section aria-labelledby="ran-booster-repository-release-automation-heading">
			<h5 id="ran-booster-repository-release-automation-heading"><?php esc_html_e( 'Release automation', 'ran-booster' ); ?></h5>
			<p class="description"><?php esc_html_e( 'Each package owns its release workflow and source revision. This repository view does not mutate release automation.', 'ran-booster' ); ?></p>
			<dl class="ran-booster-repository-detail__facts">
			<?php
			foreach ( $details as $detail ) {
				?>
				<div><dt><?php echo esc_html( (string) $detail['label'] ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div><?php } ?></dl>
			<div class="ran-booster-repository-detail__actions">
			<?php
			foreach ( $actions as $action ) {
				?>
				<a class="button" href="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></a><?php } ?></div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderActivity( array $row, string $activityUrl ): void {
		$details = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), static fn ( mixed $detail ): bool => is_array( $detail ) && ! str_starts_with( (string) ( $detail['key'] ?? '' ), 'gh:release-automation-' ) ) );
		?>
		<section aria-labelledby="ran-booster-repository-activity-heading">
			<h5 id="ran-booster-repository-activity-heading"><?php esc_html_e( 'Recorded webhook activity', 'ran-booster' ); ?></h5>
			<p class="description"><?php esc_html_e( 'This is local history, not live provider state.', 'ran-booster' ); ?></p>
			<?php
			if ( array() !== $details ) {
				?>
				<dl class="ran-booster-repository-detail__facts">
				<?php
				foreach ( $details as $detail ) {
					?>
				<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div><?php } ?></dl><?php } ?>
			<p><a href="<?php echo esc_url( $activityUrl ); ?>"><?php esc_html_e( 'View all Activity', 'ran-booster' ); ?></a></p>
		</section>
		<?php
	}

	/** @param array<string, mixed> $row @return list<array<string, mixed>> */
	private function packages( array $row ): array {
		return array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), static fn ( mixed $package ): bool => is_array( $package ) ) );
	}

	private function policyLabel( mixed $policy ): string {
		return match ( $policy ) {
			'automatic' => __( 'Automatic', 'ran-booster' ),
			'manual'    => __( 'Manual', 'ran-booster' ),
			default     => __( 'Disabled', 'ran-booster' ),
		};
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
}

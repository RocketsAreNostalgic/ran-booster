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
		$sourceKey  = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
		$packages   = $this->packages( $row );
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

			<div class="ran-booster-repository-detail__layout">
				<main class="ran-booster-repository-detail__main">
					<section class="ran-booster-settings-section" aria-labelledby="ran-booster-repository-packages-heading">
						<header class="ran-booster-settings-section__header">
							<h3 id="ran-booster-repository-packages-heading"><?php esc_html_e( 'Packages using this repository', 'ran-booster' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Repository relationships are read-only here. Change package-owned settings from the linked plugin or theme.', 'ran-booster' ); ?></p>
						</header>
						<div class="ran-booster-settings-section__body ran-booster-repository-detail__table-wrap">
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

					<?php if ( null !== $renderWebhookPanel ) { ?>
						<?php $renderWebhookPanel(); ?>
					<?php } else { ?>
						<?php $this->renderUnavailableWebhookCards( $sourceKey ); ?>
					<?php } ?>
				</main>

				<aside class="ran-booster-repository-detail__sidebar">
					<?php $this->renderActivity( $row, $activityUrl ); ?>
				</aside>
			</div>
		</div>
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
		$details = array_values(
			array_filter(
				is_array( $row['details'] ?? null ) ? $row['details'] : array(),
				static fn ( mixed $detail ): bool => is_array( $detail )
					&& ! str_starts_with( (string) ( $detail['key'] ?? '' ), 'gh:release-automation-' )
					&& ! str_starts_with( (string) ( $detail['label'] ?? '' ), 'Release automation' )
			)
		);
		if ( array() === $details ) {
			$details = array(
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
			<dl class="ran-booster-repository-detail__facts">
			<?php foreach ( $details as $detail ) { ?>
				<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div>
			<?php } ?>
			</dl>
			<p><a href="<?php echo esc_url( $activityUrl ); ?>"><?php esc_html_e( 'View delivery evidence in Activity', 'ran-booster' ); ?></a></p>
			</div>
		</section>
		<?php
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

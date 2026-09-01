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
				<?php } elseif ( true === ( $row['has_branch_consumer'] ?? false ) ) { ?>
					<p class="description"><?php esc_html_e( 'Core-assisted webhook management is unavailable for this provider. Use the provider webhook settings when available.', 'ran-booster' ); ?></p>
				<?php } else { ?>
					<p class="description"><?php esc_html_e( 'No eligible Branch package uses this repository. Published-release packages ignore pushes, so webhook operations are unavailable.', 'ran-booster' ); ?></p>
					<p><button type="button" class="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></button></p>
				<?php } ?>
				<?php $this->renderStatusLinks( $row ); ?>
				<?php $this->renderProviderActions( $row ); ?>
			</section>

			<?php $this->renderReleaseAutomation( $row ); ?>
			<?php $this->renderProviderDetails( $row ); ?>
			<?php $this->renderActivity( $row, $activityUrl ); ?>
		</div>
		<?php
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
			<?php if ( 0 < $index ) { ?>
				<span aria-hidden="true">·</span>
			<?php } ?>
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
				fn ( mixed $action ): bool => is_array( $action ) && ! $this->isReleaseAutomationEntry( $action ) && ! $this->isPackageOrManagementAction( $action )
			)
		);
		if ( array() === $actions ) {
			return;
		}
		?>
		<div class="ran-booster-repository-detail__actions">
		<?php
		foreach ( $actions as $action ) {
			$this->renderAction( $action );
		}
		?>
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
		$details = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), fn ( mixed $detail ): bool => is_array( $detail ) && $this->isReleaseAutomationEntry( $detail ) ) );
		$actions = array_values( array_filter( is_array( $row['actions'] ?? null ) ? $row['actions'] : array(), fn ( mixed $action ): bool => is_array( $action ) && $this->isReleaseAutomationEntry( $action ) ) );
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
				$this->renderAction( $action );
			}
			?>
			</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderProviderDetails( array $row ): void {
		$details = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), fn ( mixed $detail ): bool => is_array( $detail ) && ! $this->isReleaseAutomationEntry( $detail ) && ! $this->isWebhookActivityEntry( $detail ) ) );
		if ( array() === $details ) {
			return;
		}
		?>
		<section aria-labelledby="ran-booster-repository-details-heading">
			<h5 id="ran-booster-repository-details-heading"><?php esc_html_e( 'Repository details', 'ran-booster' ); ?></h5>
			<dl class="ran-booster-repository-detail__facts">
			<?php foreach ( $details as $detail ) { ?>
				<div><dt><?php echo esc_html( (string) ( $detail['label'] ?? '' ) ); ?></dt><dd><?php echo esc_html( (string) ( $detail['value'] ?? '' ) ); ?></dd></div>
			<?php } ?>
			</dl>
		</section>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderActivity( array $row, string $activityUrl ): void {
		$details = array_values( array_filter( is_array( $row['details'] ?? null ) ? $row['details'] : array(), fn ( mixed $detail ): bool => is_array( $detail ) && $this->isWebhookActivityEntry( $detail ) ) );
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

	/** @param array<string, mixed> $entry */
	private function isReleaseAutomationEntry( array $entry ): bool {
		$key = $entry['key'] ?? null;

		return is_string( $key ) && 1 === preg_match( '/\A[a-z][a-z0-9_-]{0,63}:release-automation-/', $key );
	}

	/** @param array<string, mixed> $entry */
	private function isWebhookActivityEntry( array $entry ): bool {
		$key = $entry['key'] ?? null;

		return is_string( $key ) && str_starts_with( $key, 'core:webhook-' );
	}

	/** @param array<string, mixed> $action */
	private function isPackageOrManagementAction( array $action ): bool {
		$key = $action['key'] ?? null;

		return is_string( $key ) && ( 'core:webhook-management' === $key || str_starts_with( $key, 'core:package-' ) );
	}

	/** @param array<string, mixed> $action */
	private function renderAction( array $action ): void {
		$disabled    = true === ( $action['disabled'] ?? false );
		$describedBy = is_string( $action['described_by'] ?? null ) ? $action['described_by'] : '';
		if ( 'post' === ( $action['type'] ?? null ) ) {
			?>
			<form method="post" action="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>">
			<?php foreach ( is_array( $action['hidden'] ?? null ) ? $action['hidden'] : array() as $name => $value ) { ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php } ?>
				<button type="submit" class="button"<?php disabled( $disabled ); ?><?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) $action['label'] ); ?></button>
			</form>
			<?php
			return;
		}
		if ( $disabled ) {
			?>
			<button type="button" class="button" disabled aria-disabled="true"<?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) $action['label'] ); ?></button>
			<?php
			return;
		}
		?>
		<a class="button" href="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>"<?php echo true === ( $action['external'] ?? false ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?><?php echo '' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''; ?>><?php echo esc_html( (string) $action['label'] ); ?></a>
		<?php
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

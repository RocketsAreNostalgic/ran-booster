<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Renders the shared managed-repository list used by Core and official add-ons.
 *
 * Callers own repository state and actions. This component owns only escaped,
 * accessible markup so the common list cannot drift between provider screens.
 */
final class RepositoryTableRenderer {

	/**
	 * @param list<array<string, mixed>> $rows Display-safe repository rows.
	 */
	public function render( string $labelled_by, array $rows ): void {
		?>
		<div class="ran-booster-repository-list" role="list" aria-labelledby="<?php echo esc_attr( $labelled_by ); ?>">
			<?php foreach ( $rows as $row ) { ?>
				<article
					class="ran-booster-repository-record<?php echo 'release_asset' === ( $row['source_key'] ?? '' ) ? ' ran-booster-repository-record--release' : ''; ?>"
					role="listitem"
					data-ran-booster-provider-repository
					data-repository-search="<?php echo esc_attr( strtolower( (string) ( $row['repository'] ?? '' ) ) ); ?>"
				>
					<div class="ran-booster-repository-record__summary">
						<div class="ran-booster-repository-record__identity">
							<?php $this->render_repository( $row ); ?>
							<span class="ran-booster-repository-record__meta"><?php echo esc_html( $this->identity_meta( $row ) ); ?></span>
						</div>
						<div class="ran-booster-repository-record__overview">
							<strong>
								<?php echo esc_html( $this->management_label( $row ) ); ?>
								<?php $this->render_management_detail( $row ); ?>
							</strong>
							<?php $this->render_consequence( $row ); ?>
						</div>
						<div class="ran-booster-repository-record__actions">
							<div class="ran-booster-repository-record__action-group">
								<?php $this->render_inventory_action( $row ); ?>
							</div>
						</div>
					</div>
					<?php $this->render_historical_evidence( $row ); ?>
				</article>
			<?php } ?>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function render_repository( array $row ): void {
		$label = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$url   = is_string( $row['repository_url'] ?? null ) ? $row['repository_url'] : '';

		if ( '' === $url ) {
			echo '<strong class="ran-booster-repository-record__name">' . esc_html( $label ) . '</strong>';

			return;
		}
		?>
		<a class="ran-booster-repository-record__name" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $label ); ?>
			<span class="screen-reader-text"><?php esc_html_e( ' (opens repository in a new tab)', 'ran-booster' ); ?></span>
		</a>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function render_inventory_action( array $row ): void {
		$historical = true === ( $row['historical'] ?? false );
		if ( $historical ) {
			return;
		}
		$url = is_string( $row['detail_url'] ?? null ) ? $row['detail_url'] : '';
		$this->render_action(
			array(
				'label'         => __( 'Manage repository', 'ran-booster' ),
				'url'           => $url,
				'disabled'      => '' === $url,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => is_string( $row['repository'] ?? null ) ? $row['repository'] : '',
			)
		);
	}

	/** @param array<string, mixed> $row */
	private function render_historical_evidence( array $row ): void {
		if ( true !== ( $row['historical'] ?? false ) ) {
			return;
		}

		$details = $this->items( $row, 'details' );
		$actions = $this->items( $row, 'actions' );
		if ( array() === $details && array() === $actions ) {
			return;
		}
		?>
		<div class="ran-booster-repository-record__details">
			<div class="ran-booster-repository-record__details-layout">
				<?php $this->render_details( $details ); ?>
				<?php if ( array() !== $actions ) { ?>
					<div>
						<strong><?php esc_html_e( 'Recorded actions', 'ran-booster' ); ?></strong>
						<div class="ran-booster-repository-record__action-group">
							<?php ( new AdminActionRenderer() )->render( $actions ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/** @param list<array<string, mixed>> $details */
	private function render_details( array $details ): void {
		foreach ( $details as $detail ) {
			$label    = is_string( $detail['label'] ?? null ) ? $detail['label'] : '';
			$value    = is_string( $detail['value'] ?? null ) ? $detail['value'] : '';
			$tone     = is_string( $detail['tone'] ?? null ) ? $detail['tone'] : '';
			$datetime = is_string( $detail['datetime'] ?? null ) ? $detail['datetime'] : '';
			?>
			<div>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( '' !== $tone ) { ?>
					<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $value ); ?></span>
				<?php } elseif ( '' !== $datetime ) { ?>
					<time datetime="<?php echo esc_attr( $datetime ); ?>"><?php echo esc_html( $value ); ?></time>
				<?php } else { ?>
					<span><?php echo esc_html( $value ); ?></span>
				<?php } ?>
			</div>
			<?php
		}
	}

	/** @param array<string, mixed> $action */
	private function render_action( array $action, bool $button = true ): void {
		$label         = is_string( $action['label'] ?? null ) ? $action['label'] : '';
		$url           = is_string( $action['url'] ?? null ) ? $action['url'] : '';
		$disabled      = true === ( $action['disabled'] ?? false );
		$external      = true === ( $action['external'] ?? false );
		$described_by  = is_string( $action['described_by'] ?? null ) ? $action['described_by'] : '';
		$screen_reader = is_string( $action['screen_reader'] ?? null ) ? $action['screen_reader'] : '';
		$class_name    = $button ? 'button' : '';

		if ( '' === $label ) {
			return;
		}
		if ( $disabled || '' === $url ) {
			?>
			<button type="button" class="<?php echo esc_attr( $class_name ); ?>" disabled aria-disabled="true"<?php echo '' === $described_by ? '' : ' aria-describedby="' . esc_attr( $described_by ) . '"'; ?>><?php echo esc_html( $label ); ?></button>
			<?php
			return;
		}
		?>
		<a class="<?php echo esc_attr( $class_name ); ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
			<?php if ( '' !== $screen_reader ) { ?>
				<span class="screen-reader-text">: <?php echo esc_html( $screen_reader ); ?></span>
			<?php } ?>
		</a>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function identity_meta( array $row ): string {
		$type        = is_string( $row['package_type_label'] ?? null ) ? $row['package_type_label'] : '';
		$source      = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$count       = count( $this->strings( $row, 'package_references' ) );
		$count_label = 0 < $count
			? sprintf(
				/* translators: %d is the number of managed packages using a repository. */
				_nx( '%d package', '%d packages', $count, 'Managed packages using a repository', 'ran-booster' ),
				$count
			)
			: '';

		return implode( ' · ', array_filter( array( $type, $source, $count_label ) ) );
	}

	/** @param array<string, mixed> $row */
	private function management_label( array $row ): string {
		if ( is_string( $row['management_label'] ?? null ) && '' !== $row['management_label'] ) {
			return $row['management_label'];
		}

		$policies = $this->items( $row, 'policies' );
		if ( is_string( $policies[0]['label'] ?? null ) ) {
			return $policies[0]['label'];
		}

		$statuses = $this->items( $row, 'statuses' );

		return is_string( $statuses[0]['label'] ?? null ) ? $statuses[0]['label'] : '';
	}

	/** @param array<string, mixed> $row */
	private function render_management_detail( array $row ): void {
		$label = is_string( $row['management_detail'] ?? null ) ? $row['management_detail'] : '';
		if ( '' === $label ) {
			return;
		}
		$tone = is_string( $row['management_tone'] ?? null ) ? $row['management_tone'] : 'neutral';
		?>
		<span aria-hidden="true"> · </span><span class="ran-booster-repository-record__management-detail ran-booster-repository-record__management-detail--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function render_consequence( array $row ): void {
		$message = is_string( $row['consequence'] ?? null ) ? $row['consequence'] : '';
		$id      = is_string( $row['consequence_id'] ?? null ) ? $row['consequence_id'] : '';
		if ( '' === $message ) {
			$message = is_string( $row['status_message'] ?? null ) ? $row['status_message'] : '';
		}
		if ( '' === $message ) {
			$message = is_string( $row['package_message'] ?? null ) ? $row['package_message'] : '';
		}
		if ( '' !== $message ) {
			echo '<p' . ( '' === $id ? '' : ' id="' . esc_attr( $id ) . '"' ) . '>' . esc_html( $message ) . '</p>';
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<array<string, mixed>>
	 */
	private function items( array $row, string $key ): array {
		return is_array( $row[ $key ] ?? null )
			? array_values( array_filter( $row[ $key ], 'is_array' ) )
			: array();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function strings( array $row, string $key ): array {
		return is_array( $row[ $key ] ?? null )
			? array_values( array_filter( $row[ $key ], 'is_string' ) )
			: array();
	}
}

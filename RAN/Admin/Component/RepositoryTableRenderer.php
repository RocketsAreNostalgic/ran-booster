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
	public function render( string $labelledBy, array $rows ): void {
		?>
		<div class="ran-booster-repository-list" role="list" aria-labelledby="<?php echo esc_attr( $labelledBy ); ?>">
			<?php foreach ( $rows as $row ) { ?>
				<article
					class="ran-booster-repository-record<?php echo 'release_asset' === ( $row['source_key'] ?? '' ) ? ' ran-booster-repository-record--release' : ''; ?>"
					role="listitem"
					data-ran-booster-provider-repository
					data-repository-search="<?php echo esc_attr( strtolower( (string) ( $row['repository'] ?? '' ) ) ); ?>"
				>
					<div class="ran-booster-repository-record__summary">
						<div class="ran-booster-repository-record__identity">
							<?php $this->renderRepository( $row ); ?>
							<span class="ran-booster-repository-record__meta"><?php echo esc_html( $this->identityMeta( $row ) ); ?></span>
						</div>
						<div class="ran-booster-repository-record__overview">
							<strong>
								<?php echo esc_html( $this->managementLabel( $row ) ); ?>
								<?php $this->renderManagementDetail( $row ); ?>
							</strong>
							<?php $this->renderConsequence( $row ); ?>
							<?php $this->renderPackageReferences( $this->strings( $row, 'package_references' ) ); ?>
						</div>
						<div class="ran-booster-repository-record__actions">
							<div class="ran-booster-repository-record__action-group">
								<?php $this->renderActions( $this->items( $row, 'actions' ) ); ?>
							</div>
						</div>
					</div>
					<?php $this->renderDetails( $this->items( $row, 'details' ) ); ?>
				</article>
			<?php } ?>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function renderRepository( array $row ): void {
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

	/** @param list<string> $references */
	private function renderPackageReferences( array $references ): void {
		$count = count( $references );
		if ( 1 >= $count ) {
			return;
		}
		?>
		<details class="ran-booster-repository-record__packages-list">
			<summary>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the number of managed packages using one repository. */
						_n( '%d package uses this repository', '%d packages use this repository', $count, 'ran-booster' ),
						$count
					)
				);
				?>
			</summary>
			<ul>
				<?php foreach ( $references as $reference ) { ?>
					<li><code><?php echo esc_html( $reference ); ?></code></li>
				<?php } ?>
			</ul>
		</details>
		<?php
	}

	/** @param list<array<string, mixed>> $actions */
	private function renderActions( array $actions ): void {
		$packageActions = array();
		foreach ( $actions as $action ) {
			$key = is_string( $action['key'] ?? null ) ? $action['key'] : '';
			if ( str_starts_with( $key, 'core:package-' ) ) {
				$packageActions[] = $action;
				continue;
			}
			$this->renderAction( $action );
		}

		if ( 1 === count( $packageActions ) ) {
			$this->renderAction( $packageActions[0] );
		} elseif ( 1 < count( $packageActions ) ) {
			?>
			<details class="ran-booster-repository-record__settings-menu">
				<summary class="button"><?php esc_html_e( 'Package settings', 'ran-booster' ); ?></summary>
				<div>
					<?php foreach ( $packageActions as $action ) { ?>
						<?php $this->renderAction( $action, false ); ?>
					<?php } ?>
				</div>
			</details>
			<?php
		}
	}

	/** @param array<string, mixed> $action */
	private function renderAction( array $action, bool $button = true ): void {
		$label        = is_string( $action['label'] ?? null ) ? $action['label'] : '';
		$url          = is_string( $action['url'] ?? null ) ? $action['url'] : '';
		$disabled     = true === ( $action['disabled'] ?? false );
		$external     = true === ( $action['external'] ?? false );
		$describedBy  = is_string( $action['described_by'] ?? null ) ? $action['described_by'] : '';
		$screenReader = is_string( $action['screen_reader'] ?? null ) ? $action['screen_reader'] : '';
		$className    = $button ? 'button' : '';

		if ( '' === $label ) {
			return;
		}
		if ( $disabled || '' === $url ) {
			?>
			<button type="button" class="<?php echo esc_attr( $className ); ?>" disabled aria-disabled="true"<?php echo '' === $describedBy ? '' : ' aria-describedby="' . esc_attr( $describedBy ) . '"'; ?>><?php echo esc_html( $label ); ?></button>
			<?php
			return;
		}
		?>
		<a class="<?php echo esc_attr( $className ); ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
			<?php if ( '' !== $screenReader ) { ?>
				<span class="screen-reader-text">: <?php echo esc_html( $screenReader ); ?></span>
			<?php } ?>
		</a>
		<?php
	}

	/** @param list<array<string, mixed>> $details */
	private function renderDetails( array $details ): void {
		if ( array() === $details ) {
			return;
		}
		?>
		<div class="ran-booster-repository-record__details">
			<div class="ran-booster-repository-record__details-layout">
				<?php foreach ( $details as $detail ) { ?>
					<?php
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
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row */
	private function identityMeta( array $row ): string {
		$type       = is_string( $row['package_type_label'] ?? null ) ? $row['package_type_label'] : '';
		$source     = is_string( $row['source_label'] ?? null ) ? $row['source_label'] : '';
		$count      = count( $this->strings( $row, 'package_references' ) );
		$countLabel = 0 < $count
			? sprintf(
				/* translators: %d is the number of managed packages using a repository. */
				_n( '%d package', '%d packages', $count, 'ran-booster' ),
				$count
			)
			: '';

		return implode( ' · ', array_filter( array( $type, $source, $countLabel ) ) );
	}

	/** @param array<string, mixed> $row */
	private function managementLabel( array $row ): string {
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
	private function renderManagementDetail( array $row ): void {
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
	private function renderConsequence( array $row ): void {
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

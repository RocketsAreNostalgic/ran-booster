<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Renders normalized Core-owned administration action markup.
 */
final class AdminActionRenderer {

	/** @param array<string, array<string, mixed>> $actions */
	public function render( array $actions, bool $emphasizeFirst = false ): void {
		$position = 0;
		foreach ( $actions as $action ) {
			if ( 'post' === $action['type'] ) {
				$this->renderPost( $action, $emphasizeFirst && 0 === $position );
			} else {
				$this->renderLink( $action, $emphasizeFirst && 0 === $position );
			}
			++$position;
		}
	}

	/** @param array<string, mixed> $action */
	private function renderLink( array $action, bool $primary ): void {
		if ( true === $action['disabled'] || '' === $action['url'] ) {
			?>
			<button type="button" class="button" disabled aria-disabled="true"<?php echo '' === $action['described_by'] ? '' : ' aria-describedby="' . esc_attr( $action['described_by'] ) . '"'; ?>><?php echo esc_html( $action['label'] ); ?></button>
			<?php
			return;
		}
		?>
		<a class="button<?php echo $primary ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $action['url'] ); ?>"<?php echo true === $action['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<?php echo esc_html( $action['label'] ); ?>
			<?php if ( '' !== $action['screen_reader'] ) { ?>
				<span class="screen-reader-text">: <?php echo esc_html( $action['screen_reader'] ); ?></span>
			<?php } ?>
		</a>
		<?php
	}

	/** @param array<string, mixed> $action */
	private function renderPost( array $action, bool $primary ): void {
		$busyLabel    = is_string( $action['busy_label'] ?? null ) ? $action['busy_label'] : '';
		$confirm      = is_string( $action['confirm'] ?? null ) ? $action['confirm'] : '';
		$hasBusyState = '' !== $busyLabel;
		?>
		<form action="<?php echo esc_url( $action['url'] ); ?>" method="post"<?php echo $hasBusyState ? ' class="ran-booster-package-row__update-form"' : ''; ?> data-ran-booster-enhanced-mutation data-ran-booster-package-mutation hx-post="<?php echo esc_url( $action['url'] ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-sync="this:drop">
			<?php foreach ( $action['hidden'] as $name => $value ) { ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php } ?>
			<button
				type="submit"
				class="button<?php echo $primary ? ' button-primary' : ''; ?><?php echo $hasBusyState ? ' button-update-package' : ''; ?>"
				<?php disabled( true === $action['disabled'] ); ?>
				<?php echo '' === $action['described_by'] ? '' : ' aria-describedby="' . esc_attr( $action['described_by'] ) . '"'; ?>
				<?php if ( $hasBusyState ) { ?>
					data-ran-booster-update-button
					data-idle-label="<?php echo esc_attr( $action['label'] ); ?>"
					data-busy-label="<?php echo esc_attr( $busyLabel ); ?>"
					data-update-can-run="<?php echo esc_attr( true === $action['disabled'] ? '0' : '1' ); ?>"
					<?php echo '' === $confirm ? '' : ' data-reinstall-confirm-message="' . esc_attr( $confirm ) . '"'; ?>
				<?php } ?>
			>
				<?php if ( $hasBusyState ) { ?>
					<span data-ran-booster-update-label><?php echo esc_html( $action['label'] ); ?></span>
				<?php } else { ?>
					<?php echo esc_html( $action['label'] ); ?>
				<?php } ?>
				<?php if ( '' !== $action['screen_reader'] ) { ?>
					<span class="screen-reader-text">: <?php echo esc_html( $action['screen_reader'] ); ?></span>
				<?php } ?>
			</button>
		</form>
		<?php
	}
}

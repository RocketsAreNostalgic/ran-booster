<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

use Closure;
use InvalidArgumentException;

/**
 * Renders the shared administration status-summary component.
 */
final class AdminStatusSummaryRenderer {

	public const NEUTRAL   = 'neutral';
	public const PENDING   = 'pending';
	public const READY     = 'ready';
	public const ATTENTION = 'attention';

	/**
	 * @param Closure(): void $renderActions
	 */
	public function render(
		string $state,
		string $heading,
		string $description,
		Closure $renderActions
	): void {
		if ( ! in_array( $state, array( self::NEUTRAL, self::PENDING, self::READY, self::ATTENTION ), true ) ) {
			throw new InvalidArgumentException( 'Administration status summaries require a supported state.' );
		}
		?>
		<div class="ran-booster-status-summary ran-booster-status-summary--<?php echo esc_attr( $state ); ?>">
			<div class="ran-booster-status-summary__status">
				<span class="ran-booster-status-dot is-<?php echo esc_attr( $state ); ?>" aria-hidden="true"></span>
				<div>
					<strong><?php echo esc_html( $heading ); ?></strong>
					<p><?php echo esc_html( $description ); ?></p>
				</div>
			</div>
			<div class="ran-booster-status-summary__actions">
				<?php $renderActions(); ?>
			</div>
		</div>
		<?php
	}
}

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
	 * Public Badge intent for each summary state.
	 *
	 * @var array<string,string>
	 */
	private const INTENTS = array(
		self::NEUTRAL   => 'none',
		self::PENDING   => 'none',
		self::READY     => 'stable',
		self::ATTENTION => 'medium',
	);

	/**
	 * @param Closure(): void $render_actions
	 * @param string          $status_label Short state label drawn as the Badge beside the heading.
	 */
	public function render(
		string $state,
		string $heading,
		string $description,
		Closure $render_actions,
		string $status_label
	): void {
		if ( ! isset( self::INTENTS[ $state ] ) ) {
			throw new InvalidArgumentException( 'Administration status summaries require a supported state.' );
		}
		if ( '' === trim( $status_label ) ) {
			throw new InvalidArgumentException( 'Administration status summaries require a status label.' );
		}
		?>
		<div class="ran-booster-status-summary ran-booster-status-summary--<?php echo esc_attr( $state ); ?>">
			<div class="ran-booster-status-summary__status">
				<p class="ran-booster-status-summary__heading"><strong><?php echo esc_html( $heading ); ?></strong> <?php StatusBadge::print_intent( $status_label, self::INTENTS[ $state ] ); ?></p>
				<p class="ran-booster-status-summary__description"><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="ran-booster-status-summary__actions">
				<?php $render_actions(); ?>
			</div>
		</div>
		<?php
	}
}

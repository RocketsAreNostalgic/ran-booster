<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsRuntimeAvailability;

/**
 * Persistent, pathless warning for already-active unsupported environments.
 */
final class SecretsRuntimeAvailabilityNotice {

	private bool $rendered = false;

	public function __construct(
		private SecretsRuntimeAvailability $availability,
		private ?string $screenId = null
	) {
	}

	public function shouldRender(): bool {
		return current_user_can( 'manage_options' )
			&& BoosterNoticeScope::allows( $this->screenId )
			&& ! $this->availability->isAvailable();
	}

	public function render(): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
			return;
		}
		$this->rendered = true;
		?>
		<div class="notice notice-error" data-ran-booster-secrets-runtime-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster encrypted credentials are unavailable:', 'ran-booster' ); ?></strong>
				<?php echo esc_html( $this->availability->message() ); ?>
				<?php esc_html_e( 'Public repositories and package-only Transporter Blueprints remain available; credential-backed operations and webhooks are paused.', 'ran-booster' ); ?>
			</p>
		</div>
		<?php
	}
}

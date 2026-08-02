<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

/**
 * Persistent warning for an active site outside Booster's database envelope.
 */
final class DatabaseCompatibilityNotice {

	private bool $rendered = false;

	public function __construct(
		private Database $database,
		private ?string $screenId = null
	) {
	}

	public function shouldRender(): bool {
		return current_user_can( 'manage_options' )
			&& BoosterNoticeScope::allows( $this->screenId )
			&& null !== $this->message();
	}

	public function render(): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
			return;
		}
		$this->rendered = true;
		?>
		<div class="notice notice-error" data-ran-booster-database-compatibility-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster database operations are paused:', 'ran-booster' ); ?></strong>
				<?php echo esc_html( $this->message() ?? '' ); ?>
				<?php esc_html_e( 'Existing Booster data was left unchanged. Review Troubleshooting before re-enabling deployments.', 'ran-booster' ); ?>
			</p>
		</div>
		<?php
	}

	private function message(): ?string {
		if ( ! $this->database->isSupported() ) {
			return DatabaseCompatibilityFailure::REQUIREMENT;
		}

		return $this->database->isReady() ? null : DatabaseLifecycleFailure::REQUIREMENT;
	}
}

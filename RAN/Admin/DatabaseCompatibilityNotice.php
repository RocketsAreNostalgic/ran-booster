<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\NoticeIcon;
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
		private ?string $screen_id = null
	) {
	}

	public function should_render(): bool {
		return current_user_can( 'manage_options' )
			&& BoosterNoticeScope::allows( $this->screen_id )
			&& null !== $this->message();
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;
		?>
		<div class="notice notice-error<?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-database-compatibility-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p>
				<strong><?php esc_html_e( 'RAN Booster database operations are paused:', 'ran-booster' ); ?></strong>
				<?php echo esc_html( $this->message() ?? '' ); ?>
				<?php esc_html_e( 'Existing Booster data was left unchanged. Review Troubleshooting before re-enabling deployments.', 'ran-booster' ); ?>
			</p>
		</div><?php NoticeIcon::print_intent( 'error' ); ?></div>
		<?php
	}

	private function message(): ?string {
		if ( ! $this->database->is_supported() ) {
			return DatabaseCompatibilityFailure::REQUIREMENT;
		}

		return $this->database->is_ready() ? null : DatabaseLifecycleFailure::REQUIREMENT;
	}
}

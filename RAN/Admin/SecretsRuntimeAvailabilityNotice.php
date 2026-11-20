<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\NoticeIcon;
use RAN\Secrets\SecretsRuntimeAvailability;

/**
 * Persistent, pathless warning for already-active unsupported environments.
 */
final class SecretsRuntimeAvailabilityNotice {

	private bool $rendered = false;

	public function __construct(
		private SecretsRuntimeAvailability $availability,
		private ?string $screen_id = null
	) {
	}

	public function should_render(): bool {
		return current_user_can( 'manage_options' )
			&& BoosterNoticeScope::allows( $this->screen_id )
			&& ! $this->availability->is_available();
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;
		?>
		<div class="notice notice-error<?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-secrets-runtime-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p>
				<strong><?php esc_html_e( 'RAN Booster encrypted credentials are unavailable:', 'ran-booster' ); ?></strong>
				<?php echo esc_html( $this->availability->message() ); ?>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: opening link tag to Secure credential storage in Settings; 2: closing link tag. */
						esc_html__( 'Public repositories and package-only Transporter Blueprints remain available; %1$scredential-backed operations%2$s and webhooks are paused.', 'ran-booster' ),
						'<a href="' . esc_url( ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) . '?page=ran-booster-settings#ran-booster-storage' ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div><?php NoticeIcon::print_intent( 'error' ); ?></div>
		<?php
	}
}

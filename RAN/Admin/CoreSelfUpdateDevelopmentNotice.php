<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\NoticeIcon;
use RAN\WordPress\CoreSelfUpdatePolicy;

/**
 * Explains the intentional Core-update boundary for a source checkout.
 */
final class CoreSelfUpdateDevelopmentNotice {

	private bool $rendered = false;

	public function __construct(
		private readonly CoreSelfUpdatePolicy $policy,
		private readonly ?string $screen_id = null
	) {
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'admin_notices', array( $this, 'render_global' ) );
		add_action( 'network_admin_notices', array( $this, 'render_global' ) );
	}

	public function enqueue_style(): void {
		if ( $this->should_render() || ( BoosterNoticeScope::is_booster_screen( $this->screen_id ) && $this->authorized() ) ) {
			wp_add_inline_style( 'common', '[data-ran-booster-core-development-notice] { background-color: #e5f3ff; }' );
		}
	}

	public function should_render(): bool {
		$diagnostics = $this->policy->diagnostics();

		return $this->authorized()
			&& BoosterNoticeScope::allows( $this->screen_id )
			&& 'disabled' === ( $diagnostics['effective_mode'] ?? null )
			&& 'source_checkout' === ( $diagnostics['reason'] ?? null );
	}

	/** Render the source-policy fact within the local-development guidance. */
	public function render_local_fact(): void {
		if ( ! $this->rendered && $this->should_render() ) {
			$this->rendered = true;
			echo '<p>' . esc_html__( 'Core updates are disabled to protect this source checkout.', 'ran-booster' ) . '</p>';
		}
	}

	private function authorized(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_plugins' );
	}

	/** Render on non-Booster admin screens through WordPress's notice region. */
	public function render_global(): void {
		if ( BoosterNoticeScope::is_booster_screen( $this->screen_id ) ) {
			return;
		}

		$this->render();
	}

	/** Render on Booster screens immediately after the shared admin shell. */
	public function render_shell_inline(): void {
		if ( ! BoosterNoticeScope::is_booster_screen( $this->screen_id ) ) {
			return;
		}

		$this->render_notice( true );
	}

	public function render(): void {
		$this->render_notice( false );
	}

	private function render_notice( bool $inline ): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;
		?>
		<div class="notice notice-info<?php echo $inline ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-core-development-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p>
				<strong><?php esc_html_e( 'RAN Booster development detected:', 'ran-booster' ); ?></strong>
				<?php esc_html_e( 'Core updates are disabled to protect this source checkout.', 'ran-booster' ); ?>
			</p>
		</div><?php NoticeIcon::print_intent( 'info' ); ?></div>
		<?php
	}
}

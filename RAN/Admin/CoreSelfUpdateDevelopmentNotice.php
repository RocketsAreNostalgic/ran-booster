<?php

declare(strict_types=1);

namespace RAN\Admin;

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
		if ( $this->should_render() ) {
			wp_add_inline_style( 'common', '[data-ran-booster-core-development-notice] { background-color: #e5f3ff; }' );
		}
	}

	public function should_render(): bool {
		$diagnostics = $this->policy->diagnostics();

		return (
			current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network_plugins' )
		)
			&& BoosterNoticeScope::allows( $this->screen_id )
			&& 'disabled' === ( $diagnostics['effective_mode'] ?? null )
			&& 'source_checkout' === ( $diagnostics['reason'] ?? null );
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
		<div class="notice notice-info<?php echo $inline ? ' inline' : ''; ?>" data-ran-booster-core-development-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster development detected:', 'ran-booster' ); ?></strong>
				<?php esc_html_e( 'Core updates are disabled to protect this source checkout.', 'ran-booster' ); ?>
			</p>
		</div>
		<?php
	}
}

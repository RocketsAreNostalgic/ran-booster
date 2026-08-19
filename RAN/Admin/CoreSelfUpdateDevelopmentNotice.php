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
		private readonly ?string $screenId = null
	) {
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueStyle' ) );
		add_action( 'admin_notices', array( $this, 'renderGlobal' ) );
		add_action( 'network_admin_notices', array( $this, 'renderGlobal' ) );
	}

	public function enqueueStyle(): void {
		if ( $this->shouldRender() ) {
			wp_add_inline_style( 'common', '[data-ran-booster-core-development-notice] { background-color: #e5f3ff; }' );
		}
	}

	public function shouldRender(): bool {
		$diagnostics = $this->policy->diagnostics();

		return (
			current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network_plugins' )
		)
			&& BoosterNoticeScope::allows( $this->screenId )
			&& 'disabled' === ( $diagnostics['effective_mode'] ?? null )
			&& 'source_checkout' === ( $diagnostics['reason'] ?? null );
	}

	/** Render on non-Booster admin screens through WordPress's notice region. */
	public function renderGlobal(): void {
		if ( BoosterNoticeScope::isBoosterScreen( $this->screenId ) ) {
			return;
		}

		$this->render();
	}

	/** Render on Booster screens immediately after the shared admin shell. */
	public function renderShellInline(): void {
		if ( ! BoosterNoticeScope::isBoosterScreen( $this->screenId ) ) {
			return;
		}

		$this->renderNotice( true );
	}

	public function render(): void {
		$this->renderNotice( false );
	}

	private function renderNotice( bool $inline ): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
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

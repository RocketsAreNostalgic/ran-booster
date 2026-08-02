<?php

declare(strict_types=1);

namespace RAN\Runtime;

use RAN\Deployment\WordPressWorkerWakeup;

/**
 * Registers only the recovery lifecycle allowed on unsupported Multisite.
 */
final class UnsupportedMultisiteBootstrap {

	private const RECOVERY_DOCUMENT = 'views/multisite-recovery.html';

	private bool $noticeRendered = false;

	public function __construct( private readonly string $pluginFile ) {
	}

	public function register(): void {
		register_activation_hook( $this->pluginFile, array( $this, 'activate' ) );
		register_deactivation_hook( $this->pluginFile, array( $this, 'deactivate' ) );
		add_action( 'network_admin_notices', array( $this, 'renderNotice' ) );
	}

	public function activate(): void {
		wp_die(
			esc_html__(
				'RAN Booster does not support WordPress Multisite in this release. Use a single-site WordPress installation.',
				'ran-booster'
			)
		);
	}

	public function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( WordPressWorkerWakeup::HOOK, array() );
		}
	}

	public function renderNotice(): void {
		if ( ! current_user_can( 'manage_network_plugins' ) || $this->noticeRendered ) {
			return;
		}
		$this->noticeRendered = true;
		?>
		<div class="notice notice-warning is-dismissible" data-ran-booster-unsupported-multisite-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster does not support WordPress Multisite in this release.', 'ran-booster' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Package management and background deployments are paused. While this Multisite safety mode is active, Booster does not intentionally change shared plugin or theme files.', 'ran-booster' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Back up the database, WordPress configuration and Booster private storage before recovery. Deactivate Booster network-wide before changing or restoring the installation. Do not delete Booster tables, its encryption key or encrypted secrets file as a workaround.', 'ran-booster' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Booster itself may still be updated manually through WordPress Updates. Managed package updates remain paused until the site is restored to single-site WordPress or a future Multisite-compatible Booster release is installed.', 'ran-booster' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $this->recoveryUrl() ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Read the version-specific Multisite recovery guide', 'ran-booster' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function recoveryUrl(): string {
		return plugin_dir_url( $this->pluginFile ) . self::RECOVERY_DOCUMENT;
	}
}

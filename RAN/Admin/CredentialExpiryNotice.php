<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsStorageUnavailable;

/**
 * Renders one administrator-only, request-deduplicated expiry notice.
 */
final class CredentialExpiryNotice {

	public const USER_META_KEY = '_ran_booster_credential_expiry_notice_fingerprint';

	private bool $rendered = false;

	/** @var list<array<string, mixed>>|null */
	private ?array $affected = null;

	private bool $storage_unavailable = false;

	public function __construct( private CredentialExpiryReminder $reminders ) {
	}

	public function should_render(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$affected = $this->visible( $this->snapshot() );
		if ( $this->storage_unavailable ) {
			return true;
		}

		if ( array() === $affected ) {
			return false;
		}

		return get_current_user_id() > 0;
	}

	public function should_load_dismissal_script(): bool {
		return $this->should_render() && ! $this->storage_unavailable;
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;

		if ( $this->storage_unavailable ) {
			$this->render_storage_unavailable();
			return;
		}

		$affected    = $this->visible( $this->snapshot() );
		$scope       = BoosterNoticeScope::is_booster_screen() ? 'booster' : 'global';
		$state       = wp_create_nonce( $this->reminders->state_action( $affected, $scope ) );
		$primary     = $affected[0];
		$count       = count( $affected );
		$class       = 'warning' === $primary['stage'] ? 'notice-warning' : 'notice-error';
		$base_url    = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$providers   = array();
		$replacement = null;
		foreach ( $affected as $status ) {
			$providers[ $status['provider'] ] = $status['provider_label'];
			if ( null === $replacement && $status['editable'] ) {
				$replacement = $status;
			}
		}
		?>
		<div class="notice <?php echo esc_attr( $class ); ?><?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?>" data-ran-booster-credential-expiry-notice data-ran-booster-notice-preference="expiry" data-ran-booster-notice-state="<?php echo esc_attr( $state ); ?>" data-ran-booster-notice-scope="<?php echo esc_attr( $scope ); ?>">
			<p>
				<strong><?php esc_html_e( 'Credential expiry reminder:', 'ran-booster' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the number of expiring credentials. */
						_n( '%d credential needs attention.', '%d credentials need attention.', $count, 'ran-booster' ),
						$count
					)
				);
				?>
				<?php
				/* translators: 1: credential label. 2: repository provider label. 3: credential expiry status. */
				echo esc_html( sprintf( __( '%1$s (%2$s): %3$s.', 'ran-booster' ), $primary['label'], $primary['provider_label'], $primary['badge_label'] ) );
				?>
			</p>
			<p>
				<?php foreach ( $providers as $provider => $label ) { ?>
					<a href="<?php echo esc_url( $base_url . '?page=ran-booster&tab=' . rawurlencode( $provider ) . '&view=credentials' ); ?>"><?php /* translators: %s is a repository provider label. */ echo esc_html( sprintf( __( 'Review %s credentials', 'ran-booster' ), $label ) ); ?></a>
				<?php } ?>
				<?php if ( null !== $replacement ) { ?>
					<a href="<?php echo esc_url( $base_url . '?page=ran-booster&tab=' . rawurlencode( $replacement['provider'] ) . '&view=credentials&replace_credential=' . rawurlencode( $replacement['id'] ) ); ?>"><?php esc_html_e( 'Replace credential', 'ran-booster' ); ?></a>
				<?php } else { ?>
					<span><?php esc_html_e( 'This credential is managed by deployment configuration; update it there.', 'ran-booster' ); ?></span>
				<?php } ?>
			</p>
				<p>
					<?php if ( $this->reminders->can_snooze( $affected ) ) { ?>
						<button type="button" class="button" data-ran-booster-notice-preference-mode="snooze"><?php esc_html_e( 'Remind me in 5 days', 'ran-booster' ); ?></button>
					<?php } ?>
					<button type="button" class="button" data-ran-booster-notice-preference-mode="dismiss"><?php esc_html_e( 'Dismiss this reminder', 'ran-booster' ); ?></button>
				</p>
		</div>
		<?php
	}

	/** @return list<array<string, mixed>> */
	private function snapshot(): array {
		if ( null === $this->affected ) {
			try {
				$this->affected = $this->reminders->affected();
			} catch ( SecretsStorageUnavailable ) {
				$this->storage_unavailable = true;
				$this->affected            = array();
			}
		}

		return $this->affected;
	}

	/**
	 * @param list<array<string, mixed>> $affected Affected statuses.
	 * @return list<array<string, mixed>>
	 */
	private function visible( array $affected ): array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return array();
		}
		$scope = BoosterNoticeScope::is_booster_screen() ? 'booster' : 'global';
		return $this->reminders->visible( $affected, $user_id, $scope, new NoticePreference() );
	}

	private function render_storage_unavailable(): void {
		$base_url = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		?>
		<div class="notice notice-error<?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?>" data-ran-booster-secrets-storage-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster encrypted credentials are unavailable:', 'ran-booster' ); ?></strong>
				<?php esc_html_e( 'The encrypted sidecar and its site key are missing, incomplete, or do not match. Credential-backed operations remain paused.', 'ran-booster' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Restore the matching sidecar and site key from the same backup before changing credentials.', 'ran-booster' ); ?>
				<a href="<?php echo esc_url( $base_url . '?page=ran-booster&tab=overview' ); ?>"><?php esc_html_e( 'Review encrypted storage', 'ran-booster' ); ?></a>
			</p>
		</div>
		<?php
	}
}

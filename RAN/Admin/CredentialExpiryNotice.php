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

	private bool $storageUnavailable = false;

	public function __construct( private CredentialExpiryReminder $reminders ) {
	}

	public function shouldRender(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$affected = $this->snapshot();
		if ( $this->storageUnavailable ) {
			return true;
		}

		$fingerprint = $this->reminders->fingerprint( $affected );
		if ( null === $fingerprint ) {
			return false;
		}

		$userId = get_current_user_id();

		return $userId > 0
			&& ! hash_equals( $fingerprint, (string) get_user_meta( $userId, self::USER_META_KEY, true ) );
	}

	public function shouldLoadDismissalScript(): bool {
		return $this->shouldRender() && ! $this->storageUnavailable;
	}

	public function render(): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
			return;
		}
		$this->rendered = true;

		if ( $this->storageUnavailable ) {
			$this->renderStorageUnavailable();
			return;
		}

		$affected    = $this->snapshot();
		$primary     = $affected[0];
		$count       = count( $affected );
		$class       = 'warning' === $primary['stage'] ? 'notice-warning' : 'notice-error';
		$baseUrl     = function_exists( 'is_network_admin' ) && is_network_admin()
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
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible" data-ran-booster-credential-expiry-notice>
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
				<?php echo esc_html( $primary['label'] . ' (' . $primary['provider_label'] . '): ' . $primary['badge_label'] . '.' ); ?>
			</p>
			<p>
				<?php foreach ( $providers as $provider => $label ) { ?>
					<a href="<?php echo esc_url( $baseUrl . '?page=ran-booster&tab=' . rawurlencode( $provider ) ); ?>"><?php echo esc_html( sprintf( 'Review %s credentials', $label ) ); ?></a>
				<?php } ?>
				<?php if ( null !== $replacement ) { ?>
					<a class="button button-primary" href="<?php echo esc_url( $baseUrl . '?page=ran-booster&tab=' . rawurlencode( $replacement['provider'] ) . '&replace_credential=' . rawurlencode( $replacement['id'] ) ); ?>"><?php esc_html_e( 'Replace credential', 'ran-booster' ); ?></a>
				<?php } else { ?>
					<span><?php esc_html_e( 'This credential is managed by deployment configuration; update it there.', 'ran-booster' ); ?></span>
				<?php } ?>
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
				$this->storageUnavailable = true;
				$this->affected           = array();
			}
		}

		return $this->affected;
	}

	private function renderStorageUnavailable(): void {
		$baseUrl = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		?>
		<div class="notice notice-error" data-ran-booster-secrets-storage-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster encrypted credentials are unavailable:', 'ran-booster' ); ?></strong>
				<?php esc_html_e( 'The encrypted sidecar and its site key are missing, incomplete, or do not match. Credential-backed operations remain paused.', 'ran-booster' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Restore the matching sidecar and site key from the same backup before changing credentials.', 'ran-booster' ); ?>
				<a class="button button-primary" href="<?php echo esc_url( $baseUrl . '?page=ran-booster&tab=overview' ); ?>"><?php esc_html_e( 'Review encrypted storage', 'ran-booster' ); ?></a>
			</p>
		</div>
		<?php
	}
}

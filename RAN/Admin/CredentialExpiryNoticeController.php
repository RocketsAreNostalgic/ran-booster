<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsStorageUnavailable;

final class CredentialExpiryNoticeController {

	public const AJAX_ACTION  = 'ran_booster_dismiss_credential_expiry_notice';
	public const NONCE_ACTION = 'ran-booster-credential-expiry-notice';

	public function __construct( private CredentialExpiryReminder $reminders ) {
	}

	public function handle(): mixed {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'You are not allowed to dismiss this notice.', 'ran-booster' ) ),
				403
			);
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'The notice dismissal request expired. Reload the page and try again.', 'ran-booster' ) ),
				403
			);
		}

		$userId = get_current_user_id();
		try {
			$fingerprint = $this->reminders->fingerprint();
		} catch ( SecretsStorageUnavailable ) {
			return wp_send_json_error(
				array(
					'message' => __(
						'Encrypted credential storage is unavailable. Restore the matching sidecar and site key, then reload this page.',
						'ran-booster'
					),
				),
				409
			);
		}
		if ( $userId < 1 || null === $fingerprint ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not identify an active credential reminder.', 'ran-booster' ) ),
				409
			);
		}

		update_user_meta( $userId, CredentialExpiryNotice::USER_META_KEY, $fingerprint );
		if ( ! hash_equals( $fingerprint, (string) get_user_meta( $userId, CredentialExpiryNotice::USER_META_KEY, true ) ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ),
				500
			);
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}
}

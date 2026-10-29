<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsStorageUnavailable;

final class CredentialExpiryNoticeController {

	public const AJAX_ACTION  = 'ran_booster_dismiss_credential_expiry_notice';
	public const NONCE_ACTION = 'ran-booster-credential-expiry-notice';

	public function __construct(
		private CredentialExpiryReminder $reminders,
		private NoticePreference $preferences = new NoticePreference()
	) {
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

		$user_id = get_current_user_id();
		try {
			$affected = $this->reminders->affected();

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
		$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? wp_unslash( $_POST['scope'] ) : '';
		$state = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : '';
		if ( ! in_array( $scope, array( 'booster', 'global' ), true ) ) {
			return wp_send_json_error( array( 'message' => __( 'The notice state is invalid. Reload the page and try again.', 'ran-booster' ) ), 400 );
		}
		$visible = $this->reminders->visible( $affected, $user_id, $scope, $this->preferences );
		$events  = $this->reminders->events( $visible );
		if ( $user_id < 1 || array() === $events || ! wp_verify_nonce( $state, $this->reminders->state_action( $visible, $scope ) ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not identify an active credential reminder.', 'ran-booster' ) ),
				409
			);
		}
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] )
			? wp_unslash( $_POST['mode'] )
			: '';
		if ( ! in_array( $mode, array( NoticePreference::MODE_SNOOZE, NoticePreference::MODE_DISMISS ), true ) ) {
			return wp_send_json_error( array( 'message' => __( 'The notice preference is invalid. Reload the page and try again.', 'ran-booster' ) ), 400 );
		}
		if ( NoticePreference::MODE_SNOOZE === $mode && ! $this->reminders->can_snooze( $visible ) ) {
			return wp_send_json_error( array( 'message' => __( 'One or more credentials need attention within five days and cannot be snoozed.', 'ran-booster' ) ), 409 );
		}

		if ( ! $this->preferences->save_events( $user_id, CredentialExpiryNotice::USER_META_KEY, $events, $mode, $this->reminders->events( $affected ), $this->reminders->now() ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ),
				500
			);
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}
}

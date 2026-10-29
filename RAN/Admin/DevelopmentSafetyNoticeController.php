<?php

declare(strict_types=1);

namespace RAN\Admin;

final class DevelopmentSafetyNoticeController {

	public const AJAX_ACTION   = 'ran_booster_dismiss_development_safety_notice';
	public const NONCE_ACTION  = 'ran-booster-development-safety-notice';
	public const USER_META_KEY = '_ran_booster_development_safety_notice_dismissed';

	public function __construct( private NoticePreference $preferences = new NoticePreference() ) {
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
		if ( $user_id < 1 ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not identify the current administrator.', 'ran-booster' ) ),
				403
			);
		}

		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] )
			? wp_unslash( $_POST['mode'] )
			: NoticePreference::MODE_ACKNOWLEDGE;
		if ( NoticePreference::MODE_ACKNOWLEDGE !== $mode
			|| ! $this->preferences->save( $user_id, self::USER_META_KEY, '1', $mode ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ),
				500
			);
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}
}

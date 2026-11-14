<?php

declare(strict_types=1);

namespace RAN\Admin;

final class DevelopmentSafetyNoticeController {

	public const AJAX_ACTION   = 'ran_booster_dismiss_development_safety_notice';
	public const NONCE_ACTION  = 'ran-booster-development-safety-notice';
	public const NOTICE_STATE  = 'local-development-v2';
	public const USER_META_KEY = '_ran_booster_development_safety_notice_dismissed';

	/** One acknowledgement per notice, keyed by the signed-state token the notice renders. */
	private const USER_META_KEYS = array(
		self::NOTICE_STATE                            => self::USER_META_KEY,
		CoreSelfUpdateDevelopmentNotice::NOTICE_STATE => CoreSelfUpdateDevelopmentNotice::USER_META_KEY,
	);

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

		$mode  = isset( $_POST['mode'] ) && is_string( $_POST['mode'] )
			? wp_unslash( $_POST['mode'] )
			: NoticePreference::MODE_ACKNOWLEDGE;
		$state = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : '';
		$key   = self::USER_META_KEYS[ $state ] ?? null;
		if ( null === $key ) {
			return wp_send_json_error(
				array( 'message' => __( 'The notice dismissal request expired. Reload the page and try again.', 'ran-booster' ) ),
				403
			);
		}
		if ( NoticePreference::MODE_ACKNOWLEDGE !== $mode
			|| ! $this->preferences->save( $user_id, $key, $state, $mode ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ),
				500
			);
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Admin;

final class BackgroundDeploymentFailureNoticeController {

	public const AJAX_ACTION  = 'ran_booster_dismiss_background_failure_notice';
	public const NONCE_ACTION = 'ran-booster-background-failure-notice';

	public function __construct( private BackgroundDeploymentFailureMonitor $monitor ) {
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

		$userId      = get_current_user_id();
		$fingerprint = $this->monitor->fingerprint();
		if ( $userId < 1 || null === $fingerprint ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not identify an active deployment failure.', 'ran-booster' ) ),
				409
			);
		}

		update_user_meta( $userId, BackgroundDeploymentFailureNotice::USER_META_KEY, $fingerprint );
		if ( ! hash_equals( $fingerprint, (string) get_user_meta( $userId, BackgroundDeploymentFailureNotice::USER_META_KEY, true ) ) ) {
			return wp_send_json_error(
				array( 'message' => __( 'RAN Booster could not remember the notice dismissal.', 'ran-booster' ) ),
				500
			);
		}

		return wp_send_json_success( array( 'dismissed' => true ) );
	}
}

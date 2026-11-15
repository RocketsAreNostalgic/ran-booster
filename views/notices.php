<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$notice_messages = isset( $messages ) && is_array( $messages ) ? $messages : array();

// A warning or error in the same response qualifies a success (#755 R12): it stays a static notice.
$notice_qualified = false;
foreach ( $notice_messages as $message ) {
	$notice_qualified = $notice_qualified || ( is_array( $message ) && in_array( $message['type'] ?? null, array( 'warning', 'error' ), true ) );
}
$feedback_items = '';

foreach ( $notice_messages as $message ) {
	if ( is_array( $message )
		&& in_array( $message['type'] ?? null, array( 'info', 'warning', 'error', 'success' ), true )
		&& is_string( $message['message'] ?? null )
	) {
		// A producer names its result with a closed `feedback` code. A confirmed success then goes to the snackbar host;
		// a warning or error stays here as the record, with a pointer to it.
		$feedback_key = is_string( $message['feedback'] ?? null ) && 1 === preg_match( '/\A[a-z0-9_]{1,64}\z/', $message['feedback'] ) ? $message['feedback'] : '';
		if ( '' !== $feedback_key && 'success' === $message['type'] && ! $notice_qualified ) {
			$feedback_items .= \RAN\Admin\Feedback\FeedbackFlash::item( $feedback_key, 'success', wp_strip_all_tags( $message['message'] ) );
			continue;
		}
		$notice_id = '' !== $feedback_key && 'success' !== $message['type'] ? 'ran-booster-feedback-' . str_replace( '_', '-', $feedback_key ) : '';
		if ( '' !== $notice_id ) {
			$feedback_items .= \RAN\Admin\Feedback\FeedbackFlash::item( $feedback_key, $message['type'], wp_strip_all_tags( $message['message'] ), $notice_id );
		}
		$notice_class         = 'error' === $message['type'] ? 'notice-error' : ( 'warning' === $message['type'] ? 'notice-warning' : ( 'info' === $message['type'] ? 'notice-info' : 'notice-success' ) );
		$notice_intent        = $message['type'];
		$is_bulk_queue_notice = 'bulk_update_queue' === ( $message['code'] ?? null )
			&& is_int( $message['queued_updates'] ?? null )
			&& is_int( $message['skipped_updates'] ?? null );
		$is_package_saved     = 'success' === $message['type'] && ! $is_bulk_queue_notice
			&& is_string( $message['saved_package'] ?? null )
			&& in_array( $message['saved_type'] ?? null, array( 'plugin', 'theme' ), true );
		?>
		<div<?php echo '' === $notice_id ? '' : ' id="' . esc_attr( $notice_id ) . '"'; ?> class="notice <?php echo esc_attr( $notice_class ); ?> inline<?php echo $is_bulk_queue_notice ? ' is-dismissible' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $notice_intent ); ?>"<?php echo 'success' === $message['type'] && ! $is_bulk_queue_notice ? ' data-ran-booster-package-success' : ''; ?><?php echo $is_package_saved ? ' data-ran-booster-package-saved data-ran-booster-saved-package="' . esc_attr( $message['saved_package'] ) . '" data-ran-booster-saved-type="' . esc_attr( $message['saved_type'] ) . '"' : ''; ?><?php echo $is_bulk_queue_notice ? ' data-ran-booster-update-summary data-queued="' . esc_attr( (string) $message['queued_updates'] ) . '" data-skipped="' . esc_attr( (string) $message['skipped_updates'] ) . '"' : ''; ?> data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $message['type'] ); ?>"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p<?php echo $is_bulk_queue_notice ? ' aria-live="polite" data-ran-booster-update-summary-message' : ''; ?>><?php echo wp_kses_post( $message['message'] ); ?></p></div>
		<?php \RAN\Admin\Component\NoticeIcon::print_intent( $notice_intent ); ?>
		</div>
		<?php
	}
}
echo \RAN\Admin\Feedback\FeedbackFlash::container( $feedback_items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- container() escapes every dynamic value; the key is a closed code.
?>
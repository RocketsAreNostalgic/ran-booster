<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$notice_messages = isset( $messages ) && is_array( $messages ) ? $messages : array();

// A warning or error in the same response qualifies a success (#755 R12): it stays a static notice.
$notice_qualified = false;
$notice_has_error = false;
foreach ( $notice_messages as $message ) {
	$notice_qualified = $notice_qualified || ( is_array( $message ) && in_array( $message['type'] ?? null, array( 'warning', 'error' ), true ) );
	$notice_has_error = $notice_has_error || ( is_array( $message ) && 'error' === ( $message['type'] ?? null ) );
}
$feedback_items = '';

foreach ( $notice_messages as $message ) {
	if ( is_array( $message )
		&& in_array( $message['type'] ?? null, array( 'info', 'warning', 'error', 'success' ), true )
		&& is_string( $message['message'] ?? null )
	) {
		// A producer names its result with a closed `feedback` code. A confirmed success then goes to the snackbar host;
		// any other result stays here as the record.
		$feedback_key = is_string( $message['feedback'] ?? null ) && 1 === preg_match( '/\A[a-z0-9_]{1,64}\z/', $message['feedback'] ) ? $message['feedback'] : '';
		// The queue summary is also the live progress region (R9), so it stays a notice the host reads, whatever its intent.
		$is_bulk_queue_notice = 'bulk_update_queue' === ( $message['code'] ?? null )
			&& is_int( $message['queued_updates'] ?? null )
			&& is_int( $message['skipped_updates'] ?? null );
		$feedback_key         = $is_bulk_queue_notice ? 'bulk_update_queue' : $feedback_key;
		if ( ! $notice_has_error && 'success' === $message['type'] && ( str_starts_with( $feedback_key, 'package_' ) || ( '' === $feedback_key && 'bulk_update_queue' !== ( $message['code'] ?? null ) ) ) ) {
			echo '<span hidden data-ran-booster-confirmed-package-result></span>';
		}
		if ( '' !== $feedback_key && 'success' === $message['type'] && ! $notice_qualified && ! $is_bulk_queue_notice ) {
			$feedback_items .= \RAN\Admin\Feedback\FeedbackFlash::item( $feedback_key, 'success', wp_strip_all_tags( $message['message'] ) );
			continue;
		}
		// Any other coded result is its own record: the notice carries the key and role, and the host reads its text from it.
		$notice_coded     = '' !== $feedback_key && ( 'success' !== $message['type'] || $is_bulk_queue_notice );
		$notice_class     = 'error' === $message['type'] ? 'notice-error' : ( 'warning' === $message['type'] ? 'notice-warning' : ( 'info' === $message['type'] ? 'notice-info' : 'notice-success' ) );
		$notice_intent    = $message['type'];
		$is_package_saved = 'success' === $message['type'] && ! $is_bulk_queue_notice
			&& is_string( $message['saved_package'] ?? null )
			&& in_array( $message['saved_type'] ?? null, array( 'plugin', 'theme' ), true );
		?>
		<div<?php echo $notice_coded ? ' data-feedback-key="' . esc_attr( $feedback_key ) . '"' . ( $is_bulk_queue_notice ? '' : ' role="' . ( 'error' === $message['type'] ? 'alert' : 'status' ) . '"' ) : ''; ?> class="notice <?php echo esc_attr( $notice_class ); ?> inline<?php echo $is_bulk_queue_notice ? ' is-dismissible' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $notice_intent ); ?>"<?php echo 'success' === $message['type'] && ! $is_bulk_queue_notice ? ' data-ran-booster-package-success' : ''; ?><?php echo $is_package_saved ? ' data-ran-booster-package-saved data-ran-booster-saved-package="' . esc_attr( $message['saved_package'] ) . '" data-ran-booster-saved-type="' . esc_attr( $message['saved_type'] ) . '"' : ''; ?><?php echo $is_bulk_queue_notice ? ' data-ran-booster-update-summary data-queued="' . esc_attr( (string) $message['queued_updates'] ) . '" data-skipped="' . esc_attr( (string) $message['skipped_updates'] ) . '"' : ''; ?> data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $message['type'] ); ?>"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p<?php echo $is_bulk_queue_notice ? ' aria-live="polite" data-ran-booster-update-summary-message' : ''; ?>><?php echo wp_kses_post( $message['message'] ); ?></p>
		<?php
		if ( $is_bulk_queue_notice ) :
			?>
			<p><a class="ran-booster-feedback__activity" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' ) ); ?>"><?php esc_html_e( 'View Activity', 'ran-booster' ); ?></a></p><?php endif; ?></div>
		<?php \RAN\Admin\Component\NoticeIcon::print_intent( $notice_intent ); ?>
		</div>
		<?php
	}
}
echo \RAN\Admin\Feedback\FeedbackFlash::container( $feedback_items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- container() escapes every dynamic value; the key is a closed code.
?>

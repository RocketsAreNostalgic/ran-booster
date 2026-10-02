<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$notice_messages = isset( $messages ) && is_array( $messages ) ? $messages : array();

foreach ( $notice_messages as $message ) {
	if ( is_array( $message )
		&& in_array( $message['type'] ?? null, array( 'info', 'warning', 'error', 'success' ), true )
		&& is_string( $message['message'] ?? null )
	) {
		$notice_class         = 'error' === $message['type'] ? 'notice-error' : ( 'warning' === $message['type'] ? 'notice-warning' : ( 'info' === $message['type'] ? 'notice-info' : 'notice-success' ) );
		$is_bulk_queue_notice = 'bulk_update_queue' === ( $message['code'] ?? null )
			&& is_int( $message['queued_updates'] ?? null )
			&& is_int( $message['skipped_updates'] ?? null );
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> inline<?php echo $is_bulk_queue_notice ? ' is-dismissible' : ''; ?>"<?php echo 'success' === $message['type'] && ! $is_bulk_queue_notice ? ' data-ran-booster-package-success' : ''; ?><?php echo $is_bulk_queue_notice ? ' data-ran-booster-update-summary data-queued="' . esc_attr( (string) $message['queued_updates'] ) . '" data-skipped="' . esc_attr( (string) $message['skipped_updates'] ) . '"' : ''; ?>><p<?php echo $is_bulk_queue_notice ? ' aria-live="polite" data-ran-booster-update-summary-message' : ''; ?>><?php echo wp_kses_post( $message['message'] ); ?></p></div>
		<?php
	}
}
?>
<?php if ( ! empty( $development_safety_notice ) ) { ?>
	<div class="notice notice-warning inline is-dismissible" data-ran-booster-development-safety><p><strong><?php esc_html_e( 'Development safety:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Booster can replace managed plugin and theme directories. Before editing a managed package on this site, set Updates to Disabled. Manual still allows an administrator-triggered replacement; Automatic also accepts configured repository webhooks.', 'ran-booster' ); ?></p></div>
<?php } ?>

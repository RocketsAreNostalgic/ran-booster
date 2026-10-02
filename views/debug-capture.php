<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$debug_capture      = isset( $debug_capture ) && is_array( $debug_capture )
	? $debug_capture
	: array();
$capture_state      = isset( $debug_capture['state'] ) && in_array(
	$debug_capture['state'],
	array( 'inactive', 'active', 'retained', 'unavailable', 'malformed' ),
	true
)
	? $debug_capture['state']
	: 'unavailable';
$filename          = is_string( $debug_capture['filename'] ?? null )
	? basename( str_replace( '\\', '/', $debug_capture['filename'] ) )
	: 'ran-booster-debug.php';
$capture_until      = is_string( $debug_capture['capture_until'] ?? null )
	? $debug_capture['capture_until']
	: '';
$delete_after       = is_string( $debug_capture['delete_after'] ?? null )
	? $debug_capture['delete_after']
	: '';
$content           = is_string( $debug_capture['content'] ?? null )
	? $debug_capture['content']
	: '';
$debug_capture_error = isset( $debug_capture_error ) && is_string( $debug_capture_error )
	? $debug_capture_error
	: null;

$troubleshooting_base = isset( $troubleshooting_base ) && is_string( $troubleshooting_base )
	? $troubleshooting_base
	: ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
		. '?page=ran-booster&tab=troubleshooting';
$refresh_url          = $troubleshooting_base . '&panel=debug-capture';
$status_labels        = array(
	'inactive'    => __( 'Inactive', 'ran-booster' ),
	'active'      => __( 'Capture active', 'ran-booster' ),
	'retained'    => __( 'Capture retained', 'ran-booster' ),
	'unavailable' => __( 'Unavailable', 'ran-booster' ),
	'malformed'   => __( 'File needs attention', 'ran-booster' ),
);
$status_classes       = array(
	'inactive'    => 'neutral',
	'active'      => 'pending',
	'retained'    => 'ok',
	'unavailable' => 'error',
	'malformed'   => 'error',
);

?>
<section id="ran-booster-debug-capture-region" class="ran-booster-debug-capture" aria-labelledby="ran-booster-debug-capture-heading">
	<h3 id="ran-booster-debug-capture-heading"><?php esc_html_e( 'Logging', 'ran-booster' ); ?></h3>
	<div id="ran-booster-debug-capture-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" <?php echo null === $debug_capture_error ? 'hidden' : ''; ?>><p><?php echo null === $debug_capture_error ? '' : esc_html( $debug_capture_error ); ?></p></div>

	<div class="ran-booster-debug-capture__panel ran-booster-panel">
		<div class="notice notice-warning inline ran-booster-debug-capture__scope">
			<p><strong><?php esc_html_e( 'Booster events only', 'ran-booster' ); ?></strong></p>
			<p><?php esc_html_e( 'This temporary capture does not require or enable WP_DEBUG_LOG. It omits PHP, WordPress, theme, and other-plugin messages.', 'ran-booster' ); ?></p>
		</div>

		<dl class="ran-booster-debug-capture__facts">
			<div>
				<dt class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'Status', 'ran-booster' ); ?></dt>
				<dd>
					<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $status_classes[ $capture_state ] ); ?>"><?php echo esc_html( $status_labels[ $capture_state ] ); ?></span>
					<?php if ( 'active' === $capture_state && '' !== $capture_until ) { ?>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: UTC capture end time. */
									__( 'until %s.', 'ran-booster' ),
									$capture_until
								)
							);
							?>
						</span>
					<?php } elseif ( 'retained' === $capture_state && '' !== $delete_after ) { ?>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: UTC automatic deletion time. */
									__( 'retained until %s.', 'ran-booster' ),
									$delete_after
								)
							);
							?>
						</span>
					<?php } ?>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Capture file', 'ran-booster' ); ?></dt>
				<dd><code><?php echo esc_html( $filename ); ?></code><span class="description"><?php esc_html_e( 'Beside the credential sidecar; the server path is not shown.', 'ran-booster' ); ?></span></dd>
			</div>
		</dl>

		<?php if ( 'inactive' === $capture_state ) { ?>
			<div class="ran-booster-debug-capture__empty">
				<p><strong><?php esc_html_e( 'No temporary logging capture is active.', 'ran-booster' ); ?></strong></p>
				<p class="description"><?php esc_html_e( 'Starting a capture records Booster events for 60 minutes and replaces any retained capture.', 'ran-booster' ); ?></p>
				<form method="post" action="" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-debug-capture-error" hx-post="" hx-target="#ran-booster-debug-capture-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
					<?php wp_nonce_field( 'ran-booster-manage-debug-capture' ); ?>
					<input type="hidden" name="ran_booster[action]" value="manage-debug-capture">
					<button type="submit" class="button button-primary" name="ran_booster[operation]" value="start"><?php esc_html_e( 'Start 60-minute capture', 'ran-booster' ); ?></button>
				</form>
			</div>
		<?php } elseif ( in_array( $capture_state, array( 'active', 'retained' ), true ) ) { ?>
			<div class="ran-booster-debug-capture__events">
				<div class="ran-booster-debug-capture__events-header">
					<h4 id="ran-booster-debug-capture-events-heading"><?php esc_html_e( 'Captured Booster events', 'ran-booster' ); ?></h4>
					<p class="description"><?php esc_html_e( 'Newest activity is shown in a bounded, read-only excerpt.', 'ran-booster' ); ?></p>
				</div>
				<?php if ( '' !== $content ) { ?>
					<label class="screen-reader-text" for="ran-booster-debug-capture-content"><?php esc_html_e( 'Captured Booster events', 'ran-booster' ); ?></label>
					<textarea id="ran-booster-debug-capture-content" class="large-text code" rows="16" readonly aria-labelledby="ran-booster-debug-capture-events-heading"><?php echo esc_textarea( $content ); ?></textarea>
				<?php } else { ?>
					<p class="ran-booster-debug-capture__no-events"><?php echo esc_html( 'active' === $capture_state ? __( 'No Booster events have been captured yet.', 'ran-booster' ) : __( 'No Booster events were captured.', 'ran-booster' ) ); ?></p>
				<?php } ?>
			</div>

			<div class="ran-booster-button-group ran-booster-debug-capture__actions" role="group" aria-label="<?php echo esc_attr( __( 'Logging capture actions', 'ran-booster' ) ); ?>">
				<form method="post" action="" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-debug-capture-error" hx-post="" hx-target="#ran-booster-debug-capture-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
					<?php wp_nonce_field( 'ran-booster-manage-debug-capture' ); ?>
					<input type="hidden" name="ran_booster[action]" value="manage-debug-capture">
				<?php if ( 'active' === $capture_state ) { ?>
					<a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'ran-booster' ); ?></a>
					<button type="submit" class="button" name="ran_booster[operation]" value="stop"><?php esc_html_e( 'Stop capture', 'ran-booster' ); ?></button>
				<?php } else { ?>
					<button type="submit" class="button button-primary" name="ran_booster[operation]" value="start"><?php esc_html_e( 'Start new capture', 'ran-booster' ); ?></button>
				<?php } ?>
				</form>
				<form method="post" action="">
					<?php wp_nonce_field( 'ran-booster-manage-debug-capture' ); ?>
					<input type="hidden" name="ran_booster[action]" value="manage-debug-capture">
				<button type="submit" class="button button-link-delete" name="ran_booster[operation]" value="delete"><?php esc_html_e( 'Delete capture', 'ran-booster' ); ?></button>
			</form>
			</div>
		<?php } elseif ( 'malformed' === $capture_state ) { ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'The temporary capture file could not be read safely. Booster left it unchanged.', 'ran-booster' ); ?></p>
			</div>
		<?php } else { ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Temporary logging capture is unavailable because Booster cannot safely use its capture file location.', 'ran-booster' ); ?></p>
			</div>
		<?php } ?>
	</div>
</section>

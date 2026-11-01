<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$debug_capture       = isset( $debug_capture ) && is_array( $debug_capture )
	? $debug_capture
	: array();
$capture_state       = isset( $debug_capture['state'] ) && in_array(
	$debug_capture['state'],
	array( 'inactive', 'active', 'retained', 'unavailable', 'malformed' ),
	true
)
	? $debug_capture['state']
	: 'unavailable';
$filename            = is_string( $debug_capture['filename'] ?? null )
	? basename( str_replace( '\\', '/', $debug_capture['filename'] ) )
	: 'ran-booster-debug.php';
$configured_path     = is_string( $debug_capture['configured_path'] ?? null ) ? $debug_capture['configured_path'] : null;
$capture_until       = is_string( $debug_capture['capture_until'] ?? null )
	? $debug_capture['capture_until']
	: '';
$delete_after        = is_string( $debug_capture['delete_after'] ?? null )
	? $debug_capture['delete_after']
	: '';
$content             = is_string( $debug_capture['content'] ?? null )
	? $debug_capture['content']
	: '';
$debug_capture_error = isset( $debug_capture_error ) && is_string( $debug_capture_error )
	? $debug_capture_error
	: null;

$wp_debug_log         = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;
$wp_debug_log_default = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : 'wp-content' ) . '/debug.log';
$wp_debug_log_path    = $wp_debug_log_default;
$wp_debug_log_custom  = false;
$wp_debug_log_hidden  = false;
if ( is_string( $wp_debug_log ) && ! in_array( strtolower( $wp_debug_log ), array( 'true', '1' ), true ) && '' !== $wp_debug_log && '0' !== $wp_debug_log ) {
	$wp_debug_log_custom = true;
	$wp_debug_log_hidden = ! in_array( $wp_debug_log, array( 'php://stderr', 'php://stdout' ), true ) && 1 === preg_match( '~^[a-z][a-z0-9+.-]*://~i', $wp_debug_log );
	$wp_debug_log_path   = $wp_debug_log;
}
if ( $wp_debug_log_hidden ) {
	$wp_debug_log_example = '// ' . __( 'Keep the existing WP_DEBUG_LOG destination.', 'ran-booster' );
} else {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Render a safe PHP string literal as instructional text, never execute it.
	$wp_debug_log_example = "define( 'WP_DEBUG_LOG', " . ( $wp_debug_log_custom ? var_export( $wp_debug_log_path, true ) : 'true' ) . ' );';
}

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
		<div class="ran-booster-debug-capture__header">
			<div class="ran-booster-debug-capture__heading">
				<h4 class="ran-booster-section__title"><?php esc_html_e( 'Booster event capture', 'ran-booster' ); ?></h4>
				<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $status_classes[ $capture_state ] ); ?>"><?php echo esc_html( $status_labels[ $capture_state ] ); ?></span>
				<span class="ran-booster-debug-capture__deadline description">
					<?php if ( 'active' === $capture_state && '' !== $capture_until ) { ?>
						<?php echo esc_html( sprintf( /* translators: %s: UTC capture end time. */ __( 'until %s.', 'ran-booster' ), $capture_until ) ); ?>
					<?php } elseif ( 'retained' === $capture_state && '' !== $delete_after ) { ?>
						<?php echo esc_html( sprintf( /* translators: %s: UTC automatic deletion time. */ __( 'retained until %s.', 'ran-booster' ), $delete_after ) ); ?>
					<?php } ?>
				</span>
			</div>
		</div>
		<div class="ran-booster-debug-capture__body">
			<p>
				<?php
				/* translators: %s: WP_DEBUG_LOG constant. */
				printf( esc_html__( 'This temporary capture records Booster events only. It does not require or enable %s and excludes PHP, WordPress, theme, and other-plugin messages.', 'ran-booster' ), '<code>WP_DEBUG_LOG</code>' );
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Configured capture file:', 'ran-booster' ); ?> <code><?php echo esc_html( $filename ); ?></code></p>
			<p class="description ran-booster-debug-capture__retained-note"<?php echo 'retained' === $capture_state ? '' : ' aria-hidden="true"'; ?>><?php esc_html_e( 'Starting a new capture replaces this retained capture.', 'ran-booster' ); ?></p>
			<div class="ran-booster-debug-capture__events">
				<div class="ran-booster-debug-capture__events-header">
					<h4 id="ran-booster-debug-capture-events-heading"><?php esc_html_e( 'Captured Booster events', 'ran-booster' ); ?></h4>
				</div>
				<div data-support-report-tools>
					<label class="screen-reader-text" for="ran-booster-debug-capture-content"><?php esc_html_e( 'Captured Booster events', 'ran-booster' ); ?></label>
					<div class="ran-booster-troubleshooting__report-field">
						<textarea id="ran-booster-debug-capture-content" class="large-text code" rows="10" readonly aria-labelledby="ran-booster-debug-capture-events-heading" placeholder="<?php esc_attr_e( 'No Booster events have been captured yet.', 'ran-booster' ); ?>"><?php echo esc_textarea( in_array( $capture_state, array( 'active', 'retained' ), true ) ? $content : '' ); ?></textarea>
						<button type="button" class="button-link ran-booster-troubleshooting__report-copy hide-if-no-js" data-support-report-copy aria-label="<?php esc_attr_e( 'Copy captured events', 'ran-booster' ); ?>"<?php disabled( '' === $content || ! in_array( $capture_state, array( 'active', 'retained' ), true ) ); ?>><span class="dashicons dashicons-clipboard" aria-hidden="true"></span></button>
					</div>
					<p data-support-report-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Captured events copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The captured events are selected; use your browser copy command.', 'ran-booster' ); ?>"></p>
				</div>
			</div>
			<?php if ( 'malformed' === $capture_state ) { ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'The temporary capture file could not be read safely. Booster left it unchanged.', 'ran-booster' ); ?></p></div>
			<?php } elseif ( 'unavailable' === $capture_state ) { ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Temporary logging capture is unavailable because Booster cannot safely use its capture file location.', 'ran-booster' ); ?></p></div>
			<?php } ?>
		</div>
		<?php if ( in_array( $capture_state, array( 'inactive', 'active', 'retained' ), true ) ) { ?>
			<div class="ran-booster-debug-capture__footer" role="group" aria-label="<?php esc_attr_e( 'Logging capture actions', 'ran-booster' ); ?>">
				<form class="ran-booster-debug-capture__primary-actions" method="post" action="" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-debug-capture-error" hx-post="" hx-target="#ran-booster-debug-capture-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
					<?php wp_nonce_field( 'ran-booster-manage-debug-capture' ); ?>
					<input type="hidden" name="ran_booster[action]" value="manage-debug-capture">
					<?php if ( 'active' === $capture_state ) { ?>
						<a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'ran-booster' ); ?></a>
						<button type="submit" class="button" name="ran_booster[operation]" value="stop"><?php esc_html_e( 'Stop capture', 'ran-booster' ); ?></button>
					<?php } else { ?>
						<button type="submit" class="button button-primary" name="ran_booster[operation]" value="start"><?php echo esc_html( 'retained' === $capture_state ? __( 'Start new capture', 'ran-booster' ) : __( 'Start 60-minute capture', 'ran-booster' ) ); ?></button>
					<?php } ?>
				</form>
				<?php if ( in_array( $capture_state, array( 'active', 'retained' ), true ) ) { ?>
				<form class="ran-booster-debug-capture__delete" method="post" action="">
					<?php wp_nonce_field( 'ran-booster-manage-debug-capture' ); ?>
					<input type="hidden" name="ran_booster[action]" value="manage-debug-capture">
					<button type="submit" class="button button-link-delete" name="ran_booster[operation]" value="delete"><?php esc_html_e( 'Delete capture', 'ran-booster' ); ?></button>
				</form>
				<?php } ?>
			</div>
		<?php } ?>
	</div>

	<details id="ran-booster-logging-details" data-ran-booster-logging-disclosure class="ran-booster-debug-capture__wordpress-help ran-booster-troubleshooting__disclosure ran-booster-disclosure">
		<summary><?php esc_html_e( 'Logging details', 'ran-booster' ); ?></summary>
		<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
			<p>
			<?php
			if ( null !== $configured_path ) {
				?>
				<?php /* translators: %s: configured temporary Booster capture path. */ ?><?php printf( esc_html__( 'Booster capture location: %s.', 'ran-booster' ), '<code>' . esc_html( $configured_path ) . '</code>' ); ?>
				<?php
			} else {
				?>
				<?php esc_html_e( 'Booster capture location is unavailable.', 'ran-booster' ); ?><?php } ?></p>
			<p>
				<?php
				/* translators: 1: WP_DEBUG constant, 2: WP_DEBUG_LOG constant, 3: capture file extension. */
				printf( esc_html__( 'Booster events can also go to PHP’s error log when %1$s and %2$s are enabled. The temporary capture records Booster events only, not PHP errors. Its %3$s guard helps prevent direct access to the capture file.', 'ran-booster' ), '<code>WP_DEBUG</code>', '<code>WP_DEBUG_LOG</code>', '<code>.php</code>' );
				?>
			</p>
			<p><?php esc_html_e( 'For broader PHP, WordPress, theme, and plugin error logging, add or update these definitions in wp-config.php before the “stop editing” line:', 'ran-booster' ); ?></p>
			<pre><code><?php echo esc_html( "define( 'WP_DEBUG', true );\n" . $wp_debug_log_example . "\ndefine( 'WP_DEBUG_DISPLAY', false );" ); ?></code></pre>
			<p>
				<?php if ( $wp_debug_log_hidden ) { ?>
					<?php esc_html_e( 'A custom log destination is configured. Review wp-config.php for its location.', 'ran-booster' ); ?>
				<?php } else { ?>
					<?php
					/* translators: %s: WordPress debug log path. */
					printf( esc_html__( 'Log location when enabled: %s.', 'ran-booster' ), '<code>' . esc_html( $wp_debug_log_path ) . '</code>' );
					?>
				<?php } ?>
			</p>
			<p><?php esc_html_e( 'Turn off debugging after reproducing the issue, and review the log privately before sharing it.', 'ran-booster' ); ?></p>
			<p><a href="https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/"><?php esc_html_e( 'WordPress debugging documentation', 'ran-booster' ); ?></a></p>
		</div>
	</details>
</section>

<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * @var string $snippet_value
 * @var string $snippet_label
 * @var string $snippet_copy_label
 * @var int    $snippet_rows Optional textarea rows; defaults to 4 and is reset after each render.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
$snippet_textarea_rows = isset( $snippet_rows ) && is_int( $snippet_rows ) && $snippet_rows > 0 ? $snippet_rows : 4;
unset( $snippet_rows );
?>
<div class="ran-booster-setup-snippet" data-support-report-tools>
	<div class="ran-booster-troubleshooting__report-field">
		<textarea class="large-text code" rows="<?php echo esc_attr( (string) $snippet_textarea_rows ); ?>" readonly aria-label="<?php echo esc_attr( $snippet_label ); ?>"><?php echo esc_textarea( $snippet_value ); ?></textarea>
		<button type="button" class="button-link ran-booster-troubleshooting__report-copy hide-if-no-js" data-support-report-copy aria-label="<?php echo esc_attr( $snippet_copy_label ); ?>" title="<?php echo esc_attr( $snippet_copy_label ); ?>"><svg class="dashicons dashicons-clipboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><g class="ran-booster-copy-icon"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></g><path class="ran-booster-copy-check" d="m5 12 4 4L19 6"/></svg></button>
	</div>
	<span data-support-report-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The text is selected; use your browser copy command.', 'ran-booster' ); ?>"></span>
</div>

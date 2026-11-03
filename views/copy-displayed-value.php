<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * @var string $copy_value
 * @var string $copy_label
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<span class="ran-booster-copy-value" data-copy-value-tools><code data-copy-value><?php echo esc_html( $copy_value ); ?></code>
<?php
if ( '' !== $copy_value ) {
	?>
	<button type="button" class="button ran-booster-copy-value__button hide-if-no-js" data-copy-value-button aria-label="<?php echo esc_attr( $copy_label ); ?>" title="<?php echo esc_attr( $copy_label ); ?>"><svg class="ran-booster-copy-value__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg><svg class="ran-booster-copy-value__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12 4 4L19 6"/></svg></button><span class="ran-booster-copy-value__status" data-copy-value-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The value is selected; use your browser copy command.', 'ran-booster' ); ?>"></span><?php } ?></span>

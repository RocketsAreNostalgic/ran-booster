<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var array<string,mixed> $troubleshooting
 * @var string $troubleshooting_base
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
$report_generated = ! empty( $troubleshooting['report_generated'] );
?>
<h3 class="ran-booster-section__title"><?php esc_html_e( 'Support report', 'ran-booster' ); ?></h3>
<p><?php esc_html_e( 'Generate a local, sanitized snapshot. Available diagnostic runs from the last hour for your administrator account and this site are included automatically. This does not run diagnostics, start logging or send data.', 'ran-booster' ); ?></p>
<form method="post" action="<?php echo esc_url( $troubleshooting_base . '&panel=support-report' ); ?>">
	<?php wp_nonce_field( 'ran-booster-generate-support-report' ); ?>
	<input type="hidden" name="ran_booster[action]" value="generate-support-report">
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate support report', 'ran-booster' ); ?></button></p>
</form>
<?php if ( $report_generated ) { ?>
<div data-support-report-tools>
	<p><label for="ran-booster-troubleshooting-report"><strong><?php esc_html_e( 'Sanitized preview', 'ran-booster' ); ?></strong></label></p>
	<div class="ran-booster-troubleshooting__report-field">
		<textarea id="ran-booster-troubleshooting-report" class="large-text code" rows="16" readonly><?php echo esc_textarea( $troubleshooting['report'] ?? '' ); ?></textarea>
		<button type="button" class="button-link ran-booster-troubleshooting__report-copy hide-if-no-js" data-support-report-copy aria-label="<?php esc_attr_e( 'Copy report', 'ran-booster' ); ?>"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span></button>
	</div>
	<p><button type="button" class="button hide-if-no-js" data-support-report-download data-support-report-filename="<?php echo esc_attr( $troubleshooting['report_filename'] ?? '' ); ?>"><?php esc_html_e( 'Download text report', 'ran-booster' ); ?></button></p>
	<p data-support-report-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Support report copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The report is selected; use your browser copy command.', 'ran-booster' ); ?>" data-download-failed-message="<?php esc_attr_e( 'Download failed. Select the report and save it manually.', 'ran-booster' ); ?>"></p>
	<p class="description"><?php esc_html_e( 'Preview, Copy and Download use this same frozen text. You can select it and copy or save it manually. Review before sharing; generating a new report collects a new observation.', 'ran-booster' ); ?></p>
</div>
<?php } ?>
<p class="description"><?php esc_html_e( 'Logging captures Booster events only. When evidence is missing, explicitly start Logging, reproduce the problem, then regenerate. Events from before capture cannot be recovered.', 'ran-booster' ); ?></p>
<p class="description"><?php esc_html_e( 'If Booster or WordPress administration cannot load, use WordPress recovery mode or your hosting support route. External server logs are not collected here.', 'ran-booster' ); ?></p>

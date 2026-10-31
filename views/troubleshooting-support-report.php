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
<p><?php esc_html_e( 'Generate a local, sanitized report. The report is not transmitted or logged; suitably authorised administrators also receive fresh basic diagnostics, which may contact admitted providers without selecting credentials or repositories.', 'ran-booster' ); ?></p>
<form method="post" action="<?php echo esc_url( $troubleshooting_base . '&panel=support-report' ); ?>">
	<?php wp_nonce_field( 'ran-booster-generate-support-report' ); ?>
	<input type="hidden" name="ran_booster[action]" value="generate-support-report">
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate support report', 'ran-booster' ); ?></button></p>
</form>
<?php if ( $report_generated ) { ?>
<div data-support-report-tools>
	<p><label for="ran-booster-troubleshooting-report"><strong><?php esc_html_e( 'Sanitized JSON preview', 'ran-booster' ); ?></strong></label></p>
	<div class="ran-booster-troubleshooting__report-field">
		<textarea id="ran-booster-troubleshooting-report" class="large-text code" rows="16" readonly><?php echo esc_textarea( $troubleshooting['report'] ?? '' ); ?></textarea>
		<button type="button" class="button-link ran-booster-troubleshooting__report-copy hide-if-no-js" data-support-report-copy aria-label="<?php esc_attr_e( 'Copy JSON report', 'ran-booster' ); ?>"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span></button>
	</div>
	<p><button type="button" class="button hide-if-no-js" data-support-report-download data-support-report-filename="<?php echo esc_attr( $troubleshooting['report_filename'] ?? '' ); ?>"><?php esc_html_e( 'Download JSON report', 'ran-booster' ); ?></button></p>
	<p data-support-report-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Support report copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The report is selected; use your browser copy command.', 'ran-booster' ); ?>" data-download-failed-message="<?php esc_attr_e( 'Download failed. Select the report and save it manually.', 'ran-booster' ); ?>"></p>
		<p class="description"><?php esc_html_e( 'Preview, Copy and Download use this same frozen JSON. To save it manually, select and copy the JSON into a text editor, then save it as a .json file.', 'ran-booster' ); ?></p>
</div>
<?php } ?>
<details class="ran-booster-troubleshooting__disclosure ran-booster-disclosure">
	<summary><?php esc_html_e( 'Evidence and recovery guidance', 'ran-booster' ); ?></summary>
	<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
		<p><?php esc_html_e( 'Available diagnostic runs from the last hour for your administrator account and this site are included automatically.', 'ran-booster' ); ?></p>
		<p><?php esc_html_e( 'Logging captures Booster events only. When evidence is missing, explicitly start Logging, reproduce the problem, then generate another report. Events from before capture cannot be recovered.', 'ran-booster' ); ?></p>
		<p><?php esc_html_e( 'If Booster or WordPress administration cannot load, use WordPress recovery mode or your hosting support route. External server logs are not collected here.', 'ran-booster' ); ?></p>
	</div>
</details>

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
<form method="post" action="<?php echo esc_url( $troubleshooting_base . '&panel=support-report' ); ?>" data-support-report-tools>
	<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-generate-support-report' ); ?>
	<input type="hidden" name="ran_booster[action]" value="generate-support-report">
	<p><label for="ran-booster-troubleshooting-report"><strong><?php esc_html_e( 'Sanitized JSON preview', 'ran-booster' ); ?></strong></label></p>
	<div class="ran-booster-troubleshooting__report-field">
		<textarea id="ran-booster-troubleshooting-report" class="large-text code" rows="16" readonly placeholder="<?php esc_attr_e( 'No report generated yet.', 'ran-booster' ); ?>"><?php echo esc_textarea( $report_generated ? ( $troubleshooting['report'] ?? '' ) : '' ); ?></textarea>
		<button type="button" class="button-link ran-booster-troubleshooting__report-copy hide-if-no-js" data-support-report-copy aria-label="<?php esc_attr_e( 'Copy JSON report', 'ran-booster' ); ?>"<?php disabled( ! $report_generated ); ?>><svg class="dashicons dashicons-clipboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><g class="ran-booster-copy-icon"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></g><path class="ran-booster-copy-check" d="m5 12 4 4L19 6"/></svg></button>
	</div>
	<p data-support-report-status role="status" aria-live="polite" aria-atomic="true" data-copied-message="<?php esc_attr_e( 'Support report copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The report is selected; use your browser copy command.', 'ran-booster' ); ?>" data-download-failed-message="<?php esc_attr_e( 'Download failed. Select the report and save it manually.', 'ran-booster' ); ?>"></p>
<div class="ran-booster-troubleshooting__support-disclosure" data-ran-booster-ui-disclosure="troubleshooting-evidence" data-ran-booster-ui-disclosure-density="compact">
<details data-ran-booster-ui-disclosure-fallback class="ran-booster-troubleshooting__disclosure ran-booster-disclosure">
	<summary><?php esc_html_e( 'Evidence and recovery guidance', 'ran-booster' ); ?></summary>
	<div data-ran-booster-ui-disclosure-content class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
		<p><?php esc_html_e( 'Diagnostic runs from the last hour are included automatically.', 'ran-booster' ); ?></p>
		<p><?php esc_html_e( 'Logging captures Booster events only. If evidence is missing, start Logging, reproduce the problem, then generate another report. Earlier events cannot be recovered.', 'ran-booster' ); ?></p>
		<p><?php esc_html_e( 'If administration cannot load, use WordPress recovery mode or your host’s support. External server logs are not collected.', 'ran-booster' ); ?></p>
	</div>
</details></div>
	<div class="ran-booster-card-footer">
		<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"><?php esc_html_e( 'Generate support report', 'ran-booster' ); ?></button>
		<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() . ' hide-if-no-js' ); ?>" data-support-report-download data-support-report-filename="<?php echo esc_attr( $report_generated ? ( $troubleshooting['report_filename'] ?? '' ) : '' ); ?>"<?php disabled( ! $report_generated ); ?>><?php esc_html_e( 'Download JSON report', 'ran-booster' ); ?></button>
	</div>
</form>

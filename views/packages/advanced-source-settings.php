<?php

defined( 'WPINC' ) || die;

$packageAdvancedSummary = isset( $packageAdvancedSummary ) && is_string( $packageAdvancedSummary )
	? $packageAdvancedSummary
	: __( 'Branch · provider default', 'ran-booster' );
$packageAdvancedOpen    = isset( $packageAdvancedOpen ) && true === $packageAdvancedOpen;
$packageAdvancedBody    = isset( $packageAdvancedBody ) && is_string( $packageAdvancedBody )
	? $packageAdvancedBody
	: '';

?>
<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings <?php echo $packageAdvancedOpen ? 'open' : ''; ?>>
	<summary>
		<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Advanced settings', 'ran-booster' ); ?></h3>
		<small data-ran-booster-advanced-source-summary><?php echo esc_html( $packageAdvancedSummary ); ?></small>
	</summary>
	<div class="ran-booster-settings-disclosure__body">
		<?php echo $packageAdvancedBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core and bounded registered add-ons rendered this body. ?>
	</div>
</details>

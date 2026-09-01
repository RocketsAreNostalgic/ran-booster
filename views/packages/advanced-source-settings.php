<?php

defined( 'WPINC' ) || die;

$packageAdvancedSummary                  = isset( $packageAdvancedSummary ) && is_string( $packageAdvancedSummary )
	? $packageAdvancedSummary
	: __( 'Branch · provider default', 'ran-booster' );
$packageAdvancedOpen                     = isset( $packageAdvancedOpen ) && true === $packageAdvancedOpen;
$packageAdvancedBody                     = isset( $packageAdvancedBody ) && is_string( $packageAdvancedBody )
	? $packageAdvancedBody
	: '';
$packageAdvancedSummaryProjection        = is_array( $packageSource['advanced_summary_projection'] ?? null )
	? $packageSource['advanced_summary_projection']
	: null;
$packageAdvancedSummaryProjectionHeading = null === $packageAdvancedSummaryProjection || ! is_string( $packageAdvancedSummaryProjection['heading'] ?? null )
	? null
	: (string) $packageAdvancedSummaryProjection['heading'];
$packageAdvancedSummaryProjectionBadges  = array();
if ( is_array( $packageAdvancedSummaryProjection['badges'] ?? null ) ) {
	foreach ( $packageAdvancedSummaryProjection['badges'] as $packageAdvancedSummaryProjectionBadge ) {
		if ( is_array( $packageAdvancedSummaryProjectionBadge )
			&& is_string( $packageAdvancedSummaryProjectionBadge['label'] ?? null )
			&& '' !== trim( (string) $packageAdvancedSummaryProjectionBadge['label'] ) ) {
			$packageAdvancedSummaryProjectionBadges[] = array(
				'label' => trim( (string) $packageAdvancedSummaryProjectionBadge['label'] ),
			);
		}
	}
}
$packageAdvancedSummaryProjectionStatus = is_string( $packageAdvancedSummaryProjection['status'] ?? null )
	? trim( (string) $packageAdvancedSummaryProjection['status'] )
	: '';
if ( '' === $packageAdvancedSummaryProjectionHeading
	|| ( 0 === count( $packageAdvancedSummaryProjectionBadges ) && '' === $packageAdvancedSummaryProjectionStatus ) ) {
	$packageAdvancedSummaryProjection = null;
}

?>
<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings <?php echo $packageAdvancedOpen ? 'open' : ''; ?>>
	<summary>
		<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Advanced settings', 'ran-booster' ); ?></h3>
		<small class="ran-booster-advanced-source-summary" data-ran-booster-advanced-source-summary>
			<?php if ( null !== $packageAdvancedSummaryProjection ) { ?>
				<span class="ran-booster-advanced-source-summary__heading"><?php echo esc_html( $packageAdvancedSummaryProjectionHeading ); ?></span>
				<?php foreach ( $packageAdvancedSummaryProjectionBadges as $packageAdvancedSummaryProjectionBadge ) { ?>
					<span class="ran-booster-advanced-source-summary__badge">
						<?php echo esc_html( $packageAdvancedSummaryProjectionBadge['label'] ); ?>
					</span>
				<?php } ?>
				<?php if ( '' !== $packageAdvancedSummaryProjectionStatus ) { ?>
					<span class="ran-booster-advanced-source-summary__status">• <?php echo esc_html( $packageAdvancedSummaryProjectionStatus ); ?></span>
				<?php } ?>
			<?php } else { ?>
				<span><?php echo esc_html( $packageAdvancedSummary ); ?></span>
			<?php } ?>
		</small>
	</summary>
	<div class="ran-booster-settings-disclosure__body">
		<?php echo $packageAdvancedBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core and bounded registered add-ons rendered this body. ?>
	</div>
</details>

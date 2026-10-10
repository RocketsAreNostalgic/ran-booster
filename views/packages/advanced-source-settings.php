<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

defined( 'WPINC' ) || die;

$package_advanced_summary                    = isset( $package_advanced_summary ) && is_string( $package_advanced_summary )
	? $package_advanced_summary
	: __( 'Branch · provider default', 'ran-booster' );
$package_advanced_open                       = isset( $package_advanced_open ) && true === $package_advanced_open;
$package_advanced_body                       = isset( $package_advanced_body ) && is_string( $package_advanced_body )
	? $package_advanced_body
	: '';
$package_advanced_summary_projection         = isset( $package_source ) && is_array( $package_source['advanced_summary_projection'] ?? null )
	? $package_source['advanced_summary_projection']
	: null;
$package_advanced_summary_projection_heading = null === $package_advanced_summary_projection || ! is_string( $package_advanced_summary_projection['heading'] ?? null )
	? null
	: (string) $package_advanced_summary_projection['heading'];
$package_advanced_summary_projection_badges  = array();
if ( is_array( $package_advanced_summary_projection['badges'] ?? null ) ) {
	foreach ( $package_advanced_summary_projection['badges'] as $package_advanced_summary_projection_badge ) {
		if ( is_array( $package_advanced_summary_projection_badge )
			&& is_string( $package_advanced_summary_projection_badge['label'] ?? null )
			&& '' !== trim( (string) $package_advanced_summary_projection_badge['label'] ) ) {
			$package_advanced_summary_projection_badges[] = array(
				'label' => trim( (string) $package_advanced_summary_projection_badge['label'] ),
			);
		}
	}
}
$package_advanced_summary_projection_status = is_string( $package_advanced_summary_projection['status'] ?? null )
	? trim( (string) $package_advanced_summary_projection['status'] )
	: '';
if ( '' === $package_advanced_summary_projection_heading
	|| ( 0 === count( $package_advanced_summary_projection_badges ) && '' === $package_advanced_summary_projection_status ) ) {
	$package_advanced_summary_projection = null;
}

?>
<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings <?php echo $package_advanced_open ? 'open' : ''; ?>>
	<summary>
		<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Advanced settings', 'ran-booster' ); ?></h3>
		<small class="ran-booster-advanced-source-summary" data-ran-booster-advanced-source-summary>
			<?php if ( null !== $package_advanced_summary_projection && null !== $package_advanced_summary_projection_heading ) { ?>
				<span class="ran-booster-advanced-source-summary__heading"><?php echo esc_html( $package_advanced_summary_projection_heading ); ?></span>
				<?php foreach ( $package_advanced_summary_projection_badges as $package_advanced_summary_projection_badge ) { ?>
					<span class="ran-booster-advanced-source-summary__badge">
						<?php echo esc_html( $package_advanced_summary_projection_badge['label'] ); ?>
					</span>
				<?php } ?>
				<?php if ( '' !== $package_advanced_summary_projection_status ) { ?>
					<span class="ran-booster-advanced-source-summary__status">• <?php echo esc_html( $package_advanced_summary_projection_status ); ?></span>
				<?php } ?>
			<?php } else { ?>
				<span><?php echo esc_html( $package_advanced_summary ); ?></span>
			<?php } ?>
		</small>
	</summary>
	<div class="ran-booster-settings-disclosure__body">
		<?php echo $package_advanced_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core and bounded registered add-ons rendered this body. ?>
	</div>
</details>

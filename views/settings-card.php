<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * One Settings section as a collapsible Card.
 *
 * It is the Installation Card pattern: a prominent shared Disclosure whose
 * heading slot carries the title and a one-line summary in a real h3 around the
 * toggle, with an optional status badge in the meta slot. The body holds the
 * section content directly. Without JavaScript the native details element
 * keeps the same content and its server-rendered open state. A Card with no
 * body renders the same header without the disclosure.
 *
 * @var string $card_id          Host element ID (also the section anchor).
 * @var string $card_modifier    Space-separated extra host classes.
 * @var string $card_title       Escaped title markup.
 * @var string $card_description Escaped one-line summary markup, or an empty string.
 * @var string $card_badge       StatusBadge markup, or an empty string.
 * @var string $card_label       Plain-text accessible name for the disclosure button.
 * @var bool   $card_open        Whether the Card renders open.
 * @var string $card_body        Escaped body markup, or an empty string when there is nothing to expand.
 * @var string $card_body_class  Optional extra body classes.
 * @var array<string,string> $card_attributes Optional extra host attributes (values are escaped here).
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
$card_modifier   = isset( $card_modifier ) && is_string( $card_modifier ) ? $card_modifier : '';
$card_body_class = isset( $card_body_class ) && is_string( $card_body_class ) ? $card_body_class : '';
$card_attributes = isset( $card_attributes ) && is_array( $card_attributes ) ? $card_attributes : array();
$card_extra      = '';
foreach ( $card_attributes as $card_attribute => $card_attribute_value ) {
	$card_extra .= ' ' . esc_attr( $card_attribute ) . '="' . esc_attr( $card_attribute_value ) . '"';
}
$card_classes = trim( 'ran-booster-settings__card ran-booster-troubleshooting__installation-entry ' . $card_modifier );
if ( '' === trim( $card_body ) ) {
	?>
<div id="<?php echo esc_attr( $card_id ); ?>" class="<?php echo esc_attr( $card_classes ); ?> ran-booster-troubleshooting__installation-entry--static"<?php echo $card_extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where the attributes are built. ?>>
	<div class="ran-booster-troubleshooting__entry-header">
		<div class="ran-booster-troubleshooting__entry-row">
			<div class="ran-booster-troubleshooting__entry-heading"><h3 class="ran-booster-troubleshooting__entry-title"><?php echo $card_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></h3>
			<?php
			if ( '' !== $card_description ) {
				?>
				<span class="ran-booster-troubleshooting__entry-description"><?php echo $card_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span><?php } ?></div>
			<?php if ( '' !== $card_badge ) { ?>
				<span class="ran-booster-troubleshooting__entry-meta"><?php echo $card_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></span>
			<?php } ?>
		</div>
	</div>
</div>
	<?php
	return;
}
?>
<div id="<?php echo esc_attr( $card_id ); ?>" class="<?php echo esc_attr( $card_classes ); ?>" data-ran-booster-ui-disclosure="settings-card" data-ran-booster-ui-disclosure-density="prominent"<?php echo $card_extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where the attributes are built. ?>>
<details class="ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback<?php echo $card_open ? ' open' : ''; ?>>
<summary aria-label="<?php echo esc_attr( $card_label ); ?>"><span class="ran-booster-troubleshooting__entry-heading" data-ran-booster-ui-disclosure-heading="h3"><span class="ran-booster-troubleshooting__entry-title"><?php echo $card_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span>
<?php
if ( '' !== $card_description ) {
	?>
	<span class="ran-booster-troubleshooting__entry-description"><?php echo $card_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span><?php } ?></span>
	<?php
	if ( '' !== $card_badge ) {
		?>
	<span class="ran-booster-troubleshooting__entry-meta" data-ran-booster-ui-disclosure-meta><?php echo $card_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></span><?php } ?></summary>
<div class="<?php echo esc_attr( trim( 'ran-booster-settings__card-body ran-booster-disclosure__body ' . $card_body_class ) ); ?>" data-ran-booster-ui-disclosure-content>
<?php echo $card_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>
</div>
</details>
</div>

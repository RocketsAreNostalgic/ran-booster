<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * One Installation section as a non-compact expandable Card.
 *
 * The header carries the title, a one-line summary and the status badge; an
 * optional warning row sits directly beneath it, outside the heading and the
 * toggle once mounted (the Disclosure moves it between the header and the
 * content), so it can hold a real link; the details sit directly in the Card
 * body. The title is an h4: the mounted Disclosure makes its header wrapper the
 * heading around the toggle, and an entry with no details renders the same
 * header without the disclosure, so it is not a bare row.
 *
 * @var string $entry_title       Escaped title markup.
 * @var string $entry_description Escaped one-line summary markup.
 * @var string $entry_badge       StatusBadge markup, or an empty string.
 * @var string $entry_warning     Optional problem message markup (escaped, may end in a link) shown beneath the header, or an empty string.
 * @var string $entry_body        Escaped body markup, or an empty string when there is nothing to expand.
 * @var string $entry_label       Plain-text accessible name for the disclosure button.
 * @var string $entry_id          Optional host element ID.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
$entry_id           = isset( $entry_id ) && is_string( $entry_id ) ? $entry_id : '';
$entry_id_attribute = '' !== $entry_id ? ' id="' . esc_attr( $entry_id ) . '"' : '';
$entry_warning      = isset( $entry_warning ) && is_string( $entry_warning ) ? $entry_warning : '';
$warning_markup     = '' !== $entry_warning ? '<span class="ran-booster-troubleshooting__entry-warning" data-ran-booster-ui-disclosure-warning>' . $entry_warning . '</span>' : '';
if ( '' === trim( $entry_body ) ) {
	?>
<div<?php echo $entry_id_attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The ID is escaped where the attribute is built. ?> class="ran-booster-troubleshooting__installation-entry ran-booster-troubleshooting__installation-entry--static">
	<div class="ran-booster-troubleshooting__entry-header">
		<div class="ran-booster-troubleshooting__entry-row">
			<div class="ran-booster-troubleshooting__entry-heading"><h4 class="ran-booster-troubleshooting__entry-title"><?php echo $entry_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></h4>
			<?php
			if ( '' !== $entry_description ) {
				?>
				<span class="ran-booster-troubleshooting__entry-description"><?php echo $entry_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span><?php } ?></div>
			<?php if ( '' !== $entry_badge ) { ?>
				<span class="ran-booster-troubleshooting__entry-meta"><?php echo $entry_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></span>
			<?php } ?>
		</div>
		<?php echo $warning_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where the markup is built. ?>
	</div>
</div>
	<?php
	return;
}
?>
<div<?php echo $entry_id_attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The ID is escaped where the attribute is built. ?> class="ran-booster-troubleshooting__installation-entry" data-ran-booster-ui-disclosure="installation-section" data-ran-booster-ui-disclosure-density="prominent"<?php echo '' !== $entry_warning ? ' data-ran-booster-ui-disclosure-warned' : ''; ?>>
<details class="ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback>
<summary aria-label="<?php echo esc_attr( $entry_label ); ?>"><span class="ran-booster-troubleshooting__entry-heading" data-ran-booster-ui-disclosure-heading><span class="ran-booster-troubleshooting__entry-title"><?php echo $entry_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span>
<?php
if ( '' !== $entry_description ) {
	?>
	<span class="ran-booster-troubleshooting__entry-description"><?php echo $entry_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?></span><?php } ?></span>
<?php echo $warning_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where the markup is built. ?>
	<?php
	if ( '' !== $entry_badge ) {
		?>
	<span class="ran-booster-troubleshooting__entry-meta" data-ran-booster-ui-disclosure-meta><?php echo $entry_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></span><?php } ?></summary>
<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body" data-ran-booster-ui-disclosure-content>
<?php echo $entry_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>
</div>
</details>
</div>

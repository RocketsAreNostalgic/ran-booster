<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var list<string> $package_advanced_sections
 * @var bool $package_mutation_available
 * @var string $package_source_view
 * @var bool $release_managed
 */

defined( 'WPINC' ) || die;

$package_source_mode      = isset( $package_source_mode ) && 'create' === $package_source_mode ? 'create' : 'edit';
$is_package_edit          = 'edit' === $package_source_mode;
$branch_settings_inactive = $is_package_edit && $release_managed;

ob_start();
foreach ( $package_advanced_sections as $package_advanced_section ) {
	if ( is_string( $package_advanced_section ) && '' !== $package_advanced_section ) {
		echo $package_advanced_section; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer.
	}
}
$package_advanced_sections_markup = (string) ob_get_clean();

ob_start();
?>
<fieldset class="ran-booster-package-source-shell" data-ran-booster-source-controls <?php disabled( ! $is_package_edit && ! $package_mutation_available ); ?>>
	<?php
	require __DIR__ . '/source-choices.php';
	$package_field_form = $is_package_edit ? 'ran-booster-package-edit-form' : '';
	?>
	<div class="notice notice-warning inline" role="alert" hidden data-ran-booster-source-unsaved-notice>
		<p><?php esc_html_e( 'Save or revert your package settings before changing source.', 'ran-booster' ); ?></p>
	</div>
	<div
		id="ran-booster-source-pane-branch"
		class="ran-booster-package-source-pane<?php echo $is_package_edit ? '' : ' ran-booster-settings-fields__branch'; ?>"
		aria-labelledby="ran-booster-source-tab-branch"
		data-ran-booster-source-pane="branch"
		data-ran-booster-branch-fields
		<?php echo $is_package_edit && ! ( $show_branch_settings ?? false ) ? 'hidden' : ''; ?>
	>
		<?php if ( $is_package_edit && ( $show_branch_settings ?? false ) && isset( $package_source_choices['branch']['description'] ) ) { ?>
			<p class="ran-booster-package-source-pane__description"><?php echo esc_html( (string) $package_source_choices['branch']['description'] ); ?></p>
		<?php } ?>
		<?php if ( $is_package_edit && 'branch' === $package_source_view ) { ?>
			<?php echo $package_advanced_sections_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
		<?php } ?>
		<fieldset class="ran-booster-branch-settings<?php echo $branch_settings_inactive ? ' is-inactive' : ''; ?>"<?php disabled( $branch_settings_inactive ); ?><?php echo $branch_settings_inactive ? ' aria-disabled="true"' : ''; ?>>
			<legend class="screen-reader-text"><?php echo esc_html( $branch_settings_inactive ? __( 'Inactive Branch deployment settings', 'ran-booster' ) : __( 'Branch deployment settings', 'ran-booster' ) ); ?></legend>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/branch.php'; ?>
				<?php require __DIR__ . '/fields/subdirectory.php'; ?>
			</div>
			<?php if ( $is_package_edit ) { ?>
				<?php require __DIR__ . '/branch-readiness.php'; ?>
			<?php } ?>
		</fieldset>
	</div>
	<?php if ( ! ( $is_package_edit && 'branch' === $package_source_view ) ) { ?>
		<?php echo $package_advanced_sections_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
	<?php } ?>
</fieldset>
<?php
unset( $package_field_form );
$package_advanced_body = (string) ob_get_clean();
require __DIR__ . '/advanced-source-settings.php';

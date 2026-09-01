<?php

defined( 'WPINC' ) || die;

$packageSourceMode      = isset( $packageSourceMode ) && 'create' === $packageSourceMode ? 'create' : 'edit';
$isPackageEdit          = 'edit' === $packageSourceMode;
$branchSettingsInactive = $isPackageEdit && $releaseManaged;

ob_start();
foreach ( $packageAdvancedSections as $packageAdvancedSection ) {
	if ( is_string( $packageAdvancedSection ) && '' !== $packageAdvancedSection ) {
		echo $packageAdvancedSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer.
	}
}
$packageAdvancedSectionsMarkup = (string) ob_get_clean();

ob_start();
?>
<fieldset class="ran-booster-package-source-shell" data-ran-booster-source-controls <?php disabled( ! $isPackageEdit && ! $packageMutationAvailable ); ?>>
	<?php
	require __DIR__ . '/source-choices.php';
	$packageFieldForm = $isPackageEdit ? 'ran-booster-package-edit-form' : '';
	?>
	<div class="notice notice-warning inline" role="alert" hidden data-ran-booster-source-unsaved-notice>
		<p><?php esc_html_e( 'Save or revert your package settings before changing source.', 'ran-booster' ); ?></p>
	</div>
	<div
		id="ran-booster-source-pane-branch"
		class="ran-booster-package-source-pane<?php echo $isPackageEdit ? '' : ' ran-booster-settings-fields__branch'; ?>"
		aria-labelledby="ran-booster-source-tab-branch"
		data-ran-booster-source-pane="branch"
		data-ran-booster-branch-fields
		<?php echo $isPackageEdit && ! $showBranchSettings ? 'hidden' : ''; ?>
	>
		<?php if ( $isPackageEdit && $showBranchSettings && isset( $packageSourceChoices['branch']['description'] ) ) { ?>
			<p class="ran-booster-package-source-pane__description"><?php echo esc_html( (string) $packageSourceChoices['branch']['description'] ); ?></p>
		<?php } ?>
		<?php if ( $isPackageEdit && 'branch' === $packageSourceView ) { ?>
			<?php echo $packageAdvancedSectionsMarkup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
		<?php } ?>
		<header class="ran-booster-package-source-pane__header">
			<h3><?php esc_html_e( 'Branch readiness', 'ran-booster' ); ?></h3>
		</header>
		<fieldset class="ran-booster-branch-settings<?php echo $branchSettingsInactive ? ' is-inactive' : ''; ?>"<?php disabled( $branchSettingsInactive ); ?><?php echo $branchSettingsInactive ? ' aria-disabled="true"' : ''; ?>>
			<legend class="screen-reader-text"><?php echo esc_html( $branchSettingsInactive ? __( 'Inactive Branch deployment settings', 'ran-booster' ) : __( 'Branch deployment settings', 'ran-booster' ) ); ?></legend>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/branch.php'; ?>
				<?php require __DIR__ . '/fields/subdirectory.php'; ?>
			</div>
			<?php if ( $isPackageEdit ) { ?>
				<?php require __DIR__ . '/branch-readiness.php'; ?>
			<?php } ?>
		</fieldset>
	</div>
	<?php if ( ! ( $isPackageEdit && 'branch' === $packageSourceView ) ) { ?>
		<?php echo $packageAdvancedSectionsMarkup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
	<?php } ?>
</fieldset>
<?php
unset( $packageFieldForm );
$packageAdvancedBody = (string) ob_get_clean();
require __DIR__ . '/advanced-source-settings.php';

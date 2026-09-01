<?php

defined( 'WPINC' ) || die;

$packageSourceMode = isset( $packageSourceMode ) && 'create' === $packageSourceMode ? 'create' : 'edit';
$isPackageEdit     = 'edit' === $packageSourceMode;

ob_start();
?>
<fieldset class="ran-booster-package-source-shell" data-ran-booster-source-controls <?php disabled( ! $isPackageEdit && ! $packageMutationAvailable ); ?>>
	<?php
	require __DIR__ . '/source-choices.php';
	$packageFieldForm = $isPackageEdit ? 'ran-booster-package-edit-form' : '';
	?>
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
		<div class="ran-booster-settings-fields">
			<?php require __DIR__ . '/fields/branch.php'; ?>
			<?php require __DIR__ . '/fields/subdirectory.php'; ?>
		</div>
		<?php if ( $isPackageEdit && $releaseManaged ) { ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Published releases remain the current source. Return the package to Branch management before changing this retained branch target.', 'ran-booster' ); ?></p>
			</div>
		<?php } ?>
		<?php if ( $isPackageEdit && $showBranchOperations ) { ?>
			<?php require __DIR__ . '/branch-readiness.php'; ?>
		<?php } ?>
	</div>
	<?php foreach ( $packageAdvancedSections as $packageAdvancedSection ) { ?>
		<?php if ( is_string( $packageAdvancedSection ) && '' !== $packageAdvancedSection ) { ?>
			<?php echo $packageAdvancedSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
		<?php } ?>
	<?php } ?>
</fieldset>
<?php
unset( $packageFieldForm );
$packageAdvancedBody = (string) ob_get_clean();
require __DIR__ . '/advanced-source-settings.php';

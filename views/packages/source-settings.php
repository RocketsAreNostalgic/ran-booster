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
		<header class="ran-booster-package-source-pane__header">
			<h3><?php esc_html_e( 'Branch readiness', 'ran-booster' ); ?></h3>
			<?php if ( $isPackageEdit && $releaseManaged ) { ?>
				<p><?php esc_html_e( 'Published releases remain the package source and settings are retained until returning.', 'ran-booster' ); ?></p>
			<?php } else { ?>
				<p><?php esc_html_e( 'Branch deployments are the package source.', 'ran-booster' ); ?></p>
			<?php } ?>
		</header>
		<fieldset class="ran-booster-branch-settings<?php echo $isPackageEdit && $releaseManaged ? ' is-inactive' : ''; ?>"<?php disabled( $isPackageEdit && $releaseManaged ); ?><?php echo $isPackageEdit && $releaseManaged ? ' aria-disabled="true"' : ''; ?>>
			<?php if ( $isPackageEdit && $releaseManaged ) { ?>
				<legend class="screen-reader-text"><?php esc_html_e( 'Inactive Branch deployment settings', 'ran-booster' ); ?></legend>
			<?php } ?>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/branch.php'; ?>
				<?php require __DIR__ . '/fields/subdirectory.php'; ?>
			</div>
			<?php require __DIR__ . '/branch-readiness.php'; ?>
		</fieldset>
		<?php if ( $isPackageEdit && $releaseManaged && 'branch' === $packageSourceView ) { ?>
			<?php foreach ( $packageAdvancedSections as $packageAdvancedSection ) { ?>
				<?php if ( is_string( $packageAdvancedSection ) && '' !== $packageAdvancedSection ) { ?>
					<?php echo $packageAdvancedSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
				<?php } ?>
			<?php } ?>
		<?php } ?>
	</div>
	<?php if ( ! ( $isPackageEdit && $releaseManaged && 'branch' === $packageSourceView ) ) { ?>
		<?php foreach ( $packageAdvancedSections as $packageAdvancedSection ) { ?>
			<?php if ( is_string( $packageAdvancedSection ) && '' !== $packageAdvancedSection ) { ?>
				<?php echo $packageAdvancedSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bounded add-on renderer. ?>
			<?php } ?>
		<?php } ?>
	<?php } ?>
</fieldset>
<?php
unset( $packageFieldForm );
$packageAdvancedBody = (string) ob_get_clean();
require __DIR__ . '/advanced-source-settings.php';

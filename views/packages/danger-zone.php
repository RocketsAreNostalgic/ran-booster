<?php

defined( 'WPINC' ) || die;

$packageTypeLabel  = strtolower( $packageView->getSingularLabel() );
$unlinkCheckboxId  = 'ran-booster-confirm-unlink-' . $packageView->getType();
$deleteCheckboxId  = 'ran-booster-confirm-delete-' . $packageView->getType();
$deleteDescription = 'plugin' === $packageView->getType()
	? __( 'WordPress will deactivate the plugin and run its package-defined uninstall before deletion. Settings may be permanently removed, while incomplete cleanup may leave incompatible data. This is not a rollback.', 'ran-booster' )
	: __( 'WordPress will delete the inactive theme before Booster unlinks it. Active, parent and depended-on themes are protected. Theme deletion is not a database rollback.', 'ran-booster' );

?>
<details id="ran-booster-package-danger-zone" class="ran-booster-settings-disclosure ran-booster-package-danger-zone" data-ran-booster-package-disclosure <?php echo $packageDangerOpen ? 'open' : ''; ?> aria-labelledby="ran-booster-package-danger-zone-heading">
	<summary>
		<h3 id="ran-booster-package-danger-zone-heading" class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Danger zone', 'ran-booster' ); ?></h3>
		<small><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Stop Booster managing this %s, with or without deleting it from WordPress.', 'ran-booster' ), $packageTypeLabel ) ); ?></small>
	</summary>
	<div class="ran-booster-settings-disclosure__body ran-booster-package-danger-zone__actions">
		<form action="" method="POST" data-ran-booster-confirmed-package-removal data-ran-booster-package-mutation data-ran-booster-native-submit>
			<?php wp_nonce_field( $packageView->getAction( 'unlink' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'unlink' ) ); ?>">
			<input type="hidden" name="ran_booster[<?php echo esc_attr( $packageView->getIdentifierField() ); ?>]" value="<?php echo esc_attr( $identifierValue ); ?>">
			<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->getSourceRevision() ); ?>">
			<label for="<?php echo esc_attr( $unlinkCheckboxId ); ?>">
				<input id="<?php echo esc_attr( $unlinkCheckboxId ); ?>" type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required data-ran-booster-package-removal-confirm>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'I understand Booster will stop managing this %s.', 'ran-booster' ), $packageTypeLabel ) ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'The installed files and WordPress activation state are unchanged.', 'ran-booster' ); ?></p>
			<button type="submit" class="button ran-booster-red" disabled data-ran-booster-package-removal-submit>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Unlink %s', 'ran-booster' ), $packageTypeLabel ) ); ?>
			</button>
		</form>

		<form action="" method="POST" data-ran-booster-confirmed-package-removal data-ran-booster-package-mutation data-ran-booster-native-submit>
			<?php wp_nonce_field( $packageView->getAction( 'unlink-delete' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'unlink-delete' ) ); ?>">
			<input type="hidden" name="ran_booster[<?php echo esc_attr( $packageView->getIdentifierField() ); ?>]" value="<?php echo esc_attr( $identifierValue ); ?>">
			<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->getSourceRevision() ); ?>">
			<label for="<?php echo esc_attr( $deleteCheckboxId ); ?>">
				<input id="<?php echo esc_attr( $deleteCheckboxId ); ?>" type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required data-ran-booster-package-removal-confirm>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'I understand this will remove the %s from this WordPress site.', 'ran-booster' ), $packageTypeLabel ) ); ?>
			</label>
			<p class="description">
				<?php echo esc_html( $deleteDescription ); ?>
			</p>
			<button type="submit" class="button button-delete" disabled data-ran-booster-package-removal-submit>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Unlink and delete %s', 'ran-booster' ), $packageTypeLabel ) ); ?>
			</button>
		</form>
	</div>
</details>

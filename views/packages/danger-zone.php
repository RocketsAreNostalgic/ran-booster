<?php

/**
 * Inherited from the including package template.
 *
 * @var string $identifier_value
 * @var \RAN\Plugin|\RAN\Theme $package
 * @var bool $package_danger_open
 * @var \RAN\Admin\PackagePagePresenter $package_view
 */

defined( 'WPINC' ) || die;

$package_type_label = strtolower( $package_view->get_singular_label() );
$unlink_checkbox_id = 'ran-booster-confirm-unlink-' . $package_view->get_type();
$delete_checkbox_id = 'ran-booster-confirm-delete-' . $package_view->get_type();
$delete_description = 'plugin' === $package_view->get_type()
	? __( 'WordPress will deactivate the plugin and run its package-defined uninstall before deletion. Settings may be permanently removed, while incomplete cleanup may leave incompatible data. This is not a rollback.', 'ran-booster' )
	: __( 'WordPress will delete the inactive theme before Booster unlinks it. Active, parent and depended-on themes are protected. Theme deletion is not a database rollback.', 'ran-booster' );

?>
<details id="ran-booster-package-danger-zone" class="ran-booster-settings-disclosure ran-booster-package-danger-zone" data-ran-booster-package-disclosure <?php echo $package_danger_open ? 'open' : ''; ?> aria-labelledby="ran-booster-package-danger-zone-heading">
	<summary>
		<h3 id="ran-booster-package-danger-zone-heading" class="ran-booster-section__title ran-booster-settings-disclosure__label"><?php esc_html_e( 'Danger zone', 'ran-booster' ); ?></h3>
		<small><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Stop Booster managing this %s, with or without deleting it from WordPress.', 'ran-booster' ), $package_type_label ) ); ?></small>
	</summary>
	<div class="ran-booster-settings-disclosure__body ran-booster-package-danger-zone__actions">
		<form action="" method="POST" data-ran-booster-confirmed-package-removal data-ran-booster-package-mutation data-ran-booster-native-submit>
			<?php wp_nonce_field( $package_view->get_action( 'unlink' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'unlink' ) ); ?>">
			<input type="hidden" name="ran_booster[<?php echo esc_attr( $package_view->get_identifier_field() ); ?>]" value="<?php echo esc_attr( $identifier_value ); ?>">
			<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->get_source_revision() ); ?>">
			<label for="<?php echo esc_attr( $unlink_checkbox_id ); ?>">
				<input id="<?php echo esc_attr( $unlink_checkbox_id ); ?>" type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required data-ran-booster-package-removal-confirm>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'I understand Booster will stop managing this %s.', 'ran-booster' ), $package_type_label ) ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'The installed files and WordPress activation state are unchanged.', 'ran-booster' ); ?></p>
			<button type="submit" class="button ran-booster-red" disabled data-ran-booster-package-removal-submit>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Unlink %s', 'ran-booster' ), $package_type_label ) ); ?>
			</button>
		</form>

		<form action="" method="POST" data-ran-booster-confirmed-package-removal data-ran-booster-package-mutation data-ran-booster-native-submit>
			<?php wp_nonce_field( $package_view->get_action( 'unlink-delete' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'unlink-delete' ) ); ?>">
			<input type="hidden" name="ran_booster[<?php echo esc_attr( $package_view->get_identifier_field() ); ?>]" value="<?php echo esc_attr( $identifier_value ); ?>">
			<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->get_source_revision() ); ?>">
			<label for="<?php echo esc_attr( $delete_checkbox_id ); ?>">
				<input id="<?php echo esc_attr( $delete_checkbox_id ); ?>" type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required data-ran-booster-package-removal-confirm>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'I understand this will remove the %s from this WordPress site.', 'ran-booster' ), $package_type_label ) ); ?>
			</label>
			<p class="description">
				<?php echo esc_html( $delete_description ); ?>
			</p>
			<button type="submit" class="button button-delete" disabled data-ran-booster-package-removal-submit>
				<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Unlink and delete %s', 'ran-booster' ), $package_type_label ) ); ?>
			</button>
		</form>
	</div>
</details>

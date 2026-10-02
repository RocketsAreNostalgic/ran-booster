<?php

/**
 * Inherited from the including package template.
 *
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var bool $provider_browse_available
 * @var string $repository_value
 */

defined( 'WPINC' ) || die;

$repository_picker_hidden_attribute = $provider_browse_available ? '' : ' hidden';
$package_field_grid                = isset( $package_field_layout ) && 'grid' === $package_field_layout;
$repository_read_only              = isset( $repository_read_only ) && true === $repository_read_only;

?>
<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide">
		<label for="ran-booster-repository-name"><?php echo esc_html( sprintf( /* translators: %1$s: Package type label, such as Plugin or Theme. */ __( '%1$s repository', 'ran-booster' ), $package_view->get_singular_label() ) ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-name"><?php echo esc_html( sprintf( /* translators: %1$s: Package type label, such as Plugin or Theme. */ __( '%1$s repository', 'ran-booster' ), $package_view->get_singular_label() ) ); ?></label></th>
		<td>
<?php } ?>
		<div class="ran-booster-repository-field">
			<input id="ran-booster-repository-name" name="ran_booster[repository]" type="text" class="regular-text ran-booster-repository-input" placeholder="<?php esc_attr_e( 'repo-name/package-name', 'ran-booster' ); ?>" value="<?php echo esc_attr( $repository_value ); ?>" maxlength="512" required <?php disabled( $repository_read_only ); ?>>
			<button type="button" class="button ran-booster-open-repository-picker" data-package-type="<?php echo esc_attr( $package_view->get_type() ); ?>"<?php echo esc_attr( $repository_picker_hidden_attribute ); ?> <?php disabled( ! $provider_browse_available || $repository_read_only ); ?>><?php echo esc_html( sprintf( /* translators: %1$s: Package type label, such as Plugin or Theme. */ __( 'Pick %1$s repository', 'ran-booster' ), $package_view->get_singular_label() ) ); ?></button>
		</div>
		<p class="description"><?php esc_html_e( 'Repository locator supplied by the selected provider.', 'ran-booster' ); ?></p>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

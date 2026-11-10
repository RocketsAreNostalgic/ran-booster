<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var bool $provider_browse_available
 * @var string $repository_value
 */

defined( 'WPINC' ) || die;

$repository_picker_hidden_attribute = $provider_browse_available ? '' : ' hidden';
$package_field_grid                 = isset( $package_field_layout ) && 'grid' === $package_field_layout;
$repository_read_only               = isset( $repository_read_only ) && true === $repository_read_only;
$repository_label                   = sprintf( /* translators: %1$s: Package type label, such as Plugin or Theme. */ __( '%1$s repository', 'ran-booster' ), $package_view->get_singular_label() );
// The link reuses the stored provider's validated repository URL base; without one the name stays plain text.
$repository_read_only_url = $repository_read_only && isset( $stored_repository_url_base, $repository_url )
	&& is_string( $stored_repository_url_base ) && '' !== $stored_repository_url_base && is_string( $repository_url )
	? $repository_url
	: '';

?>
<?php if ( $repository_read_only ) { ?>
	<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide">
		<span id="ran-booster-repository-name-label" class="ran-booster-settings-field__label"><?php echo esc_html( $repository_label ); ?></span>
	<?php } else { ?>
	<tr>
		<th scope="row"><span id="ran-booster-repository-name-label"><?php echo esc_html( $repository_label ); ?></span></th>
		<td>
	<?php } ?>
		<div id="ran-booster-repository-name" class="ran-booster-repository-field ran-booster-repository-field--read-only" role="group" aria-labelledby="ran-booster-repository-name-label" aria-describedby="ran-booster-repository-fixed-description">
			<?php if ( '' !== $repository_read_only_url ) { ?>
				<a href="<?php echo esc_url( $repository_read_only_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $repository_value ); ?></a>
			<?php } else { ?>
				<span><?php echo esc_html( $repository_value ); ?></span>
			<?php } ?>
		</div>
		<p id="ran-booster-repository-fixed-description" class="description"><?php esc_html_e( 'The Git provider and repository cannot change once a package is installed.', 'ran-booster' ); ?></p>
<?php } elseif ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide">
		<label for="ran-booster-repository-name"><?php echo esc_html( $repository_label ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-name"><?php echo esc_html( $repository_label ); ?></label></th>
		<td>
<?php } ?>
<?php if ( ! $repository_read_only ) { ?>
		<div class="ran-booster-repository-field">
			<input id="ran-booster-repository-name" name="ran_booster[repository]" type="text" class="regular-text ran-booster-repository-input" placeholder="<?php esc_attr_e( 'repo-name/package-name', 'ran-booster' ); ?>" value="<?php echo esc_attr( $repository_value ); ?>" maxlength="512" required>
			<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() . ' ran-booster-open-repository-picker' ); ?>" data-package-type="<?php echo esc_attr( $package_view->get_type() ); ?>"<?php echo esc_attr( $repository_picker_hidden_attribute ); ?> <?php disabled( ! $provider_browse_available ); ?>><?php echo esc_html( sprintf( /* translators: %1$s: Package type label, such as Plugin or Theme. */ __( 'Pick %1$s repository', 'ran-booster' ), $package_view->get_singular_label() ) ); ?></button>
		</div>
<?php } ?>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

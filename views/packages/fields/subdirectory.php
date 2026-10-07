<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var string $subdirectory_value
 */

defined( 'WPINC' ) || die;

$package_field_grid = isset( $package_field_layout ) && 'grid' === $package_field_layout;
$branch_read_only   = isset( $branch_read_only ) && true === $branch_read_only;
$package_field_form = isset( $package_field_form ) && is_string( $package_field_form )
	? $package_field_form
	: '';

?>
<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-repository-subdirectory"><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-subdirectory"><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<input id="ran-booster-repository-subdirectory" name="ran_booster[subdirectory]" type="text" class="regular-text" placeholder="example/plugin" value="<?php echo esc_attr( $subdirectory_value ); ?>"<?php echo '' !== $package_field_form ? ' form="' . esc_attr( $package_field_form ) . '"' : ''; ?> <?php disabled( $branch_read_only ); ?>>
		<?php /* translators: %s: package type, such as plugin or theme. */ ?>
		<p class="description"><?php printf( esc_html__( 'Only when the %s lives below the repository root.', 'ran-booster' ), esc_html( $package_view->get_type() ) ); ?></p>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

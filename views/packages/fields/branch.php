<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var string $branch_value
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
		<label for="ran-booster-repository-branch"><?php esc_html_e( 'Repository branch', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-branch"><?php esc_html_e( 'Repository branch', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<input id="ran-booster-repository-branch" name="ran_booster[branch]" type="text" class="regular-text ran-booster-branch-input" placeholder="<?php esc_attr_e( 'main, development etc.', 'ran-booster' ); ?>" value="<?php echo esc_attr( $branch_value ); ?>"<?php echo '' !== $package_field_form ? ' form="' . esc_attr( $package_field_form ) . '"' : ''; ?> <?php disabled( $branch_read_only ); ?>>
		<p class="description"><?php esc_html_e( 'Leave blank to use the repository provider\'s default branch.', 'ran-booster' ); ?></p>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

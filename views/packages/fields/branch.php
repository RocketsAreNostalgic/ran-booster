<?php

defined( 'WPINC' ) || die;

$packageFieldGrid = isset( $packageFieldLayout ) && 'grid' === $packageFieldLayout;
$branchReadOnly   = isset( $branchReadOnly ) && true === $branchReadOnly;
$packageFieldForm = isset( $packageFieldForm ) && is_string( $packageFieldForm )
	? $packageFieldForm
	: '';

?>
<?php if ( $packageFieldGrid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-repository-branch"><?php esc_html_e( 'Repository branch', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-branch"><?php esc_html_e( 'Repository branch', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<input id="ran-booster-repository-branch" name="ran_booster[branch]" type="text" class="regular-text ran-booster-branch-input" placeholder="<?php esc_attr_e( 'main, development etc.', 'ran-booster' ); ?>" value="<?php echo esc_attr( $branchValue ); ?>"<?php echo '' !== $packageFieldForm ? ' form="' . esc_attr( $packageFieldForm ) . '"' : ''; ?> <?php disabled( $branchReadOnly ); ?>>
		<p class="description"><?php esc_html_e( 'Leave blank to use the repository provider\'s default branch.', 'ran-booster' ); ?></p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

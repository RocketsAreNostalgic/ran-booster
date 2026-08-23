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
		<label for="ran-booster-repository-subdirectory">Repository subdirectory</label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-subdirectory">Repository subdirectory</label></th>
		<td>
<?php } ?>
		<input id="ran-booster-repository-subdirectory" name="ran_booster[subdirectory]" type="text" class="regular-text" placeholder="expample/plugin" value="<?php echo esc_attr( $subdirectoryValue ); ?>"<?php echo '' !== $packageFieldForm ? ' form="' . esc_attr( $packageFieldForm ) . '"' : ''; ?> <?php disabled( $branchReadOnly ); ?>>
		<p class="description">Only when the <?php echo esc_html( $packageView->getType() ); ?> lives below the repository root.</p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

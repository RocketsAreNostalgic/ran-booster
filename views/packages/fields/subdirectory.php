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
		<label for="ran-booster-repository-subdirectory"><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-subdirectory"><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<input id="ran-booster-repository-subdirectory" name="ran_booster[subdirectory]" type="text" class="regular-text" placeholder="example/plugin" value="<?php echo esc_attr( $subdirectoryValue ); ?>"<?php echo '' !== $packageFieldForm ? ' form="' . esc_attr( $packageFieldForm ) . '"' : ''; ?> <?php disabled( $branchReadOnly ); ?>>
		<?php /* translators: %s: package type, such as plugin or theme. */ ?>
		<p class="description"><?php printf( esc_html__( 'Only when the %s lives below the repository root.', 'ran-booster' ), esc_html( $packageView->getType() ) ); ?></p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

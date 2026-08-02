<?php

defined( 'WPINC' ) || die;

$repositoryPickerHiddenAttribute = $providerBrowseAvailable ? '' : ' hidden';
$packageFieldGrid                = isset( $packageFieldLayout ) && 'grid' === $packageFieldLayout;
$repositoryReadOnly              = isset( $repositoryReadOnly ) && true === $repositoryReadOnly;

?>
<?php if ( $packageFieldGrid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide">
		<label for="ran-booster-repository-name"><?php echo esc_html( $packageView->getSingularLabel() ); ?> repository</label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-repository-name"><?php echo esc_html( $packageView->getSingularLabel() ); ?> repository</label></th>
		<td>
<?php } ?>
		<div class="ran-booster-repository-field">
			<input id="ran-booster-repository-name" name="ran_booster[repository]" type="text" class="regular-text ran-booster-repository-input" placeholder="repo-name/package-name" value="<?php echo esc_attr( $repositoryValue ); ?>" maxlength="512" required <?php disabled( $repositoryReadOnly ); ?>>
			<button type="button" class="button ran-booster-open-repository-picker" data-package-type="<?php echo esc_attr( $packageView->getType() ); ?>"<?php echo esc_attr( $repositoryPickerHiddenAttribute ); ?> <?php disabled( ! $providerBrowseAvailable || $repositoryReadOnly ); ?>>Pick <?php echo esc_html( $packageView->getType() ); ?> repository</button>
		</div>
		<p class="description">Repository locator supplied by the selected provider.</p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

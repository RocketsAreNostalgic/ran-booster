<?php

defined( 'WPINC' ) || die;

$packageFieldGrid   = isset( $packageFieldLayout ) && 'grid' === $packageFieldLayout;
$repositoryReadOnly = isset( $repositoryReadOnly ) && true === $repositoryReadOnly;

?>
<?php if ( $packageFieldGrid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-provider">Repository provider</label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-provider">Repository provider</label></th>
		<td>
<?php } ?>
		<select id="ran-booster-provider" name="ran_booster[provider]" class="ran-booster-provider-input" <?php disabled( ! $packageMutationAvailable || $repositoryReadOnly ); ?>>
			<?php foreach ( $providerOptions as $providerOption ) { ?>
				<?php $providerStatus = ! $providerOption['available'] ? __( ' — Provider unavailable', 'ran-booster' ) : ( $providerOption['deploy'] ? '' : __( ' — integration unavailable', 'ran-booster' ) ); ?>
				<option value="<?php echo esc_attr( $providerOption['code'] ); ?>" data-label="<?php echo esc_attr( $providerOption['label'] ); ?>" data-browse="<?php echo $providerOption['browse'] ? '1' : '0'; ?>" data-deploy="<?php echo $providerOption['deploy'] ? '1' : '0'; ?>" data-webhooks="<?php echo $providerOption['webhooks'] ? '1' : '0'; ?>" data-repository-url-base="<?php echo esc_attr( $providerOption['repository_url_base'] ); ?>" <?php selected( $providerCode, $providerOption['code'] ); ?> <?php disabled( ! $providerOption['deploy'] && $providerCode !== $providerOption['code'] ); ?>><?php echo esc_html( $providerOption['label'] . $providerStatus ); ?></option>
			<?php } ?>
		</select>
		<p class="description ran-booster-provider-description">Choose the git service.</p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

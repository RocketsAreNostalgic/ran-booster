<?php

defined( 'WPINC' ) || die;

$packageFieldGrid = isset( $packageFieldLayout ) && 'grid' === $packageFieldLayout;

?>
<?php if ( $packageFieldGrid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-credential-id">Repository access</label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-credential-id">Repository access</label></th>
		<td>
<?php } ?>
		<select id="ran-booster-credential-id" name="ran_booster[credential_id]" class="ran-booster-credential-input">
			<option value="" <?php selected( $selectedCredentialId, '' ); ?>>Default / public repository</option>
			<?php
			foreach ( $providerOptions as $providerOption ) {
				foreach ( $providerOption['credential_profiles'] as $profile ) {
					$credentialLabel = $profile['label'] . ' — ' . $profile['kind_label'];
					if ( '' !== $profile['detail'] ) {
						$credentialLabel .= ' · ' . $profile['detail'];
					}
					?>
					<option value="<?php echo esc_attr( $profile['id'] ); ?>" data-provider="<?php echo esc_attr( $providerOption['code'] ); ?>" <?php selected( $providerCode === $providerOption['code'] && $selectedCredentialId === $profile['id'] ); ?> <?php disabled( $providerCode !== $providerOption['code'] ); ?> <?php echo $providerCode !== $providerOption['code'] ? 'hidden' : ''; ?>><?php echo esc_html( $credentialLabel ); ?></option>
					<?php
				}
			}
			?>
			</select>
			<p class="description">Choose the saved credential that can access this repository. Public repositories can use the default option.</p>
			<p class="description"><?php esc_html_e( 'The active provider can read every credential saved under its provider code, not only the selected profile. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

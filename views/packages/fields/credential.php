<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var string $provider_code
 * @var list<array<string, mixed>> $provider_options
 * @var string $selected_credential_id
 */

defined( 'WPINC' ) || die;

$package_field_grid = isset( $package_field_layout ) && 'grid' === $package_field_layout;

?>
<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-credential-id"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-credential-id"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<select id="ran-booster-credential-id" name="ran_booster[credential_id]" class="ran-booster-credential-input">
			<option value="" <?php selected( $selected_credential_id, '' ); ?>><?php esc_html_e( 'Default / public repository', 'ran-booster' ); ?></option>
			<?php
			foreach ( $provider_options as $provider_option ) {
				foreach ( $provider_option['credential_profiles'] as $profile ) {
					$credential_label = $profile['label'] . ' — ' . $profile['kind_label'];
					if ( '' !== $profile['detail'] ) {
						$credential_label .= ' · ' . $profile['detail'];
					}
					?>
					<option value="<?php echo esc_attr( $profile['id'] ); ?>" data-provider="<?php echo esc_attr( $provider_option['code'] ); ?>" <?php selected( $provider_code === $provider_option['code'] && $selected_credential_id === $profile['id'] ); ?> <?php disabled( $provider_code !== $provider_option['code'] ); ?> <?php echo $provider_code !== $provider_option['code'] ? 'hidden' : ''; ?>><?php echo esc_html( $credential_label ); ?></option>
					<?php
				}
			}
			?>
			</select>
			<p class="description"><?php esc_html_e( 'Private repos require a PAT with appropriate access.', 'ran-booster' ); ?></p>
			<p class="description"><?php esc_html_e( 'Only install provider integrations you trust: an active provider can read its saved credentials. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

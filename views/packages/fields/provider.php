<?php

/**
 * Inherited from the including package template.
 *
 * @var bool $package_mutation_available
 * @var string $provider_code
 * @var list<array<string, mixed>> $provider_options
 */

defined( 'WPINC' ) || die;

$package_field_grid   = isset( $package_field_layout ) && 'grid' === $package_field_layout;
$repository_read_only = isset( $repository_read_only ) && true === $repository_read_only;

?>
<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-provider"><?php esc_html_e( 'Repository provider', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-provider"><?php esc_html_e( 'Repository provider', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<select id="ran-booster-provider" name="ran_booster[provider]" class="ran-booster-provider-input" <?php disabled( ! $package_mutation_available || $repository_read_only ); ?>>
			<?php foreach ( $provider_options as $provider_option ) { ?>
				<?php $provider_status = ! $provider_option['available'] ? __( ' — Provider unavailable', 'ran-booster' ) : ( $provider_option['deploy'] ? '' : __( ' — integration unavailable', 'ran-booster' ) ); ?>
				<option value="<?php echo esc_attr( $provider_option['code'] ); ?>" data-label="<?php echo esc_attr( $provider_option['label'] ); ?>" data-browse="<?php echo $provider_option['browse'] ? '1' : '0'; ?>" data-deploy="<?php echo $provider_option['deploy'] ? '1' : '0'; ?>" data-webhooks="<?php echo $provider_option['webhooks'] ? '1' : '0'; ?>" data-repository-url-base="<?php echo esc_attr( $provider_option['repository_url_base'] ); ?>" <?php selected( $provider_code, $provider_option['code'] ); ?> <?php disabled( ! $provider_option['deploy'] && $provider_code !== $provider_option['code'] ); ?>><?php echo esc_html( $provider_option['label'] . $provider_status ); ?></option>
			<?php } ?>
		</select>
		<p class="description ran-booster-provider-description"><?php esc_html_e( 'Choose the Git service.', 'ran-booster' ); ?></p>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

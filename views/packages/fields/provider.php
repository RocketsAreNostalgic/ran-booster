<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

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

$provider_read_only_label = $provider_code;
$provider_read_only_url   = '';
foreach ( $provider_options as $provider_option ) {
	if ( $provider_option['code'] === $provider_code ) {
		$provider_read_only_label = $provider_option['label'];
		$provider_read_only_url   = isset( $provider_option['credentials_url'] ) && is_string( $provider_option['credentials_url'] ) && '' !== $provider_option['credentials_url']
			? admin_url( $provider_option['credentials_url'] )
			: '';
		break;
	}
}

?>
<?php if ( $repository_read_only ) { ?>
	<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field">
		<span id="ran-booster-provider-name-label" class="ran-booster-settings-field__label"><?php esc_html_e( 'Git provider', 'ran-booster' ); ?></span>
	<?php } else { ?>
	<tr>
		<th scope="row"><span id="ran-booster-provider-name-label"><?php esc_html_e( 'Git provider', 'ran-booster' ); ?></span></th>
		<td>
	<?php } ?>
		<div id="ran-booster-provider-name" class="ran-booster-repository-field ran-booster-repository-field--read-only" role="group" aria-labelledby="ran-booster-provider-name-label" aria-describedby="ran-booster-repository-fixed-description">
			<?php if ( '' !== $provider_read_only_url ) { ?>
				<a href="<?php echo esc_url( $provider_read_only_url ); ?>"><?php echo esc_html( $provider_read_only_label ); ?></a>
			<?php } else { ?>
				<span><?php echo esc_html( $provider_read_only_label ); ?></span>
			<?php } ?>
		</div>
<?php } elseif ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field">
		<label for="ran-booster-provider"><?php esc_html_e( 'Git provider', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr>
		<th scope="row"><label for="ran-booster-provider"><?php esc_html_e( 'Git provider', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
<?php if ( ! $repository_read_only ) { ?>
		<select id="ran-booster-provider" name="ran_booster[provider]" class="ran-booster-provider-input" <?php disabled( ! $package_mutation_available ); ?>>
			<?php foreach ( $provider_options as $provider_option ) { ?>
				<?php $provider_status = ! $provider_option['available'] ? __( ' — Git provider unavailable', 'ran-booster' ) : ( $provider_option['deploy'] ? '' : __( ' — integration unavailable', 'ran-booster' ) ); ?>
				<option value="<?php echo esc_attr( $provider_option['code'] ); ?>" data-label="<?php echo esc_attr( $provider_option['label'] ); ?>" data-browse="<?php echo $provider_option['browse'] ? '1' : '0'; ?>" data-deploy="<?php echo $provider_option['deploy'] ? '1' : '0'; ?>" data-webhooks="<?php echo $provider_option['webhooks'] ? '1' : '0'; ?>" data-repository-url-base="<?php echo esc_attr( $provider_option['repository_url_base'] ); ?>" <?php selected( $provider_code, $provider_option['code'] ); ?> <?php disabled( ! $provider_option['deploy'] && $provider_code !== $provider_option['code'] ); ?>><?php echo esc_html( $provider_option['label'] . $provider_status ); ?></option>
			<?php } ?>
		</select>
<?php } ?>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

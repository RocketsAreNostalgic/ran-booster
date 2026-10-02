<?php

/**
 * Inherited from the including package template.
 *
 * @var string $deployment_policy
 * @var string $provider_code
 * @var bool $provider_webhook_available
 */

defined( 'WPINC' ) || die;

$push_to_deploy_documentation_url = admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-push-to-deploy' );
$push_to_deploy_provider_url      = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) . '#ran-booster-webhook-secrets-heading' );
$release_automation               = isset( $package_automation_source ) && 'release_asset' === $package_automation_source;
$automatic_available              = $release_automation || $provider_webhook_available;
$package_field_grid               = isset( $package_field_layout ) && 'grid' === $package_field_layout;
$package_field_form               = isset( $package_field_form ) && is_string( $package_field_form )
	? $package_field_form
	: '';
$show_development_safety_notice   = ! empty( $development_environment_detected );
$hide_development_safety_notice   = \RAN\Deployment\DeploymentPolicy::DISABLED->value === $deployment_policy;

?>
<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide ran-booster-deployment-policy-field">
		<label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr class="ran-booster-deployment-policy-field">
		<th scope="row"><label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<select name="ran_booster[deployment_policy]" id="ran-booster-deployment-policy" class="ran-booster-deployment-policy-input"<?php echo '' !== $package_field_form ? ' form="' . esc_attr( $package_field_form ) . '"' : ''; ?>>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>><?php esc_html_e( 'Disabled — do not let Booster update this package', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>><?php esc_html_e( 'Manual — update only when requested', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?> <?php disabled( ! $automatic_available ); ?>><?php echo esc_html( $release_automation ? __( 'Automatic — install validated releases through WordPress Updates', 'ran-booster' ) : __( 'Automatic — deploy signed repository pushes', 'ran-booster' ) ); ?></option>
		</select>
		<?php if ( $show_development_safety_notice ) { ?>
			<div class="notice notice-warning inline" data-ran-booster-local-development-warning<?php echo $hide_development_safety_notice ? ' hidden' : ''; ?>>
				<p>
			<strong><?php esc_html_e( 'NOTICE: Editing this package’s files on this site? Choose Disabled.', 'ran-booster' ); ?></strong><br/>
			<?php esc_html_e( 'Manual and Automatic can overwrite local changes when an update or reinstall runs.', 'ran-booster' ); ?>
			<?php if ( ! $release_automation && $provider_webhook_available ) { ?>
				<br/><a href="<?php echo esc_url( $push_to_deploy_provider_url ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a>
			<?php } ?>
				</p>
			</div>
		<?php } ?>
		<?php if ( $release_automation ) { ?>
			<p class="description"><?php esc_html_e( 'Manual waits for an administrator; Automatic lets WordPress install validated published updates.', 'ran-booster' ); ?></p>
		<?php } elseif ( ! $provider_webhook_available ) { ?>
			<p class="description">
				<?php esc_html_e( 'Automatic deployment is unavailable because this provider does not support signed webhooks.', 'ran-booster' ); ?>
				<a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Read the Push-to-Deploy guide.', 'ran-booster' ); ?></a>
			</p>
		<?php } elseif ( ! $show_development_safety_notice ) { ?>
			<p class="description"><a href="<?php echo esc_url( $push_to_deploy_provider_url ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a></p>
		<?php } ?>
<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

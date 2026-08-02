<?php

defined( 'WPINC' ) || die;

$pushToDeployDocumentationUrl = admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-push-to-deploy' );
$pushToDeployProviderUrl      = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) . '#ran-booster-webhook-secrets-heading' );
$releaseAutomation            = isset( $packageAutomationSource ) && 'release_asset' === $packageAutomationSource;
$automaticAvailable           = $releaseAutomation || $providerWebhookAvailable;
$packageFieldGrid             = isset( $packageFieldLayout ) && 'grid' === $packageFieldLayout;
$packageFieldForm             = isset( $packageFieldForm ) && is_string( $packageFieldForm )
	? $packageFieldForm
	: '';
$showDevelopmentSafetyNotice  = ! empty( $developmentEnvironmentDetected );
$hideDevelopmentSafetyNotice  = \RAN\Deployment\DeploymentPolicy::DISABLED->value === $deploymentPolicy;

?>
<?php if ( $packageFieldGrid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide ran-booster-deployment-policy-field">
		<label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr class="ran-booster-deployment-policy-field">
		<th scope="row"><label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<select name="ran_booster[deployment_policy]" id="ran-booster-deployment-policy" class="ran-booster-deployment-policy-input"<?php echo '' !== $packageFieldForm ? ' form="' . esc_attr( $packageFieldForm ) . '"' : ''; ?>>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>" <?php selected( $deploymentPolicy, \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>><?php esc_html_e( 'Disabled — do not let Booster update this package', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>" <?php selected( $deploymentPolicy, \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>><?php esc_html_e( 'Manual — update only when requested', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?>" <?php selected( $deploymentPolicy, \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?> <?php disabled( ! $automaticAvailable ); ?>><?php echo esc_html( $releaseAutomation ? __( 'Automatic — install validated releases through WordPress Updates', 'ran-booster' ) : __( 'Automatic — deploy signed repository pushes', 'ran-booster' ) ); ?></option>
		</select>
		<?php if ( $showDevelopmentSafetyNotice ) { ?>
			<div class="notice notice-warning inline" data-ran-booster-local-development-warning<?php echo $hideDevelopmentSafetyNotice ? ' hidden' : ''; ?>>
				<p>
			<strong><?php esc_html_e( 'NOTICE: Editing this package’s files on this site? Choose Disabled.', 'ran-booster' ); ?></strong><br/>
			<?php esc_html_e( 'Manual and Automatic can overwrite local changes when an update or reinstall runs.', 'ran-booster' ); ?>
			<?php if ( ! $releaseAutomation && $providerWebhookAvailable ) { ?>
				<br/><a href="<?php echo esc_url( $pushToDeployProviderUrl ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $pushToDeployDocumentationUrl ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a>
			<?php } ?>
				</p>
			</div>
		<?php } ?>
		<?php if ( $releaseAutomation ) { ?>
			<p class="description"><?php esc_html_e( 'Manual waits for an administrator; Automatic lets WordPress install validated published updates.', 'ran-booster' ); ?></p>
		<?php } elseif ( ! $providerWebhookAvailable ) { ?>
			<p class="description">
				<?php esc_html_e( 'Automatic deployment is unavailable because this provider does not support signed webhooks.', 'ran-booster' ); ?>
				<a href="<?php echo esc_url( $pushToDeployDocumentationUrl ); ?>"><?php esc_html_e( 'Read the Push-to-Deploy guide.', 'ran-booster' ); ?></a>
			</p>
		<?php } elseif ( ! $showDevelopmentSafetyNotice ) { ?>
			<p class="description"><a href="<?php echo esc_url( $pushToDeployProviderUrl ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $pushToDeployDocumentationUrl ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a></p>
		<?php } ?>
<?php if ( $packageFieldGrid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>

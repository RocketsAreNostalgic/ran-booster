<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

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
$operation_layout                 = ! empty( $package_operation_layout );
$policy_source_toggle             = ! empty( $package_policy_source_toggle );
$operation_actions_html           = isset( $package_operation_actions_html ) && is_string( $package_operation_actions_html )
	? $package_operation_actions_html
	: '';
$branch_policy_description        = __( 'Manual waits for an administrator. Automatic deploys on push.', 'ran-booster' );
$show_development_safety_notice   = ! empty( $development_environment_detected );
$hide_development_safety_notice   = \RAN\Deployment\DeploymentPolicy::DISABLED->value === $deployment_policy;

$render_development_safety_notice = static function () use ( $show_development_safety_notice, $hide_development_safety_notice, $release_automation, $provider_webhook_available, $push_to_deploy_provider_url, $push_to_deploy_documentation_url ): void {
	if ( $show_development_safety_notice ) {
		?>
	<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning" data-ran-booster-local-development-warning<?php echo $hide_development_safety_notice ? ' hidden' : ''; ?>>
		<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
		<p>
			<strong><?php esc_html_e( 'Editing this package’s files here? Choose Disabled.', 'ran-booster' ); ?></strong><br/>
			<?php esc_html_e( 'Manual and Automatic can overwrite local changes when an update or reinstall runs.', 'ran-booster' ); ?>
			<?php if ( ! $release_automation && $provider_webhook_available ) { ?>
				<br/><a href="<?php echo esc_url( $push_to_deploy_provider_url ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a>
			<?php } ?>
		</p>
		</div>
		<?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?>
	</div>
		<?php
	}
};

$render_policy_select = static function () use ( $deployment_policy, $automatic_available, $release_automation, $package_field_form ): void {
	?>
		<select name="ran_booster[deployment_policy]" id="ran-booster-deployment-policy" class="ran-booster-deployment-policy-input"<?php echo '' !== $package_field_form ? ' form="' . esc_attr( $package_field_form ) . '"' : ''; ?>>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::DISABLED->value ); ?>><?php esc_html_e( 'Disabled — do not let Booster update this package', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::MANUAL->value ); ?>><?php esc_html_e( 'Manual — update only when requested', 'ran-booster' ); ?></option>
			<option value="<?php echo esc_attr( \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?>" <?php selected( $deployment_policy, \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value ); ?> <?php disabled( ! $automatic_available ); ?>><?php echo esc_html( $release_automation ? __( 'Automatic — install validated releases through WordPress Updates', 'ran-booster' ) : __( 'Automatic — deploy signed repository pushes', 'ran-booster' ) ); ?></option>
		</select>
	<?php
};

$render_policy_description = static function () use ( $operation_layout, $policy_source_toggle, $branch_policy_description, $release_automation, $provider_webhook_available, $show_development_safety_notice, $push_to_deploy_documentation_url, $push_to_deploy_provider_url ): void {
	$source_attribute = $policy_source_toggle ? ' data-ran-booster-policy-source="branch"' : '';
	if ( $release_automation ) {
		?>
			<p class="description"><?php esc_html_e( 'Manual waits for an administrator. Automatic installs validated releases.', 'ran-booster' ); ?></p>
		<?php } elseif ( ! $provider_webhook_available ) { ?>
			<p class="description"<?php echo $source_attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute. ?>>
				<?php esc_html_e( 'Automatic deployment is unavailable because this Git provider does not support signed webhooks.', 'ran-booster' ); ?>
				<a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Read the Push-to-Deploy guide.', 'ran-booster' ); ?></a>
			</p>
		<?php } elseif ( $operation_layout ) { ?>
			<p class="description"<?php echo $source_attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute. ?>>
				<?php echo esc_html( $branch_policy_description ); ?>
				<?php if ( ! $show_development_safety_notice ) { ?>
					<br/><a href="<?php echo esc_url( $push_to_deploy_provider_url ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a>
				<?php } ?>
			</p>
		<?php } elseif ( ! $show_development_safety_notice ) { ?>
			<p class="description"><a href="<?php echo esc_url( $push_to_deploy_provider_url ); ?>"><?php esc_html_e( 'Push-to-Deploy setting', 'ran-booster' ); ?></a> | <a href="<?php echo esc_url( $push_to_deploy_documentation_url ); ?>"><?php esc_html_e( 'Docs', 'ran-booster' ); ?></a></p>
			<?php
		}
		if ( $policy_source_toggle ) {
			?>
			<p class="description" data-ran-booster-policy-source="release_asset" hidden><?php esc_html_e( 'Manual waits for an administrator. Automatic installs validated releases.', 'ran-booster' ); ?></p>
			<?php
		}
};
?>
<?php if ( $operation_layout ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide ran-booster-deployment-policy-field ran-booster-deployment-policy-field--operation">
		<div class="ran-booster-deployment-policy-group">
			<label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label>
			<div class="ran-booster-deployment-policy-row">
				<?php $render_policy_select(); ?>
			</div>
			<?php $render_policy_description(); ?>
		</div>
		<?php $render_development_safety_notice(); ?>
		<?php echo $operation_actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured from escaped package templates in the including view. ?>
	</div>
<?php } else { ?>
	<?php if ( $package_field_grid ) { ?>
	<div class="ran-booster-settings-field ran-booster-settings-field--wide ran-booster-deployment-policy-field">
		<label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label>
<?php } else { ?>
	<tr class="ran-booster-deployment-policy-field">
		<th scope="row"><label for="ran-booster-deployment-policy"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></label></th>
		<td>
<?php } ?>
		<?php $render_policy_select(); ?>
		<?php $render_development_safety_notice(); ?>
		<?php $render_policy_description(); ?>
	<?php if ( $package_field_grid ) { ?>
	</div>
<?php } else { ?>
		</td>
	</tr>
<?php } ?>
<?php } ?>

<?php

defined( 'WPINC' ) || die;

$reinstallAvailable = $packageMutationAvailable
	&& \RAN\Deployment\DeploymentPolicy::DISABLED->value !== $deploymentPolicy;

?>
<button
	type="submit"
	name="ran_booster[reinstall_after_save]"
	value="1"
	form="ran-booster-package-edit-form"
	class="button button-secondary button-update-package"
	<?php disabled( ! $reinstallAvailable ); ?>
	data-ran-booster-update-button
	data-ran-booster-settings-reinstall
	data-ran-booster-reinstall-capable="<?php echo esc_attr( $packageMutationAvailable ? '1' : '0' ); ?>"
	data-ran-booster-enhanced-mutation
	data-ran-booster-error-target="#ran-booster-package-mutation-error"
	data-ran-booster-package-mutation
	data-idle-label="<?php esc_attr_e( 'Reinstall', 'ran-booster' ); ?>"
	data-update-can-run="<?php echo esc_attr( $reinstallAvailable ? '1' : '0' ); ?>"
	data-reinstall-confirm-message="<?php esc_attr_e( 'Reinstall from the saved branch and overwrite local changes?', 'ran-booster' ); ?>"
	hx-post="<?php echo esc_url( $settingsUrl ); ?>"
	hx-target="#wpbody-content"
	hx-select="#wpbody-content"
	hx-swap="outerHTML show:none"
	hx-sync="this:drop"
	hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"
><span data-ran-booster-update-label><?php esc_html_e( 'Reinstall', 'ran-booster' ); ?></span></button>
<p class="description" data-ran-booster-reinstall-guidance <?php echo $reinstallAvailable ? 'hidden' : ''; ?>><?php esc_html_e( 'Set Updates to Manual or Automatic before reinstalling.', 'ran-booster' ); ?></p>

<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var string $deployment_policy
 * @var bool $package_mutation_available
 * @var string $settings_url
 */

defined( 'WPINC' ) || die;

$package_paused      = true === ( $package_paused ?? false );
$reinstall_capable   = $package_mutation_available && ! $package_paused;
$reinstall_available = $reinstall_capable
	&& \RAN\Deployment\DeploymentPolicy::DISABLED->value !== $deployment_policy;
$reinstall_reason    = $package_paused ? wp_strip_all_tags( (string) ( $package_paused_text ?? '' ) ) : __( 'Set Updates to Manual or Automatic before reinstalling.', 'ran-booster' );

?>
<span class="ran-booster-reason-tooltip"<?php echo $reinstall_available ? '' : ' data-ran-booster-reason-tooltip'; ?>>
<button
	type="submit"
	name="ran_booster[reinstall_after_save]"
	value="1"
	form="ran-booster-package-edit-form"
	class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() . ' button-update-package' ); ?>"
	aria-disabled="<?php echo $reinstall_available ? 'false' : 'true'; ?>"
	<?php if ( ! $reinstall_available ) { ?>
	disabled
	title="<?php echo esc_attr( $reinstall_reason ); ?>"
	aria-describedby="ran-booster-reinstall-reason"
	<?php } ?>
	data-ran-booster-update-button
	data-ran-booster-settings-reinstall
	data-ran-booster-reinstall-capable="<?php echo esc_attr( $reinstall_capable ? '1' : '0' ); ?>"
	data-ran-booster-enhanced-mutation
	data-ran-booster-error-target="#ran-booster-package-mutation-error"
	data-ran-booster-package-mutation
	data-idle-label="<?php esc_attr_e( 'Reinstall', 'ran-booster' ); ?>"
	data-update-can-run="<?php echo esc_attr( $reinstall_available ? '1' : '0' ); ?>"
	data-reinstall-confirm-message="<?php esc_attr_e( 'Reinstall from the saved branch and overwrite local changes?', 'ran-booster' ); ?>"
	hx-post="<?php echo esc_url( wp_make_link_relative( $settings_url ) ); ?>"
	hx-target="#wpbody-content"
	hx-select="#wpbody-content"
	hx-swap="outerHTML show:none"
	hx-sync="this:drop"
	hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"
><span data-ran-booster-update-label><?php esc_html_e( 'Reinstall', 'ran-booster' ); ?></span></button>
<span id="ran-booster-reinstall-reason" class="ran-booster-reason-tooltip__bubble" role="tooltip" hidden><?php echo esc_html( $reinstall_reason ); ?></span>
</span>

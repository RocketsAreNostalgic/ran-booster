<?php

/**
 * Inherited from the including package template.
 *
 * @var \RAN\Plugin|\RAN\Theme $package
 */

defined( 'WPINC' ) || die;

?>
<input type="hidden" name="ran_booster[expected_provider]" value="<?php echo esc_attr( (string) $package->get_provider_code() ); ?>">
<input type="hidden" name="ran_booster[expected_provider_repository_id]" value="<?php echo esc_attr( (string) ( $package->get_provider_repository_id() ?? '' ) ); ?>">
<input type="hidden" name="ran_booster[expected_repository]" value="<?php echo esc_attr( (string) $package->get_repository() ); ?>">
<input type="hidden" name="ran_booster[expected_branch]" value="<?php echo esc_attr( (string) $package->get_branch() ); ?>">
<input type="hidden" name="ran_booster[expected_credential_id]" value="<?php echo esc_attr( $package->get_credential_id() ); ?>">
<input type="hidden" name="ran_booster[expected_subdirectory]" value="<?php echo esc_attr( (string) $package->get_subdirectory() ); ?>">
<input type="hidden" name="ran_booster[expected_private]" value="<?php echo esc_attr( $package->get_private() ? '1' : '0' ); ?>">
<input type="hidden" name="ran_booster[expected_package_slug]" value="<?php echo esc_attr( (string) $package->get_slug() ); ?>">
<input type="hidden" name="ran_booster[expected_deployment_policy]" value="<?php echo esc_attr( $package->get_deployment_policy()->value ); ?>">
<input type="hidden" name="ran_booster[expected_source]" value="<?php echo esc_attr( $package->get_source()->value ); ?>">
<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->get_source_revision() ); ?>">

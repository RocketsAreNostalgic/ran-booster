<?php

defined( 'WPINC' ) || die;

?>
<input type="hidden" name="ran_booster[expected_provider]" value="<?php echo esc_attr( (string) $package->getProviderCode() ); ?>">
<input type="hidden" name="ran_booster[expected_provider_repository_id]" value="<?php echo esc_attr( (string) ( $package->getProviderRepositoryId() ?? '' ) ); ?>">
<input type="hidden" name="ran_booster[expected_repository]" value="<?php echo esc_attr( (string) $package->getRepository() ); ?>">
<input type="hidden" name="ran_booster[expected_branch]" value="<?php echo esc_attr( (string) $package->getBranch() ); ?>">
<input type="hidden" name="ran_booster[expected_credential_id]" value="<?php echo esc_attr( $package->getCredentialId() ); ?>">
<input type="hidden" name="ran_booster[expected_subdirectory]" value="<?php echo esc_attr( (string) $package->getSubdirectory() ); ?>">
<input type="hidden" name="ran_booster[expected_private]" value="<?php echo esc_attr( $package->getPrivate() ? '1' : '0' ); ?>">
<input type="hidden" name="ran_booster[expected_package_slug]" value="<?php echo esc_attr( (string) $package->getSlug() ); ?>">
<input type="hidden" name="ran_booster[expected_deployment_policy]" value="<?php echo esc_attr( $package->getDeploymentPolicy()->value ); ?>">
<input type="hidden" name="ran_booster[expected_source]" value="<?php echo esc_attr( $package->getSource()->value ); ?>">
<input type="hidden" name="ran_booster[expected_source_revision]" value="<?php echo esc_attr( (string) $package->getSourceRevision() ); ?>">

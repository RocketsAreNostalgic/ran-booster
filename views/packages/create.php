<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// The dispatcher verifies the action nonce before this template repopulates submitted values.
// phpcs:disable WordPress.Security.NonceVerification.Missing

$providerOptions       = $packageProviderSettings['providers'];
$defaultProviderCode   = $packageProviderSettings['default_provider'];
$selectedCredentialId  = isset( $_POST['ran_booster']['credential_id'] )
	? sanitize_text_field( (string) $_POST['ran_booster']['credential_id'] )
	: '';
$repositoryValue       = isset( $_POST['ran_booster']['repository'] ) ? (string) $_POST['ran_booster']['repository'] : '';
$branchValue           = isset( $_POST['ran_booster']['branch'] ) ? (string) $_POST['ran_booster']['branch'] : '';
$subdirectoryValue     = isset( $_POST['ran_booster']['subdirectory'] ) ? (string) $_POST['ran_booster']['subdirectory'] : '';
$publicLookupProfileId = isset( $_POST['ran_booster']['public_lookup_profile_id'] ) && is_string( $_POST['ran_booster']['public_lookup_profile_id'] )
	? sanitize_text_field( $_POST['ran_booster']['public_lookup_profile_id'] )
	: '';
if ( '' !== $publicLookupProfileId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $publicLookupProfileId ) ) {
	$publicLookupProfileId = '';
}

$deploymentPolicy                 = isset( $_POST['ran_booster']['deployment_policy'] )
	? sanitize_key( (string) $_POST['ran_booster']['deployment_policy'] )
	: \RAN\Deployment\DeploymentPolicy::MANUAL->value;
$providerCode                     = isset( $_POST['ran_booster']['provider'] ) ? sanitize_key( (string) $_POST['ran_booster']['provider'] ) : $defaultProviderCode;
$providerRepositoryId             = isset( $_POST['ran_booster']['provider_repository_id'] ) ? wp_strip_all_tags( wp_unslash( (string) $_POST['ran_booster']['provider_repository_id'] ), true ) : '';
$providerRepositoryIdentitySource = isset( $_POST['ran_booster']['provider_repository_identity_source'] )
	? sanitize_key( (string) $_POST['ran_booster']['provider_repository_identity_source'] )
	: 'manual';

if ( ! in_array( $providerRepositoryIdentitySource, array( 'picker', 'manual' ), true ) ) {
	$providerRepositoryIdentitySource = 'manual';
}

$selectedProviderOption = null;
foreach ( $providerOptions as $providerOption ) {
	if ( $providerOption['code'] === $providerCode ) {
		$selectedProviderOption = $providerOption;
		break;
	}
}
if ( null === $selectedProviderOption ) {
	$selectedProviderOption = $providerOptions[0];
	$providerCode           = $selectedProviderOption['code'];
}
$providerBrowseAvailable  = $selectedProviderOption['browse'];
$providerWebhookAvailable = $selectedProviderOption['webhooks'];
$packageMutationAvailable = isset( $packageMutationAvailable ) ? true === $packageMutationAvailable : true;
$releaseManaged           = false;
$repositoryReadOnly       = false;
$branchReadOnly           = false;
$packageSourceChoices     = is_array( $packageSource['choices'] ?? null ) ? $packageSource['choices'] : array();
$packageAdvancedSections  = is_array( $packageSource['advanced_sections'] ?? null ) ? $packageSource['advanced_sections'] : array();
$packageAdvancedSummary   = is_string( $packageSource['advanced_summary'] ?? null )
	? $packageSource['advanced_summary']
	: __( 'Branch · provider default', 'ran-booster' );
$packageSourceView        = 'branch';
$packageSourceMode        = 'create';
$packageAdvancedOpen      = isset( $_POST['ran_booster'] ) && is_array( $_POST['ran_booster'] );
$packageRepositoryReady   = '' !== trim( $repositoryValue )
	&& strlen( $repositoryValue ) <= 512
	&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repositoryValue );
$adminUrl                 = function_exists( 'is_multisite' ) && is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$backUrl                  = add_query_arg( 'page', $packageView->getPageSlug(), $adminUrl );

?>
<p class="ran-booster-package-settings__back"><a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $packageView->getPluralLabel() ) ); ?></a></p>
<h2 id="ran-booster-package-create-heading" class="ran-booster-package-settings__heading"><?php echo esc_html( sprintf( /* translators: %s is Plugin or Theme. */ __( 'Install New %s', 'ran-booster' ), $packageView->getSingularLabel() ) ); ?></h2>
<p class="ran-booster-package-settings__intro"><?php esc_html_e( 'Identify the repository Booster should manage, then adjust source-specific settings when needed.', 'ran-booster' ); ?></p>

<div class="ran-booster-package-settings ran-booster-package-settings--create">
	<div class="ran-booster-package-settings__main">
		<form
			id="ran-booster-package-create-form"
			action=""
			method="POST"
			data-ran-booster-package-mutation
			data-ran-booster-package-create="1"
			data-ran-booster-explicit-provider="<?php echo esc_attr( $explicitProvider ? '1' : '0' ); ?>"
			data-ran-booster-open-picker="<?php echo esc_attr( $openRepositoryPicker ? '1' : '0' ); ?>"
			data-ran-booster-package-mutation-available="<?php echo esc_attr( $packageMutationAvailable ? '1' : '0' ); ?>"
		>
			<?php wp_nonce_field( $packageView->getAction( 'install' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'install' ) ); ?>">
			<input type="hidden" name="ran_booster[provider_repository_id]" class="ran-booster-provider-repository-id-input" value="<?php echo esc_attr( $providerRepositoryId ); ?>">
			<input type="hidden" name="ran_booster[provider_repository_identity_source]" class="ran-booster-provider-repository-identity-source-input" value="<?php echo esc_attr( $providerRepositoryIdentitySource ); ?>">
			<input type="hidden" name="ran_booster[public_lookup_profile_id]" class="ran-booster-public-lookup-profile-input" value="<?php echo esc_attr( $publicLookupProfileId ); ?>">
			<?php $packageFieldLayout = 'grid'; ?>

			<?php $packageRepositoryDescription = __( 'Choose the repository and access Booster should use for this package.', 'ran-booster' ); ?>
			<?php require __DIR__ . '/repository-configuration.php'; ?>

			<?php require __DIR__ . '/source-settings.php'; ?>

			<section class="ran-booster-settings-section ran-booster-package-operation-settings" aria-labelledby="ran-booster-package-operation-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-operation-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package operation', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Choose when Booster may update this package.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body">
					<fieldset <?php disabled( ! $packageMutationAvailable ); ?>>
						<div class="ran-booster-settings-fields">
							<?php require __DIR__ . '/fields/deployment-policy.php'; ?>
						</div>
					</fieldset>
					<div data-ran-booster-branch-install-actions>
						<fieldset <?php disabled( ! $packageMutationAvailable ); ?>>
							<div class="ran-booster-settings-fields">
								<div class="ran-booster-settings-field ran-booster-settings-field--wide">
									<label>
										<input type="checkbox" name="ran_booster[dry-run]" <?php checked( isset( $_POST['ran_booster']['dry-run'] ) ); ?>>
										<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Link installed %s', 'ran-booster' ), $packageView->getType() ) ); ?>
									</label>
									<p class="description"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Let Booster manage an already installed %s instead of deploying it now.', 'ran-booster' ), $packageView->getType() ) ); ?></p>
									<p class="description"><?php esc_html_e( 'The installed folder name must match the repository package name.', 'ran-booster' ); ?></p>
								</div>
							</div>
						</fieldset>
						<div class="ran-booster-settings-actions" role="group" aria-label="<?php esc_attr_e( 'Installation actions', 'ran-booster' ); ?>">
							<button type="submit" class="button button-primary" <?php disabled( ! $packageMutationAvailable ); ?>><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install %s', 'ran-booster' ), $packageView->getType() ) ); ?></button>
							<button type="submit" class="button" name="ran_booster[install_another]" value="1" <?php disabled( ! $packageMutationAvailable ); ?>><?php esc_html_e( 'Install and add another', 'ran-booster' ); ?></button>
						</div>
					</div>
				</div>
			</section>
		</form>
	</div>
</div>

<?php // phpcs:enable WordPress.Security.NonceVerification.Missing ?>

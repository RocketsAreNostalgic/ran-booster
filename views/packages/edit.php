<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// The dispatcher verifies the action nonce before this template repopulates submitted values.
// phpcs:disable WordPress.Security.NonceVerification.Missing

$providerOptions                  = $packageProviderSettings['providers'];
$defaultProviderCode              = $packageProviderSettings['default_provider'];
$submittedPackage                 = isset( $_POST['ran_booster'] ) && is_array( $_POST['ran_booster'] )
	? $_POST['ran_booster']
	: array();
$submittedAction                  = isset( $submittedPackage['action'] ) && is_scalar( $submittedPackage['action'] )
	? sanitize_key( wp_unslash( (string) $submittedPackage['action'] ) )
	: '';
$selectedCredentialId             = isset( $_POST['ran_booster']['credential_id'] )
	? sanitize_text_field( (string) $_POST['ran_booster']['credential_id'] )
	: $package->getCredentialId();
$repositoryValue                  = isset( $submittedPackage['repository'] ) && is_scalar( $submittedPackage['repository'] )
	? sanitize_text_field( wp_unslash( (string) $submittedPackage['repository'] ) )
	: (string) $package->repository;
$branchValue                      = isset( $submittedPackage['branch'] ) && is_scalar( $submittedPackage['branch'] )
	? sanitize_text_field( wp_unslash( (string) $submittedPackage['branch'] ) )
	: (string) $package->getBranch();
$subdirectoryValue                = isset( $submittedPackage['subdirectory'] ) && is_scalar( $submittedPackage['subdirectory'] )
	? sanitize_text_field( wp_unslash( (string) $submittedPackage['subdirectory'] ) )
	: (string) $package->getSubdirectory();
$submittedDeploymentPolicy        = isset( $submittedPackage['deployment_policy'] ) && is_scalar( $submittedPackage['deployment_policy'] )
	? \RAN\Deployment\DeploymentPolicy::tryFrom( sanitize_key( wp_unslash( (string) $submittedPackage['deployment_policy'] ) ) )
	: null;
$deploymentPolicy                 = ( $submittedDeploymentPolicy ?? $package->getDeploymentPolicy() )->value;
$identifierValue                  = (string) $package->getIdentifier();
$storedProviderCode               = (string) ( $package->getProviderCode() ?? '' );
$providerCode                     = isset( $_POST['ran_booster']['provider'] )
	? sanitize_key( (string) $_POST['ran_booster']['provider'] )
	: $storedProviderCode;
$providerRepositoryId             = isset( $_POST['ran_booster']['provider_repository_id'] )
	? wp_strip_all_tags( wp_unslash( (string) $_POST['ran_booster']['provider_repository_id'] ), true )
	: (string) ( $package->getProviderRepositoryId() ?? '' );
$providerRepositoryIdentitySource = isset( $_POST['ran_booster']['provider_repository_identity_source'] )
	? sanitize_key( (string) $_POST['ran_booster']['provider_repository_identity_source'] )
	: 'stored';
$publicLookupProfileId            = isset( $_POST['ran_booster']['public_lookup_profile_id'] ) && is_string( $_POST['ran_booster']['public_lookup_profile_id'] )
	? sanitize_text_field( $_POST['ran_booster']['public_lookup_profile_id'] )
	: '';
if ( '' !== $publicLookupProfileId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $publicLookupProfileId ) ) {
	$publicLookupProfileId = '';
}

if ( ! in_array( $providerRepositoryIdentitySource, array( 'stored', 'picker', 'manual' ), true ) ) {
	$providerRepositoryIdentitySource = '';
}

$selectedProviderOption = null;
foreach ( $providerOptions as $providerOption ) {
	if ( $providerOption['code'] === $providerCode ) {
		$selectedProviderOption = $providerOption;
		break;
	}
}
if ( null === $selectedProviderOption ) {
	$providerCode = $storedProviderCode;
	foreach ( $providerOptions as $providerOption ) {
		if ( $providerOption['code'] === $providerCode ) {
			$selectedProviderOption = $providerOption;
			break;
		}
	}
}

if ( null === $selectedProviderOption ) {
	$selectedProviderOption = array(
		'code'                  => $storedProviderCode,
		'label'                 => $storedProviderCode,
		'owner_label'           => '',
		'repository_url_base'   => '',
		'available'             => false,
		'browse'                => false,
		'deploy'                => false,
		'webhooks'              => false,
		'default_credential_id' => '',
		'credential_profiles'   => array(),
	);
}

$providerUnavailable      = false === $selectedProviderOption['available'];
$releaseManaged           = \RAN\PackageSource::RELEASE_ASSET === $package->getSource();
$packageMutationAvailable = ! $providerUnavailable && true === $selectedProviderOption['deploy'];
$packageExtensionPanels   = isset( $packageExtensionPanels ) && is_array( $packageExtensionPanels )
	? $packageExtensionPanels
	: array();
$packageBranchReadiness   = isset( $packageBranchReadiness ) && is_array( $packageBranchReadiness )
	? $packageBranchReadiness
	: null;
$packageWebhookCleanup    = isset( $packageWebhookCleanup ) && is_array( $packageWebhookCleanup )
	? $packageWebhookCleanup
	: null;
$packageSourceChoices     = is_array( $packageSource['choices'] ?? null ) ? $packageSource['choices'] : array();
$packageAdvancedSections  = is_array( $packageSource['advanced_sections'] ?? null ) ? $packageSource['advanced_sections'] : array();
$packageAdvancedSummary   = is_string( $packageSource['advanced_summary'] ?? null )
	? $packageSource['advanced_summary']
	: __( 'Branch · provider default', 'ran-booster' );
$packageSourceView        = is_string( $packageSource['selected'] ?? null ) ? $packageSource['selected'] : $package->getSource()->value;
$packageCurrentSource     = is_string( $packageSource['current'] ?? null ) ? $packageSource['current'] : $package->getSource()->value;
$packageSourceUnavailable = array_key_exists( 'unavailable', $packageSource ?? array() )
	? true === $packageSource['unavailable']
	: \RAN\PackageSource::BRANCH !== $package->getSource();
$packageSourceMode        = 'edit';
$packageRepositoryReady   = true;
$packageAdvancedOpen      = isset( $_POST['ran_booster'] )
	|| true === ( $packageSource['advanced_open'] ?? false )
	|| $packageSourceView !== $packageCurrentSource;
$packageDangerOpen        = in_array(
	$submittedAction,
	array( $packageView->getAction( 'unlink' ), $packageView->getAction( 'unlink-delete' ) ),
	true
);

if ( $providerUnavailable ) {
	$selectedCredentialId  = $package->getCredentialId();
	$repositoryValue       = (string) $package->repository;
	$branchValue           = (string) $package->getBranch();
	$subdirectoryValue     = (string) $package->getSubdirectory();
	$deploymentPolicy      = $package->getDeploymentPolicy()->value;
	$providerRepositoryId  = (string) ( $package->getProviderRepositoryId() ?? '' );
	$publicLookupProfileId = '';

} elseif ( $releaseManaged ) {
	$repositoryValue   = (string) $package->repository;
	$branchValue       = (string) $package->getBranch();
	$subdirectoryValue = (string) $package->getSubdirectory();
}

if ( '' === $selectedCredentialId && $package->isPrivate() && ! $providerUnavailable ) {
	$selectedCredentialId = $selectedProviderOption['default_credential_id'];
}
$providerBrowseAvailable  = $selectedProviderOption['browse'];
$providerWebhookAvailable = $selectedProviderOption['webhooks'];
$storedRepositoryUrlBase  = '';
foreach ( $providerOptions as $providerOption ) {
	if ( $providerOption['code'] === $storedProviderCode ) {
		$storedRepositoryUrlBase = (string) $providerOption['repository_url_base'];
		break;
	}
}
$repositoryUrl            = $storedRepositoryUrlBase . ltrim( (string) $package->repository, '/' );
$adminUrl                 = $packageView->getAdminUrl();
$installAnotherUrl        = add_query_arg(
	array(
		'page'        => $packageView->getCreatePageSlug(),
		'provider'    => $storedProviderCode,
		'open_picker' => '1',
	),
	$adminUrl
);
$settingsUrl              = add_query_arg(
	array(
		'page'    => $packageView->getPageSlug(),
		'package' => $identifierValue,
	),
	$adminUrl
);
$backUrl                  = add_query_arg( 'page', $packageView->getPageSlug(), $adminUrl );
$showBranchSettings       = 'branch' === $packageSourceView;
$showBranchOperations     = $showBranchSettings && ! $releaseManaged;
$repositoryReadOnly       = $releaseManaged;
$branchReadOnly           = $releaseManaged;
$packageMutationAvailable = $packageMutationAvailable && ! $packageSourceUnavailable;
$wordPressEnabled         = 'plugin' === $packageView->getType()
	? ( function_exists( 'is_plugin_active' ) && is_plugin_active( $identifierValue ) )
	: ( function_exists( 'wp_get_theme' ) && wp_get_theme()->get_stylesheet() === $identifierValue );
$wordPressState           = $wordPressEnabled ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' );
$wordPressActionUrl       = null;
$wordPressActionLabel     = null;
if ( ! $wordPressEnabled && 'plugin' === $packageView->getType() && current_user_can( 'activate_plugins' ) ) {
	$wordPressActionUrl   = add_query_arg(
		array(
			'action'   => 'activate',
			'plugin'   => $identifierValue,
			'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $identifierValue ),
		),
		admin_url( 'plugins.php' )
	);
	$wordPressActionLabel = __( 'Activate plugin', 'ran-booster' );
} elseif ( ! $wordPressEnabled && 'theme' === $packageView->getType() && current_user_can( is_multisite() ? 'manage_network_themes' : 'switch_themes' ) ) {
	$wordPressActionUrl   = add_query_arg(
		array(
			'action'     => is_multisite() ? 'enable' : 'activate',
			'stylesheet' => $identifierValue,
			'_wpnonce'   => wp_create_nonce( ( is_multisite() ? 'enable-theme_' : 'switch-theme_' ) . $identifierValue ),
		),
		is_multisite() ? network_admin_url( 'themes.php' ) : admin_url( 'themes.php' )
	);
	$wordPressActionLabel = is_multisite() ? __( 'Enable theme', 'ran-booster' ) : __( 'Activate theme', 'ran-booster' );
}
$showPackageOperationActions = $showBranchOperations || ( is_string( $wordPressActionUrl ) && is_string( $wordPressActionLabel ) );
$packageSettingsSaveLabel    = 'plugin' === $packageView->getType()
	? __( 'Save plugin settings', 'ran-booster' )
	: __( 'Save theme settings', 'ran-booster' );
$sourceSummary               = 'branch' === $packageCurrentSource
	? sprintf(
		/* translators: %s is the saved repository branch. */
		__( 'Branch · %s', 'ran-booster' ),
		'' !== (string) $package->getBranch() ? (string) $package->getBranch() : __( 'provider default', 'ran-booster' )
	)
	: (string) ( $packageSourceChoices[ $packageCurrentSource ]['heading'] ?? __( 'Unavailable source', 'ran-booster' ) );
$sourceSummaryMeta = (string) ( $packageSourceChoices[ $packageCurrentSource ]['meta'] ?? __( 'The source provider is unavailable', 'ran-booster' ) );
$automationSummary = match ( $package->getDeploymentPolicy()->value ) {
	\RAN\Deployment\DeploymentPolicy::DISABLED->value => __( 'Disabled', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::AUTOMATIC->value => __( 'Automatic', 'ran-booster' ),
	default => __( 'Manual', 'ran-booster' ),
};

?>
<p class="ran-booster-package-settings__back"><a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $packageView->getPluralLabel() ) ); ?></a></p>
<h2 class="ran-booster-package-settings__heading"><?php echo esc_html( sprintf( /* translators: %s is a package name. */ __( 'Edit %s', 'ran-booster' ), $package->name ) ); ?></h2>

<?php if ( $providerUnavailable ) { ?>
	<div class="notice notice-error inline">
		<p><strong><?php esc_html_e( 'Provider unavailable.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'This package remains linked but cannot be edited or deployed until its provider is registered again.', 'ran-booster' ); ?></p>
		<p>
			<?php esc_html_e( 'Provider:', 'ran-booster' ); ?> <code><?php echo esc_html( $storedProviderCode ); ?></code><br>
			<?php esc_html_e( 'Repository:', 'ran-booster' ); ?> <code><?php echo esc_html( (string) $package->repository ); ?></code><br>
			<?php esc_html_e( 'Provider repository ID:', 'ran-booster' ); ?> <code><?php echo esc_html( (string) ( $package->getProviderRepositoryId() ?? '' ) ); ?></code>
		</p>
	</div>
<?php } ?>

<div class="ran-booster-package-settings">
	<div class="ran-booster-package-settings__main">
		<?php if ( $packageSourceUnavailable ) { ?>
			<section class="ran-booster-settings-section" aria-labelledby="ran-booster-package-source-unavailable-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-source-unavailable-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package source unavailable', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'The package remains linked, but its source controls require an add-on that is not currently available.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body">
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Booster will not reinterpret this package as a branch deployment. Restore the source add-on to manage updates or unlink the package.', 'ran-booster' ); ?></p>
					</div>
					<div class="ran-booster-settings-actions" role="group" aria-label="<?php esc_attr_e( 'Package settings actions', 'ran-booster' ); ?>">
						<a class="button" href="<?php echo esc_url( $installAnotherUrl ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $packageView->getType() ) ); ?></a>
						<a class="button" href="<?php echo esc_url( $backUrl ); ?>"><?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $packageView->getPluralLabel() ) ); ?></a>
					</div>
				</div>
			</section>
		<?php } else { ?>
			<form id="ran-booster-package-edit-form" action="" method="POST" data-ran-booster-package-mutation>
						<?php wp_nonce_field( $packageView->getAction( 'edit' ) ); ?>
						<?php if ( $showBranchOperations ) { ?>
							<input type="hidden" name="_ran_booster_reinstall_nonce" value="<?php echo esc_attr( wp_create_nonce( $packageView->getAction( 'update' ) ) ); ?>">
						<?php } ?>
						<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'edit' ) ); ?>">
						<input type="hidden" name="ran_booster[<?php echo esc_attr( $packageView->getIdentifierField() ); ?>]" value="<?php echo esc_attr( $identifierValue ); ?>">
						<?php require __DIR__ . '/expected-package.php'; ?>
						<input type="hidden" name="ran_booster[provider_repository_id]" class="ran-booster-provider-repository-id-input" value="<?php echo esc_attr( $providerRepositoryId ); ?>">
						<input type="hidden" name="ran_booster[provider_repository_identity_source]" class="ran-booster-provider-repository-identity-source-input" value="<?php echo esc_attr( $providerRepositoryIdentitySource ); ?>">
						<input type="hidden" name="ran_booster[public_lookup_profile_id]" class="ran-booster-public-lookup-profile-input" value="<?php echo esc_attr( $publicLookupProfileId ); ?>">
						<?php if ( $releaseManaged ) { ?>
							<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $providerCode ); ?>">
							<input type="hidden" name="ran_booster[repository]" value="<?php echo esc_attr( $repositoryValue ); ?>">
							<input type="hidden" name="ran_booster[branch]" value="<?php echo esc_attr( $branchValue ); ?>">
							<input type="hidden" name="ran_booster[subdirectory]" value="<?php echo esc_attr( $subdirectoryValue ); ?>">
						<?php } elseif ( ! $showBranchSettings ) { ?>
							<input type="hidden" name="ran_booster[branch]" value="<?php echo esc_attr( $branchValue ); ?>">
							<input type="hidden" name="ran_booster[subdirectory]" value="<?php echo esc_attr( $subdirectoryValue ); ?>">
						<?php } ?>
						<?php $packageFieldLayout = 'grid'; ?>
						<?php $packageRepositoryDescription = __( 'The saved repository and access used by this package.', 'ran-booster' ); ?>
						<?php require __DIR__ . '/repository-configuration.php'; ?>
			</form>

			<?php require __DIR__ . '/source-settings.php'; ?>

			<section class="ran-booster-settings-section ran-booster-package-operation-settings" aria-labelledby="ran-booster-package-operation-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-operation-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package operation', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Choose when Booster may update this package.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body<?php echo $showPackageOperationActions ? ' ran-booster-package-operation-settings__body--split' : ''; ?>">
					<div class="ran-booster-settings-fields">
						<?php $packageFieldForm = 'ran-booster-package-edit-form'; ?>
						<?php $packageAutomationSource = $packageCurrentSource; ?>
						<?php require __DIR__ . '/fields/deployment-policy.php'; ?>
						<?php unset( $packageFieldForm ); ?>
					</div>
					<?php if ( $showPackageOperationActions ) { ?>
					<div class="ran-booster-package-operation-settings__actions" role="group" aria-label="<?php esc_attr_e( 'Package operations', 'ran-booster' ); ?>">
						<?php if ( is_string( $wordPressActionUrl ) && is_string( $wordPressActionLabel ) ) { ?>
							<a class="button" href="<?php echo esc_url( $wordPressActionUrl ); ?>"><?php echo esc_html( $wordPressActionLabel ); ?></a>
						<?php } ?>
						<?php if ( $showBranchOperations ) { ?>
							<?php require __DIR__ . '/reinstall.php'; ?>
						<?php } ?>
					</div>
					<?php } ?>
				</div>
			</section>

			<div class="ran-booster-settings-actions ran-booster-package-settings__save-actions" role="group" aria-label="<?php esc_attr_e( 'Package settings actions', 'ran-booster' ); ?>">
				<button type="submit" class="button button-primary" form="ran-booster-package-edit-form" data-ran-booster-package-settings-save data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error" data-ran-booster-package-mutation hx-post="<?php echo esc_url( $settingsUrl ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-sync="this:drop" hx-include="#ran-booster-package-edit-form" <?php disabled( ! $packageMutationAvailable ); ?>><?php echo esc_html( $packageSettingsSaveLabel ); ?></button>
				<a class="button" href="<?php echo esc_url( $installAnotherUrl ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $packageView->getType() ) ); ?></a>
				<a class="button" href="<?php echo esc_url( $backUrl ); ?>"><?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $packageView->getPluralLabel() ) ); ?></a>
			</div>
		<?php } ?>

		<?php if ( $releaseManaged && null !== $packageWebhookCleanup ) { ?>
			<?php require __DIR__ . '/webhook-cleanup.php'; ?>
		<?php } ?>

		<?php foreach ( $packageExtensionPanels as $packageExtensionPanel ) { ?>
			<?php if ( is_string( $packageExtensionPanel ) && '' !== $packageExtensionPanel ) { ?>
				<?php echo $packageExtensionPanel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted registered add-on renderer; Core owns the containing page. ?>
			<?php } ?>
		<?php } ?>

		<?php require __DIR__ . '/danger-zone.php'; ?>
	</div>

	<aside class="ran-booster-package-summary" aria-label="<?php esc_attr_e( 'Package summary', 'ran-booster' ); ?>">
			<div>
				<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'Current source', 'ran-booster' ); ?></p>
				<p class="ran-booster-package-summary__value"><?php echo esc_html( $sourceSummary ); ?></p>
				<p class="ran-booster-package-summary__meta">
					<?php if ( $providerUnavailable ) { ?>
						<code><?php echo esc_html( (string) $package->repository ); ?></code>
					<?php } else { ?>
						<a href="<?php echo esc_url( $repositoryUrl ); ?>" class="ran-booster-repository-link" data-repository-base="<?php echo esc_attr( $storedRepositoryUrlBase ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $package->repository ); ?></a>
					<?php } ?>
				</p>
				<p class="ran-booster-package-summary__meta"><?php echo esc_html( $sourceSummaryMeta ); ?></p>
		</div>
		<div>
			<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></p>
			<p class="ran-booster-package-summary__value"><?php echo esc_html( $automationSummary ); ?></p>
			<p class="ran-booster-package-summary__meta"><?php esc_html_e( 'Source changes reset Automatic to Manual', 'ran-booster' ); ?></p>
		</div>
		<div>
			<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'WordPress state', 'ran-booster' ); ?></p>
			<p class="ran-booster-package-summary__value"><?php echo esc_html( $wordPressState ); ?></p>
			<p class="ran-booster-package-summary__meta"><?php esc_html_e( 'Independent of package source', 'ran-booster' ); ?></p>
		</div>
	</aside>
</div>
<?php // phpcs:enable WordPress.Security.NonceVerification.Missing ?>

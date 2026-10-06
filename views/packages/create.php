<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * PackagePagePresenter projection passed through Dashboard::render().
 *
 * @var bool $explicit_provider
 * @var bool $open_repository_picker
 * @var array{default_provider: string, providers: list<array<string, mixed>>} $package_provider_settings
 * @var \RAN\Admin\PackagePagePresenter $package_view
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// The dispatcher verifies the action nonce before this template repopulates submitted values.

$provider_options      = $package_provider_settings['providers'];
$default_provider_code = $package_provider_settings['default_provider'];
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$selected_credential_id = isset( $_POST['ran_booster']['credential_id'] )
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
	? sanitize_text_field( (string) $_POST['ran_booster']['credential_id'] )
	: '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$repository_value = isset( $_POST['ran_booster']['repository'] ) ? (string) $_POST['ran_booster']['repository'] : '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$branch_value = isset( $_POST['ran_booster']['branch'] ) ? (string) $_POST['ran_booster']['branch'] : '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$subdirectory_value = isset( $_POST['ran_booster']['subdirectory'] ) ? (string) $_POST['ran_booster']['subdirectory'] : '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$public_lookup_profile_id = isset( $_POST['ran_booster']['public_lookup_profile_id'] ) && is_string( $_POST['ran_booster']['public_lookup_profile_id'] )
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
	? sanitize_text_field( $_POST['ran_booster']['public_lookup_profile_id'] )
	: '';
if ( '' !== $public_lookup_profile_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $public_lookup_profile_id ) ) {
	$public_lookup_profile_id = '';
}

// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$deployment_policy = isset( $_POST['ran_booster']['deployment_policy'] )
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
	? sanitize_key( (string) $_POST['ran_booster']['deployment_policy'] )
	: \RAN\Deployment\DeploymentPolicy::MANUAL->value;
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$provider_code = isset( $_POST['ran_booster']['provider'] ) ? sanitize_key( (string) $_POST['ran_booster']['provider'] ) : $default_provider_code;
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$provider_repository_id = isset( $_POST['ran_booster']['provider_repository_id'] ) ? wp_strip_all_tags( wp_unslash( (string) $_POST['ran_booster']['provider_repository_id'] ), true ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$provider_repository_identity_source = isset( $_POST['ran_booster']['provider_repository_identity_source'] )
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
	? sanitize_key( (string) $_POST['ran_booster']['provider_repository_identity_source'] )
	: 'manual';

if ( ! in_array( $provider_repository_identity_source, array( 'picker', 'manual' ), true ) ) {
	$provider_repository_identity_source = 'manual';
}

$selected_provider_option = null;
foreach ( $provider_options as $provider_option ) {
	if ( $provider_option['code'] === $provider_code ) {
		$selected_provider_option = $provider_option;
		break;
	}
}
if ( null === $selected_provider_option ) {
	$selected_provider_option = $provider_options[0];
	$provider_code            = $selected_provider_option['code'];
}
$provider_browse_available  = $selected_provider_option['browse'];
$provider_webhook_available = $selected_provider_option['webhooks'];
$package_mutation_available = isset( $package_mutation_available ) ? true === $package_mutation_available : true;
$release_managed            = false;
$repository_read_only       = false;
$branch_read_only           = false;
$package_source_choices     = isset( $package_source ) && is_array( $package_source['choices'] ?? null ) ? $package_source['choices'] : array();
$package_advanced_sections  = isset( $package_source ) && is_array( $package_source['advanced_sections'] ?? null ) ? $package_source['advanced_sections'] : array();
$package_advanced_summary   = isset( $package_source ) && is_string( $package_source['advanced_summary'] ?? null )
	? $package_source['advanced_summary']
	: __( 'Branch · provider default', 'ran-booster' );
$package_source_view        = 'branch';
$package_source_mode        = 'create';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification.
$package_advanced_open      = isset( $_POST['ran_booster'] ) && is_array( $_POST['ran_booster'] );
$package_repository_ready   = '' !== trim( $repository_value )
	&& strlen( $repository_value ) <= 512
	&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repository_value );
$admin_url                  = $package_view->get_admin_url();
$back_url                   = add_query_arg( 'page', $package_view->get_page_slug(), $admin_url );
$managed_package_identifier = isset( $managed_package_identifier ) && is_string( $managed_package_identifier )
	? trim( $managed_package_identifier )
	: '';
$managed_package_url        = '' === $managed_package_identifier
	? ''
	: add_query_arg(
		array(
			'page'    => $package_view->get_page_slug(),
			'package' => $managed_package_identifier,
		),
		$admin_url
	);

?>
<p class="ran-booster-package-settings__back"><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></a></p>
<h2 id="ran-booster-package-create-heading" class="ran-booster-package-settings__heading"><?php echo esc_html( sprintf( /* translators: %s is Plugin or Theme. */ __( 'Install New %s', 'ran-booster' ), $package_view->get_singular_label() ) ); ?></h2>
<p class="ran-booster-package-settings__intro"><?php esc_html_e( 'Identify the repository Booster should manage, then adjust source-specific settings when needed.', 'ran-booster' ); ?></p>

<div class="ran-booster-package-settings ran-booster-package-settings--create">
	<div class="ran-booster-package-settings__main">
		<form
			id="ran-booster-package-create-form"
			action=""
			method="POST"
			data-ran-booster-package-mutation
			data-ran-booster-native-submit
			data-ran-booster-package-create="1"
			data-ran-booster-explicit-provider="<?php echo esc_attr( $explicit_provider ? '1' : '0' ); ?>"
			data-ran-booster-open-picker="<?php echo esc_attr( $open_repository_picker ? '1' : '0' ); ?>"
			data-ran-booster-package-mutation-available="<?php echo esc_attr( $package_mutation_available ? '1' : '0' ); ?>"
		>
			<?php wp_nonce_field( $package_view->get_action( 'install' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'install' ) ); ?>">
			<input type="hidden" name="ran_booster[provider_repository_id]" class="ran-booster-provider-repository-id-input" value="<?php echo esc_attr( $provider_repository_id ); ?>">
			<input type="hidden" name="ran_booster[provider_repository_identity_source]" class="ran-booster-provider-repository-identity-source-input" value="<?php echo esc_attr( $provider_repository_identity_source ); ?>">
			<input type="hidden" name="ran_booster[public_lookup_profile_id]" class="ran-booster-public-lookup-profile-input" value="<?php echo esc_attr( $public_lookup_profile_id ); ?>">
			<?php $package_field_layout = 'grid'; ?>

			<?php $package_repository_description = __( 'Choose the repository and access Booster should use for this package.', 'ran-booster' ); ?>
			<?php require __DIR__ . '/repository-configuration.php'; ?>

			<?php require __DIR__ . '/source-settings.php'; ?>

			<section class="ran-booster-settings-section ran-booster-package-operation-settings" aria-labelledby="ran-booster-package-operation-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-operation-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package operation', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Choose when Booster may update this package.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body">
					<fieldset <?php disabled( ! $package_mutation_available ); ?>>
						<div class="ran-booster-settings-fields">
							<?php require __DIR__ . '/fields/deployment-policy.php'; ?>
						</div>
					</fieldset>
					<div data-ran-booster-branch-install-actions>
						<fieldset <?php disabled( ! $package_mutation_available ); ?>>
							<div class="ran-booster-settings-fields">
								<div class="ran-booster-settings-field ran-booster-settings-field--wide">
									<label>
										<input type="checkbox" name="ran_booster[dry-run]" <?php /* phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only form repopulation; output is escaped in the field and the action handler owns nonce verification. */ checked( isset( $_POST['ran_booster']['dry-run'] ) ); ?>>
										<?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Link installed %s', 'ran-booster' ), $package_view->get_type() ) ); ?>
									</label>
									<p class="description"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Let Booster manage an already installed %s instead of deploying it now.', 'ran-booster' ), $package_view->get_type() ) ); ?></p>
									<p class="description"><?php esc_html_e( 'The installed folder name must match the repository package name.', 'ran-booster' ); ?></p>
								</div>
							</div>
						</fieldset>
						<div class="ran-booster-settings-actions" role="group" aria-label="<?php esc_attr_e( 'Installation actions', 'ran-booster' ); ?>">
							<?php if ( '' !== $managed_package_url ) { ?>
								<a class="button button-primary" href="<?php echo esc_url( $managed_package_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Manage %s', 'ran-booster' ), $package_view->get_type() ) ); ?></a>
								<button type="submit" class="button" name="ran_booster[install_another]" value="1" <?php disabled( ! $package_mutation_available ); ?>><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $package_view->get_type() ) ); ?></button>
							<?php } else { ?>
								<button type="submit" class="button button-primary" <?php disabled( ! $package_mutation_available ); ?>><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install %s', 'ran-booster' ), $package_view->get_type() ) ); ?></button>
								<button type="submit" class="button" name="ran_booster[install_another]" value="1" <?php disabled( ! $package_mutation_available ); ?>><?php esc_html_e( 'Install and add another', 'ran-booster' ); ?></button>
							<?php } ?>
						</div>
					</div>
				</div>
			</section>
		</form>
	</div>
</div>

<?php // End of package template. ?>

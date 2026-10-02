<?php

/**
 * PackagePagePresenter projection passed through Dashboard::render().
 *
 * @var \RAN\Plugin|\RAN\Theme $package
 * @var array{default_provider: string, providers: list<array<string, mixed>>} $package_provider_settings
 * @var array<string, mixed> $package_source
 * @var \RAN\Admin\PackagePagePresenter $package_view
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// The dispatcher verifies the action nonce before this template repopulates submitted values.
// phpcs:disable WordPress.Security.NonceVerification.Missing

$provider_options                  = $package_provider_settings['providers'];
$default_provider_code              = $package_provider_settings['default_provider'];
$submitted_package                 = isset( $_POST['ran_booster'] ) && is_array( $_POST['ran_booster'] )
	? $_POST['ran_booster']
	: array();
$submitted_action                  = isset( $submitted_package['action'] ) && is_scalar( $submitted_package['action'] )
	? sanitize_key( wp_unslash( (string) $submitted_package['action'] ) )
	: '';
$selected_credential_id             = isset( $_POST['ran_booster']['credential_id'] )
	? sanitize_text_field( (string) $_POST['ran_booster']['credential_id'] )
	: $package->get_credential_id();
$repository_value                  = isset( $submitted_package['repository'] ) && is_scalar( $submitted_package['repository'] )
	? sanitize_text_field( wp_unslash( (string) $submitted_package['repository'] ) )
	: (string) $package->repository;
$branch_value                      = isset( $submitted_package['branch'] ) && is_scalar( $submitted_package['branch'] )
	? sanitize_text_field( wp_unslash( (string) $submitted_package['branch'] ) )
	: (string) $package->get_branch();
$subdirectory_value                = isset( $submitted_package['subdirectory'] ) && is_scalar( $submitted_package['subdirectory'] )
	? sanitize_text_field( wp_unslash( (string) $submitted_package['subdirectory'] ) )
	: (string) $package->get_subdirectory();
$saved_subdirectory_value           = (string) $package->get_subdirectory();
$submitted_deployment_policy        = isset( $submitted_package['deployment_policy'] ) && is_scalar( $submitted_package['deployment_policy'] )
	? \RAN\Deployment\DeploymentPolicy::tryFrom( sanitize_key( wp_unslash( (string) $submitted_package['deployment_policy'] ) ) )
	: null;
$deployment_policy                 = ( $submitted_deployment_policy ?? $package->get_deployment_policy() )->value;
$identifier_value                  = (string) $package->get_identifier();
$stored_provider_code               = (string) ( $package->get_provider_code() ?? '' );
$provider_code                     = isset( $_POST['ran_booster']['provider'] )
	? sanitize_key( (string) $_POST['ran_booster']['provider'] )
	: $stored_provider_code;
$provider_repository_id             = isset( $_POST['ran_booster']['provider_repository_id'] )
	? wp_strip_all_tags( wp_unslash( (string) $_POST['ran_booster']['provider_repository_id'] ), true )
	: (string) ( $package->get_provider_repository_id() ?? '' );
$provider_repository_identity_source = isset( $_POST['ran_booster']['provider_repository_identity_source'] )
	? sanitize_key( (string) $_POST['ran_booster']['provider_repository_identity_source'] )
	: 'stored';
$public_lookup_profile_id            = isset( $_POST['ran_booster']['public_lookup_profile_id'] ) && is_string( $_POST['ran_booster']['public_lookup_profile_id'] )
	? sanitize_text_field( $_POST['ran_booster']['public_lookup_profile_id'] )
	: '';
if ( '' !== $public_lookup_profile_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $public_lookup_profile_id ) ) {
	$public_lookup_profile_id = '';
}

if ( ! in_array( $provider_repository_identity_source, array( 'stored', 'picker', 'manual' ), true ) ) {
	$provider_repository_identity_source = '';
}

$selected_provider_option = null;
foreach ( $provider_options as $provider_option ) {
	if ( $provider_option['code'] === $provider_code ) {
		$selected_provider_option = $provider_option;
		break;
	}
}
if ( null === $selected_provider_option ) {
	$provider_code = $stored_provider_code;
	foreach ( $provider_options as $provider_option ) {
		if ( $provider_option['code'] === $provider_code ) {
			$selected_provider_option = $provider_option;
			break;
		}
	}
}

if ( null === $selected_provider_option ) {
	$selected_provider_option = array(
		'code'                  => $stored_provider_code,
		'label'                 => $stored_provider_code,
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

$provider_unavailable      = false === $selected_provider_option['available'];
$release_managed           = \RAN\PackageSource::RELEASE_ASSET === $package->get_source();
$package_mutation_available = ! $provider_unavailable && true === $selected_provider_option['deploy'];
$package_extension_panels   = isset( $package_extension_panels ) && is_array( $package_extension_panels )
	? $package_extension_panels
	: array();
$package_branch_readiness   = isset( $package_branch_readiness ) && is_array( $package_branch_readiness )
	? $package_branch_readiness
	: null;

$repository_branch_check_outcome = isset( $repository_branch_check_outcome ) && is_string( $repository_branch_check_outcome )
	&& in_array( $repository_branch_check_outcome, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified', 'unable_to_check', 'provider_unavailable' ), true )
	? $repository_branch_check_outcome
	: null;
$package_source_choices         = is_array( $package_source['choices'] ?? null ) ? $package_source['choices'] : array();
$package_advanced_sections      = is_array( $package_source['advanced_sections'] ?? null ) ? $package_source['advanced_sections'] : array();
$package_advanced_summary       = is_string( $package_source['advanced_summary'] ?? null )
	? $package_source['advanced_summary']
	: __( 'Branch · provider default', 'ran-booster' );
$package_source_view            = is_string( $package_source['selected'] ?? null ) ? $package_source['selected'] : $package->get_source()->value;
$package_current_source         = is_string( $package_source['current'] ?? null ) ? $package_source['current'] : $package->get_source()->value;
$package_source_unavailable     = array_key_exists( 'unavailable', $package_source ?? array() )
	? true === $package_source['unavailable']
	: \RAN\PackageSource::BRANCH !== $package->get_source();
$package_source_mode            = 'edit';
$package_repository_ready       = true;
$package_advanced_open          = isset( $_POST['ran_booster'] )
	|| true === ( $package_source['advanced_open'] ?? false );
$package_danger_open            = in_array(
	$submitted_action,
	array( $package_view->get_action( 'unlink' ), $package_view->get_action( 'unlink-delete' ) ),
	true
);

if ( $provider_unavailable ) {
	$selected_credential_id  = $package->get_credential_id();
	$repository_value       = (string) $package->repository;
	$branch_value           = (string) $package->get_branch();
	$subdirectory_value     = (string) $package->get_subdirectory();
	$deployment_policy      = $package->get_deployment_policy()->value;
	$provider_repository_id  = (string) ( $package->get_provider_repository_id() ?? '' );
	$public_lookup_profile_id = '';

} elseif ( $release_managed ) {
	$repository_value   = (string) $package->repository;
	$branch_value       = (string) $package->get_branch();
	$subdirectory_value = (string) $package->get_subdirectory();
}

if ( '' === $selected_credential_id && $package->is_private() && ! $provider_unavailable ) {
	$selected_credential_id = $selected_provider_option['default_credential_id'];
}
$provider_browse_available  = $selected_provider_option['browse'];
$provider_webhook_available = $selected_provider_option['webhooks'];
$stored_repository_url_base  = '';
foreach ( $provider_options as $provider_option ) {
	if ( $provider_option['code'] === $stored_provider_code ) {
		$stored_repository_url_base = (string) $provider_option['repository_url_base'];
		break;
	}
}
$repository_url            = $stored_repository_url_base . ltrim( (string) $package->repository, '/' );
$admin_url                 = $package_view->get_admin_url();
$install_another_url        = add_query_arg(
	array(
		'page'        => $package_view->get_create_page_slug(),
		'provider'    => $stored_provider_code,
		'open_picker' => '1',
	),
	$admin_url
);
$settings_url              = add_query_arg(
	array(
		'page'    => $package_view->get_page_slug(),
		'package' => $identifier_value,
	),
	$admin_url
);
$back_url                  = add_query_arg( 'page', $package_view->get_page_slug(), $admin_url );
$show_branch_settings       = 'branch' === $package_source_view;
$show_branch_operations     = $show_branch_settings && ! $release_managed;
$repository_read_only       = $release_managed;
$branch_read_only           = $release_managed;
$package_mutation_available = $package_mutation_available && ! $package_source_unavailable;
$word_press_enabled         = 'plugin' === $package_view->get_type()
	? ( function_exists( 'is_plugin_active' ) && is_plugin_active( $identifier_value ) )
	: ( function_exists( 'wp_get_theme' ) && wp_get_theme()->get_stylesheet() === $identifier_value );
$word_press_state           = $word_press_enabled ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' );
$word_press_action_url       = null;
$word_press_action_label     = null;
if ( ! $word_press_enabled && 'plugin' === $package_view->get_type() && current_user_can( 'activate_plugins' ) ) {
	$word_press_action_url   = add_query_arg(
		array(
			'action'   => 'activate',
			'plugin'   => $identifier_value,
			'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $identifier_value ),
		),
		admin_url( 'plugins.php' )
	);
	$word_press_action_label = __( 'Activate plugin', 'ran-booster' );
} elseif ( ! $word_press_enabled && 'theme' === $package_view->get_type() && current_user_can( is_multisite() ? 'manage_network_themes' : 'switch_themes' ) ) {
	$word_press_action_url   = add_query_arg(
		array(
			'action'     => is_multisite() ? 'enable' : 'activate',
			'stylesheet' => $identifier_value,
			'_wpnonce'   => wp_create_nonce( ( is_multisite() ? 'enable-theme_' : 'switch-theme_' ) . $identifier_value ),
		),
		is_multisite() ? network_admin_url( 'themes.php' ) : admin_url( 'themes.php' )
	);
	$word_press_action_label = is_multisite() ? __( 'Enable theme', 'ran-booster' ) : __( 'Activate theme', 'ran-booster' );
}
$show_package_operation_actions = $show_branch_operations || ( is_string( $word_press_action_url ) && is_string( $word_press_action_label ) );
$package_settings_save_label    = 'plugin' === $package_view->get_type()
	? __( 'Save plugin settings', 'ran-booster' )
	: __( 'Save theme settings', 'ran-booster' );
$source_summary               = 'branch' === $package_current_source
	? sprintf(
		/* translators: %s is the repository branch name. */
		__( 'Branch · %s', 'ran-booster' ),
		'' !== (string) $package->get_branch() ? (string) $package->get_branch() : __( 'provider default', 'ran-booster' )
	)
	: (string) ( $package_source_choices[ $package_current_source ]['heading'] ?? __( 'Unavailable update source', 'ran-booster' ) );
$source_summary_meta = (string) ( $package_source_choices[ $package_current_source ]['meta'] ?? __( 'The update source provider is unavailable', 'ran-booster' ) );
$automation_summary = match ( $package->get_deployment_policy()->value ) {
	\RAN\Deployment\DeploymentPolicy::DISABLED->value => __( 'Disabled', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::AUTOMATIC->value => __( 'Automatic', 'ran-booster' ),
	default => __( 'Manual', 'ran-booster' ),
};

?>
<p class="ran-booster-package-settings__back"><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></a></p>
<h2 class="ran-booster-package-settings__heading"><?php echo esc_html( sprintf( /* translators: %s is the item being edited. */ __( 'Edit %s', 'ran-booster' ), $package->name ) ); ?></h2>

<?php if ( $provider_unavailable ) { ?>
	<div class="notice notice-error inline">
		<p><strong><?php esc_html_e( 'Provider unavailable.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'This package remains linked but cannot be edited or deployed until its provider is registered again.', 'ran-booster' ); ?></p>
		<p>
			<?php esc_html_e( 'Provider:', 'ran-booster' ); ?> <code><?php echo esc_html( $stored_provider_code ); ?></code><br>
			<?php esc_html_e( 'Repository:', 'ran-booster' ); ?> <code><?php echo esc_html( (string) $package->repository ); ?></code><br>
			<?php esc_html_e( 'Provider repository ID:', 'ran-booster' ); ?> <code><?php echo esc_html( (string) ( $package->get_provider_repository_id() ?? '' ) ); ?></code>
		</p>
	</div>
<?php } ?>

<div class="ran-booster-package-settings">
	<div class="ran-booster-package-settings__main">
		<?php if ( $package_source_unavailable ) { ?>
			<section class="ran-booster-settings-section" aria-labelledby="ran-booster-package-source-unavailable-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-source-unavailable-heading" class="ran-booster-section__title"><?php esc_html_e( 'Update source unavailable', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'The package remains linked, but its update source controls require an add-on that is not currently available.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body">
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Booster will not reinterpret this package as a branch deployment. Restore the update source add-on to manage updates or unlink the package.', 'ran-booster' ); ?></p>
					</div>
					<div class="ran-booster-settings-actions" role="group" aria-label="<?php esc_attr_e( 'Package settings actions', 'ran-booster' ); ?>">
						<a class="button" href="<?php echo esc_url( $install_another_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $package_view->get_type() ) ); ?></a>
						<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></a>
					</div>
				</div>
			</section>
		<?php } else { ?>
			<form id="ran-booster-package-edit-form" action="" method="POST" data-ran-booster-package-mutation>
						<?php wp_nonce_field( $package_view->get_action( 'edit' ) ); ?>
						<?php if ( $show_branch_operations ) { ?>
							<input type="hidden" name="_ran_booster_reinstall_nonce" value="<?php echo esc_attr( wp_create_nonce( $package_view->get_action( 'update' ) ) ); ?>">
						<?php } ?>
						<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'edit' ) ); ?>">
						<input type="hidden" name="ran_booster[<?php echo esc_attr( $package_view->get_identifier_field() ); ?>]" value="<?php echo esc_attr( $identifier_value ); ?>">
						<?php require __DIR__ . '/expected-package.php'; ?>
						<input type="hidden" name="ran_booster[provider_repository_id]" class="ran-booster-provider-repository-id-input" value="<?php echo esc_attr( $provider_repository_id ); ?>">
						<input type="hidden" name="ran_booster[provider_repository_identity_source]" class="ran-booster-provider-repository-identity-source-input" value="<?php echo esc_attr( $provider_repository_identity_source ); ?>">
						<input type="hidden" name="ran_booster[public_lookup_profile_id]" class="ran-booster-public-lookup-profile-input" value="<?php echo esc_attr( $public_lookup_profile_id ); ?>">
						<?php if ( $release_managed ) { ?>
							<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider_code ); ?>">
							<input type="hidden" name="ran_booster[repository]" value="<?php echo esc_attr( $repository_value ); ?>">
							<input type="hidden" name="ran_booster[branch]" value="<?php echo esc_attr( $branch_value ); ?>">
							<input type="hidden" name="ran_booster[subdirectory]" value="<?php echo esc_attr( $subdirectory_value ); ?>">
						<?php } elseif ( ! $show_branch_settings ) { ?>
							<input type="hidden" name="ran_booster[branch]" value="<?php echo esc_attr( $branch_value ); ?>">
							<input type="hidden" name="ran_booster[subdirectory]" value="<?php echo esc_attr( $subdirectory_value ); ?>">
						<?php } ?>
						<?php $package_field_layout = 'grid'; ?>
						<?php $package_repository_description = __( 'The saved repository and access used by this package.', 'ran-booster' ); ?>
						<?php require __DIR__ . '/repository-configuration.php'; ?>
			</form>

			<?php require __DIR__ . '/source-settings.php'; ?>

			<section class="ran-booster-settings-section ran-booster-package-operation-settings" aria-labelledby="ran-booster-package-operation-heading">
				<header class="ran-booster-settings-section__header">
					<h3 id="ran-booster-package-operation-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package operation', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Choose when Booster may update this package.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-settings-section__body<?php echo $show_package_operation_actions ? ' ran-booster-package-operation-settings__body--split' : ''; ?>">
					<div class="ran-booster-settings-fields">
						<?php $package_field_form = 'ran-booster-package-edit-form'; ?>
						<?php $package_automation_source = $package_current_source; ?>
						<?php require __DIR__ . '/fields/deployment-policy.php'; ?>
						<?php unset( $package_field_form ); ?>
					</div>
					<?php if ( $show_package_operation_actions ) { ?>
					<div class="ran-booster-package-operation-settings__actions" role="group" aria-label="<?php esc_attr_e( 'Package operations', 'ran-booster' ); ?>">
						<?php if ( is_string( $word_press_action_url ) && is_string( $word_press_action_label ) ) { ?>
							<a class="button" href="<?php echo esc_url( $word_press_action_url ); ?>"><?php echo esc_html( $word_press_action_label ); ?></a>
						<?php } ?>
						<?php if ( $show_branch_operations ) { ?>
							<?php require __DIR__ . '/reinstall.php'; ?>
						<?php } ?>
					</div>
					<?php } ?>
				</div>
			</section>

			<div class="ran-booster-settings-actions ran-booster-package-settings__save-actions" role="group" aria-label="<?php esc_attr_e( 'Package settings actions', 'ran-booster' ); ?>">
				<button type="submit" class="button button-primary" form="ran-booster-package-edit-form" data-ran-booster-package-settings-save data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error" data-ran-booster-package-mutation hx-post="<?php echo esc_url( wp_make_link_relative( $settings_url ) ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-sync="this:drop" hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]" <?php disabled( ! $package_mutation_available ); ?>><?php echo esc_html( $package_settings_save_label ); ?></button>
				<a class="button" href="<?php echo esc_url( $install_another_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $package_view->get_type() ) ); ?></a>
				<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></a>
			</div>
		<?php } ?>

		<?php foreach ( $package_extension_panels as $package_extension_panel ) { ?>
			<?php if ( is_string( $package_extension_panel ) && '' !== $package_extension_panel ) { ?>
				<?php echo $package_extension_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted registered add-on renderer; Core owns the containing page. ?>
			<?php } ?>
		<?php } ?>

		<?php require __DIR__ . '/danger-zone.php'; ?>
	</div>

	<aside class="ran-booster-package-summary" aria-label="<?php esc_attr_e( 'Package summary', 'ran-booster' ); ?>">
			<div>
				<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'Current source', 'ran-booster' ); ?></p>
				<p class="ran-booster-package-summary__value"><?php echo esc_html( $source_summary ); ?></p>
				<p class="ran-booster-package-summary__meta">
					<?php if ( $provider_unavailable ) { ?>
						<code><?php echo esc_html( (string) $package->repository ); ?></code>
					<?php } else { ?>
						<a href="<?php echo esc_url( $repository_url ); ?>" class="ran-booster-repository-link" data-repository-base="<?php echo esc_attr( $stored_repository_url_base ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $package->repository ); ?></a>
					<?php } ?>
				</p>
				<p class="ran-booster-package-summary__meta"><?php echo esc_html( $source_summary_meta ); ?></p>
		</div>
		<div>
			<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></p>
			<p class="ran-booster-package-summary__value"><?php echo esc_html( $automation_summary ); ?></p>
			<p class="ran-booster-package-summary__meta"><?php esc_html_e( 'Source changes reset Automatic to Manual', 'ran-booster' ); ?></p>
		</div>
		<div>
			<p class="ran-booster-eyebrow ran-booster-eyebrow--compact"><?php esc_html_e( 'WordPress state', 'ran-booster' ); ?></p>
			<p class="ran-booster-package-summary__value"><?php echo esc_html( $word_press_state ); ?></p>
			<p class="ran-booster-package-summary__meta"><?php esc_html_e( 'Independent of package source', 'ran-booster' ); ?></p>
		</div>
	</aside>
</div>
<?php // phpcs:enable WordPress.Security.NonceVerification.Missing ?>

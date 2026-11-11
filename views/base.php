<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var string $view
 * @var string $tab_view
 * @var string $provider_view
 * @var array{label:string} $provider
 * @var string $overview_url
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$footer_plugin_headers     = get_file_data(
	dirname( __DIR__ ) . '/ran-booster.php',
	array(
		'author'     => 'Author',
		'author_uri' => 'Author URI',
		'version'    => 'Version',
	),
	'plugin'
);
$footer_plugin_author      = is_string( $footer_plugin_headers['author'] ?? null )
	? trim( $footer_plugin_headers['author'] )
	: '';
$footer_plugin_author_url  = is_string( $footer_plugin_headers['author_uri'] ?? null )
	? trim( $footer_plugin_headers['author_uri'] )
	: '';
$footer_plugin_version     = is_string( $footer_plugin_headers['version'] ?? null )
	? trim( $footer_plugin_headers['version'] )
	: '';
$footer_plugin_author_link = esc_url( $footer_plugin_author_url );
$admin_page_modifier       = match ( $view ) {
	'extensions'      => ' ran-booster-admin--extensions ran-booster-admin--wide-collection',
	'packages/index'  => ' ran-booster-admin--packages ran-booster-admin--wide-collection',
	'packages/create',
	'packages/edit'   => ' ran-booster-admin--packages',
	default           => '',
};
$ran_admin_shell_navigation = array();
$package_type               = isset( $package_view ) ? $package_view->get_type() : '';
$admin_url                  = is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$package_navigation         = array(
	array(
		'label'   => __( 'Plugins', 'ran-booster' ),
		'url'     => $admin_url . '?page=ran-booster-plugins',
		'current' => 'plugin' === $package_type,
	),
	array(
		'label'   => __( 'Themes', 'ran-booster' ),
		'url'     => $admin_url . '?page=ran-booster-themes',
		'current' => 'theme' === $package_type,
	),
);

if ( isset( $tabs ) && is_array( $tabs ) ) {
	foreach ( $tabs as $admin_tab ) {
		if ( 'troubleshooting' === ( $admin_tab['key'] ?? null ) ) {
			continue;
		}
		if ( 'portability' === ( $admin_tab['key'] ?? null ) ) {
			foreach ( $package_navigation as $package_tab ) {
				$ran_admin_shell_navigation[] = $package_tab;
			}
			continue;
		}

		$ran_admin_shell_navigation[] = array(
			'label'   => $admin_tab['label'] ?? '',
			'url'     => $admin_tab['url'] ?? '',
			'current' => ! empty( $admin_tab['active'] ),
		);
	}
}

$ran_admin_shell = array(
	'name'             => __( 'Booster', 'ran-booster' ),
	'color_scheme'     => 'inverse',
	'home_url'         => $admin_url . '?page=ran-booster',
	'strapline'        => __( 'Rockets Are Nostalgic', 'ran-booster' ),
	'logo'             => array(
		'url'    => plugins_url( 'assets/booster-nozzle-10364e-cream-transparent.svg', dirname( __DIR__ ) . '/ran-booster.php' ),
		'width'  => 32,
		'height' => 32,
	),
	'navigation_label' => __( 'RAN Booster sections', 'ran-booster' ),
	'navigation'       => $ran_admin_shell_navigation,
);

$ran_admin_shell['navigation_variant'] = 'thick';

if ( in_array( $view, array( 'packages/create', 'packages/edit', 'packages/recovery' ), true ) && isset( $package_view, $package_return_list ) ) {
	$parent_url                           = add_query_arg( array( 'page' => $package_view->get_page_slug() ) + array_map( 'rawurlencode', $package_return_list ), $admin_url );
	$ran_admin_shell['breadcrumbs_label'] = __( 'Breadcrumbs', 'ran-booster' );
	if ( 'packages/create' === $view ) {
		/* translators: %s is plugin or theme. */
		$package_heading = sprintf( __( 'Install %s', 'ran-booster' ), $package_view->get_singular_label() );
	} elseif ( 'packages/recovery' === $view ) {
		$package_heading = is_string( $recovery_name ?? null ) ? trim( $recovery_name ) : '';
	} else {
		$package_heading = isset( $package ) && $package instanceof \RAN\Package ? trim( $package->get_display_name() ) : '';
	}
	if ( '' === $package_heading ) {
		$package_heading = $package_view->get_singular_label();
	}
	$ran_admin_shell['breadcrumbs'] = array(
		array(
			'label' => $package_view->get_plural_label(),
			'url'   => $parent_url,
		),
		array( 'label' => $package_heading ),
	);
} elseif ( 'index' === $view && 'provider.php' === ( $tab_view ?? '' ) && in_array( $provider_view ?? '', array( 'credentials', 'secrets' ), true ) && isset( $provider, $overview_url ) ) {
	$ran_admin_shell['breadcrumbs_label'] = __( 'Breadcrumbs', 'ran-booster' );
	$ran_admin_shell['breadcrumbs']       = array(
		array(
			'label' => $provider['label'],
			'url'   => $overview_url,
		),
		array( 'label' => 'credentials' === $provider_view ? __( 'Manage Credentials', 'ran-booster' ) : __( 'Manage Webhook Secrets', 'ran-booster' ) ),
	);
} elseif ( 'index' === $view && 'provider.php' === ( $tab_view ?? '' ) && 'overview' === ( $provider_view ?? '' ) && '' !== ( $requested_repository_id ?? '' ) && isset( $provider, $overview_url, $repository_list_url ) ) {
	$repository_crumb                     = is_array( $selected_repository_row ?? null ) && is_string( $selected_repository_row['repository'] ?? null ) ? trim( $selected_repository_row['repository'] ) : '';
	$ran_admin_shell['breadcrumbs_label'] = __( 'Breadcrumbs', 'ran-booster' );
	$ran_admin_shell['breadcrumbs']       = array(
		array(
			'label' => $provider['label'],
			'url'   => $overview_url,
		),
		array(
			'label' => __( 'Repositories', 'ran-booster' ),
			'url'   => $repository_list_url,
		),
		array( 'label' => '' !== $repository_crumb ? $repository_crumb : __( 'Repository unavailable', 'ran-booster' ) ),
	);
}

echo '<div class="ran-admin-shell-notices"><hr class="wp-header-end"></div>';
require __DIR__ . '/generated/ran-admin-shell.php';

?><div class="wrap ran-booster-admin<?php echo esc_attr( $admin_page_modifier ); ?>" data-ran-booster-shell-navigation>
	<?php
	\RAN\Admin\BoosterNoticeScope::render_shell_notices();
	?>
	<div id="ran-booster-core-development-notice-slot">
		<?php
		if ( empty( $local_development_overview ) && isset( $core_self_update_development_notice ) ) {
			$core_self_update_development_notice->render_shell_inline();
		}
		?>
	</div>
	<?php if ( ! empty( $development_safety_notice ) ) { ?>
		<div class="notice notice-info inline is-dismissible ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-development-safety data-ran-booster-notice-state="<?php echo esc_attr( \RAN\Admin\DevelopmentSafetyNoticeController::NOTICE_STATE ); ?>" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p><strong><?php esc_html_e( 'Local development detected', 'ran-booster' ); ?></strong></p>
			<p><?php esc_html_e( 'Booster can still download and replace managed plugins and themes on this site. Disable updates for packages you’re editing. Incoming webhooks for branch deployment are generally unavailable on local or private domains.', 'ran-booster' ); ?></p>
			<?php
			if ( isset( $core_self_update_development_notice ) ) {
				$core_self_update_development_notice->render_local_fact(); }
			?>
			<p class="ran-booster-standalone-link-row"><a href="<?php echo esc_url( $admin_url . '?page=ran-booster&tab=documentation#ran-booster-file-protection' ); ?>"><?php esc_html_e( 'Learn about local development', 'ran-booster' ); ?></a></p>
		</div>
		<button type="button" class="notice-dismiss" hidden style="display:none"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'ran-booster' ); ?></span></button>
		<?php \RAN\Admin\Component\NoticeIcon::print_intent( 'info' ); ?>
		</div>
	<?php } ?>
	<?php if ( ! in_array( $view, array( 'packages/index', 'packages/recovery' ), true ) ) { ?>
		<?php require __DIR__ . '/notices.php'; ?>
	<?php } ?>

	<div id="ran-booster-package-mutation-error" class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'error' ); ?></div>

	<?php require __DIR__ . '/' . $view . '.php'; ?>

	<hr>

	<div class="ran-booster-footer">
		<p>
			<?php
			/* translators: %s: current year. */
			echo esc_html( sprintf( __( 'Copyright © %s', 'ran-booster' ), wp_date( 'Y' ) ) );
			?>
			<?php if ( '' !== $footer_plugin_author && '' !== $footer_plugin_author_link ) { ?>
				<a href="<?php echo esc_url( $footer_plugin_author_link ); ?>"><?php echo esc_html( $footer_plugin_author ); ?></a>
			<?php } else { ?>
				<?php echo esc_html( $footer_plugin_author ); ?>
			<?php } ?>
			<?php if ( '' !== $footer_plugin_version ) { ?>
				| <?php /* translators: %s: installed Booster version. */ ?>
				<?php echo esc_html( sprintf( __( 'Booster %s', 'ran-booster' ), $footer_plugin_version ) ); ?>
			<?php } ?>
		</p>
	</div>
</div>
<?php
// Screens whose scripts change a notice's intent in place clone its icon from here.
if ( in_array( $view, array( 'packages/index', 'packages/create', 'packages/edit' ), true )
	|| 'portability.php' === ( $tab_view ?? '' )
	|| ( 'provider.php' === ( $tab_view ?? '' ) && 'credentials' === ( $provider_view ?? '' ) )
) {
	\RAN\Admin\Component\NoticeIcon::print_template();
}

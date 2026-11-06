<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var string $view
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
	'extensions'      => ' ran-booster-admin--extensions',
	'packages/index',
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
	'name'             => __( 'RAN Booster', 'ran-booster' ),
	'home_url'         => $admin_url . '?page=ran-booster',
	'strapline'        => __( 'Deploy themes and plugins straight from your Git repos.', 'ran-booster' ),
	'logo'             => array(
		'url'    => plugins_url( 'assets/ran-booster-mark.svg', dirname( __DIR__ ) . '/ran-booster.php' ),
		'width'  => 56,
		'height' => 56,
	),
	'navigation_label' => __( 'RAN Booster sections', 'ran-booster' ),
	'navigation'       => $ran_admin_shell_navigation,
);

echo '<div class="ran-admin-shell-notices"><hr class="wp-header-end"></div>';
require __DIR__ . '/generated/ran-admin-shell.php';

?><div class="wrap ran-booster-admin<?php echo esc_attr( $admin_page_modifier ); ?>">
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
		<div class="notice notice-info inline is-dismissible" data-ran-booster-development-safety data-ran-booster-notice-state="<?php echo esc_attr( \RAN\Admin\DevelopmentSafetyNoticeController::NOTICE_STATE ); ?>">
			<p><strong><?php esc_html_e( 'Local development detected', 'ran-booster' ); ?></strong></p>
			<p><?php esc_html_e( 'Booster can still download and replace managed plugins and themes on this site. Disable updates for packages you’re editing. Incoming webhooks for branch deployment are generally unavailable on local or private domains.', 'ran-booster' ); ?></p>
			<?php
			if ( isset( $core_self_update_development_notice ) ) {
				$core_self_update_development_notice->render_local_fact(); }
			?>
			<p><a href="<?php echo esc_url( $admin_url . '?page=ran-booster&tab=documentation#ran-booster-file-protection' ); ?>"><?php esc_html_e( 'Learn about local development', 'ran-booster' ); ?></a></p>
			<button type="button" class="notice-dismiss" hidden style="display:none"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'ran-booster' ); ?></span></button>
		</div>
	<?php } ?>
	<?php if ( ! in_array( $view, array( 'packages/index', 'packages/recovery' ), true ) ) { ?>
		<?php require __DIR__ . '/notices.php'; ?>
	<?php } ?>

	<div id="ran-booster-package-mutation-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>

	<?php require __DIR__ . '/' . $view . '.php'; ?>

	<hr>

	<div class="ran-booster-footer">
		<?php require __DIR__ . '/admin-feedback-toast.php'; ?>
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

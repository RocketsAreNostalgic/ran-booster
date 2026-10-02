<?php

/**
 * View locals supplied by Dashboard::render().
 *
 * @var string $view
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$footer_plugin_headers    = get_file_data(
	dirname( __DIR__ ) . '/ran-booster.php',
	array(
		'author'     => 'Author',
		'author_uri' => 'Author URI',
	),
	'plugin'
);
$footer_plugin_author     = is_string( $footer_plugin_headers['author'] ?? null )
	? trim( $footer_plugin_headers['author'] )
	: '';
$footer_plugin_author_url  = is_string( $footer_plugin_headers['author_uri'] ?? null )
	? trim( $footer_plugin_headers['author_uri'] )
	: '';
$footer_plugin_author_link = esc_url( $footer_plugin_author_url );
$admin_page_modifier      = match ( $view ) {
	'extensions'      => ' ran-booster-admin--extensions',
	'packages/index',
	'packages/create',
	'packages/edit'   => ' ran-booster-admin--packages',
	default           => '',
};
$ran_admin_shell_navigation = array();
$package_type             = isset( $package_view ) ? $package_view->get_type() : '';
$admin_url                = is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$package_navigation       = array(
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

require __DIR__ . '/generated/ran-admin-shell.php';

?><div class="wrap ran-booster-admin<?php echo esc_attr( $admin_page_modifier ); ?>">
	<hr class="wp-header-end">
	<?php
	if ( isset( $core_self_update_development_notice ) ) {
		$core_self_update_development_notice->render_shell_inline();
	}
	?>
	<?php if ( 'packages/index' !== $view ) { ?>
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
		</p>
	</div>
</div>

<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$footerPluginHeaders    = get_file_data(
	dirname( __DIR__ ) . '/ran-booster.php',
	array(
		'author'     => 'Author',
		'author_uri' => 'Author URI',
	),
	'plugin'
);
$footerPluginAuthor     = is_string( $footerPluginHeaders['author'] ?? null )
	? trim( $footerPluginHeaders['author'] )
	: '';
$footerPluginAuthorUrl  = is_string( $footerPluginHeaders['author_uri'] ?? null )
	? trim( $footerPluginHeaders['author_uri'] )
	: '';
$footerPluginAuthorLink = esc_url( $footerPluginAuthorUrl );
$adminPageModifier      = match ( $view ) {
	'extensions'      => ' ran-booster-admin--extensions',
	'packages/index',
	'packages/create',
	'packages/edit'   => ' ran-booster-admin--packages',
	default           => '',
};
$ranAdminShellNavigation = array();
$packageType             = isset( $packageView ) ? $packageView->getType() : '';
$adminUrl                = is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$packageNavigation       = array(
	array(
		'label'   => __( 'Plugins', 'ran-booster' ),
		'url'     => $adminUrl . '?page=ran-booster-plugins',
		'current' => 'plugin' === $packageType,
	),
	array(
		'label'   => __( 'Themes', 'ran-booster' ),
		'url'     => $adminUrl . '?page=ran-booster-themes',
		'current' => 'theme' === $packageType,
	),
);

if ( isset( $tabs ) && is_array( $tabs ) ) {
	foreach ( $tabs as $adminTab ) {
		if ( 'portability' === ( $adminTab['key'] ?? null ) ) {
			foreach ( $packageNavigation as $packageTab ) {
				$ranAdminShellNavigation[] = $packageTab;
			}
			continue;
		}

		$ranAdminShellNavigation[] = array(
			'label'   => $adminTab['label'] ?? '',
			'url'     => $adminTab['url'] ?? '',
			'current' => ! empty( $adminTab['active'] ),
		);
	}
}

$ran_admin_shell = array(
	'name'             => __( 'RAN Booster', 'ran-booster' ),
	'home_url'         => $adminUrl . '?page=ran-booster',
	'strapline'        => __( 'Deploy themes and plugins straight from your Git repos.', 'ran-booster' ),
	'logo'             => array(
		'url'    => plugins_url( 'assets/ran-booster-mark.svg', dirname( __DIR__ ) . '/ran-booster.php' ),
		'width'  => 56,
		'height' => 56,
	),
	'navigation_label' => __( 'RAN Booster sections', 'ran-booster' ),
	'navigation'       => $ranAdminShellNavigation,
);

require __DIR__ . '/generated/ran-admin-shell.php';

?><div class="wrap ran-booster-admin<?php echo esc_attr( $adminPageModifier ); ?>">
	<hr class="wp-header-end">
	<?php
	if ( isset( $coreSelfUpdateDevelopmentNotice ) ) {
		$coreSelfUpdateDevelopmentNotice->renderShellInline();
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
			<?php if ( '' !== $footerPluginAuthor && '' !== $footerPluginAuthorLink ) { ?>
				<a href="<?php echo esc_url( $footerPluginAuthorLink ); ?>"><?php echo esc_html( $footerPluginAuthor ); ?></a>
			<?php } else { ?>
				<?php echo esc_html( $footerPluginAuthor ); ?>
			<?php } ?>
		</p>
	</div>
</div>

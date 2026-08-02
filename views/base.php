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

?><div class="wrap ran-booster-admin<?php echo 'packages/index' === $view ? ' ran-booster-admin--package-index' : ''; ?>">
	<header class="ran-booster-masthead">
		<h1 class="ran-booster-brand"><a class="ran-booster-brand__link" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster' ) ); ?>"><span class="ran-booster-brand__icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10,2 C11.5,2 13,5 13,8 L13,15 L7,15 L7,8 C7,5 8.5,2 10,2 Z M7,12 L4,17 L7,15 Z M13,12 L16,17 L13,15 Z M8,15 L10,18.5 L12,15 Z"></path></svg></span> <?php esc_html_e( 'RAN Booster', 'ran-booster' ); ?></a></h1>
		<p><?php esc_html_e( 'Safe, portable and extensible repository deployment for WordPress — modern and independent.', 'ran-booster' ); ?></p>
	</header>
	<hr class="wp-header-end">
	<?php if ( 'packages/index' !== $view ) { ?>
		<?php require __DIR__ . '/notices.php'; ?>
	<?php } ?>

	<?php if ( ! str_starts_with( $view, 'packages/' ) && isset( $tabs ) && is_array( $tabs ) && array() !== $tabs ) { ?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'RAN Booster sections', 'ran-booster' ); ?>">
			<?php foreach ( $tabs as $adminTab ) { ?>
				<a href="<?php echo esc_url( $adminTab['url'] ); ?>" class="nav-tab<?php echo $adminTab['active'] ? ' nav-tab-active' : ''; ?>"<?php echo $adminTab['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $adminTab['label'] ); ?></a>
			<?php } ?>
		</nav>
	<?php } ?>
	<div id="ran-booster-package-mutation-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>

	<?php require __DIR__ . '/' . $view . '.php'; ?>

	<hr>

	<div class="ran-booster-footer">
		<?php require __DIR__ . '/admin-feedback-toast.php'; ?>
		<p>
			Copyright &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?>
			<?php if ( '' !== $footerPluginAuthor && '' !== $footerPluginAuthorLink ) { ?>
				<a href="<?php echo esc_url( $footerPluginAuthorLink ); ?>"><?php echo esc_html( $footerPluginAuthor ); ?></a>
			<?php } else { ?>
				<?php echo esc_html( $footerPluginAuthor ); ?>
			<?php } ?>
		</p>
	</div>
</div>

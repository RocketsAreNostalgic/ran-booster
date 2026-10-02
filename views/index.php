<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( isset( $add_on_tab, $add_on_context ) && $add_on_tab instanceof \RAN\Admin\AdminAddOnTab && $add_on_context instanceof \RAN\Admin\AdminAddOnContext ) { ?>
	<?php try { ?>
		<?php $add_on_tab->render( $add_on_context ); ?>
	<?php } catch ( \Throwable $failure ) { ?>
		<?php \RAN\Logging\BoosterLogger::log_exception( 'add-on tab rendering failed', $failure, array( 'step' => 'admin_add_on_render' ) ); ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'This Booster add-on could not render its tab. Check the plugin compatibility and error log.', 'ran-booster' ); ?></p></div>
	<?php } ?>
<?php } else { ?>
	<?php
	/** @var string $tab_view Built-in tab path supplied by Dashboard::get_index() in this branch. */
	require __DIR__ . '/' . $tab_view;
	?>
<?php } ?>

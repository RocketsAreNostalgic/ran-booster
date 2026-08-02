<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( isset( $addOnTab, $addOnContext ) && $addOnTab instanceof \RAN\Admin\AdminAddOnTab && $addOnContext instanceof \RAN\Admin\AdminAddOnContext ) { ?>
	<?php try { ?>
		<?php $addOnTab->render( $addOnContext ); ?>
	<?php } catch ( \Throwable $failure ) { ?>
		<?php \RAN\Logging\BoosterLogger::logException( 'add-on tab rendering failed', $failure, array( 'step' => 'admin_add_on_render' ) ); ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'This Booster add-on could not render its tab. Check the plugin compatibility and error log.', 'ran-booster' ); ?></p></div>
	<?php } ?>
<?php } else { ?>
	<?php require __DIR__ . '/' . $tabView; ?>
<?php } ?>

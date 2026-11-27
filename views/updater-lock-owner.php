<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by views/notices.php and views/attempts/detail.php.
 *
 * @var array{token: string, operation: string, started_at: int} $updater_lock_owner
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// The start time is shown as a fact only; it never stands for proof that the operation stopped.
$lock_started = (string) wp_date( 'Y-m-d H:i:s T', $updater_lock_owner['started_at'] );
?>
<div id="ran-booster-updater-lock-owner" class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
	<?php /* translators: %s: date and time the operation started. */ ?>
	<p><?php echo esc_html( sprintf( __( 'A Booster package operation started at %s didn’t finish.', 'ran-booster' ), $lock_started ) ); ?></p>
	<form method="post" action="" data-hx-boost="false">
		<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-release-updater-lock' ); ?>
		<input type="hidden" name="ran_booster[action]" value="release-updater-lock">
		<input type="hidden" name="ran_booster[lock_token]" value="<?php echo esc_attr( $updater_lock_owner['token'] ); ?>">
		<p><label><input type="checkbox" name="ran_booster[confirm_stopped]" value="1" required> <?php esc_html_e( 'I have confirmed that this operation has stopped.', 'ran-booster' ); ?></label></p>
		<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>"><?php esc_html_e( 'Release', 'ran-booster' ); ?></button>
	</form>
</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?></div>

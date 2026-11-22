<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * The in-place release of a site-wide GitHub install hold, on the held package's own page.
 *
 * @var array{digest:string,type:string,identifier:string,finished:bool,started_at:?int,url:string,check_url:string}|null $package_native_hold
 * @var array{code:string,folder:string,type:string}|null $package_native_hold_result
 */

defined( 'WPINC' ) || die;

$native_hold_code = is_array( $package_native_hold_result ?? null ) ? $package_native_hold_result['code'] : '';
if ( ! is_array( $package_native_hold ?? null ) ) {
	if ( 'released' === $native_hold_code ) {
		?>
<div id="ran-booster-native-hold" class="notice notice-success inline ran-wp-ui-notice ran-wp-ui-box-sizing is-success" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="success"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Hold released. GitHub installs can continue.', 'ran-booster' ); ?></p></div></div>
		<?php
	}
	return;
}
if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) ) {
	return;
}
$native_hold_theme  = 'theme' === $package_native_hold['type'];
$native_hold_check  = '<a href="' . esc_url( $package_native_hold['check_url'] ) . '">';
$native_hold_reload = '<a href="' . esc_url( explode( '#', $package_native_hold['url'] )[0] ) . '">';
?>
<section id="ran-booster-native-hold" class="ran-booster-settings-section ran-booster-card" aria-labelledby="ran-booster-native-hold-heading" data-ran-booster-native-hold><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
	<h3 id="ran-booster-native-hold-heading" class="ran-booster-section__title"><?php esc_html_e( 'GitHub installs are on a site-wide hold', 'ran-booster' ); ?></h3>
	<?php if ( $package_native_hold['finished'] ) { ?>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				$native_hold_theme
				/* translators: 1: opening link to the theme on the Themes screen, 2: closing link. */
				? esc_html__( 'The last install of this theme couldn\'t complete. %1$sCheck that it works%2$s, then release the hold.', 'ran-booster' )
				/* translators: 1: opening link to the plugin on the Plugins screen, 2: closing link. */
				: esc_html__( 'The last install of this plugin couldn\'t complete. %1$sCheck that it works%2$s, then release the hold.', 'ran-booster' ),
				$native_hold_check,
				'</a>'
			),
			array( 'a' => array( 'href' => true ) )
		);
		?>
	</p>
	<?php } else { ?>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				$native_hold_theme
				/* translators: 1: how long ago the install started, in WordPress's relative form, 2: opening link to the theme on the Themes screen, 3: closing link. */
				? esc_html__( 'The last install of this theme started %1$s ago and hasn\'t completed. Booster can\'t tell whether it\'s still running. %2$sCheck that the theme works%3$s, then release the hold.', 'ran-booster' )
				/* translators: 1: how long ago the install started, in WordPress's relative form, 2: opening link to the plugin on the Plugins screen, 3: closing link. */
				: esc_html__( 'The last install of this plugin started %1$s ago and hasn\'t completed. Booster can\'t tell whether it\'s still running. %2$sCheck that the plugin works%3$s, then release the hold.', 'ran-booster' ),
				esc_html( human_time_diff( $package_native_hold['started_at'] ?? time() ) ),
				$native_hold_check,
				'</a>'
			),
			array( 'a' => array( 'href' => true ) )
		);
		?>
	</p>
	<p>
		<?php
		/* translators: 1: opening link that reloads this page, 2: closing link. */
		echo wp_kses( sprintf( esc_html__( 'If it may still be running, %1$sreload this page later%2$s.', 'ran-booster' ), $native_hold_reload, '</a>' ), array( 'a' => array( 'href' => true ) ) );
		?>
	</p>
	<?php } ?>
	<form method="post" action="" data-hx-boost="false">
		<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-release-github-hold-' . $package_native_hold['digest'] ); ?>
		<input type="hidden" name="ran_booster[action]" value="release-github-hold">
		<input type="hidden" name="ran_booster[review_digest]" value="<?php echo esc_attr( $package_native_hold['digest'] ); ?>">
		<input type="hidden" name="ran_booster[package]" value="<?php echo esc_attr( $package_native_hold['identifier'] ); ?>">
		<input type="hidden" name="ran_booster[package_type]" value="<?php echo esc_attr( $package_native_hold['type'] ); ?>">
		<?php if ( ! $package_native_hold['finished'] ) { ?>
		<p><label><input type="checkbox" name="ran_booster[confirmed_stopped]" value="1" required> <?php esc_html_e( 'I\'m sure the install is no longer running.', 'ran-booster' ); ?></label></p>
		<?php } ?>
		<p><label><input type="checkbox" name="ran_booster[confirmed_checked]" value="1" required> <?php $native_hold_theme ? esc_html_e( 'I\'ve checked this theme.', 'ran-booster' ) : esc_html_e( 'I\'ve checked this plugin.', 'ran-booster' ); ?></label></p>
		<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>"><?php esc_html_e( 'Release hold', 'ran-booster' ); ?></button>
		<?php if ( 'busy' === $native_hold_code ) { ?>
		<p role="status"><?php esc_html_e( 'Another update is holding the updater lock, so Booster can\'t continue yet. Try again when it has finished.', 'ran-booster' ); ?></p>
		<?php } elseif ( 'failed' === $native_hold_code ) { ?>
		<p role="status">
			<?php
			/* translators: 1: opening link that reloads this page, 2: closing link. */
			echo wp_kses( sprintf( esc_html__( 'Booster couldn\'t release the hold. %1$sReload this page%2$s and try again.', 'ran-booster' ), $native_hold_reload, '</a>' ), array( 'a' => array( 'href' => true ) ) );
			?>
		</p>
		<?php } ?>
	</form>
</div></div></section>

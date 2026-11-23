<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * The in-place release of a site-wide GitHub install hold, on the held package's own page.
 *
 * @var array{digest:string,type:string,identifier:string,finished:bool,started_at:?int,url:string,check_url:string}|null $package_native_hold
 * @var array{code:string,folder:string,type:string}|null $package_native_hold_result
 */

defined( 'WPINC' ) || die;

if ( ! is_array( $package_native_hold ?? null ) ) {
	$native_hold_result         = $package_native_hold_result ?? null;
	$native_hold_result_surface = 'package-notice';
	$native_hold_reload_url     = '';
	$native_hold_status_folder  = '';
	require dirname( __DIR__ ) . '/native-hold-result.php';
	return;
}
if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) ) {
	return;
}
$native_hold_theme  = 'theme' === $package_native_hold['type'];
$native_hold_check  = '<a href="' . esc_url( $package_native_hold['check_url'] ) . '">';
$native_hold_reload = '<a href="' . esc_url( explode( '#', $package_native_hold['url'] )[0] ) . '">';
?>
<div id="ran-booster-native-hold" class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning ran-booster-native-hold" data-ran-booster-native-hold data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning" role="region" aria-labelledby="ran-booster-native-hold-heading">
	<h3 id="ran-booster-native-hold-heading" class="ran-wp-ui-text is-heading-lg ran-wp-ui-notice__title"><?php esc_html_e( 'GitHub installs are on a site-wide hold', 'ran-booster' ); ?></h3>
	<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
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
		$native_hold_started = $package_native_hold['started_at'];
		echo wp_kses(
			is_int( $native_hold_started )
				? sprintf(
					$native_hold_theme
					/* translators: 1: how long ago the install started, in WordPress's relative form, 2: opening link to the theme on the Themes screen, 3: closing link. */
					? esc_html__( 'The last install of this theme started %1$s ago and hasn\'t completed. Booster can\'t tell whether it\'s still running. %2$sCheck that the theme works%3$s, then release the hold.', 'ran-booster' )
					/* translators: 1: how long ago the install started, in WordPress's relative form, 2: opening link to the plugin on the Plugins screen, 3: closing link. */
					: esc_html__( 'The last install of this plugin started %1$s ago and hasn\'t completed. Booster can\'t tell whether it\'s still running. %2$sCheck that the plugin works%3$s, then release the hold.', 'ran-booster' ),
					esc_html( human_time_diff( $native_hold_started ) ),
					$native_hold_check,
					'</a>'
				)
				: sprintf(
					$native_hold_theme
					/* translators: 1: opening link to the theme on the Themes screen, 2: closing link. */
					? esc_html__( 'The last install of this theme hasn\'t completed. Booster can\'t tell whether it\'s still running. %1$sCheck that the theme works%2$s, then release the hold.', 'ran-booster' )
					/* translators: 1: opening link to the plugin on the Plugins screen, 2: closing link. */
					: esc_html__( 'The last install of this plugin hasn\'t completed. Booster can\'t tell whether it\'s still running. %1$sCheck that the plugin works%2$s, then release the hold.', 'ran-booster' ),
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
	<form method="post" action="" class="ran-booster-native-hold__form" data-hx-boost="false">
		<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-release-github-hold-' . $package_native_hold['digest'] ); ?>
		<input type="hidden" name="ran_booster[action]" value="release-github-hold">
		<input type="hidden" name="ran_booster[review_digest]" value="<?php echo esc_attr( $package_native_hold['digest'] ); ?>">
		<input type="hidden" name="ran_booster[package]" value="<?php echo esc_attr( $package_native_hold['identifier'] ); ?>">
		<input type="hidden" name="ran_booster[package_type]" value="<?php echo esc_attr( $package_native_hold['type'] ); ?>">
		<div class="ran-booster-native-hold__checks">
		<?php if ( ! $package_native_hold['finished'] ) { ?>
		<label class="ran-booster-native-hold__check"><input type="checkbox" name="ran_booster[confirmed_stopped]" value="1" required><span><?php esc_html_e( 'I\'m sure the install is no longer running.', 'ran-booster' ); ?></span></label>
		<?php } ?>
		<label class="ran-booster-native-hold__check"><input type="checkbox" name="ran_booster[confirmed_checked]" value="1" required><span><?php $native_hold_theme ? esc_html_e( 'I\'ve checked this theme.', 'ran-booster' ) : esc_html_e( 'I\'ve checked this plugin.', 'ran-booster' ); ?></span></label>
		</div>
		<div class="ran-booster-native-hold__actions">
		<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>"><?php esc_html_e( 'Release hold', 'ran-booster' ); ?></button>
		<?php
		$native_hold_result         = $package_native_hold_result ?? null;
		$native_hold_result_surface = 'package-status';
		$native_hold_reload_url     = $package_native_hold['url'];
		$native_hold_status_folder  = '';
		require dirname( __DIR__ ) . '/native-hold-result.php';
		?>
		</div>
	</form>
	</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'warning' ); ?>
</div>

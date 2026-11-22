<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Result notice and status feedback for native GitHub hold actions.
 *
 * @var array{code:string,folder:string,type:string}|null $native_hold_result
 * @var 'package-notice'|'provider-notice'|'package-status'|'provider-status' $native_hold_result_surface
 * @var string $native_hold_reload_url
 * @var string $native_hold_status_folder
 */

defined( 'WPINC' ) || die;

$native_hold_code   = is_array( $native_hold_result ?? null ) ? $native_hold_result['code'] : '';
$native_hold_notice = null;
if ( 'package-notice' === $native_hold_result_surface && 'released' === $native_hold_code ) {
	$native_hold_notice = esc_html__( 'Hold released. GitHub installs can continue.', 'ran-booster' );
} elseif ( 'provider-notice' === $native_hold_result_surface && is_array( $native_hold_result ) && 'cleared' === $native_hold_code && '' !== $native_hold_result['folder'] ) {
	$native_hold_install_url = add_query_arg(
		'page',
		'theme' === $native_hold_result['type'] ? 'ran-booster-themes-create' : 'ran-booster-plugins-create',
		is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
	);
	$native_hold_notice      = sprintf(
		/* translators: 1: the folder Booster no longer manages, 2: opening link to the install form, 3: closing link. */
		__( 'Hold cleared. Booster no longer manages %1$s. %2$sInstall it again%3$s to continue.', 'ran-booster' ),
		'<code>' . esc_html( $native_hold_result['folder'] ) . '</code>',
		'<a href="' . esc_url( $native_hold_install_url ) . '">',
		'</a>'
	);
}
if ( null !== $native_hold_notice ) {
	?>
<div id="ran-booster-native-hold" class="notice notice-success inline ran-wp-ui-notice ran-wp-ui-box-sizing is-success" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="success"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p>
	<?php
	echo wp_kses(
		$native_hold_notice,
		array(
			'a'    => array( 'href' => true ),
			'code' => array(),
		)
	);
	?>
</p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'success' ); ?></div>
	<?php
}

$native_hold_status = null;
if ( 'provider-status' === $native_hold_result_surface && 'present' === $native_hold_code ) {
	$native_hold_status = sprintf(
		/* translators: %s: the held package's folder name. */
		__( 'The folder %s is still there. Delete it, then confirm again.', 'ran-booster' ),
		'<code>' . esc_html( $native_hold_status_folder ) . '</code>'
	);
} elseif ( in_array( $native_hold_result_surface, array( 'package-status', 'provider-status' ), true ) ) {
	if ( 'busy' === $native_hold_code ) {
		$native_hold_status = esc_html__( 'Another update is holding the updater lock, so Booster can\'t continue yet. Try again when it has finished.', 'ran-booster' );
	} elseif ( 'failed' === $native_hold_code ) {
		$native_hold_status = sprintf(
			/* translators: 1: opening link that reloads this page, 2: closing link. */
			esc_html__( 'Booster couldn\'t release the hold. %1$sReload this page%2$s and try again.', 'ran-booster' ),
			'<a href="' . esc_url( explode( '#', $native_hold_reload_url )[0] ) . '">',
			'</a>'
		);
	}
}
if ( null !== $native_hold_status ) {
	?>
<p role="status">
	<?php
	echo wp_kses(
		$native_hold_status,
		array(
			'a'    => array( 'href' => true ),
			'code' => array(),
		)
	);
	?>
</p>
	<?php
}

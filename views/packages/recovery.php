<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var string $recovery_identifier
 * @var string $recovery_name
 * @var 'healthy'|'damaged'|'blocked'|'unknown' $recovery_state
 * @var string $recovery_reason
 * @var string|null $recovery_blocker
 * @var array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string,installed_digest:string}|null $recovery_confirmation
 */
defined( 'WPINC' ) || die;
$recovery_type        = $package_view->get_type();
$recovery_url         = add_query_arg(
	array(
		'page'            => $package_view->get_page_slug(),
		'recovery'        => '1',
		'recovery_target' => $recovery_identifier,
	),
	$package_view->get_admin_url()
);
$package_list_url     = add_query_arg( 'page', $package_view->get_page_slug(), $package_view->get_admin_url() );
$package_settings_url = add_query_arg( 'package', $recovery_identifier, $package_list_url );
$troubleshooting_url  = add_query_arg(
	array(
		'page' => 'ran-booster',
		'tab'  => 'troubleshooting',
	),
	$package_view->get_admin_url()
);
?>
<p class="ran-booster-package-settings__back"><a href="<?php echo esc_url( $package_list_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s is Managed Plugins or Managed Themes. */ __( 'Back to Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></a></p>
<div data-ran-booster-package-failure-state="<?php echo esc_attr( $recovery_state ); ?>" data-ran-booster-package-identifier="<?php echo esc_attr( $recovery_identifier ); ?>">
	<h2 class="ran-booster-page-heading__title">
	<?php
	if ( 'healthy' === $recovery_state ) {
		echo esc_html( sprintf( /* translators: %s is an installed plugin or theme name. */ __( 'Management settings are available for %s', 'ran-booster' ), $recovery_name ) );
	} elseif ( '' !== $recovery_name ) {
		echo esc_html( sprintf( /* translators: %s is an installed plugin or theme name. */ __( 'Booster can’t open settings for %s', 'ran-booster' ), $recovery_name ) );
	} else {
		esc_html_e( 'Package management settings unavailable', 'ran-booster' );
	}
	?>
	</h2>
	<?php require dirname( __DIR__ ) . '/notices.php'; ?>
	<section class="ran-booster-settings-section">
		<p class="ran-booster-page-heading__description"><?php echo esc_html( $recovery_reason ); ?></p>
		<?php if ( '' !== $recovery_identifier ) { ?>
			<p><code><?php echo esc_html( $recovery_identifier ); ?></code></p>
		<?php } ?>
		<?php if ( 'healthy' === $recovery_state ) { ?>
			<p><a class="button button-primary" href="<?php echo esc_url( $package_settings_url ); ?>"><?php esc_html_e( 'Open package settings', 'ran-booster' ); ?></a></p>
		<?php } elseif ( 'damaged' === $recovery_state && null !== $recovery_confirmation ) { ?>
			<p><?php esc_html_e( 'The problem is in Booster’s saved management settings. This does not establish that the installed package or its data is damaged. Booster cannot use these invalid settings to manage updates.', 'ran-booster' ); ?></p>
			<p><?php esc_html_e( 'You can stop Booster managing this package and then link it again using verified repository settings. Stopping management keeps its installed files, WordPress activation state, package data, shared credentials and deployment history.', 'ran-booster' ); ?></p>
			<form method="post" action="<?php echo esc_url( $recovery_url ); ?>">
				<?php wp_nonce_field( \RAN\Admin\PackageAdminController::recovery_action( $recovery_type, $recovery_identifier, $recovery_confirmation['adoption_token'], $recovery_confirmation['digest'], $recovery_confirmation['installed_digest'] ) ); ?>
				<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( 'recover-' . $recovery_type ); ?>">
				<input type="hidden" name="ran_booster[identifier]" value="<?php echo esc_attr( $recovery_identifier ); ?>">
				<?php foreach ( array( 'adoption_token', 'digest', 'installed_digest' ) as $confirmation_field ) { ?>
					<input type="hidden" name="ran_booster[<?php echo esc_attr( $confirmation_field ); ?>]" value="<?php echo esc_attr( $recovery_confirmation[ $confirmation_field ] ); ?>">
				<?php } ?>
				<p><label><input type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required> <?php echo esc_html( sprintf( /* translators: %s is the installed package name. */ __( 'Stop managing %s and retain its installed files.', 'ran-booster' ), $recovery_name ) ); ?></label></p>
				<button type="submit" class="button"><?php echo esc_html( sprintf( /* translators: %s is the installed package name. */ _x( 'Stop managing %s', 'installed package name', 'ran-booster' ), $recovery_name ) ); ?></button>
			</form>
		<?php } else { ?>
			<?php if ( null !== $recovery_blocker ) { ?>
				<p><?php echo esc_html( $recovery_blocker ); ?></p>
			<?php } ?>
			<p><?php esc_html_e( 'No management changes have been made. Booster will not disconnect a package whose management identity or operation state cannot be verified.', 'ran-booster' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $troubleshooting_url ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a></p>
		<?php } ?>
	</section>
</div>

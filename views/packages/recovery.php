<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var string $recovery_identifier
 * @var string $recovery_name
 * @var 'healthy'|'damaged'|'unregistered'|'blocked'|'unknown' $recovery_state
 * @var string $recovery_reason
 * @var string|null $recovery_blocker
 * @var array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string,installed_digest?:string,folder_absent?:bool}|null $recovery_confirmation
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
<div class="ran-booster-package-recovery" data-ran-booster-package-failure-state="<?php echo esc_attr( $recovery_state ); ?>" data-ran-booster-package-identifier="<?php echo esc_attr( $recovery_identifier ); ?>">
	<h2 class="ran-booster-package-settings__heading ran-booster-page-heading__title">
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
	<section class="ran-booster-settings-section ran-booster-settings-section__body">
		<p class="ran-booster-page-heading__description"><?php echo esc_html( $recovery_reason ); ?></p>
		<?php if ( '' !== $recovery_identifier ) { ?>
			<p><code><?php echo esc_html( $recovery_identifier ); ?></code></p>
		<?php } ?>
		<?php if ( 'healthy' === $recovery_state ) { ?>
			<p><a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary( false, true ) ); ?>" href="<?php echo esc_url( $package_settings_url ); ?>"><?php esc_html_e( 'Open package settings', 'ran-booster' ); ?></a></p>
		<?php } elseif ( null !== $recovery_confirmation && ( ( 'damaged' === $recovery_state && is_string( $recovery_confirmation['installed_digest'] ?? null ) ) || 'unregistered' === $recovery_state ) ) { ?>
			<?php
			$recovery_unregistered  = 'unregistered' === $recovery_state;
			$recovery_folder_absent = $recovery_unregistered && true === ( $recovery_confirmation['folder_absent'] ?? false );
			?>
			<?php if ( $recovery_unregistered ) { ?>
				<?php if ( $recovery_folder_absent ) { ?>
					<p><?php esc_html_e( 'Its folder is not on disk. Stopping management removes Booster’s management record without touching disk files, so you can install it again.', 'ran-booster' ); ?></p>
				<?php } else { ?>
					<p><?php esc_html_e( 'Its folder is still on disk. Stopping management removes Booster’s management record without deleting any files. Remove its folder from disk before reinstalling.', 'ran-booster' ); ?></p>
				<?php } ?>
			<?php } else { ?>
				<p><?php esc_html_e( 'The problem is in Booster’s saved management settings. This does not establish that the installed package or its data is damaged. Booster cannot use these invalid settings to manage updates.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'You can stop Booster managing this package and then link it again using verified repository settings. Stopping management keeps its installed files, WordPress activation state, package data, shared credentials and deployment history.', 'ran-booster' ); ?></p>
			<?php } ?>
			<?php
			$recovery_installed_digest = is_string( $recovery_confirmation['installed_digest'] ?? null ) ? $recovery_confirmation['installed_digest'] : '';
			$recovery_nonce_action     = $recovery_unregistered
				? \RAN\Admin\PackageAdminController::unregistered_recovery_action( $recovery_type, $recovery_identifier, $recovery_confirmation['adoption_token'], $recovery_confirmation['digest'] )
				: \RAN\Admin\PackageAdminController::recovery_action( $recovery_type, $recovery_identifier, $recovery_confirmation['adoption_token'], $recovery_confirmation['digest'], $recovery_installed_digest );
			$recovery_fields           = $recovery_unregistered ? array( 'adoption_token', 'digest' ) : array( 'adoption_token', 'digest', 'installed_digest' );
			$recovery_checkbox_label   = $recovery_unregistered
				? ( $recovery_folder_absent
					/* translators: %s is the package folder slug. */
					? __( 'Stop managing %s and remove its Booster management record.', 'ran-booster' )
					/* translators: %s is the package folder slug. */
					: __( 'Stop managing %s without deleting its files on disk.', 'ran-booster' ) )
				/* translators: %s is the installed package name. */
				: __( 'Stop managing %s and retain its installed files.', 'ran-booster' );
			?>
			<form method="post" action="<?php echo esc_url( $recovery_url ); ?>" data-ran-booster-submit-busy>
				<?php \RAN\Admin\Component\NonceField::print( $recovery_nonce_action ); ?>
				<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( 'recover-' . $recovery_type ); ?>">
				<?php if ( $recovery_unregistered ) { ?>
					<input type="hidden" name="ran_booster[recovery_mode]" value="unregistered">
				<?php } ?>
				<input type="hidden" name="ran_booster[identifier]" value="<?php echo esc_attr( $recovery_identifier ); ?>">
				<?php foreach ( $recovery_fields as $confirmation_field ) { ?>
					<input type="hidden" name="ran_booster[<?php echo esc_attr( $confirmation_field ); ?>]" value="<?php echo esc_attr( (string) ( $recovery_confirmation[ $confirmation_field ] ?? '' ) ); ?>">
				<?php } ?>
				<p><label><input type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required> <?php echo esc_html( sprintf( $recovery_checkbox_label, $recovery_name ) ); ?></label></p>
				<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( __( 'Stopping…', 'ran-booster' ) ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( __( 'Stopping management. This can take a few seconds.', 'ran-booster' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s is the installed package name. */ _x( 'Stop managing %s', 'installed package name', 'ran-booster' ), $recovery_name ) ); ?></button>
				<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
			</form>
		<?php } else { ?>
			<?php if ( null !== $recovery_blocker ) { ?>
				<p><?php echo esc_html( $recovery_blocker ); ?></p>
			<?php } ?>
			<p><?php esc_html_e( 'No management changes have been made. Booster will not disconnect a package whose management identity or operation state cannot be verified.', 'ran-booster' ); ?></p>
			<p><a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $troubleshooting_url ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a></p>
		<?php } ?>
	</section>
</div>

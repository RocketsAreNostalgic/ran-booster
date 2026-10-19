<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var array<string,string> $recovery_targets
 * @var array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string,installed_digest:string}|null $recovery_confirmation
 * @var string $recovery_identifier
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
$recovery_type = $package_view->get_type();
$recovery_url  = add_query_arg(
	array(
		'page'     => $package_view->get_page_slug(),
		'recovery' => '1',
	),
	$package_view->get_admin_url()
);
?>
<h2 class="ran-booster-page-heading__title"><?php esc_html_e( 'Stop managing an installed package', 'ran-booster' ); ?></h2>
<?php require dirname( __DIR__ ) . '/notices.php'; ?>
<p class="ran-booster-package-settings__intro ran-booster-page-heading__description"><?php esc_html_e( 'Stop managing an installed package when its management settings cannot be opened. This keeps installed files, activation, package data, shared credentials and deployment history. Running or unresolved operations prevent removal.', 'ran-booster' ); ?></p>
<form method="get" action="<?php echo esc_url( $package_view->get_admin_url() ); ?>">
	<input type="hidden" name="page" value="<?php echo esc_attr( $package_view->get_page_slug() ); ?>">
	<input type="hidden" name="recovery" value="1">
	<label for="ran-booster-recovery-target"><?php esc_html_e( 'Installed package', 'ran-booster' ); ?></label>
	<select id="ran-booster-recovery-target" name="recovery_target" required>
		<option value=""><?php esc_html_e( 'Choose an exact installed target', 'ran-booster' ); ?></option>
		<?php foreach ( $recovery_targets as $target_identifier => $target_name ) { ?>
			<option value="<?php echo esc_attr( $target_identifier ); ?>" <?php selected( $recovery_identifier, $target_identifier ); ?>><?php echo esc_html( $target_name . ' — ' . $target_identifier ); ?></option>
		<?php } ?>
	</select>
	<button type="submit" class="button"><?php esc_html_e( 'Review management removal', 'ran-booster' ); ?></button>
</form>
<?php if ( null !== $recovery_confirmation ) { ?>
	<h3><?php esc_html_e( 'Confirm stop managing', 'ran-booster' ); ?></h3>
	<p><code><?php echo esc_html( $recovery_identifier ); ?></code></p>
	<p><?php esc_html_e( 'This confirmation applies only to the installed target and management record checked on this page. Changes before submission require a fresh review.', 'ran-booster' ); ?></p>
	<form method="post" action="<?php echo esc_url( add_query_arg( 'recovery_target', $recovery_identifier, $recovery_url ) ); ?>">
		<?php wp_nonce_field( \RAN\Admin\PackageAdminController::recovery_action( $recovery_type, $recovery_identifier, $recovery_confirmation['adoption_token'], $recovery_confirmation['digest'], $recovery_confirmation['installed_digest'] ) ); ?>
		<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( 'recover-' . $recovery_type ); ?>">
		<input type="hidden" name="ran_booster[identifier]" value="<?php echo esc_attr( $recovery_identifier ); ?>">
		<?php foreach ( array( 'adoption_token', 'digest', 'installed_digest' ) as $confirmation_field ) { ?>
			<input type="hidden" name="ran_booster[<?php echo esc_attr( $confirmation_field ); ?>]" value="<?php echo esc_attr( $recovery_confirmation[ $confirmation_field ] ); ?>">
		<?php } ?>
		<p><label><input type="checkbox" name="ran_booster[confirm_package_removal]" value="1" required> <?php esc_html_e( 'Stop managing this exact package and retain its installed files.', 'ran-booster' ); ?></label></p>
		<button type="submit" class="button"><?php esc_html_e( 'Stop managing', 'ran-booster' ); ?></button>
	</form>
<?php } ?>
<h3><?php esc_html_e( 'Link the package again', 'ran-booster' ); ?></h3>
<p><?php esc_html_e( 'After stopping management, you can link the installed package again using verified repository and branch settings. For published releases, then enable release tracking from the package settings. Restore any unavailable repository provider before adopting again. Re-adoption starts a new management lifetime.', 'ran-booster' ); ?></p>
<?php if ( isset( $recovery_targets[ $recovery_identifier ] ) ) { ?>
	<p><a class="button" href="
	<?php
	echo esc_url(
		add_query_arg(
			array(
				'page'   => $package_view->get_create_page_slug(),
				'relink' => $recovery_identifier,
			),
			$package_view->get_admin_url()
		)
	);
	?>
								"><?php esc_html_e( 'Link this installed package', 'ran-booster' ); ?></a></p>
<?php } ?>

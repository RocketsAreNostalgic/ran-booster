<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<section class="ran-booster-page-shell" aria-labelledby="ran-booster-settings-heading">
	<header class="ran-booster-page-shell__header">
		<h2 id="ran-booster-settings-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Settings', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Site-wide Booster preferences and secure credential storage.', 'ran-booster' ); ?></p>
	</header>
	<section class="ran-booster-panel" aria-labelledby="ran-booster-general-heading">
		<h3 id="ran-booster-general-heading"><?php esc_html_e( 'General', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Booster currently uses its configured policy defaults.', 'ran-booster' ); ?></p>
	</section>
	<section class="ran-booster-panel" aria-labelledby="ran-booster-storage">
		<h3 id="ran-booster-storage"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=overview#ran-booster-onboarding-storage-heading' ) ); ?>"><?php esc_html_e( 'Review secure credential storage', 'ran-booster' ); ?></a></p>
	</section>
</section>

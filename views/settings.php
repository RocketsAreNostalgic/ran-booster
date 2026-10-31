<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * @var array<string,mixed>|null $secrets_storage
 * @var string $credential_storage_documentation_url
 */
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
	<?php if ( null !== $secrets_storage ) { ?>
		<?php require __DIR__ . '/settings-storage.php'; ?>
	<?php } else { ?>
		<section class="ran-booster-panel" aria-labelledby="ran-booster-storage">
			<h3 id="ran-booster-storage"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Storage status is unavailable. No setup or recovery action has been performed.', 'ran-booster' ); ?></p>
		</section>
	<?php } ?>
</section>

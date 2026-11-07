<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.

/**
 * @var array<string, mixed>|null $secrets_storage
 * @var string $credential_storage_documentation_url
 * @var array<string,array{value:bool|int,source:string,locked:bool,error:?string}> $general_settings
 * @var string $general_token
 * @var ?string $general_feedback
 * @var array<string,string> $general_errors
 * @var array<string,string> $general_attempted
 * @var array{hidden:bool,eligible:bool,reason:string} $github_visibility
 * @var array<string,mixed> $core_update_status
 * @var bool $check_available
 * @var ?string $native_update_url
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

?>
<section class="ran-booster-page-shell ran-booster-settings" aria-labelledby="ran-booster-settings-heading">
	<header class="ran-booster-page-shell__header">
		<h2 id="ran-booster-settings-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Settings', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Site-wide Booster preferences and secure credential storage.', 'ran-booster' ); ?></p>
	</header>
		<?php if ( null === $secrets_storage ) { ?>
			<section class="ran-booster-panel ran-booster-card" data-ran-booster-card="settings" aria-labelledby="ran-booster-storage"><h3 id="ran-booster-storage"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3><p><?php esc_html_e( 'Storage status is unavailable. No setup or recovery action has been performed.', 'ran-booster' ); ?></p></section>
		<?php } else { ?>
			<?php require __DIR__ . '/settings-storage.php'; ?>
		<?php } ?>
	<?php require __DIR__ . '/settings-general.php'; ?>
</section>

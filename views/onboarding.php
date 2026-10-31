<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var array<string, mixed> $onboarding
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

?>
<section class="ran-booster-page-shell ran-booster-onboarding" aria-labelledby="ran-booster-onboarding-heading">
	<header class="ran-booster-page-shell__header ran-booster-onboarding__header">
		<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Start with a repository', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Public repositories do not need repository credentials or webhooks.', 'ran-booster' ); ?></p>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Install and manage custom plugins and themes from supported Git repositories; private access and Push-to-Deploy are optional.', 'ran-booster' ); ?></p>
		<p class="ran-booster-onboarding__actions">
			<a class="button button-primary" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
			<a class="button" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
		</p>
	</header>

	<div class="ran-booster-onboarding__columns">
		<section class="ran-booster-panel ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-connect-heading">
			<h3 id="ran-booster-onboarding-connect-heading"><?php esc_html_e( 'Private repository access', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Add a credential for a Git provider, such as GitHub or Bitbucket, only when anonymous access is not enough.', 'ran-booster' ); ?></p>

			<h4 class="ran-booster-onboarding__provider-heading"><?php esc_html_e( 'Connect a Git provider', 'ran-booster' ); ?></h4>
			<?php if ( array() !== $onboarding['provider_links'] ) { ?>
				<ul class="ran-booster-onboarding__links">
					<?php foreach ( $onboarding['provider_links'] as $provider_link ) { ?>
						<?php // translators: %s is the display name of a registered Git provider. ?>
						<li><a href="<?php echo esc_url( $provider_link['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Add %s access', 'ran-booster' ), $provider_link['label'] ) ); ?></a></li>
					<?php } ?>
				</ul>
			<?php } else { ?>
				<p><?php esc_html_e( 'Git provider settings will appear here when an integration is available.', 'ran-booster' ); ?></p>
			<?php } ?>
		</section>

		<section class="ran-booster-panel ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-next-heading">
			<h3 id="ran-booster-onboarding-next-heading"><?php esc_html_e( 'Move or automate later', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Packages begin in Manual mode. Enable Push-to-Deploy only when the target is ready.', 'ran-booster' ); ?></p>
			<ul class="ran-booster-onboarding__links">
				<li><a href="<?php echo esc_url( $onboarding['portability_url'] ); ?>"><?php esc_html_e( 'Move an existing Booster setup', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['documentation_url'] ); ?>"><?php esc_html_e( 'Read the documentation', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['troubleshooting_url'] ); ?>"><?php esc_html_e( 'Open troubleshooting', 'ran-booster' ); ?></a></li>
			</ul>
		</section>
	</div>

	<?php
	$migration_prompt_buffer_level = ob_get_level();
	ob_start();
	try {
		do_action( 'ran_booster_overview_render_migration_prompt' );
		$migration_prompt = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-ons own and escape their bounded migration prompt.
		echo $migration_prompt;
	} catch ( \Throwable $failure ) {
		while ( ob_get_level() > $migration_prompt_buffer_level ) {
			ob_end_clean();
		}
		\RAN\Logging\BoosterLogger::log_exception( 'overview migration prompt rendering failed', $failure, array( 'step' => 'overview_migration_prompt_render' ) );
	}
	?>

	<?php if ( isset( $onboarding['storage_summary'] ) && is_array( $onboarding['storage_summary'] ) ) { ?>
		<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-onboarding-storage-heading">
			<h3 id="ran-booster-onboarding-storage-heading"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
			<p><?php echo esc_html( $onboarding['storage_summary']['message'] ); ?></p>
			<p><a href="<?php echo esc_url( $onboarding['storage_summary']['url'] ); ?>"><?php esc_html_e( 'Review secure credential storage', 'ran-booster' ); ?></a></p>
		</section>
	<?php } ?>
</section>

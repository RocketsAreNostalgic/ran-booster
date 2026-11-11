<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var bool $package_mutation_available
 * @var bool $repository_read_only
 */

defined( 'WPINC' ) || die;

$repository_read_only = isset( $repository_read_only ) && true === $repository_read_only;

?>
<section class="ran-booster-settings-section" aria-labelledby="ran-booster-package-configuration-heading">
	<header class="ran-booster-settings-section__header">
		<h3 id="ran-booster-package-configuration-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository configuration', 'ran-booster' ); ?></h3>
	</header>
	<div class="ran-booster-settings-section__body">
		<fieldset <?php disabled( ! $package_mutation_available ); ?>>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/provider.php'; ?>
				<?php require __DIR__ . '/fields/credential.php'; ?>
				<?php if ( ! $repository_read_only ) { ?>
					<div class="notice notice-info inline ran-booster-settings-fields__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Only install Git provider integrations you trust: an active Git provider can read its saved credentials. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'info' ); ?></div>
				<?php } ?>
				<?php require __DIR__ . '/fields/repository.php'; ?>
				<?php if ( $repository_read_only ) { ?>
					<div id="ran-booster-repository-fixed-description" class="notice notice-info inline ran-booster-settings-fields__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Provider and repository can\'t change after install.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'info' ); ?></div>
				<?php } ?>
			</div>
		</fieldset>
	</div>
</section>

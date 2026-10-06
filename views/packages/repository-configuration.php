<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var bool $package_mutation_available
 */

defined( 'WPINC' ) || die;

$package_repository_description = isset( $package_repository_description ) && is_string( $package_repository_description )
	? $package_repository_description
	: '';

?>
<section class="ran-booster-settings-section" aria-labelledby="ran-booster-package-configuration-heading">
	<header class="ran-booster-settings-section__header">
		<h3 id="ran-booster-package-configuration-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository configuration', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php echo esc_html( $package_repository_description ); ?></p>
	</header>
	<div class="ran-booster-settings-section__body">
		<fieldset <?php disabled( ! $package_mutation_available ); ?>>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/provider.php'; ?>
				<?php require __DIR__ . '/fields/credential.php'; ?>
				<?php require __DIR__ . '/fields/repository.php'; ?>
			</div>
		</fieldset>
	</div>
</section>

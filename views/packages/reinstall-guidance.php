<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var string $deployment_policy
 * @var bool $package_mutation_available
 */

defined( 'WPINC' ) || die;

$reinstall_available = $package_mutation_available
	&& \RAN\Deployment\DeploymentPolicy::DISABLED->value !== $deployment_policy;

?>
<p class="description" data-ran-booster-reinstall-guidance <?php echo $reinstall_available ? 'hidden' : ''; ?>><?php esc_html_e( 'Set Updates to Manual or Automatic before reinstalling.', 'ran-booster' ); ?></p>

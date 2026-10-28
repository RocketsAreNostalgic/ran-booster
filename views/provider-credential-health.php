<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals are supplied by the owning Core renderer.
/** @var string $credential_health_id */
/** @var string $credential_health_label */
/** @var string|null $credential_validation_message */
/** @var string|null $credential_validation_error */

defined( 'ABSPATH' ) || exit;
?>
<div id="<?php echo esc_attr( 'ran-booster-credential-validation-error-' . $credential_health_id ); ?>" class="ran-booster-credential-health" data-ran-booster-admin-mutation-error tabindex="-1">
	<span><?php echo esc_html( $credential_health_label ); ?></span>
	<p data-ran-booster-credential-result class="<?php echo esc_attr( null !== $credential_validation_error ? 'ran-booster-credential-health__error' : 'ran-booster-credential-health__success' ); ?>"<?php echo null === $credential_validation_error && null === $credential_validation_message ? ' hidden' : ''; ?>><?php echo esc_html( $credential_validation_error ?? $credential_validation_message ?? '' ); ?></p>
</div>

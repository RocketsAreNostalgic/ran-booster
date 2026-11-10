<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals are supplied by the owning Core renderer.
/** @var string $credential_health_id */
/** @var string|null $credential_validation_message */
/** @var string|null $credential_validation_error */

defined( 'ABSPATH' ) || exit;

$credential_validation_intent = null !== $credential_validation_error ? 'error' : 'success';
?>
<div id="<?php echo esc_attr( 'ran-booster-credential-validation-error-' . $credential_health_id ); ?>" class="ran-booster-credential-health" data-ran-booster-admin-mutation-error tabindex="-1">
	<div class="notice notice-<?php echo esc_attr( $credential_validation_intent ); ?> inline ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $credential_validation_intent ); ?>" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $credential_validation_intent ); ?>"<?php echo null === $credential_validation_error && null === $credential_validation_message ? ' hidden' : ''; ?>><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
	<p data-ran-booster-credential-result class="<?php echo esc_attr( null !== $credential_validation_error ? 'ran-booster-credential-health__error' : 'ran-booster-credential-health__success' ); ?>"<?php echo null === $credential_validation_error && null === $credential_validation_message ? ' hidden' : ''; ?>><?php echo esc_html( $credential_validation_error ?? $credential_validation_message ?? '' ); ?></p>
	</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( $credential_validation_intent ); ?></div>
</div>

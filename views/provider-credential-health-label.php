<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals are supplied by the owning Core renderer.
/** @var string $credential_health_id */
/** @var string $credential_health_label */
/** @var bool $credential_health_label_oob */

defined( 'ABSPATH' ) || exit;
?>
<span id="<?php echo esc_attr( 'ran-booster-credential-health-' . $credential_health_id ); ?>"<?php echo $credential_health_label_oob ? ' hx-swap-oob="true"' : ''; ?>><?php echo esc_html( $credential_health_label ); ?></span>

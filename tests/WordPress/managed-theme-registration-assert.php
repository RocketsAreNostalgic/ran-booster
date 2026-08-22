<?php
/**
 * Assert normal Booster registration for active and inactive managed themes.
 */

if ( 'ran-booster-managed-active' !== get_stylesheet() ) {
	throw new RuntimeException( 'The managed active fixture theme is not active.' );
}

foreach ( array( 'ran-booster-managed-active', 'ran-booster-managed-inactive' ) as $ran_booster_stylesheet ) {
	$ran_booster_theme = wp_get_theme( $ran_booster_stylesheet );
	if ( ! $ran_booster_theme->exists() || false !== $ran_booster_theme->errors() ) {
		throw new RuntimeException( 'A managed fixture theme is unavailable.' );
	}
}

$ran_booster_theme_hook = $GLOBALS['wp_filter']['update_themes_github.com'] ?? null;
if ( ! $ran_booster_theme_hook instanceof WP_Hook
	|| 2 !== count( $ran_booster_theme_hook->callbacks[10] ?? array() ) ) {
	throw new RuntimeException( 'Booster did not register exactly one neutral updater callback for each managed theme fixture.' );
}

WP_CLI::success( 'Normal Booster registration covers active and inactive managed themes through the neutral updater hooks.' );

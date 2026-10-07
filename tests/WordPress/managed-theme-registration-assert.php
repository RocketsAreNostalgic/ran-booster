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
	$ran_booster_package = \RAN\Theme::from_wp_theme_object( wp_theme: $ran_booster_theme );
	if ( $ran_booster_stylesheet !== $ran_booster_package->get_identifier() || $ran_booster_theme->get( 'Version' ) !== $ran_booster_package->get_version() ) {
		throw new RuntimeException( 'The named Theme factory changed the installed theme identity or version.' );
	}
}

$ran_booster_theme_hook           = $GLOBALS['wp_filter']['update_themes_github.com'] ?? null;
$ran_booster_theme_callback_count = $ran_booster_theme_hook instanceof WP_Hook
	? count( $ran_booster_theme_hook->callbacks[10] ?? array() )
	: 0;
if ( 2 !== $ran_booster_theme_callback_count ) {
	throw new RuntimeException(
		sprintf(
			'Booster registered %d neutral managed-theme callbacks; expected exactly 2.',
			// The bounded integer count is emitted only to diagnose the disposable CLI proof.
			$ran_booster_theme_callback_count
		)
	);
}

WP_CLI::success( 'Normal Booster registration covers active and inactive managed themes through the neutral updater hooks.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

<?php
/**
 * Assert normal Booster registration for active and inactive managed themes.
 */

declare(strict_types=1);

if ( 'ran-booster-managed-active' !== get_stylesheet() ) {
	throw new RuntimeException( 'The managed active fixture theme is not active.' );
}

foreach ( array( 'ran-booster-managed-active', 'ran-booster-managed-inactive' ) as $ran_booster_stylesheet ) {
	$ran_booster_theme = wp_get_theme( $ran_booster_stylesheet );
	if ( ! $ran_booster_theme->exists() || false !== $ran_booster_theme->errors() ) {
		throw new RuntimeException( 'A managed fixture theme is unavailable.' );
	}
	if ( ! function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
		|| ! ran_wp_github_release_updater_v1_has_registered_target( 'theme', $ran_booster_stylesheet ) ) {
		throw new RuntimeException( 'Booster did not register a managed theme fixture.' );
	}
}

WP_CLI::success( 'Normal Booster registration covers active and inactive managed themes.' );

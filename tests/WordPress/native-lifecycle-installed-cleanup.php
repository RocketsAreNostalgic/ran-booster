<?php

// Test-only cleanup: derive each target from a closed identity, never a saved path.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Native cleanup requires the marked CI site.' );
}
$ran_booster_site = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( false === $ran_booster_site || rtrim( ABSPATH, '/' ) !== $ran_booster_site
	|| WP_CONTENT_DIR !== $ran_booster_site . '/wp-content'
	|| WP_PLUGIN_DIR !== $ran_booster_site . '/wp-content/plugins'
	|| get_theme_root() !== $ran_booster_site . '/wp-content/themes'
	|| 'http://localhost' !== get_option( 'siteurl' )
	|| is_link( $ran_booster_site . '/.ran-booster-disposable-test-site' )
	|| ! is_file( $ran_booster_site . '/.ran-booster-disposable-test-site' )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	|| 'RAN Booster disposable test site' !== trim( file_get_contents( $ran_booster_site . '/.ran-booster-disposable-test-site' ) ) ) {
	throw new RuntimeException( 'Native cleanup site admission failed.' );
}
foreach ( array( $ran_booster_site, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), WP_PLUGIN_DIR . '/ran-booster' ) as $ran_booster_root ) {
	if ( is_link( $ran_booster_root ) || realpath( $ran_booster_root ) !== $ran_booster_root ) {
		throw new RuntimeException( 'Native cleanup refuses shared roots.' );
	}
}
if ( ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'Native cleanup requires installed Core.' );
}
$ran_booster_items = get_option( 'ran_booster_c4_native_items', array() );
if ( ! is_array( $ran_booster_items ) || count( $ran_booster_items ) > 20 ) {
	throw new RuntimeException( 'Native cleanup manifest is invalid.' );
}
global $wpdb;
foreach ( $ran_booster_items as $ran_booster_item ) {
	$ran_booster_number = $ran_booster_item['number'] ?? null;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$type = $ran_booster_item['type'] ?? null;
	if ( ! is_int( $ran_booster_number ) || $ran_booster_number < 1 || $ran_booster_number > 20
		|| ( 0 === $ran_booster_number % 2 ? 'theme' : 'plugin' ) !== $type ) {
		throw new RuntimeException( 'Native cleanup identity is invalid.' );
	}
	$ran_booster_name       = 'ran-booster-c4-' . $type . '-' . $ran_booster_number;
	$ran_booster_directory  = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $ran_booster_name;
	$ran_booster_metadata   = 'plugin' === $type ? $ran_booster_name . '.php' : 'style.css';
	$ran_booster_identifier = 'plugin' === $type ? $ran_booster_name . '/' . $ran_booster_metadata : $ran_booster_name;
	if ( ( $ran_booster_item['identifier'] ?? null ) !== $ran_booster_identifier || ( $ran_booster_item['directory'] ?? null ) !== $ran_booster_directory ) {
		throw new RuntimeException( 'Native cleanup manifest binding failed.' );
	}
	if ( is_link( $ran_booster_directory ) || ( file_exists( $ran_booster_directory ) && realpath( $ran_booster_directory ) !== $ran_booster_directory ) ) {
		throw new RuntimeException( 'Native cleanup target is unsafe.' );
	}
	if ( is_dir( $ran_booster_directory ) ) {
		$ran_booster_expected = 'plugin' === $type ? array( $ran_booster_metadata ) : array( 'style.css', 'index.php', 'functions.php' );
		foreach ( scandir( $ran_booster_directory ) as $ran_booster_entry ) {
			if ( '.' === $ran_booster_entry || '..' === $ran_booster_entry ) {
				continue; }
			if ( ! in_array( $ran_booster_entry, $ran_booster_expected, true ) || is_link( $ran_booster_directory . '/' . $ran_booster_entry ) || ! is_file( $ran_booster_directory . '/' . $ran_booster_entry ) ) {
				throw new RuntimeException( 'Native cleanup refuses unexpected fixture content.' );
			}
		}
		foreach ( $ran_booster_expected as $ran_booster_entry ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
			$path = $ran_booster_directory . '/' . $ran_booster_entry;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			if ( is_file( $path ) && ! unlink( $path ) ) {
				throw new RuntimeException( 'Native cleanup file removal failed.' );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		if ( ! rmdir( $ran_booster_directory ) ) {
			throw new RuntimeException( 'Native cleanup directory removal failed.' ); }
	}
	$ran_booster_deleted = $wpdb->delete(
		ran_booster_table_name(),
		array(
			'package'                => $ran_booster_identifier,
			'type'                   => 'plugin' === $type ? 1 : 2,
			'provider'               => 'gh',
			'provider_repository_id' => (string) ( 940000 + $ran_booster_number ),
			'repository'             => 'ran-booster-c4/' . $ran_booster_name,
		)
	);
	if ( false === $ran_booster_deleted || $ran_booster_deleted > 1 ) {
		throw new RuntimeException( 'Native cleanup row removal failed.' ); }
}
delete_option( 'ran_booster_c4_native_items' );
delete_option( 'ran_booster_c4_native_archives' );

<?php

// Fresh-request verification runs after every worker's real WordPress shutdown.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Native verification requires the marked CI site.' );
}
$ran_booster_site = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( false === $ran_booster_site || rtrim( ABSPATH, '/' ) !== $ran_booster_site || WP_CONTENT_DIR !== $ran_booster_site . '/wp-content' || WP_PLUGIN_DIR !== $ran_booster_site . '/wp-content/plugins' || get_theme_root() !== $ran_booster_site . '/wp-content/themes' || 'http://localhost' !== get_option( 'siteurl' ) ) {
	throw new RuntimeException( 'Native verification site admission failed.' );
}
foreach ( array( $ran_booster_site, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), WP_PLUGIN_DIR . '/ran-booster' ) as $ran_booster_root ) {
	if ( is_link( $ran_booster_root ) || realpath( $ran_booster_root ) !== $ran_booster_root ) {
		throw new RuntimeException( 'Native verification refuses shared roots.' );
	}
}
$ran_booster_scale = getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' );
$ran_booster_items = get_option( 'ran_booster_c4_native_items', null );
if ( ! is_string( $ran_booster_scale ) || ! in_array( $ran_booster_scale, array( '1', '5', '10', '20' ), true ) || ! is_array( $ran_booster_items ) || ! array_is_list( $ran_booster_items ) || count( $ran_booster_items ) !== (int) $ran_booster_scale ) {
	throw new RuntimeException( 'Native verification inventory is invalid.' );
}
$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_selection = $ran_booster_container->make( RAN\Storage\GitHubSelectionStore::class )->read();
if ( isset( $ran_booster_selection['native_mutation'] ) ) {
	throw new RuntimeException( 'Native shutdown left an unresolved mutation.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach ( $ran_booster_items as $ran_booster_item_index => $ran_booster_item ) {
	$ran_booster_number   = $ran_booster_item_index + 1;
	$ran_booster_type     = 0 === $ran_booster_number % 2 ? 'theme' : 'plugin';
	$ran_booster_name     = 'ran-booster-c4-' . $ran_booster_type . '-' . $ran_booster_number;
	$ran_booster_identity = 'plugin' === $ran_booster_type ? $ran_booster_name . '/' . $ran_booster_name . '.php' : $ran_booster_name;
	if ( ! is_array( $ran_booster_item ) || ( $ran_booster_item['number'] ?? null ) !== $ran_booster_number || ( $ran_booster_item['type'] ?? null ) !== $ran_booster_type || ( $ran_booster_item['identifier'] ?? null ) !== $ran_booster_identity || ! is_string( $ran_booster_item['expected_digest'] ?? null ) ) {
		throw new RuntimeException( 'Native verification target is invalid.' );
	}
	$ran_booster_metadata  = 'plugin' === $ran_booster_type ? WP_PLUGIN_DIR . '/' . $ran_booster_identity : get_theme_root() . '/' . $ran_booster_identity . '/style.css';
	$ran_booster_directory = dirname( $ran_booster_metadata );
	if ( ( $ran_booster_item['directory'] ?? null ) !== $ran_booster_directory || is_link( $ran_booster_directory ) || realpath( $ran_booster_directory ) !== $ran_booster_directory || is_link( $ran_booster_metadata ) || ! is_file( $ran_booster_metadata ) || ! hash_equals( $ran_booster_item['expected_digest'], (string) hash_file( 'sha256', $ran_booster_metadata ) ) ) {
		throw new RuntimeException( 'The installed native digest changed after shutdown.' );
	}
	$ran_booster_version = 'plugin' === $ran_booster_type
		? ( get_plugin_data( $ran_booster_metadata, false, false )['Version'] ?? '' )
		: ( get_file_data( $ran_booster_metadata, array( 'Version' => 'Version' ), 'theme' )['Version'] ?? '' );
	if ( '2.0.0' !== $ran_booster_version ) {
		throw new RuntimeException( 'The installed native version changed after shutdown.' );
	}
}
WP_CLI::success( 'Verified ' . $ran_booster_scale . ' native targets after real request shutdown and before cleanup.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

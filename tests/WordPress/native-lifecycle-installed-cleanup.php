<?php

// Test-only cleanup: derive each target from a closed identity, never a saved path.
// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI
    || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
    throw new RuntimeException( 'Native cleanup requires the marked CI site.' );
}
$site = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( false === $site || rtrim( ABSPATH, '/' ) !== $site
    || WP_CONTENT_DIR !== $site . '/wp-content'
    || WP_PLUGIN_DIR !== $site . '/wp-content/plugins'
    || get_theme_root() !== $site . '/wp-content/themes'
    || 'http://localhost' !== get_option( 'siteurl' )
    || is_link( $site . '/.ran-booster-disposable-test-site' )
    || ! is_file( $site . '/.ran-booster-disposable-test-site' )
    || 'RAN Booster disposable test site' !== trim( file_get_contents( $site . '/.ran-booster-disposable-test-site' ) ) ) {
    throw new RuntimeException( 'Native cleanup site admission failed.' );
}
foreach ( array( $site, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), WP_PLUGIN_DIR . '/ran-booster' ) as $root ) {
    if ( is_link( $root ) || realpath( $root ) !== $root ) {
        throw new RuntimeException( 'Native cleanup refuses shared roots.' );
    }
}
if ( ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) ) {
    throw new RuntimeException( 'Native cleanup requires installed Core.' );
}
$items = get_option( 'ran_booster_c4_native_items', array() );
if ( ! is_array( $items ) || count( $items ) > 20 ) {
    throw new RuntimeException( 'Native cleanup manifest is invalid.' );
}
global $wpdb;
foreach ( $items as $item ) {
    $number = $item['number'] ?? null;
    $type = $item['type'] ?? null;
    if ( ! is_int( $number ) || $number < 1 || $number > 20
        || $type !== ( 0 === $number % 2 ? 'theme' : 'plugin' ) ) {
        throw new RuntimeException( 'Native cleanup identity is invalid.' );
    }
    $name = 'ran-booster-c4-' . $type . '-' . $number;
    $directory = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $name;
    $metadata = 'plugin' === $type ? $name . '.php' : 'style.css';
    $identifier = 'plugin' === $type ? $name . '/' . $metadata : $name;
    if ( ( $item['identifier'] ?? null ) !== $identifier || ( $item['directory'] ?? null ) !== $directory ) {
        throw new RuntimeException( 'Native cleanup manifest binding failed.' );
    }
    if ( is_link( $directory ) || ( file_exists( $directory ) && realpath( $directory ) !== $directory ) ) {
        throw new RuntimeException( 'Native cleanup target is unsafe.' );
    }
    if ( is_dir( $directory ) ) {
        $expected = 'plugin' === $type ? array( $metadata ) : array( 'style.css', 'index.php', 'functions.php' );
        foreach ( scandir( $directory ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) { continue; }
            if ( ! in_array( $entry, $expected, true ) || is_link( $directory . '/' . $entry ) || ! is_file( $directory . '/' . $entry ) ) {
                throw new RuntimeException( 'Native cleanup refuses unexpected fixture content.' );
            }
        }
        foreach ( $expected as $entry ) {
            $path = $directory . '/' . $entry;
            if ( is_file( $path ) && ! unlink( $path ) ) {
                throw new RuntimeException( 'Native cleanup file removal failed.' );
            }
        }
        if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Native cleanup directory removal failed.' ); }
    }
    $deleted = $wpdb->delete( ran_booster_table_name(), array(
        'package' => $identifier, 'type' => 'plugin' === $type ? 1 : 2,
        'provider' => 'gh', 'provider_repository_id' => (string) ( 940000 + $number ),
        'repository' => 'ran-booster-c4/' . $name,
    ) );
    if ( false === $deleted || $deleted > 1 ) { throw new RuntimeException( 'Native cleanup row removal failed.' ); }
}
delete_option( 'ran_booster_c4_native_items' );
delete_option( 'ran_booster_c4_native_archives' );

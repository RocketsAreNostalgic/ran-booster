<?php

// Disposable-site proof for the raw-storage repository source invariant.
// phpcs:disable

use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

[$action, $run_id, $ready, $release, $result] = array_pad( $args, 5, '' );
if ( ! in_array( $action, array( 'setup', 'branch', 'release', 'assert', 'cleanup' ), true ) || preg_match( '/^[a-f0-9]{24}$/D', $run_id ) !== 1 ) {
	throw new RuntimeException( 'Invalid repository-exclusivity race arguments.' );
}
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'WP_CONTENT_DIR' ) || ! str_starts_with( WP_PLUGIN_DIR, WP_CONTENT_DIR . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'This is not the validated disposable WordPress content root.' );
}
$plugin_dir  = WP_PLUGIN_DIR . '/exclusivity-root-' . $run_id;
$plugin_file = $plugin_dir . '/exclusivity-root.php';
$package     = 'exclusivity-root-' . $run_id . '/exclusivity-root.php';
$theme_dir   = WP_CONTENT_DIR . '/themes/exclusivity-theme-' . $run_id;
$theme       = 'exclusivity-theme-' . $run_id;
$table       = ran_booster_table_name();

if ( 'setup' === $action ) {
	if ( ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
		|| 'RAN Booster disposable test site' !== trim( (string) file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) )
		|| realpath( ABSPATH ) === realpath( '/Users/anachronistic/Local Sites/pns-stageing/app/public' )
		|| is_link( WP_PLUGIN_DIR ) || is_link( WP_CONTENT_DIR ) ) {
		throw new RuntimeException( 'The exact disposable-site marker and paths were not verified.' );
	}
	wp_mkdir_p( $plugin_dir );
	wp_mkdir_p( $theme_dir );
	file_put_contents( $plugin_file, "<?php\n/*\nPlugin Name: Exclusivity Root\nVersion: 1.0.0\nUpdate URI: https://github.com/example/exclusivity-fixture\n*/\n" );
	file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: Exclusivity Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/example/exclusivity-fixture\n*/\n" );
	global $wpdb;
	$wpdb->delete( $table, array( 'package' => $package, 'type' => 1 ) );
	$wpdb->delete( $table, array( 'package' => $theme, 'type' => 2 ) );
	$wpdb->insert( $table, array( 'package' => $package, 'type' => 1, 'repository' => 'example/exclusivity-fixture', 'branch' => 'main', 'provider' => 'gh', 'provider_repository_id' => 'race-' . $run_id, 'private' => 0, 'credential_id' => null, 'deployment_policy' => 'manual', 'source' => 'branch', 'source_revision' => 1, 'subdirectory' => null, 'release_configuration' => null ) );
	return;
}
if ( 'cleanup' === $action ) {
	global $wpdb;
	$wpdb->delete( $table, array( 'package' => $package, 'type' => 1 ) );
	$wpdb->delete( $table, array( 'package' => $theme, 'type' => 2 ) );
	@unlink( $plugin_file );
	@rmdir( $plugin_dir );
	@unlink( $theme_dir . '/style.css' );
	@rmdir( $theme_dir );
	return;
}
if ( 'assert' === $action ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source FROM %i WHERE provider = %s AND provider_repository_id = %s', $table, 'gh', 'race-' . $run_id ) );
	$results = array_map( static fn( string $path ): array => json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ), array( $ready, $release ) );
	$successes = count( array_filter( $results, static fn( array $entry ): bool => true === ( $entry['ok'] ?? null ) ) );
	if ( 1 !== $successes || ! is_array( $rows ) || ( 1 === count( $rows ) && 'release_asset' !== ( $rows[0]->source ?? null ) ) || ( 2 === count( $rows ) && count( array_filter( $rows, static fn( object $row ): bool => 'branch' === ( $row->source ?? null ) ) ) !== 2 ) || ! in_array( count( $rows ), array( 1, 2 ), true ) ) {
		throw new RuntimeException( 'The concurrent persistence operations created a mixed or missing repository group.' );
	}
	return;
}

foreach ( array( $ready, $release, $result ) as $path ) {
	if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException( 'Invalid race marker path.' );
	}
}
global $wpdb;
$database = new class( $wpdb, $ready, $release ) {
	public string $last_error = '';
	public string $options;
	public string $prefix;
	public string $base_prefix;
	private bool $paused = false;
	public function __construct( private object $wpdb, private string $ready, private string $release ) { $this->options = $wpdb->options; $this->prefix = $wpdb->prefix; $this->base_prefix = $wpdb->base_prefix; }
	public function db_server_info(): string { return (string) $this->wpdb->db_server_info(); }
	public function suppress_errors( bool $suppress = true ): bool { return (bool) $this->wpdb->suppress_errors( $suppress ); }
	public function __call( string $name, array $arguments ): mixed { $value = $this->wpdb->{$name}( ...$arguments ); $this->last_error = (string) $this->wpdb->last_error; return $value; }
	public function get_results( string $query ): array|object|null {
		if ( ! $this->paused && str_contains( $query, 'provider_repository_id') && str_contains( $query, 'FOR UPDATE' ) ) {
			$this->paused = true; $handle = fopen( $this->ready, 'x' ); if ( false === $handle ) { throw new RuntimeException( 'Barrier failed.' ); } fclose( $handle);
			$deadline = microtime( true ) + 15; while ( ! file_exists( $this->release ) ) { if ( microtime( true ) >= $deadline ) { throw new RuntimeException( 'Barrier timed out.' ); } usleep( 50000 ); }
		}
		$value = $this->wpdb->get_results( $query ); $this->last_error = (string) $this->wpdb->last_error; return $value;
	}
};
$wpdb = $database;
$ok = false;
if ( 'release' === $action ) {
	$ok = ( new ManagedReleaseStore( $database ) )->transition( 'plugin', $package, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( 'exclusivity-root', 'exclusivity-root.php' ), 1 );
} else {
	$wp_theme = wp_get_theme( $theme );
	$managed = Theme::fromWpThemeObject( $wp_theme );
	$managed->setRepository( new ManagedRepository( 'gh', 'example/exclusivity-fixture', 'race-' . $run_id, 'main' ) );
	$ok = ( new ThemeRepository() )->adopt( $managed )->isSuccessful();
}
file_put_contents( $result, wp_json_encode( array( 'action' => $action, 'ok' => $ok ) ) );

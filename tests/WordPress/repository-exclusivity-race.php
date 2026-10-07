<?php

/** @var list<string> $args Arguments injected by WP-CLI eval-file. */

// Disposable-site proof for the raw-storage repository source invariant.

use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
[$action, $ran_booster_run_id, $ran_booster_ready, $ran_booster_release, $ran_booster_result] = array_pad( $args, 5, '' );
if ( ! in_array( $action, array( 'setup', 'branch', 'release', 'assert', 'cleanup' ), true ) || preg_match( '/^[a-f0-9]{24}$/D', $ran_booster_run_id ) !== 1 ) {
	throw new RuntimeException( 'Invalid repository-exclusivity race arguments.' );
}
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'WP_CONTENT_DIR' ) || ! str_starts_with( WP_PLUGIN_DIR, WP_CONTENT_DIR . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'This is not the validated disposable WordPress content root.' );
}
$ran_booster_plugin_dir  = WP_PLUGIN_DIR . '/exclusivity-root-' . $ran_booster_run_id;
$ran_booster_plugin_file = $ran_booster_plugin_dir . '/exclusivity-root.php';
$ran_booster_package     = 'exclusivity-root-' . $ran_booster_run_id . '/exclusivity-root.php';
$ran_booster_theme_dir   = WP_CONTENT_DIR . '/themes/exclusivity-theme-' . $ran_booster_run_id;
$ran_booster_theme       = 'exclusivity-theme-' . $ran_booster_run_id;
$ran_booster_table       = ran_booster_table_name();
$ran_booster_protected   = getenv( 'RAN_BOOSTER_PROTECTED_ROOT' );
$ran_booster_protected   = is_string( $ran_booster_protected ) && '' !== trim( $ran_booster_protected ) ? realpath( $ran_booster_protected ) : false;

if ( 'setup' === $action ) {
	if ( ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
		|| 'RAN Booster disposable test site' !== trim( (string) file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) )
		|| ( false !== $ran_booster_protected && realpath( ABSPATH ) === $ran_booster_protected )
		|| is_link( WP_PLUGIN_DIR ) || is_link( WP_CONTENT_DIR ) ) {
		throw new RuntimeException( 'The exact disposable-site marker and paths were not verified.' );
	}
	wp_mkdir_p( $ran_booster_plugin_dir );
	wp_mkdir_p( $ran_booster_theme_dir );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	file_put_contents( $ran_booster_plugin_file, "<?php\n/*\nPlugin Name: Exclusivity Root\nVersion: 1.0.0\nUpdate URI: https://github.com/example/exclusivity-fixture\n*/\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	file_put_contents( $ran_booster_theme_dir . '/style.css', "/*\nTheme Name: Exclusivity Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/example/exclusivity-fixture\n*/\n" );
	global $wpdb;
	$wpdb->delete(
		$ran_booster_table,
		array(
			'package' => $ran_booster_package,
			'type'    => 1,
		)
	);
	$wpdb->delete(
		$ran_booster_table,
		array(
			'package' => $ran_booster_theme,
			'type'    => 2,
		)
	);
	$wpdb->insert(
		$ran_booster_table,
		array(
			'package'                => $ran_booster_package,
			'type'                   => 1,
			'repository'             => 'example/exclusivity-fixture',
			'branch'                 => 'main',
			'provider'               => 'gh',
			'provider_repository_id' => 'race-' . $ran_booster_run_id,
			'private'                => 0,
			'credential_id'          => null,
			'deployment_policy'      => 'manual',
			'source'                 => 'branch',
			'source_revision'        => 1,
			'subdirectory'           => null,
			'release_configuration'  => null,
		)
	);
	return;
}
if ( 'cleanup' === $action ) {
	global $wpdb;
	$wpdb->delete(
		$ran_booster_table,
		array(
			'package' => $ran_booster_package,
			'type'    => 1,
		)
	);
	$wpdb->delete(
		$ran_booster_table,
		array(
			'package' => $ran_booster_theme,
			'type'    => 2,
		)
	);
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup tolerates an already-removed disposable fixture or stopped child process. Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	@unlink( $ran_booster_plugin_file );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup tolerates an already-removed disposable fixture or stopped child process. Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	@rmdir( $ran_booster_plugin_dir );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup tolerates an already-removed disposable fixture or stopped child process. Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	@unlink( $ran_booster_theme_dir . '/style.css' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup tolerates an already-removed disposable fixture or stopped child process. Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	@rmdir( $ran_booster_theme_dir );
	return;
}
if ( 'assert' === $action ) {
	global $wpdb;
	$ran_booster_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source FROM %i WHERE provider = %s AND provider_repository_id = %s', $ran_booster_table, 'gh', 'race-' . $ran_booster_run_id ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	$ran_booster_results   = array_map( static fn( string $path ): array => json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ), array( $ran_booster_ready, $ran_booster_release ) );
	$ran_booster_successes = count( array_filter( $ran_booster_results, static fn( array $entry ): bool => true === ( $entry['ok'] ?? null ) ) );
	if ( 1 !== $ran_booster_successes || ! is_array( $ran_booster_rows ) || ( 1 === count( $ran_booster_rows ) && 'release_asset' !== ( $ran_booster_rows[0]->source ?? null ) ) || ( 2 === count( $ran_booster_rows ) && count( array_filter( $ran_booster_rows, static fn( object $row ): bool => 'branch' === ( $row->source ?? null ) ) ) !== 2 ) || ! in_array( count( $ran_booster_rows ), array( 1, 2 ), true ) ) {
		throw new RuntimeException( 'The concurrent persistence operations created a mixed or missing repository group.' );
	}
	return;
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
foreach ( array( $ran_booster_ready, $ran_booster_release, $ran_booster_result ) as $path ) {
	if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException( 'Invalid race marker path.' );
	}
}
global $wpdb;
$ran_booster_database = new class( $wpdb, $ran_booster_ready, $ran_booster_release ) {
	public string $last_error = '';
	public string $options;
	public string $prefix;
	public string $base_prefix;
	private bool $paused = false;
	public function __construct( private object $wpdb, private string $ready, private string $release ) {
		$this->options     = $wpdb->options;
		$this->prefix      = $wpdb->prefix;
		$this->base_prefix = $wpdb->base_prefix; }
	public function db_server_info(): string {
		return (string) $this->wpdb->db_server_info(); }
	public function suppress_errors( bool $suppress = true ): bool {
		return (bool) $this->wpdb->suppress_errors( $suppress ); }
	public function __call( string $name, array $arguments ): mixed {
		$value            = $this->wpdb->{$name}( ...$arguments );
		$this->last_error = (string) $this->wpdb->last_error;
		return $value; }
	public function get_results( string $query ): array|object|null {
		if ( ! $this->paused && str_contains( $query, 'provider_repository_id' ) && str_contains( $query, 'FOR UPDATE' ) ) {
			$this->paused = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			$handle = fopen( $this->ready, 'x' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Barrier failed.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			} fclose( $handle );
			$deadline = microtime( true ) + 15;
			while ( ! file_exists( $this->release ) ) {
				if ( microtime( true ) >= $deadline ) {
					throw new RuntimeException( 'Barrier timed out.' );
				} usleep( 50000 ); }
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is assembled from fixed fixture SQL and already-prepared values before this read.
		$value            = $this->wpdb->get_results( $query );
		$this->last_error = (string) $this->wpdb->last_error;
		return $value;
	}
};
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$wpdb           = $ran_booster_database;
$ran_booster_ok = false;
if ( 'release' === $action ) {
	$ran_booster_ok = ( new ManagedReleaseStore( $ran_booster_database ) )->transition( 'plugin', $ran_booster_package, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( 'exclusivity-root', 'exclusivity-root.php' ), 1 );
} else {
	$ran_booster_wp_theme = wp_get_theme( $ran_booster_theme );
	$ran_booster_managed  = Theme::from_wp_theme_object( wp_theme: $ran_booster_wp_theme );
	$ran_booster_managed->set_repository( new ManagedRepository( 'gh', 'example/exclusivity-fixture', 'race-' . $ran_booster_run_id, 'main' ) );
	$ran_booster_ok = ( new ThemeRepository() )->adopt( $ran_booster_managed )->is_successful();
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
file_put_contents(
	$ran_booster_result,
	wp_json_encode(
		array(
			'action' => $action,
			'ok'     => $ran_booster_ok,
		)
	)
);

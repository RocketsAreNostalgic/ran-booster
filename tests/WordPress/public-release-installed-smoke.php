<?php

// Real installed Core facade, public updater API, controlled GitHub responses.

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

$ran_booster_assert       = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( $message ); }
};
$ran_booster_site         = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
$ran_booster_archive_root = realpath( (string) getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) );
if ( ! ( defined( 'WP_CLI' ) && WP_CLI && current_user_can( 'manage_options' )
	&& '1' === getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' )
	&& false !== $ran_booster_site && rtrim( ABSPATH, '/' ) === $ran_booster_site
	&& WP_CONTENT_DIR === $ran_booster_site . '/wp-content' && WP_PLUGIN_DIR === $ran_booster_site . '/wp-content/plugins'
	&& get_theme_root() === $ran_booster_site . '/wp-content/themes' && 'http://localhost' === get_option( 'siteurl' )
	&& is_file( $ran_booster_site . '/.ran-booster-disposable-test-site' ) && ! is_link( $ran_booster_site . '/.ran-booster-disposable-test-site' ) ) ) {
	throw new RuntimeException( 'Public release proof requires the exact marked CI site.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
$ran_booster_marker = file_get_contents( $ran_booster_site . '/.ran-booster-disposable-test-site' );
if ( ! is_string( $ran_booster_marker ) || 'RAN Booster disposable test site' !== trim( $ran_booster_marker ) || false === $ran_booster_archive_root ) {
	throw new RuntimeException( 'Public release proof requires the exact marked CI site.' );
}
foreach ( array( $ran_booster_site, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), WP_PLUGIN_DIR . '/ran-booster', $ran_booster_archive_root ) as $ran_booster_root ) {
	$ran_booster_assert( ! is_link( $ran_booster_root ) && realpath( $ran_booster_root ) === $ran_booster_root, 'Public release proof refuses shared roots.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
global $wp_filesystem;
$ran_booster_assert( ! defined( 'FS_METHOD' ) && ! isset( $wp_filesystem ), 'Public release proof requires an unconfigured direct WordPress filesystem.' );
$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_facade    = $ran_booster_container->make( ProspectiveReleaseFacade::class );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$plugins                    = $ran_booster_container->make( PluginRepository::class );
$ran_booster_themes         = $ran_booster_container->make( ThemeRepository::class );
$ran_booster_counts         = array(
	'http'       => 0,
	'http_bytes' => 0,
	'zip'        => 0,
	'zip_bytes'  => 0,
);
$ran_booster_streams        = array();
$ran_booster_fixtures       = array();
$ran_booster_measurements   = array();
$ran_booster_selected_theme = get_stylesheet();
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$ran_booster_slug      = 'ran-booster-c4-prospective-' . $type;
	$ran_booster_directory = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $ran_booster_slug;
	$ran_booster_assert( ! file_exists( $ran_booster_directory ) && ! is_link( $ran_booster_directory ), 'Prospective target already exists.' );
	$ran_booster_repository = 'ran-booster-c4/' . $ran_booster_slug;
	$ran_booster_metadata   = 'plugin' === $type ? $ran_booster_slug . '.php' : 'style.css';
	$ran_booster_contents   = 'plugin' === $type
		? "<?php\n/*\nPlugin Name: C4 prospective plugin\nVersion: 2.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$ran_booster_repository\n*/\n"
		: "/*\nTheme Name: C4 prospective theme\nVersion: 2.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$ran_booster_repository\n*/\n";
	$ran_booster_archive    = $ran_booster_archive_root . '/' . $ran_booster_slug . '.zip';
	$ran_booster_assert( ! file_exists( $ran_booster_archive ) && ! is_link( $ran_booster_archive ), 'Prospective archive already exists.' );
	$ran_booster_zip = new ZipArchive();
	$ran_booster_assert( true === $ran_booster_zip->open( $ran_booster_archive, ZipArchive::CREATE ), 'Cannot create prospective ZIP.' );
	$ran_booster_zip->addFromString( $ran_booster_slug . '/' . $ran_booster_metadata, $ran_booster_contents );
	if ( 'theme' === $type ) {
		$ran_booster_zip->addFromString( $ran_booster_slug . '/index.php', '<?php' ); }
	$ran_booster_zip->close();
	$ran_booster_fixtures[ $type ] = array(
		'slug'       => $ran_booster_slug,
		'directory'  => $ran_booster_directory,
		'repository' => $ran_booster_repository,
		'metadata'   => $ran_booster_metadata,
		'archive'    => $ran_booster_archive,
		'contents'   => $ran_booster_contents,
	);
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
$ran_booster_http = static function ( mixed $pre, array $args, string $url ) use ( &$ran_booster_counts, &$ran_booster_streams, $ran_booster_fixtures ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
	$path = parse_url( $url, PHP_URL_PATH );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
	if ( 'api.github.com' !== parse_url( $url, PHP_URL_HOST ) ) {
		return new WP_Error( 'c4_external_http_denied' ); }
	foreach ( $ran_booster_fixtures as $type => $ran_booster_fixture ) {
		$repo = $ran_booster_fixture['repository'];
		$base = '/repos/' . $repo;
		$id   = 'plugin' === $type ? 951001 : 951002;
		if ( '/repositories/' . $id !== $path && $path !== $base && ! str_starts_with( (string) $path, $base . '/' ) ) {
			continue; }
		++$ran_booster_counts['http'];
		$release = array(
			'id'           => 42,
			'tag_name'     => 'v2.0.0',
			'draft'        => false,
			'prerelease'   => false,
			'immutable'    => true,
			'published_at' => '2026-09-06T00:00:00Z',
			'html_url'     => 'https://github.com/' . $repo . '/releases/tag/v2.0.0',
			'assets'       => array(
				array(
					'id'     => 43,
					'name'   => basename( $ran_booster_fixture['archive'] ),
					'state'  => 'uploaded',
					'size'   => filesize( $ran_booster_fixture['archive'] ),
					'digest' => 'sha256:' . hash_file( 'sha256', $ran_booster_fixture['archive'] ),
				),
			),
		);
		if ( true === ( $args['stream'] ?? false ) ) {
			if ( $path !== $base . '/releases/assets/43' || ! is_string( $args['filename'] ?? null ) || ! copy( $ran_booster_fixture['archive'], $args['filename'] ) ) {
				return new WP_Error( 'c4_stream_invalid' ); }
			$ran_booster_streams[] = $args['filename'];
			++$ran_booster_counts['zip'];
			$ran_booster_counts['zip_bytes'] += filesize( $ran_booster_fixture['archive'] );
			$body                             = '';
		} elseif ( $path === $base || '/repositories/' . $id === $path ) {
			$body = wp_json_encode(
				array(
					'id'             => $id,
					'name'           => $ran_booster_fixture['slug'],
					'full_name'      => $repo,
					'private'        => false,
					'default_branch' => 'main',
					'html_url'       => 'https://github.com/' . $repo,
					'owner'          => array( 'login' => 'ran-booster-c4' ),
				)
			);
		} elseif ( $path === $base . '/releases' ) {
			$body = wp_json_encode( array( $release ) );
		} elseif ( $path === $base . '/releases/42' || $path === $base . '/releases/tags/v2.0.0' ) {
			$body = wp_json_encode( $release );
		} elseif ( is_string( $path ) && str_starts_with( $path, $base . '/commits/' ) ) {
			$body = wp_json_encode( array( 'sha' => str_repeat( 'a', 40 ) ) );
		} else {
			return new WP_Error( 'c4_route_invalid' ); }
		if ( ! is_string( $body ) ) {
			throw new RuntimeException( 'The public release fixture response could not be encoded.' );
		}
		$ran_booster_counts['http_bytes'] += strlen( $body );
		return array(
			'body'     => $body,
			'headers'  => array(),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'filename' => $args['filename'] ?? null,
		);
	}
	return new WP_Error( 'c4_repository_invalid' );
};
add_filter( 'pre_http_request', $ran_booster_http, PHP_INT_MIN, 3 );
$ran_booster_clean_streams = static function () use ( &$ran_booster_streams, $ran_booster_assert ): void {
	foreach ( $ran_booster_streams as $path ) {
		$ran_booster_assert( ! file_exists( $path ) && ! is_link( $path ), 'Public updater retained an acquired ZIP.' ); }
};
$ran_booster_successful    = static function ( RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult $ran_booster_result, string $code ) use ( $ran_booster_assert ): void {
	$ran_booster_assert( $ran_booster_result->successful() && $code === $ran_booster_result->code(), 'Public prospective result: ' . $ran_booster_result->code() . ', expected ' . $code );
};
try {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $ran_booster_fixtures as $type => $ran_booster_fixture ) {
		$ran_booster_request = array(
			'provider'      => 'gh',
			'repository'    => $ran_booster_fixture['repository'],
			'credential_id' => '',
			'branch'        => 'main',
		);
		$ran_booster_nonce   = static fn ( string $operation ): string => wp_create_nonce( $ran_booster_facade->nonce_action( $operation, $type ) );
		$ran_booster_before  = $ran_booster_counts;
		$ran_booster_list    = $ran_booster_facade->list_candidates( $type, $ran_booster_request, 'stable', $ran_booster_nonce( 'list_candidates' ) );
		$ran_booster_successful( $ran_booster_list, 'release_candidates_available' );
		$ran_booster_assert( $wp_filesystem instanceof WP_Filesystem_Direct, 'Core did not establish the direct WordPress filesystem for release operations.' );
		$ran_booster_assert( '42' === ( $ran_booster_list->data()['candidates'][0]['release_id'] ?? null ) && $ran_booster_counts['zip'] === $ran_booster_before['zip'], 'Listing lost exact identity or downloaded a ZIP.' );
		$ran_booster_inspection = $ran_booster_facade->inspect( $type, $ran_booster_request, '42', 'v2.0.0', 'stable', $ran_booster_nonce( 'inspect' ) );
		$ran_booster_successful( $ran_booster_inspection, 'release_ready' );
		$ran_booster_fingerprint = $ran_booster_inspection->data()['fingerprint'] ?? '';
		$ran_booster_assert( 1 === preg_match( '/\Av2:[a-f0-9]{64}\z/D', $ran_booster_fingerprint ) && $ran_booster_counts['zip'] === $ran_booster_before['zip'] + 1, 'Inspection did not verify exactly one ZIP.' );
		$ran_booster_clean_streams();
		$ran_booster_identifier = 'plugin' === $type ? $ran_booster_fixture['slug'] . '/' . $ran_booster_fixture['metadata'] : $ran_booster_fixture['slug'];
		$ran_booster_veto       = static fn (): WP_Error => new WP_Error( 'c4_post_acquisition_veto' );
		add_filter( 'upgrader_pre_install', $ran_booster_veto, PHP_INT_MIN, 2 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		try {
			$ran_booster_failed = $ran_booster_facade->install( $type, $ran_booster_request, '42', 'v2.0.0', $ran_booster_fingerprint, 'stable', $ran_booster_nonce( 'install' ) ); } finally {
			remove_filter( 'upgrader_pre_install', $ran_booster_veto, PHP_INT_MIN ); }
			$ran_booster_assert( ! $ran_booster_failed->successful() && $ran_booster_counts['zip'] === $ran_booster_before['zip'] + 2 && ! file_exists( $ran_booster_fixture['directory'] ) && ! is_link( $ran_booster_fixture['directory'] ), 'Post-acquisition failure mutated or reused inspection bytes.' );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from the trusted Core table helper; package and type values use placeholders.
			$ran_booster_assert( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ran_booster_table_name() . ' WHERE package = %s AND type = %d', $ran_booster_identifier, 'plugin' === $type ? 1 : 2 ) ), 'Failed first install adopted a package record.' );
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
			$ran_booster_assert( $ran_booster_selected_theme === get_stylesheet() && ! ( 'plugin' === $type && is_plugin_active( $ran_booster_identifier ) ), 'Failed first install changed activation.' );
			$ran_booster_clean_streams();
			$ran_booster_result = $ran_booster_facade->install( $type, $ran_booster_request, '42', 'v2.0.0', $ran_booster_fingerprint, 'stable', $ran_booster_nonce( 'install' ) );
			$ran_booster_successful( $ran_booster_result, 'installed' );
			$ran_booster_assert( $ran_booster_counts['zip'] === $ran_booster_before['zip'] + 3, 'Installation must acquire exactly one fresh ZIP without pre-inspection.' );
			$ran_booster_assert( hash_equals( hash( 'sha256', $ran_booster_fixture['contents'] ), (string) hash_file( 'sha256', $ran_booster_fixture['directory'] . '/' . $ran_booster_fixture['metadata'] ) ), 'Installed prospective bytes differ from the verified ZIP.' );
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
			$ran_booster_assert( $ran_booster_selected_theme === get_stylesheet() && ! ( 'plugin' === $type && is_plugin_active( $ran_booster_identifier ) ), 'New prospective target became active.' );
			$ran_booster_package = 'plugin' === $type ? $plugins->booster_plugin_from_file( $ran_booster_identifier ) : $ran_booster_themes->booster_theme_from_stylesheet( $ran_booster_identifier );
			$ran_booster_assert( '2.0.0' === $ran_booster_package->get_version() && RAN\PackageSource::RELEASE_ASSET === $ran_booster_package->get_source(), 'Successful prospective install was not adopted exactly.' );
			$ran_booster_clean_streams();
			$ran_booster_measurements[ $type ] = array_map( static fn ( string $key ): int => $ran_booster_counts[ $key ] - $ran_booster_before[ $key ], array_keys( $ran_booster_counts ) );
	}
} finally {
	remove_filter( 'pre_http_request', $ran_booster_http, PHP_INT_MIN );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $ran_booster_fixtures as $type => $ran_booster_fixture ) {
		$ran_booster_identifier = 'plugin' === $type ? $ran_booster_fixture['slug'] . '/' . $ran_booster_fixture['metadata'] : $ran_booster_fixture['slug'];
		$ran_booster_assert( ! is_link( $ran_booster_fixture['directory'] ), 'Prospective cleanup refuses a replaced target.' );
		if ( is_dir( $ran_booster_fixture['directory'] ) ) {
			( 'plugin' === $type ? $plugins : $ran_booster_themes )->unlink( $ran_booster_identifier )->require_success();
			$ran_booster_removed = 'plugin' === $type ? delete_plugins( array( $ran_booster_identifier ) ) : delete_theme( $ran_booster_identifier );
			$ran_booster_assert( ! is_wp_error( $ran_booster_removed ) && false !== $ran_booster_removed && ! file_exists( $ran_booster_fixture['directory'] ), 'Prospective fixture cleanup failed.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$ran_booster_assert( unlink( $ran_booster_fixture['archive'] ), 'Prospective archive cleanup failed.' );
	}
}
WP_CLI::success( // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	wp_json_encode(
		array(
			'public_github_prospective' => $ran_booster_measurements,
			'columns'                   => array_keys( $ran_booster_counts ),
			'cleanup'                   => 'complete',
		)
	)
);

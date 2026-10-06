<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolated CLI harness locals do not enter shipped plugin scope; declarations and hooks remain checked.

// Real installed Core facade, public updater API, controlled GitHub responses.

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

$assert       = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( $message ); }
};
$site         = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
$archive_root = realpath( (string) getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) );
$assert(
	defined( 'WP_CLI' ) && WP_CLI && current_user_can( 'manage_options' )
	&& '1' === getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' )
	&& false !== $site && rtrim( ABSPATH, '/' ) === $site
	&& WP_CONTENT_DIR === $site . '/wp-content' && WP_PLUGIN_DIR === $site . '/wp-content/plugins'
	&& get_theme_root() === $site . '/wp-content/themes' && 'http://localhost' === get_option( 'siteurl' )
	&& is_file( $site . '/.ran-booster-disposable-test-site' ) && ! is_link( $site . '/.ran-booster-disposable-test-site' )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	&& 'RAN Booster disposable test site' === trim( file_get_contents( $site . '/.ran-booster-disposable-test-site' ) )
	&& false !== $archive_root,
	'Public release proof requires the exact marked CI site.'
);
foreach ( array( $site, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), WP_PLUGIN_DIR . '/ran-booster', $archive_root ) as $root ) {
	$assert( ! is_link( $root ) && realpath( $root ) === $root, 'Public release proof refuses shared roots.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
global $wp_filesystem;
$assert( ! defined( 'FS_METHOD' ) && ! isset( $wp_filesystem ), 'Public release proof requires an unconfigured direct WordPress filesystem.' );
$container = require __DIR__ . '/core-container-fixture.php';
$facade    = $container->make( ProspectiveReleaseFacade::class );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$plugins        = $container->make( PluginRepository::class );
$themes         = $container->make( ThemeRepository::class );
$counts         = array(
	'http'       => 0,
	'http_bytes' => 0,
	'zip'        => 0,
	'zip_bytes'  => 0,
);
$streams        = array();
$fixtures       = array();
$measurements   = array();
$selected_theme = get_stylesheet();
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$slug      = 'ran-booster-c4-prospective-' . $type;
	$directory = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $slug;
	$assert( ! file_exists( $directory ) && ! is_link( $directory ), 'Prospective target already exists.' );
	$repository = 'ran-booster-c4/' . $slug;
	$metadata   = 'plugin' === $type ? $slug . '.php' : 'style.css';
	$contents   = 'plugin' === $type
		? "<?php\n/*\nPlugin Name: C4 prospective plugin\nVersion: 2.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$repository\n*/\n"
		: "/*\nTheme Name: C4 prospective theme\nVersion: 2.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$repository\n*/\n";
	$archive    = $archive_root . '/' . $slug . '.zip';
	$assert( ! file_exists( $archive ) && ! is_link( $archive ), 'Prospective archive already exists.' );
	$zip = new ZipArchive();
	$assert( true === $zip->open( $archive, ZipArchive::CREATE ), 'Cannot create prospective ZIP.' );
	$zip->addFromString( $slug . '/' . $metadata, $contents );
	if ( 'theme' === $type ) {
		$zip->addFromString( $slug . '/index.php', '<?php' ); }
	$zip->close();
	$fixtures[ $type ] = compact( 'slug', 'directory', 'repository', 'metadata', 'archive', 'contents' );
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
$http = static function ( mixed $pre, array $args, string $url ) use ( &$counts, &$streams, $fixtures ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
	$path = parse_url( $url, PHP_URL_PATH );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
	if ( 'api.github.com' !== parse_url( $url, PHP_URL_HOST ) ) {
		return new WP_Error( 'c4_external_http_denied' ); }
	foreach ( $fixtures as $type => $fixture ) {
		$repo = $fixture['repository'];
		$base = '/repos/' . $repo;
		$id   = 'plugin' === $type ? 951001 : 951002;
		if ( '/repositories/' . $id !== $path && $path !== $base && ! str_starts_with( (string) $path, $base . '/' ) ) {
			continue; }
		++$counts['http'];
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
					'name'   => basename( $fixture['archive'] ),
					'state'  => 'uploaded',
					'size'   => filesize( $fixture['archive'] ),
					'digest' => 'sha256:' . hash_file( 'sha256', $fixture['archive'] ),
				),
			),
		);
		if ( true === ( $args['stream'] ?? false ) ) {
			if ( $path !== $base . '/releases/assets/43' || ! is_string( $args['filename'] ?? null ) || ! copy( $fixture['archive'], $args['filename'] ) ) {
				return new WP_Error( 'c4_stream_invalid' ); }
			$streams[] = $args['filename'];
			++$counts['zip'];
			$counts['zip_bytes'] += filesize( $fixture['archive'] );
			$body                 = '';
		} elseif ( $path === $base || '/repositories/' . $id === $path ) {
			$body = wp_json_encode(
				array(
					'id'             => $id,
					'name'           => $fixture['slug'],
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
		} elseif ( str_starts_with( $path, $base . '/commits/' ) ) {
			$body = wp_json_encode( array( 'sha' => str_repeat( 'a', 40 ) ) );
		} else {
			return new WP_Error( 'c4_route_invalid' ); }
		$counts['http_bytes'] += strlen( $body );
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
add_filter( 'pre_http_request', $http, PHP_INT_MIN, 3 );
$clean_streams = static function () use ( &$streams, $assert ): void {
	foreach ( $streams as $path ) {
		$assert( ! file_exists( $path ) && ! is_link( $path ), 'Public updater retained an acquired ZIP.' ); }
};
$successful    = static function ( object $result, string $code ) use ( $assert ): void {
	$assert( $result->successful() && $code === $result->code(), 'Public prospective result: ' . $result->code() . ', expected ' . $code );
};
try {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $fixtures as $type => $fixture ) {
		$request = array(
			'provider'      => 'gh',
			'repository'    => $fixture['repository'],
			'credential_id' => '',
			'branch'        => 'main',
		);
		$nonce   = static fn ( string $operation ): string => wp_create_nonce( $facade->nonce_action( $operation, $type ) );
		$before  = $counts;
		$list    = $facade->list_candidates( $type, $request, 'stable', $nonce( 'list_candidates' ) );
		$successful( $list, 'release_candidates_available' );
		$assert( $wp_filesystem instanceof WP_Filesystem_Direct, 'Core did not establish the direct WordPress filesystem for release operations.' );
		$assert( '42' === ( $list->data()['candidates'][0]['release_id'] ?? null ) && $counts['zip'] === $before['zip'], 'Listing lost exact identity or downloaded a ZIP.' );
		$inspection = $facade->inspect( $type, $request, '42', 'v2.0.0', 'stable', $nonce( 'inspect' ) );
		$successful( $inspection, 'release_ready' );
		$fingerprint = $inspection->data()['fingerprint'] ?? '';
		$assert( 1 === preg_match( '/\Av2:[a-f0-9]{64}\z/D', $fingerprint ) && $counts['zip'] === $before['zip'] + 1, 'Inspection did not verify exactly one ZIP.' );
		$clean_streams();
		$identifier = 'plugin' === $type ? $fixture['slug'] . '/' . $fixture['metadata'] : $fixture['slug'];
		$veto       = static fn (): WP_Error => new WP_Error( 'c4_post_acquisition_veto' );
		add_filter( 'upgrader_pre_install', $veto, PHP_INT_MIN, 2 );
		try {
			$failed = $facade->install( $type, $request, '42', 'v2.0.0', $fingerprint, 'stable', $nonce( 'install' ) ); } finally {
			remove_filter( 'upgrader_pre_install', $veto, PHP_INT_MIN ); }
			$assert( ! $failed->successful() && $counts['zip'] === $before['zip'] + 2 && ! file_exists( $fixture['directory'] ) && ! is_link( $fixture['directory'] ), 'Post-acquisition failure mutated or reused inspection bytes.' );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from the trusted Core table helper; package and type values use placeholders.
			$assert( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ran_booster_table_name() . ' WHERE package = %s AND type = %d', $identifier, 'plugin' === $type ? 1 : 2 ) ), 'Failed first install adopted a package record.' );
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
			$assert( $selected_theme === get_stylesheet() && ! ( 'plugin' === $type && is_plugin_active( $identifier ) ), 'Failed first install changed activation.' );
			$clean_streams();
			$result = $facade->install( $type, $request, '42', 'v2.0.0', $fingerprint, 'stable', $nonce( 'install' ) );
			$successful( $result, 'installed' );
			$assert( $counts['zip'] === $before['zip'] + 3, 'Installation must acquire exactly one fresh ZIP without pre-inspection.' );
			$assert( hash_equals( hash( 'sha256', $fixture['contents'] ), (string) hash_file( 'sha256', $fixture['directory'] . '/' . $fixture['metadata'] ) ), 'Installed prospective bytes differ from the verified ZIP.' );
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
			$assert( $selected_theme === get_stylesheet() && ! ( 'plugin' === $type && is_plugin_active( $identifier ) ), 'New prospective target became active.' );
			$package = 'plugin' === $type ? $plugins->booster_plugin_from_file( $identifier ) : $themes->booster_theme_from_stylesheet( $identifier );
			$assert( '2.0.0' === $package->get_version() && RAN\PackageSource::RELEASE_ASSET === $package->get_source(), 'Successful prospective install was not adopted exactly.' );
			$clean_streams();
			$measurements[ $type ] = array_map( static fn ( string $key ): int => $counts[ $key ] - $before[ $key ], array_keys( $counts ) );
	}
} finally {
	remove_filter( 'pre_http_request', $http, PHP_INT_MIN );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $fixtures as $type => $fixture ) {
		$identifier = 'plugin' === $type ? $fixture['slug'] . '/' . $fixture['metadata'] : $fixture['slug'];
		$assert( ! is_link( $fixture['directory'] ), 'Prospective cleanup refuses a replaced target.' );
		if ( is_dir( $fixture['directory'] ) ) {
			( 'plugin' === $type ? $plugins : $themes )->unlink( $identifier )->require_success();
			$removed = 'plugin' === $type ? delete_plugins( array( $identifier ) ) : delete_theme( $identifier );
			$assert( ! is_wp_error( $removed ) && false !== $removed && ! file_exists( $fixture['directory'] ), 'Prospective fixture cleanup failed.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$assert( unlink( $fixture['archive'] ), 'Prospective archive cleanup failed.' );
	}
}
WP_CLI::success(
	wp_json_encode(
		array(
			'public_github_prospective' => $measurements,
			'columns'                   => array_keys( $counts ),
			'cleanup'                   => 'complete',
		)
	)
);

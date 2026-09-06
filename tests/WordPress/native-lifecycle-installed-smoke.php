<?php

// A fresh WP-CLI request proves the installed Core's eager managed-target scan.
// phpcs:disable

use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The native lifecycle smoke requires the marked installed CI site.' );
}

$scale = getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' );
$items = get_option( 'ran_booster_c4_native_items', null );
$archives = get_option( 'ran_booster_c4_native_archives', null );
if ( ! is_string( $scale ) || ! in_array( $scale, array( '1', '5', '10', '20' ), true ) || ! is_array( $items ) || ! is_array( $archives ) || (int) $scale !== count( $items ) ) {
	throw new RuntimeException( 'The native lifecycle fixture state is invalid.' );
}

$counts = array( 'credentials' => 0, 'http' => 0, 'zip_bytes' => 0, 'blocked' => 0 );
add_filter(
	'pre_http_request',
	static function ( mixed $pre, array $args, string $url ) use ( $archives, &$counts ): mixed {
		$path = parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! str_starts_with( $path, '/repos/ran-booster-c4/' ) && ! str_starts_with( $path, '/repositories/' ) ) {
			++$counts['blocked'];
			return new WP_Error( 'ran_booster_c4_network_denied' );
		}
		++$counts['http'];
		if ( isset( $args['headers']['Authorization'] ) ) {
			++$counts['credentials'];
		}
		foreach ( $archives as $repository => $archive ) {
		if ( ! is_string( $repository ) || ! is_string( $archive ) ) {
			continue;
		}
		$repositoryNumber = (int) substr( $repository, strrpos( $repository, '-' ) + 1 );
		$repositoryId     = 940000 + $repositoryNumber;
		$releaseId        = hexdec( substr( sha1( $repository ), 0, 6 ) );
		$assetId          = $releaseId + 1;
		$repositoryPath   = '/repos/' . $repository;
		$release          = array( 'id' => $releaseId, 'tag_name' => 'v2.0.0', 'draft' => false, 'prerelease' => false, 'immutable' => true, 'published_at' => '2026-09-06T00:00:00Z', 'html_url' => 'https://github.com/' . $repository . '/releases/tag/v2.0.0', 'assets' => array( array( 'id' => $assetId, 'name' => basename( $archive ), 'size' => filesize( $archive ), 'state' => 'uploaded', 'digest' => 'sha256:' . hash_file( 'sha256', $archive ), 'browser_download_url' => 'https://api.github.com' . $repositoryPath . '/releases/assets/' . $assetId ) ) );
		$repositoryBody    = array( 'id' => $repositoryId, 'name' => basename( $repository ), 'full_name' => $repository, 'private' => false, 'default_branch' => 'main', 'owner' => array( 'login' => 'ran-booster-c4' ) );
		$repositoryMatch   = $path === $repositoryPath || $path === '/repositories/' . $repositoryId || str_starts_with( $path, $repositoryPath . '/' );
		if ( ! $repositoryMatch ) {
			continue;
		}
			if ( true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && copy( $archive, $args['filename'] ) ) {
				$counts['zip_bytes'] += filesize( $archive );
				$body = null;
			} elseif ( $path === $repositoryPath || $path === '/repositories/' . $repositoryId ) {
				$body = $repositoryBody;
			} elseif ( str_ends_with( $path, '/releases' ) ) {
				$body = array( $release );
			} elseif ( str_contains( $path, '/releases/' ) ) {
				$body = $release;
			} elseif ( str_contains( $path, '/commits/' ) ) {
				$body = array( 'sha' => str_repeat( 'a', 40 ) );
			} else {
				continue;
			}
			return array( 'body' => null === $body ? '' : wp_json_encode( $body ), 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'filename' => $args['filename'] ?? null );
		}
		return new WP_Error( 'ran_booster_c4_network_denied' );
	},
	PHP_INT_MIN,
	3
);

$origin = WP_PLUGIN_DIR . '/ran-booster/';
foreach ( array( GitHubProvider::class, RAN\Booster\GitHub\GitHubReleaseNativeTarget::class, RAN\WordPress\ManagedReleaseTargetRegistrar::class ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( ! is_string( $file ) || ! str_starts_with( $file, $origin ) ) {
		throw new RuntimeException( 'The native lifecycle class is not from the installed Core archive.' );
	}
}

$container = require __DIR__ . '/core-container-fixture.php';
$registry  = $container->make( ProviderRegistry::class );
$provider  = $registry->requireCapability( 'gh', RepositoryReleaseNativeTargets::class );
if ( ! $provider instanceof GitHubProvider ) {
	throw new RuntimeException( 'The installed Core did not select its built-in GitHub provider.' );
}

// The public beta.3 registrar only activates at this lifecycle boundary. The
// worker must observe WordPress' real lifecycle rather than synthesize it.
if ( 1 > did_action( 'after_setup_theme' ) ) {
	throw new RuntimeException( 'The installed request has not crossed the native activation boundary.' );
}
$targets = ( new ReflectionProperty( GitHubProvider::class, 'nativeTargets' ) )->getValue( $provider );
if ( ! is_array( $targets ) || count( $targets ) < count( $items ) ) {
	throw new RuntimeException( 'The fresh installed request did not register every managed GitHub target.' );
}

$active = 0;
foreach ( $items as $item ) {
	if ( ! is_array( $item ) || ! is_string( $item['type'] ?? null ) || ! is_string( $item['identifier'] ?? null ) ) {
		throw new RuntimeException( 'The native lifecycle item is malformed.' );
	}
	$key    = $item['type'] . ':' . $item['identifier'];
	$target = $targets[ $key ] ?? null;
	if ( ! is_object( $target ) ) {
		throw new RuntimeException( 'The installed Core target key is absent.' );
	}
	$status = $target->status();
	if ( ! $status->active ) {
		throw new RuntimeException( 'The beta.3 public registrar did not activate an exact target.' );
	}
	++$active;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_update_plugins();
wp_update_themes();
foreach ( array( 'plugin', 'theme' ) as $type ) {
	foreach ( $items as $item ) {
		if ( $type !== $item['type'] ) {
			continue;
		}
		$result = 'plugin' === $type
			? ( new Plugin_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $item['identifier'], array( 'clear_update_cache' => false ) )
			: ( new Theme_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $item['identifier'], array( 'clear_update_cache' => false ) );
		if ( true !== $result ) {
			throw new RuntimeException( 'The installed native ' . $type . ' update failed.' );
		}
		$version = 'plugin' === $type
			? ( get_plugin_data( WP_PLUGIN_DIR . '/' . $item['identifier'], false, false )['Version'] ?? '' )
			: ( get_file_data( get_theme_root() . '/' . $item['identifier'] . '/style.css', array( 'Version' => 'Version' ), 'theme' )['Version'] ?? '' );
		if ( '2.0.0' !== $version ) {
			throw new RuntimeException( 'The installed native ' . $type . ' version was not replaced.' );
		}
		$metadataPath = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $item['identifier'] : get_theme_root() . '/' . $item['identifier'] . '/style.css';
		if ( ! is_string( $item['expected_digest'] ?? null ) || ! hash_equals( $item['expected_digest'], (string) hash_file( 'sha256', $metadataPath ) ) ) {
			throw new RuntimeException( 'The installed native ' . $type . ' digest was not replaced.' );
		}
		break;
	}
}
if ( 1 > $counts['http'] || 1 > $counts['zip_bytes'] ) {
	throw new RuntimeException( 'The controlled GitHub fixture did not serve native metadata and ZIP bytes.' );
}

$bulk = apply_filters(
	'upgrader_pre_download',
	false,
	'fixture.zip',
	(object) array( 'bulk' => true, 'update_count' => 1, 'update_current' => 1 ),
	array( 'plugin' => 'ran-booster/ran-booster.php', 'action' => 'update', 'type' => 'plugin' )
);
if ( ! is_wp_error( $bulk ) || 'ran_booster_native_update_unsupported_context' !== $bulk->get_error_code() ) {
	throw new RuntimeException( 'The installed Core self bulk guard is unavailable.' );
}

require __DIR__ . '/native-lifecycle-installed-cleanup.php';

WP_CLI::success( wp_json_encode( array( 'scale' => (int) $scale, 'active' => $active, 'memory' => memory_get_peak_usage( true ), 'hooks' => did_action( 'after_setup_theme' ), 'http' => $counts ) ) );

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

$bootstrapProbe = $GLOBALS['ran_booster_c4_bootstrap_probe'] ?? null;
if ( ! is_array( $bootstrapProbe ) || ! isset( $bootstrapProbe['elapsed_ns'], $bootstrapProbe['native_hooks'] )
	|| 0 !== $bootstrapProbe['http'] || 0 !== $bootstrapProbe['zip'] || 0 !== $bootstrapProbe['authorization_headers'] ) {
	throw new RuntimeException( 'Declaration/activation performed remote or credential-bearing HTTP work.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
global $wp_filesystem;
if ( ! WP_Filesystem() || ! $wp_filesystem instanceof WP_Filesystem_Direct ) {
	throw new RuntimeException( 'The native lifecycle proof requires the direct WordPress filesystem.' );
}
$started = microtime( true );
$selfArchive = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) . '/ran-booster.zip';
if ( file_exists( $selfArchive ) || is_link( $selfArchive ) ) { throw new RuntimeException( 'Self-offer archive is not exclusively owned.' ); }
$selfZip = new ZipArchive();
if ( true !== $selfZip->open( $selfArchive, ZipArchive::CREATE ) ) { throw new RuntimeException( 'Self-offer archive creation failed.' ); }
$selfZip->addFromString( 'ran-booster/ran-booster.php', "<?php\n/*\nPlugin Name: RAN Booster\nVersion: 2.0.0-beta.1\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/RocketsAreNostalgic/ran-booster\n*/\n" );
$selfZip->close();
$archives['RocketsAreNostalgic/ran-booster'] = $selfArchive;
$counts  = array( 'credentials' => 0, 'http' => 0, 'http_bytes' => 0, 'wordpress_org' => 0, 'zip_bytes' => 0, 'zip_count' => 0, 'blocked' => 0 );
add_filter(
	'pre_http_request',
	static function ( mixed $pre, array $args, string $url ) use ( $archives, &$counts ): mixed {
		$host = parse_url( $url, PHP_URL_HOST );
		$path = parse_url( $url, PHP_URL_PATH );
		if ( 'api.wordpress.org' === $host && in_array( $path, array( '/plugins/update-check/1.1/', '/themes/update-check/1.1/' ), true ) ) {
			++$counts['wordpress_org'];
			$body = '/plugins/update-check/1.1/' === $path
				? array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() )
				: array( 'themes' => array(), 'translations' => array(), 'no_update' => array() );
			return array( 'body' => wp_json_encode( $body ), 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		}
		if ( ! is_string( $path ) || ! str_starts_with( $path, '/repos/ran-booster-c4/' ) && ! str_starts_with( $path, '/repos/RocketsAreNostalgic/ran-booster' ) && ! str_starts_with( $path, '/repositories/' ) ) {
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
		$self = 'RocketsAreNostalgic/ran-booster' === $repository;
		$repositoryId     = $self ? 1319710173 : 940000 + $repositoryNumber;
		$releaseId        = hexdec( substr( sha1( $repository ), 0, 6 ) );
		$assetId          = $releaseId + 1;
		$repositoryPath   = '/repos/' . $repository;
		$release          = array( 'id' => $releaseId, 'tag_name' => 'v2.0.0', 'draft' => false, 'prerelease' => false, 'immutable' => true, 'published_at' => '2026-09-06T00:00:00Z', 'html_url' => 'https://github.com/' . $repository . '/releases/tag/v2.0.0', 'assets' => array( array( 'id' => $assetId, 'name' => basename( $archive ), 'size' => filesize( $archive ), 'state' => 'uploaded', 'digest' => 'sha256:' . hash_file( 'sha256', $archive ), 'browser_download_url' => 'https://api.github.com' . $repositoryPath . '/releases/assets/' . $assetId ) ) );
		if ( $self ) { $release['tag_name'] = 'v2.0.0-beta.1'; $release['prerelease'] = true; $release['html_url'] = 'https://github.com/' . $repository . '/releases/tag/v2.0.0-beta.1'; }
		$repositoryBody    = array( 'id' => $repositoryId, 'name' => basename( $repository ), 'full_name' => $repository, 'private' => false, 'default_branch' => 'main', 'html_url' => 'https://github.com/' . $repository, 'owner' => array( 'login' => $self ? 'RocketsAreNostalgic' : 'ran-booster-c4' ) );
		$repositoryMatch   = $path === $repositoryPath || $path === '/repositories/' . $repositoryId || str_starts_with( $path, $repositoryPath . '/' );
		if ( ! $repositoryMatch ) {
			continue;
		}
			if ( true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && copy( $archive, $args['filename'] ) ) {
				$counts['zip_bytes'] += filesize( $archive );
				++$counts['zip_count'];
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
			$encoded = null === $body ? '' : wp_json_encode( $body );
			$counts['http_bytes'] += strlen( $encoded );
			return array( 'body' => $encoded, 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'filename' => $args['filename'] ?? null );
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

foreach ( array( 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater', 'RAN\\WPReleaseUpdater\\V1\\Runtime\\RequestBroker' ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( ! is_string( $file ) || ! str_starts_with( $file, $origin . 'vendor/ran/wp-release-updater/' ) ) {
		throw new RuntimeException( 'The native runtime did not originate in the installed dependency.' );
	}
}
$container = require __DIR__ . '/core-container-fixture.php';
$registry  = $container->make( ProviderRegistry::class );
$provider  = $registry->requireCapability( 'gh', RepositoryReleaseNativeTargets::class );
if ( ! $provider instanceof GitHubProvider ) {
	throw new RuntimeException( 'The installed Core did not select its built-in GitHub provider.' );
}

// The public beta.4 registrar only activates at this lifecycle boundary. The
// worker must observe WordPress' real lifecycle rather than synthesize it.
if ( 1 > did_action( 'after_setup_theme' ) ) {
	throw new RuntimeException( 'The installed request has not crossed the native activation boundary.' );
}
$targets = ( new ReflectionProperty( GitHubProvider::class, 'nativeTargets' ) )->getValue( $provider );
if ( ! is_array( $targets ) || count( $targets ) < count( $items ) ) {
	throw new RuntimeException( 'The fresh installed request did not register every managed GitHub target.' );
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
delete_site_transient( 'update_plugins' );
delete_site_transient( 'update_themes' );
wp_update_plugins();
wp_update_themes();
if ( 2 !== $counts['wordpress_org'] ) {
	throw new RuntimeException( 'The native lifecycle fixture did not serve both bounded WordPress.org update checks.' );
}
$pluginUpdates = get_site_transient( 'update_plugins' );
$themeUpdates  = get_site_transient( 'update_themes' );
$active        = 0;
foreach ( $items as $item ) {
	if ( ! is_array( $item ) || ! is_string( $item['type'] ?? null ) || ! is_string( $item['identifier'] ?? null ) || ! is_string( $item['policy'] ?? null ) ) {
		throw new RuntimeException( 'The native lifecycle item is malformed.' );
	}
	if ( 'theme' === $item['type'] && get_stylesheet() === $item['identifier'] ) {
		throw new RuntimeException( 'The C4 inactive theme fixture is active.' );
	}
	$key    = $item['type'] . ':' . $item['identifier'];
	$target = $targets[ $key ] ?? null;
	if ( ! is_object( $target ) ) {
		throw new RuntimeException( 'The installed Core target key is absent.' );
	}
	$status = $target->status();
	if ( ! $status->active ) {
		throw new RuntimeException( 'The beta.4 public registrar did not activate an exact target.' );
	}
	$expectedReleaseId = (string) hexdec( substr( sha1( (string) $item['repository'] ), 0, 6 ) );
	if ( '2.0.0' !== $status->offeredVersion || ! hash_equals( $expectedReleaseId, $status->candidateProviderReleaseId ) ) {
		throw new RuntimeException( 'The installed target did not retain its exact native offer identity: ' . wp_json_encode( array( 'expected_release_id' => $expectedReleaseId, 'offered_version' => $status->offeredVersion, 'candidate_release_id' => $status->candidateProviderReleaseId, 'candidate_code' => $status->candidateCode, 'failure_code' => $status->failureCode, 'counts' => $counts ) ) );
	}
	++$active;
}


foreach ( $items as $item ) {
	$type   = $item['type'];
	$updates = 'plugin' === $type ? $pluginUpdates : $themeUpdates;
	$offer   = is_object( $updates ) && isset( $updates->response[ $item['identifier'] ] ) ? $updates->response[ $item['identifier'] ] : null;
	if ( is_array( $offer ) ) { $offer = (object) $offer; }
	if ( ! is_object( $offer ) ) {
		throw new RuntimeException( 'The installed native target has no WordPress offer.' );
	}
	$automatic = apply_filters( 'plugin' === $type ? 'auto_update_plugin' : 'auto_update_theme', false, $offer );
	if ( ( 'automatic' === $item['policy'] ) !== $automatic ) {
		throw new RuntimeException( 'The installed native automatic policy was not applied.' );
	}
	if ( 'automatic' === $item['policy'] ) {
		$result = null;
		$automaticUpdate = static function () use ( $type, $offer, &$result ): void {
			$result = ( new WP_Automatic_Updater() )->update( $type, $offer );
		};
		$targetContext = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();
		$vcsCheckout   = static function ( bool $checkout, string $context ) use ( $targetContext ): bool {
			return realpath( $context ) === realpath( $targetContext ) ? false : $checkout;
		};
		if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
			throw new RuntimeException( 'The installed native automatic updater lock is unavailable.' );
		}
		add_filter( 'automatic_updates_is_vcs_checkout', $vcsCheckout, PHP_INT_MAX, 2 );
		add_action( 'wp_maybe_auto_update', $automaticUpdate, PHP_INT_MAX );
		try {
			do_action( 'wp_maybe_auto_update' );
		} finally {
			remove_action( 'wp_maybe_auto_update', $automaticUpdate, PHP_INT_MAX );
			remove_filter( 'automatic_updates_is_vcs_checkout', $vcsCheckout, PHP_INT_MAX );
			WP_Upgrader::release_lock( 'auto_updater' );
		}
	} else {
		$result = 'plugin' === $type
			? ( new Plugin_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $item['identifier'], array( 'clear_update_cache' => false ) )
			: ( new Theme_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $item['identifier'], array( 'clear_update_cache' => false ) );
	}
		if ( true !== $result ) {
			throw new RuntimeException( 'The installed native ' . $type . ' ' . $item['policy'] . ' update failed.' );
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
}
if ( 1 > $counts['http'] || 1 > $counts['zip_bytes'] || 1 > $counts['zip_count'] ) {
	throw new RuntimeException( 'The controlled GitHub fixture did not serve native metadata and ZIP bytes.' );
}

$selfOffer = $pluginUpdates->response['ran-booster/ran-booster.php'] ?? null;
$selfStatus = ( $targets['plugin:ran-booster/ran-booster.php'] ?? null )?->status();
if ( ! is_object( $selfOffer ) || null === $selfStatus || '2.0.0-beta.1' !== $selfStatus->offeredVersion
	|| false !== apply_filters( 'auto_update_plugin', true, $selfOffer ) ) {
	throw new RuntimeException( 'Self Manual offer or Automatic denial is unavailable.' );
}
$beforeBulk = $counts;
$selfDigest = hash_file( 'sha256', WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' );
$bulk = apply_filters(
	'upgrader_pre_download',
	false,
	'fixture.zip',
	(object) array( 'bulk' => true, 'update_count' => 1, 'update_current' => 1 ),
	array( 'plugin' => 'ran-booster/ran-booster.php', 'action' => 'update', 'type' => 'plugin' )
);
if ( ! is_wp_error( $bulk ) || 'ran_booster_native_update_unsupported_context' !== $bulk->get_error_code()
	|| $counts !== $beforeBulk || $selfDigest !== hash_file( 'sha256', WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'The installed Core self bulk guard is unavailable.' );
}

if ( ! unlink( $selfArchive ) ) { throw new RuntimeException( 'Self-offer archive cleanup failed.' ); }
require __DIR__ . '/native-lifecycle-installed-cleanup.php';

WP_CLI::success( wp_json_encode( array( 'scale' => (int) $scale, 'active' => $active, 'bootstrap' => $bootstrapProbe, 'operation_seconds' => microtime( true ) - $started, 'memory' => memory_get_peak_usage( true ), 'hooks' => did_action( 'after_setup_theme' ), 'http' => $counts ) ) );

<?php

// A fresh WP-CLI request proves the installed Core's eager managed-target scan.

use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\Troubleshooting\CoreSelfUpdateStatus;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The native lifecycle smoke requires the marked installed CI site.' );
}

$ran_booster_scale    = getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' );
$ran_booster_items    = get_option( 'ran_booster_c4_native_items', null );
$ran_booster_archives = get_option( 'ran_booster_c4_native_archives', null );
if ( ! is_string( $ran_booster_scale ) || ! in_array( $ran_booster_scale, array( '1', '5', '10', '20' ), true ) || ! is_array( $ran_booster_items ) || ! is_array( $ran_booster_archives ) || count( $ran_booster_items ) !== (int) $ran_booster_scale ) {
	throw new RuntimeException( 'The native lifecycle fixture state is invalid.' );
}

$ran_booster_bootstrap_probe = $GLOBALS['ran_booster_c4_bootstrap_probe'] ?? null;
if ( ! is_array( $ran_booster_bootstrap_probe ) || ! isset( $ran_booster_bootstrap_probe['elapsed_ns'], $ran_booster_bootstrap_probe['native_hooks'] )
	|| 0 !== $ran_booster_bootstrap_probe['http'] || 0 !== $ran_booster_bootstrap_probe['zip'] || 0 !== $ran_booster_bootstrap_probe['authorization_headers'] ) {
	throw new RuntimeException( 'Declaration/activation performed remote or credential-bearing HTTP work.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
global $wp_filesystem;
if ( ! WP_Filesystem() || ! $wp_filesystem instanceof WP_Filesystem_Direct ) {
	throw new RuntimeException( 'The native lifecycle proof requires the direct WordPress filesystem.' );
}
$ran_booster_started      = microtime( true );
$ran_booster_self_archive = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) . '/ran-booster.zip';
if ( file_exists( $ran_booster_self_archive ) || is_link( $ran_booster_self_archive ) ) {
	throw new RuntimeException( 'Self-offer archive is not exclusively owned.' ); }
$ran_booster_self_zip = new ZipArchive();
if ( true !== $ran_booster_self_zip->open( $ran_booster_self_archive, ZipArchive::CREATE ) ) {
	throw new RuntimeException( 'Self-offer archive creation failed.' ); }
$ran_booster_self_zip->addFromString( 'ran-booster/ran-booster.php', "<?php\n/*\nPlugin Name: RAN Booster\nVersion: 2.0.0-beta.1\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/RocketsAreNostalgic/ran-booster\n*/\n" );
$ran_booster_self_zip->close();
$ran_booster_archives['RocketsAreNostalgic/ran-booster'] = $ran_booster_self_archive;
$ran_booster_counts                                      = array(
	'credentials'   => 0,
	'http'          => 0,
	'http_bytes'    => 0,
	'wordpress_org' => 0,
	'zip_bytes'     => 0,
	'zip_count'     => 0,
	'blocked'       => 0,
);
add_filter(
	'pre_http_request',
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
	static function ( mixed $pre, array $args, string $url ) use ( $ran_booster_archives, &$ran_booster_counts ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
		$host = parse_url( $url, PHP_URL_HOST );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
		$path = parse_url( $url, PHP_URL_PATH );
		if ( 'api.wordpress.org' === $host && in_array( $path, array( '/plugins/update-check/1.1/', '/themes/update-check/1.1/' ), true ) ) {
			++$ran_booster_counts['wordpress_org'];
			$body = '/plugins/update-check/1.1/' === $path
				? array(
					'plugins'      => array(),
					'translations' => array(),
					'no_update'    => array(),
				)
				: array(
					'themes'       => array(),
					'translations' => array(),
					'no_update'    => array(),
				);
			return array(
				'body'     => wp_json_encode( $body ),
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		}
		if ( ! is_string( $path ) || ( ! str_starts_with( $path, '/repos/ran-booster-c4/' ) && ! str_starts_with( $path, '/repos/RocketsAreNostalgic/ran-booster' ) && ! str_starts_with( $path, '/repositories/' ) ) ) {
			++$ran_booster_counts['blocked'];
			return new WP_Error( 'ran_booster_c4_network_denied' );
		}
		++$ran_booster_counts['http'];
		if ( isset( $args['headers']['Authorization'] ) ) {
			++$ran_booster_counts['credentials'];
		}
		foreach ( $ran_booster_archives as $repository => $archive ) {
			if ( ! is_string( $repository ) || ! is_string( $archive ) ) {
				continue;
			}
			$repository_number = (int) substr( $repository, strrpos( $repository, '-' ) + 1 );
			$self              = 'RocketsAreNostalgic/ran-booster' === $repository;
			$repository_id     = $self ? 1319710173 : 940000 + $repository_number;
			$release_id        = hexdec( substr( sha1( $repository ), 0, 6 ) );
			$asset_id          = $release_id + 1;
			$repository_path   = '/repos/' . $repository;
			$release           = array(
				'id'           => $release_id,
				'tag_name'     => 'v2.0.0',
				'draft'        => false,
				'prerelease'   => false,
				'immutable'    => true,
				'published_at' => '2026-09-06T00:00:00Z',
				'html_url'     => 'https://github.com/' . $repository . '/releases/tag/v2.0.0',
				'assets'       => array(
					array(
						'id'                   => $asset_id,
						'name'                 => basename( $archive ),
						'size'                 => filesize( $archive ),
						'state'                => 'uploaded',
						'digest'               => 'sha256:' . hash_file( 'sha256', $archive ),
						'browser_download_url' => 'https://api.github.com' . $repository_path . '/releases/assets/' . $asset_id,
					),
				),
			);
			if ( $self ) {
				$release['tag_name']   = 'v2.0.0-beta.1';
				$release['prerelease'] = true;
				$release['html_url']   = 'https://github.com/' . $repository . '/releases/tag/v2.0.0-beta.1'; }
			$repository_body  = array(
				'id'             => $repository_id,
				'name'           => basename( $repository ),
				'full_name'      => $repository,
				'private'        => false,
				'default_branch' => 'main',
				'html_url'       => 'https://github.com/' . $repository,
				'owner'          => array( 'login' => $self ? 'RocketsAreNostalgic' : 'ran-booster-c4' ),
			);
			$repository_match = $path === $repository_path || '/repositories/' . $repository_id === $path || str_starts_with( $path, $repository_path . '/' );
			if ( ! $repository_match ) {
				continue;
			}
			if ( true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && copy( $archive, $args['filename'] ) ) {
				$ran_booster_counts['zip_bytes'] += filesize( $archive );
				++$ran_booster_counts['zip_count'];
				$body = null;
			} elseif ( $path === $repository_path || '/repositories/' . $repository_id === $path ) {
				$body = $repository_body;
			} elseif ( str_ends_with( $path, '/releases' ) ) {
				$body = array( $release );
			} elseif ( str_contains( $path, '/releases/' ) ) {
				$body = $release;
			} elseif ( str_contains( $path, '/commits/' ) ) {
				$body = array( 'sha' => str_repeat( 'a', 40 ) );
			} else {
				continue;
			}
			$encoded                           = null === $body ? '' : wp_json_encode( $body );
			$ran_booster_counts['http_bytes'] += strlen( $encoded );
			return array(
				'body'     => $encoded,
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'filename' => $args['filename'] ?? null,
			);
		}
		return new WP_Error( 'ran_booster_c4_network_denied' );
	},
	PHP_INT_MIN,
	3
);

$ran_booster_origin = WP_PLUGIN_DIR . '/ran-booster/';
foreach ( array( GitHubProvider::class, RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget::class, RAN\WordPress\ManagedReleaseTargetRegistrar::class ) as $ran_booster_class ) {
	$ran_booster_file = ( new ReflectionClass( $ran_booster_class ) )->getFileName();
	if ( ! is_string( $ran_booster_file ) || ! str_starts_with( $ran_booster_file, $ran_booster_origin ) ) {
		throw new RuntimeException( 'The native lifecycle class is not from the installed Core archive.' );
	}
}

foreach ( array( 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater', 'RAN\\WPReleaseUpdater\\V1\\Runtime\\RequestBroker' ) as $ran_booster_class ) {
	$ran_booster_file = ( new ReflectionClass( $ran_booster_class ) )->getFileName();
	if ( ! is_string( $ran_booster_file ) || ! str_starts_with( $ran_booster_file, $ran_booster_origin . 'vendor/ran/wp-release-updater/' ) ) {
		throw new RuntimeException( 'The native runtime did not originate in the installed dependency.' );
	}
}
$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_registry  = $ran_booster_container->make( ProviderRegistry::class );
$ran_booster_provider  = $ran_booster_registry->require_capability( 'gh', RepositoryReleaseNativeTargets::class );
if ( ! $ran_booster_provider instanceof GitHubProvider ) {
	throw new RuntimeException( 'The installed Core did not select its built-in GitHub provider.' );
}

// The public beta.4 registrar only activates at this lifecycle boundary. The
// worker must observe WordPress' real lifecycle rather than synthesize it.
if ( 1 > did_action( 'after_setup_theme' ) ) {
	throw new RuntimeException( 'The installed request has not crossed the native activation boundary.' );
}
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$targets = ( new ReflectionProperty( GitHubProvider::class, 'native_targets' ) )->getValue( $ran_booster_provider );
if ( ! is_array( $targets ) || count( $targets ) < count( $ran_booster_items ) ) {
	throw new RuntimeException( 'The fresh installed request did not register every managed GitHub target.' );
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
delete_site_transient( 'update_plugins' );
delete_site_transient( 'update_themes' );
wp_update_plugins();
wp_update_themes();
if ( 2 !== $ran_booster_counts['wordpress_org'] ) {
	throw new RuntimeException( 'The native lifecycle fixture did not serve both bounded WordPress.org update checks.' );
}
$ran_booster_plugin_updates = get_site_transient( 'update_plugins' );
$ran_booster_theme_updates  = get_site_transient( 'update_themes' );
$ran_booster_active         = 0;
foreach ( $ran_booster_items as $ran_booster_item ) {
	if ( ! is_array( $ran_booster_item ) || ! is_string( $ran_booster_item['type'] ?? null ) || ! is_string( $ran_booster_item['identifier'] ?? null ) || ! is_string( $ran_booster_item['policy'] ?? null ) ) {
		throw new RuntimeException( 'The native lifecycle item is malformed.' );
	}
	if ( 'theme' === $ran_booster_item['type'] && get_stylesheet() === $ran_booster_item['identifier'] ) {
		throw new RuntimeException( 'The C4 inactive theme fixture is active.' );
	}
	$ran_booster_key    = $ran_booster_item['type'] . ':' . $ran_booster_item['identifier'];
	$ran_booster_target = $targets[ $ran_booster_key ] ?? null;
	if ( ! is_object( $ran_booster_target ) ) {
		throw new RuntimeException( 'The installed Core target key is absent.' );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$status = $ran_booster_target->status();
	if ( ! $status->active ) {
		throw new RuntimeException( 'The beta.4 public registrar did not activate an exact target.' );
	}
	$ran_booster_expected_release_id = (string) hexdec( substr( sha1( (string) $ran_booster_item['repository'] ), 0, 6 ) );
	if ( '2.0.0' !== $status->offered_version || ! hash_equals( $ran_booster_expected_release_id, $status->candidate_provider_release_id ) ) {
		throw new RuntimeException(
			'The installed target did not retain its exact native offer identity: ' . wp_json_encode(
				array(
					'expected_release_id'  => $ran_booster_expected_release_id,
					'offered_version'      => $status->offered_version,
					'candidate_release_id' => $status->candidate_provider_release_id,
					'candidate_code'       => $status->candidate_code,
					'failure_code'         => $status->failure_code,
					'counts'               => $ran_booster_counts,
				)
			)
		);
	}
	++$ran_booster_active;
}


foreach ( $ran_booster_items as $ran_booster_item ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$type                = $ran_booster_item['type'];
	$ran_booster_updates = 'plugin' === $type ? $ran_booster_plugin_updates : $ran_booster_theme_updates;
	$ran_booster_offer   = is_object( $ran_booster_updates ) && isset( $ran_booster_updates->response[ $ran_booster_item['identifier'] ] ) ? $ran_booster_updates->response[ $ran_booster_item['identifier'] ] : null;
	if ( is_array( $ran_booster_offer ) ) {
		$ran_booster_offer = (object) $ran_booster_offer; }
	if ( ! is_object( $ran_booster_offer ) ) {
		throw new RuntimeException( 'The installed native target has no WordPress offer.' );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
	$ran_booster_automatic = apply_filters( 'plugin' === $type ? 'auto_update_plugin' : 'auto_update_theme', false, $ran_booster_offer );
	if ( ( 'automatic' === $ran_booster_item['policy'] ) !== $ran_booster_automatic ) {
		throw new RuntimeException( 'The installed native automatic policy was not applied.' );
	}
	if ( 'automatic' === $ran_booster_item['policy'] ) {
		$ran_booster_result           = null;
		$ran_booster_automatic_update = static function () use ( $type, $ran_booster_offer, &$ran_booster_result ): void {
			$ran_booster_result = ( new WP_Automatic_Updater() )->update( $type, $ran_booster_offer );
		};
		$ran_booster_target_context   = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();
		$ran_booster_vcs_checkout     = static function ( bool $checkout, string $context ) use ( $ran_booster_target_context ): bool {
			return realpath( $context ) === realpath( $ran_booster_target_context ) ? false : $checkout;
		};
		if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
			throw new RuntimeException( 'The installed native automatic updater lock is unavailable.' );
		}
		add_filter( 'automatic_updates_is_vcs_checkout', $ran_booster_vcs_checkout, PHP_INT_MAX, 2 );
		add_action( 'wp_maybe_auto_update', $ran_booster_automatic_update, PHP_INT_MAX );
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
			do_action( 'wp_maybe_auto_update' );
		} finally {
			remove_action( 'wp_maybe_auto_update', $ran_booster_automatic_update, PHP_INT_MAX );
			remove_filter( 'automatic_updates_is_vcs_checkout', $ran_booster_vcs_checkout, PHP_INT_MAX );
			WP_Upgrader::release_lock( 'auto_updater' );
		}
	} else {
		$ran_booster_result = 'plugin' === $type
			? ( new Plugin_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $ran_booster_item['identifier'], array( 'clear_update_cache' => false ) )
			: ( new Theme_Upgrader( new WP_Upgrader_Skin() ) )->upgrade( $ran_booster_item['identifier'], array( 'clear_update_cache' => false ) );
	}
	if ( true !== $ran_booster_result ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'The installed native ' . $type . ' ' . $ran_booster_item['policy'] . ' update failed.' );
	}
		$ran_booster_version = 'plugin' === $type
			? ( get_plugin_data( WP_PLUGIN_DIR . '/' . $ran_booster_item['identifier'], false, false )['Version'] ?? '' )
			: ( get_file_data( get_theme_root() . '/' . $ran_booster_item['identifier'] . '/style.css', array( 'Version' => 'Version' ), 'theme' )['Version'] ?? '' );
	if ( '2.0.0' !== $ran_booster_version ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'The installed native ' . $type . ' version was not replaced.' );
	}
		$ran_booster_metadata_path = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $ran_booster_item['identifier'] : get_theme_root() . '/' . $ran_booster_item['identifier'] . '/style.css';
	if ( ! is_string( $ran_booster_item['expected_digest'] ?? null ) || ! hash_equals( $ran_booster_item['expected_digest'], (string) hash_file( 'sha256', $ran_booster_metadata_path ) ) ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'The installed native ' . $type . ' digest was not replaced.' );
	}
}
if ( 1 > $ran_booster_counts['http'] || 1 > $ran_booster_counts['zip_bytes'] || 1 > $ran_booster_counts['zip_count'] ) {
	throw new RuntimeException( 'The controlled GitHub fixture did not serve native metadata and ZIP bytes.' );
}

$ran_booster_self_offer       = $ran_booster_plugin_updates->response['ran-booster/ran-booster.php'] ?? null;
$ran_booster_self_diagnostics = $ran_booster_container->make( CoreSelfUpdateStatus::class )->diagnostics();
if ( ! is_object( $ran_booster_self_offer ) || '2.0.0-beta.1' !== ( $ran_booster_self_diagnostics['offered_version'] ?? null )
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
	|| false !== apply_filters( 'auto_update_plugin', true, $ran_booster_self_offer ) ) {
	throw new RuntimeException( 'Self Manual offer or Automatic denial is unavailable.' );
}
$ran_booster_before_bulk = $ran_booster_counts;
$ran_booster_self_digest = hash_file( 'sha256', WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' );
$ran_booster_bulk        = apply_filters(
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
	'upgrader_pre_download',
	false,
	'fixture.zip',
	(object) array(
		'bulk'           => true,
		'update_count'   => 1,
		'update_current' => 1,
	),
	array(
		'plugin' => 'ran-booster/ran-booster.php',
		'action' => 'update',
		'type'   => 'plugin',
	)
);
if ( ! is_wp_error( $ran_booster_bulk ) || 'ran_booster_native_update_unsupported_context' !== $ran_booster_bulk->get_error_code()
	|| $ran_booster_counts !== $ran_booster_before_bulk || hash_file( 'sha256', WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) !== $ran_booster_self_digest ) {
	throw new RuntimeException( 'The installed Core self bulk guard is unavailable.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
if ( ! unlink( $ran_booster_self_archive ) ) {
	throw new RuntimeException( 'Self-offer archive cleanup failed.' ); }
require __DIR__ . '/native-lifecycle-installed-cleanup.php';

WP_CLI::success( // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	wp_json_encode(
		array(
			'scale'             => (int) $ran_booster_scale,
			'active'            => $ran_booster_active,
			'bootstrap'         => $ran_booster_bootstrap_probe,
			'operation_seconds' => microtime( true ) - $ran_booster_started,
			'memory'            => memory_get_peak_usage( true ),
			'hooks'             => did_action( 'after_setup_theme' ),
			'http'              => $ran_booster_counts,
		)
	)
);

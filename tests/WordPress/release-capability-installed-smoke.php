<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\PackageSource;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The installed release-capability smoke requires an administrator WP-CLI request.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$expected_root    = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
$expected_url     = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_URL' );
$archive_root     = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' );
$wordpress_root   = realpath( ABSPATH );
$content_root     = realpath( WP_CONTENT_DIR );
$plugin_root      = realpath( WP_PLUGIN_DIR );
$theme_root       = realpath( get_theme_root() );
$fixture_plugin   = WP_PLUGIN_DIR . '/ran-booster-release-capability-provider/ran-booster-release-capability-provider.php';
$disposable_mark  = ABSPATH . '.ran-booster-disposable-test-site';
$expected_targets = array(
	WP_PLUGIN_DIR . '/ran-booster-p2-fixture-plugin',
	get_theme_root() . '/ran-booster-p2-fixture-theme',
);
if ( ! is_string( $expected_root ) || false === $wordpress_root || realpath( $expected_root ) !== $wordpress_root
	|| false === $content_root || $content_root !== $wordpress_root . '/wp-content'
	|| false === $plugin_root || $plugin_root !== $content_root . '/plugins'
	|| false === $theme_root || $theme_root !== $content_root . '/themes'
	// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
	|| 'http://localhost' !== $expected_url || $expected_url !== get_option( 'siteurl' )
	|| is_link( $disposable_mark ) || ! is_file( $disposable_mark )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	|| "RAN Booster disposable test site\n" !== file_get_contents( $disposable_mark )
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' )
	|| is_link( dirname( $fixture_plugin ) ) || ! is_file( $fixture_plugin ) || ! is_plugin_active( plugin_basename( $fixture_plugin ) )
	|| ! is_string( $archive_root ) || false === realpath( $archive_root ) ) {
	throw new RuntimeException( 'The installed release-capability smoke requires the exact disposable site and fixture.' );
}
foreach ( $expected_targets as $target ) {
	if ( is_link( $target ) || file_exists( $target ) ) {
		throw new RuntimeException( 'A disposable release-capability target already exists.' );
	}
}
foreach ( array( 'plugin', 'theme' ) as $archive_type ) {
	$archive = get_option( 'ran_booster_p2_' . $archive_type . '_archive', '' );
	if ( ! is_string( $archive ) || false === realpath( $archive ) || realpath( dirname( $archive ) ) !== realpath( $archive_root )
		|| is_link( $archive ) || ! is_file( $archive ) || 'zip' !== pathinfo( $archive, PATHINFO_EXTENSION ) ) {
		throw new RuntimeException( 'A disposable release-capability archive is outside the exact archive root.' );
	}
}

$container = require __DIR__ . '/core-container-fixture.php';
$facade    = $container->make( ProspectiveReleaseFacade::class );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$plugins   = $container->make( PluginRepository::class );
$themes    = $container->make( ThemeRepository::class );
$installed = array();

$assert_result = static function ( object $result, string $code ): void {
	if ( ! $result->successful() || $code !== $result->code() ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'Unexpected prospective release result: ' . $result->code() );
	}
};

try {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$request = array(
			'provider'      => 'p2-release',
			'repository'    => 'fixtures/' . $type,
			'credential_id' => '',
			'branch'        => 'main',
		);
		$list    = $facade->list_candidates(
			$type,
			$request,
			'stable',
			wp_create_nonce( $facade->nonce_action( 'list_candidates', $type ) )
		);
		$assert_result( $list, 'release_candidates_available' );
		$candidate = $list->data()['candidates'][0] ?? null;
		if ( ! is_array( $candidate )
			|| '42' !== ( $candidate['release_id'] ?? null )
			|| 'v2.0.0' !== ( $candidate['tag'] ?? null )
			|| '2.0.0' !== ( $candidate['version'] ?? null )
			|| false !== ( $candidate['prerelease'] ?? null )
		) {
			throw new RuntimeException( 'The installed candidate projection is invalid.' );
		}

		$inspection = $facade->inspect(
			$type,
			$request,
			'42',
			'v2.0.0',
			'stable',
			wp_create_nonce( $facade->nonce_action( 'inspect', $type ) )
		);
		$assert_result( $inspection, 'release_ready' );
		$evidence = $inspection->data();
		if ( 'v2:' . str_repeat( 'b', 64 ) !== ( $evidence['fingerprint'] ?? null ) ) {
			throw new RuntimeException( 'The installed release fingerprint is invalid.' );
		}

		$result = $facade->install(
			$type,
			$request,
			'42',
			'v2.0.0',
			$evidence['fingerprint'],
			'stable',
			wp_create_nonce( $facade->nonce_action( 'install', $type ) )
		);
		$assert_result( $result, 'installed' );

		$identifier = 'plugin' === $type
			? 'ran-booster-p2-fixture-plugin/ran-booster-p2-fixture-plugin.php'
			: 'ran-booster-p2-fixture-theme';
		$package    = 'plugin' === $type
			? $plugins->booster_plugin_from_file( $identifier )
			: $themes->booster_theme_from_stylesheet( $identifier );
		if ( '2.0.0' !== $package->get_version()
			|| PackageSource::RELEASE_ASSET !== $package->get_source()
			|| 1 !== $package->get_source_revision()
		) {
			throw new RuntimeException( 'The installed release package readback is invalid.' );
		}
		$artifact = get_option( 'ran_booster_p2_last_artifact', '' );
		if ( ! is_string( $artifact ) || '' === $artifact || file_exists( $artifact ) || is_link( $artifact ) ) {
			throw new RuntimeException( 'The acquired release artifact was not cleaned exactly once.' );
		}
		$installed[ $type ] = $identifier;
	}
} finally {
	$cleanup = ! (bool) get_option( 'ran_booster_p2_keep_installed', false );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $cleanup ? array_reverse( $installed, true ) : array() as $type => $identifier ) {
		if ( 'plugin' === $type ) {
			$plugins->unlink( $identifier )->require_success();
			if ( is_plugin_active( $identifier ) ) {
				deactivate_plugins( $identifier, true );
			}
			$result = delete_plugins( array( $identifier ) );
		} else {
			$themes->unlink( $identifier )->require_success();
			$result = delete_theme( $identifier );
		}
		if ( is_wp_error( $result ) || false === $result ) {
			throw new RuntimeException( 'The installed release fixture could not be cleaned.' );
		}
	}
	if ( $cleanup ) {
		delete_option( 'ran_booster_p2_last_artifact' );
	}
}

WP_CLI::success(
	$cleanup
	? 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption, readback and cleanup passed.'
	: 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption and retained readback passed.'
);

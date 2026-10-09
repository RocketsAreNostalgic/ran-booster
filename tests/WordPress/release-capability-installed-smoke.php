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

$ran_booster_expected_root    = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
$ran_booster_expected_url     = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_URL' );
$ran_booster_archive_root     = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' );
$ran_booster_wordpress_root   = realpath( ABSPATH );
$ran_booster_content_root     = realpath( WP_CONTENT_DIR );
$ran_booster_plugin_root      = realpath( WP_PLUGIN_DIR );
$ran_booster_theme_root       = realpath( get_theme_root() );
$ran_booster_fixture_plugin   = WP_PLUGIN_DIR . '/ran-booster-release-capability-provider/ran-booster-release-capability-provider.php';
$ran_booster_disposable_mark  = ABSPATH . '.ran-booster-disposable-test-site';
$ran_booster_expected_targets = array(
	WP_PLUGIN_DIR . '/ran-booster-p2-fixture-plugin',
	get_theme_root() . '/ran-booster-p2-fixture-theme',
);
if ( ! is_string( $ran_booster_expected_root ) || false === $ran_booster_wordpress_root || realpath( $ran_booster_expected_root ) !== $ran_booster_wordpress_root
	|| false === $ran_booster_content_root || $ran_booster_content_root !== $ran_booster_wordpress_root . '/wp-content'
	|| false === $ran_booster_plugin_root || $ran_booster_plugin_root !== $ran_booster_content_root . '/plugins'
	|| false === $ran_booster_theme_root || $ran_booster_theme_root !== $ran_booster_content_root . '/themes'
	// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Capture the expected fixture value before WordPress filters can mutate global test state.
	|| 'http://localhost' !== $ran_booster_expected_url || $ran_booster_expected_url !== get_option( 'siteurl' )
	|| is_link( $ran_booster_disposable_mark ) || ! is_file( $ran_booster_disposable_mark )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	|| "RAN Booster disposable test site\n" !== file_get_contents( $ran_booster_disposable_mark )
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' )
	|| is_link( dirname( $ran_booster_fixture_plugin ) ) || ! is_file( $ran_booster_fixture_plugin ) || ! is_plugin_active( plugin_basename( $ran_booster_fixture_plugin ) )
	|| ! is_string( $ran_booster_archive_root ) || false === realpath( $ran_booster_archive_root ) ) {
	throw new RuntimeException( 'The installed release-capability smoke requires the exact disposable site and fixture.' );
}
foreach ( $ran_booster_expected_targets as $ran_booster_target ) {
	if ( is_link( $ran_booster_target ) || file_exists( $ran_booster_target ) ) {
		throw new RuntimeException( 'A disposable release-capability target already exists.' );
	}
}
foreach ( array( 'plugin', 'theme' ) as $ran_booster_archive_type ) {
	$ran_booster_archive = get_option( 'ran_booster_p2_' . $ran_booster_archive_type . '_archive', '' );
	if ( ! is_string( $ran_booster_archive ) || false === realpath( $ran_booster_archive ) || realpath( dirname( $ran_booster_archive ) ) !== realpath( $ran_booster_archive_root )
		|| is_link( $ran_booster_archive ) || ! is_file( $ran_booster_archive ) || 'zip' !== pathinfo( $ran_booster_archive, PATHINFO_EXTENSION ) ) {
		throw new RuntimeException( 'A disposable release-capability archive is outside the exact archive root.' );
	}
}

$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_facade    = $ran_booster_container->make( ProspectiveReleaseFacade::class );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$plugins               = $ran_booster_container->make( PluginRepository::class );
$ran_booster_themes    = $ran_booster_container->make( ThemeRepository::class );
$ran_booster_installed = array();

$ran_booster_assert_result = static function ( RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult $ran_booster_result, string $code ): void {
	if ( ! $ran_booster_result->successful() || $code !== $ran_booster_result->code() ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'Unexpected prospective release result: ' . $ran_booster_result->code() );
	}
};

try {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$ran_booster_request = array(
			'provider'      => 'p2-release',
			'repository'    => 'fixtures/' . $type,
			'credential_id' => '',
			'branch'        => 'main',
		);
		$ran_booster_list    = $ran_booster_facade->list_candidates(
			$type,
			$ran_booster_request,
			'stable',
			wp_create_nonce( $ran_booster_facade->nonce_action( 'list_candidates', $type ) )
		);
		$ran_booster_assert_result( $ran_booster_list, 'release_candidates_available' );
		$ran_booster_candidate = $ran_booster_list->data()['candidates'][0] ?? null;
		if ( ! is_array( $ran_booster_candidate )
			|| '42' !== ( $ran_booster_candidate['release_id'] ?? null )
			|| 'v2.0.0' !== ( $ran_booster_candidate['tag'] ?? null )
			|| '2.0.0' !== ( $ran_booster_candidate['version'] ?? null )
			|| false !== ( $ran_booster_candidate['prerelease'] ?? null )
		) {
			throw new RuntimeException( 'The installed candidate projection is invalid.' );
		}

		$ran_booster_inspection = $ran_booster_facade->inspect(
			$type,
			$ran_booster_request,
			'42',
			'v2.0.0',
			'stable',
			wp_create_nonce( $ran_booster_facade->nonce_action( 'inspect', $type ) )
		);
		$ran_booster_assert_result( $ran_booster_inspection, 'release_ready' );
		$ran_booster_evidence = $ran_booster_inspection->data();
		if ( 'v2:' . str_repeat( 'b', 64 ) !== ( $ran_booster_evidence['fingerprint'] ?? null ) ) {
			throw new RuntimeException( 'The installed release fingerprint is invalid.' );
		}

		$ran_booster_result = $ran_booster_facade->install(
			$type,
			$ran_booster_request,
			'42',
			'v2.0.0',
			$ran_booster_evidence['fingerprint'],
			'stable',
			wp_create_nonce( $ran_booster_facade->nonce_action( 'install', $type ) )
		);
		$ran_booster_assert_result( $ran_booster_result, 'installed' );

		$ran_booster_identifier = 'plugin' === $type
			? 'ran-booster-p2-fixture-plugin/ran-booster-p2-fixture-plugin.php'
			: 'ran-booster-p2-fixture-theme';
		$ran_booster_package    = 'plugin' === $type
			? $plugins->booster_plugin_from_file( $ran_booster_identifier )
			: $ran_booster_themes->booster_theme_from_stylesheet( $ran_booster_identifier );
		if ( '2.0.0' !== $ran_booster_package->get_version()
			|| PackageSource::RELEASE_ASSET !== $ran_booster_package->get_source()
			|| 1 !== $ran_booster_package->get_source_revision()
		) {
			throw new RuntimeException( 'The installed release package readback is invalid.' );
		}
		$ran_booster_artifact = get_option( 'ran_booster_p2_last_artifact', '' );
		if ( ! is_string( $ran_booster_artifact ) || '' === $ran_booster_artifact || file_exists( $ran_booster_artifact ) || is_link( $ran_booster_artifact ) ) {
			throw new RuntimeException( 'The acquired release artifact was not cleaned exactly once.' );
		}
		$ran_booster_installed[ $type ] = $ran_booster_identifier;
	}
} finally {
	$ran_booster_cleanup = ! (bool) get_option( 'ran_booster_p2_keep_installed', false );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( $ran_booster_cleanup ? array_reverse( $ran_booster_installed, true ) : array() as $type => $ran_booster_identifier ) {
		if ( 'plugin' === $type ) {
			$plugins->unlink( $ran_booster_identifier )->require_success();
			if ( is_plugin_active( $ran_booster_identifier ) ) {
				deactivate_plugins( $ran_booster_identifier, true );
			}
			$ran_booster_result = delete_plugins( array( $ran_booster_identifier ) );
		} else {
			$ran_booster_themes->unlink( $ran_booster_identifier )->require_success();
			$ran_booster_result = delete_theme( $ran_booster_identifier );
		}
		if ( is_wp_error( $ran_booster_result ) || false === $ran_booster_result ) {
			throw new RuntimeException( 'The installed release fixture could not be cleaned.' );
		}
	}
	if ( $ran_booster_cleanup ) {
		delete_option( 'ran_booster_p2_last_artifact' );
	}
}

WP_CLI::success( // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	$ran_booster_cleanup
	? 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption, readback and cleanup passed.'
	: 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption and retained readback passed.'
);

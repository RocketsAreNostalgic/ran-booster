<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.
// phpcs:disable

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\PackageSource;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'The installed release-capability smoke requires an administrator WP-CLI request.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$container = require __DIR__ . '/core-container-fixture.php';
$facade    = $container->make( ProspectiveReleaseFacade::class );
$plugins   = $container->make( PluginRepository::class );
$themes    = $container->make( ThemeRepository::class );
$installed = array();

$assertResult = static function ( object $result, string $code ): void {
	if ( ! $result->successful() || $code !== $result->code() ) {
		throw new RuntimeException( 'Unexpected prospective release result: ' . $result->code() );
	}
};

try {
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$request = array(
			'provider'      => 'p2-release',
			'repository'    => 'fixtures/' . $type,
			'credential_id' => '',
			'branch'        => 'main',
		);
		$list = $facade->listCandidates(
			$type,
			$request,
			'stable',
			wp_create_nonce( $facade->nonceAction( 'list_candidates', $type ) )
		);
		$assertResult( $list, 'release_candidates_available' );
		$candidate = $list->data()['candidates'][0] ?? null;
		if ( ! is_array( $candidate )
			|| 42 !== ( $candidate['release_id'] ?? null )
			|| 'v2.0.0' !== ( $candidate['tag'] ?? null )
			|| '2.0.0' !== ( $candidate['version'] ?? null )
			|| false !== ( $candidate['prerelease'] ?? null )
		) {
			throw new RuntimeException( 'The installed candidate projection is invalid.' );
		}

		$inspection = $facade->inspect(
			$type,
			$request,
			42,
			'v2.0.0',
			'stable',
			wp_create_nonce( $facade->nonceAction( 'inspect', $type ) )
		);
		$assertResult( $inspection, 'release_ready' );
		$evidence = $inspection->data();
		if ( 'v1:' . str_repeat( 'b', 64 ) !== ( $evidence['fingerprint'] ?? null ) ) {
			throw new RuntimeException( 'The installed release fingerprint is invalid.' );
		}

		$result = $facade->install(
			$type,
			$request,
			42,
			'v2.0.0',
			$evidence['fingerprint'],
			'stable',
			wp_create_nonce( $facade->nonceAction( 'install', $type ) )
		);
		$assertResult( $result, 'installed' );

		$identifier = 'plugin' === $type
			? 'ran-booster-p2-fixture-plugin/ran-booster-p2-fixture-plugin.php'
			: 'ran-booster-p2-fixture-theme';
		$package = 'plugin' === $type
			? $plugins->boosterPluginFromFile( $identifier )
			: $themes->boosterThemeFromStylesheet( $identifier );
		if ( '2.0.0' !== $package->getVersion()
			|| PackageSource::RELEASE_ASSET !== $package->getSource()
			|| 1 !== $package->getSourceRevision()
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
	foreach ( $cleanup ? array_reverse( $installed, true ) : array() as $type => $identifier ) {
		if ( 'plugin' === $type ) {
			$plugins->unlink( $identifier );
			if ( is_plugin_active( $identifier ) ) {
				deactivate_plugins( $identifier, true );
			}
			$result = delete_plugins( array( $identifier ) );
		} else {
			$themes->unlink( $identifier );
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

WP_CLI::success( $cleanup
	? 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption, readback and cleanup passed.'
	: 'Installed release capability list, inspect, fresh acquire, plugin/theme install, adoption and retained readback passed.' );

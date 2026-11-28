<?php

// Installed proof that a plugin can supersede bundled GitHub. Every phase is a
// fresh WP-CLI request against the marked disposable site; the owning shell
// activates and deactivates the claimant plugins between phases.

use RAN\Admin\GitHubSupplierNotice;
use RAN\RepositoryProvider\ProviderRegistry;

$ran_booster_extension = 'ran-booster-github-provider-extension';
$ran_booster_claimant  = 'ran-booster-github-supersede-failing';

$ran_booster_assert = static function ( bool $condition, string $reason ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored CLI assertion, not HTML.
		throw new RuntimeException( 'Installed GitHub supersede proof: ' . $reason );
	}
};

$ran_booster_site = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( false === $ran_booster_site ) {
	throw new RuntimeException( 'Installed GitHub supersede proof: requires the marked disposable CI site' );
}
$ran_booster_assert(
	defined( 'WP_CLI' ) && WP_CLI && '1' === getenv( 'RAN_BOOSTER_GITHUB_SUPERSEDE_DISPOSABLE' )
		&& rtrim( ABSPATH, '/' ) === $ran_booster_site
		&& WP_CONTENT_DIR === $ran_booster_site . '/wp-content' && WP_PLUGIN_DIR === $ran_booster_site . '/wp-content/plugins'
		&& 'http://localhost' === get_option( 'siteurl' ) && 'http://localhost' === get_option( 'home' ),
	'requires the marked disposable CI site'
);
foreach ( array( $ran_booster_site, WP_CONTENT_DIR, WP_PLUGIN_DIR, WP_PLUGIN_DIR . '/ran-booster', WP_PLUGIN_DIR . '/' . $ran_booster_extension, WP_PLUGIN_DIR . '/' . $ran_booster_claimant ) as $ran_booster_root ) {
	$ran_booster_assert( ! is_link( $ran_booster_root ) && realpath( $ran_booster_root ) === $ran_booster_root, 'refuses shared, missing or noncanonical roots' );
}
$ran_booster_marker = $ran_booster_site . '/.ran-booster-disposable-test-site';
$ran_booster_assert( is_file( $ran_booster_marker ) && ! is_link( $ran_booster_marker ), 'disposable marker is unavailable' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact disposable fixture marker without a filesystem abstraction.
$ran_booster_assert( 'RAN Booster disposable test site' === trim( (string) file_get_contents( $ran_booster_marker ) ), 'disposable marker does not match' );

$ran_booster_phase     = $args[0] ?? '';
$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_registry  = $ran_booster_container->make( ProviderRegistry::class );
$ran_booster_assert( $ran_booster_registry->is_sealed(), 'the registry is sealed for the request' );
$ran_booster_supplier  = $ran_booster_registry->github_supplier();
$ran_booster_claimants = $ran_booster_registry->github_claimants();
sort( $ran_booster_claimants );
$ran_booster_providers = $ran_booster_registry->all();
$ran_booster_failing   = $GLOBALS['ran_booster_supersede_failing_calls'] ?? null;

// The registered gh implementation must load from this package root.
$ran_booster_gh_from = static function ( string $plugin ) use ( $ran_booster_registry, $ran_booster_providers, $ran_booster_assert ): void {
	$ran_booster_assert( isset( $ran_booster_providers['gh'] ), 'gh is registered' );
	$provider = $ran_booster_registry->get( 'gh' );
	$metadata = $provider->get_metadata();
	$file     = ( new ReflectionClass( $provider ) )->getFileName();
	$ran_booster_assert( 'gh' === $metadata->code->value && 'https://github.com/' === $metadata->repository_url_base, 'gh carries GitHub metadata' );
	$ran_booster_assert( is_string( $file ) && str_starts_with( $file, WP_PLUGIN_DIR . '/' . $plugin . '/vendor/ran/booster-github-provider/src/' ), 'gh loads from ' . $plugin );
};

// Render the supplier notice through Core's container on the Plugins screen.
$ran_booster_render = static function () use ( $ran_booster_container ): string {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'plugins' );
	ob_start();
	try {
		$ran_booster_container->make( GitHubSupplierNotice::class )->render();
	} catch ( Throwable $failure ) {
		ob_end_clean();
		throw $failure;
	}
	return (string) ob_get_clean();
};

switch ( $ran_booster_phase ) {
	case 'bundled':
		$ran_booster_assert( ProviderRegistry::GITHUB_BUNDLED === $ran_booster_supplier && array() === $ran_booster_claimants, 'no claimant leaves the bundled supplier' );
		$ran_booster_gh_from( 'ran-booster' );
		$ran_booster_assert( null === $ran_booster_failing, 'no claimant callback ran' );
		break;
	case 'superseded':
		$ran_booster_assert( $ran_booster_extension === $ran_booster_supplier && array( $ran_booster_extension ) === $ran_booster_claimants, 'the fixture plugin supplies gh' );
		$ran_booster_gh_from( $ran_booster_extension );
		break;
	case 'conflict':
		$ran_booster_assert( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT === $ran_booster_supplier && array( $ran_booster_extension, $ran_booster_claimant ) === $ran_booster_claimants, 'two claimants conflict' );
		$ran_booster_assert( ! isset( $ran_booster_providers['gh'] ), 'a conflict leaves gh unregistered' );
		$ran_booster_assert( 0 === $ran_booster_failing, 'a conflict runs no claimant factory' );
		break;
	case 'failed':
		$ran_booster_assert( ProviderRegistry::GITHUB_SUPERSEDE_FAILED === $ran_booster_supplier && array( $ran_booster_claimant ) === $ran_booster_claimants, 'a throwing claimant fails' );
		$ran_booster_assert( 1 === $ran_booster_failing, 'the claimant factory ran once' );
		$ran_booster_assert( ! isset( $ran_booster_providers['gh'] ), 'a failed claim leaves gh unregistered, without the bundled fallback' );
		break;
	case 'notice-anonymous':
		$ran_booster_assert( 0 === get_current_user_id() && $ran_booster_extension === $ran_booster_supplier, 'runs without a user while the fixture supplies gh' );
		$ran_booster_assert( false === get_option( GitHubSupplierNotice::OPTION ), 'no earlier request recorded a shown supplier' );
		$ran_booster_assert( '' === $ran_booster_render(), 'no notice renders without an administrator' );
		wp_cache_delete( GitHubSupplierNotice::OPTION, 'options' );
		$ran_booster_assert( false === get_option( GitHubSupplierNotice::OPTION ), 'a request without an administrator records nothing' );
		break;
	case 'notice-administrator':
		$ran_booster_assert( current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' ), 'runs as the disposable-site administrator' );
		$ran_booster_assert( in_array( $ran_booster_supplier, array( ProviderRegistry::GITHUB_BUNDLED, $ran_booster_extension ), true ), 'runs while bundled or the fixture supplies gh' );
		$ran_booster_superseded = $ran_booster_extension === $ran_booster_supplier;
		$ran_booster_assert( ( $ran_booster_superseded ? false : $ran_booster_extension ) === get_option( GitHubSupplierNotice::OPTION ), 'the shown supplier differs from the current one' );
		$ran_booster_output = $ran_booster_render();
		$ran_booster_assert(
			str_contains( $ran_booster_output, $ran_booster_superseded ? 'GitHub is provided by RAN Booster GitHub Provider Extension Fixture.' : 'GitHub is provided by RAN Booster again.' ),
			'the administrator sees the supplier notice'
		);
		wp_cache_delete( GitHubSupplierNotice::OPTION, 'options' );
		$ran_booster_assert( ( $ran_booster_superseded ? $ran_booster_extension : false ) === get_option( GitHubSupplierNotice::OPTION ), 'rendering records the shown supplier' );
		$ran_booster_assert( '' === $ran_booster_render(), 'the notice shows once per supplier change' );
		break;
	default:
		throw new RuntimeException( 'Unknown GitHub supersede proof phase.' );
}

WP_CLI::success( 'GitHub supersede phase passed: ' . $ran_booster_phase ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

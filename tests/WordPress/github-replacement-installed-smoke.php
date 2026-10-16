<?php

// Each phase runs in a fresh WordPress process. No direct selection-row writes.

use RAN_Booster_GitHubReplacementFixture\Fixture;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\Secrets\SecretsFile;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\WordPress\WordPressUpdaterLock;

if ( ! class_exists( Fixture::class, false ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/github-replacement/fixture.php';
}
Fixture::guard();
$ran_booster_authorized = static fn (): bool => current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) ) {
	throw new RuntimeException( 'Replacement fixture phases require an authorized disposable-site WP-CLI administrator.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ran_booster_phase     = $args[0] ?? '';
$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_selection = $ran_booster_container->make( GitHubProviderSelection::class );
$ran_booster_registry  = $ran_booster_container->make( ProviderRegistry::class );
$ran_booster_assert    = static function ( bool $condition ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Installed replacement fixture assertion failed.' );
	}
};

switch ( $ran_booster_phase ) {
	case 'setup':
		$ran_booster_assert( ! is_plugin_active( 'github-replacement/a.php' ) && ! is_plugin_active( 'github-replacement/b.php' ) && ! is_plugin_active( 'github-replacement/incompatible.php' ) );
		delete_option( Fixture::COUNTERS );
		break;
	case 'activate-a':
	case 'activate-b':
	case 'incompatible':
	case 'conflict':
	case 'missing':
		deactivate_plugins( array( 'github-replacement/a.php', 'github-replacement/b.php', 'github-replacement/incompatible.php' ) );
		$ran_booster_entries = match ( $ran_booster_phase ) {
			'activate-a' => array( 'a' ),
			'activate-b' => array( 'b' ),
			'conflict' => array( 'a', 'b' ),
			'incompatible' => array( 'incompatible' ),
			default => array(),
		};
		foreach ( $ran_booster_entries as $ran_booster_entry ) {
			$ran_booster_result = activate_plugin( 'github-replacement/' . $ran_booster_entry . '.php' );
			$ran_booster_assert( ! is_wp_error( $ran_booster_result ) );
		}
		break;
	case 'select':
		$ran_booster_secrets = $ran_booster_container->make( SecretsFile::class );
		$ran_booster_review  = $ran_booster_selection->adoption_review( $ran_booster_secrets );
		$ran_booster_selection->transition_selection(
			$ran_booster_review['digest'],
			true,
			true,
			true,
			$ran_booster_secrets,
			$ran_booster_container->make( DeploymentAttemptRepository::class ),
			$ran_booster_container->make( WordPressUpdaterLock::class ),
			$ran_booster_authorized
		);
		break;
	case 'reset-counters':
		delete_option( Fixture::COUNTERS );
		break;
	case 'counters':
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric fixture counters on authenticated WP-CLI only.
		echo wp_json_encode( Fixture::counters() ) . "\n";
		break;
	case 'core-first':
	case 'replacement-first':
		$ran_booster_active = get_option( 'active_plugins', array() );
		$ran_booster_assert( is_array( $ran_booster_active ) );
		if ( ! is_array( $ran_booster_active ) ) {
			throw new RuntimeException( 'Active plugin list is unavailable.' );
		}
		$ran_booster_core   = 'ran-booster/ran-booster.php';
		$ran_booster_active = array_values( array_filter( $ran_booster_active, static fn ( mixed $plugin ): bool => $plugin !== $ran_booster_core ) );
		if ( 'core-first' === $ran_booster_phase ) {
			array_unshift( $ran_booster_active, $ran_booster_core );
		} else {
			$ran_booster_active[] = $ran_booster_core;
		}
		update_option( 'active_plugins', $ran_booster_active );
		break;
	case 'status':
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable redacted Core status on authenticated WP-CLI only.
		echo wp_json_encode( $ran_booster_selection->status() ) . "\n";
		break;
	case 'expect-bundled':
		$ran_booster_status = $ran_booster_selection->status();
		$ran_booster_assert( $ran_booster_status['available'] && 'bundled' === ( $ran_booster_status['current']['selected'] ?? null ) && array() === $ran_booster_status['candidates'] );
		$ran_booster_assert( $ran_booster_registry->get( 'gh' ) instanceof \RAN\BoosterGitHubProvider\V1\GitHubProvider );
		foreach ( Fixture::counters() as $ran_booster_count ) {
			$ran_booster_assert( 0 === $ran_booster_count );
		}
		break;
	case 'expect-unavailable':
		$ran_booster_assert( ! $ran_booster_selection->status()['available'] );
		$ran_booster_counts = Fixture::counters();
		foreach ( $ran_booster_counts as $ran_booster_count ) {
			$ran_booster_assert( 0 === $ran_booster_count );
		}
		break;
	case 'exercise-temporary':
		$ran_booster_container->make( SecretsFile::class )->with_temporary_credential(
			'gh',
			array(
				'label'         => 'Disposable fixture',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'ghp_' . str_repeat( 'a', 40 ),
			static fn ( string $id ) => $ran_booster_registry->get( 'gh' )->resolve_repository( new RepositoryLookupRequest( 'fixtures/example', $id ) )
		);
		$ran_booster_counts = Fixture::counters();
		$ran_booster_assert( 1 === $ran_booster_counts['a.credential_calls'] + $ran_booster_counts['b.credential_calls'] );
		$ran_booster_assert( 1 === $ran_booster_counts['a.credential_material_returns'] + $ran_booster_counts['b.credential_material_returns'] );
		break;
	case 'exercise':
		$ran_booster_assert( $ran_booster_selection->status()['available'] );
		$ran_booster_registry->get( 'gh' )->resolve_repository( new RepositoryLookupRequest( 'fixtures/example' ) );
		$ran_booster_counts = Fixture::counters();
		$ran_booster_assert( 1 === $ran_booster_counts['a.provider_calls'] + $ran_booster_counts['b.provider_calls'] );
		break;
	default:
		throw new RuntimeException( 'Unknown installed replacement fixture phase.' );
}

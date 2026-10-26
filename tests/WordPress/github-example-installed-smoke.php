<?php

declare(strict_types=1);

// Fresh-process proof only; selection changes use the real protected transition.

use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\Secrets\SecretsFile;
use RAN\WordPress\WordPressUpdaterLock;
use RAN_Booster_GitHubReplacementFixture\Fixture;

if ( ! class_exists( Fixture::class, false ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/github-replacement/fixture.php';
}
Fixture::guard();
$ran_booster_example_site       = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
$ran_booster_example_authorized = static fn (): bool => current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_GITHUB_EXAMPLE_DISPOSABLE' )
	|| false === $ran_booster_example_site || rtrim( ABSPATH, '/' ) !== $ran_booster_example_site
	|| ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
	throw new RuntimeException( 'Example proof requires the exact marked disposable site and administrator.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ran_booster_example_phase    = $args[0] ?? '';
$ran_booster_example_basename = 'ran-booster-github-example/ran-booster-github-example.php';
$ran_booster_example_core     = 'ran-booster/ran-booster.php';
$ran_booster_example_target   = WP_PLUGIN_DIR . '/ran-booster-github-example';
$ran_booster_example_check    = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Example installed proof: ' . esc_html( $message ) );
	}
};
$ran_booster_example_check( is_plugin_active( $ran_booster_example_core ), 'Core must be active' );
foreach ( array( 'github-replacement/a.php', 'github-replacement/b.php', 'github-replacement/incompatible.php' ) as $ran_booster_example_other ) {
	$ran_booster_example_check( ! is_plugin_active( $ran_booster_example_other ), 'synthetic replacement candidates must be inactive' );
}
$ran_booster_example_active = get_option( 'active_plugins', array() );
if ( ! is_array( $ran_booster_example_active ) || ! array_is_list( $ran_booster_example_active ) ) {
	throw new RuntimeException( 'Active plugin order must be a list.' );
}
foreach ( $ran_booster_example_active as $ran_booster_example_entry ) {
	$ran_booster_example_check( is_string( $ran_booster_example_entry ), 'active plugin identifiers must be strings' );
}
if ( 'setup' !== $ran_booster_example_phase ) {
	$ran_booster_example_check( ! is_link( $ran_booster_example_target ) && realpath( $ran_booster_example_target ) === $ran_booster_example_target, 'canonical installed Example root' );
	$ran_booster_example_header  = get_plugin_data( $ran_booster_example_target . '/ran-booster-github-example.php', false, false );
	$ran_booster_example_version = getenv( 'RAN_BOOSTER_EXAMPLE_EXPECTED_VERSION' );
	$ran_booster_example_check( is_string( $ran_booster_example_version ) && '' !== $ran_booster_example_version && $ran_booster_example_version === $ran_booster_example_header['Version'], 'published Example version' );
}
if ( 'activate' === $ran_booster_example_phase ) {
	$ran_booster_example_check( ! is_wp_error( activate_plugin( $ran_booster_example_basename ) ), 'native dependency-aware plugin activation' );
	return;
}
if ( 'deactivate' === $ran_booster_example_phase ) {
	deactivate_plugins( $ran_booster_example_basename );
	return;
}
if ( 'core-first' === $ran_booster_example_phase || 'example-first' === $ran_booster_example_phase ) {
	$ran_booster_example_check( is_plugin_active( $ran_booster_example_basename ), 'Example must be active before changing load order' );
	$ran_booster_example_others = array_values( array_filter( $ran_booster_example_active, static fn ( mixed $entry ): bool => ! in_array( $entry, array( $ran_booster_example_core, $ran_booster_example_basename ), true ) ) );
	$ran_booster_example_order  = 'core-first' === $ran_booster_example_phase
		? array( $ran_booster_example_core, $ran_booster_example_basename ) : array( $ran_booster_example_basename, $ran_booster_example_core );
	update_option( 'active_plugins', array_merge( $ran_booster_example_order, $ran_booster_example_others ) );
	return;
}
if ( 'restore-order' === $ran_booster_example_phase ) {
	$ran_booster_example_original = json_decode( (string) getenv( 'RAN_BOOSTER_EXAMPLE_ORIGINAL_ACTIVE' ), true, 4, JSON_THROW_ON_ERROR );
	$ran_booster_example_check( is_array( $ran_booster_example_original ) && array_is_list( $ran_booster_example_original ) && ! in_array( $ran_booster_example_basename, $ran_booster_example_original, true ), 'original active order' );
	if ( ! is_array( $ran_booster_example_original ) ) {
		throw new RuntimeException( 'Original active order is unavailable.' );
	}
	$ran_booster_example_current_sorted  = $ran_booster_example_active;
	$ran_booster_example_original_sorted = $ran_booster_example_original;
	sort( $ran_booster_example_current_sorted );
	sort( $ran_booster_example_original_sorted );
	$ran_booster_example_check( $ran_booster_example_current_sorted === $ran_booster_example_original_sorted, 'restore must not discard other active plugins' );
	update_option( 'active_plugins', $ran_booster_example_original );
	return;
}
$ran_booster_example_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_example_selection = $ran_booster_example_container->make( GitHubProviderSelection::class );
$ran_booster_example_registry  = $ran_booster_example_container->make( ProviderRegistry::class );
$ran_booster_example_status    = $ran_booster_example_selection->status();
if ( 'setup' === $ran_booster_example_phase || 'expect-bundled' === $ran_booster_example_phase ) {
	$ran_booster_example_check( $ran_booster_example_status['available'] && 'bundled' === ( $ran_booster_example_status['current']['selected'] ?? null ) && array() === $ran_booster_example_status['candidates'], 'approved bundled state before and after proof' );
	if ( 'setup' === $ran_booster_example_phase ) {
		$ran_booster_example_check( ! file_exists( $ran_booster_example_target ) && ! is_link( $ran_booster_example_target ), 'previously absent Example destination' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact active-plugin list is returned only to the owning disposable CLI runner for restoration; no credential data is included.
		echo wp_json_encode( $ran_booster_example_active ) . "\n";
	}
	return;
}
if ( 'expect-unavailable' === $ran_booster_example_phase ) {
	$ran_booster_example_check( ! $ran_booster_example_status['available'] && isset( $ran_booster_example_status['candidates'][ $ran_booster_example_basename ] ), 'activation must require an adoption decision' );
	return;
}
if ( 'select-example' === $ran_booster_example_phase || 'select-bundled' === $ran_booster_example_phase ) {
	$ran_booster_example_secrets  = $ran_booster_example_container->make( SecretsFile::class );
	$ran_booster_example_review   = $ran_booster_example_selection->adoption_review( $ran_booster_example_secrets );
	$ran_booster_example_expected = 'select-example' === $ran_booster_example_phase ? 'replacement' : 'bundled';
	$ran_booster_example_check( $ran_booster_example_expected === $ran_booster_example_review['selected'] && ! $ran_booster_example_review['has_material'] && array() === $ran_booster_example_review['profiles'], 'exact target and no credential material to reuse or mutate' );
	if ( 'replacement' === $ran_booster_example_expected ) {
		$ran_booster_example_check( ( $ran_booster_example_review['target']['plugin_basename'] ?? null ) === $ran_booster_example_basename && 'ran/booster-github-example' === ( $ran_booster_example_review['target']['implementation_id'] ?? null ), 'exact published replacement declaration' );
	}
	$ran_booster_example_selection->transition_selection(
		$ran_booster_example_review['digest'],
		true,
		false,
		false,
		$ran_booster_example_secrets,
		$ran_booster_example_container->make( DeploymentAttemptRepository::class ),
		$ran_booster_example_container->make( WordPressUpdaterLock::class ),
		$ran_booster_example_authorized
	);
	return;
}
if ( 'exercise' !== $ran_booster_example_phase ) {
	throw new RuntimeException( 'Unknown Example installed proof phase.' );
}
$ran_booster_example_check( $ran_booster_example_status['available'] && 'replacement' === ( $ran_booster_example_status['current']['selected'] ?? null ) && ( $ran_booster_example_status['current']['declaration']['plugin_basename'] ?? null ) === $ran_booster_example_basename, 'fresh request uses the explicitly adopted Example' );
$ran_booster_example_requests  = array();
$ran_booster_example_transport = static function ( mixed $response, array $arguments, string $url ) use ( &$ran_booster_example_requests ): array {
	unset( $response );
	$headers = $arguments['headers'] ?? null;
	if ( ! is_array( $headers ) || 0 !== ( $arguments['redirection'] ?? null ) || true !== ( $arguments['reject_unsafe_urls'] ?? null ) ) {
		throw new RuntimeException( 'Unexpected HTTP shape during anonymous Example lookup.' );
	}
	foreach ( array_keys( $headers ) as $header ) {
		if ( ! is_string( $header ) || in_array( strtolower( $header ), array( 'authorization', 'proxy-authorization', 'cookie' ), true ) ) {
			throw new RuntimeException( 'Credential-bearing HTTP during anonymous Example proof.' );
		}
	}
	$repository = array(
		'id'             => 123456789,
		'full_name'      => 'ran-fixtures/example-consumer',
		'name'           => 'example-consumer',
		'private'        => false,
		'default_branch' => 'main',
	);
	$payload    = match ( $url ) {
		'https://api.github.com/repos/ran-fixtures/example-consumer' => $repository,
		'https://api.github.com/users/ran-fixtures' => array(
			'login' => 'ran-fixtures',
			'type'  => 'Organization',
		),
		'https://api.github.com/orgs/ran-fixtures/repos?type=public&per_page=30&page=1&sort=full_name' => array( $repository ),
		default => throw new RuntimeException( 'Example proof refuses every uncontrolled HTTP request.' ),
	};
	$body = wp_json_encode( $payload );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'Controlled Example response encoding failed.' );
	}
	$ran_booster_example_requests[] = $url;
	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $ran_booster_example_transport, PHP_INT_MIN, 3 );
try {
	$ran_booster_example_provider = $ran_booster_example_registry->get( 'gh' );
	$ran_booster_example_check( $ran_booster_example_provider instanceof GitHubProvider, 'actual Composer provider instance' );
	if ( ! $ran_booster_example_provider instanceof GitHubProvider ) {
		throw new RuntimeException( 'The actual package provider is unavailable.' );
	}
	$ran_booster_example_source = ( new ReflectionClass( $ran_booster_example_provider ) )->getFileName();
	$ran_booster_example_copies = array( WP_PLUGIN_DIR . '/ran-booster/vendor/ran/booster-github-provider/src/GitHubProvider.php', $ran_booster_example_target . '/vendor/ran/booster-github-provider/src/GitHubProvider.php' );
	$ran_booster_example_check( is_string( $ran_booster_example_source ) && in_array( $ran_booster_example_source, $ran_booster_example_copies, true ) && hash_file( 'sha256', $ran_booster_example_source ) === hash_file( 'sha256', $ran_booster_example_copies[1] ), 'provider bytes come from a matching installed package copy' );
	$ran_booster_example_check( array() === $ran_booster_example_requests, 'provider composition must not perform HTTP' );
	$ran_booster_example_repository = $ran_booster_example_provider->resolve_repository( new RepositoryLookupRequest( 'ran-fixtures/example-consumer', null, true ) );
	$ran_booster_example_browse     = $ran_booster_example_provider->browse_repositories( RepositoryBrowseRequest::public_owner( 'ran-fixtures' ) );
	$ran_booster_example_check( '123456789' === $ran_booster_example_repository->provider_repository_id && 'ran-fixtures/example-consumer' === $ran_booster_example_repository->locator && ! $ran_booster_example_repository->private && null === $ran_booster_example_repository->credential_id, 'real anonymous repository resolution' );
	$ran_booster_example_check( ! $ran_booster_example_browse->is_partial() && 1 === count( $ran_booster_example_browse->repositories ) && $ran_booster_example_repository->to_array() === $ran_booster_example_browse->repositories[0]->to_array() && 3 === count( $ran_booster_example_requests ), 'real anonymous browsing through controlled WordPress HTTP' );
} finally {
	remove_filter( 'pre_http_request', $ran_booster_example_transport, PHP_INT_MIN );
}

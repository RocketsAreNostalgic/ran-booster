<?php

// Read-only connected proof; the owning shell installs the immutable add-on and sets GH state.

use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN_Booster_GitHubReplacementFixture\Fixture;

if ( ! class_exists( Fixture::class, false ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/github-replacement/fixture.php';
}
Fixture::guard();

$ran_booster_bb_site     = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
$ran_booster_bb_expected = getenv( 'RAN_BOOSTER_BITBUCKET_EXPECT_GH' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| '1' !== getenv( 'RAN_BOOSTER_BITBUCKET_TEST_DISPOSABLE' )
	|| ! in_array( $ran_booster_bb_expected, array( 'available', 'unavailable' ), true )
	|| false === $ran_booster_bb_site || rtrim( ABSPATH, '/' ) !== $ran_booster_bb_site
	|| WP_CONTENT_DIR !== $ran_booster_bb_site . '/wp-content'
	|| WP_PLUGIN_DIR !== $ran_booster_bb_site . '/wp-content/plugins'
	|| Fixture::expected_origin() !== get_option( 'siteurl' ) ) {
	throw new RuntimeException( 'Bitbucket selection proof requires the marked disposable CI site and explicit GH availability.' );
}
$ran_booster_bb_marker = $ran_booster_bb_site . '/.ran-booster-disposable-test-site';
if ( ! is_file( $ran_booster_bb_marker ) || is_link( $ran_booster_bb_marker ) ) {
	throw new RuntimeException( 'Disposable marker is unavailable.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact disposable fixture marker without a filesystem abstraction.
$ran_booster_bb_marker_bytes = file_get_contents( $ran_booster_bb_marker );
if ( ! is_string( $ran_booster_bb_marker_bytes ) || 'RAN Booster disposable test site' !== trim( $ran_booster_bb_marker_bytes ) ) {
	throw new RuntimeException( 'Disposable marker does not match.' );
}
$ran_booster_bb_root = WP_PLUGIN_DIR . '/ran-booster-bitbucket';
foreach ( array( $ran_booster_bb_site, WP_CONTENT_DIR, WP_PLUGIN_DIR, $ran_booster_bb_root ) as $ran_booster_bb_path ) {
	if ( is_link( $ran_booster_bb_path ) || realpath( $ran_booster_bb_path ) !== $ran_booster_bb_path ) {
		throw new RuntimeException( 'Bitbucket proof refuses shared or noncanonical roots.' );
	}
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ran_booster_bb_headers = get_plugin_data( $ran_booster_bb_root . '/ran-booster-bitbucket.php', false, false );
if ( '0.1.0-beta.17' !== $ran_booster_bb_headers['Version']
	|| ! is_plugin_active( 'ran-booster-bitbucket/ran-booster-bitbucket.php' )
	|| ! is_plugin_active( 'ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'The exact released Bitbucket and Core must be active.' );
}
// Archive digest and complete installed-byte equality are established by the owning shell.
$ran_booster_bb_requests      = (object) array( 'count' => 0 );
$ran_booster_bb_request_count = static fn (): int => $ran_booster_bb_requests->count;
$ran_booster_bb_transport     = static function ( mixed $response, array $arguments, string $url ) use ( &$ran_booster_bb_requests ): array {
	unset( $response );
	$headers = $arguments['headers'] ?? null;
	if ( ! is_array( $headers ) ) {
		throw new RuntimeException( 'Unexpected HTTP header representation.' );
	}
	foreach ( array_keys( $headers ) as $header ) {
		if ( ! is_string( $header ) || in_array( strtolower( $header ), array( 'authorization', 'proxy-authorization', 'cookie' ), true ) ) {
			throw new RuntimeException( 'Credential-bearing HTTP header during public Bitbucket lookup.' );
		}
	}
	if ( 'https://api.bitbucket.org/2.0/repositories/rocketsarenostalgic/ran-booster-fixture-public-plugin?fields=uuid%2Cfull_name%2Cis_private%2Cmainbranch.name' !== $url
		|| 0 !== ( $arguments['redirection'] ?? null )
		|| 262144 !== ( $arguments['limit_response_size'] ?? null )
		|| true !== ( $arguments['reject_unsafe_urls'] ?? null )
		|| 'application/json' !== ( $arguments['headers']['Accept'] ?? null )
		|| 'RAN-Booster' !== ( $arguments['headers']['User-Agent'] ?? null ) ) {
		throw new RuntimeException( 'Unexpected or credential-bearing HTTP request during Bitbucket selection proof.' );
	}
	++$ran_booster_bb_requests->count;
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(
			array(
				'uuid'       => '{5a314489-3bbb-4914-9568-01ee989751e1}',
				'full_name'  => 'rocketsarenostalgic/ran-booster-fixture-public-plugin',
				'is_private' => false,
				'mainbranch' => array( 'name' => 'main' ),
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $ran_booster_bb_transport, PHP_INT_MIN, 3 );
try {
	$ran_booster_bb_container = require __DIR__ . '/core-container-fixture.php';
	$ran_booster_bb_selection = $ran_booster_bb_container->make( GitHubProviderSelection::class );
	$ran_booster_bb_registry  = $ran_booster_bb_container->make( ProviderRegistry::class );
	$ran_booster_bb_available = 'available' === $ran_booster_bb_expected;
	if ( $ran_booster_bb_available !== $ran_booster_bb_selection->operation_allowed()
		|| ! $ran_booster_bb_registry->is_sealed() ) {
		throw new RuntimeException( 'Installed GH authority does not match the explicit proof phase.' );
	}
	$ran_booster_bb_provider   = $ran_booster_bb_registry->get( 'bb' );
	$ran_booster_bb_reflection = new ReflectionClass( $ran_booster_bb_provider );
	$ran_booster_bb_file       = $ran_booster_bb_reflection->getFileName();
	if ( ! str_starts_with( $ran_booster_bb_reflection->getName(), 'RAN\\Booster\\Bitbucket\\' )
		|| false === $ran_booster_bb_file || ! str_starts_with( $ran_booster_bb_file, $ran_booster_bb_root . '/' )
		|| 'bb' !== $ran_booster_bb_provider->get_metadata()->code->value ) {
		throw new RuntimeException( 'Core did not provide the actual installed Bitbucket implementation without network access.' );
	}
	( static function ( int $count ): void {
		if ( 0 !== $count ) {
			throw new RuntimeException( 'Provider composition performed unexpected HTTP.' );
		}
	} )( $ran_booster_bb_request_count() );
	$ran_booster_bb_repository = $ran_booster_bb_provider->resolve_repository(
		new RepositoryLookupRequest( 'rocketsarenostalgic/ran-booster-fixture-public-plugin', null, true )
	);
	if ( 1 !== $ran_booster_bb_request_count()
		|| 'rocketsarenostalgic/ran-booster-fixture-public-plugin' !== $ran_booster_bb_repository->locator
		|| '{5a314489-3bbb-4914-9568-01ee989751e1}' !== $ran_booster_bb_repository->provider_repository_id
		|| $ran_booster_bb_repository->private || 'main' !== $ran_booster_bb_repository->default_branch
		|| $ran_booster_bb_available !== $ran_booster_bb_selection->operation_allowed() ) {
		throw new RuntimeException( 'Bitbucket public lookup or unchanged GH authority assertion failed.' );
	}
} finally {
	remove_filter( 'pre_http_request', $ran_booster_bb_transport, PHP_INT_MIN );
}
echo 'Actual installed Bitbucket public lookup passed with the expected GH availability.' . PHP_EOL;

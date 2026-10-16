<?php

// Execute the real installed PackageOperation -> admitted Branch -> WordPress path.

use RAN\AdoptionIdentity;
use RAN\Deployment\DeploymentPolicy;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\Storage\PluginRepository;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN_Booster_GitHubReplacementFixture\Fixture;

if ( ! class_exists( Fixture::class, false ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/github-replacement/fixture.php';
}
require_once __DIR__ . '/disposable-management-cleanup.php';
Fixture::guard();
$ran_booster_root = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_GITHUB_HISTORICAL_DISPOSABLE' )
	|| rtrim( ABSPATH, '/' ) !== $ran_booster_root || 'http://localhost' !== get_option( 'home' )
	|| ! current_user_can( 'install_plugins' ) || ! current_user_can( 'manage_options' )
	|| ! is_plugin_active( 'github-replacement/a.php' ) || ! is_plugin_active( 'ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'Historical proof requires the exact disposable installed Core administrator.' );
}
$ran_booster_branch_path = WP_PLUGIN_DIR . '/ran-booster/vendor/ran/wp-branch-updater/src/Runtime/BranchUpdater.php';
if ( ! in_array( constant( BranchUpdater::class . '::ADMITTED_API_VERSION' ), array( 2, 3 ), true ) || realpath( $ran_booster_branch_path ) !== $ran_booster_branch_path
	|| ( new ReflectionClass( BranchUpdater::class ) )->getFileName() !== $ran_booster_branch_path ) {
	throw new RuntimeException( 'Historical proof requires the installed Branch admitted API 2 or 3.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$ran_booster_container  = require __DIR__ . '/core-container-fixture.php';
$ran_booster_operations = $ran_booster_container->make( PackageOperationService::class );
$ran_booster_plugins    = $ran_booster_container->make( PluginRepository::class );
$ran_booster_phase      = $args[0] ?? '';
$ran_booster_slug       = 'ran-gh-historical-proof';
$ran_booster_id         = $ran_booster_slug . '/proof.php';
$ran_booster_target     = WP_PLUGIN_DIR . '/' . $ran_booster_slug;
$ran_booster_commit     = str_repeat( 'a', 40 );
$ran_booster_check      = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Historical installed proof: ' . esc_html( $message ) );
	}
};
$ran_booster_check( ! file_exists( $ran_booster_target ) && ! is_link( $ran_booster_target ) && ! $ran_booster_plugins->has_management_record( $ran_booster_id ), 'target must be absent before proof' );
$ran_booster_zip_path = wp_tempnam( 'ran-gh-historical.zip' );
$ran_booster_zip      = new ZipArchive();
$ran_booster_check( true === $ran_booster_zip->open( $ran_booster_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'create controlled archive' );
$ran_booster_zip->addFromString( 'repository/packages/target/proof.php', "<?php\n/*\nPlugin Name: Historical fixture\nVersion: 1.0.0\n*/\n" );
$ran_booster_zip->addFromString( 'repository/packages/target/commit.txt', $ran_booster_commit );
$ran_booster_zip->addFromString( 'repository/outside.txt', 'excluded source' );
$ran_booster_zip->close();
$ran_booster_downloads    = 0;
$ran_booster_mutations    = 0;
$ran_booster_destinations = array();
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The fixture deliberately denies all non-fixture HTTP, regardless of previous preemption.
$ran_booster_transport = static function ( mixed $preempt, array $arguments, string $url ) use ( $ran_booster_zip_path, &$ran_booster_downloads, &$ran_booster_destinations ): mixed {
	if ( 'https://github.com/ran-fixtures/historical/archive.zip' !== $url ) {
		return new WP_Error( 'fixture_network_denied', 'Historical proof refuses external network requests.' );
	}
	$destination = $arguments['filename'] ?? null;
	if ( true !== ( $arguments['stream'] ?? null ) || ! is_string( $destination ) || is_link( $destination ) ) {
		throw new RuntimeException( 'Expected the real admitted Branch streaming download.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Supply controlled ZIP bytes through the actual WordPress HTTP streaming boundary.
	if ( ! copy( $ran_booster_zip_path, $destination ) ) {
		throw new RuntimeException( 'Fixture ZIP streaming failed.' );
	}
	++$ran_booster_downloads;
	$ran_booster_destinations[] = $destination;
	return array(
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'headers'  => array(),
		'body'     => '',
		'cookies'  => array(),
		'filename' => $destination,
	);
};
$ran_booster_observe   = static function ( mixed $response ) use ( &$ran_booster_mutations ): mixed {
	++$ran_booster_mutations;
	return $response;
};
add_filter( 'pre_http_request', $ran_booster_transport, 10, 3 );
add_filter( 'upgrader_pre_install', $ran_booster_observe );
delete_option( 'ran_booster_historical_fixture_archive' );
try {
	$ran_booster_input   = array(
		'provider'                            => 'gh',
		'repository'                          => 'fixtures/historical',
		'provider_repository_id'              => 'replacement-fixture:fixtures/historical',
		'provider_repository_identity_source' => 'resolved',
		'branch'                              => 'main',
		'package_slug'                        => $ran_booster_slug,
		'subdirectory'                        => 'packages/target',
		'selected_commit'                     => $ran_booster_commit,
		'deployment_policy'                   => 'disabled',
	);
	$ran_booster_result  = $ran_booster_operations->execute( PackageOperation::from_input( 'install-plugin', $ran_booster_input ) );
	$ran_booster_archive = get_option( 'ran_booster_historical_fixture_archive', array() );
	if ( in_array( $ran_booster_phase, array( 'unsupported', 'wrong-commit' ), true ) ) {
		$ran_booster_expected = 'unsupported' === $ran_booster_phase ? 'provider_unavailable' : 'archive_revision_invalid';
		$ran_booster_check( 'failed' === $ran_booster_result['status'] && ( $ran_booster_result['outcome_code'] ?? null ) === $ran_booster_expected, 'precise capability/revision refusal' );
		$ran_booster_check( 0 === $ran_booster_downloads && 0 === $ran_booster_mutations && ! file_exists( $ran_booster_target ) && ! $ran_booster_plugins->has_management_record( $ran_booster_id ), 'refusal precedes download/native mutation/adoption' );
		$ran_booster_check( 'unsupported' === $ran_booster_phase ? array() === $ran_booster_archive : is_array( $ran_booster_archive ) && true === ( $ran_booster_archive['cleaned'] ?? false ), 'prepared archive cleanup or no preparation' );
	} else {
		$ran_booster_check( 'success' === $ran_booster_phase && 'succeeded' === $ran_booster_result['status'], 'historical native installation succeeds' );
		$ran_booster_check( is_array( $ran_booster_archive ) && ( $ran_booster_archive['selected_commit'] ?? null ) === $ran_booster_commit && ( $ran_booster_archive['ref'] ?? null ) === $ran_booster_commit && null === ( $ran_booster_archive['expected_branch'] ?? null ) && true === ( $ran_booster_archive['verified'] ?? false ) && true === ( $ran_booster_archive['cleaned'] ?? false ), 'exact selected commit and final verification despite advanced fixture branch' );
		$ran_booster_package = $ran_booster_plugins->booster_plugin_from_file( $ran_booster_id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read actual installed bytes to prove exact historical archive installation.
		$ran_booster_check( file_get_contents( $ran_booster_target . '/commit.txt' ) === $ran_booster_commit && ! file_exists( $ran_booster_target . '/outside.txt' ) && ! is_plugin_active( $ran_booster_id ), 'exact source relocation and inactive installation' );
		$ran_booster_check( 'main' === (string) $ran_booster_package->get_branch() && DeploymentPolicy::DISABLED === $ran_booster_package->get_deployment_policy() && 1 === $ran_booster_downloads && 1 === $ran_booster_mutations, 'tracking branch preserved and Disabled adoption' );
		$ran_booster_update = array(
			'file'            => $ran_booster_id,
			'repository'      => 'fixtures/historical',
			'selected_commit' => $ran_booster_commit,
		);
		foreach ( array(
			'provider'               => 'gh',
			'provider_repository_id' => $ran_booster_package->get_provider_repository_id(),
			'repository'             => (string) $ran_booster_package->get_repository(),
			'branch'                 => 'main',
			'credential_id'          => '',
			'subdirectory'           => 'packages/target',
			'private'                => false,
			'package_slug'           => $ran_booster_slug,
			'deployment_policy'      => 'disabled',
			'source'                 => $ran_booster_package->get_source()->value,
			'source_revision'        => $ran_booster_package->get_source_revision(),
			'adoption_token'         => AdoptionIdentity::of( $ran_booster_package ),
		) as $ran_booster_key => $ran_booster_value ) {
			$ran_booster_update[ 'expected_' . $ran_booster_key ] = $ran_booster_value;
		}
		$ran_booster_stale                    = $ran_booster_update;
		$ran_booster_stale['expected_source'] = 'release_asset';
		$ran_booster_source_refused           = false;
		try {
			$ran_booster_operations->execute( PackageOperation::from_input( 'update-plugin', $ran_booster_stale ) );
		} catch ( RuntimeException $ran_booster_failure ) {
			$ran_booster_source_refused = str_contains( $ran_booster_failure->getMessage(), 'changed after' );
		}
		$ran_booster_check( $ran_booster_source_refused && 1 === $ran_booster_downloads && 1 === $ran_booster_mutations, 'historical selection cannot bypass the current source snapshot' );
		$ran_booster_refused = false;
		try {
			$ran_booster_operations->execute( PackageOperation::from_input( 'update-plugin', $ran_booster_update ) );
		} catch ( RuntimeException $ran_booster_failure ) {
			$ran_booster_refused = str_contains( $ran_booster_failure->getMessage(), 'disabled' );
		}
		$ran_booster_check( $ran_booster_refused && 1 === $ran_booster_downloads && 1 === $ran_booster_mutations, 'matching historical request cannot bypass Disabled policy' );
	}
	foreach ( $ran_booster_destinations as $ran_booster_destination ) {
		$ran_booster_check( ! file_exists( $ran_booster_destination ), 'Branch downloaded artifact cleaned' );
	}
} finally {
	remove_filter( 'pre_http_request', $ran_booster_transport, 10 );
	remove_filter( 'upgrader_pre_install', $ran_booster_observe );
	wp_delete_file( $ran_booster_zip_path );
	$ran_booster_check( ! is_link( $ran_booster_target ) && ( ! file_exists( $ran_booster_target ) || realpath( $ran_booster_target ) === $ran_booster_target ), 'canonical cleanup target' );
	ran_booster_cleanup_fixture_management( $ran_booster_plugins, $ran_booster_id );
	if ( is_dir( $ran_booster_target ) ) {
		$ran_booster_check( true === delete_plugins( array( $ran_booster_id ) ), 'native fixture deletion' );
	}
	delete_option( 'ran_booster_historical_fixture_archive' );
}
echo 'Installed historical proof passed: ' . esc_html( $ran_booster_phase ) . "\n";

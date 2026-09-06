<?php

// Executed only by the marked installed-CI runner before its fresh lifecycle request.
// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The native lifecycle seed requires the marked installed CI site.' );
}

$scale = getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' );
if ( ! is_string( $scale ) || ! in_array( $scale, array( '1', '5', '10', '20' ), true ) ) {
	throw new RuntimeException( 'The native lifecycle seed scale is invalid.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

$wordpressRoot = realpath( ABSPATH );
$contentRoot   = realpath( WP_CONTENT_DIR );
$pluginRoot    = realpath( WP_PLUGIN_DIR );
$themeRoot     = realpath( get_theme_root() );
if ( false === $wordpressRoot || false === $contentRoot || false === $pluginRoot || false === $themeRoot
	|| $contentRoot !== $wordpressRoot . '/wp-content' || $pluginRoot !== $contentRoot . '/plugins' || $themeRoot !== $contentRoot . '/themes'
	|| 'http://localhost' !== get_option( 'siteurl' ) || is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'The native lifecycle seed refuses an unverified installed Core site.' );
}

global $wpdb;
$table = ran_booster_table_name();
$items = array();
$archives = array();
for ( $number = 1; $number <= (int) $scale; ++$number ) {
	$type       = 0 === $number % 2 ? 'theme' : 'plugin';
	$root       = 'ran-booster-c4-' . $type . '-' . $number;
	$identifier = 'plugin' === $type ? $root . '/' . $root . '.php' : $root;
	$directory  = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $root;
	if ( file_exists( $directory ) || is_link( $directory ) ) {
		throw new RuntimeException( 'The native lifecycle fixture target already exists.' );
	}
}
for ( $number = 1; $number <= (int) $scale; ++$number ) {
	$type       = 0 === $number % 2 ? 'theme' : 'plugin';
	$root       = 'ran-booster-c4-' . $type . '-' . $number;
	$identifier = 'plugin' === $type ? $root . '/' . $root . '.php' : $root;
	$directory  = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $root;
	if ( ! mkdir( $directory, 0700, true ) ) {
		throw new RuntimeException( 'The native lifecycle fixture directory is unsafe.' );
	}
	$items[] = array( 'number' => $number, 'type' => $type, 'identifier' => $identifier, 'directory' => $directory );
	update_option( 'ran_booster_c4_native_items', $items, false );
	$repository = 'ran-booster-c4/' . $root;
	$metadata   = 'plugin' === $type ? $root . '.php' : 'style.css';
	$contents   = 'plugin' === $type
		? "<?php\n/*\nPlugin Name: C4 $number\nVersion: 1.0.0\nUpdate URI: https://github.com/$repository\n*/\n"
		: "/*\nTheme Name: C4 $number\nVersion: 1.0.0\nUpdate URI: https://github.com/$repository\n*/\n";
	file_put_contents( $directory . '/' . $metadata, $contents );
	if ( 'theme' === $type ) {
		file_put_contents( $directory . '/index.php', '<?php' );
	}
	$archive = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) . '/' . $root . '.zip';
	$zip     = new ZipArchive();
	if ( ! is_string( $archive ) || true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'The native lifecycle archive fixture is unavailable.' );
	}
	$updatedContents = str_replace( '1.0.0', '2.0.0', $contents );
	$zip->addFromString( $root . '/' . $metadata, $updatedContents );
	if ( 'theme' === $type ) {
		$zip->addFromString( $root . '/index.php', '<?php' );
	}
	$zip->close();
	$configuration = json_encode( array( 'channel' => 'stable', 'package_root' => $root, 'metadata_file' => $metadata ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	$inserted      = $wpdb->insert(
		$table,
		array(
			'package'                => $identifier,
			'repository'             => $repository,
			'branch'                 => 'main',
			'type'                   => 'plugin' === $type ? 1 : 2,
			'deployment_policy'      => 0 === $number % 4 ? 'automatic' : 'manual',
			'source'                 => 'release_asset',
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => (string) ( 940000 + $number ),
			'private'                => 0,
			'release_configuration'  => $configuration,
		)
	);
	if ( 1 !== $inserted ) {
		throw new RuntimeException( 'The native lifecycle fixture row was not persisted.' );
	}
	$items[ count( $items ) - 1 ] += array( 'repository' => $repository, 'repository_id' => (string) ( 940000 + $number ), 'expected_digest' => hash( 'sha256', $updatedContents ) );
	update_option( 'ran_booster_c4_native_items', $items, false );
	$archives[ $repository ] = $archive;
}
update_option( 'ran_booster_c4_native_items', $items, false );
update_option( 'ran_booster_c4_native_archives', $archives, false );
WP_CLI::success( 'Seeded ' . $scale . ' installed native lifecycle targets.' );

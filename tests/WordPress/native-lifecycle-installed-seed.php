<?php

// Executed only by the marked installed-CI runner before its fresh lifecycle request.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The native lifecycle seed requires the marked installed CI site.' );
}

$ran_booster_scale = getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' );
if ( ! is_string( $ran_booster_scale ) || ! in_array( $ran_booster_scale, array( '1', '5', '10', '20' ), true ) ) {
	throw new RuntimeException( 'The native lifecycle seed scale is invalid.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

$ran_booster_wordpress_root = realpath( ABSPATH );
$ran_booster_content_root   = realpath( WP_CONTENT_DIR );
$ran_booster_plugin_root    = realpath( WP_PLUGIN_DIR );
$ran_booster_theme_root     = realpath( get_theme_root() );
if ( false === $ran_booster_wordpress_root || false === $ran_booster_content_root || false === $ran_booster_plugin_root || false === $ran_booster_theme_root
	|| $ran_booster_content_root !== $ran_booster_wordpress_root . '/wp-content' || $ran_booster_plugin_root !== $ran_booster_content_root . '/plugins' || $ran_booster_theme_root !== $ran_booster_content_root . '/themes'
	|| 'http://localhost' !== get_option( 'siteurl' ) || is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( WP_PLUGIN_DIR . '/ran-booster/ran-booster.php' ) ) {
	throw new RuntimeException( 'The native lifecycle seed refuses an unverified installed Core site.' );
}

global $wpdb;
$ran_booster_table    = ran_booster_table_name();
$ran_booster_items    = array();
$ran_booster_archives = array();
for ( $ran_booster_number = 1; $ran_booster_number <= (int) $ran_booster_scale; ++$ran_booster_number ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$type                   = 0 === $ran_booster_number % 2 ? 'theme' : 'plugin';
	$ran_booster_root       = 'ran-booster-c4-' . $type . '-' . $ran_booster_number;
	$ran_booster_identifier = 'plugin' === $type ? $ran_booster_root . '/' . $ran_booster_root . '.php' : $ran_booster_root;
	$ran_booster_directory  = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $ran_booster_root;
	if ( file_exists( $ran_booster_directory ) || is_link( $ran_booster_directory ) ) {
		throw new RuntimeException( 'The native lifecycle fixture target already exists.' );
	}
}
for ( $ran_booster_number = 1; $ran_booster_number <= (int) $ran_booster_scale; ++$ran_booster_number ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$type                   = 0 === $ran_booster_number % 2 ? 'theme' : 'plugin';
	$ran_booster_root       = 'ran-booster-c4-' . $type . '-' . $ran_booster_number;
	$ran_booster_identifier = 'plugin' === $type ? $ran_booster_root . '/' . $ran_booster_root . '.php' : $ran_booster_root;
	$ran_booster_directory  = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $ran_booster_root;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	if ( ! mkdir( $ran_booster_directory, 0700, true ) ) {
		throw new RuntimeException( 'The native lifecycle fixture directory is unsafe.' );
	}
	$ran_booster_policy  = $ran_booster_number % 4 >= 2 ? 'automatic' : 'manual';
	$ran_booster_items[] = array(
		'number'     => $ran_booster_number,
		'type'       => $type,
		'identifier' => $ran_booster_identifier,
		'directory'  => $ran_booster_directory,
		'policy'     => $ran_booster_policy,
	);
	update_option( 'ran_booster_c4_native_items', $ran_booster_items, false );
	$ran_booster_repository = 'ran-booster-c4/' . $ran_booster_root;
	$ran_booster_metadata   = 'plugin' === $type ? $ran_booster_root . '.php' : 'style.css';
	$ran_booster_contents   = 'plugin' === $type
		? "<?php\n/*\nPlugin Name: C4 $ran_booster_number\nVersion: 1.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$ran_booster_repository\n*/\n"
		: "/*\nTheme Name: C4 $ran_booster_number\nVersion: 1.0.0\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/$ran_booster_repository\n*/\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	file_put_contents( $ran_booster_directory . '/' . $ran_booster_metadata, $ran_booster_contents );
	if ( 'theme' === $type ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $ran_booster_directory . '/index.php', '<?php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $ran_booster_directory . '/functions.php', "<?php\nthrow new RuntimeException( 'Inactive C4 theme executed.' );\n" );
	}
	$ran_booster_archive = getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT' ) . '/' . $ran_booster_root . '.zip';
	$ran_booster_zip     = new ZipArchive();
	if ( ! is_string( $ran_booster_archive ) || true !== $ran_booster_zip->open( $ran_booster_archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { // @phpstan-ignore function.alreadyNarrowedType (Runtime acceptance proof retains the observed loaded value check rather than relying on analyzer assumptions.)
		throw new RuntimeException( 'The native lifecycle archive fixture is unavailable.' );
	}
	$ran_booster_updated_contents = str_replace( '1.0.0', '2.0.0', $ran_booster_contents );
	$ran_booster_zip->addFromString( $ran_booster_root . '/' . $ran_booster_metadata, $ran_booster_updated_contents );
	if ( 'theme' === $type ) {
		$ran_booster_zip->addFromString( $ran_booster_root . '/index.php', '<?php' );
		$ran_booster_zip->addFromString( $ran_booster_root . '/functions.php', "<?php\nthrow new RuntimeException( 'Inactive C4 theme executed.' );\n" );
	}
	$ran_booster_zip->close();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
	$ran_booster_configuration = json_encode(
		array(
			'channel'       => 'stable',
			'package_root'  => $ran_booster_root,
			'metadata_file' => $ran_booster_metadata,
		),
		JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
	);
	$ran_booster_inserted      = $wpdb->insert(
		$ran_booster_table,
		array(
			'package'                => $ran_booster_identifier,
			'repository'             => $ran_booster_repository,
			'branch'                 => 'main',
			'type'                   => 'plugin' === $type ? 1 : 2,
			'deployment_policy'      => $ran_booster_policy,
			'source'                 => 'release_asset',
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => (string) ( 940000 + $ran_booster_number ),
			'private'                => 0,
			'release_configuration'  => $ran_booster_configuration,
		)
	);
	if ( 1 !== $ran_booster_inserted ) {
		throw new RuntimeException( 'The native lifecycle fixture row was not persisted.' );
	}
	$ran_booster_items[ count( $ran_booster_items ) - 1 ] += array(
		'repository'      => $ran_booster_repository,
		'repository_id'   => (string) ( 940000 + $ran_booster_number ),
		'expected_digest' => hash( 'sha256', $ran_booster_updated_contents ),
	);
	update_option( 'ran_booster_c4_native_items', $ran_booster_items, false );
	$ran_booster_archives[ $ran_booster_repository ] = $ran_booster_archive;
}
update_option( 'ran_booster_c4_native_items', $ran_booster_items, false );
update_option( 'ran_booster_c4_native_archives', $ran_booster_archives, false );
WP_CLI::success( 'Seeded ' . $ran_booster_scale . ' installed native lifecycle targets.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

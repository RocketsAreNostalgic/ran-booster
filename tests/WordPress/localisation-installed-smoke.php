<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| '1' !== getenv( 'RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires an administrator WP-CLI request.' );
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$ran_booster_expected_root            = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
$ran_booster_expected_url             = getenv( 'RAN_BOOSTER_LOCALISATION_TEST_URL' );
$ran_booster_wordpress_root           = realpath( ABSPATH );
$ran_booster_content_root             = realpath( WP_CONTENT_DIR );
$ran_booster_plugin_root              = realpath( WP_PLUGIN_DIR );
$ran_booster_plugin_file              = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
$ran_booster_plugin_languages         = WP_PLUGIN_DIR . '/ran-booster/languages';
$ran_booster_disposable_mark          = ABSPATH . '.ran-booster-disposable-test-site';
$ran_booster_expected_php_translation = 'En attente';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact disposable marker content.
$ran_booster_marker_contents = file_get_contents( $ran_booster_disposable_mark );
if ( ! is_string( $ran_booster_expected_root ) || false === $ran_booster_wordpress_root || realpath( $ran_booster_expected_root ) !== $ran_booster_wordpress_root
	|| false === $ran_booster_content_root || $ran_booster_content_root !== $ran_booster_wordpress_root . '/wp-content'
	|| false === $ran_booster_plugin_root || $ran_booster_plugin_root !== $ran_booster_content_root . '/plugins'
	|| 'http://localhost' !== $ran_booster_expected_url || $ran_booster_expected_url !== get_option( 'siteurl' ) // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the expected global before WordPress option filters can mutate it.
	|| 'fr_FR' !== get_option( 'WPLANG', '' ) || 'fr_FR' !== determine_locale()
	|| is_link( $ran_booster_disposable_mark ) || ! is_file( $ran_booster_disposable_mark )
	|| "RAN Booster disposable test site\n" !== $ran_booster_marker_contents
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( $ran_booster_plugin_file )
	|| is_link( $ran_booster_plugin_languages ) || ! is_file( $ran_booster_plugin_languages . '/ran-booster.pot' )
	|| ! is_plugin_active( plugin_basename( $ran_booster_plugin_file ) ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires the exact disposable installed archive.' );
}
foreach (
	array(
		'RAN_BOOSTER_PROVIDER_API_VERSION'          => 14,
		'RAN_BOOSTER_ADDON_API_VERSION'             => 17,
		'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 3,
		'RAN_BOOSTER_PORTABILITY_API_VERSION'       => 3,
	) as $ran_booster_constant => $ran_booster_expected_version
) {
	if ( ! defined( $ran_booster_constant ) || constant( $ran_booster_constant ) !== $ran_booster_expected_version ) {
		throw new RuntimeException( 'The installed localisation smoke found an unexpected public API version.' );
	}
}

$ran_booster_php_translation = __( 'Queued', 'ran-booster' );
if ( ! is_textdomain_loaded( 'ran-booster' ) || $ran_booster_expected_php_translation !== $ran_booster_php_translation ) {
	throw new RuntimeException( 'The installed plugin init path did not load the expected PHP translation.' );
}

$_GET = array( 'page' => 'ran-booster-plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route fixture.
set_current_screen( 'ran-booster_page_ran-booster-plugins' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
do_action( 'admin_enqueue_scripts', 'ran-booster_page_ran-booster-plugins' );
$_GET = array( // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route fixture.
	'page' => 'ran-booster',
	'tab'  => 'portability',
);
set_current_screen( 'toplevel_page_ran-booster' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
do_action( 'admin_enqueue_scripts', 'toplevel_page_ran-booster' );

$ran_booster_scripts = wp_scripts();
foreach (
	array(
		'ran-booster-enhanced-mutations' => array( 'ran-booster-enhanced-mutations.js', 'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.' ),
		'ran-booster-js'                 => array( 'ran-booster.js', '%d dépôt affiché' ),
		'ran-booster-packages'           => array( 'ran-booster-packages.js', 'Réinstallation annulée.' ),
		'ran-booster-portability'        => array( 'ran-booster-portability.js', 'Examen du plan…' ),
		'ran-booster-release-management' => array( 'ran-booster-release-management.js', 'Installer %s maintenant' ),
		'ran-booster-repository-picker'  => array( 'ran-booster-repository-picker.js', 'Fermer le sélecteur de dépôt' ),
		'ran-booster-secure-inputs'      => array( 'ran-booster-secure-inputs.js', 'Ajouter %s' ),
	) as $ran_booster_handle => [ $ran_booster_source_file, $ran_booster_expected_translation ]
) {
	$ran_booster_registered      = $ran_booster_scripts->registered[ $ran_booster_handle ] ?? null;
	$ran_booster_expected_source = plugins_url( 'assets/' . $ran_booster_source_file, $ran_booster_plugin_file );
	$ran_booster_translations    = $ran_booster_scripts->print_translations( $ran_booster_handle, false );

	if ( ! $ran_booster_registered instanceof _WP_Dependency
		|| $ran_booster_expected_source !== $ran_booster_registered->src
		|| ! in_array( 'wp-i18n', $ran_booster_registered->deps, true )
		|| 'ran-booster' !== $ran_booster_registered->textdomain
		|| $ran_booster_plugin_languages !== $ran_booster_registered->translations_path
		|| ! is_string( $ran_booster_translations )
		|| ! str_contains( $ran_booster_translations, wp_json_encode( $ran_booster_expected_translation ) ) ) {
		throw new RuntimeException( sprintf( 'The %s script does not expose its expected installed Jed translation API.', esc_html( $ran_booster_handle ) ) );
	}
}

WP_CLI::success( 'Installed French PHP and all seven Jed translations passed.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| '1' !== getenv( 'RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires an administrator WP-CLI request.' );
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$expected_root            = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
$expected_url             = getenv( 'RAN_BOOSTER_LOCALISATION_TEST_URL' );
$wordpress_root           = realpath( ABSPATH );
$content_root             = realpath( WP_CONTENT_DIR );
$plugin_root              = realpath( WP_PLUGIN_DIR );
$plugin_file              = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
$plugin_languages         = WP_PLUGIN_DIR . '/ran-booster/languages';
$disposable_mark          = ABSPATH . '.ran-booster-disposable-test-site';
$expected_php_translation = 'En attente';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact disposable marker content.
$marker_contents = file_get_contents( $disposable_mark );
if ( ! is_string( $expected_root ) || false === $wordpress_root || realpath( $expected_root ) !== $wordpress_root
	|| false === $content_root || $content_root !== $wordpress_root . '/wp-content'
	|| false === $plugin_root || $plugin_root !== $content_root . '/plugins'
	|| 'http://localhost' !== $expected_url || $expected_url !== get_option( 'siteurl' ) // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the expected global before WordPress option filters can mutate it.
	|| 'fr_FR' !== get_option( 'WPLANG', '' ) || 'fr_FR' !== determine_locale()
	|| is_link( $disposable_mark ) || ! is_file( $disposable_mark )
	|| "RAN Booster disposable test site\n" !== $marker_contents
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( $plugin_file )
	|| is_link( $plugin_languages ) || ! is_file( $plugin_languages . '/ran-booster.pot' )
	|| ! is_plugin_active( plugin_basename( $plugin_file ) ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires the exact disposable installed archive.' );
}
foreach (
	array(
		'RAN_BOOSTER_PROVIDER_API_VERSION'          => 14,
		'RAN_BOOSTER_ADDON_API_VERSION'             => 17,
		'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 3,
		'RAN_BOOSTER_PORTABILITY_API_VERSION'       => 3,
	) as $constant => $expected_version
) {
	if ( ! defined( $constant ) || constant( $constant ) !== $expected_version ) {
		throw new RuntimeException( 'The installed localisation smoke found an unexpected public API version.' );
	}
}

$php_translation = __( 'Queued', 'ran-booster' );
if ( ! is_textdomain_loaded( 'ran-booster' ) || $expected_php_translation !== $php_translation ) {
	throw new RuntimeException( 'The installed plugin init path did not load the expected PHP translation.' );
}

$_GET = array( 'page' => 'ran-booster-plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route fixture.
set_current_screen( 'ran-booster_page_ran-booster-plugins' );
do_action( 'admin_enqueue_scripts', 'ran-booster_page_ran-booster-plugins' );
$_GET = array( // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route fixture.
	'page' => 'ran-booster',
	'tab'  => 'portability',
);
set_current_screen( 'toplevel_page_ran-booster' );
do_action( 'admin_enqueue_scripts', 'toplevel_page_ran-booster' );

$scripts = wp_scripts();
foreach (
	array(
		'ran-booster-enhanced-mutations' => array( 'ran-booster-enhanced-mutations.js', 'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.' ),
		'ran-booster-js'                 => array( 'ran-booster.js', '%d dépôt affiché' ),
		'ran-booster-packages'           => array( 'ran-booster-packages.js', 'Réinstallation annulée.' ),
		'ran-booster-portability'        => array( 'ran-booster-portability.js', 'Examen du plan…' ),
		'ran-booster-release-management' => array( 'ran-booster-release-management.js', 'Installer %s maintenant' ),
		'ran-booster-repository-picker'  => array( 'ran-booster-repository-picker.js', 'Fermer le sélecteur de dépôt' ),
		'ran-booster-secure-inputs'      => array( 'ran-booster-secure-inputs.js', 'Ajouter %s' ),
	) as $handle => [ $source_file, $expected_translation ]
) {
	$registered      = $scripts->registered[ $handle ] ?? null;
	$expected_source = plugins_url( 'assets/' . $source_file, $plugin_file );
	$translations    = $scripts->print_translations( $handle, false );

	if ( ! $registered instanceof _WP_Dependency
		|| $expected_source !== $registered->src
		|| ! in_array( 'wp-i18n', $registered->deps, true )
		|| 'ran-booster' !== $registered->textdomain
		|| $plugin_languages !== $registered->translations_path
		|| ! is_string( $translations )
		|| ! str_contains( $translations, wp_json_encode( $expected_translation ) ) ) {
		throw new RuntimeException( sprintf( 'The %s script does not expose its expected installed Jed translation API.', esc_html( $handle ) ) );
	}
}

WP_CLI::success( 'Installed French PHP and all seven Jed translations passed.' );

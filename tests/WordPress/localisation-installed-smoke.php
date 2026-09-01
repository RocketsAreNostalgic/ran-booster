<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| '1' !== getenv( 'RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires an administrator WP-CLI request.' );
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$expectedRoot           = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
$expectedUrl            = getenv( 'RAN_BOOSTER_LOCALISATION_TEST_URL' );
$wordpressRoot          = realpath( ABSPATH );
$contentRoot            = realpath( WP_CONTENT_DIR );
$pluginRoot             = realpath( WP_PLUGIN_DIR );
$pluginFile             = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
$pluginLanguages        = WP_PLUGIN_DIR . '/ran-booster/languages';
$disposableMark         = ABSPATH . '.ran-booster-disposable-test-site';
$expectedPhpTranslation = 'En attente';
$expectedJsSource       = 'We could not complete that request. Please try again.';
$expectedJsTranslation  = 'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact disposable marker content.
$markerContents = file_get_contents( $disposableMark );
if ( ! is_string( $expectedRoot ) || false === $wordpressRoot || $wordpressRoot !== realpath( $expectedRoot )
	|| false === $contentRoot || $contentRoot !== $wordpressRoot . '/wp-content'
	|| false === $pluginRoot || $pluginRoot !== $contentRoot . '/plugins'
	|| 'http://localhost' !== $expectedUrl || $expectedUrl !== get_option( 'siteurl' )
	|| 'fr_FR' !== get_option( 'WPLANG', '' ) || 'fr_FR' !== determine_locale()
	|| is_link( $disposableMark ) || ! is_file( $disposableMark )
	|| "RAN Booster disposable test site\n" !== $markerContents
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_file( $pluginFile )
	|| is_link( $pluginLanguages ) || ! is_file( $pluginLanguages . '/ran-booster.pot' )
	|| ! is_plugin_active( plugin_basename( $pluginFile ) ) ) {
	throw new RuntimeException( 'The installed localisation smoke requires the exact disposable installed archive.' );
}
foreach (
	array(
		'RAN_BOOSTER_PROVIDER_API_VERSION'          => 10,
		'RAN_BOOSTER_ADDON_API_VERSION'             => 16,
		'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 2,
		'RAN_BOOSTER_PORTABILITY_API_VERSION'       => 2,
	) as $constant => $expectedVersion
) {
	if ( ! defined( $constant ) || $expectedVersion !== constant( $constant ) ) {
		throw new RuntimeException( 'The installed localisation smoke found an unexpected public API version.' );
	}
}

$phpTranslation = __( 'Queued', 'ran-booster' );
if ( ! is_textdomain_loaded( 'ran-booster' ) || $expectedPhpTranslation !== $phpTranslation ) {
	throw new RuntimeException( 'The installed plugin init path did not load the expected PHP translation.' );
}

$booster              = new RAN\Booster();
$booster->boosterPath = plugin_dir_path( $pluginFile );
$booster->boosterUrl  = plugin_dir_url( $pluginFile );
$booster->loadScripts( 'toplevel_page_ran-booster' );
$scripts        = wp_scripts();
$handle         = 'ran-booster-enhanced-mutations';
$registered     = $scripts->registered[ $handle ] ?? null;
$expectedSource = plugins_url( 'assets/ran-booster-enhanced-mutations.js', $pluginFile );
$translations   = $scripts->print_translations( $handle, false );

if ( ! $registered instanceof _WP_Dependency
	|| $expectedSource !== $registered->src
	|| ! in_array( 'wp-i18n', $registered->deps, true )
	|| 'ran-booster' !== $registered->textdomain
	|| $pluginLanguages !== $registered->translations_path
	|| ! is_string( $translations )
	|| ! str_contains( $translations, wp_json_encode( $expectedJsTranslation ) ) ) {
	throw new RuntimeException( 'The enhanced-mutations script does not expose the expected installed Jed translation API.' );
}

WP_CLI::success( 'Installed French PHP and enhanced-mutations Jed translations passed.' );

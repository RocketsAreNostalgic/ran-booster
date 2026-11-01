<?php

// Executed by WP-CLI inside the exact disposable fixture after a pre-bootstrap
// invalid archive-limit constant forces Core target registration to fail.

$ran_booster_core_admission_marker = ABSPATH . '.ran-booster-disposable-test-site';
$ran_booster_core_admission_plugin = 'ran-booster/ran-booster.php';

if ( ! defined( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' )
	|| 0 !== constant( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' )
	|| is_multisite()
	|| ! is_file( $ran_booster_core_admission_marker )
	|| is_link( $ran_booster_core_admission_marker )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify only the exact disposable-site marker before invoking filters.
	|| "RAN Booster disposable test site\n" !== file_get_contents( $ran_booster_core_admission_marker )
	|| ! is_plugin_active( $ran_booster_core_admission_plugin ) ) {
	throw new RuntimeException( 'The Core registration-failure admission smoke requires the marked active disposable Core fixture.' );
}

if ( false !== has_filter( 'update_plugins_github.com' ) ) {
	throw new RuntimeException( 'The failed Core target must not retain its native GitHub discovery callback.' );
}

$ran_booster_core_admission_offer = (object) array(
	'response' => array(
		$ran_booster_core_admission_plugin => (object) array(
			'plugin'  => $ran_booster_core_admission_plugin,
			'package' => 'ran-wp-release-updater:v1:stale',
		),
		'unrelated/unrelated.php'          => (object) array(
			'plugin'  => 'unrelated/unrelated.php',
			'package' => 'https://example.test/unrelated.zip',
		),
	),
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the exact WordPress cache-read hook under test.
$ran_booster_core_admission_filtered = apply_filters( 'site_transient_update_plugins', $ran_booster_core_admission_offer );
if ( ! is_object( $ran_booster_core_admission_filtered )
	|| isset( $ran_booster_core_admission_filtered->response[ $ran_booster_core_admission_plugin ] )
	|| ! isset( $ran_booster_core_admission_filtered->response['unrelated/unrelated.php'] ) ) {
	throw new RuntimeException( 'The Core registration-failure fallback must remove only the cached Core offer.' );
}

$ran_booster_core_admission_sentinel      = (object) array( 'unchanged' => true );
$ran_booster_core_admission_direct        = array(
	'action' => 'update',
	'type'   => 'plugin',
	'plugin' => $ran_booster_core_admission_plugin,
);
$ran_booster_core_admission_bulk          = array(
	'action'  => 'update',
	'type'    => 'plugin',
	'plugins' => array( 'unrelated/unrelated.php', $ran_booster_core_admission_plugin ),
);
$ran_booster_core_admission_unrelated     = array(
	'action' => 'update',
	'type'   => 'plugin',
	'plugin' => 'unrelated/unrelated.php',
);
$ran_booster_core_admission_theme         = array(
	'action' => 'update',
	'type'   => 'theme',
	'theme'  => 'unrelated-theme',
);
$ran_booster_core_admission_install_extra = array(
	'action' => 'install-plugin',
	'type'   => 'plugin',
	'plugin' => $ran_booster_core_admission_plugin,
);

foreach ( array( $ran_booster_core_admission_direct, $ran_booster_core_admission_bulk ) as $ran_booster_core_admission_extra ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the exact WordPress updater hook under test.
	$ran_booster_core_admission_download = apply_filters( 'upgrader_pre_download', $ran_booster_core_admission_sentinel, 'ran-wp-release-updater:v1:stale', new stdClass(), $ran_booster_core_admission_extra );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the exact WordPress updater hook under test.
	$ran_booster_core_admission_install_result = apply_filters( 'upgrader_pre_install', $ran_booster_core_admission_sentinel, $ran_booster_core_admission_extra );
	if ( ! $ran_booster_core_admission_download instanceof WP_Error
		|| ! $ran_booster_core_admission_install_result instanceof WP_Error
		|| 'ran_booster_native_update_core_registration_unavailable' !== $ran_booster_core_admission_download->get_error_code()
		|| 'ran_booster_native_update_core_registration_unavailable' !== $ran_booster_core_admission_install_result->get_error_code() ) {
		throw new RuntimeException( 'The failed Core target must deny exact download and install attempts.' );
	}
}

foreach ( array( $ran_booster_core_admission_unrelated, $ran_booster_core_admission_theme, $ran_booster_core_admission_install_extra ) as $ran_booster_core_admission_extra ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the exact WordPress updater hook under test.
	$ran_booster_core_admission_download = apply_filters( 'upgrader_pre_download', $ran_booster_core_admission_sentinel, 'https://example.test/unrelated.zip', new stdClass(), $ran_booster_core_admission_extra );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the exact WordPress updater hook under test.
	$ran_booster_core_admission_install_result = apply_filters( 'upgrader_pre_install', $ran_booster_core_admission_sentinel, $ran_booster_core_admission_extra );
	if ( $ran_booster_core_admission_download !== $ran_booster_core_admission_sentinel
		|| $ran_booster_core_admission_install_result !== $ran_booster_core_admission_sentinel ) {
		throw new RuntimeException( 'The Core registration-failure fallback must not affect unrelated or install operations.' );
	}
}

echo "Core registration-failure admission smoke passed\n";

<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

try {
	require_once __DIR__ . '/autoload.php';
	$ran_booster_secrets = new \RAN\Secrets\SecretsFile(
		availability: \RAN\Secrets\SecretsRuntimeAvailability::forConfirmedUninstall( __DIR__ . '/ran-booster.php' )
	);
	( new \RAN\Uninstall\LocalDataRemover(
		$ran_booster_secrets,
		new \RAN\Logging\TemporaryDebugCapture( $ran_booster_secrets->path() ),
		new \RAN\Secrets\WpConfigSecretsPathWriter()
	) )->remove();
} catch ( \Throwable ) {
	wp_die(
		esc_html__(
			'RAN Booster could not safely remove all of its local data. WordPress kept the plugin files. Correct the local storage or database problem, then try Delete again.',
			'ran-booster'
		),
		esc_html__( 'RAN Booster uninstall stopped', 'ran-booster' ),
		array( 'response' => 500 )
	);
}

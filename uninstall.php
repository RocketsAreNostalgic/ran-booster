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
} catch ( \Throwable $failure ) {
	$ran_booster_diagnostic = $failure instanceof \RAN\Secrets\SecretsStorageUnavailable
		? $failure->reason()
		: 'uninstall_failed';
	\RAN\Logging\BoosterLogger::logException(
		'secrets or local data cleanup blocked uninstall',
		$failure,
		array(
			'diagnostic_id' => $ran_booster_diagnostic,
			'event'         => 'uninstall_failed',
			'operation'     => 'uninstall',
			'outcome_code'  => $ran_booster_diagnostic,
			'source'        => 'wordpress',
			'state'         => 'blocked',
			'step'          => 'cleanup',
		)
	);
	wp_die(
		esc_html(
			sprintf(
				/* translators: %s: stable uninstall diagnostic code. */
				__( 'RAN Booster could not safely remove all of its local data. WordPress kept the plugin files. Diagnostic code: %s. Reactivate Booster, review Secure credential storage on the Overview, correct the reported problem, then try Delete again.', 'ran-booster' ),
				$ran_booster_diagnostic
			)
		),
		esc_html__( 'RAN Booster uninstall stopped', 'ran-booster' ),
		array( 'response' => 500 )
	);
}

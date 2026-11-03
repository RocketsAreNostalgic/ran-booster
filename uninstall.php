<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

try {
	require_once __DIR__ . '/autoload.php';
	$ran_booster_secrets = new \RAN\Secrets\SecretsFile(
		availability: \RAN\Secrets\SecretsRuntimeAvailability::for_confirmed_uninstall( __DIR__ . '/ran-booster.php' )
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
	$ran_booster_guidance   = '';
	if ( get_class( $failure ) === \RuntimeException::class ) {
		switch ( $failure->getMessage() ) {
			case 'Booster has an unfinished native mutation that must be resolved before cleanup.':
				$ran_booster_diagnostic = 'uninstall_native_unfinished';
				$ran_booster_guidance   = __( 'Reactivate Booster if needed, open the Booster GitHub page, and review An installation needs recovery before retrying Delete.', 'ran-booster' );
				break;
			case 'Booster has unfinished work that must be resolved before cleanup.':
				$ran_booster_diagnostic = 'uninstall_deployments_unfinished';
				$ran_booster_guidance   = __( 'Reactivate Booster if needed and review unfinished deployments before retrying Delete.', 'ran-booster' );
				break;
			case 'Booster could not verify recorded work before cleanup.':
				$ran_booster_diagnostic = 'uninstall_custody_unverified';
				$ran_booster_guidance   = __( 'Review Troubleshooting or contact support before retrying Delete.', 'ran-booster' );
				break;
		}
	}
	\RAN\Logging\BoosterLogger::log_exception(
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
				/* translators: 1: stable uninstall diagnostic code, 2: recovery guidance. */
				__( 'RAN Booster could not safely remove all of its local data. WordPress kept the plugin files. Diagnostic code: %1$s. Some local data may already have been removed. Resolve the reported problem before retrying Delete. Reactivating Booster does not restore removed data. %2$s', 'ran-booster' ),
				$ran_booster_diagnostic,
				$ran_booster_guidance
			)
		),
		esc_html__( 'RAN Booster uninstall stopped', 'ran-booster' ),
		array( 'response' => 500 )
	);
}

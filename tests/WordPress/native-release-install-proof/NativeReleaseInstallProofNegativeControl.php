<?php

// Refs #969. Deliberately contradict observations; the real proof must exit nonzero.

$ran_booster_native_proof_control = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_NEGATIVE_CONTROL' );
$ran_booster_native_proof_phase   = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_PHASE' );
$ran_booster_native_proof_case    = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_CASE' );
$ran_booster_native_proof_kind    = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPE' );

if ( 'refusal' === $ran_booster_native_proof_control && 'run' === $ran_booster_native_proof_phase && 'equal' === $ran_booster_native_proof_case ) {
	add_filter( 'upgrader_pre_download', static fn () => new WP_Error( 'native_proof_wrong_refusal' ), PHP_INT_MAX );
} elseif ( 'activation' === $ran_booster_native_proof_control && 'run' === $ran_booster_native_proof_phase && 'active' === $ran_booster_native_proof_case ) {
	add_action(
		'upgrader_process_complete',
		static function () use ( $ran_booster_native_proof_kind ): void {
			// Change only the read result after installation, never stored activation state.
			add_filter( 'pre_option_' . ( 'plugin' === $ran_booster_native_proof_kind ? 'active_plugins' : 'stylesheet' ), static fn () => 'plugin' === $ran_booster_native_proof_kind ? array() : 'native-proof-inactive', PHP_INT_MAX );
		},
		PHP_INT_MAX
	);
} elseif ( 'lifetime' === $ran_booster_native_proof_control && 'readback' === $ran_booster_native_proof_phase && 'current' === $ran_booster_native_proof_case ) {
	$ran_booster_native_proof_slug     = 'native-proof-' . $ran_booster_native_proof_kind . '-current-' . getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN' );
	$ran_booster_native_proof_identity = 'plugin' === $ran_booster_native_proof_kind ? $ran_booster_native_proof_slug . '/' . $ran_booster_native_proof_slug . '.php' : $ran_booster_native_proof_slug;
	add_filter(
		'pre_site_transient_update_' . ( 'plugin' === $ran_booster_native_proof_kind ? 'plugins' : 'themes' ),
		static fn () => (object) array( 'response' => array( $ran_booster_native_proof_identity => array( 'native_release_install_proof' => $ran_booster_native_proof_slug ) ) ),
		PHP_INT_MAX
	);
} else {
	throw new RuntimeException( 'Unknown negative control or incorrect scenario phase.' );
}

// All fixture identity and target checks run before the proof's mutations.
require __DIR__ . '/NativeReleaseInstallProof.php';

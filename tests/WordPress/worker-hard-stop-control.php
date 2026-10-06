<?php

// Seed, inspect, reconcile and clean the compact hard-stop proof.

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$mode               = $args[0] ?? '';
$ran_booster_run_id = $args[1] ?? '';
$ran_booster_phase  = $args[2] ?? '';
if ( ! is_string( $mode ) || preg_match( '/^[a-f0-9]{24}$/D', (string) $ran_booster_run_id ) !== 1 || ! in_array( $ran_booster_phase, array( 'pre', 'post', 'foreign' ), true ) ) {
	throw new RuntimeException( 'The hard-stop control arguments are invalid.' );
}
global $wpdb;
$ran_booster_booster      = require __DIR__ . '/core-container-fixture.php';
$ran_booster_attempts     = $ran_booster_booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$ran_booster_table        = RAN\Storage\Database::attempt_table_name();
$ran_booster_manual_slug  = 'hard-stop-' . $ran_booster_phase . '-' . $ran_booster_run_id;
$ran_booster_webhook_slug = 'hard-stop-webhook-' . $ran_booster_phase . '-' . $ran_booster_run_id;

if ( 'cleanup' === $mode ) {
	$ran_booster_ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE package_slug IN (%s, %s)', $ran_booster_table, $ran_booster_manual_slug, $ran_booster_webhook_slug ) );
	$ran_booster_core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( 'pre' !== $ran_booster_phase && is_array( $ran_booster_ids ) && array() !== $ran_booster_ids && is_string( $ran_booster_core ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'auto_updater.lock', $ran_booster_core ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE package_slug IN (%s, %s)', $ran_booster_table, $ran_booster_manual_slug, $ran_booster_webhook_slug ) );
	return;
}

if ( 'seed' === $mode ) {
	$ran_booster_core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( null !== $ran_booster_core ) {
		throw new RuntimeException( 'The hard-stop proof requires the WordPress updater lock to be idle.' );
	}
	$ran_booster_request         = new DeploymentRequest( 'org/' . $ran_booster_manual_slug, null, false, 'main', $ran_booster_manual_slug, null, DeploymentPolicy::AUTOMATIC, null );
	$ran_booster_webhook_request = new DeploymentRequest( 'org/' . $ran_booster_webhook_slug, null, false, 'main', $ran_booster_webhook_slug, null, DeploymentPolicy::AUTOMATIC, null );
	$ran_booster_attempts->admit_webhook_batch(
		'gh',
		'hard-stop-delivery-' . $ran_booster_phase . '-' . $ran_booster_run_id,
		hash( 'sha256', 'hard-stop-' . $ran_booster_phase . '-' . $ran_booster_run_id ),
		array(
			array(
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'provider_repository_id'  => 'first-' . $ran_booster_run_id,
				'requested_ref'           => str_repeat( 'a', 40 ),
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'request'                 => $ran_booster_request,
			),
			array(
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'provider_repository_id'  => 'webhook-' . $ran_booster_run_id,
				'requested_ref'           => str_repeat( 'a', 40 ),
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'request'                 => $ran_booster_webhook_request,
			),
		)
	);
	return;
}

$ran_booster_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_slug = %s LIMIT 1', $ran_booster_table, $ran_booster_manual_slug ), ARRAY_A );
if ( ! is_array( $ran_booster_row ) ) {
	throw new RuntimeException( 'The hard-stop manual attempt is missing.' );
}

if ( 'assert-retained' === $mode ) {
	$ran_booster_expected_fence = 'pre' !== $ran_booster_phase;
	if ( 'running' !== $ran_booster_row['state'] || ( null !== $ran_booster_row['mutation_started_at'] ) !== $ran_booster_expected_fence ) {
		throw new RuntimeException(
			'The killed worker did not retain the expected state and fence: '
			. wp_json_encode(
				array(
					'state'           => $ran_booster_row['state'],
					'id'              => (string) $ran_booster_row['id'],
					'fenced'          => null !== $ran_booster_row['mutation_started_at'],
					'expected_fenced' => $ran_booster_expected_fence,
				)
			)
		);
	}
	$ran_booster_core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( 'pre' === $ran_booster_phase ) {
		if ( null !== $ran_booster_core ) {
			throw new RuntimeException( 'The pre-acquisition kill unexpectedly owns the core lock.' );
		}
	} elseif ( ! is_string( $ran_booster_core ) || preg_match( '/^\d+$/D', $ran_booster_core ) !== 1 ) {
		throw new RuntimeException( 'The post-acquisition kill did not retain the native core-lock token.' );
	}
	return;
}

if ( 'replace-core-lock' === $mode ) {
	if ( 'foreign' !== $ran_booster_phase ) {
		throw new RuntimeException( 'Only the foreign-lock proof may replace the core token.' );
	}
	$ran_booster_core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( ! is_string( $ran_booster_core ) || preg_match( '/^\d+$/D', $ran_booster_core ) !== 1 ) {
		throw new RuntimeException( 'The retained core lock is unavailable for replacement.' );
	}
	$ran_booster_foreign = (string) max( time(), (int) $ran_booster_core + 1 );
	$ran_booster_updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $ran_booster_foreign, 'auto_updater.lock' ) );
	if ( 1 !== $ran_booster_updated ) {
		throw new RuntimeException( 'The foreign core lock could not be installed.' );
	}
	wp_cache_delete( 'auto_updater.lock', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return;
}

if ( 'reconcile' === $mode ) {
	$ran_booster_core_before    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	$ran_booster_result         = $ran_booster_booster->make( RAN\Deployment\DeploymentCoordinator::class )->reconcile_confirmed_stopped( (int) $ran_booster_row['id'], (string) $ran_booster_row['correlation_id'] );
	$ran_booster_expected_state = 'pre' === $ran_booster_phase ? 'failed' : 'needs_attention';
	$ran_booster_expected_code  = 'pre' === $ran_booster_phase ? 'worker_stopped' : 'interrupted';
	if ( $ran_booster_expected_state !== $ran_booster_result->get_state()->value || $ran_booster_expected_code !== $ran_booster_result->get_outcome()?->get_code() ) {
		throw new RuntimeException( 'Protected reconciliation produced the wrong hard-stop outcome.' );
	}
	$ran_booster_webhook                  = $wpdb->get_row( $wpdb->prepare( 'SELECT state, outcome_code FROM %i WHERE package_slug = %s', $ran_booster_table, $ran_booster_webhook_slug ), ARRAY_A );
	$ran_booster_expected_contender_state = 'pre' === $ran_booster_phase ? 'succeeded' : 'failed';
	$ran_booster_expected_contender_code  = 'pre' === $ran_booster_phase ? 'no_change' : 'lock_unavailable';
	if ( ! is_array( $ran_booster_webhook ) || $ran_booster_expected_contender_state !== $ran_booster_webhook['state'] || $ran_booster_expected_contender_code !== $ran_booster_webhook['outcome_code'] ) {
		throw new RuntimeException( 'The independent contender did not retain its explicit native-lock outcome.' );
	}
	$ran_booster_core_after = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( $ran_booster_core_before !== $ran_booster_core_after ) {
		throw new RuntimeException( 'Protected reconciliation changed the native WordPress updater lock.' );
	}
	WP_CLI::success( 'The ' . $ran_booster_phase . '-fence hard stop reconciled truthfully without changing the native updater lock.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	return;
}

throw new RuntimeException( 'The hard-stop control mode is invalid.' );

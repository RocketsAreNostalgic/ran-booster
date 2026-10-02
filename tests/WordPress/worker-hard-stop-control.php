<?php

// Seed, inspect, reconcile and clean the compact hard-stop proof.

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$mode   = $args[0] ?? '';
$run_id = $args[1] ?? '';
$phase  = $args[2] ?? '';
if ( ! is_string( $mode ) || preg_match( '/^[a-f0-9]{24}$/D', (string) $run_id ) !== 1 || ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true ) ) {
	throw new RuntimeException( 'The hard-stop control arguments are invalid.' );
}
global $wpdb;
$booster      = require __DIR__ . '/core-container-fixture.php';
$attempts     = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$table        = RAN\Storage\Database::attempt_table_name();
$manual_slug  = 'hard-stop-' . $phase . '-' . $run_id;
$webhook_slug = 'hard-stop-webhook-' . $phase . '-' . $run_id;

if ( 'cleanup' === $mode ) {
	$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE package_slug IN (%s, %s)', $table, $manual_slug, $webhook_slug ) );
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( 'pre' !== $phase && is_array( $ids ) && array() !== $ids && is_string( $core ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'auto_updater.lock', $core ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE package_slug IN (%s, %s)', $table, $manual_slug, $webhook_slug ) );
	return;
}

if ( 'seed' === $mode ) {
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( null !== $core ) {
		throw new RuntimeException( 'The hard-stop proof requires the WordPress updater lock to be idle.' );
	}
	$request         = new DeploymentRequest( 'org/' . $manual_slug, null, false, 'main', $manual_slug, null, DeploymentPolicy::AUTOMATIC, null );
	$webhook_request = new DeploymentRequest( 'org/' . $webhook_slug, null, false, 'main', $webhook_slug, null, DeploymentPolicy::AUTOMATIC, null );
	$attempts->admit_webhook_batch(
		'gh',
		'hard-stop-delivery-' . $phase . '-' . $run_id,
		hash( 'sha256', 'hard-stop-' . $phase . '-' . $run_id ),
		array(
			array(
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'provider_repository_id'  => 'first-' . $run_id,
				'requested_ref'           => str_repeat( 'a', 40 ),
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'request'                 => $request,
			),
			array(
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'provider_repository_id'  => 'webhook-' . $run_id,
				'requested_ref'           => str_repeat( 'a', 40 ),
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'request'                 => $webhook_request,
			),
		)
	);
	return;
}

$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_slug = %s LIMIT 1', $table, $manual_slug ), ARRAY_A );
if ( ! is_array( $row ) ) {
	throw new RuntimeException( 'The hard-stop manual attempt is missing.' );
}

if ( 'assert-retained' === $mode ) {
	$expected_fence = 'pre' !== $phase;
	if ( 'running' !== $row['state'] || ( null !== $row['mutation_started_at'] ) !== $expected_fence ) {
		throw new RuntimeException(
			'The killed worker did not retain the expected state and fence: '
			. wp_json_encode(
				array(
					'state'           => $row['state'],
					'id'              => (string) $row['id'],
					'fenced'          => null !== $row['mutation_started_at'],
					'expected_fenced' => $expected_fence,
				)
			)
		);
	}
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( 'pre' === $phase ) {
		if ( null !== $core ) {
			throw new RuntimeException( 'The pre-acquisition kill unexpectedly owns the core lock.' );
		}
	} elseif ( ! is_string( $core ) || preg_match( '/^\d+$/D', $core ) !== 1 ) {
		throw new RuntimeException( 'The post-acquisition kill did not retain the native core-lock token.' );
	}
	return;
}

if ( 'replace-core-lock' === $mode ) {
	if ( 'foreign' !== $phase ) {
		throw new RuntimeException( 'Only the foreign-lock proof may replace the core token.' );
	}
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( ! is_string( $core ) || preg_match( '/^\d+$/D', $core ) !== 1 ) {
		throw new RuntimeException( 'The retained core lock is unavailable for replacement.' );
	}
	$foreign = (string) max( time(), (int) $core + 1 );
	$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $foreign, 'auto_updater.lock' ) );
	if ( 1 !== $updated ) {
		throw new RuntimeException( 'The foreign core lock could not be installed.' );
	}
	wp_cache_delete( 'auto_updater.lock', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return;
}

if ( 'reconcile' === $mode ) {
	$core_before    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	$result         = $booster->make( RAN\Deployment\DeploymentCoordinator::class )->reconcile_confirmed_stopped( (int) $row['id'], (string) $row['correlation_id'] );
	$expected_state = 'pre' === $phase ? 'failed' : 'needs_attention';
	$expected_code  = 'pre' === $phase ? 'worker_stopped' : 'interrupted';
	if ( $expected_state !== $result->get_state()->value || $expected_code !== $result->get_outcome()?->get_code() ) {
		throw new RuntimeException( 'Protected reconciliation produced the wrong hard-stop outcome.' );
	}
	$webhook                  = $wpdb->get_row( $wpdb->prepare( 'SELECT state, outcome_code FROM %i WHERE package_slug = %s', $table, $webhook_slug ), ARRAY_A );
	$expected_contender_state = 'pre' === $phase ? 'succeeded' : 'failed';
	$expected_contender_code  = 'pre' === $phase ? 'no_change' : 'lock_unavailable';
	if ( ! is_array( $webhook ) || $expected_contender_state !== $webhook['state'] || $expected_contender_code !== $webhook['outcome_code'] ) {
		throw new RuntimeException( 'The independent contender did not retain its explicit native-lock outcome.' );
	}
	$core_after = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( $core_before !== $core_after ) {
		throw new RuntimeException( 'Protected reconciliation changed the native WordPress updater lock.' );
	}
	WP_CLI::success( 'The ' . $phase . '-fence hard stop reconciled truthfully without changing the native updater lock.' );
	return;
}

throw new RuntimeException( 'The hard-stop control mode is invalid.' );

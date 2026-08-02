<?php

// Seed, inspect, reconcile and clean the compact hard-stop proof.
// phpcs:disable

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

$mode = $args[0] ?? '';
$run_id = $args[1] ?? '';
$phase = $args[2] ?? '';
if ( ! is_string( $mode ) || preg_match( '/^[a-f0-9]{24}$/D', (string) $run_id ) !== 1 || ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true ) ) {
	throw new RuntimeException( 'The hard-stop control arguments are invalid.' );
}
global $wpdb;
$booster    = require __DIR__ . '/core-container-fixture.php';
$attempts   = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$table      = RAN\Storage\Database::attemptTableName();
$manualSlug = 'hard-stop-' . $phase . '-' . $run_id;
$webhookSlug = 'hard-stop-webhook-' . $phase . '-' . $run_id;

if ( 'cleanup' === $mode ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE package_slug IN (%s, %s)', $table, $manualSlug, $webhookSlug ) );
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( 'pre' !== $phase && is_array( $ids ) && array() !== $ids && is_string( $core ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'auto_updater.lock', $core ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE package_slug IN (%s, %s)', $table, $manualSlug, $webhookSlug ) );
	return;
}

if ( 'seed' === $mode ) {
	$core = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( null !== $core ) {
		throw new RuntimeException( 'The hard-stop proof requires the WordPress updater lock to be idle.' );
	}
	$request = new DeploymentRequest( 'org/' . $manualSlug, null, false, 'main', $manualSlug, null, DeploymentPolicy::AUTOMATIC, null );
	$webhookRequest = new DeploymentRequest( 'org/' . $webhookSlug, null, false, 'main', $webhookSlug, null, DeploymentPolicy::AUTOMATIC, null );
	$attempts->admitWebhookBatch(
		'gh',
		'hard-stop-delivery-' . $phase . '-' . $run_id,
		hash( 'sha256', 'hard-stop-' . $phase . '-' . $run_id ),
		array(
			array( 'operation' => 'update', 'package_type' => 'plugin', 'provider_repository_id' => 'first-' . $run_id, 'requested_ref' => str_repeat( 'a', 40 ), 'package_source' => 'branch', 'package_source_revision' => 1, 'request' => $request ),
			array( 'operation' => 'update', 'package_type' => 'plugin', 'provider_repository_id' => 'webhook-' . $run_id, 'requested_ref' => str_repeat( 'a', 40 ), 'package_source' => 'branch', 'package_source_revision' => 1, 'request' => $webhookRequest ),
		)
	);
	return;
}

$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_slug = %s LIMIT 1', $table, $manualSlug ), ARRAY_A );
if ( ! is_array( $row ) ) {
	throw new RuntimeException( 'The hard-stop manual attempt is missing.' );
}

if ( 'assert-retained' === $mode ) {
	$expectedFence = 'pre' !== $phase;
	if ( 'running' !== $row['state'] || $expectedFence !== ( null !== $row['mutation_started_at'] ) ) {
		throw new RuntimeException(
			'The killed worker did not retain the expected state and fence: '
			. wp_json_encode( array( 'state' => $row['state'], 'id' => (string) $row['id'], 'fenced' => null !== $row['mutation_started_at'], 'expected_fenced' => $expectedFence ) )
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
	$coreBefore = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	$result = $booster->make( RAN\Deployment\DeploymentCoordinator::class )->reconcileConfirmedStopped( (int) $row['id'], (string) $row['correlation_id'] );
	$expectedState = 'pre' === $phase ? 'failed' : 'needs_attention';
	$expectedCode  = 'pre' === $phase ? 'worker_stopped' : 'interrupted';
	if ( $expectedState !== $result->getState()->value || $expectedCode !== $result->getOutcome()?->getCode() ) {
		throw new RuntimeException( 'Protected reconciliation produced the wrong hard-stop outcome.' );
	}
	$webhook = $wpdb->get_row( $wpdb->prepare( 'SELECT state, outcome_code FROM %i WHERE package_slug = %s', $table, $webhookSlug ), ARRAY_A );
	$expectedContenderState = 'pre' === $phase ? 'succeeded' : 'failed';
	$expectedContenderCode  = 'pre' === $phase ? 'no_change' : 'lock_unavailable';
	if ( ! is_array( $webhook ) || $expectedContenderState !== $webhook['state'] || $expectedContenderCode !== $webhook['outcome_code'] ) {
		throw new RuntimeException( 'The independent contender did not retain its explicit native-lock outcome.' );
	}
	$coreAfter = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
	if ( $coreBefore !== $coreAfter ) {
		throw new RuntimeException( 'Protected reconciliation changed the native WordPress updater lock.' );
	}
	WP_CLI::success( 'The ' . $phase . '-fence hard stop reconciled truthfully without changing the native updater lock.' );
	return;
}

throw new RuntimeException( 'The hard-stop control mode is invalid.' );

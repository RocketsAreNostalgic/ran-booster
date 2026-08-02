<?php

// Inspect and clean the compact concurrent delivery proof.
// phpcs:disable

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentStorageFailure;

$mode   = $args[0] ?? '';
$run_id = $args[1] ?? '';
if ( ! is_string( $mode ) || ! is_string( $run_id ) || preg_match( '/^[a-f0-9]{24}$/D', $run_id ) !== 1 ) {
	throw new RuntimeException( 'The delivery-intake race control arguments are invalid.' );
}

global $wpdb;
$booster     = require __DIR__ . '/core-container-fixture.php';
$repository  = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$table       = RAN\Storage\Database::attemptTableName();
$provider    = 'fixture-provider';
$delivery_id = 'delivery-intake-race-' . $run_id;
$zero_id     = $delivery_id . '-zero';
$digest      = hash( 'sha256', 'authenticated-body-' . $run_id );

if ( 'cleanup' === $mode ) {
	$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE provider = %s AND delivery_id IN (%s, %s)', $table, $provider, $delivery_id, $zero_id ) );
	if ( false === $result ) {
		throw new RuntimeException( 'The delivery-intake race row could not be removed.' );
	}
	return;
}

if ( 'cron-state' === $mode ) {
	$event = wp_get_scheduled_event( RAN\Deployment\WordPressWorkerWakeup::HOOK, array() );
	WP_CLI::line( false === $event ? 'none' : wp_json_encode( array( 'timestamp' => (int) $event->timestamp, 'schedule' => $event->schedule, 'args' => $event->args ) ) );
	return;
}

if ( 'assert' === $mode ) {
	$results = array();
	foreach ( array( $args[2] ?? '', $args[3] ?? '' ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A delivery-intake result path is invalid.' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'A delivery-intake result is invalid.' );
		}
		$results[] = $data;
	}
	$statuses = array_column( $results, 'status' );
	sort( $statuses );
	if ( array( 'loser', 'winner' ) !== $statuses && array( 'winner', 'winner' ) !== $statuses ) {
		throw new RuntimeException( 'Concurrent delivery admission did not converge on a safe result.' );
	}
	$winners = array_values( array_filter( $results, static fn ( array $result ): bool => 'winner' === $result['status'] ) );
	if ( 1 !== count( $winners ) && 2 !== count( $winners ) ) {
		throw new RuntimeException( 'Concurrent delivery admission did not return a durable identity.' );
	}
	$winner = $winners[0];
	if (
		2 === count( $winners )
		&& (
			$winners[1]['attempt_id'] !== $winner['attempt_id']
			|| $winners[1]['correlation_id'] !== $winner['correlation_id']
		)
	) {
		throw new RuntimeException( 'Concurrent delivery admission returned conflicting identities.' );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s', $table, $provider, $delivery_id ), ARRAY_A );
	if ( ! is_array( $rows ) || 1 !== count( $rows ) || 'queued' !== $rows[0]['state'] || 'commit-' . $run_id !== $rows[0]['requested_ref'] ) {
		throw new RuntimeException( 'The race did not persist exactly one immutable queued target.' );
	}

	$request = new DeploymentRequest( 'group/subgroup/package-' . $run_id, 'race-credential', true, 'main', 'fixture-' . $run_id, null, DeploymentPolicy::AUTOMATIC, null );
	$target  = array( 'operation' => 'update', 'package_type' => 'plugin', 'provider_repository_id' => 'fixture-repository-' . $run_id, 'requested_ref' => 'commit-' . $run_id, 'package_source' => 'branch', 'package_source_revision' => 1, 'request' => $request );
	$new_request = new DeploymentRequest( 'group/subgroup/new-' . $run_id, 'race-credential', true, 'main', 'new-' . $run_id, null, DeploymentPolicy::AUTOMATIC, null );
	$new_target  = array( 'operation' => 'update', 'package_type' => 'plugin', 'provider_repository_id' => 'new-repository-' . $run_id, 'requested_ref' => 'new-commit-' . $run_id, 'package_source' => 'branch', 'package_source_revision' => 1, 'request' => $new_request );
	$replay      = $repository->admitWebhookBatch( $provider, $delivery_id, $digest, array( $target, $new_target ) );
	if ( 1 !== count( $replay ) || $replay[0]->getId() !== $winner['attempt_id'] || $replay[0]->getCorrelationId() !== $winner['correlation_id'] ) {
		throw new RuntimeException( 'A fresh provider replay did not preserve the winning target set.' );
	}
	$repository->admitWebhookBatch( $provider, $zero_id, $digest, array() );
	if ( array() !== $repository->admitWebhookBatch( $provider, $zero_id, $digest, array( $new_target ) ) ) {
		throw new RuntimeException( 'A zero-target delivery admitted a package added after acknowledgement.' );
	}
	$zero_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s', $table, $provider, $zero_id ), ARRAY_A );
	if ( ! is_array( $zero_rows ) || 1 !== count( $zero_rows ) || 'delivery' !== $zero_rows[0]['package_type'] || 'succeeded' !== $zero_rows[0]['state'] ) {
		throw new RuntimeException( 'The zero-target delivery acknowledgement is not durable and immutable.' );
	}
	try {
		$repository->admitWebhookBatch(
			$provider,
			$delivery_id,
			hash( 'sha256', 'different-body-' . $run_id ),
			array( $target )
		);
		throw new RuntimeException( 'Conflicting digest was accepted.' );
	} catch ( DeploymentStorageFailure $failure ) {
		if ( ! $failure->isDeliveryConflict() ) {
			throw $failure;
		}
	}
	WP_CLI::success( 'One immutable delivery identity, durable zero-target acknowledgement and conflict rejection were proven.' );
	return;
}

throw new RuntimeException( 'The delivery-intake race control mode is invalid.' );

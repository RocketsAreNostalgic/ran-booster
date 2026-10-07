<?php

// Inspect and clean the compact concurrent delivery proof.

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentStorageFailure;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$mode               = $args[0] ?? '';
$ran_booster_run_id = $args[1] ?? '';
if ( ! is_string( $mode ) || ! is_string( $ran_booster_run_id ) || preg_match( '/^[a-f0-9]{24}$/D', $ran_booster_run_id ) !== 1 ) {
	throw new RuntimeException( 'The delivery-intake race control arguments are invalid.' );
}

global $wpdb;
$ran_booster_booster     = require __DIR__ . '/core-container-fixture.php';
$ran_booster_repository  = $ran_booster_booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$ran_booster_table       = RAN\Storage\Database::attempt_table_name();
$ran_booster_provider    = 'fixture-provider';
$ran_booster_delivery_id = 'delivery-intake-race-' . $ran_booster_run_id;
$ran_booster_zero_id     = $ran_booster_delivery_id . '-zero';
$ran_booster_digest      = hash( 'sha256', 'authenticated-body-' . $ran_booster_run_id );

if ( 'cleanup' === $mode ) {
	$ran_booster_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE provider = %s AND delivery_id IN (%s, %s)', $ran_booster_table, $ran_booster_provider, $ran_booster_delivery_id, $ran_booster_zero_id ) );
	if ( false === $ran_booster_result ) {
		throw new RuntimeException( 'The delivery-intake race row could not be removed.' );
	}
	return;
}

if ( 'cron-state' === $mode ) {
	$ran_booster_event = wp_get_scheduled_event( RAN\Deployment\WordPressWorkerWakeup::HOOK, array() );
	WP_CLI::line( // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
		false === $ran_booster_event ? 'none' : wp_json_encode(
			array(
				'timestamp' => (int) $ran_booster_event->timestamp,
				'schedule'  => $ran_booster_event->schedule,
				'args'      => $ran_booster_event->args,
			)
		)
	);
	return;
}

if ( 'assert' === $mode ) {
	$ran_booster_results = array();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( array( $args[2] ?? '', $args[3] ?? '' ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A delivery-intake result path is invalid.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$ran_booster_data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $ran_booster_data ) ) {
			throw new RuntimeException( 'A delivery-intake result is invalid.' );
		}
		$ran_booster_results[] = $ran_booster_data;
	}
	$ran_booster_statuses = array_column( $ran_booster_results, 'status' );
	sort( $ran_booster_statuses );
	if ( array( 'loser', 'winner' ) !== $ran_booster_statuses && array( 'winner', 'winner' ) !== $ran_booster_statuses ) {
		throw new RuntimeException( 'Concurrent delivery admission did not converge on a safe result.' );
	}
	$ran_booster_winners = array_values( array_filter( $ran_booster_results, static fn ( array $ran_booster_result ): bool => 'winner' === $ran_booster_result['status'] ) );
	if ( 1 !== count( $ran_booster_winners ) && 2 !== count( $ran_booster_winners ) ) {
		throw new RuntimeException( 'Concurrent delivery admission did not return a durable identity.' );
	}
	$ran_booster_winner = $ran_booster_winners[0];
	if (
		2 === count( $ran_booster_winners )
		&& (
			$ran_booster_winners[1]['attempt_id'] !== $ran_booster_winner['attempt_id']
			|| $ran_booster_winners[1]['correlation_id'] !== $ran_booster_winner['correlation_id']
		)
	) {
		throw new RuntimeException( 'Concurrent delivery admission returned conflicting identities.' );
	}
	$ran_booster_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s', $ran_booster_table, $ran_booster_provider, $ran_booster_delivery_id ), ARRAY_A );
	if ( ! is_array( $ran_booster_rows ) || 1 !== count( $ran_booster_rows ) || 'queued' !== $ran_booster_rows[0]['state'] || 'commit-' . $ran_booster_run_id !== $ran_booster_rows[0]['requested_ref'] ) {
		throw new RuntimeException( 'The race did not persist exactly one immutable queued target.' );
	}

	$ran_booster_request     = new DeploymentRequest( 'group/subgroup/package-' . $ran_booster_run_id, 'race-credential', true, 'main', 'fixture-' . $ran_booster_run_id, null, DeploymentPolicy::AUTOMATIC, null );
	$ran_booster_target      = array(
		'operation'               => 'update',
		'package_type'            => 'plugin',
		'provider_repository_id'  => 'fixture-repository-' . $ran_booster_run_id,
		'requested_ref'           => 'commit-' . $ran_booster_run_id,
		'package_source'          => 'branch',
		'package_source_revision' => 1,
		'request'                 => $ran_booster_request,
	);
	$ran_booster_new_request = new DeploymentRequest( 'group/subgroup/new-' . $ran_booster_run_id, 'race-credential', true, 'main', 'new-' . $ran_booster_run_id, null, DeploymentPolicy::AUTOMATIC, null );
	$ran_booster_new_target  = array(
		'operation'               => 'update',
		'package_type'            => 'plugin',
		'provider_repository_id'  => 'new-repository-' . $ran_booster_run_id,
		'requested_ref'           => 'new-commit-' . $ran_booster_run_id,
		'package_source'          => 'branch',
		'package_source_revision' => 1,
		'request'                 => $ran_booster_new_request,
	);
	$ran_booster_replay      = $ran_booster_repository->admit_webhook_batch( $ran_booster_provider, $ran_booster_delivery_id, $ran_booster_digest, array( $ran_booster_target, $ran_booster_new_target ) );
	if ( 1 !== count( $ran_booster_replay ) || $ran_booster_replay[0]->get_id() !== $ran_booster_winner['attempt_id'] || $ran_booster_replay[0]->get_correlation_id() !== $ran_booster_winner['correlation_id'] ) {
		throw new RuntimeException( 'A fresh provider replay did not preserve the winning target set.' );
	}
	$ran_booster_repository->admit_webhook_batch( $ran_booster_provider, $ran_booster_zero_id, $ran_booster_digest, array() );
	if ( array() !== $ran_booster_repository->admit_webhook_batch( $ran_booster_provider, $ran_booster_zero_id, $ran_booster_digest, array( $ran_booster_new_target ) ) ) {
		throw new RuntimeException( 'A zero-target delivery admitted a package added after acknowledgement.' );
	}
	$ran_booster_zero_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s', $ran_booster_table, $ran_booster_provider, $ran_booster_zero_id ), ARRAY_A );
	if ( ! is_array( $ran_booster_zero_rows ) || 1 !== count( $ran_booster_zero_rows ) || 'delivery' !== $ran_booster_zero_rows[0]['package_type'] || 'succeeded' !== $ran_booster_zero_rows[0]['state'] ) {
		throw new RuntimeException( 'The zero-target delivery acknowledgement is not durable and immutable.' );
	}
	try {
		$ran_booster_repository->admit_webhook_batch(
			$ran_booster_provider,
			$ran_booster_delivery_id,
			hash( 'sha256', 'different-body-' . $ran_booster_run_id ),
			array( $ran_booster_target )
		);
		throw new RuntimeException( 'Conflicting digest was accepted.' );
	} catch ( DeploymentStorageFailure $failure ) {
		if ( ! $failure->is_delivery_conflict() ) {
			throw $failure;
		}
	}
	WP_CLI::success( 'One immutable delivery identity, durable zero-target acknowledgement and conflict rejection were proven.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	return;
}

throw new RuntimeException( 'The delivery-intake race control mode is invalid.' );

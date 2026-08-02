<?php

// Concurrent participant for the compact webhook-attempt admission proof.
// phpcs:disable

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

[$label, $run_id, $ready_marker, $release_marker, $result_marker] = array_pad( $args, 5, '' );
if ( ! in_array( $label, array( 'a', 'b' ), true ) || preg_match( '/^[a-f0-9]{24}$/D', $run_id ) !== 1 ) {
	throw new RuntimeException( 'The delivery-intake race arguments are invalid.' );
}
foreach ( array( $ready_marker, $release_marker, $result_marker ) as $path ) {
	if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException( 'A delivery-intake marker path is invalid.' );
	}
}

$provider    = 'fixture-provider';
$delivery_id = 'delivery-intake-race-' . $run_id;
$digest      = hash( 'sha256', 'authenticated-body-' . $run_id );
$slug        = 'fixture-' . $run_id;
$request     = new DeploymentRequest(
	'group/subgroup/package-' . $run_id,
	'race-credential',
	true,
	'main',
	$slug,
	null,
	DeploymentPolicy::AUTOMATIC,
	null
);
$result = array( 'label' => $label, 'status' => 'loser', 'attempt_id' => null, 'correlation_id' => null );
try {
	global $wpdb;
	$database = new class( $wpdb, $ready_marker, $release_marker ) {
		public string $last_error = '';
		public string $options;
		private bool $barrierReached = false;

		public function __construct(
			private object $database,
			private string $readyMarker,
			private string $releaseMarker
		) {
			$this->options = $database->options;
		}

		public function prepare( string $query, mixed ...$arguments ): string {
			return $this->database->prepare( $query, ...$arguments );
		}

		public function db_server_info(): string {
			return (string) $this->database->db_server_info();
		}

		public function suppress_errors( bool $suppress = true ): bool {
			return (bool) $this->database->suppress_errors( $suppress );
		}

		public function query( string $query ): int|bool {
			$result           = $this->database->query( $query );
			$this->last_error = (string) $this->database->last_error;

			return $result;
		}

		public function insert( string $table, array $data ): int|bool {
			$result           = $this->database->insert( $table, $data );
			$this->last_error = (string) $this->database->last_error;

			return $result;
		}

		public function get_results( string $query ): array|object|null {
			if ( ! $this->barrierReached && str_contains( $query, 'delivery_id' ) && str_contains( $query, 'FOR UPDATE' ) ) {
				$this->barrierReached = true;
				$ready                = fopen( $this->readyMarker, 'x' );
				if ( false === $ready ) {
					throw new RuntimeException( 'The delivery-intake database barrier could not be created.' );
				}
				fwrite( $ready, "ready\n" );
				fclose( $ready );
				$deadline = microtime( true ) + 15.0;
				while ( ! file_exists( $this->releaseMarker ) ) {
					if ( microtime( true ) >= $deadline ) {
						throw new RuntimeException( 'The delivery-intake database barrier timed out.' );
					}
					usleep( 50000 );
				}
			}
			$result           = $this->database->get_results( $query );
			$this->last_error = (string) $this->database->last_error;

			return $result;
		}
	};
	$attempts = ( new RAN\Deployment\DeploymentAttemptRepository( $database ) )->admitWebhookBatch(
		$provider,
		$delivery_id,
		$digest,
		array(
			array(
				'operation'              => 'update',
				'package_type'           => 'plugin',
				'provider_repository_id' => 'fixture-repository-' . $run_id,
				'requested_ref'          => 'commit-' . $run_id,
				'package_source'         => 'branch',
				'package_source_revision' => 1,
				'request'                => $request,
			),
		)
	);
	if ( 1 !== count( $attempts ) ) {
		throw new RuntimeException( 'The concurrent admission did not return one target.' );
	}
	$result = array( 'label' => $label, 'status' => 'winner', 'attempt_id' => $attempts[0]->getId(), 'correlation_id' => $attempts[0]->getCorrelationId() );
} catch ( RAN\Deployment\DeploymentStorageFailure $failure ) {
	// A database-selected loser is safe; provider redelivery is the retry.
	fwrite( STDERR, 'Delivery-intake admission lost safely: ' . $failure->getMessage() . "\n" );
}
$json = wp_json_encode( $result );
$file = fopen( $result_marker, 'x' );
if ( ! is_string( $json ) || false === $file ) {
	throw new RuntimeException( 'The delivery-intake result could not be written.' );
}
fwrite( $file, $json . "\n" );
fclose( $file );

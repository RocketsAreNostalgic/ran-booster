<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\DeploymentAttemptRepository;
use Throwable;

/** Returns safe, read-only state for attempts already visible on a managed-package page. */
final readonly class PackageUpdateProgressController {

	public const AJAX_ACTION   = 'ran_booster_package_update_progress';
	public const NONCE_ACTION  = 'ran-booster-package-update-progress';
	private const MAX_ATTEMPTS = 20;

	public function __construct( private DeploymentAttemptRepository $attempts ) {
	}

	public function handle(): mixed {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( __( 'You are not allowed to view package update progress.', 'ran-booster' ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return $this->error( __( 'The progress request expired. Reload the page and try again.', 'ran-booster' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The dedicated AJAX nonce is checked above.
		$packageType = isset( $_POST['package_type'] ) && is_string( $_POST['package_type'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The dedicated AJAX nonce is checked above.
			? wp_unslash( $_POST['package_type'] )
			: '';
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) ) {
			return $this->error( __( 'The package type is invalid.', 'ran-booster' ), 400 );
		}
		$capability = 'plugin' === $packageType ? 'update_plugins' : 'update_themes';
		if ( ! current_user_can( $capability ) ) {
			return $this->error( __( 'You are not allowed to update these packages.', 'ran-booster' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The dedicated AJAX nonce is checked above.
		$input = $_POST['attempts'] ?? null;
		if ( ! is_array( $input ) || array() === $input || count( $input ) > self::MAX_ATTEMPTS ) {
			return $this->error( __( 'The progress request is invalid.', 'ran-booster' ), 400 );
		}

		$references = array();
		foreach ( $input as $attemptId => $reference ) {
			$id = is_int( $attemptId ) ? $attemptId : ( ctype_digit( (string) $attemptId ) ? (int) $attemptId : 0 );
			if ( $id < 1 || (string) $id !== (string) $attemptId || ! is_string( $reference ) ) {
				return $this->error( __( 'The progress request is invalid.', 'ran-booster' ), 400 );
			}
			$reference = wp_unslash( $reference );
			if ( preg_match( '/^[a-f0-9]{32}$/D', $reference ) !== 1 || isset( $references[ $id ] ) ) {
				return $this->error( __( 'The progress request is invalid.', 'ran-booster' ), 400 );
			}
			$references[ $id ] = $reference;
		}

		try {
			$found = $this->attempts->findExactBatch( array_keys( $references ) );
		} catch ( Throwable ) {
			return $this->error( __( 'Package update progress is temporarily unavailable.', 'ran-booster' ), 503 );
		}

		$items = array();
		foreach ( $found as $id => $attempt ) {
			if ( ! hash_equals( $references[ $id ], $attempt->getCorrelationId() ) ) {
				continue;
			}
			$items[ (string) $id ] = array(
				'attempt_id' => $id,
				'reference'  => $attempt->getCorrelationId(),
				'state'      => $attempt->getState()->value,
			);
		}

		return wp_send_json_success( array( 'items' => $items ) );
	}

	/** @return mixed */
	private function error( string $message, int $status ): mixed {
		return wp_send_json_error( array( 'message' => $message ), $status );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;

/** Secret-free result data suitable for a signed administrator redirect. */
final readonly class BulkPackageResult {

	/**
	 * @param array<string, int> $skippedByReason
	 */
	private function __construct(
		public string $operation,
		public int $selected,
		public int $changed,
		public int $unchanged,
		public int $queued,
		public array $skippedByReason,
		public string $runnerStatus,
		public string $errorCode = ''
	) {
		$processed = $changed + $unchanged + $queued + array_sum( $skippedByReason );
		if ( ! in_array( $operation, BulkPackageAction::operations(), true )
			|| ! in_array( $errorCode, array_merge( array( '' ), self::errorCodes() ), true )
			|| $selected < 0 || $selected > BulkPackageAction::MAX_IDENTIFIERS
			|| min( $changed, $unchanged, $queued ) < 0
			|| ( '' === $errorCode && $processed !== $selected )
			|| ( '' !== $errorCode && 0 !== $processed )
			|| ( BulkPackageAction::QUEUE_UPDATE === $operation && ( 0 !== $changed || 0 !== $unchanged ) )
			|| ( BulkPackageAction::QUEUE_UPDATE !== $operation && 0 !== $queued )
			|| ! in_array( $runnerStatus, array( 'scheduled', 'already_scheduled', 'unavailable', 'not_required' ), true ) ) {
			throw new InvalidArgumentException( 'The bulk package result is invalid.' );
		}
		foreach ( $skippedByReason as $reason => $count ) {
			if ( ! in_array( $reason, self::skipReasonCodes(), true ) || $count < 1 || $count > BulkPackageAction::MAX_IDENTIFIERS ) {
				throw new InvalidArgumentException( 'The bulk package result is invalid.' );
			}
		}
	}

	/** @param array{selected: int, changed: int, unchanged: int} $result */
	public static function policy( string $operation, array $result ): self {
		return new self(
			$operation,
			$result['selected'],
			$result['changed'],
			$result['unchanged'],
			0,
			array(),
			'not_required'
		);
	}

	/** @param array<string, int> $skippedByReason */
	public static function queue( int $selected, int $queued, array $skippedByReason, string $runnerStatus ): self {
		ksort( $skippedByReason, SORT_STRING );

		return new self(
			BulkPackageAction::QUEUE_UPDATE,
			$selected,
			0,
			0,
			$queued,
			$skippedByReason,
			$runnerStatus
		);
	}

	/** @param array<string, int> $skippedByReason */
	public static function pluginActivation(
		string $operation,
		int $selected,
		int $changed,
		int $unchanged,
		array $skippedByReason
	): self {
		if ( ! in_array( $operation, BulkPackageAction::pluginActivationOperations(), true ) ) {
			throw new InvalidArgumentException( 'The plugin activation operation is invalid.' );
		}
		ksort( $skippedByReason, SORT_STRING );

		return new self(
			$operation,
			$selected,
			$changed,
			$unchanged,
			0,
			$skippedByReason,
			'not_required'
		);
	}

	public static function error( string $operation, int $selected, string $errorCode ): self {
		if ( ! in_array( $operation, BulkPackageAction::operations(), true ) ) {
			$operation = BulkPackageAction::QUEUE_UPDATE;
		}
		if ( ! in_array( $errorCode, self::errorCodes(), true ) ) {
			$errorCode = 'unavailable';
		}

		return new self( $operation, $selected, 0, 0, 0, array(), 'not_required', $errorCode );
	}

	public function skipped(): int {
		return array_sum( $this->skippedByReason );
	}

	/** @return array<string, string> */
	public function noticeData(): array {
		$reasons = $this->skippedByReason;
		ksort( $reasons, SORT_STRING );
		$encodedReasons = array();
		foreach ( $reasons as $reason => $count ) {
			$encodedReasons[] = $reason . ':' . $count;
		}

		return array(
			'operation' => $this->operation,
			'selected'  => (string) $this->selected,
			'changed'   => (string) $this->changed,
			'unchanged' => (string) $this->unchanged,
			'queued'    => (string) $this->queued,
			'skips'     => implode( ',', $encodedReasons ),
			'runner'    => $this->runnerStatus,
			'error'     => $this->errorCode,
		);
	}

	/** @param array<string, mixed> $data */
	public static function fromNoticeData( array $data ): self {
		foreach ( array( 'operation', 'selected', 'changed', 'unchanged', 'queued', 'skips', 'runner', 'error' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				throw new InvalidArgumentException( 'The bulk package notice is invalid.' );
			}
		}

		$integers = array();
		foreach ( array( 'selected', 'changed', 'unchanged', 'queued' ) as $key ) {
			if ( preg_match( '/^(?:0|[1-9][0-9]{0,2})$/D', $data[ $key ] ) !== 1 ) {
				throw new InvalidArgumentException( 'The bulk package notice is invalid.' );
			}
			$integers[ $key ] = (int) $data[ $key ];
		}

		$reasons = array();
		if ( '' !== $data['skips'] ) {
			foreach ( explode( ',', $data['skips'] ) as $entry ) {
				if ( preg_match( '/^([a-z_]+):([1-9][0-9]?)$/D', $entry, $matches ) !== 1
					|| isset( $reasons[ $matches[1] ] ) ) {
					throw new InvalidArgumentException( 'The bulk package notice is invalid.' );
				}
				$reasons[ $matches[1] ] = (int) $matches[2];
			}
		}

		return new self(
			$data['operation'],
			$integers['selected'],
			$integers['changed'],
			$integers['unchanged'],
			$integers['queued'],
			$reasons,
			$data['runner'],
			$data['error']
		);
	}

	/** @return list<string> */
	public static function skipReasonCodes(): array {
		return array(
			'active_dependents',
			'activation_failed',
			'busy',
			'credential_unavailable',
			'deactivation_failed',
			'disabled',
			'permission',
			'provider_unavailable',
			'release_source',
			'self_deactivation',
			'self_update',
			'stale',
		);
	}

	/** @return list<string> */
	public static function errorCodes(): array {
		return array(
			'credential_unavailable',
			'invalid_request',
			'provider_unavailable',
			'stale',
			'unavailable',
			'webhook_unavailable',
		);
	}
}

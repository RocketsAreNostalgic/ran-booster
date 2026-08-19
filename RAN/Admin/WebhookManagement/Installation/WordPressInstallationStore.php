<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Installation;

use Closure;
use Throwable;

final class WordPressInstallationStore implements InstallationStore {
	public const OPTION_NAME   = 'ran_booster_assisted_hooks_installations';
	private const CAS_ATTEMPTS = 5;

	/** @var Closure(string,mixed,mixed,bool): bool */
	private Closure $compareAndSwap;

	/** @param callable(string,mixed,mixed,bool): bool|null $compareAndSwap */
	public function __construct( ?callable $compareAndSwap = null ) {
		$this->compareAndSwap = null === $compareAndSwap
			? static function ( string $option, mixed $expected, mixed $replacement, bool $exists ): bool {
				if ( ! $exists ) {
					return add_option( $option, $replacement, '', false );
				}

				global $wpdb;
				$oldValue = maybe_serialize( $expected );
				$newValue = maybe_serialize( $replacement );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The old option value is the optimistic concurrency token; update_option() cannot express this CAS.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
						$newValue,
						$option,
						$oldValue
					)
				);
				if ( 1 !== $updated || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
					return false;
				}

				return true;
			}
			: Closure::fromCallable( $compareAndSwap );
	}

	public function all(): array {
		return $this->records();
	}

	public function find( string $providerCode, string $repositoryId ): ?InstallationRecord {
		$records = $this->records();

		return $records[ InstallationRecord::key( $providerCode, $repositoryId ) ] ?? null;
	}

	public function saveIfCurrent( InstallationRecord $record, ?InstallationRecord $expected ): string {
		return $this->write( $record, $expected );
	}

	public function deleteIfCurrent( string $providerCode, string $repositoryId, ?InstallationRecord $expected ): string {
		return $this->remove( $providerCode, $repositoryId, $expected );
	}

	/** @return array<string, InstallationRecord> */
	private function records(): array {
		$snapshot = $this->snapshot();
		$parsed   = $this->parseRecords( $snapshot['value'] );

		return $parsed['records'];
	}

	/** @return array{records:array<string, InstallationRecord>,complete:bool} */
	private function parseRecords( mixed $raw ): array {
		$records  = array();
		$complete = true;

		if ( ! is_array( $raw ) || count( $raw ) > 1000 ) {
			return array(
				'records'  => $records,
				'complete' => false,
			);
		}

		foreach ( $raw as $key => $record ) {
			if ( ! is_string( $key ) || ! is_array( $record ) ) {
				$complete = false;
				continue;
			}

			try {
				$parsed = InstallationRecord::fromArray( $record );
			} catch ( Throwable ) {
				$complete = false;
				continue;
			}

			if ( hash_equals( $parsed->storageKey(), $key ) ) {
				$records[ $key ] = $parsed;
			} else {
				$complete = false;
			}
		}

		return array(
			'records'  => $records,
			'complete' => $complete,
		);
	}

	private function write( InstallationRecord $record, ?InstallationRecord $expected ): string {
		$key = $record->storageKey();
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$snapshot = $this->snapshot();
			$parsed   = $this->parseRecords( $snapshot['value'] );
			if ( ! $parsed['complete'] ) {
				return self::WRITE_FAILED;
			}
			$records = $parsed['records'];
			$current = $records[ $key ] ?? null;
			if ( $this->same( $current, $record ) ) {
				return self::WRITE_UNCHANGED;
			}
			if ( ! $this->same( $current, $expected ) ) {
				return self::WRITE_CONFLICT;
			}

			$records[ $key ] = $record;
			if ( ( $this->compareAndSwap )( self::OPTION_NAME, $snapshot['value'], $this->serialize( $records ), $snapshot['exists'] ) ) {
				$this->refreshOptionCache();

				return self::WRITE_APPLIED;
			}
			$this->refreshOptionCache();
		}

		return self::WRITE_FAILED;
	}

	private function remove( string $providerCode, string $repositoryId, ?InstallationRecord $expected ): string {
		$key = InstallationRecord::key( $providerCode, $repositoryId );
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$snapshot = $this->snapshot();
			$parsed   = $this->parseRecords( $snapshot['value'] );
			if ( ! $parsed['complete'] ) {
				return self::WRITE_FAILED;
			}
			$records = $parsed['records'];
			$current = $records[ $key ] ?? null;
			if ( null === $current ) {
				return self::WRITE_UNCHANGED;
			}
			if ( ! $this->same( $current, $expected ) ) {
				return self::WRITE_CONFLICT;
			}

			unset( $records[ $key ] );
			if ( ( $this->compareAndSwap )( self::OPTION_NAME, $snapshot['value'], $this->serialize( $records ), $snapshot['exists'] ) ) {
				$this->refreshOptionCache();

				return self::WRITE_APPLIED;
			}
			$this->refreshOptionCache();
		}

		return self::WRITE_FAILED;
	}

	/** @return array{exists:bool,value:mixed} */
	private function snapshot(): array {
		$missing = new \stdClass();
		$value   = get_option( self::OPTION_NAME, $missing );

		return array(
			'exists' => $missing !== $value,
			'value'  => $missing === $value ? array() : $value,
		);
	}

	private function same( ?InstallationRecord $left, ?InstallationRecord $right ): bool {
		return null === $left || null === $right
			? $left === $right
			: $left->toArray() === $right->toArray();
	}

	private function refreshOptionCache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/** @param array<string, InstallationRecord> $records
	 * @return array<string, array<string, int|string>>
	 */
	private function serialize( array $records ): array {
		return array_map( static fn ( InstallationRecord $record ): array => $record->toArray(), $records );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Package;
use RuntimeException;

/**
 * Bounded, non-secret evidence that an administrator explicitly checked a
 * package branch. It is a historical observation, never deployment authority.
 */
class RepositoryBranchCheckEvidenceStore {

	public const OPTION_NAME = 'ran_booster_repository_branch_check_evidence';

	private const MAX_RECORDS = 200;

	/**
	 * @return array{outcome: 'verified', checked_at: string}|null
	 */
	public function find( string $type, Package $package, ?string $profile_id ): ?array {
		$record = $this->all()['records'][ $this->key( $type, $package ) ] ?? null;
		if ( ! is_array( $record )
			|| 'verified' !== ( $record['outcome'] ?? null )
			|| ! is_string( $record['checked_at'] ?? null )
			|| ! is_string( $record['target'] ?? null )
			|| ! is_string( $record['profile'] ?? null )
			|| ! hash_equals( $this->target_fingerprint( $package ), $record['target'] )
			|| ! hash_equals( $this->profile_fingerprint( $package, $profile_id ), $record['profile'] )
		) {
			return null;
		}

		return array(
			'outcome'    => 'verified',
			'checked_at' => $record['checked_at'],
		);
	}

	/** Record verified evidence or clear any earlier record after a failed check. */
	public function record( string $type, Package $package, ?string $profile_id, string $outcome, ?string $profile_fingerprint = null ): void {
		$this->mutate(
			function ( array $all ) use ( $type, $package, $profile_id, $outcome, $profile_fingerprint ): array {
				$key = $this->key( $type, $package );
				unset( $all['records'][ $key ] );
				if ( 'verified' === $outcome ) {
					$all['records'][ $key ] = array(
						'outcome'    => 'verified',
						'checked_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
						'target'     => $this->target_fingerprint( $package ),
						'profile'    => $profile_fingerprint ?? $this->profile_fingerprint( $package, $profile_id ),
					);
					if ( count( $all['records'] ) > self::MAX_RECORDS ) {
						array_shift( $all['records'] );
					}
				}

				return $all;
			}
		);
	}

	/** Clear evidence when this managed package lifecycle ends. */
	public function clear( string $type, Package $package ): void {
		$this->mutate(
			function ( array $all ) use ( $type, $package ): array {
				unset( $all['records'][ $this->key( $type, $package ) ] );
				if ( $all['generation'] >= PHP_INT_MAX ) {
					$all['records']    = array();
					$all['generation'] = 1;

					return $all;
				}
				++$all['generation'];

				return $all;
			}
		);
	}

	/** Capture the exact credential-generation state used by an explicit remote check. */
	public function profile_fingerprint_for( Package $package, ?string $profile_id ): string {
		return $this->profile_fingerprint( $package, $profile_id );
	}

	/** Invalidate earlier checks after a credential profile is replaced or deleted. */
	public function bump_profile_generation( string $provider, string $profile_id ): void {
		$this->require_provider( $provider );
		$this->require_profile_id( $profile_id );
		$this->bump_generation();
	}

	/** Invalidate public checks when the provider default changes. */
	public function bump_provider_generation( string $provider ): void {
		$this->require_provider( $provider );
		$this->bump_generation();
	}

	private function bump_generation(): void {
		$this->mutate(
			static function ( array $all ): array {
				if ( $all['generation'] >= PHP_INT_MAX ) {
					$all['records']    = array();
					$all['generation'] = 1;

					return $all;
				}
				++$all['generation'];

				return $all;
			}
		);
	}

	/** @return array<array-key, mixed> */
	protected function read_option(): array {
		$value = get_option( self::OPTION_NAME, array() );
		return is_array( $value ) ? $value : array(
			'records'    => array(),
			'generation' => 0,
		);
	}

	/** @param array<string, mixed> $records */
	protected function write_option( array $records ): bool {
		return update_option( self::OPTION_NAME, $records, false );
	}

	/** @return array{records: array<array-key, mixed>, generation: int} */
	private function all(): array {
		$value = $this->read_option();
		return array(
			'records'    => is_array( $value['records'] ?? null ) ? $value['records'] : array(),
			'generation' => is_int( $value['generation'] ?? null ) && $value['generation'] >= 0 ? $value['generation'] : 0,
		);
	}

	/** @param array<string, mixed> $all */
	private function persist( array $all ): void {
		if ( ! $this->write_option( $all )
			&& $all !== $this->all()
		) {
			throw new RuntimeException( 'Booster could not save repository branch check evidence.' );
		}
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $mutation */
	private function mutate( callable $mutation ): void {
		if ( ! $this->acquire_mutation_lock() ) {
			throw new RuntimeException( 'Booster could not coordinate repository branch check evidence.' );
		}

		try {
			$this->persist( $mutation( $this->all() ) );
		} finally {
			if ( ! $this->release_mutation_lock() ) {
				throw new RuntimeException( 'Booster could not release the repository branch check evidence lock.' );
			}
		}
	}

	protected function acquire_mutation_lock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock serializes one option mutation.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::mutation_lock_name() ) );

		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	protected function release_mutation_lock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock has no persistent cacheable state.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::mutation_lock_name() ) );

		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private static function mutation_lock_name(): string {
		global $wpdb;
		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'tests';

		return 'ran_booster_branch_evidence_' . substr( hash( 'sha256', $options ), 0, 32 );
	}

	private function key( string $type, Package $package ): string {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! is_string( $package->get_identifier() ) ) {
			throw new RuntimeException( 'Booster cannot save repository branch check evidence for this package.' );
		}
		return hash( 'sha256', $type . "\\0" . $package->get_identifier() );
	}

	private function target_fingerprint( Package $package ): string {
		$reference = $package->get_repository()->reference;
		return hash(
			'sha256',
			implode(
				"\\0",
				array(
					(string) $package->get_source()->value,
					(string) $package->get_source_revision(),
					(string) $package->get_provider_code(),

					(string) $reference->provider_repository_id,
					(string) $reference->locator,
					(string) $package->get_branch(),
					$reference->private ? '1' : '0',

					(string) $reference->credential_id,
					(string) $package->get_subdirectory(),
				)
			)
		);
	}

	private function profile_fingerprint( Package $package, ?string $profile_id ): string {
		$provider  = (string) $package->get_provider_code();
		$anonymous = null === $profile_id || '' === $profile_id;
		$profile   = $anonymous ? 'anonymous:' : 'profile:' . $profile_id;
		$all       = $this->all();
		return hash(
			'sha256',
			implode(
				"\\0",
				array(
					$provider,
					$profile,
					(string) $all['generation'],
				)
			)
		);
	}

	private function require_provider( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Booster cannot update repository branch check evidence for this provider.' );
		}
	}

	private function require_profile_id( string $profile_id ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
			throw new RuntimeException( 'Booster cannot update repository branch check evidence for this profile.' );
		}
	}
}

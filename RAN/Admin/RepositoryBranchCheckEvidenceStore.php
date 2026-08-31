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
	public function find( string $type, Package $package, ?string $profileId ): ?array {
		$record = $this->all()['records'][ $this->key( $type, $package ) ] ?? null;
		if ( ! is_array( $record )
			|| 'verified' !== ( $record['outcome'] ?? null )
			|| ! is_string( $record['checked_at'] ?? null )
			|| ! is_string( $record['target'] ?? null )
			|| ! is_string( $record['profile'] ?? null )
			|| ! hash_equals( $this->targetFingerprint( $package ), $record['target'] )
			|| ! hash_equals( $this->profileFingerprint( $package, $profileId ), $record['profile'] )
		) {
			return null;
		}

		return array(
			'outcome'    => 'verified',
			'checked_at' => $record['checked_at'],
		);
	}

	/** Record verified evidence or clear any earlier record after a failed check. */
	public function record( string $type, Package $package, ?string $profileId, string $outcome, ?string $profileFingerprint = null ): void {
		$this->mutate(
			function ( array $all ) use ( $type, $package, $profileId, $outcome, $profileFingerprint ): array {
				$key = $this->key( $type, $package );
				unset( $all['records'][ $key ] );
				if ( 'verified' === $outcome ) {
					$all['records'][ $key ] = array(
						'outcome'    => 'verified',
						'checked_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
						'target'     => $this->targetFingerprint( $package ),
						'profile'    => $profileFingerprint ?? $this->profileFingerprint( $package, $profileId ),
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

				return $all;
			}
		);
	}

	/** Capture the exact credential-generation state used by an explicit remote check. */
	public function profileFingerprintFor( Package $package, ?string $profileId ): string {
		return $this->profileFingerprint( $package, $profileId );
	}

	/** Invalidate earlier checks after a credential profile is replaced or deleted. */
	public function bumpProfileGeneration( string $provider, string $profileId ): void {
		$this->requireProvider( $provider );
		$this->requireProfileId( $profileId );
		$this->bumpGeneration();
	}

	/** Invalidate public checks when the provider default changes. */
	public function bumpProviderGeneration( string $provider ): void {
		$this->requireProvider( $provider );
		$this->bumpGeneration();
	}

	private function bumpGeneration(): void {
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

	/** @return array{records: array<string, array<string, string>>, generation: int} */
	protected function readOption(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array(
				'records'    => array(),
				'generation' => 0,
			);
		}
		$value = get_option( self::OPTION_NAME, array() );
		return is_array( $value ) ? $value : array(
			'records'    => array(),
			'generation' => 0,
		);
	}

	/** @param array<string, mixed> $records */
	protected function writeOption( array $records ): bool {
		return ! function_exists( 'update_option' ) || update_option( self::OPTION_NAME, $records, false );
	}

	/** @return array{records: array<string, array<string, string>>, generation: int} */
	private function all(): array {
		$value = $this->readOption();
		return array(
			'records'    => is_array( $value['records'] ?? null ) ? $value['records'] : array(),
			'generation' => is_int( $value['generation'] ?? null ) && $value['generation'] >= 0 ? $value['generation'] : 0,
		);
	}

	/** @param array<string, mixed> $all */
	private function persist( array $all ): void {
		if ( ! $this->writeOption( $all )
			&& function_exists( 'get_option' )
			&& function_exists( 'update_option' )
			&& $all !== $this->all()
		) {
			throw new RuntimeException( 'Booster could not save repository branch check evidence.' );
		}
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $mutation */
	private function mutate( callable $mutation ): void {
		if ( ! $this->acquireMutationLock() ) {
			throw new RuntimeException( 'Booster could not coordinate repository branch check evidence.' );
		}

		try {
			$this->persist( $mutation( $this->all() ) );
		} finally {
			if ( ! $this->releaseMutationLock() ) {
				throw new RuntimeException( 'Booster could not release the repository branch check evidence lock.' );
			}
		}
	}

	protected function acquireMutationLock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock serializes one option mutation.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::mutationLockName() ) );

		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	protected function releaseMutationLock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local advisory lock has no persistent cacheable state.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::mutationLockName() ) );

		return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
	}

	private static function mutationLockName(): string {
		global $wpdb;
		$options = is_object( $wpdb ) && isset( $wpdb->options ) ? (string) $wpdb->options : 'tests';

		return 'ran_booster_branch_evidence_' . substr( hash( 'sha256', $options ), 0, 32 );
	}

	private function key( string $type, Package $package ): string {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! is_string( $package->getIdentifier() ) ) {
			throw new RuntimeException( 'Booster cannot save repository branch check evidence for this package.' );
		}
		return hash( 'sha256', $type . "\\0" . $package->getIdentifier() );
	}

	private function targetFingerprint( Package $package ): string {
		$reference = $package->getRepository()->reference;
		return hash(
			'sha256',
			implode(
				"\\0",
				array(
					(string) $package->getSource()->value,
					(string) $package->getSourceRevision(),
					(string) $package->getProviderCode(),
					(string) $reference->providerRepositoryId,
					(string) $reference->locator,
					(string) $package->getBranch(),
					$reference->private ? '1' : '0',
					(string) $reference->credentialId,
					(string) $package->getSubdirectory(),
				)
			)
		);
	}

	private function profileFingerprint( Package $package, ?string $profileId ): string {
		$provider  = (string) $package->getProviderCode();
		$anonymous = null === $profileId || '' === $profileId;
		$profile   = $anonymous ? 'anonymous:' : 'profile:' . $profileId;
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

	private function requireProvider( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Booster cannot update repository branch check evidence for this provider.' );
		}
	}

	private function requireProfileId( string $profileId ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
			throw new RuntimeException( 'Booster cannot update repository branch check evidence for this profile.' );
		}
	}
}

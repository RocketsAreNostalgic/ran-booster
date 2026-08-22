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
	public function record( string $type, Package $package, ?string $profileId, string $outcome ): void {
		$all = $this->all();
		$key = $this->key( $type, $package );
		if ( 'verified' !== $outcome ) {
			unset( $all['records'][ $key ] );
			$this->persist( $all );
			return;
		}

		unset( $all['records'][ $key ] );
		$all['records'][ $key ] = array(
			'outcome'    => 'verified',
			'checked_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			'target'     => $this->targetFingerprint( $package ),
			'profile'    => $this->profileFingerprint( $package, $profileId ),
		);
		if ( count( $all['records'] ) > self::MAX_RECORDS ) {
			array_shift( $all['records'] );
		}
		$this->persist( $all );
	}

	/** Invalidate checks that used a same-ID credential profile after replacement or deletion. */
	public function bumpProfileGeneration( string $provider, string $profileId ): void {
		$this->requireProvider( $provider );
		$this->requireProfileId( $profileId );
		$all                                = $this->all();
		$key                                = $provider . ':' . $profileId;
		$all['profile_generations'][ $key ] = (int) ( $all['profile_generations'][ $key ] ?? 0 ) + 1;
		$this->persist( $all );
	}

	/** Invalidate public checks when the provider default changes. */
	public function bumpProviderGeneration( string $provider ): void {
		$this->requireProvider( $provider );
		$all                                      = $this->all();
		$all['provider_generations'][ $provider ] = (int) ( $all['provider_generations'][ $provider ] ?? 0 ) + 1;
		$this->persist( $all );
	}

	/** @return array{records: array<string, array<string, string>>, profile_generations: array<string, int>, provider_generations: array<string, int>} */
	protected function readOption(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array(
				'records'              => array(),
				'profile_generations'  => array(),
				'provider_generations' => array(),
			);
		}
		$value = get_option( self::OPTION_NAME, array() );
		return is_array( $value ) ? $value : array(
			'records'              => array(),
			'profile_generations'  => array(),
			'provider_generations' => array(),
		);
	}

	/** @param array<string, mixed> $records */
	protected function writeOption( array $records ): bool {
		return ! function_exists( 'update_option' ) || update_option( self::OPTION_NAME, $records, false );
	}

	/** @return array{records: array<string, array<string, string>>, profile_generations: array<string, int>, provider_generations: array<string, int>} */
	private function all(): array {
		$value = $this->readOption();
		return array(
			'records'              => is_array( $value['records'] ?? null ) ? $value['records'] : array(),
			'profile_generations'  => is_array( $value['profile_generations'] ?? null ) ? $value['profile_generations'] : array(),
			'provider_generations' => is_array( $value['provider_generations'] ?? null ) ? $value['provider_generations'] : array(),
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
		$provider             = (string) $package->getProviderCode();
		$anonymous            = null === $profileId || '' === $profileId;
		$profile              = $anonymous ? 'anonymous:' : 'profile:' . $profileId;
		$profileGenerationKey = $anonymous ? null : $provider . ':' . $profileId;
		$all                  = $this->all();
		return hash(
			'sha256',
			implode(
				"\\0",
				array(
					$provider,
					$profile,
					(string) ( $all['provider_generations'][ $provider ] ?? 0 ),
					(string) ( null === $profileGenerationKey ? 0 : ( $all['profile_generations'][ $profileGenerationKey ] ?? 0 ) ),
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

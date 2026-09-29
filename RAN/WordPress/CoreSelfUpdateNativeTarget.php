<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use Throwable;

/** Adapts Booster's own selected updater handle without routing through a repository provider. */
final class CoreSelfUpdateNativeTarget implements RepositoryReleaseNativeTarget {

	private const NATIVE_STATUS_KEYS = array(
		'candidate_header_version',
		'candidate_tag',
		'candidate_validation_code',
		'candidate_version',
		'failure_code',
		'installed_version',
		'last_check',
		'offered_release_identity',
		'offered_version',
		'relationship',
	);

	public function __construct( private readonly object $updater ) {
	}

	public function register(): bool {
		if ( ! is_callable( array( $this->updater, 'register' ) ) ) {
			return false;
		}

		try {
			return true === $this->updater->register();
		} catch ( Throwable ) {
			return false;
		}
	}

	public function status(): RepositoryReleaseNativeTargetStatus {
		if ( ! is_callable( array( $this->updater, 'status' ) ) ) {
			return $this->unavailable_status();
		}

		try {
			$outer = $this->updater->status();
			if ( ! is_array( $outer )
				|| 5 !== count( $outer )
				|| array_diff( array_keys( $outer ), array( 'state', 'declaration_accepted', 'hooks_registered', 'code', 'native' ) ) !== array() ) {
				return $this->unavailable_status();
			}
			if ( 'active' !== $outer['state']
				|| true !== $outer['declaration_accepted']
				|| true !== $outer['hooks_registered']
				|| 'target_active' !== $outer['code'] ) {
				if ( 'inactive' === $outer['state'] ) {
					$code = $this->status_code( $outer['code'] );

					return new RepositoryReleaseNativeTargetStatus(
						false,
						failureCode: '' === $code ? 'github_updater_status_unavailable' : 'github_updater_' . substr( $code, 0, 48 )
					);
				}

				return new RepositoryReleaseNativeTargetStatus( false );
			}

			$status = $outer['native'];
			if ( ! is_array( $status )
				|| 10 !== count( $status )
				|| array_diff( array_keys( $status ), self::NATIVE_STATUS_KEYS ) !== array()
				|| ! $this->valid_updater_status( $status ) ) {
				return $this->unavailable_status();
			}
			$offered_version = $this->status_version( $status['offered_version'] );
			$relationship    = $this->status_relationship( $status['relationship'] );
			$failure_code    = $this->status_code( $status['failure_code'] );
			$last_check      = is_int( $status['last_check'] ) && 0 < $status['last_check'] ? $status['last_check'] : null;
			if ( ( null !== $status['offered_version'] && '' === $offered_version )
				|| ( null !== $status['relationship'] && '' === $relationship )
				|| ( null !== $status['failure_code'] && '' === $failure_code )
				|| ( null !== $status['last_check'] && null === $last_check ) ) {
				return $this->unavailable_status();
			}

			return new RepositoryReleaseNativeTargetStatus(
				true,
				$offered_version,
				$relationship,
				$last_check,
				null,
				$failure_code
			);
		} catch ( Throwable ) {
			return $this->unavailable_status();
		}
	}

	public function refresh(): bool {
		if ( ! is_callable( array( $this->updater, 'refresh' ) ) ) {
			return false;
		}

		try {
			return true === $this->updater->refresh();
		} catch ( Throwable ) {
			return false;
		}
	}

	/** @param array<string, mixed> $status */
	private function valid_updater_status( array $status ): bool {
		return ( null === $status['candidate_tag'] || '' !== $this->status_text( $status['candidate_tag'], 100 ) )
			&& ( null === $status['candidate_validation_code'] || '' !== $this->status_code( $status['candidate_validation_code'] ) )
			&& ( null === $status['candidate_version'] || '' !== $this->status_version( $status['candidate_version'] ) )
			&& ( null === $status['candidate_header_version'] || '' !== $this->status_version( $status['candidate_header_version'] ) )
			&& ( null === $status['failure_code'] || '' !== $this->status_code( $status['failure_code'] ) )
			&& ( null === $status['installed_version'] || '' !== $this->status_version( $status['installed_version'] ) )
			&& ( null === $status['last_check'] || ( is_int( $status['last_check'] ) && 0 < $status['last_check'] ) )
			&& ( null === $status['offered_version'] || '' !== $this->status_version( $status['offered_version'] ) )
			&& ( null === $status['offered_release_identity'] || '' !== $this->status_text( $status['offered_release_identity'], 191 ) )
			&& ( ( null === $status['offered_version'] ) === ( null === $status['offered_release_identity'] ) )
			&& ( null === $status['relationship'] || '' !== $this->status_relationship( $status['relationship'] ) )
			&& $this->valid_candidate_tuple( $status );
	}

	/** @param array<string, mixed> $status */
	private function valid_candidate_tuple( array $status ): bool {
		$present = array(
			null !== $status['candidate_validation_code'],
			null !== $status['candidate_tag'],
			null !== $status['candidate_version'],
		);
		$count   = count( array_filter( $present ) );
		if ( 0 !== $count && 3 !== $count ) {
			return false;
		}
		if ( 0 === $count ) {
			return null === $status['candidate_header_version'];
		}
		if ( 'archive_identity_verified' === $status['candidate_validation_code']
			&& null === $status['candidate_header_version'] ) {
			return false;
		}

		return null === $status['offered_release_identity']
			|| 'archive_identity_verified' === $status['candidate_validation_code'];
	}

	private function unavailable_status(): RepositoryReleaseNativeTargetStatus {
		return new RepositoryReleaseNativeTargetStatus( false, failureCode: 'github_updater_status_unavailable' );
	}

	private function status_code( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value ) ? $value : '';
	}

	private function status_relationship( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'newer', 'same', 'older', 'invalid' ), true ) ? $value : '';
	}

	private function status_text( mixed $value, int $maximum_length ): string {
		return is_string( $value ) && strlen( $value ) <= $maximum_length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	private function status_version( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value ) ? $value : '';
	}
}

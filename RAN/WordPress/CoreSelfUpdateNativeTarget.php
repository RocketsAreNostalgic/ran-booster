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
			return $this->unavailableStatus();
		}

		try {
			$outer = $this->updater->status();
			if ( ! is_array( $outer )
				|| 5 !== count( $outer )
				|| array_diff( array_keys( $outer ), array( 'state', 'declaration_accepted', 'hooks_registered', 'code', 'native' ) ) !== array() ) {
				return $this->unavailableStatus();
			}
			if ( 'active' !== $outer['state']
				|| true !== $outer['declaration_accepted']
				|| true !== $outer['hooks_registered']
				|| 'target_active' !== $outer['code'] ) {
				if ( 'inactive' === $outer['state'] ) {
					$code = $this->statusCode( $outer['code'] );

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
				|| ! $this->validUpdaterStatus( $status ) ) {
				return $this->unavailableStatus();
			}
			$offeredVersion = $this->statusVersion( $status['offered_version'] );
			$relationship   = $this->statusRelationship( $status['relationship'] );
			$failureCode    = $this->statusCode( $status['failure_code'] );
			$lastCheck      = is_int( $status['last_check'] ) && 0 < $status['last_check'] ? $status['last_check'] : null;
			if ( ( null !== $status['offered_version'] && '' === $offeredVersion )
				|| ( null !== $status['relationship'] && '' === $relationship )
				|| ( null !== $status['failure_code'] && '' === $failureCode )
				|| ( null !== $status['last_check'] && null === $lastCheck ) ) {
				return $this->unavailableStatus();
			}

			return new RepositoryReleaseNativeTargetStatus(
				true,
				$offeredVersion,
				$relationship,
				$lastCheck,
				null,
				$failureCode
			);
		} catch ( Throwable ) {
			return $this->unavailableStatus();
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
	private function validUpdaterStatus( array $status ): bool {
		return ( null === $status['candidate_tag'] || '' !== $this->statusText( $status['candidate_tag'], 100 ) )
			&& ( null === $status['candidate_validation_code'] || '' !== $this->statusCode( $status['candidate_validation_code'] ) )
			&& ( null === $status['candidate_version'] || '' !== $this->statusVersion( $status['candidate_version'] ) )
			&& ( null === $status['candidate_header_version'] || '' !== $this->statusVersion( $status['candidate_header_version'] ) )
			&& ( null === $status['failure_code'] || '' !== $this->statusCode( $status['failure_code'] ) )
			&& ( null === $status['installed_version'] || '' !== $this->statusVersion( $status['installed_version'] ) )
			&& ( null === $status['last_check'] || ( is_int( $status['last_check'] ) && 0 < $status['last_check'] ) )
			&& ( null === $status['offered_version'] || '' !== $this->statusVersion( $status['offered_version'] ) )
			&& ( null === $status['offered_release_identity'] || '' !== $this->statusText( $status['offered_release_identity'], 191 ) )
			&& ( ( null === $status['offered_version'] ) === ( null === $status['offered_release_identity'] ) )
			&& ( null === $status['relationship'] || '' !== $this->statusRelationship( $status['relationship'] ) );
	}

	private function unavailableStatus(): RepositoryReleaseNativeTargetStatus {
		return new RepositoryReleaseNativeTargetStatus( false, failureCode: 'github_updater_status_unavailable' );
	}

	private function statusCode( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value ) ? $value : '';
	}

	private function statusRelationship( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'newer', 'same', 'older', 'invalid' ), true ) ? $value : '';
	}

	private function statusText( mixed $value, int $maximumLength ): string {
		return is_string( $value ) && strlen( $value ) <= $maximumLength && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	private function statusVersion( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value ) ? $value : '';
	}
}

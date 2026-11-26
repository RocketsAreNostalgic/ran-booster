<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

/** @internal Owns ordinary release status reads and exact typed release mutations. */
final class ReleaseTrackingOperations {
	/** @var array<string, ReleaseTrackingStatus|null> */
	private array $statuses = array();

	/** @var (\Closure(string,string,int,string,string,string,string,?array{identity:string,tag:string,version:string}):ReleaseTrackingResult)|null */
	private readonly ?\Closure $confirmed_enable;

	/** @param (callable(string,string,int,string,string,string,string,?array{identity:string,tag:string,version:string}):ReleaseTrackingResult)|null $confirmed_enable */
	public function __construct( private readonly ReleaseTrackingFacade $releases, ?callable $confirmed_enable = null ) {
		$this->confirmed_enable = null === $confirmed_enable ? null : \Closure::fromCallable( $confirmed_enable );
	}


	public function nonce_action( string $operation, string $type, string $identifier, int $revision, string $channel = '' ): string {
		return $this->releases->nonce_action( $operation, $type, $identifier, $revision, $channel );
	}

	public function status( string $type, string $identifier, int $revision ): ?ReleaseTrackingStatus {
		$key = $type . ':' . $identifier . ':' . $revision;
		if ( array_key_exists( $key, $this->statuses ) ) {
			return $this->statuses[ $key ];
		}
		$status                 = $this->releases->status( $type, $identifier );
		$this->statuses[ $key ] = $this->matches( $status, $type, $identifier, $revision ) ? $status : null;

		return $this->statuses[ $key ];
	}


	public function fresh_status( string $type, string $identifier ): ?ReleaseTrackingStatus {
		$status = $this->releases->status( $type, $identifier );

		return $this->matches_identity( $status, $type, $identifier ) ? $status : null;
	}

	/**
	 * @param list<string>       $identifiers
	 * @param array<string, int> $revisions
	 * @return array<string, ReleaseTrackingStatus|null>
	 */
	public function statuses( string $type, array $identifiers, array $revisions ): array {
		$read = $this->releases->statuses( $type, $identifiers );
		$out  = array();
		foreach ( $identifiers as $identifier ) {
			$revision           = is_int( $revisions[ $identifier ] ?? null ) ? $revisions[ $identifier ] : 0;
			$status             = $read[ $identifier ] ?? null;
			$out[ $identifier ] = $status instanceof ReleaseTrackingStatus && $this->matches( $status, $type, $identifier, $revision )
				? $status : null;
			$this->statuses[ $type . ':' . $identifier . ':' . $revision ] = $out[ $identifier ];
		}

		return $out;
	}

	/** The repository gate shared by package controls and the cached offer projection. */
	public static function repository_blocker( ?ReleaseTrackingStatus $status ): string {
		$failure_code = $status?->failure_code();
		if ( in_array( $failure_code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true ) ) {
			return 'release_repository_conflict';
		}

		return 'repository_source_unavailable' === $failure_code
			|| ( 'release_unavailable' === $failure_code && 'branch' === $status?->source() )
			? 'repository_source_unavailable'
			: '';
	}

	public function preflight( string $type, string $identifier, int $revision, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		return $this->releases->preflight( $type, $identifier, $revision, $channel, $nonce );
	}

	/**
	 * @param array{identity:string,tag:string,version:string}|null $confirmed_release
	 * @return array{type:string,identifier:string,code:string,successful:bool,reason?:string,check_reason?:string,detail?:string}
	 */
	public function execute( string $operation, string $type, string $identifier, int $revision, string $channel, string $nonce, string $confirmed_older_release_identity = '', string $confirmed_older_release_tag = '', ?array $confirmed_release = null ): array {
		$result = match ( $operation ) {
			'enable' => null === $this->confirmed_enable ? ReleaseTrackingResult::failed( 'service_unavailable', '' ) : ( $this->confirmed_enable )( $type, $identifier, $revision, $channel, $nonce, $confirmed_older_release_identity, $confirmed_older_release_tag, $confirmed_release ),
			'change_channel' => $this->releases->change_channel( $type, $identifier, $revision, $channel, $nonce ),
			'refresh' => $this->releases->refresh( $type, $identifier, $revision, $nonce ),
			'return_to_branch' => $this->releases->return_to_branch( $type, $identifier, $revision, $nonce ),
			default => throw new \UnhandledMatchError( 'Unsupported release tracking operation.' ),
		};
		$code       = $result->code();
		$successful = $result->successful();
		if ( 'refresh' === $operation && $successful ) {
			$status = $this->releases->status( $type, $identifier );
			if ( ! $this->matches_identity( $status, $type, $identifier ) ) {
				return $this->outcome( $type, $identifier, 'refresh_failed', false );
			}
			if ( '' !== $status->failure_code() ) {
				return $this->outcome( $type, $identifier, $status->failure_code(), false );
			}
			if ( '' === $status->last_checked_at() ) {
				// The refresh ran but recorded no check, so claiming completion would contradict the status.
				return $this->outcome( $type, $identifier, 'refresh_unchecked', false );
			}
			$code = $status->update_available() ? 'release_update_available' : 'release_current';
		}

		$outcome = $this->outcome( $type, $identifier, $code, $successful );

		$outcome += '' === $result->reason() ? array() : array( 'reason' => $result->reason() );
		$outcome += '' === $result->check_reason() ? array() : array( 'check_reason' => $result->check_reason() );

		return $outcome + ( '' === $result->detail() ? array() : array( 'detail' => $result->detail() ) );
	}

	/**
	 * Read back the source on landing. A verified switch normally commits before the reply; any
	 * remaining native hold or unresolved outcome is shown from the current package state.
	 *
	 * @param array<string, mixed>|null $native_hold Active GitHub native hold metadata, when present.
	 * @return array{0: string, 1: bool} Code and success for display.
	 */
	public static function settled(
		string $code,
		bool $successful,
		?ReleaseTrackingStatus $status,
		?array $native_hold = null,
		string $type = '',
		string $identifier = ''
	): array {
		if ( in_array( $code, array( 'release_enabled', 'switch_uncertain' ), true ) ) {
			if ( null !== $status && \RAN\PackageSource::RELEASE_ASSET->value === $status->source() ) {
				return array( 'release_enabled', true );
			}
			if ( null !== $native_hold ) {
				return self::hold_belongs_to_package( $native_hold, $status, $type, $identifier )
					? array( 'switch_held', false )
					: array( 'switch_other_held', false );
			}

			return array( 'switch_not_confirmed', false );
		}

		if ( in_array( $code, array( 'switch_failed', 'native_hold' ), true ) ) {
			if ( null !== $native_hold ) {
				return self::hold_belongs_to_package( $native_hold, $status, $type, $identifier )
					? array( 'switch_held', false )
					: array( 'switch_other_held', false );
			}

			return array( 'switch_failed_unchanged', false );
		}

		return array( $code, $successful );
	}

	/** @param array<string, mixed> $native_hold */
	private static function hold_belongs_to_package( array $native_hold, ?ReleaseTrackingStatus $status, string $type, string $identifier ): bool {
		$package_type       = $status?->type() ?? $type;
		$package_identifier = $status?->identifier() ?? $identifier;

		return '' !== $package_type
			&& '' !== $package_identifier
			&& ( $native_hold['type'] ?? null ) === $package_type
			&& ( $native_hold['identifier'] ?? null ) === $package_identifier;
	}

	private function matches( ReleaseTrackingStatus $status, string $type, string $identifier, int $revision ): bool {
		return $revision === $status->source_revision() && $this->matches_identity( $status, $type, $identifier );
	}

	private function matches_identity( ReleaseTrackingStatus $status, string $type, string $identifier ): bool {
		return hash_equals( $type, $status->type() ) && hash_equals( $identifier, $status->identifier() );
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool} */
	private function outcome( string $type, string $identifier, string $code, bool $successful ): array {
		return array(
			'type'       => $type,
			'identifier' => $identifier,
			'code'       => $code,
			'successful' => $successful,
		);
	}
}

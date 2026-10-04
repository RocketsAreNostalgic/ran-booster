<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

/** @internal Owns ordinary release status reads and exact typed release mutations. */
final class ReleaseTrackingOperations {
	/** @var array<string, ReleaseTrackingStatus|null> */
	private array $statuses = array();

	public function __construct( private readonly ReleaseTrackingFacade $releases ) {
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

	/** @return array{type:string,identifier:string,code:string,successful:bool} */
	public function execute( string $operation, string $type, string $identifier, int $revision, string $channel, string $nonce ): array {
		$result = match ( $operation ) {
			'enable' => $this->releases->enable( $type, $identifier, $revision, $channel, $nonce ),
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
			$code = $status->update_available() ? 'release_update_available' : 'release_current';
		}

		return $this->outcome( $type, $identifier, $code, $successful );
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

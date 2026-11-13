<?php

declare(strict_types=1);

namespace RAN\Admin;

/** Reads the newer release a past check observed, from its Activity facts; never an update offer. */
final class PastReleaseObservation {

	/**
	 * @param array<string, mixed> $record An Activity row whose check established a result.
	 * @return array{version: string, at: string}|null
	 */
	public static function from_record( array $record ): ?array {
		$encoded = $record['facts'] ?? null;
		$facts   = is_string( $encoded ) && strlen( $encoded ) <= 4096 ? json_decode( $encoded, true ) : null;
		$version = is_array( $facts ) ? ( $facts['candidate_version'] ?? null ) : null;
		$at      = $record['sort_at'] ?? null;
		if ( ! is_array( $facts ) || 'newer' !== ( $facts['relationship'] ?? null )
			|| ! is_string( $version ) || 1 !== preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/', $version )
			|| ! is_string( $at ) || '' === $at ) {
			return null;
		}

		return array(
			'version' => $version,
			'at'      => $at,
		);
	}
}

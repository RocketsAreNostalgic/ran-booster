<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\FailureText;

final readonly class RepositoryReleaseCandidateList {

	private const LISTING_REASONS = array( 'release_metadata_invalid', 'release_immutable_invalid', 'release_publication_invalid', 'release_page_invalid', 'release_draft', 'tag_not_version', 'prerelease_not_allowed' );

	public ?bool $search_exhausted;
	/** @var array{scanned_count:int,dropped_count:int,drops:list<array{reason:string,tag:?string}>,truncated:bool}|null */
	public ?array $diagnostics;

	/** @param list<RepositoryReleaseCandidate> $candidates */
	public function __construct( public array $candidates, mixed $search_exhausted = null, mixed $diagnostics = null ) {
		if ( ! array_is_list( $candidates ) || count( $candidates ) > 8 ) {
			throw new InvalidArgumentException( 'Repository release candidates must be a bounded list.' );
		}
		$release_ids = array();
		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof RepositoryReleaseCandidate ) {
				throw new InvalidArgumentException( 'Repository release candidates must be typed values.' );
			}
			if ( isset( $release_ids[ $candidate->provider_release_id ] ) ) {
				throw new InvalidArgumentException( 'Repository release candidate identities must be unique.' );
			}
			$release_ids[ $candidate->provider_release_id ] = true;
		}
		$this->search_exhausted = is_bool( $search_exhausted ) ? $search_exhausted : null;
		$this->diagnostics      = self::normalize_diagnostics( $diagnostics );
	}

	/**
	 * Accept the upstream wire report, or explicitly revalidate Core's identity-free
	 * display projection. Never infer the mode from missing wire fields.
	 * Wire samples are complete up to eight; projected samples may omit unknown
	 * reasons. Keep observed counts and mark the remaining sample incomplete.
	 *
	 * @return array{scanned_count:int,dropped_count:int,drops:list<array{reason:string,tag:?string}>,truncated:bool}|null
	 */
	public static function normalize_diagnostics( mixed $value, bool $projected = false ): ?array {
		if ( ! is_array( $value ) || 4 !== count( $value )
			|| ! is_int( $value['scanned_count'] ?? null ) || ! is_int( $value['dropped_count'] ?? null )
			|| ! is_array( $value['drops'] ?? null ) || ! is_bool( $value['truncated'] ?? null )
			|| $value['scanned_count'] < 0 || $value['scanned_count'] > 40
			|| $value['dropped_count'] < 0 || $value['dropped_count'] > $value['scanned_count']
			|| ! array_is_list( $value['drops'] ) || count( $value['drops'] ) > min( 8, $value['dropped_count'] )
			|| ( $value['dropped_count'] > count( $value['drops'] ) ) !== $value['truncated'] ) {
			return null;
		}
		if ( ! $projected && count( $value['drops'] ) !== min( 8, $value['dropped_count'] ) ) {
			return null;
		}
		$drops       = array();
		$field_count = $projected ? 2 : 3;
		foreach ( $value['drops'] as $drop ) {
			if ( ! is_array( $drop ) || count( $drop ) !== $field_count
				|| ! array_key_exists( 'tag', $drop ) || ! is_string( $drop['reason'] ?? null )
				|| array_key_exists( 'release_identity', $drop ) === $projected ) {
				return null;
			}
			if ( ! $projected && null !== $drop['release_identity']
				&& ( ! is_string( $drop['release_identity'] ) || 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $drop['release_identity'] ) ) ) {
				return null;
			}
			if ( ! in_array( $drop['reason'], self::LISTING_REASONS, true ) ) {
				continue;
			}
			$tag     = $drop['tag'];
			$drops[] = array(
				'reason' => $drop['reason'],
				'tag'    => is_string( $tag ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,119}\z/D', $tag )
					&& FailureText::strict( $tag, 120 ) === $tag ? $tag : null,
			);
		}

		return array(
			'scanned_count' => $value['scanned_count'],
			'dropped_count' => $value['dropped_count'],
			'drops'         => $drops,
			'truncated'     => $value['dropped_count'] > count( $drops ),
		);
	}
}

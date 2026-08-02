<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use JsonException;

/**
 * Stateless adoption-only boundary published to trusted source bridges.
 */
abstract class PortabilityFacade {

	public const API_VERSION = 2;

	/**
	 * Derive a bounded action for a review or Apply WordPress nonce.
	 *
	 * The expected fingerprint is required only for Apply. This method neither
	 * creates nor authorizes a WordPress nonce. Implementations bind review to
	 * the SHA-256 digest of the complete canonical candidate and bind Apply to
	 * that digest plus the exact `v1:` review fingerprint. Action strings never
	 * contain raw candidate values.
	 */
	final public function nonceAction(
		string $operation,
		PortabilityCandidate $candidate,
		?string $expectedFingerprint = null
	): string {
		if ( 'review' === $operation && null === $expectedFingerprint ) {
			return 'ran-booster-portability-review-v1-' . $this->candidateDigest( $candidate );
		}
		if ( 'apply' === $operation
			&& is_string( $expectedFingerprint )
			&& 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $expectedFingerprint ) ) {
			return 'ran-booster-portability-apply-v1-' . $this->candidateDigest( $candidate ) . '-' . substr( $expectedFingerprint, 3 );
		}

		throw new InvalidArgumentException( 'The Portability nonce scope is invalid.' );
	}

	abstract public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult;

	abstract public function apply(
		PortabilityCandidate $candidate,
		string $expectedFingerprint,
		string $nonce
	): PortabilityApplyResult;

	private function candidateDigest( PortabilityCandidate $candidate ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
			$json = json_encode(
				array(
					'domain'    => 'ran-booster-portability-candidate',
					'version'   => self::API_VERSION,
					'candidate' => $candidate->toArray(),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The Portability candidate could not be scoped safely.' );
		}

		return hash( 'sha256', $json );
	}
}

<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use JsonException;

/**
 * Stateless adoption-only boundary published to trusted source bridges.
 */
abstract class PortabilityFacade {

	public const API_VERSION = 3;

	/**
	 * Derive a bounded action for a review or Apply WordPress nonce.
	 *
	 * The expected fingerprint is required only for Apply. This method neither
	 * creates nor authorizes a WordPress nonce. Implementations bind review to
	 * the SHA-256 digest of the complete canonical candidate and bind Apply to
	 * that digest plus the exact `v1:` review fingerprint. Action strings never
	 * contain raw candidate values.
	 */
	final public function nonce_action(
		string $operation,
		PortabilityCandidate $candidate,
		?string $expected_fingerprint = null
	): string {
		if ( 'review' === $operation && null === $expected_fingerprint ) {
			return 'ran-booster-portability-review-v1-' . $this->candidate_digest( $candidate );
		}
		if ( 'apply' === $operation
			&& is_string( $expected_fingerprint )
			&& 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $expected_fingerprint ) ) {
			return 'ran-booster-portability-apply-v1-' . $this->candidate_digest( $candidate ) . '-' . substr( $expected_fingerprint, 3 );
		}

		throw new InvalidArgumentException( 'The Portability nonce scope is invalid.' );
	}

	abstract public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult;

	abstract public function apply(
		PortabilityCandidate $candidate,
		string $expected_fingerprint,
		string $nonce
	): PortabilityApplyResult;

	private function candidate_digest( PortabilityCandidate $candidate ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
			$json = json_encode(
				array(
					'domain'    => 'ran-booster-portability-candidate',
					// PHP API naming does not change the canonical nonce payload version.
					'version'   => 2,
					'candidate' => $candidate->to_array(),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The Portability candidate could not be scoped safely.' );
		}

		return hash( 'sha256', $json );
	}
}

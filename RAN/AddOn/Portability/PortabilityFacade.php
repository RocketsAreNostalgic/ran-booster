<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use JsonException;
use RAN\RepositoryProvider\GitHubProviderSelection;

/**
 * Stateless adoption-only boundary published to trusted source bridges.
 */
abstract class PortabilityFacade {

	public const API_VERSION = 4;

	/**
	 * Derive a bounded action for a review or Apply WordPress nonce.
	 *
	 * The expected fingerprint is required only for Apply. This method neither
	 * creates nor authorizes a WordPress nonce. Implementations bind review to
	 * the SHA-256 digest of the complete canonical candidate and bind Apply to
	 * that digest plus the exact `v1:` review fingerprint. Action strings never
	 * contain raw candidate values.
	 * @param array<string,mixed>|null $provider_expectation
	 */
	final public function nonce_action(
		string $operation,
		PortabilityCandidate $candidate,
		?string $expected_fingerprint,
		?array $provider_expectation
	): string {
		if ( 'review' === $operation && null === $expected_fingerprint && null === $provider_expectation ) {
			return 'ran-booster-portability-review-v1-' . $this->candidate_digest( $candidate );
		}
		if ( 'apply' === $operation
			&& is_string( $expected_fingerprint )
			&& 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $expected_fingerprint ) ) {
			if ( 'gh' === $candidate->provider_code && null === $provider_expectation ) {
				throw new InvalidArgumentException( 'Fresh provider review is required.' );
			}
			return 'ran-booster-portability-apply-v1-' . $this->candidate_digest( $candidate, $provider_expectation ) . '-' . substr( $expected_fingerprint, 3 );
		}

		throw new InvalidArgumentException( 'The Portability nonce scope is invalid.' );
	}

	abstract public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult;

	/** @param array<string,mixed>|null $provider_expectation */
	abstract public function apply(
		PortabilityCandidate $candidate,
		string $expected_fingerprint,
		string $nonce,
		?array $provider_expectation
	): PortabilityApplyResult;

	/** @param array<string,mixed>|null $provider_expectation */
	private function candidate_digest( PortabilityCandidate $candidate, ?array $provider_expectation = null ): string {
		if ( null !== $provider_expectation ) {
			if ( 'gh' !== $candidate->provider_code ) {
				throw new InvalidArgumentException( 'This provider does not accept selection context.' );
			}
			$provider_expectation = GitHubProviderSelection::normalize_expectation( $provider_expectation );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
			$json = json_encode(
				array(
					'domain'               => 'ran-booster-portability-candidate',
					// API 4 binds destination-local selection context.
					'version'              => 3,
					'candidate'            => $candidate->to_array(),
					'provider_expectation' => $provider_expectation,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The Portability candidate could not be scoped safely.' );
		}

		return hash( 'sha256', $json );
	}
}

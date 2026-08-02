<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use JsonException;
use RAN\Portability\TargetPackageReason;

/** Immutable, display-safe result of one fresh Portability target review. */
final readonly class PortabilityReviewResult {

	public const ADOPT     = 'adopt';
	public const MANAGED   = 'managed';
	public const PROTECTED = 'protected';
	public const BLOCKED   = 'blocked';

	public function __construct(
		public PortabilityCandidate $candidate,
		public string $action,
		public string $reason,
		public string $message,
		public string $fingerprint
	) {
		$reasons = array_merge(
			array_map( static fn ( TargetPackageReason $reason ): string => $reason->value, TargetPackageReason::cases() ),
			array( 'forbidden', 'unexpected_failure', 'unsupported_runtime' )
		);
		if ( ! in_array(
			$action,
			array(
				self::ADOPT,
				self::MANAGED,
				self::PROTECTED,
				self::BLOCKED,
			),
			true
		)
			|| ! in_array( $reason, $reasons, true )
			|| ! self::safeMessage( $message )
			|| 1 !== preg_match( '/\Av1:[a-f0-9]{64}\z/D', $fingerprint ) ) {
			throw new InvalidArgumentException( 'The Portability review result is invalid.' );
		}
	}

	public static function fromResolved(
		PortabilityCandidate $candidate,
		string $action,
		string $reason,
		string $message,
		?string $providerRepositoryId,
		?bool $repositoryPrivate
	): self {
		if ( null !== $providerRepositoryId
			&& ( '' === $providerRepositoryId
				|| strlen( $providerRepositoryId ) > 191
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $providerRepositoryId ) ) ) {
			throw new InvalidArgumentException( 'The resolved Portability repository identity is invalid.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract with exceptions enabled.
			$json = json_encode(
				array(
					'domain'                 => 'ran-booster-portability-review',
					'version'                => PortabilityFacade::API_VERSION,
					'candidate'              => $candidate->toArray(),
					'provider_repository_id' => $providerRepositoryId,
					'private'                => $repositoryPrivate,
					'action'                 => $action,
					'reason'                 => $reason,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The Portability review could not be fingerprinted safely.' );
		}

		return new self( $candidate, $action, $reason, $message, 'v1:' . hash( 'sha256', $json ) );
	}

	private static function safeMessage( string $message ): bool {
		return '' !== trim( $message )
			&& strlen( $message ) <= 255
			&& 1 === preg_match( '//u', $message )
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $message );
	}
}

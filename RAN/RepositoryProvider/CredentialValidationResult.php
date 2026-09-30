<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use LogicException;

final readonly class CredentialValidationResult {
	public const VALID            = 'valid';
	public const INVALID          = 'invalid';
	public const UNAVAILABLE      = 'unavailable';
	public const RATE_LIMITED     = 'rate_limited';
	public const INVALID_RESPONSE = 'invalid_response';

	private const MAX_DISPLAY_MESSAGE_BYTES = 160;

	private const FAILURE_MESSAGES = array(
		self::INVALID          => 'The repository provider rejected this credential.',
		self::UNAVAILABLE      => 'The repository provider could not validate this credential. Try again later.',
		self::RATE_LIMITED     => 'The repository provider rate-limited credential validation. Try again later.',
		self::INVALID_RESPONSE => 'The repository provider returned an invalid credential-validation response.',
	);

	private function __construct(
		public string $reason,
		public ?CredentialExpiryReport $expiry = null
	) {
		if ( self::VALID !== $reason ) {
			if ( null !== $expiry ) {
				throw new LogicException( 'Failed credential validation cannot report expiry metadata.' );
			}
			self::bounded_failure_message( $reason );
		}
	}

	public static function valid( ?CredentialExpiryReport $expiry = null ): self {
		return new self( self::VALID, $expiry );
	}

	public static function invalid(): self {
		return self::failure( self::INVALID );
	}

	public static function unavailable(): self {
		return self::failure( self::UNAVAILABLE );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public static function rateLimited(): self {
		return self::failure( self::RATE_LIMITED );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public static function invalidResponse(): self {
		return self::failure( self::INVALID_RESPONSE );
	}

	private static function failure( string $reason ): self {
		return new self( $reason );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isValid(): bool {
		return self::VALID === $this->reason;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function getDisplayMessage(): ?string {
		return self::VALID === $this->reason
			? null
			: self::bounded_failure_message( $this->reason );
	}

	private static function bounded_failure_message( string $reason ): string {
		$message = self::FAILURE_MESSAGES[ $reason ] ?? null;

		if ( ! is_string( $message )
			|| '' === $message
			|| self::MAX_DISPLAY_MESSAGE_BYTES < strlen( $message )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $message )
		) {
			throw new LogicException( 'Credential validation result has no bounded core message.' );
		}

		return $message;
	}
}

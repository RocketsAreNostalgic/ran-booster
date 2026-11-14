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
		public ?CredentialExpiryReport $expiry = null,
		public ?ProviderFailure $failure = null
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

	public static function invalid( ?ProviderFailure $failure = null ): self {
		return self::failure( self::INVALID, $failure );
	}

	public static function unavailable( ?ProviderFailure $failure = null ): self {
		return self::failure( self::UNAVAILABLE, $failure );
	}


	public static function rate_limited( ?ProviderFailure $failure = null ): self {
		return self::failure( self::RATE_LIMITED, $failure );
	}


	public static function invalid_response( ?ProviderFailure $failure = null ): self {
		return self::failure( self::INVALID_RESPONSE, $failure );
	}

	private static function failure( string $reason, ?ProviderFailure $failure ): self {
		return new self( $reason, null, ProviderFailure::within( $failure, ProviderFailure::CREDENTIAL_CHECK_REASONS ) );
	}


	public function is_valid(): bool {
		return self::VALID === $this->reason;
	}


	public function get_display_message(): ?string {
		if ( self::VALID === $this->reason ) {
			return null;
		}
		$reasoned = $this->failure?->message();

		return null !== $reasoned && strlen( $reasoned ) <= self::MAX_DISPLAY_MESSAGE_BYTES
			? $reasoned
			: self::bounded_failure_message( $this->reason );
	}

	private static function bounded_failure_message( string $reason ): string {
		$message = self::FAILURE_MESSAGES[ $reason ] ?? null;

		if ( ! is_string( $message )
			|| self::MAX_DISPLAY_MESSAGE_BYTES < strlen( $message )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $message )
		) {
			throw new LogicException( 'Credential validation result has no bounded core message.' );
		}

		return $message;
	}
}

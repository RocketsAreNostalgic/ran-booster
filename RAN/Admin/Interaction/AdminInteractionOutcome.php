<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use InvalidArgumentException;

/**
 * Display-safe result returned to Core after an add-on has authorized and run
 * its own operation.
 */
final readonly class AdminInteractionOutcome {

	public const SUCCESS = 'success';

	public const ACCEPTED = 'accepted';

	public const VALIDATION_FAILURE = 'validation_failure';

	public const UNEXPECTED_FAILURE = 'unexpected_failure';

	private const GENERIC_FAILURE_MESSAGE = 'We could not complete that request. Please try again.';

	private function __construct(
		private AdminInteractionRequest $request,
		private string $kind,
		private string $message
	) {
		if ( ! in_array( $kind, array( self::SUCCESS, self::ACCEPTED, self::VALIDATION_FAILURE, self::UNEXPECTED_FAILURE ), true ) ) {
			throw new InvalidArgumentException( 'Administration interaction outcome type is invalid.' );
		}
		if ( '' === trim( $message )
			|| strlen( $message ) > 255
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $message ) ) {
			throw new InvalidArgumentException( 'Administration interaction messages must be bounded display-safe text.' );
		}
	}

	public static function success( AdminInteractionRequest $request, string $message ): self {
		return new self( $request, self::SUCCESS, $message );
	}

	public static function accepted( AdminInteractionRequest $request, string $message ): self {
		return new self( $request, self::ACCEPTED, $message );
	}

	public static function validationFailure( AdminInteractionRequest $request, string $message ): self {
		return new self( $request, self::VALIDATION_FAILURE, $message );
	}

	public static function unexpectedFailure( AdminInteractionRequest $request ): self {
		return new self( $request, self::UNEXPECTED_FAILURE, self::GENERIC_FAILURE_MESSAGE );
	}

	public function request(): AdminInteractionRequest {
		return $this->request;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function message(): string {
		return $this->message;
	}

	public function status(): int {
		return match ( $this->kind ) {
			self::SUCCESS            => 200,
			self::ACCEPTED           => 202,
			self::VALIDATION_FAILURE => 422,
			self::UNEXPECTED_FAILURE => 500,
		};
	}

	public function hasSuccessFeedback(): bool {
		return in_array( $this->kind, array( self::SUCCESS, self::ACCEPTED ), true );
	}
}

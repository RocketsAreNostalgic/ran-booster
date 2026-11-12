<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Stable mutation result safe for an administrator notice.
 */
final readonly class ReleaseTrackingResult {

	public function __construct(
		private bool $successful,
		private string $code,
		private string $message,
		private string $reason = '',
		private string $detail = ''
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $this->code )
			|| ( '' !== $this->reason && ! in_array( $this->reason, ReleaseTrackingPreflight::reason_codes(), true ) )
			|| ReleaseTrackingPreflight::detail( $this->detail ) !== $this->detail
			|| '' === trim( $this->message )
			|| strlen( $this->message ) > 255
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->message ) ) {
			throw new InvalidArgumentException( 'Release tracking results require bounded safe values.' );
		}
	}

	public static function succeeded( string $code, string $message ): self {
		return new self( true, $code, $message );
	}

	public static function failed( string $code, string $message, string $reason = '', string $detail = '' ): self {
		return new self( false, $code, $message, $reason, $detail );
	}

	public function successful(): bool {
		return $this->successful;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function reason(): string {
		return $this->reason;
	}

	public function detail(): string {
		return $this->detail;
	}
}

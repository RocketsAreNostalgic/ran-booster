<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\FailureText;
use Throwable;

final readonly class ProviderDiagnosticResult {

	public const PASSED         = 'pass';
	public const WARNING        = 'warning';
	public const FAILED         = 'fail';
	public const NOT_CONFIGURED = 'not_configured';

	private const STATUSES = array(
		self::PASSED,
		self::WARNING,
		self::FAILED,
		self::NOT_CONFIGURED,
	);

	public function __construct(
		public string $status,
		public string $code,
		public string $message,
		public string $remediation,
		public ?Throwable $failure = null
	) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Provider diagnostic status is invalid.' );
		}

		if ( 1 !== preg_match( '/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $code ) || strlen( $code ) > 96 ) {
			throw new InvalidArgumentException( 'Provider diagnostic code is invalid.' );
		}

		$this->assert_safe_text( $message );
		$this->assert_safe_text( $remediation );
	}

	/**
	 * @return array{status: string, code: string, message: string, remediation: string}
	 */

	public function to_array(): array {
		return array(
			'status'      => $this->status,
			'code'        => $this->code,
			'message'     => $this->message,
			'remediation' => $this->remediation,
		);
	}

	private function assert_safe_text( string $value ): void {
		// Provider-authored fixed sentence: whole-text shape screen, never a prose or remote-text channel.
		if ( FailureText::display( $value, 512 ) !== $value ) {
			throw new InvalidArgumentException( 'Provider diagnostic text must be safe, plain, single-line text.' );
		}
	}
}

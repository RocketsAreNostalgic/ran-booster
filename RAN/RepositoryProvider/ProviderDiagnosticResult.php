<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

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
		public string $remediation
	) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Provider diagnostic status is invalid.' );
		}

		if ( 1 !== preg_match( '/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $code ) || strlen( $code ) > 96 ) {
			throw new InvalidArgumentException( 'Provider diagnostic code is invalid.' );
		}

		$this->assertSafeText( $message );
		$this->assertSafeText( $remediation );
	}

	/**
	 * @return array{status: string, code: string, message: string, remediation: string}
	 */
	public function toArray(): array {
		return array(
			'status'      => $this->status,
			'code'        => $this->code,
			'message'     => $this->message,
			'remediation' => $this->remediation,
		);
	}

	private function assertSafeText( string $value ): void {
		if ( '' === trim( $value )
			|| strlen( $value ) > 512
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
			|| 1 === preg_match( '/<\/?[A-Za-z][^>]*>/', $value )
			|| 1 === preg_match( '/\b(?:authorization|proxy-authorization|cookie|set-cookie|x-hub-signature(?:-256)?|x-api-key|x-auth-token|private-token)\s*:/i', $value )
			|| 1 === preg_match( '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value )
			|| 1 === preg_match( '/\b(?:gh[pousr]_|github_pat_|ATATT3)[A-Za-z0-9_-]{6,}/', $value )
			|| 1 === preg_match( '/\bglpat-[A-Za-z0-9_-]{6,}/', $value )
			|| 1 === preg_match( '#(?:^|[\s(])(?:[A-Za-z]:[\\\\/]|/(?!/)[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*)#', $value )
			|| 1 === preg_match( '#(?:^|[\s(])\\\\\\\\[^\\\\\s]+\\\\[^\\\\\s]+#', $value )
			|| 1 === preg_match( '/[{}\[\]]/', $value )
			|| 1 === preg_match( '#https?://[^/\s]+@#i', $value )
		) {
			throw new InvalidArgumentException( 'Provider diagnostic text must be safe, plain, single-line text.' );
		}
	}
}

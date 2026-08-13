<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RuntimeException;

/** A bounded provider-input failure safe for an administrator boundary. */
final class InvalidCredentialInput extends RuntimeException {

	public const INVALID_CONFIGURATION    = 'invalid_configuration';
	public const CREDENTIAL_KIND_MISMATCH = 'credential_kind_mismatch';
	public const INVALID_SECRET_SHAPE     = 'invalid_secret_shape';

	private const REASONS = array(
		self::INVALID_CONFIGURATION    => true,
		self::CREDENTIAL_KIND_MISMATCH => true,
		self::INVALID_SECRET_SHAPE     => true,
	);

	public function __construct( public readonly string $reason, string $message ) {
		if ( ! isset( self::REASONS[ $reason ] ) ) {
			throw new InvalidArgumentException( 'Credential input failure reason is invalid.' );
		}
		$this->assertSafeText( $message );

		parent::__construct( $message );
	}

	private function assertSafeText( string $value ): void {
		if ( '' === trim( $value )
			|| strlen( $value ) > 512
			|| 1 !== preg_match( '//u', $value )
			|| 1 === preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value )
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
			throw new InvalidArgumentException( 'Credential input failure text must be safe, plain, single-line text.' );
		}
	}
}

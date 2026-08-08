<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RuntimeException;

/**
 * A closed provider-input failure whose fixed message is safe for administrators.
 */
final class InvalidCredentialInput extends RuntimeException {

	public const INVALID_RESOURCE_OWNER = 'invalid_resource_owner';
	public const LOOKS_CLASSIC          = 'looks_classic';

	private const MESSAGES = array(
		self::INVALID_RESOURCE_OWNER => 'Enter the GitHub user or organisation selected as the token\'s resource owner, not an email address.',
		self::LOOKS_CLASSIC          => 'This token begins with ghp_, which identifies a classic personal access token. Choose Classic personal access token or paste a fine-grained token.',
	);

	public function __construct( public readonly string $reason ) {
		if ( ! isset( self::MESSAGES[ $reason ] ) ) {
			throw new InvalidArgumentException( 'Credential input failure reason is invalid.' );
		}

		parent::__construct( self::MESSAGES[ $reason ] );
	}
}

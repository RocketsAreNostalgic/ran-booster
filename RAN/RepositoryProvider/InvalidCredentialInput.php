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
	public const REQUIRES_CLASSIC       = 'requires_classic';
	public const REQUIRES_FINE_GRAINED  = 'requires_fine_grained';
	public const INVALID_TOKEN_SHAPE    = 'invalid_token_shape';

	private const MESSAGES = array(
		self::INVALID_RESOURCE_OWNER => 'Enter the GitHub user or organisation selected as the token\'s resource owner, not an email address.',
		self::LOOKS_CLASSIC          => 'This token begins with ghp_, which identifies a classic personal access token. Choose Classic personal access token or paste a fine-grained token.',
		self::REQUIRES_CLASSIC       => 'Classic personal access tokens must begin with ghp_. Choose Fine-grained personal access token if the token begins with github_pat_.',
		self::REQUIRES_FINE_GRAINED  => 'Fine-grained personal access tokens must begin with github_pat_. Choose Classic personal access token if the token begins with ghp_.',
		self::INVALID_TOKEN_SHAPE    => 'Enter a GitHub personal access token containing 40 to 255 letters, numbers, or underscores.',
	);

	public function __construct( public readonly string $reason ) {
		if ( ! isset( self::MESSAGES[ $reason ] ) ) {
			throw new InvalidArgumentException( 'Credential input failure reason is invalid.' );
		}

		parent::__construct( self::MESSAGES[ $reason ] );
	}
}

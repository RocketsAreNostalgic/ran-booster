<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\FailureText;
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

	/** Core's own copy for each reason, used when the submitted message is not screen-safe. */
	private const GENERIC_MESSAGES = array(
		self::INVALID_CONFIGURATION    => 'The credential settings were not accepted. Check them and try again.',
		self::CREDENTIAL_KIND_MISMATCH => 'The credential type does not match the submitted secret. Check the selected type and try again.',
		self::INVALID_SECRET_SHAPE     => 'The secret does not have the expected format. Check it and try again.',
	);

	public function __construct( public readonly string $reason, string $message ) {
		if ( ! isset( self::REASONS[ $reason ] ) ) {
			throw new InvalidArgumentException( 'Credential input failure reason is invalid.' );
		}

		// A provider sentence the screen drops is replaced by Core's copy for the reason, so the failure stays typed and actionable.
		parent::__construct( FailureText::display( $message, 512 ) === $message ? $message : self::GENERIC_MESSAGES[ $reason ] );
	}
}

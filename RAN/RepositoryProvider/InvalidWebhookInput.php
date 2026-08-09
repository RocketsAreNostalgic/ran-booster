<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RuntimeException;

/**
 * A closed webhook-input failure whose fixed message is safe for administrators.
 */
final class InvalidWebhookInput extends RuntimeException {

	public const INVALID_TARGET   = 'invalid_target';
	public const INVALID_SECRET   = 'invalid_secret';
	public const DUPLICATE_TARGET = 'duplicate_target';
	public const CAPACITY         = 'capacity';

	private const MESSAGES = array(
		self::INVALID_TARGET   => 'Choose a valid managed owner or repository for this Push-to-Deploy secret.',
		self::INVALID_SECRET   => 'Enter a Push-to-Deploy secret containing 32 to 512 bytes without control characters.',
		self::DUPLICATE_TARGET => 'A Push-to-Deploy secret already exists for this owner or repository. Edit the existing secret instead.',
		self::CAPACITY         => 'This provider already has the maximum of 16 Push-to-Deploy secrets. Remove one before adding another.',
	);

	public function __construct( public readonly string $reason ) {
		if ( ! isset( self::MESSAGES[ $reason ] ) ) {
			throw new InvalidArgumentException( 'Webhook input failure reason is invalid.' );
		}

		parent::__construct( self::MESSAGES[ $reason ] );
	}
}

<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/**
 * Bounded expiry metadata returned by an optional credential validation check.
 *
 * A null expiry means that the provider was checked but did not return a
 * trustworthy expiry date. Providers that do not report expiry information
 * leave CredentialValidationResult::$expiry null instead.
 */
final readonly class CredentialExpiryReport {

	private const UTC_PATTERN = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/D';

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
	private function __construct( public ?string $expiresAt ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		if ( null !== $expiresAt ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			self::require_utc_timestamp( $expiresAt );
		}
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
	public static function known( string $expiresAt ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		return new self( $expiresAt );
	}

	public static function unknown(): self {
		return new self( null );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function isKnown(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		return null !== $this->expiresAt;
	}

	private static function require_utc_timestamp( string $value ): void {
		if ( 1 !== preg_match( self::UTC_PATTERN, $value, $matches )
			|| ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			|| (int) $matches[4] > 23
			|| (int) $matches[5] > 59
			|| (int) $matches[6] > 59
		) {
			throw new InvalidArgumentException( 'Credential expiry must be a UTC timestamp.' );
		}
	}
}

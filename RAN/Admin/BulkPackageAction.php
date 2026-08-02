<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;

/** One validated administrator bulk command for a single package type. */
final readonly class BulkPackageAction {

	public const MAX_IDENTIFIERS            = 200;
	public const MAX_DEPLOYMENT_IDENTIFIERS = 20;
	public const QUEUE_UPDATE               = 'queue-update';
	public const ACTIVATE_PLUGINS           = 'activate-plugins';
	public const DEACTIVATE_PLUGINS         = 'deactivate-plugins';
	public const POLICY_DISABLED            = 'policy-disabled';
	public const POLICY_MANUAL              = 'policy-manual';
	public const POLICY_AUTOMATIC           = 'policy-automatic';

	/**
	 * @param list<string> $identifiers
	 */
	private function __construct(
		public string $packageType,
		public string $operation,
		public array $identifiers
	) {
	}

	/** @param array<string, mixed> $input */
	public static function fromInput( string $packageType, array $input ): self {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'The bulk package type is invalid.' );
		}

		$operation = $input['bulk_action'] ?? null;
		if ( ! is_string( $operation )
			|| ! in_array( $operation, self::operations(), true )
			|| ( 'theme' === $packageType && in_array( $operation, self::pluginActivationOperations(), true ) ) ) {
			throw new InvalidArgumentException( 'Choose a valid bulk action.' );
		}

		$rawIdentifiers = $input['identifiers'] ?? null;
		$maximum        = in_array( $operation, self::pluginActivationOperations(), true )
			? self::MAX_IDENTIFIERS
			: self::MAX_DEPLOYMENT_IDENTIFIERS;
		if ( ! is_array( $rawIdentifiers )
			|| array() === $rawIdentifiers
			|| count( $rawIdentifiers ) > $maximum ) {
			throw new InvalidArgumentException( 'Select a supported number of managed packages.' );
		}

		$identifiers = array();
		foreach ( $rawIdentifiers as $rawIdentifier ) {
			if ( ! is_string( $rawIdentifier ) ) {
				throw new InvalidArgumentException( 'The bulk package selection is invalid.' );
			}
			$identifier = trim( $rawIdentifier );
			$segments   = explode( '/', $identifier );
			if ( '' === $identifier
				|| $identifier !== $rawIdentifier
				|| strlen( $identifier ) > 255
				|| str_contains( $identifier, '\\' )
				|| str_starts_with( $identifier, '/' )
				|| preg_match( '/[[:cntrl:]]/u', $identifier ) === 1
				|| in_array( '', $segments, true )
				|| in_array( '.', $segments, true )
				|| in_array( '..', $segments, true )
				|| ( 'theme' === $packageType && str_contains( $identifier, '/' ) )
				|| ( 'plugin' === $packageType && ! str_ends_with( $identifier, '.php' ) ) ) {
				throw new InvalidArgumentException( 'The bulk package selection is invalid.' );
			}
			if ( isset( $identifiers[ $identifier ] ) ) {
				throw new InvalidArgumentException( 'The bulk package selection contains duplicates.' );
			}
			$identifiers[ $identifier ] = true;
		}
		ksort( $identifiers, SORT_STRING );

		return new self( $packageType, $operation, array_keys( $identifiers ) );
	}

	public function deploymentPolicy(): ?DeploymentPolicy {
		return match ( $this->operation ) {
			self::POLICY_DISABLED => DeploymentPolicy::DISABLED,
			self::POLICY_MANUAL => DeploymentPolicy::MANUAL,
			self::POLICY_AUTOMATIC => DeploymentPolicy::AUTOMATIC,
			default => null,
		};
	}

	public function isUpdateQueue(): bool {
		return self::QUEUE_UPDATE === $this->operation;
	}

	public function isPluginActivation(): bool {
		return in_array( $this->operation, self::pluginActivationOperations(), true );
	}

	/** @return list<string> */
	public static function operations(): array {
		return array(
			self::QUEUE_UPDATE,
			self::ACTIVATE_PLUGINS,
			self::DEACTIVATE_PLUGINS,
			self::POLICY_DISABLED,
			self::POLICY_MANUAL,
			self::POLICY_AUTOMATIC,
		);
	}

	/** @return list<string> */
	public static function pluginActivationOperations(): array {
		return array(
			self::ACTIVATE_PLUGINS,
			self::DEACTIVATE_PLUGINS,
		);
	}
}

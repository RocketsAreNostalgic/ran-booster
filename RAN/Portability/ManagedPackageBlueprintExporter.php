<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use InvalidArgumentException;
use RAN\Package;
use RAN\PackageSource;
use RAN\Secrets\SecretsFile;

/**
 * Builds a deterministic package blueprint from Booster's non-cleaning readers.
 *
 * This application service is deliberately read-only. It does not validate a
 * remote provider, clean stale rows or write an artifact to disk. When
 * explicitly requested, it carries only file-backed credentials directly used
 * by an exported package.
 */
final readonly class ManagedPackageBlueprintExporter {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private SecretsFile $secrets
	) {
	}

	/**
	 * @param array<string, list<string>>                     $credentialSelection
	 * @param list<array{type:string,identifier:string}>|null $packageSelection
	 */
	public function export( array $credentialSelection = array(), ?array $packageSelection = null ): PackageBlueprint {
		$packages    = array();
		$managed     = array();
		$unsupported = array();
		$selected    = $this->selection( $packageSelection );
		$credentials = $this->credentialSelection( $credentialSelection );
		foreach ( array(
			'plugin' => $this->plugins->allDeploymentPlugins(),
			'theme'  => $this->themes->allDeploymentThemes(),
		) as $type => $group ) {
			foreach ( $group as $package ) {
				if ( ! $package instanceof Package ) {
					throw new InvalidArgumentException( 'The managed package inventory is invalid.' );
				}
				$key = $type . "\0" . $package->getIdentifier();
				if ( null !== $selected && ! isset( $selected[ $key ] ) ) {
					continue;
				}
				unset( $selected[ $key ] );
				if ( PackageSource::BRANCH !== $package->getSource() ) {
					$unsupported[] = new BlueprintExportPackageFailure(
						$type,
						$package->getDisplayName(),
						BlueprintExportPackageFailure::PUBLISHED_RELEASES
					);
					continue;
				}
				$blueprint  = BlueprintPackage::fromManagedPackage( $type, $package );
				$packages[] = $blueprint;
				$managed[]  = array(
					'package'   => $package,
					'blueprint' => $blueprint,
				);
			}
		}
		if ( null !== $selected && array() !== $selected ) {
			throw new InvalidArgumentException( 'The managed package selection is invalid.' );
		}
		if ( array() !== $unsupported ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The typed exception carries only display-safe package failure context to the administrator boundary.
			throw new UnsupportedBlueprintPackages( $unsupported );
		}

		return new PackageBlueprint( $packages, array() === $credentials ? array() : $this->credentials( $managed, $credentials ) );
	}

	/** @param array<string, list<string>> $selection @return array<string, true> */
	private function credentialSelection( array $selection ): array {
		$selected = array();
		foreach ( $selection as $provider => $ids ) {
			if ( ! is_string( $provider ) || 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,31}\z/', $provider )
				|| ! is_array( $ids ) || ! array_is_list( $ids ) || array() === $ids ) {
				throw new InvalidArgumentException( 'The managed package credential selection is invalid.' );
			}
			foreach ( $ids as $id ) {
				$key = is_string( $id ) ? $provider . "\0" . $id : '';
				if ( ! is_string( $id ) || SecretsFile::CONSTANT_PROFILE === $id
					|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', $id ) || isset( $selected[ $key ] )
					|| count( $selected ) >= PackageBlueprint::MAX_CREDENTIALS ) {
					throw new InvalidArgumentException( 'The managed package credential selection is invalid.' );
				}
				$selected[ $key ] = true;
			}
		}

		return $selected;
	}

	/**
	 * @param list<array{type:string,identifier:string}>|null $selection
	 * @return array<string, true>|null
	 */
	private function selection( ?array $selection ): ?array {
		if ( null === $selection ) {
			return null;
		}
		if ( array() === $selection || count( $selection ) > PackageBlueprint::MAX_PACKAGES ) {
			throw new InvalidArgumentException( 'The managed package selection is invalid.' );
		}

		$selected = array();
		foreach ( $selection as $identity ) {
			if ( ! is_array( $identity ) || array_keys( $identity ) !== array( 'type', 'identifier' )
				|| ! is_string( $identity['type'] ) || ! in_array( $identity['type'], array( 'plugin', 'theme' ), true )
				|| ! is_string( $identity['identifier'] ) || '' === $identity['identifier']
				|| strlen( $identity['identifier'] ) > 255 ) {
				throw new InvalidArgumentException( 'The managed package selection is invalid.' );
			}
			$key = $identity['type'] . "\0" . $identity['identifier'];
			if ( isset( $selected[ $key ] ) ) {
				throw new InvalidArgumentException( 'The managed package selection is invalid.' );
			}
			$selected[ $key ] = true;
		}

		return $selected;
	}

	/**
	 * @param list<array{package: Package, blueprint: BlueprintPackage}> $managed
	 * @return list<BlueprintCredential>
	 */
	private function credentials( array $managed, array $selected ): array {
		$grouped        = array();
		$matched        = array();
		$materials      = array();
		$storageChecked = false;

		foreach ( $managed as $entry ) {
			$package      = $entry['package'];
			$blueprint    = $entry['blueprint'];
			$credentialId = $package->getCredentialId();
			$key          = $blueprint->provider . "\0" . $credentialId;
			if ( ! isset( $selected[ $key ] ) ) {
				continue;
			}
			$matched[ $key ] = true;

			try {
				if ( ! $storageChecked ) {
					$this->secrets->assertManagedStorageReady();
					$storageChecked = true;
				}
				$material = $materials[ $key ] ??= $this->secrets->credentialMaterial( $blueprint->provider, $credentialId );
			} catch ( \Throwable $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The typed exception is caught at the admin boundary.
				throw LocalSecretStoreUnavailable::forPortability( $failure );
			}
			if ( ! is_array( $material ) || 'file' !== ( $material['source'] ?? null )
				|| $blueprint->provider !== ( $material['provider'] ?? null ) || true === ( $material['self_destruct'] ?? false ) ) {
				throw new InvalidArgumentException( 'The managed package credential selection is invalid.' );
			}

			$record = array(
				'provider'      => $blueprint->provider,
				'label'         => $material['label'] ?? null,
				'kind'          => $material['kind'] ?? null,
				'configuration' => $material['configuration'] ?? null,
				'secret'        => $material['secret'] ?? null,
			);
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical in-memory deduplication never reaches output directly.
				$key = hash( 'sha256', json_encode( $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			} catch ( \JsonException ) {
				throw new InvalidArgumentException( 'The managed package credentials are invalid.' );
			}
			$grouped[ $key ]['record']     = $record;
			$grouped[ $key ]['packages'][] = array(
				'type'       => $blueprint->type,
				'identifier' => $blueprint->identifier,
			);
		}
		if ( array_diff_key( $selected, $matched ) ) {
			throw new InvalidArgumentException( 'The managed package credential selection is invalid.' );
		}

		return array_values(
			array_map(
				static fn( array $entry ): BlueprintCredential => new BlueprintCredential(
					$entry['record']['provider'],
					$entry['record']['label'],
					$entry['record']['kind'],
					$entry['record']['configuration'],
					$entry['record']['secret'],
					$entry['packages']
				),
				$grouped
			)
		);
	}
}

<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\Package;
use RAN\PackageSource;
use RAN\WordPress\ManagedReleaseStore;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;

/** Classifies current local package state before any provider access. */
final readonly class BlueprintReviewer {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ManagedReleaseStore $releases = new ManagedReleaseStore()
	) {
	}

	/** @return list<BlueprintPlanItem> */
	public function review( PackageBlueprint $blueprint ): array {
		return array_map( fn ( BlueprintPackage $package ): BlueprintPlanItem => $this->review_package( $package ), $blueprint->packages );
	}

	/**
	 * Assess only the supplied subset; no omitted entry reserves a repository.
	 * Destination management and provider identity still require fresh checks.
	 *
	 * @return array<int, true> Conflicting package indexes.
	 */
	public function repository_conflicts( PackageBlueprint $blueprint ): array {
		$groups    = array();
		$releases  = array();
		$conflicts = array();
		foreach ( $blueprint->packages as $index => $package ) {
			$key                      = $package->provider . "\0" . $package->provider_repository_id;
			$groups[ $key ][ $index ] = (object) array(
				'type'                   => 'plugin' === $package->type ? 1 : 2,
				'package'                => $package->identifier,
				'source'                 => null === $package->release_configuration ? PackageSource::BRANCH->value : PackageSource::RELEASE_ASSET->value,
				'provider'               => $package->provider,
				'provider_repository_id' => $package->provider_repository_id,
			);
			if ( null !== $package->release_configuration ) {
				$releases[ $key ] = $package;
			}
		}
		foreach ( $releases as $key => $release ) {
			$assessment = RepositorySourceGuard::assess_rows(
				$groups[ $key ],
				$release->provider,
				$release->provider_repository_id,
				'plugin' === $release->type ? 1 : 2,
				$release->identifier,
				PackageSource::RELEASE_ASSET
			);
			if ( in_array( $assessment['code'], array( 'repository_source_conflict', 'repository_release_owner_exists' ), true ) ) {
				foreach ( $groups[ $key ] as $index => $unused ) {
					$conflicts[ $index ] = true;
				}
			}
		}
		return $conflicts;
	}

	public function review_package( BlueprintPackage $blueprint, ?Package &$managed_package = null ): BlueprintPlanItem {
		$managed_package = null;
		if ( null !== $blueprint->release_configuration && null !== $blueprint->selection ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::BLOCKED, TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION );
		}
		$item = $this->review_local_package( $blueprint, $managed_package );
		if ( ( null !== $blueprint->selection && TargetPackageAction::INSTALL !== $item->action )
			|| ( null !== $blueprint->release_configuration
				&& TargetPackageAction::ADOPT !== $item->action ) ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::BLOCKED, TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION );
		}
		return $item;
	}

	private function review_local_package( BlueprintPackage $blueprint, ?Package &$managed_package = null ): BlueprintPlanItem {
		$managed_package = null;
		$repository      = 'plugin' === $blueprint->type ? $this->plugins : $this->themes;
		$installed       = $repository->is_installed( $blueprint->identifier );
		$managed         = $repository->has_management_record( $blueprint->identifier );

		if ( ! $installed ) {
			return new BlueprintPlanItem(
				$blueprint,
				$managed ? TargetPackageAction::PROTECTED : TargetPackageAction::INSTALL,
				$managed ? TargetPackageReason::STALE_MANAGEMENT : TargetPackageReason::NONE
			);
		}

		if ( ! $managed ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::ADOPT, TargetPackageReason::NONE );
		}

		try {
			$package         = 'plugin' === $blueprint->type
				? $this->plugins->booster_plugin_from_file( $blueprint->identifier )
				: $this->themes->booster_theme_from_stylesheet( $blueprint->identifier );
			$managed_package = $package;
		} catch ( PackageStorageFailure $failure ) {
			if ( $failure->is_database_unsupported() ) {
				throw $failure;
			}
			return new BlueprintPlanItem(
				$blueprint,
				TargetPackageAction::PROTECTED,
				'ran_booster_storage_duplicate_package' === $failure->get_diagnostic_id()
					? TargetPackageReason::MANAGEMENT_CONFLICT
					: TargetPackageReason::MALFORMED_MANAGEMENT
			);
		} catch ( PluginNotFound | ThemeNotFound ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::PROTECTED, TargetPackageReason::STALE_MANAGEMENT );
		}

		return $this->managed_result( $blueprint, $package );
	}

	/**
	 * Local configuration observations only; this does not authorize an action.
	 *
	 * @return array{state:string,current:?array<string,mixed>,requested:array<string,mixed>,differences:list<string>,requested_selection:?array<string,mixed>,installed_equality:string,source_access:string,package_inspection:string}
	 */
	public function compare_local( BlueprintPackage $blueprint ): array {
		$managed = null;
		$result  = array(
			'state'               => 'unavailable',
			'current'             => null,
			'requested'           => self::configuration( $blueprint ),
			'differences'         => array(),
			'requested_selection' => $blueprint->selection,
			'installed_equality'  => 'unknown',
			'source_access'       => 'not_checked',
			'package_inspection'  => 'not_checked',
		);
		try {
			$item = $this->review_local_package( $blueprint, $managed );
			if ( null === $managed ) {
				$result['state'] = match ( $item->action ) {
					TargetPackageAction::INSTALL => 'absent',
					TargetPackageAction::ADOPT => 'unmanaged',
					default => 'unavailable',
				};
				return $result;
			}
			$release = PackageSource::RELEASE_ASSET === $managed->get_source()
				? $this->releases->configuration( $blueprint->type, $blueprint->identifier )
				: null;
			$current = BlueprintPackage::from_managed_package( $blueprint->type, $managed, $release );
		} catch ( PackageStorageFailure $failure ) {
			if ( $failure->is_database_unsupported() ) {
				throw $failure;
			}
			return $result;
		} catch ( \InvalidArgumentException | \RuntimeException ) {
			return $result;
		}
		$result['current'] = self::configuration( $current );
		foreach ( $result['requested'] as $key => $value ) {
			if ( $result['current'][ $key ] !== $value ) {
				$result['differences'][] = $key;
			}
		}
		$result['state'] = array() === $result['differences'] ? 'matching' : 'different';
		return $result;
	}

	/** @return array<string, mixed> */
	private static function configuration( BlueprintPackage $package ): array {
		$configuration = $package->to_array();
		unset( $configuration['display_name'], $configuration['selection'], $configuration['requirements'] );
		return $configuration;
	}

	private function managed_result( BlueprintPackage $blueprint, Package $package ): BlueprintPlanItem {
		if ( \RAN\PackageSource::BRANCH !== $package->get_source() ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		}
		$matches = $blueprint->same_management_as( BlueprintPackage::from_managed_package( $blueprint->type, $package ) );

		return new BlueprintPlanItem(
			$blueprint,
			$matches ? TargetPackageAction::MANAGED : TargetPackageAction::PROTECTED,
			$matches ? TargetPackageReason::ALREADY_MANAGED : TargetPackageReason::MANAGEMENT_CONFLICT
		);
	}
}

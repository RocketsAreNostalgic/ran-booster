<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\Package;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;

/** Classifies current local package state before any provider access. */
final readonly class BlueprintReviewer {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes
	) {
	}

	/** @return list<BlueprintPlanItem> */
	public function review( PackageBlueprint $blueprint ): array {
		return array_map( fn ( BlueprintPackage $package ): BlueprintPlanItem => $this->review_package( $package ), $blueprint->packages );
	}

	public function review_package( BlueprintPackage $blueprint, ?Package &$managed_package = null ): BlueprintPlanItem {
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

	private function managed_result( BlueprintPackage $blueprint, Package $package ): BlueprintPlanItem {
		$matches = $blueprint->same_management_as( BlueprintPackage::from_managed_package( $blueprint->type, $package ) );

		return new BlueprintPlanItem(
			$blueprint,
			$matches ? TargetPackageAction::MANAGED : TargetPackageAction::PROTECTED,
			$matches ? TargetPackageReason::ALREADY_MANAGED : TargetPackageReason::MANAGEMENT_CONFLICT
		);
	}
}

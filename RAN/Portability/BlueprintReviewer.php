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
		return array_map( fn ( BlueprintPackage $package ): BlueprintPlanItem => $this->reviewPackage( $package ), $blueprint->packages );
	}

	public function reviewPackage( BlueprintPackage $blueprint, ?Package &$managedPackage = null ): BlueprintPlanItem {
		$managedPackage = null;
		$repository     = 'plugin' === $blueprint->type ? $this->plugins : $this->themes;
		$installed      = $repository->isInstalled( $blueprint->identifier );
		$managed        = $repository->hasManagementRecord( $blueprint->identifier );

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
			$package        = 'plugin' === $blueprint->type
				? $this->plugins->boosterPluginFromFile( $blueprint->identifier )
				: $this->themes->boosterThemeFromStylesheet( $blueprint->identifier );
			$managedPackage = $package;
		} catch ( PackageStorageFailure $failure ) {
			if ( $failure->isDatabaseUnsupported() ) {
				throw $failure;
			}
			return new BlueprintPlanItem(
				$blueprint,
				TargetPackageAction::PROTECTED,
				'ran_booster_storage_duplicate_package' === $failure->getDiagnosticId()
					? TargetPackageReason::MANAGEMENT_CONFLICT
					: TargetPackageReason::MALFORMED_MANAGEMENT
			);
		} catch ( PluginNotFound | ThemeNotFound ) {
			return new BlueprintPlanItem( $blueprint, TargetPackageAction::PROTECTED, TargetPackageReason::STALE_MANAGEMENT );
		}

		return $this->managedResult( $blueprint, $package );
	}

	private function managedResult( BlueprintPackage $blueprint, Package $package ): BlueprintPlanItem {
		$matches = $blueprint->sameManagementAs( BlueprintPackage::fromManagedPackage( $blueprint->type, $package ) );

		return new BlueprintPlanItem(
			$blueprint,
			$matches ? TargetPackageAction::MANAGED : TargetPackageAction::PROTECTED,
			$matches ? TargetPackageReason::ALREADY_MANAGED : TargetPackageReason::MANAGEMENT_CONFLICT
		);
	}
}

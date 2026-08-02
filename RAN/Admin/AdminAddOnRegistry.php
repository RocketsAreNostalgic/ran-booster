<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;

/**
 * One-request registry for trusted add-on dashboard tabs.
 */
final class AdminAddOnRegistry {

	/** @var array<string, AdminAddOnTab> */
	private array $tabs = array();

	/** @var array<string, true> */
	private array $addOnSlugs = array();

	private bool $sealed = false;

	/** @var array<string, object> */
	private array $facades;

	/** @param array<string, object> $facades Core-owned allowlisted facade map. */
	public function __construct(
		array $facades = array(),
		private int $coreApiVersion = 1,
		private int $addOnApiVersion = 1
	) {
		if ( $this->coreApiVersion < 1 || $this->addOnApiVersion < 1 ) {
			throw new LogicException( 'Add-on API versions must be positive integers.' );
		}

		foreach ( $facades as $name => $facade ) {
			if ( ! is_string( $name )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $name )
				|| ! is_object( $facade ) ) {
				throw new LogicException( 'Add-on facades must be named Core-owned objects.' );
			}
		}

		$this->facades = $facades;
	}

	public function register( AdminAddOnTab $tab ): void {
		if ( $this->sealed ) {
			throw new LogicException( 'Add-on tab registration is closed.' );
		}

		if ( isset( $this->tabs[ $tab->key() ] ) ) {
			throw new LogicException( 'Add-on tab keys must be unique.' );
		}

		if ( isset( $this->addOnSlugs[ $tab->addOnSlug() ] ) ) {
			throw new LogicException( 'Each add-on may register only one tab.' );
		}

		if ( null !== $tab->facadeName() && ! isset( $this->facades[ $tab->facadeName() ] ) ) {
			throw new LogicException( 'Add-on tabs may request only approved facades.' );
		}

		if ( ! $tab->supportsApiVersions( $this->coreApiVersion, $this->addOnApiVersion ) ) {
			throw new LogicException( 'Add-on tabs must support the published Booster API.' );
		}

		$this->tabs[ $tab->key() ]             = $tab;
		$this->addOnSlugs[ $tab->addOnSlug() ] = true;
	}

	public function seal(): void {
		$this->sealed = true;
	}

	/** @return list<AdminAddOnTab> */
	public function all(): array {
		return array_values( $this->tabs );
	}

	public function get( string $key ): ?AdminAddOnTab {
		return $this->tabs[ $key ] ?? null;
	}

	public function contextFor(
		AdminAddOnTab $tab,
		string $boosterUrl,
		string $scope
	): AdminAddOnContext {
		if ( ( $this->tabs[ $tab->key() ] ?? null ) !== $tab ) {
			throw new LogicException( 'Add-on context requires a registered tab.' );
		}

		$facades = null === $tab->facadeName()
			? array()
			: array( $tab->facadeName() => $this->facades[ $tab->facadeName() ] );

		return AdminAddOnContext::forCurrentAdministrator(
			$tab->key(),
			$boosterUrl,
			$scope,
			$this->coreApiVersion,
			$this->addOnApiVersion,
			$facades
		);
	}
}

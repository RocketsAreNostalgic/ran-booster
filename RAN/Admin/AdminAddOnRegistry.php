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
	private array $add_on_slugs = array();

	private bool $sealed = false;

	/** @var array<string, object> */
	private array $facades;

	/** @param array<string, object> $facades Core-owned allowlisted facade map. */
	public function __construct(
		array $facades = array(),
		private int $core_api_version = 1,
		private int $add_on_api_version = 1
	) {
		if ( $this->core_api_version < 1 || $this->add_on_api_version < 1 ) {
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

		if ( isset( $this->add_on_slugs[ $tab->add_on_slug() ] ) ) {
			throw new LogicException( 'Each add-on may register only one tab.' );
		}

		if ( null !== $tab->facade_name() && ! isset( $this->facades[ $tab->facade_name() ] ) ) {
			throw new LogicException( 'Add-on tabs may request only approved facades.' );
		}

		if ( ! $tab->supports_api_versions( $this->core_api_version, $this->add_on_api_version ) ) {
			throw new LogicException( 'Add-on tabs must support the published Booster API.' );
		}

		$this->tabs[ $tab->key() ]             = $tab;
		$this->add_on_slugs[ $tab->add_on_slug() ] = true;
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

	public function context_for(
		AdminAddOnTab $tab,
		string $booster_url,
		string $scope
	): AdminAddOnContext {
		if ( ( $this->tabs[ $tab->key() ] ?? null ) !== $tab ) {
			throw new LogicException( 'Add-on context requires a registered tab.' );
		}

		$facades = null === $tab->facade_name()
			? array()
			: array( $tab->facade_name() => $this->facades[ $tab->facade_name() ] );

		return AdminAddOnContext::for_current_administrator(
			$tab->key(),
			$booster_url,
			$scope,
			$this->core_api_version,
			$this->add_on_api_version,
			$facades
		);
	}
}

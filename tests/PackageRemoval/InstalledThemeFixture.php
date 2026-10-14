<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

/** Installed-only WordPress theme inventory for removal gateway tests. */
final class InstalledThemeFixture {
	public function __construct( private string $stylesheet = 'example' ) {
		$this->exists = ! ( $GLOBALS['ran_booster_removed_theme_targets'][ $stylesheet ] ?? false );
	}

	private bool $exists;

	public function exists(): bool {
		return $this->exists;
	}

	public function cache_delete(): void {
		$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'theme_cache_delete', $this->stylesheet );
		unset( $GLOBALS['ran_booster_cached_theme_targets'][ $this->stylesheet ] );
	}

	public function get_template(): string {
		return $this->stylesheet;
	}

	public function errors(): false {
		return false;
	}

	public function get( string $header ): string {
		return 'Name' === $header ? 'Example' : '';
	}
}

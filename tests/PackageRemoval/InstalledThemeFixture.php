<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

/** Installed-only WordPress theme inventory for removal gateway tests. */
final class InstalledThemeFixture {
	public function errors(): false {
		return false;
	}

	public function get( string $header ): string {
		return 'Name' === $header ? 'Example' : '';
	}
}

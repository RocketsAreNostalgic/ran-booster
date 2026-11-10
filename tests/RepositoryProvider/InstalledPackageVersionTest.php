<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InstalledPackageVersion;

final class InstalledPackageVersionTest extends TestCase {
	public function test_composer_metadata_reports_the_installed_package_version(): void {
		$expected = \Composer\InstalledVersions::getPrettyVersion( 'ran/booster-github-provider' );
		self::assertNotNull( $expected );
		self::assertSame( $expected, ( new InstalledPackageVersion() )->for_package( 'ran/booster-github-provider' ) );
	}

	public function test_unknown_package_is_unavailable(): void {
		self::assertNull( ( new InstalledPackageVersion() )->for_package( 'ran/not-installed-package' ) );
	}

	public function test_failing_or_malformed_metadata_is_unavailable(): void {
		self::assertNull( ( new InstalledPackageVersion( static fn (): ?string => throw new \RuntimeException( 'boom' ) ) )->for_package( 'a/b' ) );
		self::assertNull( ( new InstalledPackageVersion( static fn (): ?string => str_repeat( '1', 81 ) ) )->for_package( 'a/b' ) );
		self::assertNull( ( new InstalledPackageVersion( static fn (): ?string => '' ) )->for_package( 'a/b' ) );
		self::assertSame( 'v1.2.3', ( new InstalledPackageVersion( static fn (): ?string => 'v1.2.3' ) )->for_package( 'a/b' ) );
	}
}

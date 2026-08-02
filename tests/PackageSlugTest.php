<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RAN\Plugin;
use RAN\Theme;

final class PackageSlugTest extends TestCase {

	public function testInstalledPluginSlugComesFromItsWordPressIdentifier(): void {
		self::assertSame( 'installed-plugin', $this->plugin( 'installed-plugin/plugin.php' )->getSlug() );
		self::assertSame( 'single-plugin', $this->plugin( 'single-plugin.php' )->getSlug() );
	}

	public function testInstalledThemeSlugComesFromItsStylesheet(): void {
		self::assertSame( 'installed-theme', $this->theme( 'installed-theme' )->getSlug() );
	}

	public function testExistingRuntimeAndSubdirectoryCaseArePreserved(): void {
		self::assertSame( 'MixedCasePlugin', $this->plugin( 'MixedCasePlugin/plugin.php' )->getSlug() );

		$package = $this->plugin( 'installed-plugin/plugin.php' );
		$package->setSubdirectory( 'packages/MixedCasePlugin' );

		self::assertSame( 'MixedCasePlugin', $package->getSlug() );
		self::assertSame( 'packages/MixedCasePlugin', $package->getSubdirectory() );
	}

	public function testProviderInstallationSlugIsTransientAndSubdirectoryRemainsAuthoritative(): void {
		$package = $this->plugin( 'installed-plugin/plugin.php' );
		$package->setInstallationSlug( 'provider-package' );

		self::assertSame( 'provider-package', $package->getSlug() );

		$package->setSubdirectory( 'packages/subdirectory-package' );
		self::assertSame( 'subdirectory-package', $package->getSlug() );

		$package->setSubdirectory( null );
		$package->setInstallationSlug( null );
		self::assertSame( 'installed-plugin', $package->getSlug() );
	}

	private function plugin( string $file ): Plugin {
		$reflection = new ReflectionClass( Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'file' )->setValue( $plugin, $file );

		return $plugin;
	}

	private function theme( string $stylesheet ): Theme {
		$reflection = new ReflectionClass( Theme::class );
		$theme      = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'stylesheet' )->setValue( $theme, $stylesheet );

		return $theme;
	}
}

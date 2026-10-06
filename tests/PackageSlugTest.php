<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RAN\Plugin;
use RAN\Theme;

final class PackageSlugTest extends TestCase {

	public function test_installed_plugin_slug_comes_from_its_word_press_identifier(): void {
		self::assertSame( 'installed-plugin', $this->plugin( 'installed-plugin/plugin.php' )->get_slug() );
		self::assertSame( 'single-plugin', $this->plugin( 'single-plugin.php' )->get_slug() );
	}

	public function test_installed_theme_slug_comes_from_its_stylesheet(): void {
		self::assertSame( 'installed-theme', $this->theme( 'installed-theme' )->get_slug() );
	}

	public function test_existing_runtime_and_subdirectory_case_are_preserved(): void {
		self::assertSame( 'MixedCasePlugin', $this->plugin( 'MixedCasePlugin/plugin.php' )->get_slug() );

		$package = $this->plugin( 'installed-plugin/plugin.php' );
		$package->set_subdirectory( 'packages/MixedCasePlugin' );

		self::assertSame( 'MixedCasePlugin', $package->get_slug() );
		self::assertSame( 'packages/MixedCasePlugin', $package->get_subdirectory() );
	}

	public function test_provider_installation_slug_is_transient_and_subdirectory_remains_authoritative(): void {
		$package = $this->plugin( 'installed-plugin/plugin.php' );
		$package->set_installation_slug( 'provider-package' );

		self::assertSame( 'provider-package', $package->get_slug() );

		$package->set_subdirectory( 'packages/subdirectory-package' );
		self::assertSame( 'subdirectory-package', $package->get_slug() );

		$package->set_subdirectory( null );
		$package->set_installation_slug( null );
		self::assertSame( 'installed-plugin', $package->get_slug() );
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

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

	public function test_installed_folder_overrides_repository_subdirectory_and_transient_slug(): void {
		foreach ( array(
			$this->plugin( 'myplugin/plugin.php' ),
			$this->theme( 'mytheme' ),
		) as $package ) {
			$installed = $package instanceof Plugin ? 'myplugin' : 'mytheme';
			$package->set_installation_slug( 'provider-package' );
			foreach ( array( 'packages/' . ucfirst( $installed ), 'packages/different-name' ) as $subdirectory ) {
				$package->set_subdirectory( $subdirectory );
				self::assertSame( $installed, $package->get_slug() );
				self::assertSame( $subdirectory, $package->get_subdirectory() );
			}
			$package->set_subdirectory( null );
			self::assertSame( $installed, $package->get_slug() );
		}
	}

	public function test_mixed_case_installed_identity_is_preserved_without_admitting_deployment(): void {
		self::assertSame( 'MixedCasePlugin', $this->plugin( 'MixedCasePlugin/plugin.php' )->get_slug() );
		self::assertSame( 'MixedCaseTheme', $this->theme( 'MixedCaseTheme' )->get_slug() );
	}

	public function test_generic_package_without_runtime_identity_retains_transient_slug_fallback(): void {
		$package = new class() extends \RAN\AbstractPackage {
			public function get_identifier(): mixed {
				return null;
			}
		};
		$package->set_installation_slug( 'provider-package' );
		self::assertSame( 'provider-package', $package->get_slug() );
		$package->set_installation_slug( null );
		$this->expectException( \LogicException::class );
		$package->get_slug();
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

<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use ReflectionClass;
use ReflectionMethod;

/** Release metadata and Update URI reads find a theme under its own registered root (#1188). */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ManagedReleaseThemeRootTest extends TestCase {
	private string $base;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/ManagedReleaseThemeRootWordPressFunctions.php';
		$this->base = sys_get_temp_dir() . '/ran-booster-release-theme-root-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable default and secondary theme roots.
		mkdir( $this->base . '/secondary/registered', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable default and secondary theme roots.
		mkdir( $this->base . '/themes', 0700 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable theme stylesheet.
		file_put_contents( $this->base . '/secondary/registered/style.css', "/*\nTheme Name: Registered\nUpdate URI: https://updates.example.test/registered/\n*/\n" );
		$GLOBALS['ran_booster_release_theme_roots'] = array(
			''           => $this->base . '/themes/',
			'registered' => $this->base . '/secondary/',
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the disposable test-owned stylesheet.
		unlink( $this->base . '/secondary/registered/style.css' );
		foreach ( array( '/secondary/registered', '/secondary', '/themes', '' ) as $directory ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable test-owned directories.
			rmdir( $this->base . $directory );
		}
	}

	public function test_release_metadata_path_uses_the_theme_own_root_without_a_doubled_separator(): void {
		$registrar = ( new ReflectionClass( ManagedReleaseTargetRegistrar::class ) )->newInstanceWithoutConstructor();
		$path      = ( new ReflectionMethod( $registrar, 'metadata_path' ) )->invoke( $registrar, 'theme', new ManagedReleaseConfiguration( 'registered', 'style.css' ), 'registered' );

		self::assertSame( $this->base . '/secondary/registered/style.css', $path );
	}

	public function test_update_uri_is_read_from_the_theme_own_root(): void {
		$facade = ( new ReflectionClass( NativeReleaseTrackingFacade::class ) )->newInstanceWithoutConstructor();

		self::assertSame( 'https://updates.example.test/registered', ( new ReflectionMethod( $facade, 'update_uri' ) )->invoke( $facade, 'theme', 'registered' ) );
	}
}

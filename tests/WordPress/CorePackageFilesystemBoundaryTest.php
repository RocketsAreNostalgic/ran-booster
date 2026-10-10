<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use Closure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\CorePackageExecutor;
use ReflectionMethod;
use WP_Error;

final class CorePackageFilesystemBoundaryTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_source_selection_fails_closed_for_uncallable_moves_and_preserves_native_and_magic_dispatch(): void {
		require dirname( __DIR__ ) . '/Support/WPError.php';
		require __DIR__ . '/CorePackageFilesystemWordPressFunctions.php';
		global $wp_filesystem;
		$directory = sys_get_temp_dir() . '/ran-core-filesystem-' . bin2hex( random_bytes( 8 ) );
		$source    = $directory . '/extracted';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only this test's unique source tree; no installation paths are touched.
		self::assertTrue( mkdir( $source, 0700, true ) );
		try {
			$method = new ReflectionMethod( CorePackageExecutor::class, 'source_selection_filter' );
			$filter = $method->invoke( new CorePackageExecutor(), 'target-plugin', null, 'plugin', 'install', null );
			self::assertInstanceOf( Closure::class, $filter );
			$extra = array(
				'type'   => 'plugin',
				'action' => 'install',
			);
			foreach ( array(
				null,
				false,
				get_class(
					new class() {
						public static function move(): never {
							throw new \LogicException( 'A class-string move must never execute.' );
						}
					}
				),
				new \stdClass(),
				new class() {
					protected function move(): never {
						throw new \LogicException( 'A nonpublic move must never execute.' );
					}
				},
			) as $unavailable ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Exercise the host-owned filesystem slot only within this isolated child process.
				$wp_filesystem = $unavailable;
				$result        = $filter( $source, $directory, null, $extra );
				self::assertInstanceOf( WP_Error::class, $result );
				self::assertSame( 'ran_booster_invalid_package_source', $result->get_error_code() );
				self::assertDirectoryExists( $source );
				self::assertFileDoesNotExist( $directory . '/target-plugin' );
			}

			foreach ( array( true, false ) as $accepted ) {
				$declared = new class( $accepted ) {
					/** @var list<array{string, string, bool}> */
					public array $calls = array();

					public function __construct( private bool $accepted ) {
					}

					public function move( string $from, string $to, bool $overwrite ): bool {
						$this->calls[] = array( $from, $to, $overwrite );
						return $this->accepted;
					}
				};
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind only this isolated test's declared filesystem double.
				$wp_filesystem = $declared;
				$result        = $filter( $source, $directory, null, $extra );
				self::assertSame( array( array( $source, $directory . '/target-plugin', false ) ), $declared->calls );
				if ( $accepted ) {
					self::assertSame( $directory . '/target-plugin/', $result );
				} else {
					self::assertInstanceOf( WP_Error::class, $result );
					self::assertSame( 'ran_booster_invalid_package_source', $result->get_error_code() );
				}
			}

			$magic = new class() {
				/** @var list<array{string, array<array-key, mixed>}> */
				public array $calls = array();

				/** @param array<array-key, mixed> $arguments */
				public function __call( string $name, array $arguments ): bool {
					$this->calls[] = array( $name, $arguments );
					return true;
				}
			};
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind only this isolated test's magic filesystem double.
			$wp_filesystem = $magic;
			self::assertSame( $directory . '/target-plugin/', $filter( $source, $directory, null, $extra ) );
			self::assertSame( array( array( 'move', array( $source, $directory . '/target-plugin', false ) ) ), $magic->calls );
			self::assertSame(
				$source,
				$filter(
					$source,
					$directory,
					null,
					array(
						'type'   => 'theme',
						'action' => 'install',
					)
				)
			);
			self::assertCount( 1, $magic->calls );
			self::assertDirectoryExists( $source );
			self::assertFileDoesNotExist( $directory . '/target-plugin' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the two empty directories owned by this test.
			rmdir( $source );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the private fixture root.
			rmdir( $directory );
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

require_once __DIR__ . '/WordPressPackageRemovalGatewayWordPressFunctions.php';
require_once __DIR__ . '/InstalledThemeFixture.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\PackageRemoval\WordPressPackageRemovalGateway;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WordPressPackageRemovalGatewayTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_package_removal_gateway_events'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_removal_gateway_events'],
			$GLOBALS['ran_booster_package_removal_gateway_result'],
			$GLOBALS['ran_booster_package_removal_transients'],
			$GLOBALS['ran_booster_package_removal_transient_failure']
		);
	}

	public function test_installed_confirmation_detects_content_changes_and_file_replacement(): void {
		$root = sys_get_temp_dir() . '/ran-booster-recovery-gateway-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
		mkdir( $root . '/example', 0700, true );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned installed root in this isolated process.
		define( 'WP_PLUGIN_DIR', $root );
		$file = $root . '/example/example.php';
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			file_put_contents( $file, '<?php // original' );
			$gateway  = new WordPressPackageRemovalGateway();
			$original = $gateway->installed_fingerprint( 'plugin', 'example/example.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			file_put_contents( $file, '<?php // changed' );
			$changed = $gateway->installed_fingerprint( 'plugin', 'example/example.php' );
			self::assertNotSame( $original, $changed );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			file_put_contents( $file . '.replacement', '<?php // changed' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rename( $file . '.replacement', $file );
			self::assertNotSame( $changed, $gateway->installed_fingerprint( 'plugin', 'example/example.php' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			unlink( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rmdir( $root . '/example' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rmdir( $root );
		}
	}

	/** @return array<string,array{string,string}> */
	public static function installed_links(): array {
		$cases = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'main', 'directory', 'root' ) as $component ) {
				$cases[ $type . '-' . $component ] = array( $type, $component );
			}
		}
		return $cases;
	}

	#[DataProvider( 'installed_links' )]
	public function test_management_confirmation_tracks_stable_links_swaps_and_same_byte_retargets( string $type, string $component ): void {
		$base       = sys_get_temp_dir() . '/ran-booster-recovery-gateway-' . bin2hex( random_bytes( 8 ) );
		$main       = 'plugin' === $type ? 'example.php' : 'style.css';
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example';
		$root       = $base . '/installed';
		foreach ( array( 'original', 'replacement' ) as $tree ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			mkdir( $base . '/' . $tree . '/example', 0700, true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			file_put_contents( $base . '/' . $tree . '/example/' . $main, 'identical installed bytes' );
		}
		if ( 'root' !== $component ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			mkdir( $root, 0700 );
		}
		if ( 'main' === $component ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			mkdir( $root . '/example', 0700 );
		}
		$suffix = match ( $component ) {
			'root' => '', 'directory' => '/example', default => '/example/' . $main };
		$link               = $root . $suffix;
		$original_target    = $base . '/original' . $suffix;
		$replacement_target = $base . '/replacement' . $suffix;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned installed root in this isolated process.
		define( 'WP_PLUGIN_DIR', $root );
		try {
			symlink( $original_target, $link );
			$gateway  = new WordPressPackageRemovalGateway();
			$original = $gateway->installed_fingerprint( $type, $identifier );
			self::assertSame( $original, $gateway->installed_fingerprint( $type, $identifier ) );
			self::assertSame( array_fill( 0, 2, array( 'plugin' === $type ? 'clean' : 'clean_themes', false ) ), $GLOBALS['ran_booster_package_removal_gateway_events'], 'Each installed confirmation refreshes its inventory without clearing unrelated update offers.' );
			if ( 'directory' === $component || 'root' === $component ) {
				self::assertFalse( 'plugin' === $type ? $gateway->plugin_path_is_safe( $identifier ) : $gateway->theme_path_is_safe( $identifier ) );
			}
			symlink( $original_target, $link . '.next' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rename( $link . '.next', $link );
			$swapped = $gateway->installed_fingerprint( $type, $identifier );
			self::assertNotSame( $original, $swapped, 'Replacing the link to the same target changes confirmation.' );
			symlink( $replacement_target, $link . '.next' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rename( $link . '.next', $link );
			self::assertNotSame( $swapped, $gateway->installed_fingerprint( $type, $identifier ), 'Retargeting to identical bytes changes confirmation.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			self::assertSame( 'identical installed bytes', file_get_contents( $base . '/original/example/' . $main ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			unlink( $base . '/replacement/example/' . $main );
			$this->expectException( RuntimeException::class );
			$gateway->installed_fingerprint( $type, $identifier );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			unlink( $link );
			if ( 'main' === $component ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
				rmdir( $root . '/example' );
			}
			if ( 'root' !== $component ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
				rmdir( $root );
			}
			foreach ( array( 'original', 'replacement' ) as $tree ) {
				$file = $base . '/' . $tree . '/example/' . $main;
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
					unlink( $file );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
				rmdir( $base . '/' . $tree . '/example' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
				rmdir( $base . '/' . $tree );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exercise exact disposable filesystem bytes and inode/symlink topology without WordPress filesystem indirection.
			rmdir( $base );
		}
	}

	public function test_native_cleanup_preserves_unrelated_entries_and_metadata(): void {
		foreach ( array(
			'plugin' => 'example/example.php',
			'theme'  => 'example',
		) as $type => $identifier ) {
			$name   = 'plugin' === $type ? 'update_plugins' : 'update_themes';
			$before = (object) array(
				'response'     => array(
					$identifier => (object) array( 'version' => '2' ),
					'other'     => (object) array( 'version' => '3' ),
				),
				'no_update'    => array(
					$identifier => 'old',
					'other'     => 'keep',
				),
				'last_checked' => 123,
			);
			$GLOBALS['ran_booster_package_removal_transients'][ $name ] = $before;
			( new WordPressPackageRemovalGateway() )->invalidate_native_target( $type, $identifier );
			$expected = clone $before;
			unset( $expected->response[ $identifier ], $expected->no_update[ $identifier ] );
			self::assertEquals( $expected, $GLOBALS['ran_booster_package_removal_transients'][ $name ] );
		}
	}

	public function test_native_cleanup_detects_failed_persistence(): void {
		$GLOBALS['ran_booster_package_removal_transients']['update_plugins'] = (object) array( 'response' => array( 'example/example.php' => 'old' ) );
		$GLOBALS['ran_booster_package_removal_transient_failure']            = true;
		$this->expectException( RuntimeException::class );
		( new WordPressPackageRemovalGateway() )->invalidate_native_target( 'plugin', 'example/example.php' );
	}

	#[DataProvider( 'delete_results' )]
	public function test_plugin_inventory_is_refreshed_after_every_completed_delete_attempt( mixed $result, bool $deleted ): void {
		$GLOBALS['ran_booster_package_removal_gateway_result'] = $result;

		self::assertSame(
			$deleted,
			( new WordPressPackageRemovalGateway() )->delete_plugin( 'example/example.php' )
		);
		self::assertSame(
			array(
				array( 'delete', array( 'example/example.php' ) ),
				array( 'clean', false ),
			),
			$GLOBALS['ran_booster_package_removal_gateway_events']
		);
	}

	/** @return list<array{mixed, bool}> */
	public static function delete_results(): array {
		return array(
			array( true, true ),
			array( false, false ),
		);
	}

	public function test_plugin_inventory_is_refreshed_when_word_press_deletion_throws(): void {
		$GLOBALS['ran_booster_package_removal_gateway_result'] = new RuntimeException( 'Fixture deletion failed.' );

		try {
			( new WordPressPackageRemovalGateway() )->delete_plugin( 'example/example.php' );
			self::fail( 'Expected the WordPress deletion failure.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Fixture deletion failed.', $failure->getMessage() );
		}
		self::assertSame(
			array(
				array( 'delete', array( 'example/example.php' ) ),
				array( 'clean', false ),
			),
			$GLOBALS['ran_booster_package_removal_gateway_events']
		);
	}
}

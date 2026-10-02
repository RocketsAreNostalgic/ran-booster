<?php

declare(strict_types=1);

namespace Tests\Quality;

use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileFinder;
use PHPStan\File\FileHelper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class ProductionAnalysisCoverageTest extends TestCase {

	public function test_every_shipped_core_php_file_is_directly_analysed(): void {
		$container = $this->analysis_container();
		$shipped   = $this->shipped_php_files();
		$analysed  = $container->getService( 'fileFinderAnalyse' )
			->findFiles( $container->getParameter( 'paths' ) )->getFiles();

		self::assertNotEmpty( $shipped );
		self::assertSame( array(), array_values( array_diff( $shipped, $analysed ) ), 'Shipped PHP must be directly analysed, not merely scanned for symbols.' );
	}

	public function test_removing_template_or_asset_roots_exposes_missing_shipped_files(): void {
		$container = $this->analysis_container();
		$finder    = $container->getService( 'fileFinderAnalyse' );
		$shipped   = $this->shipped_php_files();

		foreach ( array( 'views', 'assets' ) as $directory ) {
			$root     = $this->root() . '/' . $directory;
			$expected = array_values( array_filter( $shipped, static fn( string $file ): bool => str_starts_with( $file, $root . '/' ) ) );
			$paths    = array_values( array_diff( $container->getParameter( 'paths' ), array( $root ) ) );
			$missing  = array_values( array_diff( $shipped, $finder->findFiles( $paths )->getFiles() ) );

			self::assertNotEmpty( $expected );
			self::assertSame( $expected, $missing, 'Removing ' . $directory . ' must expose its shipped PHP files.' );
		}
	}

	public function test_an_excluded_shipped_file_fails_the_inventory_comparison(): void {
		$container = $this->analysis_container();
		$shipped   = $this->shipped_php_files();
		$helper    = new FileHelper( $this->root() );
		$finder    = new FileFinder( new FileExcluder( $helper, array( $shipped[0] ) ), $helper, $container->getParameter( 'fileExtensions' ) );
		$analysed  = $finder->findFiles( $container->getParameter( 'paths' ) )->getFiles();

		self::assertSame( array( $shipped[0] ), array_values( array_diff( $shipped, $analysed ) ) );
	}

	private function analysis_container(): Container {
		return ( new ContainerFactory( $this->root() ) )->create(
			$this->root() . '/.phpunit.cache/analysis-coverage',
			array( $this->root() . '/phpstan.neon' ),
			array()
		);
	}

	/** @return list<string> */
	private function shipped_php_files(): array {
		$manifest = file( $this->root() . '/release-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Read the local release allowlist for this CLI contract.
		self::assertIsArray( $manifest );
		$files = array();
		foreach ( $manifest as $entry ) {
			if ( str_starts_with( $entry, '#' ) ) {
				continue;
			}
			$path = $this->root() . '/' . $entry;
			if ( is_dir( $path ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
					if ( $file->isFile() && 'php' === $file->getExtension() ) {
						$files[] = str_replace( '\\', '/', $file->getPathname() );
					}
				}
			} elseif ( 'php' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
				self::assertFileExists( $path );
				$files[] = $path;
			}
		}
		sort( $files );
		return $files;
	}

	private function root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	}
}

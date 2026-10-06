<?php

declare(strict_types=1);

namespace RAN\Tests\Quality;

use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\DirectoryWalker;
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
		$finder    = new FileFinder( new FileExcluder( $helper, array( $shipped[0] ) ), $helper, $container->getParameter( 'fileExtensions' ), $container->getByType( DirectoryWalker::class ) );
		$analysed  = $finder->findFiles( $container->getParameter( 'paths' ) )->getFiles();

		self::assertSame( array( $shipped[0] ), array_values( array_diff( $shipped, $analysed ) ) );
	}

	public function test_development_analysis_and_pending_inventory_account_for_every_file(): void {
		$root    = $this->root();
		$pending = file( $root . '/phpstan-development-pending.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Read the temporary, reviewed migration debt inventory without executing source.
		self::assertIsArray( $pending );
		$pending = array_values( array_filter( $pending, static fn( string $path ): bool => ! str_starts_with( $path, '#' ) ) );
		self::assertSame( $pending, array_values( array_unique( $pending ) ), 'Pending paths must be exact and unique.' );
		$pending  = array_map( static fn( string $path ): string => $root . '/' . $path, $pending );
		$composer = file_get_contents( $root . '/composer.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Ensure canonical analysis executes both isolated profiles rather than merely checking their selections.
		self::assertIsString( $composer );
		self::assertSame(
			array( 'phpstan analyse --configuration=phpstan.neon --no-progress --debug --memory-limit=2G', 'phpstan analyse --configuration=phpstan-development.neon --no-progress --memory-limit=2G' ),
			json_decode( $composer, true, 512, JSON_THROW_ON_ERROR )['scripts']['analyze']
		);
		$actual = $this->development_analysed_files( $root . '/phpstan-development.neon' );
		$all    = $this->development_files( $root );
		self::assertSame( array(), array_values( array_diff( $actual, $all ) ), 'Development analysis must not introduce another symbol world.' );
		self::assertSame( $pending, array_values( array_diff( $all, $actual ) ), 'Every new or split development file must enter analysis; pending gaps cannot silently expand or become stale.' );
	}

	public function test_new_development_files_are_included_and_pending_splits_are_visible(): void {
		$root    = $this->root();
		$fixture = sys_get_temp_dir() . '/ran-core-analysis-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $fixture . '/scripts', 0700, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only private analysis fixture directories with mode 0700.
		self::assertTrue( mkdir( $fixture . '/tests/Webhook', 0700, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only private analysis fixture directories with mode 0700.
		self::assertTrue( mkdir( $fixture . '/tests/Admin', 0700, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only private analysis fixture directories with mode 0700.
		$config = file_get_contents( $root . '/phpstan-development.neon' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exercise actual development configuration in a private fixture.
		self::assertIsString( $config );
		$config = str_replace( 'vendor/', $root . '/vendor/', $config );
		// The isolated tree has no historical root-level tests requiring exact exclusions.
		$config = preg_replace( '/^\t\t\t- tests\/[^\/]*\.php\n/m', '', $config );
		self::assertIsString( $config );
		try {
			file_put_contents( $fixture . '/analysis.neon', $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert PHP/configuration bytes only inside the private analyzer fixture.
			foreach ( array( 'scripts/New.php', 'tests/NewRoot.php', 'tests/Webhook/Split.php', 'tests/Admin/Pending.php' ) as $path ) {
				file_put_contents( $fixture . '/' . $path, '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert PHP/configuration bytes only inside the private analyzer fixture.
			}
			$analysed = $this->development_analysed_files( $fixture . '/analysis.neon' );
			self::assertContains( $fixture . '/scripts/New.php', $analysed );
			self::assertContains( $fixture . '/tests/NewRoot.php', $analysed );
			self::assertContains( $fixture . '/tests/Webhook/Split.php', $analysed );
			self::assertSame( array( $fixture . '/tests/Admin/Pending.php' ), array_values( array_diff( $this->development_files( $fixture ), $analysed ) ) );
			file_put_contents( $fixture . '/tests/Admin/NewSplit.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert PHP/configuration bytes only inside the private analyzer fixture.
			self::assertSame( array( $fixture . '/tests/Admin/NewSplit.php', $fixture . '/tests/Admin/Pending.php' ), array_values( array_diff( $this->development_files( $fixture ), $this->development_analysed_files( $fixture . '/analysis.neon' ) ) ) );
			file_put_contents( $fixture . '/scripts/New.php', '<?php function ran_core_analysis_probe(): int { return "invalid"; }' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The isolated body violation must be reported by the real locked analyzer.
			list( $status, $output ) = $this->analyse_development_fixture( $fixture . '/analysis.neon' );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'return.type', $output );
			file_put_contents( $fixture . '/scripts/New.php', '<?php function ran_core_analysis_probe(): int { return 1; }' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Correcting the isolated body must restore actual analysis acceptance.
			list( $status, $output ) = $this->analyse_development_fixture( $fixture . '/analysis.neon' );
			self::assertSame( 0, $status, $output );
			file_put_contents( $fixture . '/stub.neon', $config . "\tstubFiles:\n\t\t- scripts/New.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert PHP/configuration bytes only inside the private analyzer fixture.
			self::assertNotContains( $fixture . '/scripts/New.php', $this->development_analysed_files( $fixture . '/stub.neon' ) );
		} finally {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the analysis fixture descendants, children first.
			}
			rmdir( $fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty private analysis fixture root.
		}
	}

	/** @return array{int, string} */
	private function analyse_development_fixture( string $config ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Invoke only the locked local analyzer against the private fixture to prove actual body diagnostics.
		$process = proc_open(
			array( PHP_BINARY, $this->root() . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--error-format=json' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( $config )
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the test-owned analyzer stdout pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the test-owned analyzer stderr pipe.
		return array( proc_close( $process ), $output );
	}

	/** @return list<string> */
	private function development_files( string $root ): array {
		$files = array();
		foreach ( array( 'scripts', 'tests' ) as $role ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $role, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$header = file_get_contents( $file->getPathname(), false, null, 0, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Discover nonstandard PHP entrypoints from inert headers, without executing fixtures.
				self::assertIsString( $header );
				if ( 'php' === strtolower( $file->getExtension() ) || preg_match( '/\A(?:#![^\r\n]*\R)?\s*<\?(?:php(?:\s|$)|=)/i', $header ) ) {
					$files[] = str_replace( '\\', '/', $file->getPathname() );
				}
			}
		}
		sort( $files );
		return $files;
	}

	/** @return list<string> */
	private function development_analysed_files( string $config ): array {
		$container = ( new ContainerFactory( $this->root() ) )->create( $this->root() . '/.phpunit.cache/development-coverage', array( $config ), array() );
		$level     = $container->getParameter( 'level' );
		self::assertTrue( 'max' === $level || (int) $level >= 5, 'Maintained development PHP requires at least level 5.' );
		self::assertSame( array(), $container->getParameter( 'ignoreErrors' ), 'Development analysis must not hide diagnostics.' );
		$files = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
		$stubs = new FileExcluder( $container->getByType( FileHelper::class ), $container->getParameter( 'stubFiles' ) );
		$files = array_values( array_filter( $files, static fn( string $file ): bool => ! $stubs->isExcludedFromAnalysing( $file ) ) );
		sort( $files );
		return $files;
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

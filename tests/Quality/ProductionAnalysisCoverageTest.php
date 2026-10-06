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
		$shipped   = $this->shipped_php_files();
		$helper    = $container->getByType( FileHelper::class );
		foreach ( array( 'views', 'assets' ) as $directory ) {
			$root     = $this->root() . '/' . $directory;
			$expected = array_values( array_filter( $shipped, static fn( string $file ): bool => str_starts_with( $file, $root . '/' ) ) );
			// @phpstan-ignore phpstanApi.constructor, phpstanApi.constructor, phpstanApi.classConstant (Exercise the locked analyzer's actual exclusion semantics; upgrades must rerun these controls.)
			$finder = new FileFinder( new FileExcluder( $helper, array( $root . '/*' ) ), $helper, $container->getParameter( 'fileExtensions' ), $container->getByType( DirectoryWalker::class ) );
			// @phpstan-ignore phpstanApi.method, phpstanApi.method (Use the locked file selection API to prove an excluded shipping directory becomes a coverage gap.)
			$missing = array_values( array_diff( $shipped, $finder->findFiles( $container->getParameter( 'paths' ) )->getFiles() ) );
			self::assertNotEmpty( $expected );
			self::assertSame( $expected, $missing );
		}
	}

	public function test_an_excluded_shipped_file_fails_the_inventory_comparison(): void {
		$container = $this->analysis_container();
		$shipped   = $this->shipped_php_files();
		$helper    = $container->getByType( FileHelper::class );
		// @phpstan-ignore phpstanApi.constructor, phpstanApi.constructor, phpstanApi.classConstant (Exercise the exact locked analyzer selection implementation rather than an imitation.)
		$finder = new FileFinder( new FileExcluder( $helper, array( $shipped[0] ) ), $helper, $container->getParameter( 'fileExtensions' ), $container->getByType( DirectoryWalker::class ) );
		// @phpstan-ignore phpstanApi.method, phpstanApi.method (Locked analyzer APIs are deliberately covered by this regression suite.)
		$analysed = $finder->findFiles( $container->getParameter( 'paths' ) )->getFiles();

		self::assertSame( array( $shipped[0] ), array_values( array_diff( $shipped, $analysed ) ) );
	}

	public function test_all_maintained_php_is_analysed_or_has_an_exact_historical_exemption(): void {
		$root       = $this->root();
		$exemptions = array(
			'tests/fixtures/provider-api11-registration/workflow-provider.php' => '3a8cc07fafbf8bd5fecc7cf74fee232cd8597be850bfacf052415fa56bb43658',
			'tests/fixtures/provider-api12-registration/repository-provider.php' => '3d4210d301badb765d1fcb041a931431eb8f7382c7fb91546e88560e570612d1',
		);
		foreach ( $exemptions as $path => $hash ) {
			self::assertSame( $hash, hash_file( 'sha256', $root . '/' . $path ), 'Deliberately unloadable historical fixtures require fresh review if their identity changes.' );
		}
		$expected = array_map( static fn( string $path ): string => $root . '/' . $path, array_keys( $exemptions ) );
		$actual   = array();
		foreach ( array( 'phpstan-development.neon', 'phpstan-integration.neon' ) as $profile ) {
			$files = $this->development_analysed_files( $root . '/' . $profile );
			self::assertSame( $expected, array_values( array_diff( $this->development_files( $root ), $files ) ), $profile );
			$actual = $files;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify canonical analysis actually invokes the broad development runner.
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( array( 'phpstan analyse --configuration=phpstan.neon --no-progress --debug --memory-limit=2G', 'Composer\\Config::disableProcessTimeout', '@php scripts/analyze-development.php' ), $composer['scripts']['analyze'] );
		list( $status, $output ) = $this->run_development_runner( $root, array( '--list' ) );
		self::assertSame( 0, $status, $output );
		$profiles = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$selected = array();
		foreach ( $profiles as $profile => $paths ) {
			self::assertContains( $profile, array( 'phpstan-development.neon', 'phpstan-integration.neon' ) );
			foreach ( $paths as $path ) {
				$isolated = 1 === preg_match( '~^' . preg_quote( $root, '~' ) . '/tests/(?:WordPress|fixtures|Integration)/~', $path );
				self::assertSame( $isolated ? 'phpstan-integration.neon' : 'phpstan-development.neon', $profile );
				$selected[] = $path;
			}
		}
		sort( $selected );
		self::assertSame( $actual, $selected );
		$container  = $this->analysis_container();
		$production = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
		self::assertSame( array(), array_values( array_intersect( $production, $actual ) ) );
		$all     = array_merge( $production, $actual, $expected );
		$tracked = $this->root_php_files();
		self::assertSame( array(), array_values( array_diff( $tracked, $all ) ), 'New first-party roots must enter production or development analysis automatically.' );
	}

	public function test_new_development_files_and_body_errors_enter_analysis_automatically(): void {
		$root    = $this->root();
		$fixture = sys_get_temp_dir() . '/ran-core-analysis-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the private analyzer fixture tree.
		self::assertTrue( mkdir( $fixture . '/scripts', 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise a future split under a formerly excluded test role.
		self::assertTrue( mkdir( $fixture . '/tests/Admin', 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Include a future installed-host fixture in the runner's second symbol profile.
		self::assertTrue( mkdir( $fixture . '/tests/WordPress', 0700, true ) );
		try {
			$config = "parameters:\n\tlevel: 5\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Reuse only the locked dependency tree; cleanup removes this link without traversing its target.
			self::assertTrue( symlink( $root . '/vendor', $fixture . '/vendor' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Exercise the actual maintained runner, including its invocation loop, in an isolated root.
			self::assertTrue( copy( $root . '/scripts/analyze-development.php', $fixture . '/scripts/analyze-development.php' ) );
			foreach ( array( 'phpstan-development.neon', 'phpstan-integration.neon' ) as $profile ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Both private profiles are deliberately pathless so the real runner supplies each file.
				file_put_contents( $fixture . '/' . $profile, $config );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert analyzer configuration only within the private fixture.
			file_put_contents( $fixture . '/analysis.neon', $config );
			foreach ( array( 'scripts/New.php', 'tests/NewRoot.php', 'tests/Admin/Split.php', 'tests/WordPress/New.php' ) as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert PHP to prove broad discovery without a file allowlist.
				file_put_contents( $fixture . '/' . $path, '<?php' );
			}
			self::assertSame( $this->development_files( $fixture ), $this->development_analysed_files( $fixture . '/analysis.neon' ) );
			list( $status, $output ) = $this->run_development_runner( $fixture, array( '--list' ) );
			self::assertSame( 0, $status, $output );
			$profiles = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( array( $fixture . '/tests/WordPress/New.php' ), $profiles['phpstan-integration.neon'] );
			self::assertSame( array( $fixture . '/scripts/New.php', $fixture . '/scripts/analyze-development.php', $fixture . '/tests/Admin/Split.php', $fixture . '/tests/NewRoot.php' ), $profiles['phpstan-development.neon'] );
			list( $status, $output ) = $this->run_development_runner( $fixture, array() );
			self::assertSame( 0, $status, $output );
			foreach ( array( 'tests/Admin/Split.php', 'tests/WordPress/New.php' ) as $relative ) {
				$path = $fixture . '/' . $relative;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Each real runner branch must propagate an actual body diagnostic outside the intentional occurrence allowance.
				file_put_contents( $path, "<?php\nfunction ran_core_accepted(): int { return 'intentional'; } // @phpstan-ignore return.type (Synthetic negative control.)\nfunction ran_core_new(): int { return 'invalid'; }\n" );
				list( $status, $output ) = $this->run_development_runner( $fixture, array() );
				self::assertSame( 1, $status, $output );
				self::assertStringContainsString( 'ran_core_new', $output );
				self::assertStringContainsString( 'return.type', $output );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore the private body before exercising the other profile.
				file_put_contents( $path, '<?php' );
			}
			$path = $fixture . '/tests/Admin/Split.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The real locked analyzer must reject an unannotated body violation immediately outside a precise exception.
			file_put_contents( $path, "<?php\nfunction ran_core_accepted(): int { return 'intentional'; } // @phpstan-ignore return.type (Synthetic negative control.)\nfunction ran_core_new(): int { return 'invalid'; }\n" );
			list( $status, $output ) = $this->analyse_development_fixture( $fixture . '/analysis.neon', $path );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'return.type', $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Correcting only the adjacent violation must leave the intended exception accepted.
			file_put_contents( $path, "<?php\nfunction ran_core_accepted(): int { return 'intentional'; } // @phpstan-ignore return.type (Synthetic negative control.)\nfunction ran_core_new(): int { return 1; }\n" );
			list( $status, $output ) = $this->analyse_development_fixture( $fixture . '/analysis.neon', $path );
			self::assertSame( 0, $status, $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The finder must expose a maintained file silently converted into a symbol-only stub.
			file_put_contents( $fixture . '/stub.neon', $config . "\tstubFiles:\n\t\t- tests/Admin/Split.php\n" );
			self::assertNotContains( $path, $this->development_analysed_files( $fixture . '/stub.neon' ) );
		} finally {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this private analyzer fixture, children first.
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty private fixture root.
			rmdir( $fixture );
		}
	}

	public function test_production_profile_weakening_is_rejected_by_the_real_guard(): void {
		$root    = $this->root();
		$fixture = sys_get_temp_dir() . '/ran-production-profile-' . bin2hex( random_bytes( 8 ) );
		$paths   = array();
		try {
			foreach ( array(
				'Production PHP requires at least level 5.' => "\tlevel: 0\n",
				'Production analysis cannot hide diagnostics.' => "\tignoreErrors:\n\t\t- '#unreviewed suppression#'\n",
				$root . '/RAN/Theme.php' => "\tstubFiles:\n\t\t- " . $root . "/RAN/Theme.php\n",
			) as $expected => $mutation ) {
				$path    = $fixture . '-' . count( $paths ) . '.neon';
				$paths[] = $path;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Mutate only a private profile inheriting the real production configuration, never the canonical file.
				file_put_contents( $path, "includes:\n\t- " . $root . "/phpstan.neon\nparameters:\n" . $mutation );
				try {
					$this->analysis_container( $path );
				} catch ( \PHPUnit\Framework\AssertionFailedError $failure ) {
					self::assertStringContainsString( $expected, $failure->getMessage() );
					continue;
				}
				self::fail( 'The production guard accepted a weakened effective configuration.' );
			}
		} finally {
			foreach ( $paths as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private configuration fixture.
				unlink( $path );
			}
		}
	}

	/** @return list<string> */
	private function root_php_files(): array {
		$files    = array();
		$iterator = new \RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $this->root(), FilesystemIterator::SKIP_DOTS ), static fn( \SplFileInfo $file ): bool => ! in_array( $file->getFilename(), array( '.git', 'vendor', 'node_modules', 'ran-booster-workbench', '.phpunit.cache', '.plugin-check', 'coverage' ), true ) );
		foreach ( new RecursiveIteratorIterator( $iterator ) as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = str_replace( '\\', '/', $file->getPathname() );
			}
		}
		sort( $files );
		return $files;
	}

	/** @param list<string> $arguments
	 * @return array{int, string}
	 */
	private function run_development_runner( string $root, array $arguments ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Query only the maintained runner's real selection; do not execute analyzed fixtures.
		$process = proc_open(
			array( PHP_BINARY, $root . '/scripts/analyze-development.php', ...$arguments ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only the runner stdout pipe owned by this probe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only the runner stderr pipe owned by this probe.
		fclose( $pipes[2] );
		return array( proc_close( $process ), $output );
	}

	/** @return array{int, string} */
	private function analyse_development_fixture( string $config, string $file ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Invoke only the locked local analyzer against the private fixture to prove actual body diagnostics.
		$process = proc_open(
			array( PHP_BINARY, $this->root() . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--error-format=json', $file ),
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
		self::assertSame( array(), $container->getParameter( 'paths' ), 'Only the runner may select the per-file analysis target.' );
		$files = $container->getService( 'fileFinderAnalyse' )->findFiles( array( dirname( $config ) . '/scripts', dirname( $config ) . '/tests' ) )->getFiles();
		// @phpstan-ignore phpstanApi.constructor (Match the locked analyzer's stub exclusion semantics in the coverage guard.)
		$stubs = new FileExcluder( $container->getByType( FileHelper::class ), $container->getParameter( 'stubFiles' ) );
		// @phpstan-ignore phpstanApi.method (Use actual locked stub matching so analyzed coverage cannot count symbol-only files.)
		$files = array_values( array_filter( $files, static fn( string $file ): bool => ! $stubs->isExcludedFromAnalysing( $file ) ) );
		sort( $files );
		return $files;
	}

	private function analysis_container( ?string $config = null ): Container {
		$container = ( new ContainerFactory( $this->root() ) )->create(
			$this->root() . '/.phpunit.cache/analysis-coverage',
			array( $config ?? $this->root() . '/phpstan.neon' ),
			array()
		);
		$level     = $container->getParameter( 'level' );
		self::assertTrue( 'max' === $level || (int) $level >= 5, 'Production PHP requires at least level 5.' );
		self::assertSame( array(), $container->getParameter( 'ignoreErrors' ), 'Production analysis cannot hide diagnostics.' );
		// @phpstan-ignore phpstanApi.constructor (Use the locked analyzer's real stub matching instead of counting scanned declarations as analyzed bodies.)
		$stubs = new FileExcluder( $container->getByType( FileHelper::class ), $container->getParameter( 'stubFiles' ) );
		foreach ( $this->root_php_files() as $path ) {
			// @phpstan-ignore phpstanApi.method (Guard every maintained file against a silent symbol-only stub exemption.)
			self::assertFalse( $stubs->isExcludedFromAnalysing( $path ), $path );
		}
		return $container;
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

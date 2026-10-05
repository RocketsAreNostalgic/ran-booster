<?php

declare(strict_types=1);

namespace Tests\Quality;

use PHPUnit\Framework\TestCase;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Runner;

final class StandardsCoverageTest extends TestCase {

	public function test_owned_php_cannot_disable_every_standard(): void {
		$paths = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		foreach ( $paths as $path ) {
			$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only local owned source; never load or execute the fixture.
			self::assertIsString( $source );
			self::assertFalse( $this->has_blanket_suppression( $source ), $path . ' must identify the specific rule and reason instead of disabling all standards.' );
		}
	}

	public function test_canonical_commands_keep_the_inventory_checker_scope(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local command contract; do not execute manifest content.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' );
		self::assertIsString( $source );
		$manifest = json_decode( $source, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'phpcs --standard=.phpcs.xml --report=summary', $manifest['scripts']['standards'] ?? null, 'Command changes must preserve the real-checker inventory contract; extra paths or ignore arguments cannot silently narrow it.' );
		self::assertSame( 'phpcbf --standard=.phpcs.xml --report=summary', $manifest['scripts']['standards:fix'] ?? null, 'Check and fix must keep the same reviewed selection.' );
	}

	public function test_every_tracked_php_file_is_selected_by_the_real_checker(): void {
		$tracked  = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		$selected = $this->selected_php_files();
		self::assertNotEmpty( $tracked );
		self::assertSame( array(), array_values( array_diff( $tracked, $selected ) ), 'Every tracked PHP file needs standards coverage; a new exclusion requires explicit policy review.' );
	}

	public function test_a_checker_exclusion_exposes_the_omitted_owned_file(): void {
		$tracked  = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		$selected = $this->selected_php_files( array( '--ignore=*/RAN/Theme.php' ) );
		self::assertSame( array( dirname( __DIR__, 2 ) . '/RAN/Theme.php' ), array_values( array_diff( $tracked, $selected ) ) );
	}

	public function test_a_new_tracked_root_reaches_discovery_and_the_blanket_guard(): void {
		$root      = sys_get_temp_dir() . '/ran-standards-' . bin2hex( random_bytes( 8 ) );
		$directory = $root . '/new-tooling';
		$path      = $directory . '/probe.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an isolated CLI-only Git inventory fixture.
		self::assertTrue( mkdir( $directory, 0700, true ) );
		try {
			$source = "<?php\n// phpcs:disable\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the isolated inventory fixture; it is never executed.
			self::assertSame( strlen( $source ), file_put_contents( $path, $source ) );
			$this->git_output( $root, array( 'init', '--quiet' ) );
			$this->git_output( $root, array( 'add', '--', 'new-tooling/probe.php' ) );
			$tracked = $this->tracked_php_files( $root );
			self::assertSame( array( $path ), $tracked );
			self::assertContains( $path, $this->selected_php_files( array( $directory ) ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the inert fixture through the same discovered path used by the guard.
			$discovered = file_get_contents( $tracked[0] );
			self::assertIsString( $discovered );
			self::assertTrue( $this->has_blanket_suppression( $discovered ) );
		} finally {
			// This unique fixture contains only files created above and by Git init/add.
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the isolated CLI fixture tree.
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty fixture root.
			rmdir( $root );
		}
	}

	/** @return list<string> */
	private function tracked_php_files( string $root ): array {
		$output = $this->git_output( $root, array( 'ls-files', '-z', '--', '*.php' ) );
		$paths  = array_map( static fn( string $path ): string => $root . '/' . $path, array_filter( explode( "\0", $output ), static fn( string $path ): bool => '' !== $path ) );
		sort( $paths );
		return $paths;
	}

	/** @param list<string> $arguments */
	private function git_output( string $root, array $arguments ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Read tracked CLI inventory or prepare its isolated negative fixture without invoking a shell.
		$process = proc_open(
			array( 'git', '-C', $root, ...$arguments ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child input pipe; Git reads no input.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child output pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child error pipe.
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), (string) $error );
		self::assertIsString( $output );
		return $output;
	}

	/**
	 * @param list<string> $arguments
	 * @return list<string>
	 */
	private function selected_php_files( array $arguments = array() ): array {
		$root = dirname( __DIR__, 2 );
		require_once $root . '/vendor/squizlabs/php_codesniffer/autoload.php';
		$runner         = new Runner();
		$runner->config = new Config( array( '--standard=' . $root . '/.phpcs.xml', ...$arguments ) );
		$runner->init();
		$paths = array();
		foreach ( new FileList( $runner->config, $runner->ruleset ) as $path => $file ) {
			$paths[] = $path;
		}
		sort( $paths );
		return $paths;
	}

	public function test_blanket_guard_distinguishes_annotations_from_fixture_strings(): void {
		foreach ( array( '// phpcs:disable', '// phpcs:disable -- fixture', '// phpcs:ignore', '// phpcs:ignoreFile -- fixture', "/**\n * @codingStandardsIgnoreStart\n */", '/** @codingStandardsIgnoreFile */', '/** @codingStandardsIgnoreLine */' ) as $annotation ) {
			self::assertTrue( $this->has_blanket_suppression( "<?php\n" . $annotation . "\n" ) );
		}
		self::assertFalse( $this->has_blanket_suppression( "<?php\n// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Required evaluation order.\n" ) );
		self::assertFalse( $this->has_blanket_suppression( '<?php $fixture = "// phpcs:disable";' ) );
		self::assertFalse( $this->has_blanket_suppression( '<?php $fixture = "/* @codingStandardsIgnoreFile */";' ) );
	}

	public function test_legacy_and_block_suppressions_cannot_hide_checker_findings(): void {
		$probe  = 'function ran_booster_probe( $unused, $value ) { return $value === 1; }';
		$result = $this->inspect( "<?php\n" . $probe, 'RAN/NewQualityProbe.php' );
		self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $result['sources'] );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed', $result['sources'] );
		foreach ( array( '@codingStandardsIgnoreStart', '@codingStandardsIgnoreFile', '@codingStandardsIgnoreLine', 'phpcs:disable', 'phpcs:ignoreFile', 'phpcs:ignore' ) as $directive ) {
			foreach ( array( '// ' . $directive, '/* ' . $directive . ' */' ) as $annotation ) {
				$source = "<?php\n" . $annotation . "\n" . $probe;
				$result = $this->inspect( $source, 'RAN/NewQualityProbe.php' );
				self::assertSame( array(), $result['sources'], $annotation . ' must demonstrate a real checker suppression.' );
				self::assertTrue( $this->has_blanket_suppression( $source ), $annotation . ' must still fail the independent blanket guard.' );
			}
		}
	}

	private function has_blanket_suppression( string $source ): bool {
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
				&& 1 === preg_match( '/@codingStandardsIgnore(?:Start|File|Line)|phpcs:(?:ignoreFile\b|(?:disable|ignore)(?:\s*(?:--[^\r\n]*)?\s*(?:\*\/)?\s*$))/m', $token[1] ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_new_owned_paths_cannot_escape_condition_and_parameter_checks(): void {
		$source = '<?php function ran_booster_probe( $unused, $value ) { return $value === 1; }';
		foreach ( array( 'RAN/NewQualityProbe.php', 'views/new-quality-probe.php', 'scripts/new-quality-probe.php', 'tests/Quality/NewQualityProbe.php', 'new-quality-probe.php' ) as $path ) {
			$result = $this->inspect( $source, $path );
			self::assertNotSame( 0, $result['exit'], $path );
			self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $result['sources'], $path );
			self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed', $result['sources'], $path );
		}
	}

	public function test_an_inherited_class_does_not_hide_an_unused_private_parameter(): void {
		$result = $this->inspect( '<?php class Probe extends ParentProbe { private function owned_helper( $unused ) { return true; } }', 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass', $result['sources'] );
	}

	public function test_new_owned_paths_check_reserved_parameter_names(): void {
		$result = $this->inspect( '<?php function ran_booster_probe( $default ) { return $default; }', 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertContains( 'Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound', $result['sources'] );
	}

	public function test_a_signature_exception_does_not_hide_the_next_declaration(): void {
		$source = "<?php\n// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required external callback signature.\nfunction ran_booster_callback( \$unused ) { return true; }\nfunction ran_booster_helper( \$unused ) { return true; }\n";
		$result = $this->inspect( $source, 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertSame( array( 'Generic.CodeAnalysis.UnusedFunctionParameter.Found' ), $result['sources'] );
		self::assertSame( array( 4 ), $result['lines'] );
	}

	public function test_native_boundary_exceptions_do_not_cover_new_operations(): void {
		foreach ( array(
			'RAN/Secrets/SecretsFile.php',
			'RAN/Secrets/EncryptedSecretsEnvelopeCodec.php',
			'RAN/Secrets/PrivateLocationCandidateResolver.php',
			'RAN/Secrets/SecretsStorageProvisioner.php',
			'RAN/Secrets/PosixFilesystemProbe.php',
			'RAN/Secrets/WpConfigSecretsPathWriter.php',
			'RAN/Logging/TemporaryDebugCapture.php',
			'RAN/Uninstall/LocalDataRemover.php',
			'RAN/Troubleshooting/LocalTroubleshootingService.php',
		) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read actual local exception scope into an inert checker fixture.
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertIsString( $source );
			$source .= "\nfunction ran_booster_unrelated_read() { return file_get_contents( 'unrelated-fixture' ); }\n";
			$result  = $this->inspect( $source, $path );

			self::assertNotSame( 0, $result['exit'], $path );
			self::assertSame( array( 'WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents' ), $result['sources'], $path );
		}
	}

	public function test_a_compliant_new_file_passes_the_same_rules(): void {
		$result = $this->inspect( '<?php function ran_booster_probe( $value ) { return 1 === $value; }', 'RAN/NewQualityProbe.php' );
		self::assertSame( 0, $result['exit'] );
		self::assertSame( array(), $result['sources'] );
	}

	/** @return array{exit: int, sources: list<string>, lines: list<int>} */
	private function inspect( string $source, string $path ): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the locked checker in isolation; these are CLI-only enforcement controls.
		$process = proc_open(
			array(
				PHP_BINARY,
				$root . '/vendor/bin/phpcs',
				'--standard=' . $root . '/.phpcs.xml',
				'--sniffs=WordPress.PHP.YodaConditions,Generic.CodeAnalysis.UnusedFunctionParameter,Universal.NamingConventions.NoReservedKeywordParameterNames,WordPress.WP.AlternativeFunctions',
				'--report=json',
				'--no-colors',
				'--parallel=1',
				'--stdin-path=' . $root . '/' . $path,
				'-q',
				'-',
			),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		fwrite( $pipes[0], $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Feed only an in-memory fixture to the locked CLI checker.
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker input pipe.
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker output pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker error pipe.
		$exit = proc_close( $process );
		self::assertSame( '', $error );
		$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertArrayHasKey( 'files', $report );
		$sources = array();
		$lines   = array();
		foreach ( $report['files'] as $file ) {
			foreach ( $file['messages'] as $message ) {
				$sources[] = $message['source'];
				$lines[]   = $message['line'];
			}
		}
		return array(
			'exit'    => $exit,
			'sources' => $sources,
			'lines'   => $lines,
		);
	}
}

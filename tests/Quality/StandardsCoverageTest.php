<?php

declare(strict_types=1);

namespace Tests\Quality;

use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class StandardsCoverageTest extends TestCase {

	public function test_owned_php_cannot_disable_every_standard(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = glob( $root . '/*.php' );
		self::assertIsArray( $paths );
		foreach ( array( 'RAN', 'views', 'assets', 'scripts', 'tests' ) as $directory ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$paths[] = $file->getPathname();
				}
			}
		}
		foreach ( $paths as $path ) {
			$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only local owned source; never load or execute the fixture.
			self::assertIsString( $source );
			self::assertFalse( $this->has_blanket_suppression( $source ), $path . ' must identify the specific rule and reason instead of disabling all standards.' );
		}
	}

	public function test_blanket_guard_distinguishes_annotations_from_fixture_strings(): void {
		foreach ( array( '// phpcs:disable', '// phpcs:disable -- fixture', '// phpcs:ignore', '// phpcs:ignoreFile -- fixture' ) as $annotation ) {
			self::assertTrue( $this->has_blanket_suppression( "<?php\n" . $annotation . "\n" ) );
		}
		self::assertFalse( $this->has_blanket_suppression( "<?php\n// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Required evaluation order.\n" ) );
		self::assertFalse( $this->has_blanket_suppression( '<?php $fixture = "// phpcs:disable";' ) );
	}

	private function has_blanket_suppression( string $source ): bool {
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
				&& 1 === preg_match( '/phpcs:(?:ignoreFile\b|(?:disable|ignore)(?:\s*(?:--[^\r\n]*)?\s*(?:\*\/)?\s*$))/m', $token[1] ) ) {
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
				'--sniffs=WordPress.PHP.YodaConditions,Generic.CodeAnalysis.UnusedFunctionParameter,Universal.NamingConventions.NoReservedKeywordParameterNames',
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

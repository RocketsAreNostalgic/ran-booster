<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

// CLI-only release contract tests intentionally use direct local file/process primitives.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

final class RuntimePackagingPolicyNegativeTest extends TestCase {
	public function testVerifierRejectsDotSegmentPackageNames(): void {
		foreach ( array( 'ran/.', 'ran/..' ) as $packageName ) {
			$policy = $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
			self::assertIsArray( $policy['packages'][0] ?? null );
			$policy['packages'][0]['name']         = $packageName;
			$policy['packages'][0]['archive_root'] = 'vendor/' . $packageName;
			$policyPath = $this->writeTemporaryJson( $policy );

			try {
				$result = $this->runVerifier( dirname( __DIR__ ) . '/composer.lock', $policyPath );
				self::assertNotSame( 0, $result['exit'] );
				self::assertStringContainsString( 'package record is invalid', $result['stderr'] );
			} finally {
				$this->removeTemporaryFile( $policyPath );
			}
		}
	}

	public function testVerifierRejectsMalformedSemVerVersions(): void {
		foreach ( array(
			'v01.2.3',
			'v1.2.3-.alpha',
			'v1.2.3-alpha..1',
			'v1.2.3-01',
			'v1.2.3+meta..x',
		) as $version ) {
			$lock = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
			self::assertIsArray( $lock['packages'][0] ?? null );
			$lock['packages'][0]['version'] = $version;
			$lockPath = $this->writeTemporaryJson( $lock );

			try {
				$result = $this->runVerifier( $lockPath, dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
				self::assertNotSame( 0, $result['exit'] );
				self::assertStringContainsString( 'identity is malformed', $result['stderr'] );
			} finally {
				$this->removeTemporaryFile( $lockPath );
			}
		}
	}

	public function testVerifierAcceptsSemVerBuildMetadata(): void {
		$lock = $this->readJson( dirname( __DIR__ ) . '/composer.lock' );
		self::assertIsArray( $lock['packages'][0] ?? null );
		$lock['packages'][0]['version'] = 'v1.2.3+dist.1';
		$lockPath = $this->writeTemporaryJson( $lock );

		try {
			$result = $this->runVerifier( $lockPath, dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
			self::assertSame( 0, $result['exit'], $result['stderr'] );
		} finally {
			$this->removeTemporaryFile( $lockPath );
		}
	}

	public function testVerifierRejectsInvalidSurfaceKind(): void {
		$policy = $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		self::assertIsArray( $policy['packages'][0]['surfaces'][0] ?? null );
		$policy['packages'][0]['surfaces'][0]['kind'] = 'blob';
		$policyPath = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runVerifier( dirname( __DIR__ ) . '/composer.lock', $policyPath );
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'invalid top-level surface', $result['stderr'] );
		} finally {
			$this->removeTemporaryFile( $policyPath );
		}
	}

	public function testInstalledSurfaceValidationAcceptsAValidFixture(): void {
		$policy = $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->createInstalledFixture( $policy );

		try {
			$result = $this->runVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertSame( 0, $result['exit'], $result['stderr'] );
		} finally {
			$this->removeTree( $root );
		}
	}

	public function testInstalledSurfaceValidationRejectsWrongKind(): void {
		$policy = $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->createInstalledFixture( $policy );
		$target = $root . '/vendor/ran/updater-support/LICENSE';

		try {
			unlink( $target );
			mkdir( $target );
			$result = $this->runVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'file surface is missing or changed kind', $result['stderr'] );
		} finally {
			$this->removeTree( $root );
		}
	}

	public function testInstalledSurfaceValidationRejectsEmptyNestedDirectory(): void {
		$policy = $this->readJson( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->createInstalledFixture( $policy );
		$empty  = $root . '/vendor/ran/updater-support/src/empty-subdirectory';

		try {
			mkdir( $empty );
			$result = $this->runVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'contains an empty directory', $result['stderr'] );
		} finally {
			$this->removeTree( $root );
		}
	}

	/**
	 * @param array<string, mixed> $policy
	 */
	private function createInstalledFixture( array $policy ): string {
		$root = sys_get_temp_dir() . '/ran-booster-runtime-install-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0777, true ) );

		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$archiveRoot = $record['archive_root'] ?? null;
			self::assertIsString( $archiveRoot );
			$packageRoot = $root . '/' . $archiveRoot;
			self::assertTrue( mkdir( $packageRoot, 0777, true ) );

			foreach ( $record['surfaces'] as $surface ) {
				self::assertIsArray( $surface );
				$path = $surface['path'] ?? null;
				$kind = $surface['kind'] ?? null;
				self::assertIsString( $path );
				self::assertIsString( $kind );
				$surfacePath = $packageRoot . '/' . $path;
				if ( 'file' === $kind ) {
					self::assertIsInt( file_put_contents( $surfacePath, "fixture\\n" ) );
					continue;
				}
				self::assertTrue( mkdir( $surfacePath, 0777, true ) );
				self::assertIsInt( file_put_contents( $surfacePath . '/fixture.txt', "fixture\\n" ) );
			}
		}

		return $root;
	}

	/**
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function runVerifier( string $lockPath, string $policyPath, ?string $installedRoot = null ): array {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/scripts/verify-runtime-dependencies.php' );
		if ( null !== $installedRoot ) {
			$command[] = '--verify-install';
			$command[] = $installedRoot;
		}
		$command[] = $lockPath;
		$command[] = $policyPath;

		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		self::assertIsString( $stdout );
		self::assertIsString( $stderr );
		return array( 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readJson( string $path ): array {
		$document = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $document );
		return $document;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function writeTemporaryJson( array $document ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-runtime-negative-' );
		self::assertIsString( $path );
		$bytes = file_put_contents(
			$path,
			json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR )
		);
		self::assertIsInt( $bytes );
		return $path;
	}

	private function removeTemporaryFile( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		self::assertIsArray( $entries );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->removeTree( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}

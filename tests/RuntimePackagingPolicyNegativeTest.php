<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;

// CLI-only release contract tests intentionally use direct local file/process primitives.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

final class RuntimePackagingPolicyNegativeTest extends TestCase {
	public function test_verifier_rejects_dot_segment_package_names(): void {
		foreach ( array( 'ran/.', 'ran/..' ) as $package_name ) {
			$policy = $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
			self::assertIsArray( $policy['packages'][0] ?? null );
			$policy['packages'][0]['name']         = $package_name;
			$policy['packages'][0]['archive_root'] = 'vendor/' . $package_name;
			$policy_path                           = $this->write_temporary_json( $policy );

			try {
				$result = $this->run_verifier( dirname( __DIR__ ) . '/composer.lock', $policy_path );
				self::assertNotSame( 0, $result['exit'] );
				self::assertStringContainsString( 'package record is invalid', $result['stderr'] );
			} finally {
				$this->remove_temporary_file( $policy_path );
			}
		}
	}

	public function test_verifier_rejects_malformed_sem_ver_versions(): void {
		foreach ( array(
			'v01.2.3',
			'v1.2.3-.alpha',
			'v1.2.3-alpha..1',
			'v1.2.3-01',
			'v1.2.3+meta..x',
		) as $version ) {
			$lock = $this->read_json( dirname( __DIR__ ) . '/composer.lock' );
			self::assertIsArray( $lock['packages'][0] ?? null );
			$lock['packages'][0]['version'] = $version;
			$lock_path                      = $this->write_temporary_json( $lock );

			try {
				$result = $this->run_verifier( $lock_path, dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
				self::assertNotSame( 0, $result['exit'] );
				self::assertStringContainsString( 'identity is malformed', $result['stderr'] );
			} finally {
				$this->remove_temporary_file( $lock_path );
			}
		}
	}

	public function test_verifier_accepts_sem_ver_build_metadata(): void {
		$lock = $this->read_json( dirname( __DIR__ ) . '/composer.lock' );
		self::assertIsArray( $lock['packages'][0] ?? null );
		$lock['packages'][0]['version'] = 'v1.2.3+dist.1';
		$lock_path                      = $this->write_temporary_json( $lock );

		try {
			$result = $this->run_verifier( $lock_path, dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
			self::assertSame( 0, $result['exit'], $result['stderr'] );
		} finally {
			$this->remove_temporary_file( $lock_path );
		}
	}

	public function test_verifier_rejects_invalid_surface_kind(): void {
		$policy = $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		self::assertIsArray( $policy['packages'][0]['surfaces'][0] ?? null );
		$policy['packages'][0]['surfaces'][0]['kind'] = 'blob';
		$policy_path                                  = $this->write_temporary_json( $policy );

		try {
			$result = $this->run_verifier( dirname( __DIR__ ) . '/composer.lock', $policy_path );
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'invalid top-level surface', $result['stderr'] );
		} finally {
			$this->remove_temporary_file( $policy_path );
		}
	}

	public function test_installed_surface_validation_accepts_avalid_fixture(): void {
		$policy = $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->create_installed_fixture( $policy );

		try {
			$result = $this->run_verifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertSame( 0, $result['exit'], $result['stderr'] );
		} finally {
			$this->remove_tree( $root );
		}
	}

	public function test_installed_surface_validation_rejects_wrong_kind(): void {
		$policy = $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->create_installed_fixture( $policy );
		$target = $root . '/vendor/ran/updater-support/LICENSE';

		try {
			unlink( $target );
			mkdir( $target );
			$result = $this->run_verifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'file surface is missing or changed kind', $result['stderr'] );
		} finally {
			$this->remove_tree( $root );
		}
	}

	public function test_installed_surface_validation_rejects_empty_nested_directory(): void {
		$policy = $this->read_json( dirname( __DIR__ ) . '/runtime-packaging-policy.json' );
		$root   = $this->create_installed_fixture( $policy );
		$empty  = $root . '/vendor/ran/updater-support/src/empty-subdirectory';

		try {
			mkdir( $empty );
			$result = $this->run_verifier(
				dirname( __DIR__ ) . '/composer.lock',
				dirname( __DIR__ ) . '/runtime-packaging-policy.json',
				$root
			);
			self::assertNotSame( 0, $result['exit'] );
			self::assertStringContainsString( 'contains an empty directory', $result['stderr'] );
		} finally {
			$this->remove_tree( $root );
		}
	}

	/**
	 * @param array<string, mixed> $policy
	 */
	private function create_installed_fixture( array $policy ): string {
		$root = sys_get_temp_dir() . '/ran-booster-runtime-install-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0777, true ) );

		foreach ( $policy['packages'] as $record ) {
			self::assertIsArray( $record );
			$archive_root = $record['archive_root'] ?? null;
			self::assertIsString( $archive_root );
			$package_root = $root . '/' . $archive_root;
			self::assertTrue( mkdir( $package_root, 0777, true ) );

			foreach ( $record['surfaces'] as $surface ) {
				self::assertIsArray( $surface );
				$path = $surface['path'] ?? null;
				$kind = $surface['kind'] ?? null;
				self::assertIsString( $path );
				self::assertIsString( $kind );
				$surface_path = $package_root . '/' . $path;
				if ( 'file' === $kind ) {
					self::assertIsInt( file_put_contents( $surface_path, "fixture\\n" ) );
					continue;
				}
				self::assertTrue( mkdir( $surface_path, 0777, true ) );
				self::assertIsInt( file_put_contents( $surface_path . '/fixture.txt', "fixture\\n" ) );
			}
		}

		return $root;
	}

	/**
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function run_verifier( string $lock_path, string $policy_path, ?string $installed_root = null ): array {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/scripts/verify-runtime-dependencies.php' );
		if ( null !== $installed_root ) {
			$command[] = '--verify-install';
			$command[] = $installed_root;
		}
		$command[] = $lock_path;
		$command[] = $policy_path;

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
		return array(
			'exit'   => $exit,
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_json( string $path ): array {
		$document = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $document );
		return $document;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function write_temporary_json( array $document ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-runtime-negative-' );
		self::assertIsString( $path );
		$bytes = file_put_contents(
			$path,
			json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR )
		);
		self::assertIsInt( $bytes );
		return $path;
	}

	private function remove_temporary_file( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	private function remove_tree( string $path ): void {
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
			$this->remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}

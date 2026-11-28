<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ExtensionInstaller;
use RAN\PackageArtifactLimit;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use ZipArchive;

final class ExtensionInstallerArchiveTest extends TestCase {
	private string $directory;
	private ExtensionInstaller $installer;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-ext-unit-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture custody and cleanup.
		mkdir( $this->directory, 0700 );
		$this->installer = ( new ReflectionClass( ExtensionInstaller::class ) )->newInstanceWithoutConstructor();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only fixture custody and cleanup.
				rmdir( $entry->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fixture custody and cleanup.
				unlink( $entry->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only fixture custody and cleanup.
		rmdir( $this->directory );
	}

	public function test_archive_validates_header_and_paths_and_readback_rejects_extra_files(): void {
		$archive = $this->zip(
			array(
				'example/example.php'  => "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n",
				'example/sub/file.php' => 'content',
			)
		);
		$files   = $this->archive_files( $archive );
		self::assertCount( 2, $files );
		$destination = $this->directory . '/installed';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture custody and cleanup.
		mkdir( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture custody and cleanup.
		mkdir( $destination . '/sub' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
		file_put_contents( $destination . '/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
		file_put_contents( $destination . '/sub/file.php', 'content' );
		self::assertTrue( $this->matches_installed( $destination, $files ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
		file_put_contents( $destination . '/extra.php', 'extra' );
		self::assertFalse( $this->matches_installed( $destination, $files ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fixture custody and cleanup.
		unlink( $destination . '/extra.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
		file_put_contents( $destination . '/sub/file.php', 'tampered' );
		self::assertFalse( $this->matches_installed( $destination, $files ) );
	}

	public function test_expanded_member_total_above_the_old_cap_obeys_the_configured_boundary(): void {
		$header  = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		$payload = str_repeat( 'a', 17 * 1024 * 1024 );
		$archive = $this->zip(
			array(
				'example/example.php'      => $header,
				'example/assets/large.txt' => $payload,
			),
			static function ( ZipArchive $zip ): void {
				self::assertTrue( $zip->setCompressionName( 'example/assets/large.txt', ZipArchive::CM_STORE ) );
			}
		);
		$total   = strlen( $header ) + strlen( $payload );

		self::assertSame( hash( 'sha256', $payload ), $this->archive_files( $archive, $total )['assets/large.txt'] );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Release archive is too large.' );
		$this->archive_files( $archive, $total - 1 );
	}

	public function test_installed_relative_path_uses_platform_separators(): void {
		$method = new ReflectionMethod( ExtensionInstaller::class, 'installed_relative_path' );
		self::assertSame(
			'assets/admin/file.css',
			$method->invoke( $this->installer, 'C:\plugins/example', 'C:\plugins/example\assets\admin\file.css', '\\' )
		);
		self::assertSame(
			'assets\admin/file.css',
			$method->invoke( $this->installer, '/plugins/example', '/plugins/example/assets\admin/file.css', '/' )
		);
	}

	/**
	 * @param array<string, string>         $entries
	 * @param (callable(ZipArchive): void)|null $mutate
	 */
	#[DataProvider( 'invalid_archive_provider' )]
	public function test_rejects_invalid_archive( array $entries, ?callable $mutate = null ): void {
		$this->expectException( RuntimeException::class );
		$this->archive_files( $this->zip( $entries, $mutate ) );
	}

	/** @return array<string, array{0: array<string, string>, 1?: (callable(ZipArchive): void)|null}> */
	public static function invalid_archive_provider(): array {
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		return array(
			'path traversal'         => array(
				array(
					'example/example.php'   => $header,
					'example/../escape.php' => 'bad',
				),
			),
			'unsafe control name'    => array(
				array(
					'example/example.php'     => $header,
					"example/control\x07.php" => 'bad',
				),
			),
			'unsafe reserved name'   => array(
				array(
					'example/example.php' => $header,
					'example/con.php'     => 'bad',
				),
			),
			'unsafe trailing dot'    => array(
				array(
					'example/example.php'      => $header,
					'example/dir./invalid.php' => 'bad',
				),
			),
			'case-only duplicate'    => array(
				array(
					'example/example.php' => $header,
					'example/File.php'    => 'first',
					'example/file.php'    => 'second',
				),
			),
			'unix symlink entry'     => array(
				array(
					'example/example.php' => $header,
					'example/link.php'    => 'target',
				),
				static function ( ZipArchive $zip ): void {
					self::assertTrue( $zip->setExternalAttributesName( 'example/link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16 ) );
				},
			),
			'unix mode mismatch'     => array(
				array(
					'example/example.php' => $header,
					'example/file.php'    => 'content',
				),
				static function ( ZipArchive $zip ): void {
					self::assertTrue( $zip->setExternalAttributesName( 'example/file.php', ZipArchive::OPSYS_UNIX, 0040755 << 16 ) );
				},
			),
			'unsupported entry os'   => array(
				array(
					'example/example.php' => $header,
					'example/file.php'    => 'content',
				),
				static function ( ZipArchive $zip ): void {
					self::assertTrue( $zip->setExternalAttributesName( 'example/file.php', 7, 0 ) );
				},
			),
			'ntfs reparse entry'     => array(
				array(
					'example/example.php' => $header,
					'example/file.php'    => 'content',
				),
				static function ( ZipArchive $zip ): void {
					self::assertTrue( $zip->setExternalAttributesName( 'example/file.php', 10, 0x0400 ) );
				},
			),
			'encrypted entry'        => array(
				array(
					'example/example.php' => $header,
					'example/secret.php'  => 'secret',
				),
				static function ( ZipArchive $zip ): void {
					self::assertTrue( $zip->setEncryptionName( 'example/secret.php', ZipArchive::EM_AES_256, 'secret' ) );
				},
			),
			'high compression ratio' => array(
				array(
					'example/example.php' => $header,
					'example/bomb.txt'    => str_repeat( 'a', 131072 ),
				),
			),
			'wrong root'             => array( array( 'other/example.php' => $header ) ),
			'wrong version'          => array( array( 'example/example.php' => str_replace( '1.2.3', '1.2.4', $header ) ) ),
			'missing header'         => array( array( 'example/example.php' => '<?php' ) ),
		);
	}

	/**
	 * @param array<string, string>             $entries
	 * @param (callable(ZipArchive): void)|null $mutate
	 */
	private function zip( array $entries, ?callable $mutate = null ): string {
		$path = $this->directory . '/fixture.zip';
		$zip  = new ZipArchive();
		self::assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $content ) {
			self::assertTrue( $zip->addFromString( $name, $content ) );
		}
		if ( null !== $mutate ) {
			$mutate( $zip );
		}
		self::assertTrue( $zip->close() );
		return $path;
	}

	/** @return array<string, string> */
	private function archive_files( string $path, int $maximum_bytes = PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES ): array {
		$method = new ReflectionMethod( ExtensionInstaller::class, 'archive_files' );
		return $method->invoke(
			$this->installer,
			$path,
			array(
				'plugin'          => 'example/example.php',
				'release_version' => '1.2.3',
			),
			$maximum_bytes
		);
	}

	/** @param array<string, string> $files */
	private function matches_installed( string $destination, array $files ): bool {
		$method = new ReflectionMethod( ExtensionInstaller::class, 'installed_matches' );
		return $method->invoke( $this->installer, $destination, $files );
	}
}

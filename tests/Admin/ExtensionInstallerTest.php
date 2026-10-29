<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ExtensionInstaller;
use RAN\Deployment\PreparedArtifact;
use RAN\WordPress\CorePackageExecutionResult;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use ZipArchive;


#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ExtensionInstallerTest extends TestCase {
	private string $directory;
	private string $zip;
	/** @var array<string, string|bool> */
	private array $entry;
	private FakeExtensionLock $lock;
	private FakeExtensionExecutor $executor;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		require_once __DIR__ . '/ExtensionInstallerWordPressFunctions.php';
		$this->directory = sys_get_temp_dir() . '/ran-ext-installer-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture custody and cleanup.
		mkdir( $this->directory, 0700 );
		define( 'WP_PLUGIN_DIR', $this->directory );
		$this->zip = $this->directory . '/fixture.zip';
		$zip       = new ZipArchive();
		self::assertTrue( $zip->open( $this->zip, ZipArchive::CREATE ) );
		self::assertTrue( $zip->addFromString( 'example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" ) );
		self::assertTrue( $zip->close() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only fixture custody and cleanup.
		$GLOBALS['ran_booster_extension_test_download'] = file_get_contents( $this->zip );
		$GLOBALS['ran_booster_extension_test_plugins']  = array();
		$this->entry                                    = array(
			'id'              => 'example',
			'plugin'          => 'example/example.php',
			'release_version' => '1.2.3',
			'asset_url'       => 'https://github.com/owner/repo/releases/download/v1.2.3/example.zip',
			'asset_sha256'    => hash_file( 'sha256', $this->zip ),
			'compatible'      => true,
			'state'           => 'Not installed',
		);
		$this->lock                                     = new FakeExtensionLock();
		$this->executor                                 = new FakeExtensionExecutor();
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
				unlink( $entry->getPathname() ); }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only fixture custody and cleanup.
		rmdir( $this->directory );
	}

	public function test_success_installs_exact_bytes_inactive_and_cleans_temp(): void {
		$this->executor->install                       = true;
		$GLOBALS['ran_booster_extension_test_plugins'] = array( 'example/example.php' => array( 'Version' => '1.2.3' ) );
		$before                                        = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertSame( 'installed', $this->installer()->install( 'example' ) );
		self::assertSame( 1, $this->executor->calls );
		self::assertSame( 1, $this->lock->acquires );
		self::assertSame( $before, glob( sys_get_temp_dir() . '/ran-extension-*' ) );
		self::assertFileExists( WP_PLUGIN_DIR . '/example/example.php' );
	}

	public function test_tampered_download_fails_before_mutation_and_cleans_temp(): void {
		$GLOBALS['ran_booster_extension_test_download'] = 'modified';
		$before = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertSame( 'failed', $this->installer()->install( 'example' ) );
		self::assertSame( 0, $this->executor->calls );
		self::assertSame( 0, $this->lock->acquires );
		self::assertSame( $before, glob( sys_get_temp_dir() . '/ran-extension-*' ) );
	}

	public function test_existing_unmanaged_directory_is_not_overwritten(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture custody and cleanup.
		mkdir( WP_PLUGIN_DIR . '/example' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
		file_put_contents( WP_PLUGIN_DIR . '/example/keep.php', 'keep' );
		$before = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertSame( 'already_installed', $this->installer()->install( 'example' ) );
		self::assertSame( 0, $this->executor->calls );
		self::assertSame( 'keep', file_get_contents( WP_PLUGIN_DIR . '/example/keep.php' ) );
		self::assertSame( $before, glob( sys_get_temp_dir() . '/ran-extension-*' ) );
	}

	public function test_symlink_destination_is_not_overwritten(): void {
		symlink( $this->directory . '/missing', WP_PLUGIN_DIR . '/example' );
		self::assertSame( 'already_installed', $this->installer()->install( 'example' ) );
		self::assertSame( 0, $this->executor->calls );
		self::assertTrue( is_link( WP_PLUGIN_DIR . '/example' ) );
	}

	public function test_changed_temp_identity_is_not_deleted_or_reported_as_success(): void {
		$GLOBALS['ran_booster_extension_test_replace_file'] = true;
		$before = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertSame( 'uncertain', $this->installer()->install( 'example' ) );
		self::assertSame( 0, $this->executor->calls );
		$after = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertIsArray( $before );
		self::assertIsArray( $after );
		$remaining = array_values( array_diff( $after, $before ) );
		self::assertCount( 1, $remaining );
		self::assertFileExists( $remaining[0] . '/release.zip.original' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fixture custody and cleanup.
		unlink( $remaining[0] . '/release.zip.original' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fixture custody and cleanup.
		unlink( $remaining[0] . '/release.zip' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only fixture custody and cleanup.
		rmdir( $remaining[0] );
	}

	public function test_uncertain_wordpress_failure_is_not_reported_as_success(): void {
		$this->executor->throws = true;
		$before                 = glob( sys_get_temp_dir() . '/ran-extension-*' );
		self::assertSame( 'uncertain', $this->installer()->install( 'example' ) );
		self::assertSame( 1, $this->executor->calls );
		self::assertSame( $before, glob( sys_get_temp_dir() . '/ran-extension-*' ) );
	}

	public function test_unknown_and_incompatible_ids_do_not_download(): void {
		self::assertSame( 'failed', $this->installer()->install( 'unknown' ) );
		$this->entry['compatible'] = false;
		self::assertSame( 'incompatible', $this->installer()->install( 'example' ) );
		self::assertArrayNotHasKey( 'ran_booster_extension_test_url', $GLOBALS );
	}

	private function installer(): ExtensionInstaller {
		return new ExtensionInstaller( $this->lock, $this->executor, fn(): array => array( $this->entry ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused in-process lock test double.
final class FakeExtensionLock extends WordPressUpdaterLock {
	public int $acquires = 0;
	public function acquire(): string {
		++$this->acquires;
		return 'token'; }
	public function release( string $token ): bool {
		return 'token' === $token; }
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused in-process executor test double.
final class FakeExtensionExecutor extends CorePackageExecutor {
	public int $calls    = 0;
	public bool $throws  = false;
	public bool $install = false;
	public function install_plugin( PreparedArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		if ( 'example' !== $package_slug || null !== $subdirectory ) {
			throw new RuntimeException( 'Unexpected package target.' );
		}
		++$this->calls;
		if ( $this->throws ) {
			throw new RuntimeException( 'simulated failure' ); }
		if ( $this->install ) {
			$zip = new ZipArchive();
			$zip->open( $artifact->get_path() );
			$zip->extractTo( WP_PLUGIN_DIR );
			$zip->close();
		}
		return CorePackageExecutionResult::succeeded();
	}
}

<?php

declare(strict_types=1);

namespace Tests\Uninstall;

// Native fixture operations prove the WP-CLI config-path fallback.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\BackgroundDeploymentFailureNotice;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Storage\Database;
use RAN\Uninstall\LocalDataRemover;
use RuntimeException;

require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/UninstallWordPressFunctions.php';

#[CoversClass( LocalDataRemover::class )]
final class LocalDataRemoverTest extends TestCase {

	private UninstallDatabase $database;

	protected function setUp(): void {
		$this->database = new UninstallDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused database double.
		$GLOBALS['wpdb'] = $this->database;

		$GLOBALS['ran_booster_uninstall_cron_result']                       = 1;
		$GLOBALS['ran_booster_uninstall_cron_calls']                        = array();
		$GLOBALS['ran_booster_uninstall_multisite']                         = false;
		$GLOBALS['ran_booster_uninstall_current_blog_id']                   = 1;
		$GLOBALS['ran_booster_uninstall_main_site_id']                      = 1;
		$GLOBALS['ran_booster_uninstall_deleted_transients']                = array();
		$GLOBALS['ran_booster_uninstall_deleted_options']                   = array();
		$GLOBALS['ran_booster_uninstall_plugin_basename']                   = 'renamed-booster/ran-booster.php';
		$GLOBALS['ran_booster_uninstall_cron']                              = array(
			WordPressWorkerWakeup::HOOK => true,
			'unrelated_cron_hook'       => true,
		);
		$GLOBALS['ran_booster_uninstall_transients']                        = array();
		$GLOBALS['ran_booster_uninstall_transients']['auto_updater.lock']   = 'wordpress-lock';
		$GLOBALS['ran_booster_uninstall_transients']['unrelated_transient'] = 'preserved';
		$GLOBALS['ran_booster_uninstall_options']                           = array(
			Database::VERSION_OPTION                      => '5.0',
			CredentialExpiryObservationStore::OPTION_NAME => array( 'profiles' => array() ),
			PublicRepositoryLookupProfileStore::OPTION_NAME => array( 'profiles' => array() ),
			$this->updaterAuthorityOption()               => 'owned-updater-state',
			SiteKeyStore::OPTION_NAME                     => 'encoded-key',
			'ran_booster_assisted_hooks_installations'    => array( 'owned-by-addon' ),
			'unrelated_option'                            => 'preserved',
		);
		$this->database->tables   = array(
			'wp_ran_booster_packages',
			'wp_ran_booster_deployment_attempts',
			'wp_ran_booster_rejected_admission_audit',
			'wp_unrelated',
		);
		$this->database->userMeta = array(
			DevelopmentSafetyNoticeController::USER_META_KEY => array( 1 ),
			CredentialExpiryNotice::USER_META_KEY => array( 2 ),
			BackgroundDeploymentFailureNotice::USER_META_KEY => array( 3 ),
			'unrelated_meta'                      => array( 4 ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRemoveDeletesTheExactCoreInventoryAndCanBeRepeated(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;

		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( null );
		$secrets->expects( self::exactly( 2 ) )
			->method( 'deleteManagedStorage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'removeOwnedDefinition' );
		$remover = $this->remover( $secrets, $writer );

		$remover->remove();
		$remover->remove();

		self::assertSame( array( 'wp_unrelated' ), $this->database->tables );
		self::assertSame( array( 'unrelated_meta' => array( 4 ) ), $this->database->userMeta );
		self::assertSame(
			array(
				'ran_booster_assisted_hooks_installations' => array( 'owned-by-addon' ),
				'unrelated_option'                         => 'preserved',
			),
			$GLOBALS['ran_booster_uninstall_options']
		);
		self::assertSame(
			array(
				'auto_updater.lock'   => 'wordpress-lock',
				'unrelated_transient' => 'preserved',
			),
			$GLOBALS['ran_booster_uninstall_transients']
		);
		self::assertSame( array(), $GLOBALS['ran_booster_uninstall_deleted_transients'] );
		self::assertSame( array( 'unrelated_cron_hook' => true ), $GLOBALS['ran_booster_uninstall_cron'] );
		self::assertSame(
			array(
				WordPressWorkerWakeup::HOOK,
				WordPressWorkerWakeup::HOOK,
			),
			$GLOBALS['ran_booster_uninstall_cron_calls']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConvertedUninstallStopsBeforeSecretsOrDatabaseOnANonMainSite(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite']       = true;
		$GLOBALS['ran_booster_uninstall_current_blog_id'] = 2;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'deleteManagedStorage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConvertedUninstallStopsBeforeSecretsWhenTheOptionsTableIsNotTheBaseTable(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;
		$this->database->options                    = 'wp_2_options';
		$secrets                                    = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'assertManagedStorageDeletable' );
		$secrets->expects( self::never() )->method( 'deleteManagedStorage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnsafeSecretsAbortBeforeDatabaseCleanupAndPreserveTheKey(): void {
		$this->setUp();
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( '/private/secrets.json' );
		$secrets->method( 'assertManagedStorageDeletable' )
			->willThrowException( new RuntimeException( 'sensitive path must not escape' ) );
		$secrets->expects( self::never() )->method( 'deleteManagedStorage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'removeOwnedDefinition' );

		$remover = $this->remover(
			$secrets,
			$writer,
			'/site/wp-config.php'
		);

		try {
			$remover->remove();
			self::fail( 'Unsafe managed secrets must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'sensitive path must not escape', $failure->getMessage() );
		}

		self::assertArrayHasKey( SiteKeyStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertArrayHasKey( WordPressWorkerWakeup::HOOK, $GLOBALS['ran_booster_uninstall_cron'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAmbiguousConfigOwnershipAbortsBeforeAnyManagedStorageOrDatabaseCleanup(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( $sidecar );
		$secrets->expects( self::never() )->method( 'assertManagedStorageDeletable' );
		$secrets->expects( self::never() )->method( 'deleteManagedStorage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'assertOwnedDefinitionRemovable' )
			->with( $config, $sidecar )
			->willThrowException( new RuntimeException( 'owned definition is ambiguous' ) );
		$writer->expects( self::never() )->method( 'removeOwnedDefinition' );

		try {
			$this->remover( $secrets, $writer, $config )->remove();
			self::fail( 'Ambiguous configuration ownership must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'ambiguous', $failure->getMessage() );
		}

		self::assertArrayHasKey( SiteKeyStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertArrayHasKey( WordPressWorkerWakeup::HOOK, $GLOBALS['ran_booster_uninstall_cron'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testDatabaseFailureLeavesARepeatablePartialCleanup(): void {
		$this->setUp();
		$this->database->failureContains = 'wp_ran_booster_deployment_attempts';
		$secrets                         = $this->secrets( null );
		$secrets->method( 'deleteManagedStorage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'removeOwnedDefinition' );
		$remover = $this->remover( $secrets, $writer );

		try {
			$remover->remove();
			self::fail( 'A database cleanup failure must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Booster tables could not be removed.', $failure->getMessage() );
		}

		self::assertNotContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );

		$this->database->failureContains = null;
		$remover->remove();
		self::assertSame( array( 'wp_unrelated' ), $this->database->tables );
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfiguredPathIsPassedOnlyToTheNarrowConfigInverse(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->secrets( $sidecar );
		$secrets->method( 'deleteManagedStorage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'removeOwnedDefinition' )
			->with( $config, $sidecar )
			->willReturn( false );

		$remover = new TestableLocalDataRemover(
			$secrets,
			new TemporaryDebugCapture( null ),
			$writer,
			$this->database,
			$config
		);
		$remover->remove();

		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWpCliUsesTheOnlyCanonicalSupportedConfigWhenIncludesAreHidden(): void {
		$this->setUp();
		$root   = (string) realpath( sys_get_temp_dir() )
			. '/ran-booster-uninstall-config-'
			. bin2hex( random_bytes( 6 ) );
		$config = $root . '/wp-config.php';
		self::assertTrue( mkdir( $root, 0700 ) );
		self::assertNotFalse( file_put_contents( $config, "<?php\n" ) );
		define( 'ABSPATH', $root . '/' );
		define( 'WP_CLI', true );

		$remover = new class(
			$this->secrets( null ),
			new TemporaryDebugCapture( null ),
			$this->createStub( WpConfigSecretsPathWriter::class ),
			$this->database
		) extends LocalDataRemover {
			public function __construct(
				SecretsFile $secrets,
				TemporaryDebugCapture $capture,
				WpConfigSecretsPathWriter $writer,
				object $database
			) {
				parent::__construct( $secrets, $capture, $writer, database: $database );
			}

			public function discoveredConfigPath(): string {
				return $this->loadedWpConfigPath();
			}
		};

		try {
			self::assertSame( $config, $remover->discoveredConfigPath() );
		} finally {
			unlink( $config );
			rmdir( $root );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRemoveDeletesTheExactConfigLockAndEmptyAutomaticDirectories(): void {
		$this->setUp();
		$root       = (string) realpath( sys_get_temp_dir() )
			. '/ran-booster-uninstall-directories-'
			. bin2hex( random_bytes( 6 ) );
		$base       = $root . '/.ran-booster';
		$site       = $base . '/site-fingerprint';
		$sidecar    = $site . '/secrets.json';
		$config     = $root . '/wp-config.php';
		$configLock = $config . '.ran-booster.lock';
		self::assertTrue( mkdir( $site, 0700, true ) );
		self::assertNotFalse( file_put_contents( $config, "<?php\n" ) );
		self::assertNotFalse( file_put_contents( $configLock, '' ) );
		self::assertTrue( chmod( $base, 0700 ) );
		self::assertTrue( chmod( $configLock, 0600 ) );

		$secrets = $this->secrets( $sidecar );
		$remover = new class(
			$secrets,
			new TemporaryDebugCapture( $sidecar ),
			$this->createStub( WpConfigSecretsPathWriter::class ),
			$this->database,
			$config,
			$sidecar
		) extends LocalDataRemover {
			public function __construct(
				SecretsFile $secrets,
				TemporaryDebugCapture $capture,
				WpConfigSecretsPathWriter $writer,
				object $database,
				private readonly string $configPath,
				private readonly string $automaticPath
			) {
				parent::__construct( $secrets, $capture, $writer, database: $database );
			}

			protected function loadedWpConfigPath(): string {
				return $this->configPath;
			}

			protected function automaticSidecarPath(): ?string {
				return $this->automaticPath;
			}
		};

		try {
			$remover->remove();
			$remover->remove();
			self::assertFileDoesNotExist( $configLock );
			self::assertDirectoryDoesNotExist( $site );
			self::assertDirectoryDoesNotExist( $base );
		} finally {
			if ( is_file( $configLock ) ) {
				unlink( $configLock );
			}
			unlink( $config );
			if ( is_dir( $site ) ) {
				rmdir( $site );
			}
			if ( is_dir( $base ) ) {
				rmdir( $base );
			}
			rmdir( $root );
		}
	}

	private function secrets( ?string $path ): SecretsFile {
		$secrets = $this->createStub( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( $path );

		return $secrets;
	}

	private function remover(
		SecretsFile $secrets,
		WpConfigSecretsPathWriter $writer,
		?string $configPath = null
	): LocalDataRemover {
		return new TestableLocalDataRemover(
			$secrets,
			new TemporaryDebugCapture( null ),
			$writer,
			$this->database,
			$configPath
		);
	}

	private function updaterAuthorityOption(): string {
		$target = implode( "\0", array( 'plugin', 'ran-booster', 'ran-booster.php' ) );
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}
}

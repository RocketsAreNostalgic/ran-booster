<?php

declare(strict_types=1);

namespace Tests\Uninstall;

// Native fixture operations prove the WP-CLI config-path fallback.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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
		$GLOBALS['ran_booster_uninstall_undeletable_option']                = null;
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
			'ran_booster_release_deployments_assessment_observations' => array( array( 'kind' => 'existing_automation_detected' ) ),
			'ran_booster_release_deployments_failure_history' => array( array( 'correlation_reference' => str_repeat( 'a', 32 ) ) ),
			$this->updater_authority_option()             => 'owned-updater-state',
			SiteKeyStore::OPTION_NAME                     => 'encoded-key',
			WordPressInstallationStore::OPTION_NAME       => array( 'current-webhook-record' ),
			'unrelated_option'                            => 'preserved',
		);
		foreach (
			array(
				WorkflowAssistanceState::SETUP_OPTION,
				WorkflowAssistanceState::ASSESSMENT_OPTION,
				WorkflowAssistanceState::FAILURE_OPTION,
			) as $provider_option
		) {
			$GLOBALS['ran_booster_uninstall_options'][ $provider_option ] = array( 'current-provider-state' );
		}
		$GLOBALS['ran_booster_uninstall_options']['ran_booster_release_deployments_setup_records'] = array( 'obsolete-provider-state' );
		$this->database->tables    = array(
			'wp_ran_booster_packages',
			'wp_ran_booster_deployment_attempts',
			'wp_ran_booster_rejected_admission_audit',
			'wp_ran_booster_native_update_activity',
			'wp_unrelated',
		);
		$this->database->user_meta = array(
			DevelopmentSafetyNoticeController::USER_META_KEY => array( 1 ),
			CredentialExpiryNotice::USER_META_KEY   => array( 2 ),
			DeploymentAdminPresenter::USER_META_KEY => array( 3 ),
			'unrelated_meta'                        => array( 4 ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_remove_deletes_the_exact_core_inventory_and_can_be_repeated(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;

		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( null );
		$secrets->expects( self::exactly( 2 ) )
			->method( 'delete_managed_storage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );
		$remover = $this->remover( $secrets, $writer );

		$remover->remove();
		$remover->remove();

		self::assertSame(
			array(
				'wp_ran_booster_rejected_admission_audit',
				'wp_ran_booster_native_update_activity',
				'wp_unrelated',
			),
			$this->database->tables
		);
		self::assertSame( array( 'unrelated_meta' => array( 4 ) ), $this->database->user_meta );
		self::assertSame(
			array(
				'ran_booster_release_deployments_assessment_observations' => array(
					array( 'kind' => 'existing_automation_detected' ),
				),
				'ran_booster_release_deployments_failure_history' => array(
					array( 'correlation_reference' => str_repeat( 'a', 32 ) ),
				),
				'unrelated_option' => 'preserved',
				'ran_booster_release_deployments_setup_records' => array( 'obsolete-provider-state' ),
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
	public function test_provider_cleanup_failure_stops_before_core_database_cleanup(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_undeletable_option'] = WorkflowAssistanceState::SETUP_OPTION;

		try {
			$this->remover(
				$this->secrets( null ),
				$this->createStub( WpConfigSecretsPathWriter::class )
			)->remove();
			self::fail( 'An unverifiable bundled-provider cleanup must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Bundled GitHub provider state could not be removed.', $failure->getMessage() );
		}

		self::assertArrayHasKey( Database::VERSION_OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayHasKey( WordPressInstallationStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayHasKey( DevelopmentSafetyNoticeController::USER_META_KEY, $this->database->user_meta );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_converted_uninstall_stops_before_secrets_or_database_on_a_non_main_site(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite']       = true;
		$GLOBALS['ran_booster_uninstall_current_blog_id'] = 2;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_converted_uninstall_stops_before_secrets_when_the_options_table_is_not_the_base_table(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;
		$this->database->options                    = 'wp_2_options';
		$secrets                                    = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'assert_managed_storage_deletable' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsafe_secrets_abort_before_database_cleanup_and_preserve_the_key(): void {
		$this->setUp();
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( '/private/secrets.json' );
		$secrets->method( 'assert_managed_storage_deletable' )
			->willThrowException( new RuntimeException( 'sensitive path must not escape' ) );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );

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
	public function test_ambiguous_config_ownership_aborts_before_any_managed_storage_or_database_cleanup(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( $sidecar );
		$secrets->expects( self::never() )->method( 'assert_managed_storage_deletable' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'assert_owned_definition_removable' )
			->with( $config, $sidecar )
			->willThrowException( new RuntimeException( 'owned definition is ambiguous' ) );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );

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
	public function test_database_failure_leaves_a_repeatable_partial_cleanup(): void {
		$this->setUp();
		$this->database->failure_contains = 'wp_ran_booster_deployment_attempts';
		$secrets                          = $this->secrets( null );
		$secrets->method( 'delete_managed_storage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );
		$remover = $this->remover( $secrets, $writer );

		try {
			$remover->remove();
			self::fail( 'A database cleanup failure must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Booster tables could not be removed.', $failure->getMessage() );
		}

		self::assertNotContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );

		$this->database->failure_contains = null;
		$remover->remove();
		self::assertSame(
			array(
				'wp_ran_booster_rejected_admission_audit',
				'wp_ran_booster_native_update_activity',
				'wp_unrelated',
			),
			$this->database->tables
		);
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_configured_path_is_passed_only_to_the_narrow_config_inverse(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->secrets( $sidecar );
		$secrets->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'remove_owned_definition' )
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
	public function test_wp_cli_uses_the_only_canonical_supported_config_when_includes_are_hidden(): void {
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

			public function discovered_config_path(): string {
				return $this->loaded_wp_config_path();
			}
		};

		try {
			self::assertSame( $config, $remover->discovered_config_path() );
		} finally {
			unlink( $config );
			rmdir( $root );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_remove_deletes_the_exact_config_lock_and_empty_automatic_directories(): void {
		$this->setUp();
		$root        = (string) realpath( sys_get_temp_dir() )
			. '/ran-booster-uninstall-directories-'
			. bin2hex( random_bytes( 6 ) );
		$base        = $root . '/.ran-booster';
		$site        = $base . '/site-fingerprint';
		$sidecar     = $site . '/secrets.json';
		$config      = $root . '/wp-config.php';
		$config_lock = $config . '.ran-booster.lock';
		self::assertTrue( mkdir( $site, 0700, true ) );
		self::assertNotFalse( file_put_contents( $config, "<?php\n" ) );
		self::assertNotFalse( file_put_contents( $config_lock, '' ) );
		self::assertTrue( chmod( $base, 0700 ) );
		self::assertTrue( chmod( $config_lock, 0600 ) );

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
				private readonly string $config_path,
				private readonly string $automatic_path
			) {
				parent::__construct( $secrets, $capture, $writer, database: $database );
			}

			protected function loaded_wp_config_path(): string {
				return $this->config_path;
			}

			protected function automatic_sidecar_path(): ?string {
				return $this->automatic_path;
			}
		};

		try {
			$remover->remove();
			$remover->remove();
			self::assertFileDoesNotExist( $config_lock );
			self::assertDirectoryDoesNotExist( $site );
			self::assertDirectoryDoesNotExist( $base );
		} finally {
			if ( is_file( $config_lock ) ) {
				unlink( $config_lock );
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
		?string $config_path = null
	): LocalDataRemover {
		return new TestableLocalDataRemover(
			$secrets,
			new TemporaryDebugCapture( null ),
			$writer,
			$this->database,
			$config_path
		);
	}

	private function updater_authority_option(): string {
		$target = implode( "\0", array( 'plugin', 'ran-booster', 'ran-booster.php' ) );
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}
}

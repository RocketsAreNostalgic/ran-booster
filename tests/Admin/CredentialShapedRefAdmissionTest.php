<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RAN\Activity\ActivityStore;
use RAN\Admin\PackageAdminController;
use RAN\Dashboard;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\ManagedRepository;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\DeploymentWriteConnection;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\Tests\Deployment\DeploymentRequestTest;
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;
use RAN\Webhook\SignedWebhookVerifier;
use RAN\Webhook\WebhookProcessor;
use RAN\WordPress\WordPressUpdaterLock;
use WP_Error;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CredentialShapedRefAdmissionTest extends TestCase {
	use \RAN\Tests\Support\QueuedSelectionFixture;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private DeploymentCoordinator $coordinator;
	private ProviderRegistry $registry;
	private \RAN\RepositoryProvider\GitHubProviderSelection $selection;
	private PluginRepository&MockObject $plugins;
	private ThemeRepository&MockObject $themes;
	private Plugin $package;
	private TemporaryDebugCapture $capture;
	private string $directory;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
		require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Deployment/DeploymentCoordinatorWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/PackageOperationWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/WPError.php';
		require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';

		$this->database = new AttemptRepositoryDatabase();
		$this->plugins  = $this->createMock( PluginRepository::class );
		$this->themes   = $this->createMock( ThemeRepository::class );
		$this->plugins->expects( self::never() )->method( 'adopt' );
		$this->plugins->expects( self::never() )->method( 'store' );
		$this->themes->expects( self::never() )->method( 'adopt' );
		$this->themes->expects( self::never() )->method( 'store' );
		$this->directory = sys_get_temp_dir() . '/ran-booster-ref-refusal-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The debug sink belongs exclusively to this isolated test.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->capture = new TemporaryDebugCapture( $this->directory . '/secrets.php' );
		$this->capture->start();
		BoosterLogger::configure_capture( $this->capture );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		BoosterLogger::configure_capture( null );
		foreach ( array( 'ran-booster-debug.php', 'ran-booster-debug.php.lock' ) as $name ) {
			$path = $this->directory . '/' . $name;
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test's temporary debug sink.
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this test's isolated directory.
		rmdir( $this->directory );
	}

	/** @return iterable<string, array{string}> */
	public static function refused_refs(): iterable {
		yield from DeploymentRequestTest::credential_shaped_refs();
	}

	#[DataProvider( 'refused_refs' )]
	public function test_manual_and_authenticated_webhook_refuse_before_attempt_activity_or_package_writes( string $ref ): void {
		$this->configure( $ref );
		$before    = clone $this->package;
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$controller = $this->controller();
		$notice     = null;
		$result     = $controller->perform(
			$dashboard,
			'update-plugin',
			$this->manual_request(),
			array(),
			static function ( WP_Error|array $message, array $context ) use ( &$notice ): void {
				self::assertInstanceOf( WP_Error::class, $message );
				$notice = array( $message->get_error_code(), $message->get_error_message(), $context );
			}
		);
		self::assertFalse( $result );
		self::assertSame( 400, $GLOBALS['ran_booster_test_status_header'] );
		self::assertSame(
			array(
				'ran_booster_credential_shaped_ref',
				'Deployment refused because the configured branch name resembles a credential. Rename the branch in the repository and configure Booster to use the new name.',
				array(
					'operation' => 'update-plugin',
					'step'      => 'manual_package_operation',
				),
			),
			$notice
		);

		$response = $this->webhook();
		self::assertSame( 400, $response->get_status() );
		self::assertSame( array( 'message' => 'Invalid webhook request.' ), $response->get_data() );
		self::assertSame( array(), $this->database->rows );
		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->database->management );
		self::assertEquals( $before, $this->package );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );
		$sinks = wp_json_encode( array( $notice, $response->get_data(), $this->database->rows, $this->database->queries, $this->capture->snapshot() ), JSON_UNESCAPED_SLASHES );
		self::assertIsString( $sinks );
		self::assertStringNotContainsString( $ref, $sinks );
		self::assertStringNotContainsString( 'ref-canary-840', $sinks );
	}

	public function test_ordinary_ref_reaches_real_webhook_admission_and_corrupt_stored_ref_stays_fail_closed(): void {
		$this->configure( 'feature/token-cleanup', false );
		$this->seed_management();
		$response = $this->webhook();
		self::assertSame( 202, $response->get_status() );
		self::assertSame( 1, $response->get_data()['accepted_targets'] );
		self::assertCount( 1, $this->database->rows );
		$row_key = array_key_first( $this->database->rows );
		self::assertNotNull( $row_key );
		$id = (int) $this->database->rows[ $row_key ]['id'];
		self::assertSame( 'feature/token-cleanup', $this->attempts->find_exact( $id )?->get_request()->configured_branch );
		$this->database->rows[ $row_key ]['request_json'] = str_replace( 'feature/token-cleanup', 'token=ref-canary-840', (string) $this->database->rows[ $row_key ]['request_json'] );
		try {
			$this->attempts->find_exact( $id );
			self::fail( 'Corrupt stored requests must not become executable.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertNull( $failure->getPrevious() );
			self::assertStringNotContainsString( 'ref-canary-840', $failure->getMessage() );
		}
	}

	public function test_ordinary_ref_reaches_manual_admission_without_filesystem_execution(): void {
		$this->configure( 'feature/token-cleanup', false );
		$this->seed_management();
		$result = $this->coordinator->execute_manual( PackageOperation::from_input( 'update-plugin', $this->manual_request() ) );
		self::assertSame( 'failed', $result['status'] );
		self::assertCount( 1, $this->database->rows );
		$row = reset( $this->database->rows );
		self::assertIsArray( $row );
		self::assertNull( $row['mutation_started_at'] );
		self::assertSame( 'feature/token-cleanup', $this->attempts->find_exact( (int) $row['id'] )?->get_request()->configured_branch );
	}

	public function test_generic_invalid_ref_retains_existing_manual_failure(): void {
		$this->configure( str_repeat( 'a', 256 ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' )->with(
			self::callback( static fn ( WP_Error $message ): bool => 'ran_booster_manual_action_failed' === $message->get_error_code() ),
			self::isInstanceOf( \InvalidArgumentException::class ),
			array(
				'operation' => 'update-plugin',
				'step'      => 'manual_package_operation',
			)
		);
		self::assertFalse(
			$this->controller()->perform(
				$dashboard,
				'update-plugin',
				$this->manual_request(),
				array(),
				static function (): void {
					self::fail( 'Generic failures retain their existing path.' );
				}
			)
		);
		self::assertSame( array(), $this->database->rows );
	}

	private function seed_management(): void {
		$token = (string) $this->package->get_adoption_token();
		$this->database->management['example/example.php']      = $token;
		$this->database->management_rows['example/example.php'] = array(
			'package'                => 'example/example.php',
			'adoption_token'         => $token,
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
			'repository'             => 'owner/example',
			'branch'                 => 'feature/token-cleanup',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => 'automatic',
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
	}

	private function configure( string $ref, bool $guard_activity = true ): void {
		$this->package = Plugin::from_wp_array(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$this->package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', $ref ) );
		$this->package->set_adoption_token( 'gh:R_example:11111111-1111-4111-8111-111111111111' );
		$this->package->set_source( PackageSource::BRANCH, 1 );
		$this->package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$this->plugins->method( 'booster_plugin_from_file' )->willReturn( $this->package );
		$this->plugins->method( 'all_deployment_plugins' )->willReturn( array( $this->package ) );
		$this->themes->method( 'all_deployment_themes' )->willReturn( array() );
		$provider = $this->createMockForIntersectionOfInterfaces( array( RepositoryProvider::class, WebhookNormalizer::class ) );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createStub( ProviderDiagnostics::class ) );
		$provider->expects( self::never() )->method( 'prepare_archive' );
		$provider->method( 'get_webhook_policy' )->willReturn( new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) ) );
		$provider->method( 'normalize_webhook' )->willReturn( WebhookEnvelope::events( new PushEvent( ProviderCode::parse( 'gh' ), 'owner/example', 'R_example', $ref, str_repeat( 'a', 40 ), 'delivery-840' ) ) );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
		$this->registry = new ProviderRegistry( array( $provider ) );
		$activity_db    = $this->createMock( DeploymentWriteConnection::class );
		$activity_db->expects( self::never() )->method( 'query' );
		$activity_db->expects( self::never() )->method( 'insert' );
		$activity_db->expects( self::never() )->method( 'get_results' );
		$this->selection = $this->queued_selection( $this->database, $this->registry );
		$this->attempts  = new DeploymentAttemptRepository(
			$this->database,
			'wp_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-11-17 00:00:00 UTC' ),
			database_lifecycle: $this->createStub( Database::class ),
			activity: $guard_activity ? new ActivityStore( $activity_db, 'wp_activity' ) : null,
			selection: $this->selection
		);
		$lock            = $this->createMock( WordPressUpdaterLock::class );
		$lock->method( 'acquire' )->willThrowException( new \RuntimeException( 'Isolated execution is intentionally unavailable.' ) );
		$this->coordinator = new DeploymentCoordinator(
			$this->attempts,
			$this->plugins,
			$this->themes,
			$this->registry,
			new WordPressWorkerWakeup( $this->attempts ),
			'/unused/maintenance',
			$lock
		);
	}

	/** @return array<string, mixed> */
	private function manual_request(): array {
		return array(
			'file'                            => 'example/example.php',
			'repository'                      => 'owner/example',
			'expected_provider'               => 'gh',
			'expected_provider_repository_id' => 'R_example',
			'expected_repository'             => 'owner/example',
			'expected_branch'                 => (string) $this->package->get_branch(),
			'expected_credential_id'          => '',
			'expected_subdirectory'           => '',
			'expected_private'                => '0',
			'expected_package_slug'           => 'example',
			'expected_deployment_policy'      => 'automatic',
			'expected_source'                 => 'branch',
			'expected_source_revision'        => '1',
			'expected_adoption_token'         => $this->package->get_adoption_token(),
		);
	}

	private function controller(): PackageAdminController {
		$lock     = $this->createMock( WordPressUpdaterLock::class );
		$removals = new PackageRemovalService(
			$this->plugins,
			$this->themes,
			$this->createStub( \RAN\PackageRemoval\PackageRemovalGateway::class ),
			$this->attempts,
			$lock
		);
		return new PackageAdminController( operations: new PackageOperationService( $this->plugins, $this->themes, $this->coordinator, $removals, $lock ) );
	}

	private function webhook(): \RAN\Webhook\WebhookResponse {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'webhook_materials' )->willReturn(
			array(
				'fixture' => array(
					'scope'  => 'owner',
					'target' => 'owner',
					'secret' => 'isolated-signing-material-for-ref-refusal-840',
				),
			)
		);
		$processor = new WebhookProcessor( $this->registry, $this->coordinator, new SignedWebhookVerifier( $secrets ), $this->selection );
		return $processor->handle(
			'gh',
			static fn (): array => array(
				'body'    => '{}',
				'headers' => array( 'x-fixture-signature' => 'sha256=' . hash_hmac( 'sha256', '{}', 'isolated-signing-material-for-ref-refusal-840' ) ),
			)
		);
	}
}

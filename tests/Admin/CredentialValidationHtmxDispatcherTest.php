<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderProfileAdminController;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Storage\CredentialUsageReader;
use RAN\WordPress\WordPressUpdaterLock;

final class CredentialValidationHtmxDispatcherTest extends TestCase {
	private HtmxCredentialValidationTestController $controller;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks']        = array();
		$GLOBALS['ran_booster_test_nonce_checks']             = array();
		$GLOBALS['ran_booster_test_capabilities']             = array();
		$GLOBALS['ran_booster_test_nonce_valid']              = true;
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HX_REQUEST'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid'],
			$GLOBALS['ran_booster_admin_test_translations'],
			$GLOBALS['ran_booster_repository_admin_translations']
		);
	}

	public function test_ordinary_post_keeps_the_existing_dashboard_notice_flow(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_message' )
			->with( 'Repository credential validated successfully.' );
		$dashboard->expects( self::once() )->method( 'set_credential_validation_feedback' )->with( 'bb', 'credential_1', 'Repository credential validated successfully.', null );
		$dispatcher = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );

		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatch_post_requests();

		self::assertNull( $this->controller->response );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-secrets' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_ordinary_rejection_is_associated_with_the_requested_credential(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'set_credential_validation_feedback' )->with(
			'bb',
			'credential_1',
			null,
			'The repository provider rejected this credential.'
		);
		$dispatcher           = $this->dispatcher( $dashboard, CredentialValidationResult::invalid() );
		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatch_post_requests();
	}

	public function test_htmx_post_returns_only_the_safe_success_toast_payload(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertSame( 'Repository credential validated successfully.', $response->toast_message );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function test_htmx_success_toast_uses_the_translated_credential_validation_message(): void {
		$translations = array(
			'Repository credential validated successfully.' => 'Translated credential validation success.',
		);
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']       = $translations;
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster'] = $translations;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::valid() );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertSame( 'Translated credential validation success.', $response->toast_message );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function test_htmx_validation_failure_remains_local_and_does_not_claim_success(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dispatcher                 = $this->dispatcher( $dashboard, CredentialValidationResult::rate_limited() );
		$_SERVER['HTTP_HX_REQUEST'] = 'TRUE';
		$_POST['ran_booster']       = $this->request();

		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX validation failure must return the local error fragment.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertNull( $response->toast_message );
			self::assertSame( 'The repository provider rate-limited credential validation. Try again later.', $response->error );
			self::assertSame( 422, $response->status );
		}
	}

	public function test_htmx_unexpected_failure_returns_a_bounded_local_error(): void {
		$dashboard                  = $this->createMock( Dashboard::class );
		$dispatcher                 = $this->dispatcher(
			$dashboard,
			CredentialValidationResult::valid(),
			on_validate: static function (): void {
				throw new \RuntimeException( 'private-provider-payload' );
			}
		);
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = $this->request();
		try {
			$dispatcher->dispatch_post_requests();
			self::fail( 'An HTMX response must end the request.' );
		} catch ( HtmxCredentialValidationResponse $response ) {
			self::assertSame( 'credential_1', $response->credential_id );
			self::assertNull( $response->toast_message );
			self::assertSame( 'Booster could not complete the credential request.', $response->error );
			self::assertSame( 500, $response->status );
		}
	}

	public function test_validation_history_preserves_expiry_distinctions_and_existing_authority(): void {
		foreach ( array(
			array( CredentialValidationResult::valid(), 'succeeded', 'not_reported', null ),
			array( CredentialValidationResult::valid( \RAN\RepositoryProvider\CredentialExpiryReport::unknown() ), 'succeeded', 'unknown', null ),
			array( CredentialValidationResult::valid( \RAN\RepositoryProvider\CredentialExpiryReport::known( '2027-04-30T12:34:56Z' ) ), 'succeeded', 'known', '2027-04-30T12:34:56Z' ),
			array( CredentialValidationResult::invalid(), 'rejected', 'not_reported', null ),
			array( CredentialValidationResult::rate_limited(), 'failed', 'not_reported', null ),
		) as [ $result, $status, $expiry_state, $expires_at ] ) {
			$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
			$database                                = new \RAN\Tests\Activity\ActivityConnection();
			$activity                                = new \RAN\Activity\ActivityStore( $database, 'test_activity' );
			$evidence                                = new class() extends \RAN\Admin\RepositoryBranchCheckEvidenceStore {
				public int $generation = 1;
				protected function read_option(): array {
					return array( 'generation' => $this->generation ); }
			};
			$before                                  = $evidence->credential_context( 'bb', 'credential_1' );
			$expiry                                  = new InMemoryCredentialExpiryObservationStore();
			$expiry->set_manual_expiry( 'bb', 'credential_1', '2027-01-01' );
			$expiry->record_provider_expiry( 'bb', 'credential_1', \RAN\RepositoryProvider\CredentialExpiryReport::known( '2027-02-01T00:00:00Z' ), '2026-10-12T00:00:00Z' );
			$secrets = $this->createMock( SecretsFile::class );
			$secrets->expects( null === $expires_at ? self::never() : self::once() )->method( 'record_credential_provider_expiry' )->with( self::callback( static fn ( mixed $provider ): bool => $provider instanceof \RAN\RepositoryProvider\ProviderCode && 'bb' === $provider->value ), 'credential_1', '2027-04-30' );
			$dispatcher           = $this->dispatcher(
				$this->createMock( Dashboard::class ),
				$result,
				$activity,
				$evidence,
				$secrets,
				$expiry,
				static function () use ( $database, $evidence ): void {
					self::assertCount( 1, $database->inserted );
					++$evidence->generation;
				}
			);
			$_POST['ran_booster'] = $this->request();
			$dispatcher->dispatch_post_requests();
			self::assertSame( 'credential.validate', $database->inserted[0]['operation'] );
			self::assertSame( 'bb', $database->inserted[0]['provider'] );
			self::assertSame( $before, $database->inserted[0]['context'] );
			self::assertNotSame( $before, $evidence->credential_context( 'bb', 'credential_1' ) );
			self::assertNull( $database->inserted[0]['purpose'] );
			$completion = end( $database->prepared );
			self::assertIsArray( $completion );
			self::assertSame( $status, $completion['args'][0] );
			self::assertSame( 'credential.' . $result->reason, $completion['args'][1] );
			self::assertSame(
				array(
					'expires_at'   => $expires_at,
					'expiry_state' => $expiry_state,
				),
				json_decode( (string) $completion['args'][4], true )
			);
			self::assertSame( 'none', $completion['args'][5] );
			self::assertSame( '2027-01-01', ( $expiry->get( 'bb', 'credential_1' )['manual_expires_on'] ?? null ) );
			self::assertSame( 'not_reported' === $expiry_state ? '2027-02-01T00:00:00Z' : $expires_at, $expiry->get( 'bb', 'credential_1' )['provider_expires_at'] ?? null );
			self::assertStringNotContainsString( 'credential_1', (string) \RAN\Activity\wp_json_encode( array( $database->inserted, $database->prepared ) ) );
		}
	}

	public function test_optional_history_failure_does_not_change_validation_response(): void {
		foreach ( array( 'insert', 'complete' ) as $failure ) {
			$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
			$database                                = new \RAN\Tests\Activity\ActivityConnection();
			$database->fail_insert                   = 'insert' === $failure;
			$database->query_results                 = array( false );
			$evidence                                = new class() extends \RAN\Admin\RepositoryBranchCheckEvidenceStore {
				protected function read_option(): array {
					return array( 'generation' => 1 ); }
			};
			$dashboard                               = $this->createMock( Dashboard::class );
			$dashboard->expects( self::once() )->method( 'add_message' )->with( 'Repository credential validated successfully.' );
			$dispatcher           = $this->dispatcher( $dashboard, CredentialValidationResult::valid(), new \RAN\Activity\ActivityStore( $database, 'test_activity' ), $evidence );
			$_POST['ran_booster'] = $this->request();
			$dispatcher->dispatch_post_requests();
			self::assertCount( 'insert' === $failure ? 0 : 1, $database->inserted );
		}
	}

	public function test_provider_exception_keeps_activity_incomplete_without_unwind_reporting(): void {
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$database                                = new \RAN\Tests\Activity\ActivityConnection();
		$evidence                                = new class() extends \RAN\Admin\RepositoryBranchCheckEvidenceStore {
			protected function read_option(): array {
				return array( 'generation' => 1 ); }
		};
		$dashboard                               = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dispatcher           = $this->dispatcher(
			$dashboard,
			CredentialValidationResult::valid(),
			new \RAN\Activity\ActivityStore( $database, 'test_activity' ),
			$evidence,
			on_validate: static function (): void {
				throw new \RuntimeException( 'Authorization: Bearer confidential-fixture-secret' );
			}
		);
		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatch_post_requests();
		self::assertSame( 'incomplete', $database->inserted[0]['status'] );
		self::assertSame( array(), array_values( array_filter( $database->queries, static fn ( string $query ): bool => str_contains( $query, 'SET status = ' ) ) ) );
		self::assertStringNotContainsString( 'confidential', (string) \RAN\Activity\wp_json_encode( array( $database->inserted, $database->prepared ) ) );
	}

	public function test_later_expiry_persistence_failure_does_not_rewrite_validation_observation(): void {
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$database                                = new \RAN\Tests\Activity\ActivityConnection();
		$evidence                                = new class() extends \RAN\Admin\RepositoryBranchCheckEvidenceStore {
			protected function read_option(): array {
				return array( 'generation' => 1 ); }
		};
		$secrets                                 = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )->method( 'record_credential_provider_expiry' )->willThrowException( new \RuntimeException( 'Fixture persistence unavailable' ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dispatcher           = $this->dispatcher( $dashboard, CredentialValidationResult::valid( \RAN\RepositoryProvider\CredentialExpiryReport::known( '2027-04-30T12:34:56Z' ) ), new \RAN\Activity\ActivityStore( $database, 'test_activity' ), $evidence, $secrets );
		$_POST['ran_booster'] = $this->request();
		$dispatcher->dispatch_post_requests();
		$completions = array_values( array_filter( $database->prepared, static fn ( array $query ): bool => str_contains( $query['sql'], 'SET status =' ) ) );
		self::assertCount( 1, $completions );
		self::assertSame( 'credential.valid', $completions[0]['args'][1] );
	}

	/** @return array{action:string,provider:string,id:string} */
	private function request(): array {
		return array(
			'action'   => 'validate-access-profile',
			'provider' => 'bb',
			'id'       => 'credential_1',
		);
	}

	private function dispatcher( Dashboard $dashboard, CredentialValidationResult $result, ?\RAN\Activity\ActivityStore $activity = null, ?\RAN\Admin\RepositoryBranchCheckEvidenceStore $evidence = null, ?SecretsFile $secrets = null, ?InMemoryCredentialExpiryObservationStore $expiry = null, ?\Closure $on_validate = null ): Dispatcher {
		$provider  = new CredentialValidationProvider( $result, $on_validate );
		$providers = new ProviderRegistry( array( $provider ) );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };
		$lock      = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::never() )->method( 'acquire' );
		$lock->expects( self::never() )->method( 'release' );

		$this->controller = new HtmxCredentialValidationTestController(
			$dashboard,
			$providers,
			$secrets ?? new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			$lock,
			new CredentialUsageReader(),
			new InMemoryPublicRepositoryLookupProfileStore(),
			$expiry ?? new InMemoryCredentialExpiryObservationStore(),
			branch_check_evidence: $evidence,
			activity: $activity
		);

		return new Dispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$lock,
			provider_profile_interaction: $this->controller
		);
	}
}


// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused HTMX response spy.
final class HtmxCredentialValidationResponse extends \RuntimeException {

	public function __construct(
		public readonly string $credential_id,
		public readonly ?string $toast_message,
		public readonly ?string $error,
		public readonly int $status
	) {
		parent::__construct( 'HTMX response sent.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused HTMX response spy.
final class HtmxCredentialValidationTestController extends ProviderProfileAdminController {

	/** @var array{id:string,message:?string,error:?string,status:int}|null */
	public ?array $response = null;

	protected function respond_to_htmx_credential_validation( string $credential_id, ?string $message, ?string $error, int $status ): never {
		$this->response = array(
			'id'      => $credential_id,
			'message' => $message,
			'error'   => $error,
			'status'  => $status,
		);

		// The test spy captures its fixed method arguments without output.
		throw new HtmxCredentialValidationResponse( $credential_id, $message, $error, $status );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use Tests\Support\NullLoggingFacade;

final class PublicLookupProfileHtmxDispatcherTest extends TestCase {

	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HX_REQUEST'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	public function testOrdinaryPostKeepsTheExistingDashboardNoticeFlow(): void {
		$store     = new InMemoryPublicRepositoryLookupProfileStore();
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addMessage' )
			->with( 'Public repository lookup will use anonymous access.' );
		$dispatcher = $this->dispatcher( $dashboard, $store );

		$_POST['ran_booster'] = array(
			'action'     => 'save-public-lookup-profile',
			'provider'   => 'fixture',
			'profile_id' => '',
		);

		$dispatcher->dispatchPostRequests();

		self::assertSame( array(), $store->profiles );
		self::assertNull( $dispatcher->response );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( 'ran-booster-save-public-lookup-profile' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testHtmxPostReturnsTheNamedRegionAndSafeSuccessMessage(): void {
		$store     = new InMemoryPublicRepositoryLookupProfileStore();
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dispatcher                 = $this->dispatcher( $dashboard, $store );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'     => 'save-public-lookup-profile',
			'provider'   => 'fixture',
			'profile_id' => '',
		);

		try {
			$dispatcher->dispatchPostRequests();
			self::fail( 'An HTMX response must end the request after rendering its bounded fragment.' );
		} catch ( HtmxPublicLookupResponse $response ) {
			self::assertSame( 'fixture', $response->provider );
			self::assertSame( 'Public repository lookup will use anonymous access.', $response->toastMessage );
			self::assertNull( $response->error );
			self::assertSame( 200, $response->status );
		}
	}

	public function testHtmxValidationFailureIsScopedAndDoesNotClaimSuccess(): void {
		$store     = new InMemoryPublicRepositoryLookupProfileStore();
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static fn ( mixed $error ): bool => $error instanceof \WP_Error
						&& 'Choose Anonymous or a saved repository credential.' === $error->get_error_message()
				),
				self::isInstanceOf( \Throwable::class ),
				array(
					'operation' => 'save-public-lookup-profile',
					'step'      => 'public_lookup_profile',
				)
			);
		$dispatcher                 = $this->dispatcher( $dashboard, $store );
		$_SERVER['HTTP_HX_REQUEST'] = 'TRUE';
		$_POST['ran_booster']       = array(
			'action'     => 'save-public-lookup-profile',
			'provider'   => 'fixture',
			'profile_id' => 'not a saved profile',
		);

		try {
			$dispatcher->dispatchPostRequests();
			self::fail( 'An HTMX validation failure must return the local error fragment.' );
		} catch ( HtmxPublicLookupResponse $response ) {
			self::assertSame( 'fixture', $response->provider );
			self::assertNull( $response->toastMessage );
			self::assertSame( 'Choose Anonymous or a saved repository credential.', $response->error );
			self::assertSame( 422, $response->status );
		}
	}

	private function dispatcher( Dashboard $dashboard, InMemoryPublicRepositoryLookupProfileStore $store ): HtmxPublicLookupTestDispatcher {
		$providers = new ProviderRegistry( new NullLoggingFacade(), array( $this->provider() ) );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };
		$lock      = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::never() )->method( 'acquire' );
		$lock->expects( self::never() )->method( 'release' );

		return new HtmxPublicLookupTestDispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$lock,
			publicLookupProfiles: $store
		);
	}

	private function provider(): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return new class() implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'fixture' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				return new RepositoryBrowseResult( array() );
			}
		};
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused HTMX response spy.
final class HtmxPublicLookupResponse extends \RuntimeException {

	public function __construct(
		public readonly string $provider,
		public readonly ?string $toastMessage,
		public readonly ?string $error,
		public readonly int $status
	) {
		parent::__construct( 'HTMX response sent.' );
	}
}

final class HtmxPublicLookupTestDispatcher extends Dispatcher {

	/** @var array{provider:string,message:?string,error:?string,status:int}|null */
	public ?array $response = null;

	protected function respondToHtmxPublicLookupProfile( string $provider, ?string $message, ?string $error, int $status ): never {
		$this->response = array(
			'provider' => $provider,
			'message'  => $message,
			'error'    => $error,
			'status'   => $status,
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test spy captures its fixed method arguments without output.
		throw new HtmxPublicLookupResponse( $provider, $message, $error, $status );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile

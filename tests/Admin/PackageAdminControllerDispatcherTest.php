<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use WP_Error;

final class PackageAdminControllerDispatcherTest extends TestCase {

	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_nonce_checks']                = array();
		$GLOBALS['ran_booster_test_capabilities']                = array();
		$GLOBALS['ran_booster_test_nonce_valid']                 = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function unavailableProviderEdits(): array {
		return array(
			'plugin' => array(
				'edit-plugin',
				array(
					'file'       => 'fixture/fixture.php',
					'provider'   => 'gh',
					'repository' => 'owner/replacement',
				),
			),
			'theme'  => array(
				'edit-theme',
				array(
					'stylesheet' => 'fixture-theme',
					'provider'   => 'gh',
					'repository' => 'owner/replacement',
				),
			),
		);
	}

	/** @param array<string, string> $request */
	#[DataProvider( 'unavailableProviderEdits' )]
	public function testUnavailableStoredProviderRejectsEditBeforeResolvingTheSubmittedProvider(
		string $action,
		array $request
	): void {
		$package              = EditBoundaryPackage::make( reset( $request ), 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$submitted            = new ExternalFixtureProvider( 'gh' );
		$providers            = new ProviderRegistry( array( $submitted ) );
		$_POST['ran_booster'] = array_merge( array( 'action' => $action ), $request );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'ran_booster_unavailable_package_provider' === $message->get_error_code()
				)
			);
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $submitted->getClient()->getRequests() );
		self::assertSame( 1, $plugins->lookups + $themes->lookups );
	}

	public function testUnlinkRemainsAvailableWhenTheStoredProviderIsUnavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture/fixture.php', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlinkInput          = array(
			'action' => 'unlink-plugin',
			'file'   => 'fixture/fixture.php',
		);
		$_POST['ran_booster'] = $unlinkInput;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'postPackageOperation' )->with( 'unlink-plugin', $unlinkInput );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $plugins->lookups );
	}

	public function testThemeUnlinkRemainsAvailableWhenTheStoredProviderIsUnavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture-theme', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlinkInput          = array(
			'action'     => 'unlink-theme',
			'stylesheet' => 'fixture-theme',
		);
		$_POST['ran_booster'] = $unlinkInput;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'postPackageOperation' )->with( 'unlink-theme', $unlinkInput );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $themes->lookups );
	}

	#[DataProvider( 'trustedPublicLookupPackages' )]
	public function testEditSaveAndBranchCheckUsesTrustedPublicLookupOnlyForStoredPublicPackage( bool $private, ?string $expectedLookupCredential, bool $expectedPublicOnly ): void {
		$package          = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', $private, 'deployment-profile' );
		$plugins          = new EditBoundaryPluginRepository( $package );
		$themes           = new EditBoundaryThemeRepository( $package );
		$provider         = new CapturingPublicLookupProvider();
		$providers        = new ProviderRegistry( array( $provider ) );
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$request          = array(
			'action'                             => 'edit-plugin',
			'file'                               => 'fixture/fixture.php',
			'provider'                           => 'gh',
			'repository'                         => 'owner/replacement',
			'credential_id'                      => 'deployment-profile',
			'branch'                             => 'main',
			'deployment_policy'                  => 'manual',
			'check_repository_branch_after_save' => '1',
		);
		$dashboard        = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postPackageOperation' )
			->with(
				'edit-plugin',
				self::callback(
					static fn ( array $resolved ): bool => 'deployment-profile' === $resolved['credential_id']
						&& ! array_key_exists( 'public_lookup_profile_id', $resolved )
				)
			)
			->willReturn( 'https://example.test/redirect' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			publicLookupProfiles: $lookup
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertCount( 1, $provider->requests );
		self::assertSame( $expectedLookupCredential, $provider->requests[0]->credentialId );
		self::assertSame( $expectedPublicOnly, $provider->requests[0]->publicOnly );
	}

	public function testEditSaveAndBranchCheckKeepsAnonymousPublicLookupDistinctFromSubmittedPackageAccess(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', false, 'deployment-profile' );
		$plugins   = new EditBoundaryPluginRepository( $package );
		$themes    = new EditBoundaryThemeRepository( $package );
		$provider  = new CapturingPublicLookupProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'                             => 'edit-plugin',
			'file'                               => 'fixture/fixture.php',
			'provider'                           => 'gh',
			'repository'                         => 'owner/replacement',
			'credential_id'                      => 'deployment-profile',
			'branch'                             => 'main',
			'deployment_policy'                  => 'manual',
			'check_repository_branch_after_save' => '1',
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postPackageOperation' )
			->with(
				'edit-plugin',
				self::callback(
					static fn ( array $resolved ): bool => 'deployment-profile' === $resolved['credential_id']
						&& ! array_key_exists( 'public_lookup_profile_id', $resolved )
				)
			)
			->willReturn( 'https://example.test/redirect' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			publicLookupProfiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertCount( 1, $provider->requests );
		self::assertNull( $provider->requests[0]->credentialId );
		self::assertTrue( $provider->requests[0]->publicOnly );
	}

	public function testInvalidSubdirectoryNamesTheFieldBeforeProviderResolution(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$plugins   = new EditBoundaryPluginRepository( $package );
		$themes    = new EditBoundaryThemeRepository( $package );
		$provider  = new CapturingPublicLookupProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'                             => 'edit-plugin',
			'file'                               => 'fixture/fixture.php',
			'provider'                           => 'gh',
			'repository'                         => 'owner/replacement',
			'credential_id'                      => '',
			'branch'                             => 'main',
			'subdirectory'                       => '../fixture',
			'deployment_policy'                  => 'manual',
			'check_repository_branch_after_save' => '1',
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'Enter a repository-relative subdirectory. Do not use a leading slash, empty path segments, or current-directory and parent-directory segments.' === $message->get_error_message()
				)
			);
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertCount( 0, $provider->requests );
	}

	public function testEditSaveAndBranchCheckRefusesPublicPackageWhenProviderCannotMakeTrustedPublicLookup(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', false, 'deployment-profile' );
		$plugins   = new EditBoundaryPluginRepository( $package );
		$themes    = new EditBoundaryThemeRepository( $package );
		$provider  = new CapturingRepositoryProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'                             => 'edit-plugin',
			'file'                               => 'fixture/fixture.php',
			'provider'                           => 'gh',
			'repository'                         => 'owner/replacement',
			'credential_id'                      => 'deployment-profile',
			'branch'                             => 'main',
			'deployment_policy'                  => 'manual',
			'check_repository_branch_after_save' => '1',
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			publicLookupProfiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertSame( array(), $provider->requests );
	}

	public function testEditSaveAndBranchCheckRefusesPublicPackageWhenProviderDisallowsDefaultPublicProfile(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', false, 'deployment-profile' );
		$plugins   = new EditBoundaryPluginRepository( $package );
		$themes    = new EditBoundaryThemeRepository( $package );
		$provider  = new CapturingPublicLookupProvider( false );
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'                             => 'edit-plugin',
			'file'                               => 'fixture/fixture.php',
			'provider'                           => 'gh',
			'repository'                         => 'owner/replacement',
			'credential_id'                      => 'deployment-profile',
			'branch'                             => 'main',
			'deployment_policy'                  => 'manual',
			'check_repository_branch_after_save' => '1',
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'addFailureMessage' );
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			publicLookupProfiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertSame( array(), $provider->requests );
	}

	/** @return array<string, array{bool, string|null, bool}> */
	public static function trustedPublicLookupPackages(): array {
		return array(
			'public branch package' => array( false, 'public-profile', true ),
			'private package'       => array( true, 'deployment-profile', false ),
		);
	}

	private function dispatcher(
		Dashboard $dashboard,
		ProviderRegistry $providers,
		PluginRepository $plugins,
		ThemeRepository $themes
	): Dispatcher {
		return new Dispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class )
		);
	}
}

final class EditBoundaryPackage extends AbstractPackage {

	private function __construct( private readonly string $identifier ) {
	}

	public static function make( string $identifier, string $provider, bool $private = false, ?string $credentialId = null ): self {
		$package = new self( $identifier );
		$package->setRepository( new ManagedRepository( $provider, 'owner/original', 'repository-id', 'main', $private, $credentialId ) );

		return $package;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}
}

final class EditBoundaryPluginRepository extends PluginRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function boosterPluginFromFile( $file ) {
		++$this->lookups;

		return $this->package;
	}
}

final class EditBoundaryThemeRepository extends ThemeRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function boosterThemeFromStylesheet( $stylesheet ) {
		++$this->lookups;

		return $this->package;
	}
}

final class CapturingPublicLookupProvider implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<RepositoryLookupRequest> */
	public array $requests = array();

	public function __construct( private readonly bool $supportsDefaultPublicProfile = true ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( $this->supportsDefaultPublicProfile );
	}

	public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		return new RepositoryBrowseResult( array() );
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->requests[] = $request;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'replacement',
			'repository-id',
			false,
			'main',
			$request->credentialId
		);
	}

	public function prepareArchive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw new \RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

final class CapturingRepositoryProvider implements RepositoryProvider {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<RepositoryLookupRequest> */
	public array $requests = array();

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->requests[] = $request;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'replacement',
			'repository-id',
			true,
			'main',
			$request->credentialId
		);
	}

	public function prepareArchive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw new \RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

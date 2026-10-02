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
	public static function unavailable_provider_edits(): array {
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
	#[DataProvider( 'unavailable_provider_edits' )]
	public function test_unavailable_stored_provider_rejects_edit_before_resolving_the_submitted_provider(
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
			->method( 'add_failure_message' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'ran_booster_unavailable_package_provider' === $message->get_error_code()
				)
			);
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatch_post_requests();

		self::assertSame( 0, $submitted->getClient()->getRequests() );
		self::assertSame( 1, $plugins->lookups + $themes->lookups );
	}

	public function test_unlink_remains_available_when_the_stored_provider_is_unavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture/fixture.php', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlink_input         = array(
			'action' => 'unlink-plugin',
			'file'   => 'fixture/fixture.php',
		);
		$_POST['ran_booster'] = $unlink_input;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->with( 'unlink-plugin', $unlink_input );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatch_post_requests();

		self::assertSame( 0, $plugins->lookups );
	}

	public function test_theme_unlink_remains_available_when_the_stored_provider_is_unavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture-theme', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlink_input         = array(
			'action'     => 'unlink-theme',
			'stylesheet' => 'fixture-theme',
		);
		$_POST['ran_booster'] = $unlink_input;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->with( 'unlink-theme', $unlink_input );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatch_post_requests();

		self::assertSame( 0, $themes->lookups );
	}

	#[DataProvider( 'trusted_public_lookup_packages' )]
	public function test_edit_save_and_branch_check_uses_trusted_public_lookup_only_for_stored_public_package( bool $private, ?string $expected_lookup_credential, bool $expected_public_only ): void {
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
			->method( 'post_package_operation' )
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
			public_lookup_profiles: $lookup
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertCount( 1, $provider->requests );
		self::assertSame( $expected_lookup_credential, $provider->requests[0]->credential_id );
		self::assertSame( $expected_public_only, $provider->requests[0]->publicOnly );
	}

	public function test_edit_save_and_branch_check_keeps_anonymous_public_lookup_distinct_from_submitted_package_access(): void {
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
			->method( 'post_package_operation' )
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
			public_lookup_profiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertCount( 1, $provider->requests );
		self::assertNull( $provider->requests[0]->credential_id );
		self::assertTrue( $provider->requests[0]->publicOnly );
	}

	public function test_invalid_subdirectory_names_the_field_before_provider_resolution(): void {
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
			->method( 'add_failure_message' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'Enter a repository-relative subdirectory. Do not use a leading slash, empty path segments, or current-directory and parent-directory segments.' === $message->get_error_message()
				)
			);
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertCount( 0, $provider->requests );
	}

	public function test_edit_save_and_branch_check_refuses_public_package_when_provider_cannot_make_trusted_public_lookup(): void {
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
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			public_lookup_profiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertSame( array(), $provider->requests );
	}

	public function test_edit_save_and_branch_check_refuses_public_package_when_provider_disallows_default_public_profile(): void {
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
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: $plugins,
			themes: $themes,
			providers: $providers,
			public_lookup_profiles: new InMemoryPublicRepositoryLookupProfileStore()
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertFalse( $result );
		self::assertSame( array(), $provider->requests );
	}

	/** @return array<string, array{bool, string|null, bool}> */
	public static function trusted_public_lookup_packages(): array {
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

	public static function make( string $identifier, string $provider, bool $private = false, ?string $credential_id = null ): self {
		$package = new self( $identifier );
		$package->set_repository( new ManagedRepository( $provider, 'owner/original', 'repository-id', 'main', $private, $credential_id ) );

		return $package;
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}
}

final class EditBoundaryPluginRepository extends PluginRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function booster_plugin_from_file( $file ) {
		++$this->lookups;

		return $this->package;
	}
}

final class EditBoundaryThemeRepository extends ThemeRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function booster_theme_from_stylesheet( $stylesheet ) {
		++$this->lookups;

		return $this->package;
	}
}

final class CapturingPublicLookupProvider implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<RepositoryLookupRequest> */
	public array $requests = array();

	public function __construct( private readonly bool $supports_default_public_profile = true ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( $this->supports_default_public_profile );
	}

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		return new RepositoryBrowseResult( array() );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->requests[] = $request;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'replacement',
			'repository-id',
			false,
			'main',
			$request->credential_id
		);
	}

	public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw new \RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

final class CapturingRepositoryProvider implements RepositoryProvider {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<RepositoryLookupRequest> */
	public array $requests = array();

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->requests[] = $request;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'replacement',
			'repository-id',
			true,
			'main',
			$request->credential_id
		);
	}

	public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw new \RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

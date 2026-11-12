<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';

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
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
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
use RAN\Storage\GitHubSelectionStore;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use WP_Error;

final class PackageAdminControllerDispatcherTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_nonce_checks']                = array();
		$GLOBALS['ran_booster_test_capabilities']                = array();
		$GLOBALS['ran_booster_test_nonce_valid']                 = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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

	public function test_return_context_is_bounded_and_belongs_to_the_current_package_type(): void {
		$dashboard = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$method    = new \ReflectionMethod( Dashboard::class, 'package_return_context' );
		$_GET      = array(
			'return_type'     => 'plugin',
			'return_s'        => '  release  ',
			'return_provider' => 'GH!',
			'return_source'   => 'release_asset',
			'return_policy'   => 'automatic',
			'provider'        => 'bb',
			'return_url'      => 'https://evil.test/',
		);
		self::assertSame(
			array(
				's'        => 'release',
				'provider' => 'gh',
				'source'   => 'release_asset',
				'policy'   => 'automatic',
			),
			$method->invoke( $dashboard, 'plugin' )
		);
		self::assertSame( array(), $method->invoke( $dashboard, 'theme' ) );
		$_GET['return_source'] = array( 'branch' );
		$_GET['return_policy'] = 'invalid';
		$_GET['return_s']      = array( 'invalid' );
		self::assertSame( array( 'provider' => 'gh' ), $method->invoke( $dashboard, 'plugin' ) );
		$_GET = array();
	}

	public function test_recovery_is_post_only_and_checks_exact_confirmation_nonce(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$controller = new PackageAdminController();
		$request    = array(
			'identifier'              => 'example/example.php',
			'adoption_token'          => 'gh:1:11111111-1111-4111-8111-111111111111',
			'digest'                  => 'row',
			'installed_digest'        => 'files',
			'confirm_package_removal' => '0',
		);
		self::assertFalse( $controller->manage( $dashboard, 'recover-plugin', $request, false ) );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		self::assertFalse( $controller->manage( $dashboard, 'recover-plugin', $request, true ) );
		self::assertSame( array( 'update_plugins' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array( PackageAdminController::recovery_action( 'plugin', $request['identifier'], $request['adoption_token'], 'row', 'files' ) ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	/** @return array<string,array{string,string}> */
	public static function recovery_authorization_provider(): array {
		$cases = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'capability', 'nonce', 'confirmation', 'allowed' ) as $boundary ) {
				$cases[ $type . '-' . $boundary ] = array( $type, $boundary );
			}
		}
		return $cases;
	}

	#[DataProvider( 'recovery_authorization_provider' )]
	public function test_recovery_authorization_controls_actual_management_removal( string $type, string $boundary ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example';
		$token      = 'gh:repo-42:11111111-1111-4111-8111-111111111111';
		$record     = array(
			'package'        => $identifier,
			'adoption_token' => $token,
			'corrupt_policy' => 'invalid',
		);
		$original   = $record;
		$allowed    = 'allowed' === $boundary;
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$repository = 'plugin' === $type ? $plugins : $themes;
		$other      = 'plugin' === $type ? $themes : $plugins;
		$other->expects( self::never() )->method( 'recover_unlink' );
		$repository->expects( $allowed ? self::once() : self::never() )->method( 'recover_unlink' )
			->with( $identifier, $token, 'row', self::isCallable() )
			->willReturnCallback(
				static function ( string $target, string $lifetime, string $digest, callable $guard ) use ( &$record ): \RAN\Storage\PackageMutationResult {
					unset( $target, $lifetime, $digest );
					$guard();
					$record = null;
					return \RAN\Storage\PackageMutationResult::changed( \RAN\Storage\PackageStorageOperation::DELETE );
				}
			);
		$gateway = $this->createMock( \RAN\PackageRemoval\PackageRemovalGateway::class );
		$gateway->expects( $allowed ? self::once() : self::never() )->method( 'installed_fingerprint' )->with( $type, $identifier )->willReturn( 'files' );
		$gateway->expects( $allowed ? self::once() : self::never() )->method( 'invalidate_native_target' )->with( $type, $identifier );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( $allowed ? self::once() : self::never() )->method( 'try_acquire_without_reclaim' )->willReturn( 'fixture-lock' );
		$lock->expects( $allowed ? self::exactly( 2 ) : self::never() )->method( 'current_token' )->willReturn( 'fixture-lock' );
		$lock->expects( $allowed ? self::once() : self::never() )->method( 'release' )->with( 'fixture-lock' )->willReturn( true );
		$attempts   = new \RAN\Deployment\DeploymentAttemptRepository( new \RAN\Tests\Deployment\AttemptRepositoryDatabase(), 'wp_ran_booster_attempts', database_lifecycle: $this->createStub( \RAN\Storage\Database::class ) );
		$service    = new \RAN\PackageRemoval\PackageRemovalService( $plugins, $themes, $gateway, $attempts, $lock, github_selection: $this->selection() );
		$controller = new PackageAdminController( removals: $service );
		$dashboard  = $this->createMock( Dashboard::class );
		$dashboard->expects( in_array( $boundary, array( 'nonce', 'confirmation' ), true ) ? self::once() : self::never() )->method( 'add_failure_message' );
		$GLOBALS['ran_booster_test_capabilities'][ 'update_' . $type . 's' ] = 'capability' !== $boundary;
		$GLOBALS['ran_booster_test_nonce_valid']                             = 'nonce' !== $boundary;
		$request = array(
			'identifier'              => $identifier,
			'adoption_token'          => $token,
			'digest'                  => 'row',
			'installed_digest'        => 'files',
			'confirm_package_removal' => '1',
		);
		if ( 'confirmation' === $boundary ) {
			unset( $request['confirm_package_removal'] );
		}
		try {
			$result = $controller->manage( $dashboard, 'recover-' . $type, $request, true );
			if ( $allowed ) {
				self::assertIsString( $result );
				self::assertStringContainsString( 'relink=' . $identifier, $result );
			} else {
				self::assertNotSame( 'capability', $boundary, 'Denied capabilities must terminate the request.' );
				self::assertFalse( $result );
			}
		} catch ( \RuntimeException ) {
			self::assertSame( 'capability', $boundary );
		}
		self::assertSame( $allowed ? null : $original, $record );
		self::assertSame( array( 'update_' . $type . 's' ), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( 'capability' === $boundary ? array() : array( PackageAdminController::recovery_action( $type, $identifier, $token, 'row', 'files' ) ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	private function selection(): GitHubProviderSelection {
		$store   = new GitHubSelectionStore( new \RAN\Tests\RepositoryProvider\SelectionContextDatabase(), 'wp_options' );
		$bundled = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'booster.php',
				'implementation_id'      => 'bundled',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$store->initialize_bundled( $bundled );
		return new GitHubProviderSelection( $store, '/unused', $bundled );
	}

	public function test_unhydrated_package_retains_generic_provider_failure_without_lookup(): void {
		$package              = new EditBoundaryPackage( 'fixture/fixture.php' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$submitted            = new ExternalFixtureProvider( 'gh' );
		$providers            = new ProviderRegistry( array( $submitted ) );
		$_POST['ran_booster'] = array(
			'action'   => 'edit-plugin',
			'file'     => 'fixture/fixture.php',
			'provider' => 'gh',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' )->with(
			self::callback(
				static fn ( mixed $message ): bool => $message instanceof WP_Error
				&& 'ran_booster_unavailable_package_provider' === $message->get_error_code()
				&& 'Booster could not verify the managed package Git provider. No changes were made.' === $message->get_error_message()
			),
			self::isInstanceOf( \TypeError::class )
		);
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatch_post_requests();
		self::assertSame( 0, $submitted->get_client()->get_requests() );
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
		$identifier = reset( $request );
		self::assertIsString( $identifier );
		$package              = EditBoundaryPackage::make( $identifier, 'temporarily-offline' );
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

		self::assertSame( 0, $submitted->get_client()->get_requests() );
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
	public function test_edit_save_and_branch_check_uses_trusted_public_lookup_only_for_stored_public_package( bool $is_private, ?string $expected_lookup_credential, bool $expected_public_only ): void {
		$package          = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', $is_private, 'deployment-profile' );
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
		self::assertSame( $expected_public_only, $provider->requests[0]->public_only );
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
		self::assertTrue( $provider->requests[0]->public_only );
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

	public function test_rate_limited_provider_does_not_block_saving_an_unchanged_repository(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$provider  = new FailingLookupProvider( 429 );
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )
			->method( 'post_package_operation' )
			->with(
				'edit-plugin',
				self::callback(
					static fn ( array $resolved ): bool => '1' === $resolved['repository_check_skipped']
						&& 'stored' === $resolved['provider_repository_identity_source']
						&& 'repository-id' === $resolved['provider_repository_id']
						&& 'owner/original' === $resolved['repository']
				)
			)
			->willReturn( 'https://example.test/redirect' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertSame( 1, $provider->lookups, 'The provider is still asked first; only a failure falls back.' );
	}

	public function test_rate_limit_still_blocks_a_changed_repository_and_says_nothing_was_saved(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 429 ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'The Git provider rate limit has been reached. Nothing was saved. Try again later.' === $message->get_error_message()
				)
			);
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', array_merge( $this->unchanged_edit_request(), array( 'repository' => 'owner/replacement' ) ), true );

		self::assertFalse( $result );
	}

	#[DataProvider( 'definitive_lookup_failures' )]
	public function test_definitive_provider_answers_still_block_an_unchanged_repository( int $code ): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( $code ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );

		self::assertFalse( $result );
	}

	/** @return array<string, array{int}> */
	public static function definitive_lookup_failures(): array {
		return array(
			'credential rejected' => array( 401 ),
			'access denied'       => array( 403 ),
			'not found'           => array( 404 ),
			'no code'             => array( 0 ),
		);
	}

	public function test_a_browser_supplied_skipped_check_marker_is_discarded(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$provider  = new CapturingPublicLookupProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'post_package_operation' )
			->with(
				'edit-plugin',
				self::callback( static fn ( array $resolved ): bool => ! array_key_exists( 'repository_check_skipped', $resolved ) )
			)
			->willReturn( 'https://example.test/redirect' );

		( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', array_merge( $this->unchanged_edit_request(), array( 'repository_check_skipped' => '1' ) ), true );
	}

	/** @return array<string, mixed> */
	private function unchanged_edit_request(): array {
		return array(
			'action'            => 'edit-plugin',
			'file'              => 'fixture/fixture.php',
			'provider'          => 'gh',
			'repository'        => 'owner/original',
			'credential_id'     => '',
			'branch'            => 'main',
			'subdirectory'      => '',
			'deployment_policy' => 'manual',
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
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class )
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class EditBoundaryPackage extends AbstractPackage {

	public function __construct( private readonly string $identifier = '' ) {
	}

	public static function make( string $identifier, string $provider, bool $is_private = false, ?string $credential_id = null ): self {
		$package = new self( $identifier );
		$package->set_repository( new ManagedRepository( $provider, 'owner/original', 'repository-id', 'main', $is_private, $credential_id ) );

		return $package;
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class EditBoundaryPluginRepository extends PluginRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	/** @return Package */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_plugin_from_file retains the production method contract; these inputs do not affect this controlled result.
	public function booster_plugin_from_file( $file ) {
		++$this->lookups;

		return $this->package;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class EditBoundaryThemeRepository extends ThemeRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	/** @return Package */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_theme_from_stylesheet retains the production method contract; these inputs do not affect this controlled result.
	public function booster_theme_from_stylesheet( $stylesheet ) {
		++$this->lookups;

		return $this->package;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class CapturingPublicLookupProvider implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

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

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class FailingLookupProvider implements RepositoryProvider {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $lookups = 0;

	public function __construct( private readonly int $code ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		unset( $request );
		++$this->lookups;

		throw new \RuntimeException( 'Provider lookup failed.', $this->code );
	}

	public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw new \RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class CapturingRepositoryProvider implements RepositoryProvider {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

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

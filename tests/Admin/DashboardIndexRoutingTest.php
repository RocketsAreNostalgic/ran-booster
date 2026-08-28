<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\AdminTabRegistry;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Booster;
use RAN\Dashboard;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Logging\BoosterLogger;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RAN\Troubleshooting\TroubleshootingService;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use Tests\Deployment\AttemptRepositoryDatabase;
use Tests\Support\CredentialUsageDatabase;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/DocumentationHookWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Dashboard.php';

final class DashboardIndexRoutingTest extends TestCase {

	protected function setUp(): void {
		$_GET = array();
		$GLOBALS['ran_booster_dashboard_test_multisite']         = false;
		$GLOBALS['ran_booster_package_view_multisite']           = false;
		$GLOBALS['ran_booster_dashboard_test_environment_type']  = 'production';
		$GLOBALS['ran_booster_dashboard_test_development_modes'] = array();
		$GLOBALS['ran_booster_dashboard_test_user_id']           = 7;
		$GLOBALS['ran_booster_dashboard_test_user_meta']         = array();
		$GLOBALS['ran_booster_dashboard_test_transients']        = array();
		$GLOBALS['ran_booster_dashboard_test_actions']           = array();
		$GLOBALS['ran_booster_dashboard_test_filters']           = array();
		$GLOBALS['ran_booster_admin_view_actions']               = array();
		$GLOBALS['ran_booster_admin_view_filters']               = array();
		$GLOBALS['ran_booster_documentation_test_filters']       = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset(
			$GLOBALS['ran_booster_dashboard_test_multisite'],
			$GLOBALS['ran_booster_package_view_multisite'],
			$GLOBALS['ran_booster_dashboard_test_environment_type'],
			$GLOBALS['ran_booster_dashboard_test_development_modes'],
			$GLOBALS['ran_booster_dashboard_test_user_id'],
			$GLOBALS['ran_booster_dashboard_test_user_meta'],
			$GLOBALS['ran_booster_dashboard_test_transients'],
			$GLOBALS['ran_booster_dashboard_test_actions'],
			$GLOBALS['ran_booster_dashboard_test_filters'],
			$GLOBALS['ran_booster_admin_view_actions'],
			$GLOBALS['ran_booster_admin_view_filters'],
			$GLOBALS['ran_booster_documentation_test_filters']
		);
		unset( $GLOBALS['ran_booster_repository_admin_user_id'] );
		unset( $GLOBALS['ran_booster_test_capabilities'] );
	}

	public function testDevelopmentSafetyNoticeDismissalIsScopedToTheCurrentAdministrator(): void {
		$GLOBALS['ran_booster_dashboard_test_environment_type'] = 'local';
		$GLOBALS['ran_booster_dashboard_test_user_meta'][7][ DevelopmentSafetyNoticeController::USER_META_KEY ] = '1';
		$predicate = new ReflectionMethod( Dashboard::class, 'shouldShowDevelopmentSafetyNotice' );
		$dashboard = $this->dashboard( $this->throwingSecrets() );

		self::assertFalse( $predicate->invoke( $dashboard, 'packages/index', array(), true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', array(), true ) );

		$GLOBALS['ran_booster_dashboard_test_user_id'] = 8;

		self::assertTrue( $predicate->invoke( $dashboard, 'packages/index', array(), true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', array(), true ) );
	}

	public function testRepositoryBranchCheckRejectsMissingAndStaleNonceWithoutProviderWork(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );

		self::assertNull( $check->invoke( $dashboard, $package, 'plugin' ) );

		$_GET = array( 'ran_booster_repository_branch_check' => '1' );
		self::assertNull( $check->invoke( $dashboard, $package, 'plugin' ) );

		$_GET['_ran_booster_repository_branch_nonce'] = \RAN\wp_create_nonce(
			'ran-booster-repository-branch-check|plugin|different/example.php|branch|1'
		);
		self::assertNull( $check->invoke( $dashboard, $package, 'plugin' ) );

		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		$_GET['_ran_booster_repository_branch_nonce']               = \RAN\wp_create_nonce(
			'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
		);
		self::assertNull( $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 0, $provider->prepareCalls );
	}

	public function testRepositoryBranchCheckUsesExactSavedTargetAndCleansPreparedAuthority(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managedPackage(
			'example/example.php',
			'Example',
			'repo-42',
			repository: 'owner/example',
			branch: 'feature/test'
		);
		$check     = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepareCalls );
		self::assertSame( 1, $provider->resolvedRefCalls );
		self::assertSame( 1, $provider->cleanupCalls );
		self::assertSame( 'owner/example', $provider->request?->repository->locator );
		self::assertSame( 'repo-42', $provider->request?->repository->providerRepositoryId );
		self::assertSame( 'feature/test', $provider->request?->ref );
		self::assertNull( $provider->request?->expectedBranch );
		self::assertFalse( $provider->request?->repository->private );
		self::assertNull( $provider->request?->repository->credentialId );
	}

	public function testRepositoryBranchCheckConsumesItsOneTimeMarkerBeforeRepeatingRemoteWork(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepareCalls );
	}

	public function testRepositoryBranchCheckIsCapturedAsSanitizedOperationalEvidence(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$directory = sys_get_temp_dir() . '/ran-booster-branch-check-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only private fixture directory.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
		$capture->start();
		BoosterLogger::configureCapture( $capture );
		try {
			$provider  = new DashboardBranchCheckProvider();
			$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
			$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42' );
			$_GET      = array(
				'ran_booster_repository_branch_check'  => '1',
				'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
			);
			( new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' ) )->invoke( $dashboard, $package, 'plugin' );
			$line = $capture->snapshot()['entries'][0]['line'];
			self::assertStringContainsString( 'repository branch check completed', $line );
			self::assertStringContainsString( '"event":"repository_branch_checked"', $line );
			self::assertStringNotContainsString( 'owner/repository', $line );
		} finally {
			BoosterLogger::configureCapture( null );
			foreach ( array( $directory . '/ran-booster-debug.php', $directory . '/ran-booster-debug.php.lock' ) as $path ) {
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fixture cleanup.
					unlink( $path );
				}
			}
			if ( is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only fixture cleanup.
				rmdir( $directory );
			}
		}
	}

	public function testRepositoryBranchCheckUsesProviderDefaultPublicLookupProfileInsteadOfPackageCredential(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$dashboard        = $this->dashboard(
			$this->throwingSecrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			publicLookupProfiles: $lookup
		);
		$package          = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', credentialId: 'deployment-profile' );
		$check            = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 'owner/repository', $provider->request?->repository->locator );
		self::assertSame( 'repo-42', $provider->request?->repository->providerRepositoryId );
		self::assertFalse( $provider->request?->repository->private );
		self::assertSame( 'public-profile', $provider->request?->repository->credentialId );
	}

	public function testRepositoryBranchCheckDoesNotRewritePrivatePackageAccessAsPublicLookup(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$dashboard        = $this->dashboard(
			$this->throwingSecrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			publicLookupProfiles: $lookup
		);
		$package          = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', credentialId: 'deployment-profile', private: true );
		$check            = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertTrue( $provider->request?->repository->private );
		self::assertSame( 'deployment-profile', $provider->request?->repository->credentialId );
	}

	public function testRepositoryBranchCheckVerifiesAConfiguredSubdirectoryWhenTheProviderSupportsIt(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', ( new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->pathCalls );
		self::assertSame( 'packages/example', $provider->path );
	}

	public function testRepositoryBranchCheckReportsAMissingConfiguredSubdirectoryPrecisely(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( pathExists: false );
		$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/missing' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unavailable', ( new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->pathCalls );
		self::assertSame( 1, $provider->cleanupCalls );
	}

	public function testRepositoryBranchCheckDistinguishesAnUnavailablePathCheckFromAMissingPath(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( pathCheckFails: true );
		$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unverified', ( new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->pathCalls );
		self::assertSame( 1, $provider->cleanupCalls );
	}

	public function testRepositoryBranchCheckDoesNotClaimAnUninspectedSubdirectoryIsVerified(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProviderWithoutPathInspector();
		$dashboard = $this->dashboard( $this->throwingSecrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unverified', ( new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 0, $provider->pathCalls );
	}

	public function testRepositoryBranchCheckFailsClosedWhenCleanupFails(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( cleanupFails: true );
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managedPackage( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requestedPackageRepositoryBranchCheck' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepareCalls );
		self::assertSame( 1, $provider->cleanupCalls );
	}

	/** @return list<array{string, array<string, string>, bool, bool}> */
	public static function developmentSafetyNoticeProvider(): array {
		return array(
			array( 'packages/index', array(), true, true ),
			array( 'packages/create', array(), true, false ),
			array( 'packages/edit', array(), true, false ),
			array( 'index', array( 'tab' => 'portability' ), true, false ),
			array( 'index', array( 'tab' => 'documentation' ), true, false ),
			array( 'packages/index', array(), false, false ),
		);
	}

	/**
	 * @param array<string, string> $data             Selected view data.
	 */
	#[DataProvider( 'developmentSafetyNoticeProvider' )]
	public function testDevelopmentSafetyNoticeUsesDetectedEnvironmentOnlyOnThePackageIndex( string $view, array $data, bool $developmentEnvironmentDetected, bool $expected ): void {
		$predicate = new ReflectionMethod( Dashboard::class, 'shouldShowDevelopmentSafetyNotice' );

		self::assertSame( $expected, $predicate->invoke( $this->dashboard( $this->throwingSecrets() ), $view, $data, $developmentEnvironmentDetected ) );
	}

	public function testProviderTabBuildsOnlyTheSelectedProviderSettings(): void {
		$_GET['tab'] = 'bb';

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'bb', $data['tab'] );
		self::assertSame( 'provider.php', $data['tabView'] );
		self::assertSame( 'bb', $data['selected_provider'] );
		self::assertSame( 'Bitbucket', $data['provider']['label'] );
		self::assertSame(
			array( false, false, true, false, false, false ),
			array_column( $data['tabs'], 'active' )
		);
		self::assertArrayNotHasKey( 'onboarding', $data );
	}

	public function testProviderRouteSelectsFocusedViewsTasksAndBoundedListState(): void {
		$_GET = array(
			'tab'             => 'bb',
			'view'            => 'secrets',
			'panel'           => 'setup',
			'repository_view' => 'releases',
			's'               => ' workspace ',
			'scope'           => 'owner',
			'status'          => 'ready',
			'orderby'         => 'usage',
			'order'           => 'desc',
		);

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'secrets', $data['providerView'] );
		self::assertSame( 'setup', $data['providerTask'] );
		self::assertSame( 'releases', $data['repositoryView'] );
		self::assertSame(
			array(
				'search'   => 'workspace',
				'kind'     => '',
				'scope'    => 'owner',
				'status'   => 'ready',
				'orderby'  => 'usage',
				'order'    => 'desc',
				'paged'    => 1,
				'per_page' => 20,
			),
			$data['providerListState']
		);

		$_GET['view']            = 'unknown';
		$_GET['panel']           = 'unknown';
		$_GET['repository_view'] = 'unknown';
		$_GET['orderby']         = 'unknown';

		$fallback = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'overview', $fallback['providerView'] );
		self::assertSame( 'status', $fallback['providerTask'] );
		self::assertSame( 'status', $fallback['repositoryView'] );
		self::assertSame( 'name', $fallback['providerListState']['orderby'] );
	}

	public function testProviderRouteRendersThePreparedAccessibleFilteredAndPaginatedOutcome(): void {
		$_GET     = array(
			'tab'      => 'bb',
			'view'     => 'credentials',
			's'        => 'Credential',
			'kind'     => 'api-key',
			'orderby'  => 'name',
			'order'    => 'asc',
			'per_page' => '20',
		);
		$profiles = array();
		for ( $index = 1; $index <= 21; ++$index ) {
			$profiles[] = array(
				'id'            => 'profile-' . $index,
				'label'         => sprintf( 'Credential %02d', $index ),
				'kind'          => 'api-key',
				'configuration' => array(),
				'source'        => 'file',
				'configured'    => true,
			);
		}
		$profiles[] = array(
			'id'            => 'filtered-canary',
			'label'         => 'Filtered canary',
			'kind'          => 'other',
			'configuration' => array(),
			'source'        => 'file',
			'configured'    => true,
		);
		$secrets    = new class( $profiles ) extends SecretsFile {
			/** @param list<array<string,mixed>> $profiles */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/test-secrets.php', array(), ShippedSecretPolicyCatalog::create() );
			}
			public function credentialProfiles( ProviderCode|string $provider ): array {
				return 'bb' === (string) $provider ? $this->profiles : array();
			}
			public function webhookProfiles( ProviderCode|string $provider ): array {
				unset( $provider );
				return array();
			}
		};
		$data       = $this->dashboard( $secrets, providerCredentials: true )->getIndex()['data'];

		// Dashboard supplies a fixed provider-route model; the passive view only renders and escapes it.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data );
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h2 id="ran-booster-provider-heading"', $html );
		self::assertStringContainsString( '>Credentials</h2>', $html );
		self::assertStringContainsString( 'aria-labelledby="ran-booster-provider-heading"', $html );
		self::assertStringContainsString( '<th scope="col">', $html );
		self::assertStringContainsString( 'Page 1 of 2', $html );
		self::assertStringContainsString( '>Credential 01</strong>', $html );
		self::assertStringContainsString( '>Credential 20</strong>', $html );
		self::assertStringNotContainsString( '>Credential 21</strong>', $html );
		self::assertStringNotContainsString( 'Filtered canary', $html );
		self::assertStringContainsString( 'paged=2', $html );
		self::assertStringNotContainsString( 'profile-21', $html );
	}

	public function testProviderRouteRendersANormalizedRepositorySelection(): void {
		$_GET    = array(
			'tab'        => 'bb',
			'panel'      => 'repositories',
			'repository' => 'repo-route',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'plugin/route.php'     => $this->managedPackage(
					'plugin/route.php',
					'Route Plugin',
					'repo-route',
					\RAN\PackageSource::RELEASE_ASSET,
					'bb',
					repository: 'workspace/route'
				),
				'plugin/automatic.php' => $this->managedPackage(
					'plugin/automatic.php',
					'Automatic Plugin',
					'repo-automatic',
					provider: 'bb',
					policy: \RAN\Deployment\DeploymentPolicy::AUTOMATIC,
					repository: 'workspace/automatic'
				),
			)
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			providerCredentials: true
		)->getIndex()['data'];

		self::assertSame( 'repo-route', $data['requestedRepositoryId'] );
		self::assertSame( 'repo-route', $data['selectedRepositoryRow']['repository_id'] );
		self::assertSame( 'attention', $data['webhookSummary']['tone'] );
		self::assertStringContainsString( 'Automatic branch deployments require local signing material', $data['webhookSummary']['description'] );
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed route model is rendered through the production view.
		extract( $data );
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Integration status', $html );
		self::assertStringContainsString( 'data-ran-booster-repository-view="branch"', $html );
		self::assertStringContainsString( 'Back to repositories', $html );
		self::assertStringContainsString( 'Packages using this repository', $html );
		self::assertStringContainsString( 'ran-booster-repository-detail__layout', $html );
		self::assertStringContainsString( 'ran-booster-repository-detail__sidebar', $html );
		self::assertStringContainsString( 'Management history', $html );
		self::assertStringContainsString( 'workspace/route', $html );
		self::assertStringNotContainsString( 'data-ran-booster-provider-repository-filter', $html );
		self::assertStringNotContainsString( 'Repository access', $html );
		self::assertStringNotContainsString( 'Public repository lookup', $html );
		self::assertStringNotContainsString( 'data-ran-booster-provider-task="status"', $html );
		self::assertStringNotContainsString( 'ran-booster-provider__footer', $html );
	}

	public function testProviderRepositoryProjectionUnifiesPackageTypesAndSourcesByStableIdentity(): void {
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				$this->managedPackage(
					'plugin/shared.php',
					'Shared Plugin',
					'repo-shared',
					provider: 'bb',
					repository: 'workspace/shared',
					subdirectory: 'packages/plugin'
				),
			)
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn(
			array(
				$this->managedPackage(
					'shared-theme',
					'Shared Theme',
					'repo-shared',
					\RAN\PackageSource::RELEASE_ASSET,
					'bb',
					repository: 'workspace/shared'
				),
			)
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes,
			providerCredentials: true
		)->getIndex()['data'];

		self::assertCount( 1, $data['provider_repositories']['repositories'] );
		$repository = $data['provider_repositories']['repositories'][0];
		self::assertSame( 'repo-shared', $repository['repository_id'] );
		self::assertSame( 'mixed', $repository['source'] );
		self::assertSame( array( 'plugin/shared.php' ), $repository['branch_package_references'] );
		self::assertSame( array( 'plugin', 'theme' ), array_column( $repository['package_summaries'], 'type' ) );
		self::assertSame( 'packages/plugin', $repository['package_summaries'][0]['subdirectory'] );
		self::assertCount( 1, $data['managed_webhook_repositories']['repositories'] );
		self::assertSame( 'branch', $data['managed_webhook_repositories']['repositories'][0]['source'] );
		self::assertSame( 'Mixed sources', $data['repositoryTableRows'][0]['source_label'] );
	}

	public function testProviderRepositoryProjectionFailsClosedForConflictingStableIdentity(): void {
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				$this->managedPackage( 'plugin/one.php', 'One', 'repo-conflict', provider: 'bb', repository: 'workspace/one' ),
				$this->managedPackage( 'plugin/two.php', 'Two', 'repo-conflict', provider: 'bb', repository: 'workspace/two' ),
				$this->managedPackage( 'plugin/three.php', 'Three', 'repo-three', provider: 'bb', repository: 'workspace/shared-locator' ),
				$this->managedPackage( 'plugin/four.php', 'Four', 'repo-four', provider: 'bb', repository: 'workspace/shared-locator' ),
			)
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			providerCredentials: true
		)->getIndex()['data'];

		self::assertCount( 3, $data['repositoryTableRows'] );
		foreach ( $data['repositoryTableRows'] as $row ) {
			self::assertTrue( $row['historical'] );
			self::assertSame( array(), $row['actions'] );
			self::assertSame( '', $row['repository_url'] );
			self::assertSame( 'Repository identity conflict', $row['statuses'][0]['label'] );
		}
	}

	public function testSelectedAddOnUsesTheRegisteredTabAndSafeContext(): void {
		$registry = new AdminAddOnRegistry( array(), 7, 7 );
		$registry->register(
			new AdminAddOnTab(
				'ran-booster-fixture',
				'fixture',
				'Fixture',
				static function (): void {},
				7,
				7,
				7,
				7
			)
		);
		$registry->seal();
		$_GET['tab'] = 'fixture';

		$data = $this->dashboard( $this->throwingSecrets(), adminAddOns: $registry )->getIndex()['data'];

		self::assertSame( 'fixture', $data['tab'] );
		self::assertArrayNotHasKey( 'tabView', $data );
		self::assertInstanceOf( AdminAddOnTab::class, $data['addOnTab'] );
		self::assertSame( 'fixture', $data['addOnContext']->tabKey() );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture',
			$data['addOnContext']->boosterUrl()
		);
		self::assertSame( array( false, false, false, false, false, false, true ), array_column( $data['tabs'], 'active' ) );
	}

	public function testPortabilityBuildsDisplaySafeRowsFromTheNonCleaningInventory(): void {
		$_GET['tab'] = 'portability';
		$plugin      = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$theme       = $this->managedPackage( 'example-theme', 'Example Theme', 'theme-repository-id' );
		$plugins     = $this->createStub( PluginRepository::class );
		$themes      = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}
			public function credentialProfiles( ProviderCode|string $provider ): array {
				return array();
			}
		};
		$data    = $this->dashboard( $secrets, null, null, null, $plugins, $themes )->getIndex()['data'];

		self::assertFalse( $data['portabilityExportUnavailable'] );
		self::assertSame(
			array(
				array(
					'name'       => 'Example Plugin',
					'identifier' => 'plugin/example.php',
					'type'       => 'plugin',
				),
				array(
					'name'       => 'Example Theme',
					'identifier' => 'example-theme',
					'type'       => 'theme',
				),
			),
			$data['portabilityExportRows']
		);
		self::assertSame( array(), $data['portabilityExportCredentialGroups'] );
		self::assertFalse( $data['portabilityExportCredentialsUnavailable'] );
	}

	public function testNativeTransporterRouteForcesTheCanonicalTabWithoutMutatingTheRequest(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Fixture verifies the route leaves the request intact.
		$_GET = array( 'tab' => 'documentation' );

		$data = $this->dashboard( $this->throwingSecrets() )->getTransporter()['data'];

		self::assertSame( array( 'tab' => 'documentation' ), $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		self::assertSame( 'portability', $data['tab'] );
		self::assertSame( 'portability.php', $data['tabView'] );
		self::assertSame( array( false, false, false, true, false, false ), array_column( $data['tabs'], 'active' ) );
	}

	public function testEmptyWordPressActionArgumentLeavesCanonicalTabRoutingIntact(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixture models WordPress's empty action argument.
		$_GET = array( 'tab' => 'documentation' );

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex( '' )['data'];

		self::assertSame( 'documentation', $data['tab'] );
		self::assertSame( 'documentation.php', $data['tabView'] );
	}

	public function testPortabilityGroupsOnlyDisplaySafeCredentialMetadataAndKeepsPackageOnlyFallback(): void {
		$_GET['tab'] = 'portability';
		$plugin      = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id', credentialId: 'shared-profile' );
		$theme       = $this->managedPackage( 'example-theme', 'Example Theme', 'theme-repository-id', credentialId: 'shared-profile' );
		$plugins     = $this->createStub( PluginRepository::class );
		$themes      = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );
		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}
			public function credentialProfiles( ProviderCode|string $provider ): array {
				return array(
					'shared-profile'       => array(
						'id'            => 'shared-profile',
						'provider'      => 'gh',
						'label'         => 'Shared credential',
						'kind'          => 'classic',
						'configuration' => array( 'secret_context' => 'configuration-canary' ),
						'source'        => 'file',
						'configured'    => true,
						'self_destruct' => false,
					),
					'unassociated-profile' => array(
						'id'            => 'unassociated-profile',
						'provider'      => 'gh',
						'label'         => 'Unused credential',
						'kind'          => 'classic',
						'configuration' => array( 'secret_context' => 'unused-configuration-canary' ),
						'source'        => 'file',
						'configured'    => true,
						'self_destruct' => false,
					),
				);
			}
		};

		$data = $this->dashboard( $secrets, null, null, null, $plugins, $themes )->getIndex()['data'];

		self::assertFalse( $data['portabilityExportCredentialsUnavailable'] );
		self::assertSame( array( 'code', 'label', 'credentials' ), array_keys( $data['portabilityExportCredentialGroups'][0] ) );
		self::assertCount( 2, $data['portabilityExportCredentialGroups'][0]['credentials'] );
		$credential = $data['portabilityExportCredentialGroups'][0]['credentials'][0];
		self::assertSame( array( 'id', 'label', 'kind_label', 'available', 'reason', 'destroy_on', 'packages' ), array_keys( $credential ) );
		self::assertSame( array( 'Example Plugin', 'Example Theme' ), array_column( $credential['packages'], 'name' ) );
		self::assertArrayNotHasKey( 'configuration', $credential );
		$unassociated = $data['portabilityExportCredentialGroups'][0]['credentials'][1];
		self::assertFalse( $unassociated['available'] );
		self::assertSame( 'unassociated', $unassociated['reason'] );
		self::assertSame( array(), $unassociated['packages'] );
		self::assertArrayNotHasKey( 'configuration', $unassociated );
		self::assertStringNotContainsString( 'unused-configuration-canary', (string) wp_json_encode( $data['portabilityExportCredentialGroups'] ) );

		$unavailable = $this->dashboard( $this->throwingSecrets(), null, null, null, $plugins, $themes )->getIndex()['data'];
		self::assertFalse( $unavailable['portabilityExportUnavailable'] );
		self::assertCount( 2, $unavailable['portabilityExportRows'] );
		self::assertTrue( $unavailable['portabilityExportCredentialsUnavailable'] );
	}

	/** @return list<array{string, string}> */
	public static function staticTabProvider(): array {
		return array(
			array( 'overview', 'onboarding.php' ),
			array( 'documentation', 'documentation.php' ),
			array( 'troubleshooting', 'troubleshooting.php' ),
		);
	}

	#[DataProvider( 'staticTabProvider' )]
	public function testStaticTabsDoNotReadCredentialOrWebhookProfiles( string $key, string $view ): void {
		$_GET['tab'] = $key;

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( $key, $data['tab'] );
		self::assertSame( $view, $data['tabView'] );
		self::assertArrayNotHasKey( 'provider', $data );
		self::assertSame( 1, count( array_filter( $data['tabs'], static fn ( array $tab ): bool => $tab['active'] ) ) );
		self::assertSame( 'documentation' === $key, array_key_exists( 'providerDocumentation', $data ) );
		self::assertSame( 'overview' === $key, array_key_exists( 'onboarding', $data ) );

		if ( 'documentation' === $key ) {
			self::assertSame( array( 'gh', 'bb' ), array_column( $data['providerDocumentation'], 'code' ) );
		} elseif ( 'overview' === $key ) {
			self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
		}
	}

	/** @return list<array{mixed}> */
	public static function fallbackTabProvider(): array {
		return array(
			array( null ),
			array( '' ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
		);
	}

	#[DataProvider( 'fallbackTabProvider' )]
	public function testMissingUnknownAndArrayTabsFallBackWithoutDerivingAView( mixed $requested ): void {
		if ( null !== $requested ) {
			$_GET['tab'] = $requested;
		}

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tabView'] );
		self::assertArrayNotHasKey( 'selected_provider', $data );
		self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-transporter',
			$data['onboarding']['portability_url']
		);
	}

	public function testNavigationUsesTheCorrectSingleAndNetworkAdminBases(): void {
		$_GET['tab'] = 'documentation';
		$singleSite  = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
			$singleSite['tabs'][0]['url']
		);

		$this->setMultisite( true );
		$networkSite = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=overview',
			$networkSite['tabs'][0]['url']
		);

		$_GET['tab']     = 'overview';
		$networkOverview = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];
		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster-plugins-create',
			$networkOverview['onboarding']['install_plugin_url']
		);
	}

	public function testLegacyAddOnTabRequestsFallBackToTheOverview(): void {
		$_GET['tab'] = 'assisted-hooks';

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tabView'] );
		self::assertArrayNotHasKey( 'addOnTab', $data );
	}

	public function testNativePackageHooksReceiveBoundedProjectionsForSettingsRowsAndActions(): void {
		$settingsReads   = array();
		$managementReads = array();
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][]          =
			static function ( \RAN\Admin\AdminPackageProjection $package, string $settingsUrl ) use ( &$settingsReads ): void {
				$settingsReads[] = array( $package->identifier(), $settingsUrl );
				echo '<section>Release settings</section>';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][]    =
			static function ( array $rows, string $surface, array $packages ) use ( &$managementReads ): array {
				$managementReads[] = array( $surface, array_keys( $packages ) );

				$rows['plugin/example.php'] = array(
					'badges' => array(
						array(
							'label' => 'Release',
							'tone'  => 'ok',
						),
					),
					'status' => 'Latest release: 1.1.0.',
				);

				return $rows;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_actions'][] =
			static function ( array $actions, string $surface, \RAN\Admin\AdminPackageProjection $package ): array {
				self::assertSame( 'plugin', $surface );

				return $actions + array(
					'fixture:manage' => array(
						'label' => 'Manage releases',
						'type'  => 'link',
						'url'   => $package->settingsUrl(),
					),
				);
			};
		$package = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$plugins->method( 'allBoosterPlugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins );

		$_GET  = array( 'package' => 'plugin/example.php' );
		$edit  = $dashboard->getPlugins()['data'];
		$_GET  = array();
		$index = $dashboard->getPlugins()['data'];

		self::assertSame(
			array(
				array(
					'plugin/example.php',
					'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
				),
			),
			$settingsReads
		);
		self::assertSame( array( array( 'plugin', array( 'plugin/example.php' ) ) ), $managementReads );
		self::assertSame( array( '<section>Release settings</section>' ), $edit['packageExtensionPanels'] );
		self::assertNull( $edit['repositoryBranchCheckOutcome'] );
		self::assertArrayNotHasKey( 'repositoryBranchCheckNonce', $edit );
		self::assertSame( 'Latest release: 1.1.0.', $index['packageExtensionRows']['plugin/example.php']['status'] );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$index['packageExtensionActions']['plugin/example.php']['fixture:manage']['url']
		);
	}

	public function testFailingNativePackageHooksDoNotBreakCorePackagePages(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][]       =
			static function (): void {
				throw new RuntimeException( 'Settings unavailable.' );
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][] =
			static function (): array {
				throw new RuntimeException( 'Management unavailable.' );
			};
		$package = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$plugins->method( 'allBoosterPlugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins );

		$_GET = array( 'package' => 'plugin/example.php' );
		self::assertSame( array(), $dashboard->getPlugins()['data']['packageExtensionPanels'] );
		$_GET = array();
		self::assertSame( array(), $dashboard->getPlugins()['data']['packageExtensionRows'] );
	}

	public function testExplicitPackageSourceViewUsesOnlySharedAdvancedSectionsForPluginsAndThemes(): void {
		$_GET['source_view'] = 'branch';
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_advanced_source_sections'][] =
			static function (): void {
				echo '<section>Advanced source settings</section>';
			};
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$identifier = 'plugin' === $type ? 'plugin/example.php' : 'example-theme';
			$package    = $this->managedPackage( $identifier, 'Example Package', 'repository-id' );
			$plugins    = $this->createStub( PluginRepository::class );
			$themes     = $this->createStub( ThemeRepository::class );
			$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
			$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
			$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
			$_GET      = array(
				'package'     => $identifier,
				'source_view' => 'branch',
			);
			$data      = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];

			self::assertFalse( $data['packageSource']['advanced_open'], $type );
			self::assertSame( array( '<section>Advanced source settings</section>' ), $data['packageSource']['advanced_sections'], $type );
			self::assertArrayNotHasKey( 'sections', $data['packageSource'], $type );
		}
	}

	public function testExplicitAdvancedOpenFlagOpensTheSelectedSourceView(): void {
		$package = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins );
		$_GET      = array(
			'package'                   => 'plugin/example.php',
			'source_view'               => 'branch',
			'ran_booster_open_advanced' => '1',
		);

		$data = $dashboard->getPlugins()['data'];

		self::assertTrue( $data['packageSource']['advanced_open'] );
		self::assertSame( 'branch', $data['packageSource']['selected'] );
	}

	public function testAdvancedSourceSummaryProjectionIncludesTheSavedBranchSubdirectory(): void {
		$package = $this->managedPackage(
			'plugin/example.php',
			'Example Plugin',
			'plugin-repository-id',
			subdirectory: 'packages/example'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins );
		$_GET      = array( 'package' => 'plugin/example.php' );

		$data = $dashboard->getPlugins()['data'];

		self::assertSame(
			array(
				'heading' => 'Branch',
				'badges'  => array(
					array(
						'label' => 'packages/example',
					),
				),
				'status'  => 'Active',
			),
			$data['packageSource']['advanced_summary_projection']
		);
		self::assertSame( 'Releases', $data['packageSource']['choices']['release_asset']['heading'] );
	}

	public function testReleaseDeploymentHooksReceiveExactOuterCreateEditAndIndexArguments(): void {
		$sourceCalls  = array();
		$sectionCalls = array();
		$summaryCalls = array();
		$rowCalls     = array();
		$actionCalls  = array();
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_source_choices'][]          =
			static function ( array $choices, string $mode, string $type, ?\RAN\Admin\AdminPackageProjection $package, string $pageUrl ) use ( &$sourceCalls ): array {
				$sourceCalls[]                        = array( $mode, $type, $package?->identifier(), $pageUrl );
				$choices['release_asset']['disabled'] = false;
				$choices['release_asset']['hydrated'] = true;
				$choices['release_asset']['url']      = add_query_arg( 'source_view', 'release_asset', $pageUrl );

				return $choices;
			};
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_advanced_source_sections'][]        =
			static function ( string $mode, string $type, string $selected, ?\RAN\Admin\AdminPackageProjection $package, string $pageUrl ) use ( &$sectionCalls ): void {
				$sectionCalls[] = array( $mode, $type, $selected, $package?->identifier(), $pageUrl );
				echo '<section>Release deployment source</section>';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_advanced_source_summary'][] =
			static function ( string $summary, string $mode, string $type, string $selected, ?\RAN\Admin\AdminPackageProjection $package ) use ( &$summaryCalls ): string {
				$summaryCalls[] = array( $summary, $mode, $type, $selected, $package?->identifier() );

				return 'Published release fixture';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][]         =
			static function ( array $rows, string $type, array $packages ) use ( &$rowCalls ): array {
				$rowCalls[] = array( array_keys( $rows ), $type, array_keys( $packages ) );

				return $rows;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_actions'][]      =
			static function ( array $actions, string $type, \RAN\Admin\AdminPackageProjection $package ) use ( &$actionCalls ): array {
				$actionCalls[] = array( $actions, $type, $package->identifier(), $package->settingsUrl() );

				return $actions;
			};

		$package = $this->managedPackage(
			'plugin/example.php',
			'Example Plugin',
			'plugin-repository-id',
			\RAN\PackageSource::RELEASE_ASSET
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$plugins->method( 'allBoosterPlugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			plugins: $plugins,
			database: new ReadyDashboardDatabase()
		);

		$_GET   = array( 'source_view' => 'release_asset' );
		$create = $dashboard->getPluginsCreate()['data'];
		$_GET   = array(
			'package'     => 'plugin/example.php',
			'source_view' => 'release_asset',
		);
		$edit   = $dashboard->getPlugins()['data'];
		$_GET   = array();
		$index  = $dashboard->getPlugins()['data'];

		$createUrl = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create';
		$editUrl   = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php';
		self::assertSame(
			array(
				array( 'create', 'plugin', null, $createUrl ),
				array( 'edit', 'plugin', 'plugin/example.php', $editUrl ),
			),
			$sourceCalls
		);
		self::assertSame(
			array(
				array( 'create', 'plugin', 'branch', null, $createUrl ),
				array( 'edit', 'plugin', 'release_asset', 'plugin/example.php', $editUrl ),
			),
			$sectionCalls
		);
		self::assertSame( array( 'create', 'edit' ), array_column( $summaryCalls, 1 ) );
		self::assertSame( array( 'branch', 'release_asset' ), array_column( $summaryCalls, 3 ) );
		self::assertSame( array( array( array( 'plugin/example.php' ), 'plugin', array( 'plugin/example.php' ) ) ), $rowCalls );
		self::assertSame( array( array( array(), 'plugin', 'plugin/example.php', $editUrl ) ), $actionCalls );
		self::assertSame( 'branch', $create['packageSource']['selected'] );
		self::assertSame( 'release_asset', $edit['packageSource']['selected'] );
		self::assertSame( 'Published release fixture', $edit['packageSource']['advanced_summary'] );
		self::assertArrayHasKey( 'plugin/example.php', $index['packageExtensionRows'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testSavedReleaseSourceRemainsUnavailableWithoutItsAddOnForBothPackageTypes( string $type ): void {
		$identifier = 'plugin' === $type ? 'release/release.php' : 'release-theme';
		$package    = $this->managedPackage(
			$identifier,
			'Release Package',
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET
		);
		$plugins    = $this->createStub( PluginRepository::class );
		$themes     = $this->createStub( ThemeRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
		$_GET      = array(
			'package'     => $identifier,
			'source_view' => 'branch',
		);

		$data = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];

		self::assertSame( 'release_asset', $data['packageSource']['current'] );
		self::assertSame( 'release_asset', $data['packageSource']['selected'] );
		self::assertTrue( $data['packageSource']['unavailable'] );
	}

	public function testTroubleshootingResultsRenderOnlyInTheSameDashboardRequest(): void {
		$_GET['tab'] = 'troubleshooting';
		$secrets     = new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() );
		$providers   = $this->providers();
		$local       = new class( $secrets ) extends LocalTroubleshootingService {
			public function diagnose(): array {
				$results = array();
				foreach ( array( 'one', 'two', 'three', 'four', 'five' ) as $code ) {
					$results[] = new ProviderDiagnosticResult(
						ProviderDiagnosticResult::PASSED,
						'local.' . $code,
						'The local check passed.',
						'No action is required.'
					);
				}

				return array(
					'results' => $results,
					'partial' => false,
				);
			}
		};
		$service     = new TroubleshootingService( $local, $providers );
		$dashboard   = $this->dashboard( $secrets, $service );

		self::assertFalse( $dashboard->getIndex()['data']['troubleshooting']['ran'] );
		$dashboard->postRunTroubleshooting( array( 'provider' => 'gh' ) );
		self::assertTrue( $dashboard->getIndex()['data']['troubleshooting']['ran'] );
		self::assertCount( 5, $dashboard->getIndex()['data']['troubleshooting']['results'] );
		self::assertFalse( $this->dashboard( $secrets, $service )->getIndex()['data']['troubleshooting']['ran'] );
	}

	public function testDeploymentActivityUsesItsOwnReadPayloadWithoutDiagnosticsResults(): void {
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'activity', $data['troubleshootingPanel'] );
		self::assertSame( array(), $data['troubleshooting'] );
		self::assertTrue( $data['deploymentActivity']['unavailable'] );
		self::assertSame( 'list', $data['deploymentActivity']['mode'] );
	}

	public function testDebugCaptureUsesOnlyItsBoundedFilePayload(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-dashboard-capture-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );

		try {
			$capture->start();
			self::assertTrue( $capture->append( '[ran-booster] safe dashboard event' ) );
			$_GET = array(
				'tab'   => 'troubleshooting',
				'panel' => 'debug-capture',
			);

			$data = $this->dashboard( $this->throwingSecrets(), null, null, null, null, null, $capture )->getIndex()['data'];

			self::assertSame( 'debug-capture', $data['troubleshootingPanel'] );
			self::assertSame( array(), $data['troubleshooting'] );
			self::assertSame( 'active', $data['debugCapture']['state'] );
			self::assertSame( 'ran-booster-debug.php', $data['debugCapture']['filename'] );
			self::assertStringContainsString( '[ran-booster] safe dashboard event', $data['debugCapture']['content'] );
			self::assertArrayNotHasKey( 'deploymentActivity', $data );
		} finally {
			$capture->delete();
			foreach ( array( $directory . '/ran-booster-debug.php.lock', $directory . '/ran-booster-debug.php' ) as $path ) {
				if ( is_file( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
					unlink( $path );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
			rmdir( $directory );
		}
	}

	/** @return list<array{string, bool}> */
	public static function packageStorageReadProvider(): array {
		return array(
			array( 'plugin', false ),
			array( 'plugin', true ),
			array( 'theme', false ),
			array( 'theme', true ),
		);
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testSelectedPackageRoutesRenderTheSharedEditPayload( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$package    = $this->managedPackage( $identifier, 'Example Package', 'example-repository' );
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		$plugins->expects( 'plugin' === $type ? self::once() : self::never() )
			->method( 'boosterPluginFromFile' )
			->with( $identifier )
			->willReturn( $package );
		$themes->expects( 'theme' === $type ? self::once() : self::never() )
			->method( 'boosterThemeFromStylesheet' )
			->with( $identifier )
			->willReturn( $package );

		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/edit', $result['view'] );
		self::assertSame(
			array(
				'packageProviderSettings',
				'packageBranchReadiness',
				'packageWebhookCleanup',
				'package',
				'packageView',
				'packageExtensionPanels',
				'packageSource',
				'repositoryBranchCheckOutcome',
				'repositoryBranchCheckEvidence',
			),
			array_keys( $result['data'] )
		);
		self::assertSame( $package, $result['data']['package'] );
		self::assertSame( $type, $result['data']['packageView']->getType() );
		self::assertSame( array(), $result['data']['packageExtensionPanels'] );
		self::assertSame( 'branch', $result['data']['packageSource']['current'] );
		self::assertSame( 'branch', $result['data']['packageSource']['selected'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testNetworkCreateEditAndIndexRoutesShareTheCanonicalPackageAdminBase( string $type ): void {
		$this->setMultisite( true );
		$identifier   = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$package      = $this->managedPackage( $identifier, 'Example Package', 'example-repository' );
		$settingsUrls = array();
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][] =
			static function ( \RAN\Admin\AdminPackageProjection $projection, string $settingsUrl ) use ( &$settingsUrls ): void {
				$settingsUrls[] = array( $projection->settingsUrl(), $settingsUrl );
			};
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$plugins->method( 'allBoosterPlugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
		$themes->method( 'allBoosterThemes' )->willReturn( 'theme' === $type ? array( $package ) : array() );
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			plugins: $plugins,
			themes: $themes,
			database: new ReadyDashboardDatabase()
		);
		$base      = 'https://example.test/wp-admin/network/admin.php';

		$_GET   = array();
		$create = 'plugin' === $type ? $dashboard->getPluginsCreate()['data'] : $dashboard->getThemesCreate()['data'];
		$_GET   = array( 'package' => $identifier );
		$edit   = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];
		$_GET   = array();
		$index  = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];

		self::assertSame( $base, $create['packageView']->getAdminUrl() );
		self::assertSame( $base, $edit['packageView']->getAdminUrl() );
		self::assertSame( $base, $index['packageView']->getAdminUrl() );
		self::assertStringStartsWith( $base . '?page=', $create['packageSource']['choices']['branch']['url'] );
		self::assertSame( array( array( $settingsUrls[0][0], $settingsUrls[0][0] ) ), $settingsUrls );
		self::assertStringStartsWith( $base . '?page=', $settingsUrls[0][0] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testSignedInstallNoticeProjectsTheManagedPackageIntoTheCreateView( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$_GET       = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( 'ran-booster-package-success|' . $type . '|install|' . $identifier ),
		);
		$dashboard  = $this->dashboard(
			$this->throwingSecrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->getPluginsCreate() : $dashboard->getThemesCreate();

		self::assertSame( $identifier, $result['data']['managedPackageIdentifier'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( ucfirst( $type ) . ' was successfully installed.', $dashboard->messages[0]['message'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testSignedAlreadyManagedNoticeProjectsTheManagedPackageIntoTheCreateView( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$_GET       = array(
			'ran_booster_result'        => 'already-managed',
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( 'ran-booster-package-success|' . $type . '|already-managed|' . $identifier ),
		);
		$dashboard  = $this->dashboard(
			$this->throwingSecrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->getPluginsCreate() : $dashboard->getThemesCreate();

		self::assertSame( $identifier, $result['data']['managedPackageIdentifier'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame(
			'plugin' === $type
				? 'Plugin is already installed and managed by Booster. No package settings were changed.'
				: 'Theme is already installed and managed by Booster. No package settings were changed.',
			$dashboard->messages[0]['message']
		);
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testForgedInstallNoticeDoesNotChangeTheCreateActions( string $type ): void {
		$_GET      = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => 'forged-package',
			'_ran_booster_notice_nonce' => 'forged',
		);
		$dashboard = $this->dashboard(
			$this->throwingSecrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->getPluginsCreate() : $dashboard->getThemesCreate();

		self::assertNull( $result['data']['managedPackageIdentifier'] );
		self::assertSame( array(), $dashboard->messages );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testMissingSelectedPackagesFallBackToTheMatchingIndex( string $type ): void {
		$identifier = 'plugin' === $type ? 'missing/missing.php' : 'missing-theme';
		$fallback   = $this->managedPackage(
			'plugin' === $type ? 'available/available.php' : 'available-theme',
			'Available Package',
			'available-repository'
		);
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		if ( 'plugin' === $type ) {
			$plugins->expects( self::once() )
				->method( 'boosterPluginFromFile' )
				->with( $identifier )
				->willThrowException( new PluginNotFound( 'Missing fixture plugin.' ) );
			$plugins->expects( self::once() )->method( 'allBoosterPlugins' )->willReturn( array( $fallback ) );
			$themes->expects( self::never() )->method( 'boosterThemeFromStylesheet' );
			$themes->expects( self::never() )->method( 'allBoosterThemes' );
		} else {
			$themes->expects( self::once() )
				->method( 'boosterThemeFromStylesheet' )
				->with( $identifier )
				->willThrowException( new ThemeNotFound( 'Missing fixture theme.' ) );
			$themes->expects( self::once() )->method( 'allBoosterThemes' )->willReturn( array( $fallback ) );
			$plugins->expects( self::never() )->method( 'boosterPluginFromFile' );
			$plugins->expects( self::never() )->method( 'allBoosterPlugins' );
		}

		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array( $fallback ), $result['data']['packages'] );
		self::assertSame( $type, $result['data']['packageView']->getType() );
		self::assertSame( 1, $result['data']['packageListTotal'] );
	}

	/** @return list<array{string, string}> */
	public static function packageListFilterProvider(): array {
		return array(
			array( 'plugin', 'Release Plugin' ),
			array( 'theme', 'Release Theme' ),
		);
	}

	#[DataProvider( 'packageListFilterProvider' )]
	public function testPackageIndexesApplyCombinedNormalizedFiltersForBothPackageTypes( string $type, string $releaseName ): void {
		$_GET    = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
		);
		$branch  = $this->managedPackage(
			'plugin' === $type ? 'alpha/alpha.php' : 'alpha-theme',
			'plugin' === $type ? 'Alpha Plugin' : 'Alpha Theme',
			'alpha-repository'
		);
		$release = $this->managedPackage(
			'plugin' === $type ? 'release/release.php' : 'release-theme',
			$releaseName,
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET,
			'bb',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC,
			'studio/release-package',
			'stable'
		);
		$other   = $this->managedPackage(
			'plugin' === $type ? 'other/other.php' : 'other-theme',
			'plugin' === $type ? 'Other Plugin' : 'Other Theme',
			'other-repository',
			\RAN\PackageSource::BRANCH,
			'gh',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willReturn( 'plugin' === $type ? array( $branch, $release, $other ) : array() );
		$themes->method( 'allBoosterThemes' )->willReturn( 'theme' === $type ? array( $branch, $release, $other ) : array() );

		$result = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data   = 'plugin' === $type ? $result->getPlugins()['data'] : $result->getThemes()['data'];

		self::assertSame( 3, $data['packageListTotal'] );
		self::assertSame(
			array(
				'search'   => 'release',
				'provider' => 'bb',
				'source'   => 'release_asset',
				'policy'   => 'automatic',
			),
			$data['packageListState']
		);
		self::assertSame( array( 'Bitbucket', 'GitHub' ), array_column( $data['packageProviderOptions'], 'label' ) );
		self::assertSame( array( $release->getIdentifier() ), array_map( static fn ( Package $package ): mixed => $package->getIdentifier(), $data['packages'] ) );
	}

	public function testRepeatedPackageIndexRenderingUsesFreshRepositoryReadback(): void {
		$first   = $this->managedPackage( 'first/first.php', 'First Plugin', 'first-repository' );
		$second  = $this->managedPackage( 'second/second.php', 'Second Plugin', 'second-repository' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 2 ) )
			->method( 'allBoosterPlugins' )
			->willReturnOnConsecutiveCalls( array( $first ), array( $second ) );
		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins );

		$initial = $dashboard->getPlugins()['data'];
		$fresh   = $dashboard->getPlugins()['data'];

		self::assertSame( array( $first ), $initial['packages'] );
		self::assertSame( array( $second ), $fresh['packages'] );
	}

	#[DataProvider( 'packageListFilterProvider' )]
	public function testPackageIndexesDiscardMalformedAndUnsupportedFilterValues( string $type, string $releaseName ): void {
		unset( $releaseName );
		$_GET    = array(
			's'        => array( 'not-scalar' ),
			'provider' => 'missing-provider',
			'source'   => 'archive',
			'policy'   => 'sometimes',
		);
		$package = $this->managedPackage(
			'plugin' === $type ? 'example/example.php' : 'example-theme',
			'Example Package',
			'example-repository'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$themes->method( 'allBoosterThemes' )->willReturn( 'theme' === $type ? array( $package ) : array() );

		$dashboard = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data      = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];

		self::assertSame(
			array(
				'search'   => '',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			$data['packageListState']
		);
		self::assertSame( array( $package ), $data['packages'] );
	}

	#[DataProvider( 'packageStorageReadProvider' )]
	public function testInvalidPackageStorageRendersASafeEmptyIndex( string $type, bool $detail ): void {
		if ( $detail ) {
			$_GET['package'] = 'upstream-provider-canary';
		}
		$dashboard = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			null,
			null,
			null,
			new FailingDashboardPluginRepository(),
			new FailingDashboardThemeRepository()
		);

		$result = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array(), $result['data']['packages'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $dashboard->messages[0]['code'] );
		self::assertSame( array( 'recovery_required' => false ), $dashboard->messages[0]['data'] );
		self::assertStringNotContainsString( 'upstream-provider-canary', $dashboard->messages[0]['message'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testIncompatibleDatabaseRendersADisabledCreateScreen( string $type ): void {
		$connection = new class() {
			public string $last_error = '';

			public function db_server_info(): string {
				return 'PostgreSQL 17.5';
			}
		};
		$dashboard  = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			database: new Database( $connection )
		);

		$result = 'plugin' === $type ? $dashboard->getPluginsCreate() : $dashboard->getThemesCreate();

		self::assertSame( 'packages/create', $result['view'] );
		self::assertFalse( $result['data']['packageMutationAvailable'] );
		self::assertFalse( $result['data']['openRepositoryPicker'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_storage_database_unsupported', $dashboard->messages[0]['code'] );
	}

	/** @return list<array{string}> */
	public static function packageTypeProvider(): array {
		return array(
			array( 'plugin' ),
			array( 'theme' ),
		);
	}

	public function testBulkPackageRedirectPreservesOnlyNormalizedListFilters(): void {
		$_GET = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
			'unsafe'   => '<script>',
		);

		$url = $this->dashboard( $this->throwingSecrets() )->bulkPackageRedirect(
			'theme',
			BulkPackageResult::policy(
				BulkPackageAction::POLICY_AUTOMATIC,
				array(
					'selected'  => 1,
					'changed'   => 1,
					'unchanged' => 0,
				)
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused redirect-query assertion.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'ran-booster-themes', $query['page'] );
		self::assertSame( 'release', $query['s'] );
		self::assertSame( 'bb', $query['provider'] );
		self::assertSame( 'release_asset', $query['source'] );
		self::assertSame( 'automatic', $query['policy'] );
		self::assertArrayNotHasKey( 'unsafe', $query );
		self::assertArrayHasKey( '_ran_booster_bulk_notice_nonce', $query );
	}

	public function testBulkPackageRedirectRejectsAPluginActivationResultForTheThemeList(): void {
		$this->expectException( \LogicException::class );

		$this->dashboard( $this->throwingSecrets() )->bulkPackageRedirect(
			'theme',
			BulkPackageResult::pluginActivation(
				BulkPackageAction::ACTIVATE_PLUGINS,
				1,
				1,
				0,
				array()
			)
		);
	}

	public function testSignedBulkQueueNoticeReportsPartialSuccessAndUnavailableRunner(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue(
				4,
				2,
				array(
					'busy'     => 1,
					'disabled' => 1,
				),
				'unavailable'
			)
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Queued 2 plugins', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'branch reinstall', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'already queued, running, or needs attention: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deployment disabled: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'could not schedule the deployment runner', $dashboard->messages[0]['message'] );
		self::assertSame( 'bulk_update_queue', $dashboard->messages[0]['code'] );
		self::assertSame( 2, $dashboard->messages[0]['queued_updates'] );
		self::assertSame( 2, $dashboard->messages[0]['skipped_updates'] );
	}

	public function testSignedBulkQueueNoticeReportsWhenEverySelectionWasSkipped(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue(
				2,
				0,
				array( 'disabled' => 2 ),
				'not_required'
			)
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Queued 0 plugins', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Skipped: 2', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deployment disabled: 2', $dashboard->messages[0]['message'] );
		self::assertStringNotContainsString( 'could not schedule the deployment runner', $dashboard->messages[0]['message'] );
	}

	public function testSignedBulkPolicyNoticeReportsChangedAndUnchangedCounts(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'theme',
			BulkPackageResult::policy(
				BulkPackageAction::POLICY_MANUAL,
				array(
					'selected'  => 3,
					'changed'   => 2,
					'unchanged' => 1,
				)
			)
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended

		$dashboard->getThemes();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'success', $dashboard->messages[0]['type'] );
		self::assertSame(
			'Changed 2 themes to Manual. Already in that state: 1.',
			$dashboard->messages[0]['message']
		);
	}

	public function testSignedBulkActivationNoticeReportsPartialWordPressStateChange(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::pluginActivation(
				BulkPackageAction::DEACTIVATE_PLUGINS,
				4,
				1,
				1,
				array(
					'deactivation_failed' => 1,
					'self_deactivation'   => 1,
				)
			)
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Changed 1 plugins to Disabled in WordPress', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Already in that state: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deactivation failed: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Booster cannot disable itself: 1', $dashboard->messages[0]['message'] );
	}

	public function testTamperedBulkNoticeIsIgnored(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended
		$_GET['ran_booster_bulk_queued'] = '20';

		$dashboard->getPlugins();

		self::assertSame( array(), $dashboard->messages );
	}

	public function testBulkNoticeSignatureCannotBeReplayedAcrossPackageTypes(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query and deliberately presents it to the other package type.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended

		$dashboard->getThemes();

		self::assertSame( array(), $dashboard->messages );
	}

	public function testForgedBulkNoticeMarkerIsIgnored(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test deliberately replaces the signed redirect marker.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended
		$_GET['_ran_booster_bulk_notice_nonce'] = 'forged';

		$dashboard->getPlugins();

		self::assertSame( array(), $dashboard->messages );
	}

	public function testPackageOverviewReadsBranchActivityOnly(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$attempts       = $this->deploymentAttempts( $database );
		$branch         = $this->managedPackage( 'plugin/branch.php', 'Branch Plugin', 'branch-repository' );
		$release        = $this->managedPackage(
			'plugin/release.php',
			'Release Plugin',
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET
		);

		$activity = ( new DeploymentAdminPresenter( attempts: $attempts ) )->packageActivity( array( $branch, $release ), 'plugin' );

		self::assertFalse( $activity['unavailable'] );
		self::assertArrayHasKey( 'plugin/branch.php', $activity['items'] );
		self::assertArrayNotHasKey( 'plugin/release.php', $activity['items'] );
	}

	public function testShortDeploymentHistoryDoesNotOfferAnOlderPage(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 9 ) );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database )
		)->getIndex()['data']['deploymentActivity'];

		self::assertCount( 9, $data['items'] );
		self::assertSame( 9, $data['items'][0]->getId() );
		self::assertNull( $data['next_cursor'] );
		self::assertFalse( $data['has_cursor'] );
	}

	public function testDeploymentActivityProvidesExactManagedPackageSettingsUrls(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'plugin/example.php' => $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
			)
		);
		$themes->method( 'allDeploymentThemes' )->willReturn(
			array(
				'example-theme' => $this->managedPackage( 'example-theme', 'Example Theme', 'repository-2' ),
			)
		);
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database ),
			$plugins,
			$themes
		)->getIndex()['data']['deploymentActivity'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$data['package_settings_urls']['plugin']['example']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme',
			$data['package_settings_urls']['theme']['example']
		);
	}

	public function testDeploymentActivityLinksFailClosedForAmbiguousOrUnavailableInventory(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'plugin/example.php'       => $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
				'other-example/plugin.php' => $this->managedPackage( 'other-example/plugin.php', 'Other Example Plugin', 'repository-2' ),
			)
		);
		$themes->method( 'allDeploymentThemes' )->willThrowException( PackageStorageFailure::invalidProviderIdentity() );
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database ),
			$plugins,
			$themes
		)->getIndex()['data']['deploymentActivity'];

		self::assertFalse( $data['unavailable'] );
		self::assertCount( 1, $data['items'] );
		self::assertArrayNotHasKey( 'example', $data['package_settings_urls']['plugin'] );
		self::assertSame( array(), $data['package_settings_urls']['theme'] );
	}

	public function testDeploymentHistoryUsesLookaheadWithoutOverlappingPages(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 51 ) );
		$attempts       = $this->deploymentAttempts( $database );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$firstPage = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertCount( 50, $firstPage['items'] );
		self::assertSame( 51, $firstPage['items'][0]->getId() );
		self::assertSame( 2, $firstPage['items'][49]->getId() );
		self::assertSame( 2, $firstPage['next_cursor'] );

		$_GET['before'] = '2';
		$lastPage       = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertCount( 1, $lastPage['items'] );
		self::assertSame( 1, $lastPage['items'][0]->getId() );
		self::assertNull( $lastPage['next_cursor'] );
		self::assertTrue( $lastPage['has_cursor'] );
	}

	public function testExhaustedDeploymentHistoryCursorRemainsAnOlderPage(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$_GET           = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '1',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database )
		)->getIndex()['data']['deploymentActivity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertFalse( $data['unavailable'] );
	}

	public function testMalformedDeploymentHistoryCursorFailsClosed(): void {
		$_GET = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '01',
		);

		$data = $this->dashboard( $this->throwingSecrets(), null, null, $this->deploymentAttempts( new DashboardActivityWpdb() ) )
			->getIndex()['data']['deploymentActivity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testMalformedActivityIdentityDoesNotFallBackToABroadList(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '01',
			'reference' => str_repeat( 'a', 32 ),
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data']['deploymentActivity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testArrayActivityIdentityDoesNotFallBackToABroadList(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => array( '1' ),
			'reference' => array( str_repeat( 'a', 32 ) ),
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data']['deploymentActivity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testAttemptDetailLoadsOnlyTheRequestedAttempt(): void {
		$attempt        = DashboardActivityWpdb::attempt( 1, 'succeeded' );
		$database       = new DashboardActivityWpdb();
		$database->rows = array( $attempt, DashboardActivityWpdb::attempt( 2, 'failed' ) );
		$attempts       = $this->deploymentAttempts( $database );
		$_GET           = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '1',
			'reference' => $attempt['correlation_id'],
		);

		$data = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertFalse( $data['unavailable'] );
		self::assertSame( 1, $data['detail']->getId() );
		self::assertSame( 'deployed', $data['detail']->getOutcome()?->getCode() );
		self::assertArrayNotHasKey( 'actions', $data );
	}

	public function testNeedsAttentionContentionRefusesMutationUntilAcknowledgedThenAllowsRetry(): void {
		$attemptDatabase         = new AttemptRepositoryDatabase();
		$attempt                 = DashboardActivityWpdb::attempt( 43, 'failed' );
		$attempt['state']        = 'needs_attention';
		$attempt['outcome_code'] = DeploymentOutcome::CODE_INTERRUPTED;
		$attemptDatabase->rows   = array( $attempt );
		$attempts                = new DeploymentAttemptRepository(
			$attemptDatabase,
			'wp_ran_booster_deployment_attempts',
			static fn (): \DateTimeImmutable => new \DateTimeImmutable( '2026-07-27 12:00:00 UTC' ),
			static fn ( int $length ): string => str_repeat( "\x0b", $length ),
			new ReadyDashboardDatabase()
		);
		$plugins                 = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'fromSlug' );
		$themes      = $this->createStub( ThemeRepository::class );
		$updaterLock = $this->createStub( WordPressUpdaterLock::class );
		$coordinator = new DashboardNeedsAttentionCoordinator( $attempts );
		$operations  = new PackageOperationService(
			$plugins,
			$themes,
			$coordinator,
			new PackageRemovalService(
				$plugins,
				$themes,
				$this->createStub( PackageRemovalGateway::class ),
				null,
				$updaterLock
			),
			$updaterLock
		);
		$dashboard   = $this->dashboard(
			$this->throwingSecrets(),
			packageOperations: $operations,
			deploymentAttempts: $attempts
		);
		$request     = array(
			'provider'                            => 'gh',
			'repository'                          => 'owner/example',
			'branch'                              => 'main',
			'package_slug'                        => 'example',
			'provider_repository_id'              => 'R_example',
			'provider_repository_identity_source' => 'manual',
		);
		self::assertFalse(
			$dashboard->postPackageOperation(
				'install-plugin',
				$request
			)
		);
		self::assertSame( 1, $coordinator->calls );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertStringContainsString( 'attempt=43', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'reference=' . $attempt['correlation_id'], $dashboard->messages[0]['message'] );
		self::assertCount( 1, $attemptDatabase->rows );

		self::assertFalse( $dashboard->postPackageOperation( 'install-plugin', $request ) );
		self::assertSame( 2, $coordinator->calls );
		self::assertCount( 1, $attemptDatabase->rows );

		$attempts->resolveNeedsAttention( 43, $attempt['correlation_id'], 7 );
		self::assertNotNull( $attemptDatabase->rows[0]['resolved_at'] );
		self::assertSame( '7', $attemptDatabase->rows[0]['resolved_by'] );

		self::assertFalse( $dashboard->postPackageOperation( 'install-plugin', $request ) );
		self::assertSame( 3, $coordinator->calls );
		self::assertCount( 2, $attemptDatabase->rows );
		self::assertSame( 'failed', $attemptDatabase->rows[1]['state'] );

		$_GET     = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '43',
			'reference' => $attempt['correlation_id'],
		);
		$activity = $dashboard->getIndex()['data']['deploymentActivity'];
		self::assertSame( 43, $activity['detail']->getId() );
		self::assertSame( $attempt['correlation_id'], $activity['detail']->getCorrelationId() );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	private function setMultisite( bool $multisite ): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
	}

	private function deploymentAttempts( DashboardActivityWpdb $database ): DeploymentAttemptRepository {
		return new DeploymentAttemptRepository(
			$database,
			'wp_ran_booster_deployment_attempts',
			databaseLifecycle: new ReadyDashboardDatabase()
		);
	}

	private function dashboard(
		SecretsFile $secrets,
		?TroubleshootingService $troubleshooting = null,
		?\RAN\PackageOperationService $packageOperations = null,
		?DeploymentAttemptRepository $deploymentAttempts = null,
		?PluginRepository $plugins = null,
		?ThemeRepository $themes = null,
		?TemporaryDebugCapture $debugCapture = null,
		?Database $database = null,
		?AdminAddOnRegistry $adminAddOns = null,
		bool $providerCredentials = false,
		?ProviderRegistry $providers = null,
		?PublicRepositoryLookupProfileStore $publicLookupProfiles = null
	): RoutingDashboard {
		$providers        = $providers ?? $this->providers( $providerCredentials );
		$pluginRepository = $plugins ?? new class() extends PluginRepository {

			public function __construct() {
			}

			public function allBoosterPlugins(): array {
				return array();
			}

			public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
				return array();
			}
		};
		$themeRepository  = $themes ?? new class() extends ThemeRepository {

			public function __construct() {
			}

			public function allBoosterThemes(): array {
				return array();
			}

			public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
				return array();
			}
		};

		return new RoutingDashboard(
			$database ?? new Database(),
			$pluginRepository,
			new Booster(),
			$themeRepository,
			new ProviderSettingsPresenter( $providers, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ), $publicLookupProfiles, null, null, $pluginRepository, $themeRepository ),
			$troubleshooting ?? new TroubleshootingService( new LocalTroubleshootingService( $secrets ), $providers ),
			new AdminTabRegistry( $providers ),
			new ProviderDocumentationPresenter( $providers ),
			$packageOperations,
			$deploymentAttempts,
			$debugCapture,
			null,
			$adminAddOns
		);
	}

	private function throwingSecrets(): SecretsFile {
		return new class() extends SecretsFile {

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function credentialProfiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read credential profiles.' );
			}

			public function webhookProfiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read webhook profiles.' );
			}
		};
	}

	private function managedPackage(
		string $identifier,
		string $name,
		string $providerRepositoryId,
		\RAN\PackageSource $source = \RAN\PackageSource::BRANCH,
		string $provider = 'gh',
		\RAN\Deployment\DeploymentPolicy $policy = \RAN\Deployment\DeploymentPolicy::MANUAL,
		string $repository = 'owner/repository',
		string $branch = 'main',
		string $credentialId = '',
		bool $private = false,
		?string $subdirectory = null
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( $identifier );
		$package->method( 'getDisplayName' )->willReturn( $name );
		$package->method( 'getSlug' )->willReturn( 'example' );
		$package->method( 'getProviderCode' )->willReturn( $provider );
		$package->method( 'getProviderRepositoryId' )->willReturn( $providerRepositoryId );
		$package->method( 'getRepository' )->willReturn( new ManagedRepository( $provider, $repository, $providerRepositoryId, $branch, $private, $credentialId ) );
		$package->method( 'getBranch' )->willReturn( $branch );
		$package->method( 'getSubdirectory' )->willReturn( $subdirectory );
		$package->method( 'getSource' )->willReturn( $source );
		$package->method( 'getSourceRevision' )->willReturn( 1 );
		$package->method( 'getDeploymentPolicy' )->willReturn( $policy );
		$package->method( 'getCredentialId' )->willReturn( $credentialId );

		return $package;
	}

	private function providers( bool $withCredentials = false ): ProviderRegistry {
		return new ProviderRegistry(
			array(
				$this->provider( ProviderCode::parse( 'gh' ), 'GitHub', $withCredentials ),
				$this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket', $withCredentials ),
			)
		);
	}

	private function provider( ProviderCode $code, string $label, bool $withCredentials = false ): RepositoryProvider {
		return new class( $code, $label, $withCredentials ) implements RepositoryProvider, ProviderCredentialPolicySupplier, \RAN\RepositoryProvider\WebhookNormalizer {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct(
				private ProviderCode $code,
				private string $label,
				private bool $withCredentials
			) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						$this->withCredentials ? array( new CredentialKindMetadata( 'api-key', 'API key', 'API key' ) ) : array(),
						$this->withCredentials ? array( new WebhookScopeMetadata( 'repository', 'Repository', true, 'Repository' ) ) : array(),
						navigation: new \RAN\RepositoryProvider\Admin\ProviderNavigationPlacement(
							\RAN\RepositoryProvider\Admin\ProviderNavigationPlacement::GIT_HOST,
							'gh' === $this->code->value ? 100 : 200
						)
					)
				);
			}

			public function getCredentialPolicy(): ProviderCredentialPolicy {
				return ShippedSecretPolicyCatalog::create()->credentialPolicy( $this->code );
			}

			public function getWebhookPolicy(): \RAN\RepositoryProvider\ProviderWebhookPolicy {
				return ShippedSecretPolicyCatalog::create()->webhookPolicy( $this->code );
			}

			public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
				throw new RuntimeException( 'Unused provider-route fixture method.' );
			}

			public function normalizeWebhook( \RAN\RepositoryProvider\WebhookRequest $request ): \RAN\RepositoryProvider\WebhookEnvelope {
				unset( $request );
				return \RAN\RepositoryProvider\WebhookEnvelope::ignored();
			}
		};
	}
}

final class FailingDashboardPluginRepository extends PluginRepository {
	public function allBoosterPlugins(): array {
		throw PackageStorageFailure::invalidProviderIdentity();
	}

	public function boosterPluginFromFile( $file ) {
		throw PackageStorageFailure::invalidProviderIdentity();
	}
}

final class FailingDashboardThemeRepository extends ThemeRepository {
	public function allBoosterThemes(): array {
		throw PackageStorageFailure::invalidProviderIdentity();
	}

	public function boosterThemeFromStylesheet( $stylesheet ) {
		throw PackageStorageFailure::invalidProviderIdentity();
	}
}

/** Bounded coordinator double that preserves the real package-operation boundary. */
final class DashboardNeedsAttentionCoordinator extends DeploymentCoordinator {
	public int $calls = 0;

	public function __construct( private DeploymentAttemptRepository $attempts ) {
	}

	public function executeManual( PackageOperation $command ): array {
		++$this->calls;
		$request  = new DeploymentRequest(
			(string) $command->repository,
			$command->credentialId,
			$command->private,
			(string) $command->branch,
			(string) $command->packageSlug,
			$command->subdirectory,
			$command->deploymentPolicy,
			7
		);
		$attempt  = $this->attempts->admitAndClaimManual(
			$command->operation,
			$command->packageType,
			(string) $command->providerCode,
			(string) $command->providerRepositoryId,
			$request,
			(string) $command->branch,
			'branch',
			1
		);
		$finished = $this->attempts->finish(
			$attempt->getId(),
			DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PREFLIGHT_FAILED )
		);

		return array(
			'status'         => 'failed',
			'correlation_id' => $finished->getCorrelationId(),
			'outcome_code'   => (string) $finished->getOutcome()?->getCode(),
		);
	}
}

final class DashboardActivityWpdb {

	/** @var list<array<string, mixed>> */
	public array $rows        = array();
	public string $prefix     = 'wp_';
	public string $last_error = '';

	public function db_server_info(): string {
		return '8.4.6';
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$query = (string) preg_replace_callback(
				'/%[dis]/',
				static fn ( array $match ): string => '%i' === $match[0]
					? '`' . (string) $argument . '`'
					: ( '%d' === $match[0] ? (string) (int) $argument : "'" . addslashes( (string) $argument ) . "'" ),
				$query,
				1
			);
		}

		return $query;
	}

	public function query( string $query ): int|false {
		return 0;
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data ): int|false {
		return false;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		if ( 'SHOW ENGINES' === $query ) {
			return array(
				(object) array(
					'Engine'  => 'InnoDB',
					'Support' => 'DEFAULT',
				),
			);
		}
		if ( preg_match( '/WHERE id = (\\d+)/', $query, $match ) === 1 ) {
			return array_values(
				array_map(
					static fn ( array $row ): object => (object) $row,
					array_filter( $this->rows, static fn ( array $row ): bool => (int) $row['id'] === (int) $match[1] )
				)
			);
		}
		if ( str_contains( $query, 'package_type IN' ) ) {
			$rows = $this->rows;
			if ( preg_match( '/AND id < (\\d+)/', $query, $match ) === 1 ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] < (int) $match[1] ) );
			}
			usort( $rows, static fn ( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
			$limit = preg_match( '/LIMIT (\\d+)/', $query, $match ) === 1 ? (int) $match[1] : count( $rows );

			return array_map( static fn ( array $row ): object => (object) $row, array_slice( $rows, 0, $limit ) );
		}
		return array();
	}

	/** @return array<string, mixed> */
	public static function attempt( int $id, string $state ): array {
		$outcomeCode = 'succeeded' === $state ? 'deployed' : 'preflight_failed';

		return array(
			'id'                      => $id,
			'correlation_id'          => str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ),
			'source'                  => 'manual',
			'operation'               => 'update',
			'package_type'            => 'plugin',
			'package_slug'            => 'example',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'release_identity'        => null,
			'provider'                => 'gh',
			'provider_repository_id'  => 'repository-1',
			'requested_ref'           => 'main',
			'resolved_ref'            => str_repeat( 'f', 40 ),
			'delivery_id'             => null,
			'delivery_digest'         => null,
			'state'                   => $state,
			'mutation_started_at'     => null,
			'outcome_code'            => $outcomeCode,
			'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":1}',
			'created_at'              => '2026-07-19 00:00:00',
			'finished_at'             => '2026-07-19 00:00:00',
		);
	}
}

final class ReadyDashboardDatabase extends Database {
	public function __construct() {
	}

	public function requireReady(): void {
	}
}

final class DashboardBranchCheckProvider implements RepositoryProvider, CredentialedPublicRepositoryBrowser, \RAN\RepositoryProvider\RepositoryPathInspector {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $prepareCalls        = 0;
	public int $resolvedRefCalls    = 0;
	public int $cleanupCalls        = 0;
	public int $pathCalls           = 0;
	public ?ArchiveRequest $request = null;
	public ?string $path            = null;

	public function __construct(
		public readonly bool $cleanupFails = false,
		public readonly bool $pathExists = true,
		public readonly bool $pathCheckFails = false
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://example.test/',
			'Owner'
		);
	}

	public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		unset( $request );
		throw new RuntimeException( 'Repository resolution is not used by the branch check.' );
	}

	public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		throw new RuntimeException( 'Repository browsing is not used by the branch check.' );
	}

	public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		++$this->prepareCalls;
		$this->request = $request;

		return new class( $this ) implements PreparedArchive {
			public function __construct( private DashboardBranchCheckProvider $provider ) {
			}

			public function getUrl(): string {
				return 'https://example.test/archive.zip';
			}

			public function getResolvedRef(): string {
				++$this->provider->resolvedRefCalls;

				return str_repeat( 'a', 40 );
			}

			public function verifyCurrentHead(): void {
			}

			public function cleanup(): void {
				++$this->provider->cleanupCalls;
				if ( $this->provider->cleanupFails ) {
					throw new RuntimeException( 'Cleanup fixture failure.' );
				}
			}
		};
	}

	public function repositoryPathExists( \RAN\RepositoryProvider\RepositoryReference $repository, string $ref, string $path ): bool {
		unset( $repository, $ref );
		++$this->pathCalls;
		$this->path = $path;
		if ( $this->pathCheckFails ) {
			throw new RuntimeException( 'Path check fixture failure.' );
		}
		return $this->pathExists;
	}
}

final class DashboardBranchCheckProviderWithoutPathInspector implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $pathCalls = 0;

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://example.test/',
			'Owner'
		);
	}

	public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		unset( $request );
		throw new RuntimeException( 'Repository resolution is not used by the branch check.' );
	}

	public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		throw new RuntimeException( 'Repository browsing is not used by the branch check.' );
	}

	public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		return new class() implements PreparedArchive {
			public function getUrl(): string {
				return 'https://example.test/archive.zip';
			}

			public function getResolvedRef(): string {
				return str_repeat( 'a', 40 );
			}

			public function verifyCurrentHead(): void {
			}

			public function cleanup(): void {
			}
		};
	}
}

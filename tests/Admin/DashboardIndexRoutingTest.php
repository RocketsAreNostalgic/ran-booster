<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\AdminTabRegistry;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
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
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
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
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\Tests\Support\CredentialUsageDatabase;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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

	public function test_development_safety_notice_dismissal_is_scoped_to_the_current_administrator(): void {
		$GLOBALS['ran_booster_dashboard_test_environment_type'] = 'local';
		$GLOBALS['ran_booster_dashboard_test_user_meta'][7][ DevelopmentSafetyNoticeController::USER_META_KEY ] = '1';
		$predicate = new ReflectionMethod( Dashboard::class, 'should_show_development_safety_notice' );
		$dashboard = $this->dashboard( $this->throwing_secrets() );

		self::assertFalse( $predicate->invoke( $dashboard, 'packages/index', true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', true ) );

		$GLOBALS['ran_booster_dashboard_test_user_id'] = 8;

		self::assertTrue( $predicate->invoke( $dashboard, 'packages/index', true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', true ) );
	}

	public function test_repository_branch_check_rejects_missing_and_stale_nonce_without_provider_work(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );

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
		self::assertSame( 0, $provider->prepare_calls );
	}

	public function test_repository_branch_check_uses_exact_saved_target_and_cleans_prepared_authority(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managed_package(
			'example/example.php',
			'Example',
			'repo-42',
			repository: 'owner/example',
			branch: 'feature/test'
		);
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepare_calls );
		self::assertSame( 1, $provider->resolved_ref_calls );
		self::assertSame( 1, $provider->cleanup_calls );
		self::assertSame( 'owner/example', $provider->request?->repository->locator );
		self::assertSame( 'repo-42', $provider->request->repository->provider_repository_id );
		self::assertSame( 'feature/test', $provider->request->ref );
		self::assertNull( $provider->request->expected_branch );
		self::assertFalse( $provider->request->repository->private );
		self::assertNull( $provider->request->repository->credential_id );
	}

	public function test_repository_branch_check_consumes_its_one_time_marker_before_repeating_remote_work(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			branch_check_evidence: new DashboardBranchCheckEvidenceStore()
		);
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertThat( $check->invoke( $dashboard, $package, 'plugin' ), self::identicalTo( 'verified' ) );
		self::assertSame( 1, $provider->prepare_calls );
	}

	public function test_repository_branch_check_holds_the_shared_updater_lock_during_provider_access(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider                     = new DashboardBranchCheckProvider();
		$lock                         = new DashboardBranchCheckUpdaterLock();
		$dashboard                    = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			branch_check_lock: $lock
		);
		$provider->on_provider_access = static fn () => $lock->record_provider_access();
		$package                      = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check                        = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET                         = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( array( 'acquire', 'release:branch-check-lock' ), $lock->events );
		self::assertTrue( $lock->was_held_during( 'provider_access' ) );
	}

	public function test_repository_branch_check_does_not_reuse_verified_marker_after_its_exact_evidence_is_cleared(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$evidence  = new DashboardBranchCheckEvidenceStore();
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			branch_check_evidence: $evidence
		);
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		$evidence->clear( 'plugin', $package );
		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 2, $provider->prepare_calls );
	}

	public function test_repository_branch_check_maps_advisory_evidence_write_failure_without_crashing_settings(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			branch_check_evidence: new ThrowingDashboardBranchCheckEvidenceStore()
		);
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepare_calls );
	}

	public function test_repository_branch_check_does_not_reuse_its_marker_after_credential_or_default_access_generation_changes(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$evidence         = new DashboardBranchCheckEvidenceStore();
		$dashboard        = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			public_lookup_profiles: $lookup,
			branch_check_evidence: $evidence
		);
		$package          = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check            = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		$evidence->bump_profile_generation( 'gh', 'public-profile' );
		self::assertThat( $check->invoke( $dashboard, $package, 'plugin' ), self::identicalTo( 'verified' ) );
		$evidence->bump_provider_generation( 'gh' );
		self::assertThat( $check->invoke( $dashboard, $package, 'plugin' ), self::identicalTo( 'verified' ) );
		self::assertSame( 3, $provider->prepare_calls );
	}

	public function test_repository_branch_check_is_captured_as_sanitized_operational_evidence(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$directory = sys_get_temp_dir() . '/ran-booster-branch-check-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only private fixture directory.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
		$capture->start();
		BoosterLogger::configure_capture( $capture );
		try {
			$provider  = new DashboardBranchCheckProvider();
			$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
			$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
			$_GET      = array(
				'ran_booster_repository_branch_check'  => '1',
				'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
			);
			( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' );
			$line = $capture->snapshot()['entries'][0]['line'];
			self::assertStringContainsString( 'repository branch check completed', $line );
			self::assertStringContainsString( '"event":"repository_branch_checked"', $line );
			self::assertStringNotContainsString( 'owner/repository', $line );
		} finally {
			BoosterLogger::configure_capture( null );
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

	public function test_repository_branch_check_uses_provider_default_public_lookup_profile_instead_of_package_credential(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$dashboard        = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			public_lookup_profiles: $lookup
		);
		$package          = $this->managed_package( 'example/example.php', 'Example', 'repo-42', credential_id: 'deployment-profile' );
		$check            = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 'owner/repository', $provider->request?->repository->locator );
		self::assertSame( 'repo-42', $provider->request->repository->provider_repository_id );
		self::assertFalse( $provider->request->repository->private );
		self::assertSame( 'public-profile', $provider->request->repository->credential_id );
	}

	public function test_repository_branch_check_clears_earlier_evidence_when_the_provider_is_unavailable(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$evidence = new DashboardBranchCheckEvidenceStore();
		$package  = $this->managed_package( 'provider-missing/example.php', 'Example', 'repo-42' );
		$evidence->record( 'plugin', $package, null, 'verified' );
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry(),
			branch_check_evidence: $evidence
		);
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|provider-missing/example.php|branch|1'
			),
		);

		$outcome = ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' );

		self::assertSame( 'provider_unavailable', $outcome );
		self::assertNull( $evidence->find( 'plugin', $package, null ) );
	}

	public function test_repository_branch_check_drops_astored_public_profile_without_the_declared_capability(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardUncredentialedBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$dashboard        = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			public_lookup_profiles: $lookup
		);
		$package          = $this->managed_package( 'no-capability/example.php', 'Example', 'repo-42' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|no-capability/example.php|branch|1'
			),
		);

		$outcome = ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' );

		self::assertSame( 'verified', $outcome );
		self::assertNull( $provider->request?->repository->credential_id );
	}

	public function test_repository_branch_check_does_not_rewrite_private_package_access_as_public_lookup(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider         = new DashboardBranchCheckProvider();
		$lookup           = new InMemoryPublicRepositoryLookupProfileStore();
		$lookup->profiles = array( 'gh' => 'public-profile' );
		$dashboard        = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) ),
			public_lookup_profiles: $lookup
		);
		$package          = $this->managed_package( 'example/example.php', 'Example', 'repo-42', credential_id: 'deployment-profile', is_private: true );
		$check            = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET             = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'verified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertTrue( $provider->request?->repository->private );
		self::assertSame( 'deployment-profile', $provider->request->repository->credential_id );
	}

	public function test_repository_branch_check_verifies_aconfigured_subdirectory_when_the_provider_supports_it(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider();
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'verified', ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->path_calls );
		self::assertSame( 'packages/example', $provider->path );
	}

	public function test_repository_branch_check_reports_amissing_configured_subdirectory_precisely(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( path_exists: false );
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/missing' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unavailable', ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->path_calls );
		self::assertSame( 1, $provider->cleanup_calls );
	}

	public function test_repository_branch_check_reuses_missing_configured_subdirectory_outcome_without_provider_or_path_work(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( path_exists: false );
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/missing' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unavailable', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertThat( $check->invoke( $dashboard, $package, 'plugin' ), self::identicalTo( 'subdirectory_unavailable' ) );
		self::assertSame( 1, $provider->prepare_calls );
		self::assertSame( 1, $provider->path_calls );
	}

	public function test_repository_branch_check_distinguishes_an_unavailable_path_check_from_amissing_path(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( path_check_fails: true );
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unverified', ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->path_calls );
		self::assertSame( 1, $provider->cleanup_calls );
	}

	public function test_repository_branch_check_reuses_unavailable_configured_subdirectory_outcome_without_provider_or_path_work(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( path_check_fails: true );
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unverified', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertThat( $check->invoke( $dashboard, $package, 'plugin' ), self::identicalTo( 'subdirectory_unverified' ) );
		self::assertSame( 1, $provider->prepare_calls );
		self::assertSame( 1, $provider->path_calls );
	}

	public function test_repository_branch_check_does_not_claim_an_uninspected_subdirectory_is_verified(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProviderWithoutPathInspector();
		$dashboard = $this->dashboard( $this->throwing_secrets(), providers: new ProviderRegistry( array( $provider ) ) );
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42', subdirectory: 'packages/example' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( 'ran-booster-repository-branch-check|plugin|example/example.php|branch|1' ),
		);

		self::assertSame( 'subdirectory_unverified', ( new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' ) )->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 0, $provider->path_calls );
	}

	public function test_repository_branch_check_fails_closed_when_cleanup_fails(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$provider  = new DashboardBranchCheckProvider( cleanup_fails: true );
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			providers: new ProviderRegistry( array( $provider ) )
		);
		$package   = $this->managed_package( 'example/example.php', 'Example', 'repo-42' );
		$check     = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		$_GET      = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce(
				'ran-booster-repository-branch-check|plugin|example/example.php|branch|1'
			),
		);

		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $provider->prepare_calls );
		self::assertSame( 1, $provider->cleanup_calls );
	}

	/** @return list<array{string, bool, bool}> */
	public static function development_safety_notice_provider(): array {
		return array(
			array( 'packages/index', true, true ),
			array( 'packages/create', true, false ),
			array( 'packages/edit', true, false ),
			array( 'index', true, false ),
			array( 'index', true, false ),
			array( 'packages/index', false, false ),
		);
	}

	#[DataProvider( 'development_safety_notice_provider' )]
	public function test_development_safety_notice_uses_detected_environment_only_on_the_package_index( string $view, bool $development_environment_detected, bool $expected ): void {
		$predicate = new ReflectionMethod( Dashboard::class, 'should_show_development_safety_notice' );

		self::assertSame( $expected, $predicate->invoke( $this->dashboard( $this->throwing_secrets() ), $view, $development_environment_detected ) );
	}

	public function test_provider_tab_builds_only_the_selected_provider_settings(): void {
		$_GET['tab'] = 'bb';

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->get_index()['data'];

		self::assertSame( 'bb', $data['tab'] );
		self::assertSame( 'provider.php', $data['tab_view'] );
		self::assertSame( 'bb', $data['selected_provider'] );
		self::assertSame( 'Bitbucket', $data['provider']['label'] );
		self::assertSame(
			array( false, false, true, false, false, false ),
			array_column( $data['tabs'], 'active' )
		);
		self::assertArrayNotHasKey( 'onboarding', $data );
	}

	public function test_provider_route_selects_focused_views_tasks_and_bounded_list_state(): void {
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

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->get_index()['data'];

		self::assertSame( 'secrets', $data['provider_view'] );
		self::assertSame( 'setup', $data['provider_task'] );
		self::assertSame( 'releases', $data['repository_view'] );
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
			$data['provider_list_state']
		);

		$_GET['view']            = 'unknown';
		$_GET['panel']           = 'unknown';
		$_GET['repository_view'] = 'unknown';
		$_GET['orderby']         = 'unknown';

		$fallback = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->get_index()['data'];

		self::assertSame( 'overview', $fallback['provider_view'] );
		self::assertSame( 'status', $fallback['provider_task'] );
		self::assertSame( 'status', $fallback['repository_view'] );
		self::assertSame( 'name', $fallback['provider_list_state']['orderby'] );
	}

	public function test_provider_route_renders_the_prepared_accessible_filtered_and_paginated_outcome(): void {
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
			$profiles[ 'profile-' . $index ] = array(
				'id'            => 'profile-' . $index,
				'label'         => sprintf( 'Credential %02d', $index ),
				'kind'          => 'api-key',
				'configuration' => array(),
				'source'        => 'file',
				'configured'    => true,
			);
		}
		$profiles['filtered-canary'] = array(
			'id'            => 'filtered-canary',
			'label'         => 'Filtered canary',
			'kind'          => 'other',
			'configuration' => array(),
			'source'        => 'file',
			'configured'    => true,
		);
		$secrets                     = new class( $profiles ) extends SecretsFile {
			/** @param array<string, array<string,mixed>> $profiles */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/test-secrets.php', array(), ShippedSecretPolicyCatalog::create() );
			}
			/** @return array<string, array<string, mixed>> */
			public function credential_profiles( ProviderCode|string $provider ): array {
				return 'bb' === (string) $provider ? $this->profiles : array();
			}
			public function webhook_profiles( ProviderCode|string $provider ): array {
				unset( $provider );
				return array();
			}
		};
		$data                        = $this->dashboard( $secrets, provider_credentials: true )->get_index()['data'];

		// Dashboard supplies a fixed provider-route model; the passive view only renders and escapes it.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Bind the fixed Dashboard route model as caller variables required by the production provider view.
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

	public function test_provider_route_renders_anormalized_repository_selection(): void {
		$_GET    = array(
			'tab'        => 'bb',
			'panel'      => 'repositories',
			'repository' => 'repo-route',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'plugin/route.php'     => $this->managed_package(
					'plugin/route.php',
					'Route Plugin',
					'repo-route',
					\RAN\PackageSource::RELEASE_ASSET,
					'bb',
					repository: 'workspace/route'
				),
				'plugin/automatic.php' => $this->managed_package(
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
			provider_credentials: true
		)->get_index()['data'];

		self::assertSame( 'repo-route', $data['requested_repository_id'] );
		self::assertSame( 'repo-route', $data['selected_repository_row']['repository_id'] );
		self::assertSame( 'attention', $data['webhook_summary']['tone'] );
		self::assertStringContainsString( 'Automatic branch deployments require local signing material', $data['webhook_summary']['description'] );
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

	public function test_repository_release_callback_uses_the_exact_published_releases_return_url(): void {
		$_GET    = array(
			'tab'             => 'bb',
			'panel'           => 'repositories',
			'repository'      => 'repo-route',
			'repository_view' => 'releases',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'plugin/route.php' => $this->managed_package(
					'plugin/route.php',
					'Route Plugin',
					'repo-route',
					\RAN\PackageSource::RELEASE_ASSET,
					'bb',
					repository: 'workspace/route'
				),
			)
		);
		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			provider_credentials: true
		)->get_index()['data'];

		$release_return_url = '';
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_repository_release_sections'][] = static function ( array $row, string $return_url ) use ( &$release_return_url ): void {
			unset( $row );
			$release_return_url = $return_url;
		};
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed route model is rendered through the production view.
		extract( $data );
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		ob_end_clean();

		self::assertSame( 'releases', $data['repository_view'] );
		self::assertStringContainsString( 'repository=repo-route', $release_return_url );
		self::assertStringContainsString( 'repository_view=releases', $release_return_url );
	}

	public function test_provider_repository_projection_unifies_package_types_and_sources_by_stable_identity(): void {
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				$this->managed_package(
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
		$themes->method( 'all_deployment_themes' )->willReturn(
			array(
				$this->managed_package(
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
			provider_credentials: true
		)->get_index()['data'];

		self::assertCount( 1, $data['provider_repositories']['repositories'] );
		$repository = $data['provider_repositories']['repositories'][0];
		self::assertSame( 'repo-shared', $repository['repository_id'] );
		self::assertSame( 'mixed', $repository['source'] );
		self::assertSame( array( 'plugin/shared.php' ), $repository['branch_package_references'] );
		self::assertSame( array( 'plugin', 'theme' ), array_column( $repository['package_summaries'], 'type' ) );
		self::assertSame( 'packages/plugin', $repository['package_summaries'][0]['subdirectory'] );
		self::assertCount( 1, $data['managed_webhook_repositories']['repositories'] );
		self::assertSame( 'branch', $data['managed_webhook_repositories']['repositories'][0]['source'] );
		self::assertSame( 'Conflicting sources', $data['repository_table_rows'][0]['source_label'] );
	}

	public function test_provider_repository_projection_uses_network_package_settings_urls_on_multisite(): void {
		$this->set_multisite( true );
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array( $this->managed_package( 'plugin/example.php', 'Example Plugin', 'repo-example', provider: 'bb' ) )
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			provider_credentials: true
		)->get_index()['data'];

		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$data['provider_repositories']['repositories'][0]['package_summaries'][0]['settings_url']
		);
	}

	public function test_provider_repository_projection_keeps_webhook_settings_url_when_release_precedes_branch(): void {
		$_GET             = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$release_packages = array();
		for ( $index = 1; $index <= 20; ++$index ) {
			$release_packages[] = $this->managed_package(
				'release-shared-' . $index . '.php',
				'Release Shared ' . $index,
				'repo-shared',
				\RAN\PackageSource::RELEASE_ASSET,
				'bb',
				repository: 'workspace/shared'
			);
		}
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( $release_packages );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn(
			array(
				$this->managed_package(
					'branch-shared',
					'Branch Shared',
					'repo-shared',
					policy: \RAN\Deployment\DeploymentPolicy::AUTOMATIC,
					provider: 'bb',
					repository: 'workspace/shared'
				),
			)
		);

		$data       = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes,
			provider_credentials: true
		)->get_index()['data'];
		$repository = $data['provider_repositories']['repositories'][0];

		self::assertSame( 'mixed', $repository['source'] );
		self::assertSame( 'https://example.test/workspace/shared/settings/hooks', $repository['webhook_settings_url'] );
		self::assertTrue( $repository['has_automatic_branch_consumer'] );
		self::assertCount( 20, $repository['package_summaries'] );
		self::assertSame( 1, $repository['package_summaries_omitted'] );
		self::assertSame( 0, $data['repository_integration_summary']['needs_review'] );
	}

	public function test_provider_repository_projection_fails_closed_for_conflicting_stable_identity(): void {
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				$this->managed_package( 'plugin/one.php', 'One', 'repo-conflict', provider: 'bb', repository: 'workspace/one' ),
				$this->managed_package( 'plugin/two.php', 'Two', 'repo-conflict', provider: 'bb', repository: 'workspace/two' ),
				$this->managed_package( 'plugin/three.php', 'Three', 'repo-three', provider: 'bb', repository: 'workspace/shared-locator' ),
				$this->managed_package( 'plugin/four.php', 'Four', 'repo-four', provider: 'bb', repository: 'workspace/shared-locator' ),
			)
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			provider_credentials: true
		)->get_index()['data'];

		self::assertCount( 3, $data['repository_table_rows'] );
		foreach ( $data['repository_table_rows'] as $row ) {
			self::assertTrue( $row['historical'] );
			self::assertSame( array(), $row['actions'] );
			self::assertSame( '', $row['repository_url'] );
			self::assertSame( 'Repository identity conflict', $row['statuses'][0]['label'] );
		}
	}

	public function test_provider_repository_projection_does_not_fold_opaque_provider_locators(): void {
		$_GET    = array(
			'tab'   => 'bb',
			'panel' => 'repositories',
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				$this->managed_package( 'plugin/upper.php', 'Uppercase Locator', 'repo-upper', provider: 'bb', repository: 'Owner/Repo' ),
				$this->managed_package( 'plugin/lower.php', 'Lowercase Locator', 'repo-lower', provider: 'bb', repository: 'owner/repo' ),
			)
		);

		$data = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			provider_credentials: true
		)->get_index()['data'];

		self::assertCount( 2, $data['repository_table_rows'] );
		foreach ( $data['repository_table_rows'] as $row ) {
			self::assertFalse( $row['historical'] );
			self::assertNotSame( '', $row['repository_url'] );
		}
	}

	public function test_selected_add_on_uses_the_registered_tab_and_safe_context(): void {
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

		$data = $this->dashboard( $this->throwing_secrets(), admin_add_ons: $registry )->get_index()['data'];

		self::assertSame( 'fixture', $data['tab'] );
		self::assertArrayNotHasKey( 'tab_view', $data );
		self::assertInstanceOf( AdminAddOnTab::class, $data['add_on_tab'] );
		self::assertSame( 'fixture', $data['add_on_context']->tab_key() );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture',
			$data['add_on_context']->booster_url()
		);
		self::assertSame( array( false, false, false, false, false, false, true ), array_column( $data['tabs'], 'active' ) );
	}

	public function test_portability_builds_display_safe_rows_from_the_non_cleaning_inventory(): void {
		$_GET['tab'] = 'portability';
		$plugin      = $this->managed_package( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$theme       = $this->managed_package( 'example-theme', 'Example Theme', 'theme-repository-id' );
		$plugins     = $this->createStub( PluginRepository::class );
		$themes      = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );

		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of credential_profiles retains the production method contract; these inputs do not affect this controlled result.
			public function credential_profiles( ProviderCode|string $provider ): array {
				return array();
			}
		};
		$data    = $this->dashboard( $secrets, null, null, null, $plugins, $themes )->get_index()['data'];

		self::assertFalse( $data['portability_export_unavailable'] );
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
			$data['portability_export_rows']
		);
		self::assertSame( array(), $data['portability_export_credential_groups'] );
		self::assertFalse( $data['portability_export_credentials_unavailable'] );
	}

	public function test_native_transporter_route_forces_the_canonical_tab_without_mutating_the_request(): void {

		$_GET = array( 'tab' => 'documentation' );

		$data = $this->dashboard( $this->throwing_secrets() )->get_transporter()['data'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixture verifies the route leaves the request intact.
		self::assertSame( array( 'tab' => 'documentation' ), $_GET );

		self::assertSame( 'portability', $data['tab'] );
		self::assertSame( 'portability.php', $data['tab_view'] );
		self::assertSame( array( false, false, false, true, false, false ), array_column( $data['tabs'], 'active' ) );
	}

	public function test_empty_word_press_action_argument_leaves_canonical_tab_routing_intact(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixture models WordPress's empty action argument.
		$_GET = array( 'tab' => 'documentation' );

		$data = $this->dashboard( $this->throwing_secrets() )->get_index( '' )['data'];

		self::assertSame( 'documentation', $data['tab'] );
		self::assertSame( 'documentation.php', $data['tab_view'] );
	}

	public function test_portability_groups_only_display_safe_credential_metadata_and_keeps_package_only_fallback(): void {
		$_GET['tab'] = 'portability';
		$plugin      = $this->managed_package( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id', credential_id: 'shared-profile' );
		$theme       = $this->managed_package( 'example-theme', 'Example Theme', 'theme-repository-id', credential_id: 'shared-profile' );
		$plugins     = $this->createStub( PluginRepository::class );
		$themes      = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of credential_profiles retains the production method contract; these inputs do not affect this controlled result.
			public function credential_profiles( ProviderCode|string $provider ): array {
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

		$data = $this->dashboard( $secrets, null, null, null, $plugins, $themes )->get_index()['data'];

		self::assertFalse( $data['portability_export_credentials_unavailable'] );
		self::assertSame( array( 'code', 'label', 'credentials' ), array_keys( $data['portability_export_credential_groups'][0] ) );
		self::assertCount( 2, $data['portability_export_credential_groups'][0]['credentials'] );
		$credential = $data['portability_export_credential_groups'][0]['credentials'][0];
		self::assertSame( array( 'id', 'label', 'kind_label', 'available', 'reason', 'destroy_on', 'packages' ), array_keys( $credential ) );
		self::assertSame( array( 'Example Plugin', 'Example Theme' ), array_column( $credential['packages'], 'name' ) );
		self::assertArrayNotHasKey( 'configuration', $credential );
		$unassociated = $data['portability_export_credential_groups'][0]['credentials'][1];
		self::assertFalse( $unassociated['available'] );
		self::assertSame( 'unassociated', $unassociated['reason'] );
		self::assertSame( array(), $unassociated['packages'] );
		self::assertArrayNotHasKey( 'configuration', $unassociated );
		self::assertStringNotContainsString( 'unused-configuration-canary', (string) wp_json_encode( $data['portability_export_credential_groups'] ) );

		$unavailable = $this->dashboard( $this->throwing_secrets(), null, null, null, $plugins, $themes )->get_index()['data'];
		self::assertFalse( $unavailable['portability_export_unavailable'] );
		self::assertCount( 2, $unavailable['portability_export_rows'] );
		self::assertTrue( $unavailable['portability_export_credentials_unavailable'] );
	}

	/** @return list<array{string, string}> */
	public static function static_tab_provider(): array {
		return array(
			array( 'overview', 'onboarding.php' ),
			array( 'documentation', 'documentation.php' ),
			array( 'troubleshooting', 'troubleshooting.php' ),
		);
	}

	#[DataProvider( 'static_tab_provider' )]
	public function test_static_tabs_do_not_read_credential_or_webhook_profiles( string $key, string $view ): void {
		$_GET['tab'] = $key;

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame( $key, $data['tab'] );
		self::assertSame( $view, $data['tab_view'] );
		self::assertArrayNotHasKey( 'provider', $data );
		self::assertSame( 1, count( array_filter( $data['tabs'], static fn ( array $tab ): bool => $tab['active'] ) ) );
		self::assertSame( 'documentation' === $key, array_key_exists( 'provider_documentation', $data ) );
		self::assertSame( 'overview' === $key, array_key_exists( 'onboarding', $data ) );

		if ( 'documentation' === $key ) {
			self::assertSame( array( 'gh', 'bb' ), array_column( $data['provider_documentation'], 'code' ) );
		} elseif ( 'overview' === $key ) {
			self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
		}
	}

	/** @return list<array{mixed}> */
	public static function fallback_tab_provider(): array {
		return array(
			array( null ),
			array( '' ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
		);
	}

	#[DataProvider( 'fallback_tab_provider' )]
	public function test_missing_unknown_and_array_tabs_fall_back_without_deriving_aview( mixed $requested ): void {
		if ( null !== $requested ) {
			$_GET['tab'] = $requested;
		}

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tab_view'] );
		self::assertArrayNotHasKey( 'selected_provider', $data );
		self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-transporter',
			$data['onboarding']['portability_url']
		);
	}

	public function test_navigation_uses_the_correct_single_and_network_admin_bases(): void {
		$_GET['tab'] = 'documentation';
		$single_site = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
			$single_site['tabs'][0]['url']
		);

		$this->set_multisite( true );
		$network_site = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=overview',
			$network_site['tabs'][0]['url']
		);

		$_GET['tab']      = 'overview';
		$network_overview = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];
		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster-plugins-create',
			$network_overview['onboarding']['install_plugin_url']
		);
	}

	public function test_legacy_add_on_tab_requests_fall_back_to_the_overview(): void {
		$_GET['tab'] = 'assisted-hooks';

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tab_view'] );
		self::assertArrayNotHasKey( 'add_on_tab', $data );
	}

	public function test_native_package_hooks_receive_bounded_projections_for_settings_rows_and_actions(): void {
		$settings_reads   = array();
		$management_reads = array();
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][]          =
			static function ( \RAN\Admin\AdminPackageProjection $package, string $settings_url ) use ( &$settings_reads ): void {
				$settings_reads[] = array( $package->identifier(), $settings_url );
				echo '<section>Release settings</section>';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][]    =
			static function ( array $rows, string $surface, array $packages ) use ( &$management_reads ): array {
				$management_reads[] = array( $surface, array_keys( $packages ) );

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
						'url'   => $package->settings_url(),
					),
				);
			};
		$package = $this->managed_package( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins );

		$_GET  = array( 'package' => 'plugin/example.php' );
		$edit  = $dashboard->get_plugins()['data'];
		$_GET  = array();
		$index = $dashboard->get_plugins()['data'];

		self::assertSame(
			array(
				array(
					'plugin/example.php',
					'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
				),
			),
			$settings_reads
		);
		self::assertSame( array( array( 'plugin', array( 'plugin/example.php' ) ) ), $management_reads );
		self::assertSame( array( '<section>Release settings</section>' ), $edit['package_extension_panels'] );
		self::assertNull( $edit['repository_branch_check_outcome'] );
		self::assertArrayNotHasKey( 'repositoryBranchCheckNonce', $edit );
		self::assertSame( 'Latest release: 1.1.0.', $index['package_extension_rows']['plugin/example.php']['status'] );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$index['package_extension_actions']['plugin/example.php']['fixture:manage']['url']
		);
	}

	public function test_failing_native_package_hooks_do_not_break_core_package_pages(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][]       =
			static function (): void {
				throw new RuntimeException( 'Settings unavailable.' );
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][] =
			static function (): array {
				throw new RuntimeException( 'Management unavailable.' );
			};
		$package = $this->managed_package( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins );

		$_GET = array( 'package' => 'plugin/example.php' );
		self::assertSame( array(), $dashboard->get_plugins()['data']['package_extension_panels'] );
		$_GET = array();
		self::assertSame( array(), $dashboard->get_plugins()['data']['package_extension_rows'] );
	}

	public function test_explicit_package_source_view_uses_only_shared_advanced_sections_for_plugins_and_themes(): void {
		$_GET['source_view'] = 'branch';
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_advanced_source_sections'][] =
			static function (): void {
				echo '<section>Advanced source settings</section>';
			};
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$identifier = 'plugin' === $type ? 'plugin/example.php' : 'example-theme';
			$package    = $this->managed_package( $identifier, 'Example Package', 'repository-id' );
			$plugins    = $this->createStub( PluginRepository::class );
			$themes     = $this->createStub( ThemeRepository::class );
			$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
			$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
			$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins, themes: $themes );
			$_GET      = array(
				'package'     => $identifier,
				'source_view' => 'branch',
			);
			$data      = 'plugin' === $type ? $dashboard->get_plugins()['data'] : $dashboard->get_themes()['data'];

			self::assertTrue( $data['package_source']['advanced_open'], $type );
			self::assertSame( array( '<section>Advanced source settings</section>' ), $data['package_source']['advanced_sections'], $type );
			self::assertArrayNotHasKey( 'sections', $data['package_source'], $type );
		}
	}

	public function test_explicit_advanced_open_flag_opens_the_selected_source_view(): void {
		$package = $this->managed_package( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins );
		$_GET      = array(
			'package'                   => 'plugin/example.php',
			'source_view'               => 'branch',
			'ran_booster_open_advanced' => '1',
		);

		$data = $dashboard->get_plugins()['data'];

		self::assertTrue( $data['package_source']['advanced_open'] );
		self::assertSame( 'branch', $data['package_source']['selected'] );
	}

	public function test_advanced_source_summary_projection_includes_the_saved_branch_subdirectory(): void {
		$package = $this->managed_package(
			'plugin/example.php',
			'Example Plugin',
			'plugin-repository-id',
			subdirectory: 'packages/example'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins );
		$_GET      = array( 'package' => 'plugin/example.php' );

		$data = $dashboard->get_plugins()['data'];

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
			$data['package_source']['advanced_summary_projection']
		);
		self::assertSame( 'Releases', $data['package_source']['choices']['release_asset']['heading'] );
	}

	public function test_release_deployment_hooks_receive_exact_outer_create_edit_and_index_arguments(): void {
		$source_calls  = array();
		$section_calls = array();
		$summary_calls = array();
		$row_calls     = array();
		$action_calls  = array();
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_source_choices'][]          =
			static function ( array $choices, string $mode, string $type, ?\RAN\Admin\AdminPackageProjection $package, string $page_url ) use ( &$source_calls ): array {
				$source_calls[]                       = array( $mode, $type, $package?->identifier(), $page_url );
				$choices['release_asset']['disabled'] = false;
				$choices['release_asset']['hydrated'] = true;
				$choices['release_asset']['url']      = add_query_arg( 'source_view', 'release_asset', $page_url );

				return $choices;
			};
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_advanced_source_sections'][]        =
			static function ( string $mode, string $type, string $selected, ?\RAN\Admin\AdminPackageProjection $package, string $page_url ) use ( &$section_calls ): void {
				$section_calls[] = array( $mode, $type, $selected, $package?->identifier(), $page_url );
				echo '<section>Release deployment source</section>';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_advanced_source_summary'][] =
			static function ( string $summary, string $mode, string $type, string $selected, ?\RAN\Admin\AdminPackageProjection $package ) use ( &$summary_calls ): string {
				$summary_calls[] = array( $summary, $mode, $type, $selected, $package?->identifier() );

				return 'Published release fixture';
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_rows'][]         =
			static function ( array $rows, string $type, array $packages ) use ( &$row_calls ): array {
				$row_calls[] = array( array_keys( $rows ), $type, array_keys( $packages ) );

				return $rows;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_admin_package_management_actions'][]      =
			static function ( array $actions, string $type, \RAN\Admin\AdminPackageProjection $package ) use ( &$action_calls ): array {
				$action_calls[] = array( $actions, $type, $package->identifier(), $package->settings_url() );

				return $actions;
			};

		$package = $this->managed_package(
			'plugin/example.php',
			'Example Plugin',
			'plugin-repository-id',
			\RAN\PackageSource::RELEASE_ASSET
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( $package ) );
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			plugins: $plugins,
			database: new ReadyDashboardDatabase()
		);

		$_GET   = array( 'source_view' => 'release_asset' );
		$create = $dashboard->get_plugins_create()['data'];
		$_GET   = array(
			'package'     => 'plugin/example.php',
			'source_view' => 'release_asset',
		);
		$edit   = $dashboard->get_plugins()['data'];
		$_GET   = array();
		$index  = $dashboard->get_plugins()['data'];

		$create_url = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create';
		$edit_url   = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php';
		self::assertSame(
			array(
				array( 'create', 'plugin', null, $create_url ),
				array( 'edit', 'plugin', 'plugin/example.php', $edit_url ),
			),
			$source_calls
		);
		self::assertSame(
			array(
				array( 'create', 'plugin', 'branch', null, $create_url ),
				array( 'edit', 'plugin', 'release_asset', 'plugin/example.php', $edit_url ),
			),
			$section_calls
		);
		self::assertSame( array( 'create', 'edit' ), array_column( $summary_calls, 1 ) );
		self::assertSame( array( 'branch', 'release_asset' ), array_column( $summary_calls, 3 ) );
		self::assertSame( array( array( array( 'plugin/example.php' ), 'plugin', array( 'plugin/example.php' ) ) ), $row_calls );
		self::assertSame( array( array( array(), 'plugin', 'plugin/example.php', $edit_url ) ), $action_calls );
		self::assertSame( 'branch', $create['package_source']['selected'] );
		self::assertSame( 'release_asset', $edit['package_source']['selected'] );
		self::assertSame( 'Published release fixture', $edit['package_source']['advanced_summary'] );
		self::assertArrayHasKey( 'plugin/example.php', $index['package_extension_rows'] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_saved_release_source_remains_unavailable_without_its_add_on_for_both_package_types( string $type ): void {
		$identifier = 'plugin' === $type ? 'release/release.php' : 'release-theme';
		$package    = $this->managed_package(
			$identifier,
			'Release Package',
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET
		);
		$plugins    = $this->createStub( PluginRepository::class );
		$themes     = $this->createStub( ThemeRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins, themes: $themes );
		$_GET      = array(
			'package'     => $identifier,
			'source_view' => 'branch',
		);

		$data = 'plugin' === $type ? $dashboard->get_plugins()['data'] : $dashboard->get_themes()['data'];

		self::assertSame( 'release_asset', $data['package_source']['current'] );
		self::assertSame( 'release_asset', $data['package_source']['selected'] );
		self::assertTrue( $data['package_source']['unavailable'] );
	}

	public function test_release_package_retains_current_branch_readiness_without_provider_operations(): void {
		$package  = $this->managed_package(
			'release/release.php',
			'Release Package',
			'101',
			\RAN\PackageSource::RELEASE_ASSET,
			'gh',
			\RAN\Deployment\DeploymentPolicy::MANUAL,
			'owner/repository'
		);
		$plugins  = new class( $package ) extends PluginRepository {
			public function __construct( private Package $package ) {
			}

			/** @return Package|null */
			public function booster_plugin_from_file( $file ) {
				return 'release/release.php' === $file ? $this->package : null;
			}

			public function all_booster_plugins(): array {
				return array( $this->package );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
			public function all_deployment_plugins( ?\RAN\PackageSource $source = null ): array {
				return array( 'release/release.php' => $this->package );
			}
		};
		$themes   = new class() extends ThemeRepository {
			public function __construct() {
			}

			public function all_booster_themes(): array {
				return array();
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_themes retains the production method contract; these inputs do not affect this controlled result.
			public function all_deployment_themes( ?\RAN\PackageSource $source = null ): array {
				return array();
			}
		};
		$secrets  = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function assert_managed_storage_ready(): void {
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_profiles retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_profiles( ProviderCode|string $provider ): array {
				return array(
					'webhook-profile' => array(
						'scope'        => 'repository',
						'target'       => 'owner/repository',
						'authority_id' => '101',
						'configured'   => true,
					),
				);
			}
		};
		$provider = $this->createMockForIntersectionOfInterfaces(
			array(
				RepositoryProvider::class,
				ProviderCredentialPolicySupplier::class,
				\RAN\RepositoryProvider\WebhookNormalizer::class,
			)
		);
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' ) );
		$policies = ShippedSecretPolicyCatalog::create();
		$provider->method( 'get_credential_policy' )->willReturn( $policies->credential_policy( ProviderCode::parse( 'gh' ) ) );
		$provider->method( 'get_webhook_policy' )->willReturn( $policies->webhook_policy( ProviderCode::parse( 'gh' ) ) );
		$provider->expects( self::never() )->method( 'resolve_repository' );
		$provider->expects( self::never() )->method( 'prepare_archive' );
		self::assertInstanceOf( \RAN\RepositoryProvider\WebhookNormalizer::class, $provider );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$evaluator = new WebhookAssistanceReadinessEvaluator( $plugins, $themes, $secrets, new ReadyDashboardDatabase(), static fn (): bool => true );
		self::assertSame( 'ready', $evaluator->evaluate( 'gh', rest_url( 'ran-booster/v1/webhooks/gh' ) )->to_array()['site']['status'] );
		$dashboard = $this->dashboard(
			$secrets,
			plugins: $plugins,
			themes: $themes,
			database: new ReadyDashboardDatabase(),
			providers: new ProviderRegistry( array( $provider ) ),
			webhook_assistance: $evaluator
		);
		$_GET      = array(
			'package'     => 'release/release.php',
			'source_view' => 'branch',
		);

		$readiness = $dashboard->get_plugins()['data']['package_branch_readiness'];

		self::assertIsArray( $readiness );
		self::assertArrayHasKey( 'retained', $readiness );
		self::assertTrue( $readiness['retained'] );
		self::assertSame( 'ready', $readiness['site']['status'] );
		self::assertSame( '101', $readiness['repository']['repository_id'] );
		self::assertSame( 'repository', $readiness['repository']['local_secret_coverage'] );
	}

	public function test_troubleshooting_results_render_only_in_the_same_dashboard_request(): void {
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

		self::assertFalse( $dashboard->get_index()['data']['troubleshooting']['ran'] );
		$dashboard->post_run_troubleshooting( array( 'provider' => 'gh' ) );
		self::assertTrue( $dashboard->get_index()['data']['troubleshooting']['ran'] );
		self::assertCount( 5, $dashboard->get_index()['data']['troubleshooting']['results'] );
		self::assertFalse( $this->dashboard( $secrets, $service )->get_index()['data']['troubleshooting']['ran'] );
	}

	public function test_deployment_activity_uses_its_own_read_payload_without_diagnostics_results(): void {
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data'];

		self::assertSame( 'activity', $data['troubleshooting_panel'] );
		self::assertSame( array(), $data['troubleshooting'] );
		self::assertTrue( $data['deployment_activity']['unavailable'] );
		self::assertSame( 'list', $data['deployment_activity']['mode'] );
	}

	public function test_debug_capture_uses_only_its_bounded_file_payload(): void {
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

			$data = $this->dashboard( $this->throwing_secrets(), null, null, null, null, null, $capture )->get_index()['data'];

			self::assertSame( 'debug-capture', $data['troubleshooting_panel'] );
			self::assertSame( array(), $data['troubleshooting'] );
			self::assertSame( 'active', $data['debug_capture']['state'] );
			self::assertSame( 'ran-booster-debug.php', $data['debug_capture']['filename'] );
			self::assertStringContainsString( '[ran-booster] safe dashboard event', $data['debug_capture']['content'] );
			self::assertArrayNotHasKey( 'deployment_activity', $data );
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
	public static function package_storage_read_provider(): array {
		return array(
			array( 'plugin', false ),
			array( 'plugin', true ),
			array( 'theme', false ),
			array( 'theme', true ),
		);
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_selected_package_routes_render_the_shared_edit_payload( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$package    = $this->managed_package( $identifier, 'Example Package', 'example-repository' );
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		$plugins->expects( 'plugin' === $type ? self::once() : self::never() )
			->method( 'booster_plugin_from_file' )
			->with( $identifier )
			->willReturn( $package );
		$themes->expects( 'theme' === $type ? self::once() : self::never() )
			->method( 'booster_theme_from_stylesheet' )
			->with( $identifier )
			->willReturn( $package );

		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->get_plugins() : $dashboard->get_themes();

		self::assertSame( 'packages/edit', $result['view'] );
		self::assertSame(
			array(
				'package_provider_settings',
				'package_branch_readiness',
				'package',
				'package_view',
				'package_extension_panels',
				'package_source',
				'repository_branch_check_outcome',
				'repository_branch_check_evidence',
			),
			array_keys( $result['data'] )
		);
		self::assertSame( $package, $result['data']['package'] );
		self::assertSame( $type, $result['data']['package_view']->get_type() );
		self::assertSame( array(), $result['data']['package_extension_panels'] );
		self::assertSame( 'branch', $result['data']['package_source']['current'] );
		self::assertSame( 'branch', $result['data']['package_source']['selected'] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_network_create_edit_and_index_routes_share_the_canonical_package_admin_base( string $type ): void {
		$this->set_multisite( true );
		$identifier    = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$package       = $this->managed_package( $identifier, 'Example Package', 'example-repository' );
		$settings_urls = array();
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_settings_sections'][] =
			static function ( \RAN\Admin\AdminPackageProjection $projection, string $settings_url ) use ( &$settings_urls ): void {
				$settings_urls[] = array( $projection->settings_url(), $settings_url );
			};
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$plugins->method( 'all_booster_plugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$themes->method( 'all_booster_themes' )->willReturn( 'theme' === $type ? array( $package ) : array() );
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			plugins: $plugins,
			themes: $themes,
			database: new ReadyDashboardDatabase()
		);
		$base      = 'https://example.test/wp-admin/network/admin.php';

		$_GET   = array();
		$create = 'plugin' === $type ? $dashboard->get_plugins_create()['data'] : $dashboard->get_themes_create()['data'];
		$_GET   = array( 'package' => $identifier );
		$edit   = 'plugin' === $type ? $dashboard->get_plugins()['data'] : $dashboard->get_themes()['data'];
		$_GET   = array();
		$index  = 'plugin' === $type ? $dashboard->get_plugins()['data'] : $dashboard->get_themes()['data'];

		self::assertSame( $base, $create['package_view']->get_admin_url() );
		self::assertSame( $base, $edit['package_view']->get_admin_url() );
		self::assertSame( $base, $index['package_view']->get_admin_url() );
		self::assertStringStartsWith( $base . '?page=', $create['package_source']['choices']['branch']['url'] );
		self::assertSame( array( array( $settings_urls[0][0], $settings_urls[0][0] ) ), $settings_urls );
		self::assertStringStartsWith( $base . '?page=', $settings_urls[0][0] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_signed_install_notice_projects_the_managed_package_into_the_create_view( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$_GET       = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( 'ran-booster-package-success|' . $type . '|install|' . $identifier ),
		);
		$dashboard  = $this->dashboard(
			$this->throwing_secrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->get_plugins_create() : $dashboard->get_themes_create();

		self::assertSame( $identifier, $result['data']['managed_package_identifier'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( ucfirst( $type ) . ' was successfully installed.', $dashboard->messages[0]['message'] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_signed_already_managed_notice_projects_the_managed_package_into_the_create_view( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$_GET       = array(
			'ran_booster_result'        => 'already-managed',
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( 'ran-booster-package-success|' . $type . '|already-managed|' . $identifier ),
		);
		$dashboard  = $this->dashboard(
			$this->throwing_secrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->get_plugins_create() : $dashboard->get_themes_create();

		self::assertSame( $identifier, $result['data']['managed_package_identifier'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame(
			'plugin' === $type
				? 'Plugin is already installed and managed by Booster. No package settings were changed.'
				: 'Theme is already installed and managed by Booster. No package settings were changed.',
			$dashboard->messages[0]['message']
		);
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_forged_install_notice_does_not_change_the_create_actions( string $type ): void {
		$_GET      = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => 'forged-package',
			'_ran_booster_notice_nonce' => 'forged',
		);
		$dashboard = $this->dashboard(
			$this->throwing_secrets(),
			database: new ReadyDashboardDatabase()
		);

		$result = 'plugin' === $type ? $dashboard->get_plugins_create() : $dashboard->get_themes_create();

		self::assertNull( $result['data']['managed_package_identifier'] );
		self::assertSame( array(), $dashboard->messages );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_missing_selected_packages_fall_back_to_the_matching_index( string $type ): void {
		$identifier = 'plugin' === $type ? 'missing/missing.php' : 'missing-theme';
		$fallback   = $this->managed_package(
			'plugin' === $type ? 'available/available.php' : 'available-theme',
			'Available Package',
			'available-repository'
		);
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		if ( 'plugin' === $type ) {
			$plugins->expects( self::once() )
				->method( 'booster_plugin_from_file' )
				->with( $identifier )
				->willThrowException( new PluginNotFound( 'Missing fixture plugin.' ) );
			$plugins->expects( self::once() )->method( 'all_booster_plugins' )->willReturn( array( $fallback ) );
			$themes->expects( self::never() )->method( 'booster_theme_from_stylesheet' );
			$themes->expects( self::never() )->method( 'all_booster_themes' );
		} else {
			$themes->expects( self::once() )
				->method( 'booster_theme_from_stylesheet' )
				->with( $identifier )
				->willThrowException( new ThemeNotFound( 'Missing fixture theme.' ) );
			$themes->expects( self::once() )->method( 'all_booster_themes' )->willReturn( array( $fallback ) );
			$plugins->expects( self::never() )->method( 'booster_plugin_from_file' );
			$plugins->expects( self::never() )->method( 'all_booster_plugins' );
		}

		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->get_plugins() : $dashboard->get_themes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array( $fallback ), $result['data']['packages'] );
		self::assertSame( $type, $result['data']['package_view']->get_type() );
		self::assertSame( 1, $result['data']['package_list_total'] );
	}

	/** @return list<array{string, string}> */
	public static function package_list_filter_provider(): array {
		return array(
			array( 'plugin', 'Release Plugin' ),
			array( 'theme', 'Release Theme' ),
		);
	}

	#[DataProvider( 'package_list_filter_provider' )]
	public function test_package_indexes_apply_combined_normalized_filters_for_both_package_types( string $type, string $release_name ): void {
		$_GET    = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
		);
		$branch  = $this->managed_package(
			'plugin' === $type ? 'alpha/alpha.php' : 'alpha-theme',
			'plugin' === $type ? 'Alpha Plugin' : 'Alpha Theme',
			'alpha-repository'
		);
		$release = $this->managed_package(
			'plugin' === $type ? 'release/release.php' : 'release-theme',
			$release_name,
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET,
			'bb',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC,
			'studio/release-package',
			'stable'
		);
		$other   = $this->managed_package(
			'plugin' === $type ? 'other/other.php' : 'other-theme',
			'plugin' === $type ? 'Other Plugin' : 'Other Theme',
			'other-repository',
			\RAN\PackageSource::BRANCH,
			'gh',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( 'plugin' === $type ? array( $branch, $release, $other ) : array() );
		$themes->method( 'all_booster_themes' )->willReturn( 'theme' === $type ? array( $branch, $release, $other ) : array() );

		$result = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data   = 'plugin' === $type ? $result->get_plugins()['data'] : $result->get_themes()['data'];

		self::assertSame( 3, $data['package_list_total'] );
		self::assertSame(
			array(
				'search'   => 'release',
				'provider' => 'bb',
				'source'   => 'release_asset',
				'policy'   => 'automatic',
			),
			$data['package_list_state']
		);
		self::assertSame( array( 'Bitbucket', 'GitHub' ), array_column( $data['package_provider_options'], 'label' ) );
		self::assertSame( array( $release->get_identifier() ), array_map( static fn ( Package $package ): mixed => $package->get_identifier(), $data['packages'] ) );
	}

	public function test_repeated_package_index_rendering_uses_fresh_repository_readback(): void {
		$first   = $this->managed_package( 'first/first.php', 'First Plugin', 'first-repository' );
		$second  = $this->managed_package( 'second/second.php', 'Second Plugin', 'second-repository' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::exactly( 2 ) )
			->method( 'all_booster_plugins' )
			->willReturnOnConsecutiveCalls( array( $first ), array( $second ) );
		$dashboard = $this->dashboard( $this->throwing_secrets(), plugins: $plugins );

		$initial = $dashboard->get_plugins()['data'];
		$fresh   = $dashboard->get_plugins()['data'];

		self::assertSame( array( $first ), $initial['packages'] );
		self::assertSame( array( $second ), $fresh['packages'] );
	}

	#[DataProvider( 'package_list_filter_provider' )]
	public function test_package_indexes_discard_malformed_and_unsupported_filter_values( string $type, string $release_name ): void {
		unset( $release_name );
		$_GET    = array(
			's'        => array( 'not-scalar' ),
			'provider' => 'missing-provider',
			'source'   => 'archive',
			'policy'   => 'sometimes',
		);
		$package = $this->managed_package(
			'plugin' === $type ? 'example/example.php' : 'example-theme',
			'Example Package',
			'example-repository'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$themes->method( 'all_booster_themes' )->willReturn( 'theme' === $type ? array( $package ) : array() );

		$dashboard = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data      = 'plugin' === $type ? $dashboard->get_plugins()['data'] : $dashboard->get_themes()['data'];

		self::assertSame(
			array(
				'search'   => '',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			$data['package_list_state']
		);
		self::assertSame( array( $package ), $data['packages'] );
	}

	#[DataProvider( 'package_storage_read_provider' )]
	public function test_invalid_package_storage_renders_asafe_empty_index( string $type, bool $detail ): void {
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

		$result = 'plugin' === $type ? $dashboard->get_plugins() : $dashboard->get_themes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array(), $result['data']['packages'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $dashboard->messages[0]['code'] );
		self::assertSame( array( 'recovery_required' => false ), $dashboard->messages[0]['data'] );
		self::assertStringNotContainsString( 'upstream-provider-canary', $dashboard->messages[0]['message'] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_incompatible_database_renders_adisabled_create_screen( string $type ): void {
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

		$result = 'plugin' === $type ? $dashboard->get_plugins_create() : $dashboard->get_themes_create();

		self::assertSame( 'packages/create', $result['view'] );
		self::assertFalse( $result['data']['package_mutation_available'] );
		self::assertFalse( $result['data']['open_repository_picker'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_storage_database_unsupported', $dashboard->messages[0]['code'] );
	}

	/** @return list<array{string}> */
	public static function package_type_provider(): array {
		return array(
			array( 'plugin' ),
			array( 'theme' ),
		);
	}

	public function test_bulk_package_redirect_preserves_only_normalized_list_filters(): void {
		$_GET = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
			'unsafe'   => '<script>',
		);

		$url = $this->dashboard( $this->throwing_secrets() )->bulk_package_redirect(
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

	public function test_bulk_package_redirect_rejects_aplugin_activation_result_for_the_theme_list(): void {
		$this->expectException( \LogicException::class );

		$this->dashboard( $this->throwing_secrets() )->bulk_package_redirect(
			'theme',
			BulkPackageResult::plugin_activation(
				BulkPackageAction::ACTIVATE_PLUGINS,
				1,
				1,
				0,
				array()
			)
		);
	}

	public function test_signed_bulk_queue_notice_reports_partial_success_and_unavailable_runner(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::queue(
				selected: 4,
				queued: 2,
				skipped_by_reason: array(
					'busy'     => 1,
					'disabled' => 1,
				),
				runner_status: 'unavailable'
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->get_plugins();

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

	public function test_signed_bulk_queue_notice_reports_when_every_selection_was_skipped(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::queue(
				2,
				0,
				array( 'disabled' => 2 ),
				'not_required'
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->get_plugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Queued 0 plugins', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Skipped: 2', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deployment disabled: 2', $dashboard->messages[0]['message'] );
		self::assertStringNotContainsString( 'could not schedule the deployment runner', $dashboard->messages[0]['message'] );
	}

	public function test_signed_bulk_policy_notice_reports_changed_and_unchanged_counts(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->get_themes();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'success', $dashboard->messages[0]['type'] );
		self::assertSame(
			'Changed 2 themes to Manual. Already in that state: 1.',
			$dashboard->messages[0]['message']
		);
	}

	public function test_signed_bulk_activation_notice_reports_partial_word_press_state_change(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::plugin_activation(
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->get_plugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Changed 1 plugins to Disabled in WordPress', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Already in that state: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deactivation failed: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Booster cannot disable itself: 1', $dashboard->messages[0]['message'] );
	}

	public function test_tampered_bulk_notice_is_ignored(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$_GET['ran_booster_bulk_queued'] = '20';

		$dashboard->get_plugins();

		self::assertSame( array(), $dashboard->messages );
	}

	public function test_bulk_notice_signature_cannot_be_replayed_across_package_types(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query and deliberately presents it to the other package type.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->get_themes();

		self::assertSame( array(), $dashboard->messages );
	}

	public function test_forged_bulk_notice_marker_is_ignored(): void {
		$dashboard = $this->dashboard( $this->throwing_secrets() );
		$url       = $dashboard->bulk_package_redirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test deliberately replaces the signed redirect marker.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$_GET['_ran_booster_bulk_notice_nonce'] = 'forged';

		$dashboard->get_plugins();

		self::assertSame( array(), $dashboard->messages );
	}

	public function test_package_overview_reads_branch_activity_only(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$attempts       = $this->deployment_attempts( $database );
		$branch         = $this->managed_package( 'plugin/branch.php', 'Branch Plugin', 'branch-repository' );
		$release        = $this->managed_package(
			'plugin/release.php',
			'Release Plugin',
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET
		);

		$activity = ( new DeploymentAdminPresenter( attempts: $attempts ) )->package_activity( array( $branch, $release ), 'plugin' );

		self::assertFalse( $activity['unavailable'] );
		self::assertArrayHasKey( 'plugin/branch.php', $activity['items'] );
		self::assertArrayNotHasKey( 'plugin/release.php', $activity['items'] );
	}

	public function test_short_deployment_history_does_not_offer_an_older_page(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 9 ) );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwing_secrets(),
			null,
			null,
			$this->deployment_attempts( $database )
		)->get_index()['data']['deployment_activity'];

		self::assertCount( 9, $data['items'] );
		self::assertSame( 9, $data['items'][0]->get_id() );
		self::assertNull( $data['next_cursor'] );
		self::assertFalse( $data['has_cursor'] );
	}

	public function test_deployment_activity_provides_exact_managed_package_settings_urls(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'plugin/example.php' => $this->managed_package( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
			)
		);
		$themes->method( 'all_deployment_themes' )->willReturn(
			array(
				'example-theme' => $this->managed_package( 'example-theme', 'Example Theme', 'repository-2' ),
			)
		);
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwing_secrets(),
			null,
			null,
			$this->deployment_attempts( $database ),
			$plugins,
			$themes
		)->get_index()['data']['deployment_activity'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$data['package_settings_urls']['plugin']['example']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme',
			$data['package_settings_urls']['theme']['example']
		);
	}

	public function test_deployment_activity_links_fail_closed_for_ambiguous_or_unavailable_inventory(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'plugin/example.php'       => $this->managed_package( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
				'other-example/plugin.php' => $this->managed_package( 'other-example/plugin.php', 'Other Example Plugin', 'repository-2' ),
			)
		);
		$themes->method( 'all_deployment_themes' )->willThrowException( PackageStorageFailure::invalid_provider_identity() );
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwing_secrets(),
			null,
			null,
			$this->deployment_attempts( $database ),
			$plugins,
			$themes
		)->get_index()['data']['deployment_activity'];

		self::assertFalse( $data['unavailable'] );
		self::assertCount( 1, $data['items'] );
		self::assertArrayNotHasKey( 'example', $data['package_settings_urls']['plugin'] );
		self::assertSame( array(), $data['package_settings_urls']['theme'] );
	}

	public function test_deployment_history_uses_lookahead_without_overlapping_pages(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 51 ) );
		$attempts       = $this->deployment_attempts( $database );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$first_page = $this->dashboard( $this->throwing_secrets(), null, null, $attempts )->get_index()['data']['deployment_activity'];

		self::assertCount( 50, $first_page['items'] );
		self::assertSame( 51, $first_page['items'][0]->get_id() );
		self::assertSame( 2, $first_page['items'][49]->get_id() );
		self::assertSame( 2, $first_page['next_cursor'] );

		$_GET['before'] = '2';
		$last_page      = $this->dashboard( $this->throwing_secrets(), null, null, $attempts )->get_index()['data']['deployment_activity'];

		self::assertCount( 1, $last_page['items'] );
		self::assertSame( 1, $last_page['items'][0]->get_id() );
		self::assertNull( $last_page['next_cursor'] );
		self::assertTrue( $last_page['has_cursor'] );
	}

	public function test_exhausted_deployment_history_cursor_remains_an_older_page(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$_GET           = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '1',
		);

		$data = $this->dashboard(
			$this->throwing_secrets(),
			null,
			null,
			$this->deployment_attempts( $database )
		)->get_index()['data']['deployment_activity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertFalse( $data['unavailable'] );
	}

	public function test_malformed_deployment_history_cursor_fails_closed(): void {
		$_GET = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '01',
		);

		$data = $this->dashboard( $this->throwing_secrets(), null, null, $this->deployment_attempts( new DashboardActivityWpdb() ) )
			->get_index()['data']['deployment_activity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function test_malformed_activity_identity_does_not_fall_back_to_abroad_list(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '01',
			'reference' => str_repeat( 'a', 32 ),
		);

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data']['deployment_activity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function test_array_activity_identity_does_not_fall_back_to_abroad_list(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => array( '1' ),
			'reference' => array( str_repeat( 'a', 32 ) ),
		);

		$data = $this->dashboard( $this->throwing_secrets() )->get_index()['data']['deployment_activity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function test_attempt_detail_loads_only_the_requested_attempt(): void {
		$attempt        = DashboardActivityWpdb::attempt( 1, 'succeeded' );
		$database       = new DashboardActivityWpdb();
		$database->rows = array( $attempt, DashboardActivityWpdb::attempt( 2, 'failed' ) );
		$attempts       = $this->deployment_attempts( $database );
		$_GET           = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '1',
			'reference' => $attempt['correlation_id'],
		);

		$data = $this->dashboard( $this->throwing_secrets(), null, null, $attempts )->get_index()['data']['deployment_activity'];

		self::assertFalse( $data['unavailable'] );
		self::assertSame( 1, $data['detail']->get_id() );
		self::assertSame( 'deployed', $data['detail']->get_outcome()?->get_code() );
		self::assertArrayNotHasKey( 'actions', $data );
	}

	public function test_needs_attention_contention_refuses_mutation_until_acknowledged_then_allows_retry(): void {
		$attempt_database        = new AttemptRepositoryDatabase();
		$attempt                 = DashboardActivityWpdb::attempt( 43, 'failed' );
		$attempt['state']        = 'needs_attention';
		$attempt['outcome_code'] = DeploymentOutcome::CODE_INTERRUPTED;
		$attempt_database->rows  = array( $attempt );
		$attempts                = new DeploymentAttemptRepository(
			$attempt_database,
			'wp_ran_booster_deployment_attempts',
			static fn (): \DateTimeImmutable => new \DateTimeImmutable( '2026-07-27 12:00:00 UTC' ),
			static fn ( int $length ): string => str_repeat( "\x0b", $length ),
			new ReadyDashboardDatabase()
		);
		$plugins                 = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'from_slug' );
		$themes       = $this->createStub( ThemeRepository::class );
		$updater_lock = $this->createStub( WordPressUpdaterLock::class );
		$coordinator  = new DashboardNeedsAttentionCoordinator( $attempts );
		$operations   = new PackageOperationService(
			$plugins,
			$themes,
			$coordinator,
			new PackageRemovalService(
				$plugins,
				$themes,
				$this->createStub( PackageRemovalGateway::class ),
				null,
				$updater_lock
			),
			$updater_lock
		);
		$dashboard    = $this->dashboard(
			$this->throwing_secrets(),
			package_operations: $operations,
			deployment_attempts: $attempts
		);
		$request      = array(
			'provider'                            => 'gh',
			'repository'                          => 'owner/example',
			'branch'                              => 'main',
			'package_slug'                        => 'example',
			'provider_repository_id'              => 'R_example',
			'provider_repository_identity_source' => 'manual',
		);
		self::assertFalse(
			$dashboard->post_package_operation(
				'install-plugin',
				$request
			)
		);
		self::assertSame( 1, $coordinator->calls );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertStringContainsString( 'attempt=43', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'reference=' . $attempt['correlation_id'], $dashboard->messages[0]['message'] );
		self::assertThat( $attempt_database->rows, self::countOf( 1 ) );

		self::assertFalse( $dashboard->post_package_operation( 'install-plugin', $request ) );
		self::assertSame( 2, $coordinator->calls );
		self::assertThat( $attempt_database->rows, self::countOf( 1 ) );

		$attempts->resolve_needs_attention( 43, $attempt['correlation_id'], 7 );
		self::assertNotNull( $attempt_database->rows[0]['resolved_at'] );
		self::assertSame( '7', $attempt_database->rows[0]['resolved_by'] );

		self::assertFalse( $dashboard->post_package_operation( 'install-plugin', $request ) );
		self::assertSame( 3, $coordinator->calls );
		self::assertThat( $attempt_database->rows, self::countOf( 2 ) );
		// @phpstan-ignore offsetAccess.notFound (The injected coordinator appends the second row during the preceding retry; preserve its exact position assertion.)
		self::assertSame( 'failed', $attempt_database->rows[1]['state'] );

		$_GET     = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '43',
			'reference' => $attempt['correlation_id'],
		);
		$activity = $dashboard->get_index()['data']['deployment_activity'];
		self::assertSame( 43, $activity['detail']->get_id() );
		self::assertSame( $attempt['correlation_id'], $activity['detail']->get_correlation_id() );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	private function set_multisite( bool $multisite ): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
	}

	private function deployment_attempts( DashboardActivityWpdb $database ): DeploymentAttemptRepository {
		return new DeploymentAttemptRepository(
			$database,
			'wp_ran_booster_deployment_attempts',
			database_lifecycle: new ReadyDashboardDatabase()
		);
	}

	private function dashboard(
		SecretsFile $secrets,
		?TroubleshootingService $troubleshooting = null,
		?\RAN\PackageOperationService $package_operations = null,
		?DeploymentAttemptRepository $deployment_attempts = null,
		?PluginRepository $plugins = null,
		?ThemeRepository $themes = null,
		?TemporaryDebugCapture $debug_capture = null,
		?Database $database = null,
		?AdminAddOnRegistry $admin_add_ons = null,
		bool $provider_credentials = false,
		?ProviderRegistry $providers = null,
		?PublicRepositoryLookupProfileStore $public_lookup_profiles = null,
		?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null,
		?WebhookAssistanceReadinessEvaluator $webhook_assistance = null,
		?WordPressUpdaterLock $branch_check_lock = null
	): RoutingDashboard {
		$providers         = $providers ?? $this->providers( $provider_credentials );
		$branch_check_lock = $branch_check_lock ?? new DashboardBranchCheckUpdaterLock();
		$plugin_repository = $plugins ?? new class() extends PluginRepository {

			public function __construct() {
			}

			public function all_booster_plugins(): array {
				return array();
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
			public function all_deployment_plugins( ?\RAN\PackageSource $source = null ): array {
				return array();
			}
		};
		$theme_repository  = $themes ?? new class() extends ThemeRepository {

			public function __construct() {
			}

			public function all_booster_themes(): array {
				return array();
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_themes retains the production method contract; these inputs do not affect this controlled result.
			public function all_deployment_themes( ?\RAN\PackageSource $source = null ): array {
				return array();
			}
		};

		return new RoutingDashboard(
			$database ?? new Database(),
			$plugin_repository,
			new Booster(),
			$theme_repository,
			new ProviderSettingsPresenter( $providers, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ), $public_lookup_profiles, null, null, $plugin_repository, $theme_repository, $webhook_assistance, $branch_check_evidence, $branch_check_lock ),
			$troubleshooting ?? new TroubleshootingService( new LocalTroubleshootingService( $secrets ), $providers ),
			new AdminTabRegistry( $providers ),
			new ProviderDocumentationPresenter( $providers ),
			$package_operations,
			$deployment_attempts,
			$debug_capture,
			null,
			$admin_add_ons
		);
	}

	private function throwing_secrets(): SecretsFile {
		return new class() extends SecretsFile {

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of credential_profiles retains the production method contract; these inputs do not affect this controlled result.
			public function credential_profiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read credential profiles.' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_profiles retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_profiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read webhook profiles.' );
			}
		};
	}

	private function managed_package(
		string $identifier,
		string $name,
		string $provider_repository_id,
		\RAN\PackageSource $source = \RAN\PackageSource::BRANCH,
		string $provider = 'gh',
		\RAN\Deployment\DeploymentPolicy $policy = \RAN\Deployment\DeploymentPolicy::MANUAL,
		string $repository = 'owner/repository',
		string $branch = 'main',
		string $credential_id = '',
		bool $is_private = false,
		?string $subdirectory = null
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'get_identifier' )->willReturn( $identifier );
		$package->method( 'get_display_name' )->willReturn( $name );
		$package->method( 'get_slug' )->willReturn( 'example' );
		$package->method( 'get_provider_code' )->willReturn( $provider );
		$package->method( 'get_provider_repository_id' )->willReturn( $provider_repository_id );
		$package->method( 'get_repository' )->willReturn( new ManagedRepository( $provider, $repository, $provider_repository_id, $branch, $is_private, $credential_id ) );
		$package->method( 'get_branch' )->willReturn( $branch );
		$package->method( 'get_subdirectory' )->willReturn( $subdirectory );
		$package->method( 'get_source' )->willReturn( $source );
		$package->method( 'get_source_revision' )->willReturn( 1 );
		$package->method( 'get_deployment_policy' )->willReturn( $policy );
		$package->method( 'get_credential_id' )->willReturn( $credential_id );

		return $package;
	}

	private function providers( bool $with_credentials = false ): ProviderRegistry {
		return new ProviderRegistry(
			array(
				$this->provider( ProviderCode::parse( 'gh' ), 'GitHub', $with_credentials ),
				$this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket', $with_credentials ),
			)
		);
	}

	private function provider( ProviderCode $code, string $label, bool $with_credentials = false ): RepositoryProvider {
		return new class( $code, $label, $with_credentials ) implements RepositoryProvider, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink, \RAN\RepositoryProvider\WebhookNormalizer {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct(
				private ProviderCode $code,
				private string $label,
				private bool $with_credentials
			) {
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						$this->with_credentials ? array( new CredentialKindMetadata( 'api-key', 'API key', 'API key' ) ) : array(),
						$this->with_credentials ? array( new WebhookScopeMetadata( 'repository', 'Repository', true, 'Repository' ) ) : array(),
						navigation: new \RAN\RepositoryProvider\Admin\ProviderNavigationPlacement(
							\RAN\RepositoryProvider\Admin\ProviderNavigationPlacement::GIT_HOST,
							'gh' === $this->code->value ? 100 : 200
						)
					)
				);
			}

			public function get_credential_policy(): ProviderCredentialPolicy {
				return ShippedSecretPolicyCatalog::create()->credential_policy( $this->code );
			}

			public function get_webhook_policy(): \RAN\RepositoryProvider\ProviderWebhookPolicy {
				return ShippedSecretPolicyCatalog::create()->webhook_policy( $this->code );
			}

			public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
				throw new RuntimeException( 'Unused provider-route fixture method.' );
			}

			public function normalize_webhook( \RAN\RepositoryProvider\WebhookRequest $request ): \RAN\RepositoryProvider\WebhookEnvelope {
				unset( $request );
				return \RAN\RepositoryProvider\WebhookEnvelope::ignored();
			}

			public function repository_webhook_settings_url( string $locator ): string {
				return 'https://example.test/' . trim( $locator, '/' ) . '/settings/hooks';
			}
		};
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class FailingDashboardPluginRepository extends PluginRepository {
	public function all_booster_plugins(): array {
		throw PackageStorageFailure::invalid_provider_identity();
	}

	/** @return Package|null */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_plugin_from_file retains the production method contract; these inputs do not affect this controlled result.
	public function booster_plugin_from_file( $file ) {
		throw PackageStorageFailure::invalid_provider_identity();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class FailingDashboardThemeRepository extends ThemeRepository {
	public function all_booster_themes(): array {
		throw PackageStorageFailure::invalid_provider_identity();
	}

	/** @return Package|null */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_theme_from_stylesheet retains the production method contract; these inputs do not affect this controlled result.
	public function booster_theme_from_stylesheet( $stylesheet ) {
		throw PackageStorageFailure::invalid_provider_identity();
	}
}

/** Bounded coordinator double that preserves the real package-operation boundary. */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardNeedsAttentionCoordinator extends DeploymentCoordinator {
	public int $calls = 0;

	public function __construct( private DeploymentAttemptRepository $attempts ) {
	}

	public function execute_manual( PackageOperation $command ): array {
		++$this->calls;
		$request  = new DeploymentRequest(
			(string) $command->repository,
			$command->credential_id,
			$command->is_private,
			(string) $command->branch,
			(string) $command->package_slug,
			$command->subdirectory,
			$command->deployment_policy,
			7
		);
		$attempt  = $this->attempts->admit_and_claim_manual(
			$command->operation,
			$command->package_type,
			(string) $command->provider_code,
			(string) $command->provider_repository_id,
			$request,
			(string) $command->branch,
			'branch',
			1
		);
		$finished = $this->attempts->finish(
			$attempt->get_id(),
			DeploymentOutcome::from_code( DeploymentOutcome::CODE_PREFLIGHT_FAILED )
		);

		return array(
			'status'         => 'failed',
			'correlation_id' => $finished->get_correlation_id(),
			'outcome_code'   => (string) $finished->get_outcome()?->get_code(),
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
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
				static fn ( array $matches ): string => '%i' === $matches[0]
					? '`' . (string) $argument . '`'
					: ( '%d' === $matches[0] ? (string) (int) $argument : "'" . addslashes( (string) $argument ) . "'" ),
				$query,
				1
			);
		}

		return $query;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The wpdb fixture retains the query call signature while returning the controlled database result.
	public function query( string $query ): int {
		return 0;
	}

	/** @param array<string, mixed> $data */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The wpdb fixture retains the insert call signature while returning the controlled database result.
	public function insert( string $table, array $data ): false {
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
		if ( preg_match( '/WHERE id = (\\d+)/', $query, $matches ) === 1 ) {
			return array_values(
				array_map(
					static fn ( array $row ): object => (object) $row,
					array_filter( $this->rows, static fn ( array $row ): bool => (int) $row['id'] === (int) $matches[1] )
				)
			);
		}
		if ( str_contains( $query, 'package_type IN' ) ) {
			$rows = $this->rows;
			if ( preg_match( '/AND id < (\\d+)/', $query, $matches ) === 1 ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] < (int) $matches[1] ) );
			}
			usort( $rows, static fn ( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
			$limit = preg_match( '/LIMIT (\\d+)/', $query, $matches ) === 1 ? (int) $matches[1] : count( $rows );

			return array_map( static fn ( array $row ): object => (object) $row, array_slice( $rows, 0, $limit ) );
		}
		return array();
	}

	/** @return array<string, mixed> */
	public static function attempt( int $id, string $state ): array {
		$outcome_code = 'succeeded' === $state ? 'deployed' : 'preflight_failed';

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
			'outcome_code'            => $outcome_code,
			'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":1,"maximum_artifact_bytes":52428800}',
			'created_at'              => '2026-07-19 00:00:00',
			'finished_at'             => '2026-07-19 00:00:00',
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class ReadyDashboardDatabase extends Database {
	public function __construct() {
	}

	public function require_ready(): void {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardBranchCheckProvider implements RepositoryProvider, CredentialedPublicRepositoryBrowser, \RAN\RepositoryProvider\RepositoryPathInspector {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $prepare_calls       = 0;
	public int $resolved_ref_calls  = 0;
	public int $cleanup_calls       = 0;
	public int $path_calls          = 0;
	public ?ArchiveRequest $request = null;
	public ?string $path            = null;
	/** @var \Closure(): void|null */
	public ?\Closure $on_provider_access = null;

	public function __construct(
		public readonly bool $cleanup_fails = false,
		public readonly bool $path_exists = true,
		public readonly bool $path_check_fails = false
	) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://example.test/',
			'Owner'
		);
	}

	public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		unset( $request );
		throw new RuntimeException( 'Repository resolution is not used by the branch check.' );
	}

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		throw new RuntimeException( 'Repository browsing is not used by the branch check.' );
	}

	public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		if ( null !== $this->on_provider_access ) {
			( $this->on_provider_access )();
		}
		++$this->prepare_calls;
		$this->request = $request;

		return new class( $this ) implements PreparedArchive {
			public function __construct( private DashboardBranchCheckProvider $provider ) {
			}

			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				++$this->provider->resolved_ref_calls;

				return str_repeat( 'a', 40 );
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
				++$this->provider->cleanup_calls;
				if ( $this->provider->cleanup_fails ) {
					throw new RuntimeException( 'Cleanup fixture failure.' );
				}
			}
		};
	}

	public function repository_path_exists( \RAN\RepositoryProvider\RepositoryReference $repository, string $ref, string $path ): bool {
		unset( $repository, $ref );
		++$this->path_calls;
		$this->path = $path;
		if ( $this->path_check_fails ) {
			throw new RuntimeException( 'Path check fixture failure.' );
		}
		return $this->path_exists;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardBranchCheckProviderWithoutPathInspector implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $path_calls = 0;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://example.test/',
			'Owner'
		);
	}

	public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		unset( $request );
		throw new RuntimeException( 'Repository resolution is not used by the branch check.' );
	}

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		unset( $request );
		throw new RuntimeException( 'Repository browsing is not used by the branch check.' );
	}

	public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		return new class() implements PreparedArchive {
			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				return str_repeat( 'a', 40 );
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
			}
		};
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardBranchCheckUpdaterLock extends WordPressUpdaterLock {

	/** @var list<string> */
	public array $events               = array();
	private bool $held                 = false;
	private bool $provider_access_held = false;

	public function acquire(): string {
		$this->events[] = 'acquire';
		$this->held     = true;

		return 'branch-check-lock';
	}

	public function release( string $token ): bool {
		$this->events[] = 'release:' . $token;
		$this->held     = false;

		return 'branch-check-lock' === $token;
	}

	public function record_provider_access(): void {
		$this->provider_access_held = $this->held;
	}

	public function was_held_during( string $event ): bool {
		return 'provider_access' === $event && $this->provider_access_held;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardUncredentialedBranchCheckProvider implements RepositoryProvider {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public ?ArchiveRequest $request = null;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://example.test/',
			'Owner'
		);
	}

	public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		unset( $request );
		throw new RuntimeException( 'Repository resolution is not used by the branch check.' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$this->request = $request;

		return new class() implements PreparedArchive {
			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				return str_repeat( 'a', 40 );
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
			}
		};
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class DashboardBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var array<string, mixed> */
	private array $records = array();

	protected function read_option(): array {
		return $this->records;
	}

	protected function write_option( array $records ): bool {
		$this->records = $records;
		return true;
	}

	protected function acquire_mutation_lock(): bool {
		return true;
	}

	protected function release_mutation_lock(): bool {
		return true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.
final class ThrowingDashboardBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of record retains the production method contract; these inputs do not affect this controlled result.
	public function record( string $type, \RAN\Package $package, ?string $profile_id, string $outcome, ?string $profile_fingerprint = null ): void {
		throw new RuntimeException( 'evidence unavailable' );
	}
}

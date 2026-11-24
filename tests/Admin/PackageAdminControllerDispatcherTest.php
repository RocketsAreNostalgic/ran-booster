<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderFailureWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityConnection.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
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
use WP_Error;

final class PackageAdminControllerDispatcherTest extends TestCase {

	use \RAN\Tests\Support\CapturesBoosterLog;

	/** @return iterable<string,array{string,bool,string}> */
	public static function unavailable_save_branches(): iterable {
		foreach ( array( 'ref_not_found', 'redirect_blocked' ) as $reason ) {
			foreach ( array( false, true ) as $after_lookup ) {
				foreach ( array( 'plugin', 'theme' ) as $type ) {
					yield $reason . ( $after_lookup ? ' branch ' : ' repository ' ) . $type => array( $reason, $after_lookup, $type );
				}
			}
		}
	}

	#[DataProvider( 'unavailable_save_branches' )]
	public function test_missing_and_redirected_branches_refuse_save_without_fallback( string $reason, bool $after_lookup, string $type ): void {
		$package   = EditBoundaryPackage::make( 'plugin' === $type ? 'fixture/fixture.php' : 'fixture', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 503, new \RAN\RepositoryProvider\ProviderFailure( $reason, 503 ), $after_lookup ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$expected = 'ref_not_found' === $reason ? 'The selected branch could not be found. Check the branch name on the provider.' : 'The Git provider redirected the request somewhere that is not allowed.';
		$dashboard->expects( self::once() )->method( 'add_failure_message' )->with( self::callback( static fn ( mixed $message ): bool => $message instanceof WP_Error && $expected === $message->get_error_message() ) );
		$request               = $this->unchanged_edit_request();
		$request['stylesheet'] = 'fixture';
		$result                = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-' . $type, $request, true );
		self::assertFalse( $result );
	}

	public function test_a_transient_branch_failure_never_saves_a_changed_branch(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 0, new \RAN\RepositoryProvider\ProviderFailure( 'rate_limited', retry_after: 60 ), true ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', array_merge( $this->unchanged_edit_request(), array( 'branch' => 'renamed' ) ), true );
		self::assertFalse( $result );
	}

	public function test_a_release_managed_package_saves_without_branch_verification(): void {
		$package = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$package->set_source( \RAN\PackageSource::RELEASE_ASSET, 1 );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 404, new \RAN\RepositoryProvider\ProviderFailure( 'ref_not_found', 404 ), true ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->willReturn( 'https://example.test/redirect' );
		$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );
		self::assertSame( 'https://example.test/redirect', $result );
	}

	/** @return array<string, array{string, string, int, ?string}> */
	public static function disabled_unchanged_saves(): array {
		return array(
			'missing branch, plugin'          => array( 'plugin', 'branch', 404, 'ref_not_found' ),
			'rejected credential, plugin'     => array( 'plugin', 'branch', 401, null ),
			'missing repository, theme'       => array( 'theme', 'branch', 404, null ),
			'rejected credential, on release' => array( 'plugin', 'release_asset', 401, null ),
		);
	}

	#[DataProvider( 'disabled_unchanged_saves' )]
	public function test_choosing_disabled_saves_an_unchanged_source_from_the_stored_record_without_asking_the_provider( string $type, string $source, int $code, ?string $reason ): void {
		$package = EditBoundaryPackage::make( 'plugin' === $type ? 'fixture/fixture.php' : 'fixture', 'gh' );
		if ( 'release_asset' === $source ) {
			$package->set_source( \RAN\PackageSource::RELEASE_ASSET, 1 );
		}
		$provider  = new FailingLookupProvider( $code, null === $reason ? null : new \RAN\RepositoryProvider\ProviderFailure( $reason, $code ), 'ref_not_found' === $reason );
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )
			->method( 'post_package_operation' )
			->with(
				'edit-' . $type,
				self::callback(
					static fn ( array $resolved ): bool => 'updates_disabled' === $resolved['repository_check_skipped']
						&& 'disabled' === $resolved['deployment_policy']
						&& 'repository-id' === $resolved['provider_repository_id']
						&& 'main' === $resolved['branch']
				)
			)
			->willReturn( 'https://example.test/redirect' );
		$request = array_merge(
			$this->unchanged_edit_request(),
			array(
				'action'            => 'edit-' . $type,
				'stylesheet'        => 'fixture',
				'deployment_policy' => 'disabled',
			)
		);

		$this->start_log_capture();
		try {
			$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-' . $type, $request, true );
			$lines  = $this->captured_log_lines();
		} finally {
			$this->stop_log_capture();
		}

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertSame( 0, $provider->lookups, 'A deleted branch or rejected credential cannot block choosing Disabled.' );
		self::assertSame( array( '[ran-booster] package repository check skipped {"event":"package_repository_check_skipped","operation":"edit-' . $type . '","outcome_code":"updates_disabled","step":"package_repository_resolve"}' ), $lines );
	}

	/** @return array<string, array{array<string, string>}> */
	public static function saves_that_still_check(): array {
		return array(
			'Disabled, changed branch'          => array( array( 'branch' => 'renamed' ) ),
			'Disabled, changed repository'      => array( array( 'repository' => 'owner/other' ) ),
			'Disabled, changed credential'      => array( array( 'credential_id' => 'other-profile' ) ),
			'Disabled, changed subfolder'       => array( array( 'subdirectory' => 'packages/fixture' ) ),
			'Disabled, reinstall requested'     => array( array( 'reinstall_after_save' => '1' ) ),
			'Disabled, empty branch'            => array( array( 'branch' => '' ) ),
			'Disabled, empty branch, reinstall' => array(
				array(
					'branch'               => '',
					'reinstall_after_save' => '1',
				),
			),
			'Manual, unchanged'                 => array( array( 'deployment_policy' => 'manual' ) ),
		);
	}

	/** @param array<string, string> $change */
	#[DataProvider( 'saves_that_still_check' )]
	public function test_a_changed_source_or_another_policy_still_checks_the_provider( array $change ): void {
		// A private package, so a requested branch check goes straight to the provider.
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', true, 'deployment-profile' );
		$provider  = new FailingLookupProvider( 401 );
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$request = array_merge(
			$this->unchanged_edit_request(),
			array(
				'credential_id'     => 'deployment-profile',
				'deployment_policy' => 'disabled',
			),
			$change
		);

		$_POST['_ran_booster_reinstall_nonce'] = 'test-nonce'; // The reinstall form carries its own nonce field.
		try {
			$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', $request, true );
		} finally {
			unset( $_POST['_ran_booster_reinstall_nonce'] );
		}

		self::assertFalse( $result );
		self::assertSame( 1, $provider->lookups );
	}

	public function test_an_empty_branch_on_a_disabled_save_asks_the_provider_and_logs_no_skipped_check(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$provider  = new FailingLookupProvider( 401 );
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );
		$request = array_merge(
			$this->unchanged_edit_request(),
			array(
				'branch'            => '',
				'deployment_policy' => 'disabled',
			)
		);

		$this->start_log_capture();
		try {
			$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', $request, true );
			$lines  = $this->captured_log_lines();
		} finally {
			$this->stop_log_capture();
		}

		self::assertFalse( $result );
		self::assertSame( 1, $provider->lookups, 'An empty branch needs the provider to name the default branch.' );
		self::assertSame( array(), array_values( array_filter( $lines, static fn ( string $line ): bool => str_contains( $line, 'package_repository_check_skipped' ) ) ), 'The skipped-check line is written only when the provider was not asked.' );
	}

	public function test_a_rate_limited_save_with_an_empty_branch_saves_nothing_and_says_so(): void {
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
		) )->manage( $dashboard, 'edit-plugin', array_merge( $this->unchanged_edit_request(), array( 'branch' => '' ) ), true );

		self::assertFalse( $result );
	}

	public function test_manual_working_copy_copy_requires_one_exact_core_activity_record(): void {
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$db                                      = new \RAN\Tests\Activity\ActivityConnection();
		$correlation                             = str_repeat( 'a', 32 );
		$facts                                   = wp_json_encode(
			array(
				'branch_refusal'                 => 'target_is_working_copy',
				'deployment_attempt_id'          => 17,
				'deployment_source'              => 'manual',
				'deployment_state'               => 'failed',
				'deployment_outcome'             => 'policy_blocked',
				'deployment_mutation_started_at' => null,
			)
		);
		$row                                     = array(
			'correlation'  => $correlation,
			'provenance'   => 'core',
			'operation'    => 'update',
			'subject_type' => 'plugin',
			'status'       => 'failed',
			'reason'       => 'policy_blocked',
			'facts'        => $facts,
		);
		$presenter                               = new \RAN\Admin\DeploymentAdminPresenter( activity_store: new \RAN\Activity\ActivityStore( $db ) );
		$db->read_results                        = array( array( $row ) );
		$result                                  = $presenter->deployment_failure( 'policy_blocked', $correlation, 'update-plugin' );
		self::assertNotNull( $result );
		self::assertSame( 400, $GLOBALS['ran_booster_test_status_header'] );
		self::assertStringContainsString( 'version-controlled working copy', $result['message']['message'] );
		self::assertSame(
			array(
				'correlation_id' => $correlation,
				'operation'      => 'update-plugin',
				'step'           => 'manual_package_operation',
				'outcome_code'   => 'policy_blocked',
			),
			$result['context']
		);
		foreach ( array( array(), array( $row, $row ), array( array_replace( $row, array( 'provenance' => 'provider' ) ) ), array( array_replace( $row, array( 'operation' => 'install' ) ) ), array( array_replace( $row, array( 'status' => 'succeeded' ) ) ), array( array_replace( $row, array( 'correlation' => str_repeat( 'b', 32 ) ) ) ), array( array_replace( $row, array( 'facts' => '{}' ) ) ) ) as $rows ) {
			$db->read_results = array( $rows );
			$fallback         = $presenter->deployment_failure( 'policy_blocked', $correlation, 'update-plugin' );
			self::assertStringContainsString( 'A package update policy blocked', $fallback['message']['message'] ?? '' );
		}
	}

	public function test_a_paused_refusal_links_the_setting_says_no_files_were_changed_and_logs_only_its_code(): void {
		$captured = $this->paused_refusal();

		self::assertInstanceOf( \WP_Error::class, $captured[0] );
		self::assertSame( 'Updates are paused by a <a href="https://example.test/wp-admin/admin.php?page=ran-booster-settings#ran-booster-general-branch_sources">site setting</a>. No files were changed.', $captured[0]->get_error_message() );
		self::assertSame(
			array(
				'operation'    => 'update-plugin',
				'outcome_code' => 'source_paused',
				'step'         => 'manual_package_operation',
			),
			$captured[1]
		);
	}

	public function test_a_paused_refusal_stays_plain_for_a_user_who_cannot_open_the_setting(): void {
		$GLOBALS['ran_booster_test_capabilities'] = array( 'manage_options' => false );
		$captured                                 = $this->paused_refusal();

		self::assertInstanceOf( \WP_Error::class, $captured[0] );
		self::assertSame( 'Updates are paused by a site setting. No files were changed.', $captured[0]->get_error_message() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_paused_refusal_links_the_setting_on_the_network_admin_on_multisite(): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = true;
		$captured                                        = $this->paused_refusal();

		self::assertInstanceOf( \WP_Error::class, $captured[0] );
		self::assertStringContainsString( '<a href="https://example.test/wp-admin/network/admin.php?page=ran-booster-settings#ran-booster-general-branch_sources">site setting</a>', $captured[0]->get_error_message() );
	}

	/** @return array{0: \WP_Error|array<string, mixed>|null, 1: array<string, string>} */
	private function paused_refusal(): array {
		$captured = array( null, array() );
		$result   = ( new \ReflectionMethod( PackageAdminController::class, 'coded_refusal' ) )->invoke(
			new PackageAdminController(),
			'update-plugin',
			'source_paused',
			static function ( \WP_Error|array $message, array $context ) use ( &$captured ): void {
				$captured = array( $message, $context );
			}
		);
		self::assertFalse( $result );

		return $captured;
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_bulk_provider_notice_links_extensions_on_the_network_admin_on_multisite(): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = true;
		$_POST      = array();
		$controller = new PackageAdminController();
		$url        = $controller->bulk_redirect( 'plugin', BulkPackageResult::error( BulkPackageAction::QUEUE_UPDATE, 2, 'provider_unavailable' ), array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		$messages = array();
		$controller->add_bulk_notice(
			$this->createMock( Dashboard::class ),
			'plugin',
			static function ( array $message ) use ( &$messages ): void {
				$messages[] = $message;
			}
		);

		self::assertCount( 1, $messages );
		self::assertSame( 'A selected package uses an unavailable <a href="https://example.test/wp-admin/network/admin.php?page=ran-booster-extensions">Git provider</a>.', $messages[0]['message'] );
	}

	public function test_bulk_notices_stay_plain_for_a_user_who_cannot_open_the_targets(): void {
		$GLOBALS['ran_booster_test_capabilities'] = array( 'manage_options' => false );
		$_POST                                    = array();
		$controller                               = new PackageAdminController();
		$url                                      = $controller->bulk_redirect( 'plugin', BulkPackageResult::error( BulkPackageAction::QUEUE_UPDATE, 2, 'provider_unavailable' ), array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		$messages = array();
		$controller->add_bulk_notice(
			$this->createMock( Dashboard::class ),
			'plugin',
			static function ( array $message ) use ( &$messages ): void {
				$messages[] = $message;
			}
		);

		self::assertSame( 'A selected package uses an unavailable Git provider.', $messages[0]['message'] );
	}

	public function test_refusal_copy_with_a_link_reaches_the_notice_as_markup_and_keeps_the_log_context_to_codes(): void {
		$captured = array();
		$result   = ( new \ReflectionMethod( PackageAdminController::class, 'coded_refusal' ) )->invoke(
			new PackageAdminController(),
			'install-plugin',
			'provider_failed',
			static function ( \WP_Error|array $message, array $context ) use ( &$captured ): void {
				$captured = array( $message, $context );
			}
		);

		self::assertFalse( $result );
		self::assertInstanceOf( \WP_Error::class, $captured[0] );
		self::assertSame( 'ran_booster_refused_provider_failed', $captured[0]->get_error_code() );
		self::assertSame(
			array(
				'operation'    => 'install-plugin',
				'outcome_code' => 'provider_failed',
				'step'         => 'manual_package_operation',
			),
			$captured[1]
		);

		$messages = array(
			array(
				'type'    => 'error',
				'code'    => $captured[0]->get_error_code(),
				'message' => $captured[0]->get_error_message(),
			),
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/notices.php';
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'panel=logging">Troubleshooting logging</a>' ) );
		self::assertStringNotContainsString( '&lt;a', $html );
	}

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

	#[DataProvider( 'recovery_authorization_provider' )]
	public function test_unregistered_recovery_enforces_authorization_and_distinct_disk_state_redirects( string $type, string $boundary ): void {
		$identifier = 'plugin' === $type ? 'broken-plugin/broken-plugin.php' : 'broken-theme';
		$token      = 'gh:repo-42:11111111-1111-4111-8111-111111111111';
		$allowed    = 'allowed' === $boundary;
		foreach ( $allowed ? array( true, false ) : array( true ) as $folder_absent ) {
			$GLOBALS['ran_booster_test_capability_checks'] = array();
			$GLOBALS['ran_booster_test_nonce_checks']      = array();
			$plugins                                       = $this->createMock( PluginRepository::class );
			$themes                                        = $this->createMock( ThemeRepository::class );
			$repository                                    = 'plugin' === $type ? $plugins : $themes;
			$other = 'plugin' === $type ? $themes : $plugins;
			$other->expects( self::never() )->method( 'recover_unlink' );
			$repository->expects( $allowed ? self::once() : self::never() )->method( 'recover_unlink' )
				->with( $identifier, $token, 'row', self::isCallable() )
				->willReturnCallback(
					static function ( string $target, string $lifetime, string $digest, callable $guard ): \RAN\Storage\PackageMutationResult {
						unset( $target, $lifetime, $digest );
						$guard();
						return \RAN\Storage\PackageMutationResult::changed( \RAN\Storage\PackageStorageOperation::DELETE );
					}
				);
			$gateway = $this->createMock( \RAN\PackageRemoval\PackageRemovalGateway::class );
			$gateway->method( 'installed_targets' )->willReturn( array() );
			$gateway->expects( $allowed ? self::once() : self::never() )->method( 'deletion_postcondition' )
				->with( $type, $identifier, true )
				->willReturn( static fn (): bool => $folder_absent );
			$gateway->expects( $allowed ? self::once() : self::never() )->method( 'invalidate_native_target' )->with( $type, $identifier );
			$gateway->expects( self::never() )->method( 'delete_plugin' );
			$gateway->expects( self::never() )->method( 'delete_theme' );
			$lock = $this->createMock( WordPressUpdaterLock::class );
			$lock->expects( $allowed ? self::once() : self::never() )->method( 'try_acquire_without_reclaim' )->willReturn( 'fixture-lock' );
			$lock->expects( $allowed ? self::exactly( 3 ) : self::never() )->method( 'current_token' )->willReturn( 'fixture-lock' );
			$lock->expects( $allowed ? self::once() : self::never() )->method( 'release' )->with( 'fixture-lock' )->willReturn( true );
			$attempts   = new \RAN\Deployment\DeploymentAttemptRepository( new \RAN\Tests\Deployment\AttemptRepositoryDatabase(), 'wp_ran_booster_attempts', database_lifecycle: $this->createStub( \RAN\Storage\Database::class ) );
			$service    = new \RAN\PackageRemoval\PackageRemovalService( $plugins, $themes, $gateway, $attempts, $lock, github_selection: $this->selection() );
			$controller = new PackageAdminController( removals: $service );
			$dashboard  = $this->createMock( Dashboard::class );
			$dashboard->expects( in_array( $boundary, array( 'nonce', 'confirmation' ), true ) ? self::once() : self::never() )->method( 'add_failure_message' );
			$GLOBALS['ran_booster_test_capabilities'][ 'update_' . $type . 's' ] = 'capability' !== $boundary;
			$GLOBALS['ran_booster_test_nonce_valid']                             = 'nonce' !== $boundary;
			$request = array(
				'recovery_mode'           => 'unregistered',
				'identifier'              => $identifier,
				'adoption_token'          => $token,
				'digest'                  => 'row',
				'confirm_package_removal' => '1',
			);
			if ( 'confirmation' === $boundary ) {
				unset( $request['confirm_package_removal'] );
			}
			try {
				$result = $controller->manage( $dashboard, 'recover-' . $type, $request, true );
				if ( $allowed ) {
					self::assertIsString( $result );
					self::assertStringContainsString( 'page=ran-booster-' . $type . 's', $result );
					self::assertStringContainsString( 'ran_booster_result=' . ( $folder_absent ? 'unlink-absent' : 'unlink-on-disk' ), $result );
					self::assertStringNotContainsString( 'relink=', $result );
				} else {
					self::assertNotSame( 'capability', $boundary );
					self::assertFalse( $result );
				}
			} catch ( \RuntimeException ) {
				self::assertSame( 'capability', $boundary );
			}
			self::assertSame( array( 'update_' . $type . 's' ), $GLOBALS['ran_booster_test_capability_checks'] );
			self::assertSame(
				'capability' === $boundary ? array() : array( PackageAdminController::unregistered_recovery_action( $type, $identifier, $token, 'row' ) ),
				$GLOBALS['ran_booster_test_nonce_checks']
			);
			self::assertNotSame(
				PackageAdminController::recovery_action( $type, $identifier, $token, 'row', '' ),
				PackageAdminController::unregistered_recovery_action( $type, $identifier, $token, 'row' )
			);
		}
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

	#[DataProvider( 'package_access' )]
	public function test_a_save_looks_the_repository_up_with_the_package_access( bool $is_private ): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', $is_private, 'deployment-profile' );
		$provider  = new CapturingPublicLookupProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'            => 'edit-plugin',
			'file'              => 'fixture/fixture.php',
			'provider'          => 'gh',
			'repository'        => 'owner/replacement',
			'credential_id'     => 'deployment-profile',
			'branch'            => 'main',
			'deployment_policy' => 'manual',
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

		$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', $request, true );

		// The branch check after the save uses its own public access; the save itself never borrows it.
		self::assertSame( 'https://example.test/redirect', $result );
		self::assertCount( 1, $provider->requests );
		self::assertSame( 'deployment-profile', $provider->requests[0]->credential_id );
		self::assertFalse( $provider->requests[0]->public_only );
	}

	/** @return array<string, array{bool}> */
	public static function package_access(): array {
		return array(
			'public package'  => array( false ),
			'private package' => array( true ),
		);
	}

	public function test_invalid_subdirectory_names_the_field_before_provider_resolution(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$plugins   = new EditBoundaryPluginRepository( $package );
		$themes    = new EditBoundaryThemeRepository( $package );
		$provider  = new CapturingPublicLookupProvider();
		$providers = new ProviderRegistry( array( $provider ) );
		$request   = array(
			'action'            => 'edit-plugin',
			'file'              => 'fixture/fixture.php',
			'provider'          => 'gh',
			'repository'        => 'owner/replacement',
			'credential_id'     => '',
			'branch'            => 'main',
			'subdirectory'      => '../fixture',
			'deployment_policy' => 'manual',
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

	public function test_a_public_package_saves_on_a_provider_without_a_default_public_lookup(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', false, 'deployment-profile' );
		$providers = new ProviderRegistry( array( new CapturingPublicLookupProvider( false ) ) );
		$request   = array(
			'action'            => 'edit-plugin',
			'file'              => 'fixture/fixture.php',
			'provider'          => 'gh',
			'repository'        => 'owner/replacement',
			'credential_id'     => 'deployment-profile',
			'branch'            => 'main',
			'deployment_policy' => 'manual',
		);
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->willReturn( 'https://example.test/redirect' );

		$result = ( new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: new EditBoundaryPluginRepository( $package ), themes: new EditBoundaryThemeRepository( $package ), providers: $providers ) )->manage( $dashboard, 'edit-plugin', $request, true );

		self::assertSame( 'https://example.test/redirect', $result );
	}

	#[DataProvider( 'transient_lookup_failures' )]
	public function test_rate_limited_provider_does_not_block_saving_an_unchanged_repository( int $code ): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$provider  = new FailingLookupProvider( $code );
		$providers = new ProviderRegistry( array( $provider ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )
			->method( 'post_package_operation' )
			->with(
				'edit-plugin',
				self::callback(
					static fn ( array $resolved ): bool => '1' === $resolved['repository_check_skipped']
						&& 'resolved' === $resolved['provider_repository_identity_source']
						&& 'repository-id' === $resolved['provider_repository_id']
						&& 'owner/original' === $resolved['repository']
				)
			)
			->willReturn( 'https://example.test/redirect' );

		$this->start_log_capture();
		try {
			$result = ( new PackageAdminController(
				repositories: new PackageRepositoryRequestResolver( $providers ),
				plugins: new EditBoundaryPluginRepository( $package ),
				themes: new EditBoundaryThemeRepository( $package ),
				providers: $providers
			) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );
			$lines  = $this->captured_log_lines();
		} finally {
			$this->stop_log_capture();
		}

		self::assertSame( 'https://example.test/redirect', $result );
		self::assertSame( 1, $provider->lookups, 'The provider is still asked first; only a failure falls back.' );
		self::assertSame( array( '[ran-booster] package repository check skipped {"event":"package_repository_check_skipped","operation":"edit-plugin","outcome_code":"http_' . $code . '","step":"package_repository_resolve"}' ), $lines );
	}

	/** @return array<string, array{int}> */
	public static function transient_lookup_failures(): array {
		return array(
			'rate limited'        => array( 429 ),
			'bad gateway'         => array( 502 ),
			'service unavailable' => array( 503 ),
			'gateway timeout'     => array( 504 ),
		);
	}

	/** @param array<string, string> $flag */
	#[DataProvider( 'saves_that_continue_to_the_provider' )]
	public function test_a_save_that_goes_on_to_use_the_provider_does_not_fall_back( array $flag, bool $is_private ): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh', $is_private, $is_private ? 'deployment-profile' : null );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 429 ) ) );
		$request   = array_merge( $this->unchanged_edit_request(), $is_private ? array( 'credential_id' => 'deployment-profile' ) : array(), $flag );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'add_failure_message' );
		$dashboard->expects( self::never() )->method( 'post_package_operation' );

		$_POST['_ran_booster_reinstall_nonce'] = 'test-nonce'; // The reinstall form carries its own nonce field.
		$result                                = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $request, true );

		unset( $_POST['_ran_booster_reinstall_nonce'] );

		self::assertFalse( $result );
	}

	/** @return array<string, array{array<string, string>, bool}> */
	public static function saves_that_continue_to_the_provider(): array {
		return array(
			'reinstall, public package'  => array( array( 'reinstall_after_save' => '1' ), false ),
			'reinstall, private package' => array( array( 'reinstall_after_save' => '1' ), true ),
		);
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

	public function test_a_carried_provider_failure_is_worded_by_core_when_a_changed_repository_is_blocked(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 0, new \RAN\RepositoryProvider\ProviderFailure( 'repository_identity_changed' ) ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'The repository changed identity. Review the package source.' === $message->get_error_message()
				),
				self::anything(),
				self::callback( static fn ( array $context ): bool => 'repository_identity_changed' === ( $context['reason'] ?? null ) )
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

	public function test_a_carried_rate_limit_still_lets_an_unchanged_settings_save_proceed(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 0, new \RAN\RepositoryProvider\ProviderFailure( 'rate_limited', retry_after: 60 ) ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );
		$dashboard->expects( self::once() )->method( 'post_package_operation' )->willReturn( 'https://example.test/redirect' );

		$result = ( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );

		self::assertSame( 'https://example.test/redirect', $result );
	}

	public function test_a_carried_definitive_reason_blocks_even_with_a_transient_looking_status(): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( 0, new \RAN\RepositoryProvider\ProviderFailure( 'repository_not_found', 503 ) ) ) );
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

	#[DataProvider( 'definitive_lookup_failures' )]
	public function test_a_provider_failure_notice_never_carries_the_provider_text( int $code ): void {
		$package   = EditBoundaryPackage::make( 'fixture/fixture.php', 'gh' );
		$providers = new ProviderRegistry( array( new FailingLookupProvider( $code, text: 'sntl-cred-7c1d https://signed.example.test/a.zip?token=sntl-cred-7c1d' ) ) );
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'add_failure_message' )
			->with(
				self::callback( static fn ( \WP_Error $notice ): bool => ! str_contains( $notice->get_error_message() . print_r( $notice->get_error_data(), true ), 'sntl-cred' ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Flattens the notice data for the leak assertion.
				self::anything(),
				self::callback( static fn ( array $context ): bool => ! str_contains( implode( ' ', $context ), 'sntl-cred' ) )
			);

		( new PackageAdminController(
			repositories: new PackageRepositoryRequestResolver( $providers ),
			plugins: new EditBoundaryPluginRepository( $package ),
			themes: new EditBoundaryThemeRepository( $package ),
			providers: $providers
		) )->manage( $dashboard, 'edit-plugin', $this->unchanged_edit_request(), true );
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

	#[\PHPUnit\Framework\Attributes\TestWith( array( '1' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'updates_disabled' ) )]
	public function test_a_browser_supplied_skipped_check_marker_is_discarded( string $marker ): void {
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
		) )->manage( $dashboard, 'edit-plugin', array_merge( $this->unchanged_edit_request(), array( 'repository_check_skipped' => $marker ) ), true );
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
		return new \RAN\Tests\RepositoryProvider\Support\ExternalFixturePreparedArchive( 'https://example.test/unused.zip', str_repeat( 'a', 40 ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.
final class FailingLookupProvider implements RepositoryProvider {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public int $lookups = 0;

	public function __construct( private readonly int $code, private readonly ?\RAN\RepositoryProvider\ProviderFailure $failure = null, private readonly bool $after_lookup = false, private readonly string $text = 'Provider lookup failed.' ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		unset( $request );
		++$this->lookups;
		if ( $this->after_lookup ) {
			return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/original', 'fixture', 'repository-id', false, 'main', null );
		}

		throw null === $this->failure
			? new \RuntimeException( $this->text, $this->code )
			: new \RAN\RepositoryProvider\ProviderRequestFailed( $this->failure );
	}

	public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		unset( $request );
		throw null === $this->failure ? new \RuntimeException( $this->text, $this->code ) : new \RAN\RepositoryProvider\ProviderRequestFailed( $this->failure );
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

<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Focused temporary-file tests exercise the native archive and debug-capture boundaries.

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PortabilityController;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\ManagedRepository;
use RAN\PackageOperationService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintExportPackageFailure;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\Portability\UnsupportedBlueprintPackages;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use ReflectionClass;
use RuntimeException;
use Tests\Portability\TemporaryCredentialProvider;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PortabilityControllerTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$_FILES = array();
		$_POST  = array();
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
		unset( $GLOBALS['ran_booster_repository_admin_capabilities'] );
		unset( $GLOBALS['ran_booster_repository_admin_uploaded_files'] );
		unset( $GLOBALS['ran_booster_repository_admin_file_read'] );
		unset( $GLOBALS['ran_booster_repository_admin_temporary_files'] );
		unset( $_SERVER['HTTP_HX_REQUEST'] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_FILES = array();
		$_POST  = array();
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
		unset( $GLOBALS['ran_booster_repository_admin_capabilities'] );
		unset( $GLOBALS['ran_booster_repository_admin_uploaded_files'] );
		unset( $GLOBALS['ran_booster_repository_admin_file_read'] );
		unset( $GLOBALS['ran_booster_repository_admin_temporary_files'] );
		unset( $_SERVER['HTTP_HX_REQUEST'] );

		parent::tearDown();
	}

	public function test_preview_rejects_unauthorised_requests_before_reading_an_upload(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = $this->controller()->handle_preview();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_preview_rejects_missing_uploads_without_using_portability_services(): void {
		$result = $this->controller()->handle_preview();

		self::assertFalse( $result['success'] );
		self::assertSame( 400, $result['status'] );
		self::assertSame( 'Choose a valid Transporter Blueprint ZIP to review.', $result['data']['message'] );
	}

	public function test_apply_rejects_unauthorised_requests_before_reading_an_upload(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = $this->controller()->handle_apply();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_apply_rejects_missing_upload_and_row_without_using_portability_services(): void {
		$result = $this->controller()->handle_apply();

		self::assertFalse( $result['success'] );
		self::assertSame( 400, $result['status'] );
		self::assertSame( 'Choose the same Transporter Blueprint and package row to apply.', $result['data']['message'] );
	}

	public function test_preview_accepts_areal_uploaded_blueprint_archive(): void {
		$file = $this->blueprint_archive( new PackageBlueprint( array( $this->blueprint_package() ) ) );
		$this->set_uploaded_blueprint( $file );

		try {
			$result = $this->preview_controller( new PortabilityReadinessSpySecretsFile( true ) )->handle_preview();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertStringContainsString( 'data-portability-row="0"', $result['data']['html'] );
		self::assertStringContainsString( 'data-portability-action="install"', $result['data']['html'] );
	}

	public function test_preview_success_preserves_json_for_non_htmx_requests(): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'preview_success' );

		self::assertSame(
			array(
				'success' => true,
				'data'    => array( 'html' => '<div id="review">Safe review</div>' ),
			),
			$method->invoke( $this->controller(), '<div id="review">Safe review</div>' )
		);
	}

	public function test_preview_success_writes_html_for_htmx_requests(): void {
		$_SERVER['HTTP_HX_REQUEST'] = ' TRUE ';
		$method                     = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'preview_success' );

		ob_start();
		try {
			$method->invoke( $this->controller(), '<div id="review">Safe review</div>' );
			self::fail( 'The HTMX response must terminate through wp_die after writing the review HTML.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'ran_booster_test_wp_die', $failure->getMessage() );
		} finally {
			$output = (string) ob_get_clean();
		}

		self::assertSame( '<div id="review">Safe review</div>', $output );
		self::assertStringNotContainsString( '"success"', $output );
	}

	public function test_apply_recomputes_and_skips_astale_submitted_action(): void {
		$file                   = $this->blueprint_archive( new PackageBlueprint( array( $this->blueprint_package() ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'adopt';
		$this->set_uploaded_blueprint( $file );

		try {
			$result = $this->preview_controller( new PortabilityReadinessSpySecretsFile( true ) )->handle_apply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertStringContainsString( 'changed since review', $result['data']['message'] );
	}

	public function test_apply_row_bound_matches_the_blueprint_package_bound(): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'requested_row' );

		$_POST['row'] = '127';
		self::assertSame( 127, $method->invoke( $this->controller() ) );

		$_POST['row'] = '128';
		self::assertNull( $method->invoke( $this->controller() ) );
	}

	public function test_credential_decision_parser_accepts_only_the_closed_ordinal_shape(): void {
		$blueprint                     = $this->credential_blueprint();
		$_POST['credential_decisions'] = array(
			'0' => array(
				'action'    => 'target',
				'target_id' => 'saved-profile',
			),
		);
		$method                        = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'credential_decisions' );

		self::assertSame(
			array(
				0 => array(
					'action'    => BlueprintCredentialAction::TARGET,
					'target_id' => 'saved-profile',
				),
			),
			$method->invoke( $this->controller(), $blueprint )
		);
	}

	#[DataProvider( 'invalid_credential_decisions' )]
	public function test_credential_decision_parser_rejects_ambiguous_or_malformed_input( mixed $input ): void {
		$_POST['credential_decisions'] = $input;
		$method                        = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'credential_decisions' );

		$this->expectException( InvalidArgumentException::class );
		$method->invoke( $this->controller(), $this->credential_blueprint() );
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalid_credential_decisions(): iterable {
		yield 'scalar root' => array( 'import' );
		yield 'unknown ordinal' => array( array( 1 => array( 'action' => 'import' ) ) );
		yield 'non-canonical ordinal' => array( array( '00' => array( 'action' => 'import' ) ) );
		yield 'unknown action' => array( array( 0 => array( 'action' => 'automatic' ) ) );
		yield 'extra key' => array(
			array(
				0 => array(
					'action'   => 'import',
					'fallback' => 'target',
				),
			),
		);
		yield 'target missing id' => array( array( 0 => array( 'action' => 'target' ) ) );
		yield 'import with target id' => array(
			array(
				0 => array(
					'action'    => 'import',
					'target_id' => 'saved-profile',
				),
			),
		);
		yield 'constant target' => array(
			array(
				0 => array(
					'action'    => 'target',
					'target_id' => SecretsFile::CONSTANT_PROFILE,
				),
			),
		);
	}

	public function test_missing_credential_decision_leaves_apply_unchanged_without_storage_or_provider_work(): void {
		$secrets                = new PortabilityReadinessSpySecretsFile( false );
		$file                   = $this->blueprint_archive( $this->credential_blueprint(), 'correct-horse-battery-staple' );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'install';
		$_POST['password']      = 'correct-horse-battery-staple';
		$this->set_uploaded_blueprint( $file );

		try {
			$result = $this->preview_controller( $secrets )->handle_apply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertSame( 'none', $result['data']['credential_state'] );
		self::assertSame( 0, $secrets->readiness_checks );
	}

	public function test_export_parses_the_exact_bounded_credential_selection(): void {
		$expected             = array(
			'gh' => array( 'classic-profile', 'fine_grained-profile' ),
			'bb' => array( 'bitbucket-profile' ),
		);
		$_POST['credentials'] = $expected;

		self::assertSame( $expected, $this->selected_credentials() );
		unset( $_POST['credentials'] );
		self::assertSame( array(), $this->selected_credentials() );
	}

	public function test_export_reads_the_complete_bounded_archive_before_sending_download_headers(): void {
		$path   = tempnam( sys_get_temp_dir(), 'ran-booster-controller-archive-' );
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'archive_bytes' );
		self::assertIsString( $path );
		self::assertNotFalse( file_put_contents( $path, 'complete-archive-bytes' ) );

		try {
			self::assertSame( 'complete-archive-bytes', $method->invoke( $this->controller(), $path ) );
			$GLOBALS['ran_booster_repository_admin_file_read'] = static fn(): string => 'short';
			try {
				$method->invoke( $this->controller(), $path );
				self::fail( 'A short archive read must fail before download headers are sent.' );
			} catch ( \ReflectionException $failure ) {
				throw $failure;
			} catch ( \Throwable $failure ) {
				self::assertInstanceOf( InvalidArgumentException::class, $failure );
			}

			$GLOBALS['ran_booster_repository_admin_file_read'] = false;
			$this->expectException( InvalidArgumentException::class );
			$method->invoke( $this->controller(), $path );
		} finally {
			unset( $GLOBALS['ran_booster_repository_admin_file_read'] );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function test_failed_archive_read_returns_acontroller_error_and_removes_the_temporary_zip(): void {
		$_POST['packages']                                 = array( 'plugin' => array( 'example/example.php' ) );
		$_POST['response_format']                          = 'json';
		$GLOBALS['ran_booster_repository_admin_file_read'] = false;

		$result = $this->export_controller()->handle_export();

		self::assertSame( false, $result['success'] );
		self::assertSame( 500, $result['status'] );
		self::assertSame( 'Booster could not read the Transporter Blueprint ZIP. Please try again.', $result['data']['message'] );
		$paths = array_filter( $GLOBALS['ran_booster_repository_admin_temporary_files'] ?? array(), 'is_string' );
		self::assertNotSame( array(), $paths );
		foreach ( $paths as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	#[DataProvider( 'invalid_credential_selections' )]
	public function test_export_rejects_malformed_credential_selections( mixed $selection ): void {
		$_POST['credentials'] = $selection;
		$this->expectException( InvalidArgumentException::class );
		$this->selected_credentials();
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalid_credential_selections(): iterable {
		yield 'scalar root' => array( 'gh' );
		yield 'invalid provider' => array( array( 'GitHub' => array( 'valid-profile' ) ) );
		yield 'scalar provider selection' => array( array( 'gh' => 'valid-profile' ) );
		yield 'empty provider selection' => array( array( 'gh' => array() ) );
		yield 'associative provider selection' => array( array( 'gh' => array( 'profile' => 'valid-profile' ) ) );
		yield 'constant profile' => array( array( 'gh' => array( SecretsFile::CONSTANT_PROFILE ) ) );
		yield 'malformed profile' => array( array( 'gh' => array( 'invalid profile' ) ) );
		yield 'duplicate profile' => array( array( 'gh' => array( 'valid-profile', 'valid-profile' ) ) );
		yield 'over bound' => array( array( 'gh' => array_map( static fn ( int $index ): string => 'profile_' . $index, range( 0, PackageBlueprint::MAX_CREDENTIALS ) ) ) );
	}

	public function test_apply_failure_keeps_the_safe_storage_failure_message(): void {
		try {
			PackageMutationResult::conflict(
				operation: PackageStorageOperation::INSERT,
				diagnostic_id: 'ran_booster_storage_adoption_conflict',
				message: 'Booster found existing package management data. No package changes were made.'
			)->require_success();
			self::fail( 'A failed storage mutation must throw.' );
		} catch ( PackageStorageFailure $failure ) {
			$result = $this->apply_failure( $failure );
		}

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Booster found existing package management data. No package changes were made.', $result['message'] );
	}

	public function test_apply_failure_keeps_the_actionable_database_requirement(): void {
		$result = $this->apply_failure( PackageStorageFailure::unsupported_database() );

		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( 'database requirements', $result['message'] );
	}

	public function test_apply_failure_does_not_expose_unexpected_exception_text(): void {
		$result = $this->apply_failure( new RuntimeException( 'Sensitive provider failure detail.' ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Booster could not apply this package. Review the Transporter Blueprint again and check repository access.', $result['message'] );
	}

	public function test_portability_apply_canary_never_enters_the_json_result_or_debug_capture(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-portability-log-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture(
			$directory . '/secrets.json',
			static fn(): int => strtotime( '2026-08-08T12:00:00Z' )
		);
		$capture->start();
		BoosterLogger::configure_capture( $capture );

		try {
			$result = $this->apply_failure( new RuntimeException( 'portability-apply-secret-canary', 73 ) );
			$json   = (string) wp_json_encode( $result );
			$line   = $capture->snapshot()['entries'][0]['line'];

			self::assertStringNotContainsString( 'portability-apply-secret-canary', $json );
			self::assertStringNotContainsString( 'portability-apply-secret-canary', $line );
			self::assertStringContainsString( '"operation":"portability_apply"', $line );
			self::assertStringContainsString( '"exception_code":"73"', $line );
		} finally {
			BoosterLogger::configure_capture( null );
			foreach ( array( $directory . '/ran-booster-debug.php', $directory . '/ran-booster-debug.php.lock' ) as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}

	public function test_local_store_apply_failure_has_its_own_category_and_message(): void {
		$result = $this->apply_failure( LocalSecretStoreUnavailable::for_portability() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'local_secret_store_unavailable', $result['category'] );
		self::assertStringContainsString( 'encrypted credential storage is unavailable', $result['message'] );
		self::assertStringNotContainsString( 'repository access', $result['message'] );
	}

	public function test_package_only_preview_does_not_preflight_encrypted_storage(): void {
		$secrets   = new PortabilityReadinessSpySecretsFile( false );
		$blueprint = new PackageBlueprint( array( $this->blueprint_package() ) );
		$file      = $this->blueprint_archive( $blueprint );

		try {
			$html = $this->preview_controller( $secrets )->preview_file( $file );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertSame( 0, $secrets->readiness_checks );
		self::assertStringContainsString( 'Ready to migrate; all checks passed.', $html );
	}

	public function test_credential_preview_reports_unavailable_target_storage_without_persistence(): void {
		$secrets   = new PortabilityReadinessSpySecretsFile( false );
		$package   = $this->blueprint_package();
		$blueprint = new PackageBlueprint(
			array( $package ),
			array(
				new BlueprintCredential(
					'gh',
					'Imported credential',
					'classic',
					array(),
					'sentinel-portability-token',
					array(
						array(
							'type'       => 'plugin',
							'identifier' => $package->identifier,
						),
					)
				),
			)
		);
		$file      = $this->blueprint_archive( $blueprint, 'correct-horse-battery-staple' );

		try {
			$html = $this->preview_controller( $secrets )->preview_file( $file, 'correct-horse-battery-staple' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertSame( 0, $secrets->readiness_checks );
		self::assertStringContainsString( 'Repository credentials', $html );
		self::assertStringContainsString( 'name="credential_decisions[0][action]" value="import"', $html );
		self::assertStringNotContainsString( 'value="import" data-portability-credential-action aria-describedby="ran-booster-portability-credential-description-0" checked', $html );
		self::assertStringNotContainsString( 'sentinel-portability-token', $html );
	}

	public function test_credential_projection_offers_recovery_for_associated_managed_packages(): void {
		$managed   = new BlueprintPackage( 'plugin', 'managed/managed.php', 'Managed <Plugin>', 'gh', 'managed-source-id-canary', 'owner/managed', 'main', null );
		$protected = new BlueprintPackage( 'theme', 'protected-theme', 'Protected Theme', 'gh', 'protected-source-id-canary', 'owner/protected', 'main', null );
		$blueprint = new PackageBlueprint(
			array( $managed, $protected ),
			array(
				new BlueprintCredential(
					'gh',
					'Imported credential',
					'classic',
					array( 'configuration' => 'configuration-canary' ),
					'secret-canary',
					array(
						array(
							'type'       => $managed->type,
							'identifier' => $managed->identifier,
						),
						array(
							'type'       => $protected->type,
							'identifier' => $protected->identifier,
						),
					)
				),
			)
		);
		$items     = array(
			new BlueprintPlanItem( $managed, TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED ),
			new BlueprintPlanItem( $protected, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT ),
		);
		$method    = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'credential_rows' );

		$rows = $method->invoke( $this->preview_controller( new PortabilityReadinessSpySecretsFile( true ) ), $blueprint, array(), $items );

		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]['decision_required'] );
		self::assertSame( 0, $rows[0]['proposed_count'] );
		self::assertSame( 1, $rows[0]['recovery_count'] );
		self::assertSame( 2, $rows[0]['unchanged_count'] );
		self::assertSame(
			array(
				array(
					'row'  => 0,
					'name' => 'Managed <Plugin>',
					'type' => 'Plugin',
				),
				array(
					'row'  => 1,
					'name' => 'Protected Theme',
					'type' => 'Theme',
				),
			),
			$rows[0]['packages']
		);
		self::assertArrayNotHasKey( 'secret', $rows[0] );
		self::assertArrayNotHasKey( 'configuration', $rows[0] );
		$projection = (string) wp_json_encode( $rows );
		self::assertStringNotContainsString( 'secret-canary', $projection );
		self::assertStringNotContainsString( 'configuration-canary', $projection );
		self::assertStringNotContainsString( 'managed-source-id-canary', $projection );
		self::assertStringNotContainsString( 'protected-source-id-canary', $projection );
	}

	public function test_apply_recognises_amanaged_package_without_mutation(): void {
		$package   = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$blueprint = new PackageBlueprint( array( $package ) );
		$item      = new BlueprintPlanItem( $package, TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'apply_item' );

		$result = $method->invoke( $this->application(), $blueprint, $item, null, null, null, null, false, false );

		self::assertSame(
			array(
				'status'           => 'unchanged',
				'message'          => 'This package is already managed.',
				'credential_state' => 'none',
			),
			$result
		);
	}

	#[DataProvider( 'non_actionable_credential_rows' )]
	public function test_non_actionable_credential_row_cannot_import_astandalone_credential(
		TargetPackageAction $action,
		TargetPackageReason $reason
	): void {
		$secrets     = new PortabilityReadinessSpySecretsFile( false );
		$package     = $this->blueprint_package();
		$credential  = $this->credential_blueprint()->credentials[0];
		$blueprint   = new PackageBlueprint( array( $package ), array( $credential ) );
		$item        = new BlueprintPlanItem( $package, $action, $reason );
		$application = new PortabilityApplicationService(
			( new ReflectionClass( BlueprintReviewer::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( BlueprintRepositoryVerifier::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);
		$method      = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'apply_item' );

		$result = $method->invoke( $application, $blueprint, $item, $credential, 'import', null, null, true, true );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( 'none', $result['credential_state'] );
		self::assertSame( 0, $secrets->readiness_checks );
	}

	/** @return iterable<string, array{TargetPackageAction,TargetPackageReason}> */
	public static function non_actionable_credential_rows(): iterable {
		yield 'forged blocked row' => array( TargetPackageAction::BLOCKED, TargetPackageReason::DESTINATION_CONFLICT );
		yield 'stale protected row' => array( TargetPackageAction::PROTECTED, TargetPackageReason::STALE_MANAGEMENT );
	}

	public function test_adopt_requires_explicit_approval_before_mutation(): void {
		$file                   = $this->blueprint_archive( new PackageBlueprint( array( $this->blueprint_package() ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'adopt';
		$this->set_uploaded_blueprint( $file );

		try {
			$result = $this->preview_controller( new PortabilityReadinessSpySecretsFile( true ), true )->handle_apply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertStringContainsString( 'not selected for adoption', $result['data']['message'] );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_package_type_capability_is_required_before_mutation( string $type, string $identifier ): void {
		$file                   = $this->blueprint_archive( new PackageBlueprint( array( $this->blueprint_package( $type, $identifier ) ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'install';
		$GLOBALS['ran_booster_repository_admin_capabilities'] = array(
			'manage_options'  => true,
			'install_plugins' => 'theme' === $type,
			'install_themes'  => 'plugin' === $type,
		);
		$this->set_uploaded_blueprint( $file );

		try {
			$result = $this->preview_controller( new PortabilityReadinessSpySecretsFile( true ) )->handle_apply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'failed', $result['data']['status'] );
		self::assertStringContainsString( 'permission to apply this package type', $result['data']['message'] );
	}

	/** @return iterable<string, array{string, string}> */
	public static function package_type_provider(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function test_public_repository_input_keeps_its_credential_association(): void {
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$item    = new BlueprintPlanItem( $package, TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		$method  = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'operation_input' );

		$input = $method->invoke( $this->application(), $item, 'imported-pat', false );

		self::assertSame( 'imported-pat', $input['credential_id'] );
		self::assertSame( '0', $input['private'] );
		self::assertSame( DeploymentPolicy::DISABLED->value, $input['deployment_policy'] );
		self::assertSame( '1', $method->invoke( $this->application(), $item, 'imported-pat', true )['private'] );
	}

	public function test_blueprint_success_requires_an_exact_disabled_read_back(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assert_disabled_result' );

		$method->invoke( $this->application(), array( 'package' => $this->managed_plugin( DeploymentPolicy::DISABLED ) ), $blueprint, null, false );
		$method->invoke(
			$this->application(),
			array( 'package' => $this->managed_plugin( DeploymentPolicy::DISABLED, private: true, credential_id: 'target-profile' ) ),
			$blueprint,
			'target-profile',
			true
		);
		$this->addToAssertionCount( 1 );
	}

	public function test_exact_target_predicate_supports_only_verified_managed_retry(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'target_verified' );

		self::assertTrue( $method->invoke( $this->application(), $this->managed_plugin( DeploymentPolicy::DISABLED ), $blueprint, null, false ) );
		self::assertFalse( $method->invoke( $this->application(), $this->managed_plugin( DeploymentPolicy::MANUAL ), $blueprint, null, false ) );
		self::assertFalse( $method->invoke( $this->application(), $this->managed_plugin( DeploymentPolicy::DISABLED, source: PackageSource::RELEASE_ASSET ), $blueprint, null, false ) );
	}

	public function test_blueprint_success_rejects_amanual_read_back(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assert_disabled_result' );

		$this->expectException( RuntimeException::class );
		$method->invoke( $this->application(), array( 'package' => $this->managed_plugin( DeploymentPolicy::MANUAL ) ), $blueprint, null, false );
	}

	public function test_blueprint_success_rejects_any_mismatched_management_field(): void {
		$blueprint  = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method     = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assert_disabled_result' );
		$mismatches = array(
			'provider'     => $this->managed_plugin( DeploymentPolicy::DISABLED, private: true, credential_id: 'target-profile', provider: 'gitlab' ),
			'type'         => $this->managed_theme_with_plugin_identifier(),
			'source'       => $this->managed_plugin( DeploymentPolicy::DISABLED, private: true, credential_id: 'target-profile', source: PackageSource::RELEASE_ASSET ),
			'locator'      => $this->managed_plugin( DeploymentPolicy::DISABLED, locator: 'owner/other', private: true, credential_id: 'target-profile' ),
			'stable id'    => $this->managed_plugin( DeploymentPolicy::DISABLED, provider_repository_id: 'other-id', private: true, credential_id: 'target-profile' ),
			'branch'       => $this->managed_plugin( DeploymentPolicy::DISABLED, branch: 'develop', private: true, credential_id: 'target-profile' ),
			'credential'   => $this->managed_plugin( DeploymentPolicy::DISABLED, private: true, credential_id: 'other-profile' ),
			'privacy'      => $this->managed_plugin( DeploymentPolicy::DISABLED, credential_id: 'target-profile' ),
			'subdirectory' => $this->managed_plugin( DeploymentPolicy::DISABLED, private: true, credential_id: 'target-profile', subdirectory: 'plugin' ),
		);

		foreach ( $mismatches as $label => $package ) {
			try {
				$method->invoke( $this->application(), array( 'package' => $package ), $blueprint, 'target-profile', true );
				self::fail( $label . ' mismatch must fail exact target verification.' );
			} catch ( \ReflectionException $failure ) {
				throw $failure;
			} catch ( \Throwable $failure ) {
				self::assertInstanceOf( RuntimeException::class, $failure, $label );
			}
		}
	}

	#[DataProvider( 'export_password_error_provider' )]
	public function test_protected_export_password_validation_matches_the_client_contract(
		bool $include_credentials,
		?string $password,
		?string $confirmation,
		?string $expected
	): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'export_password_error' );

		self::assertSame( $expected, $method->invoke( $this->controller(), $include_credentials, $password, $confirmation ) );
	}

	public function test_release_managed_export_failure_names_every_affected_package_and_its_limitation(): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'export_validation_failure_message' );

		self::assertSame(
			'Transporter Blueprint export cannot include: Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster; Theme “Example Theme” manages its own updates and cannot also be managed by Booster. Deselect those packages and try again.',
			$method->invoke(
				$this->controller(),
				new UnsupportedBlueprintPackages(
					array(
						new BlueprintExportPackageFailure( 'plugin', 'RAN GitHub Updater Dummy', BlueprintExportPackageFailure::PUBLISHED_RELEASES ),
						new BlueprintExportPackageFailure( 'theme', 'Example Theme', BlueprintExportPackageFailure::PUBLISHED_RELEASES ),
					)
				)
			)
		);
	}

	public function test_inline_export_failure_returns_the_specific_message_as_json(): void {
		$_POST['response_format'] = 'json';
		$method                   = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'export_failure' );

		self::assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster.' ),
				'status'  => 400,
			),
			$method->invoke( $this->controller(), 'Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster.', 400 )
		);
	}

	/** @return iterable<string, array{bool, ?string, ?string, ?string}> */
	public static function export_password_error_provider(): iterable {
		yield 'unprotected' => array( false, null, null, null );
		yield 'missing' => array( true, null, null, 'Choose a Transporter Blueprint password before exporting credentials.' );
		yield 'mismatch' => array( true, 'correct-horse-battery-staple', 'different-password-value', 'The Transporter Blueprint passwords do not match. Nothing was exported.' );
		yield 'matching' => array( true, 'correct-horse-battery-staple', 'correct-horse-battery-staple', null );
	}

	public function test_install_success_explains_the_disabled_deployment_gate(): void {
		$result = $this->deployment_result(
			array( 'status' => 'succeeded' )
		);

		self::assertSame( 'installed', $result['status'] );
		self::assertStringContainsString( 'deployment disabled', $result['message'] );
		self::assertStringContainsString( 'Re-enable deployment deliberately', $result['message'] );
	}

	public function test_install_failure_uses_the_specific_deployment_outcome_and_reference(): void {
		$result = $this->deployment_result(
			array(
				'status'         => 'failed',
				'outcome_code'   => DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE,
				'correlation_id' => str_repeat( 'a', 32 ),
			)
		);

		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( 'configured archive download limit', $result['message'] );
		self::assertStringContainsString( 'Reference: ' . str_repeat( 'a', 32 ), $result['message'] );
		self::assertStringNotContainsString( 'check repository access', $result['message'] );
	}

	public function test_install_failure_keeps_the_safe_outcome_independent_of_package_type(): void {
		$result = $this->deployment_result(
			array(
				'status'       => 'failed',
				'outcome_code' => DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE,
			)
		);

		self::assertStringContainsString( 'configured archive download limit', $result['message'] );
	}

	public function test_install_failure_rejects_unsafe_outcome_evidence_and_malformed_references(): void {
		$result = $this->deployment_result(
			array(
				'status'         => 'failed',
				'outcome_code'   => 'Authorization: Bearer secret-canary',
				'correlation_id' => 'secret-canary',
			)
		);

		self::assertSame( 'Booster recorded an unavailable deployment outcome. Open Troubleshooting, verify the package state before retrying, and submit a redacted report if it repeats.', $result['message'] );
		self::assertStringNotContainsString( 'secret-canary', $result['message'] );
	}

	public function test_it_reads_only_bounded_plugin_and_theme_selections(): void {
		$_POST['packages'] = array(
			'plugin' => array( 'example/example.php' ),
			'theme'  => array( 'example-theme' ),
		);

		self::assertSame(
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
				array(
					'type'       => 'theme',
					'identifier' => 'example-theme',
				),
			),
			$this->selected_packages()
		);
	}

	/** @param mixed $input */
	#[DataProvider( 'invalid_package_selections' )]
	public function test_it_rejects_malformed_package_selections( mixed $input ): void {
		$_POST['packages'] = $input;

		$this->expectException( InvalidArgumentException::class );
		$this->selected_packages();
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalid_package_selections(): iterable {
		yield 'not an array' => array( 'example/example.php' );
		yield 'empty' => array( array() );
		yield 'unknown group' => array( array( 'vendor' => array( 'example/example.php' ) ) );
		yield 'non-list group' => array( array( 'plugin' => array( 'chosen' => 'example/example.php' ) ) );
		yield 'non-string identity' => array( array( 'plugin' => array( 7 ) ) );
		yield 'duplicate identity' => array( array( 'plugin' => array( 'example/example.php', 'example/example.php' ) ) );
		yield 'too many' => array(
			array(
				'plugin' => array_map(
					static fn( int $index ): string => 'example-' . $index . '/example.php',
					range( 0, PackageBlueprint::MAX_PACKAGES )
				),
			),
		);
	}

	private function controller(): PortabilityController {
		return ( new ReflectionClass( PortabilityController::class ) )->newInstanceWithoutConstructor();
	}

	private function export_controller(): PortabilityController {
		$package = $this->createStub( \RAN\Package::class );
		$package->method( 'get_identifier' )->willReturn( 'example/example.php' );
		$package->method( 'get_display_name' )->willReturn( 'Example' );
		$package->method( 'get_slug' )->willReturn( 'example' );
		$package->method( 'get_provider_code' )->willReturn( 'gh' );
		$package->method( 'get_provider_repository_id' )->willReturn( 'repository-id' );
		$package->method( 'get_repository' )->willReturn( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', false, '' ) );
		$package->method( 'get_branch' )->willReturn( 'main' );
		$package->method( 'is_private' )->willReturn( false );
		$package->method( 'get_subdirectory' )->willReturn( null );
		$package->method( 'get_credential_id' )->willReturn( '' );
		$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
		$package->method( 'get_source_revision' )->willReturn( 1 );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $package ) );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );

		return new PortabilityController(
			new ManagedPackageBlueprintExporter( $plugins, $themes, new SecretsFile( null, array() ) ),
			new BlueprintArchive(),
			( new ReflectionClass( PortabilityApplicationService::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function preview_controller( SecretsFile $secrets, bool $installed = false, string $type = 'plugin' ): PortabilityController {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'is_installed' )->willReturn( $installed && 'plugin' === $type );
		$plugins->method( 'has_management_record' )->willReturn( false );
		$themes->method( 'is_installed' )->willReturn( $installed && 'theme' === $type );
		$themes->method( 'has_management_record' )->willReturn( false );
		$catalog  = new ProviderSecretPolicyCatalog();
		$provider = new TemporaryCredentialProvider( $secrets->credentials_for( 'gh' ), 0, 'repository-id' );
		$registry = new ProviderRegistry( array( $provider ), $catalog );

		$application = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets ),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);

		return new PortabilityController(
			( new ReflectionClass( ManagedPackageBlueprintExporter::class ) )->newInstanceWithoutConstructor(),
			new BlueprintArchive(),
			$application,
			( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function blueprint_archive( PackageBlueprint $blueprint, ?string $password = null ): string {
		$file = tempnam( sys_get_temp_dir(), 'ran-booster-portability-controller-' );
		self::assertIsString( $file );
		( new BlueprintArchive() )->write_to( $file, $blueprint, $password );

		return $file;
	}

	private function blueprint_package( string $type = 'plugin', ?string $identifier = null ): BlueprintPackage {
		return new BlueprintPackage(
			$type,
			$identifier ?? ( 'plugin' === $type ? 'example/example.php' : 'example-theme' ),
			'Example',
			'gh',
			'repository-id',
			'owner/repository',
			'main',
			null
		);
	}

	private function credential_blueprint(): PackageBlueprint {
		$package = $this->blueprint_package();

		return new PackageBlueprint(
			array( $package ),
			array(
				new BlueprintCredential(
					'gh',
					'Imported credential',
					'classic',
					array(),
					'sentinel-portability-token',
					array(
						array(
							'type'       => $package->type,
							'identifier' => $package->identifier,
						),
					)
				),
			)
		);
	}

	private function managed_plugin(
		DeploymentPolicy $policy,
		string $locator = 'owner/example',
		string $provider_repository_id = 'repository-id',
		string $branch = 'main',
		bool $private = false,
		?string $credential_id = null,
		?string $subdirectory = null,
		string $provider = 'gh',
		PackageSource $source = PackageSource::BRANCH
	): Plugin {
		$plugin = Plugin::from_wp_array(
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
		$plugin->set_repository( new ManagedRepository( $provider, $locator, $provider_repository_id, $branch, $private, $credential_id ) );
		$plugin->set_deployment_policy( $policy );
		$plugin->set_subdirectory( $subdirectory );
		$plugin->set_source( $source, 1 );

		return $plugin;
	}

	private function managed_theme_with_plugin_identifier(): Theme {
		$theme = new class() extends Theme {
			public function __construct() {
				$this->stylesheet = 'example/example.php';
				$this->name       = 'Example';
			}
		};
		$theme->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main', true, 'target-profile' ) );
		$theme->set_deployment_policy( DeploymentPolicy::DISABLED );

		return $theme;
	}

	/** @return list<array{type:string,identifier:string}> */
	private function selected_packages(): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'selected_packages' );

		return $method->invoke( $this->controller() );
	}

	/** @return array<string, list<string>> */
	private function selected_credentials(): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'selected_credentials' );

		return $method->invoke( $this->controller() );
	}

	/** @return array{status:string,message:string,category?:string} */
	private function apply_failure( \Throwable $failure ): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'apply_failure' );

		return $method->invoke( $this->controller(), $failure );
	}

	/** @param array<string, mixed> $result @return array{status:string,message:string} */
	private function deployment_result( array $result ): array {
		$method = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'deployment_result' );

		return $method->invoke( $this->application(), $result );
	}

	private function application(): PortabilityApplicationService {
		return ( new ReflectionClass( PortabilityApplicationService::class ) )->newInstanceWithoutConstructor();
	}

	private function set_uploaded_blueprint( string $file ): void {
		$GLOBALS['ran_booster_repository_admin_uploaded_files'] = array( $file );
		$_FILES['blueprint']                                    = array(
			'error'    => UPLOAD_ERR_OK,
			'tmp_name' => $file,
			'name'     => 'ran-booster-blueprint.zip',
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused readiness spy belongs with controller behavior tests.
final class PortabilityReadinessSpySecretsFile extends SecretsFile {
	public int $readiness_checks = 0;

	public function __construct( private readonly bool $ready ) {
		parent::__construct( null, array(), new ProviderSecretPolicyCatalog() );
	}

	public function assert_managed_storage_ready(): void {
		++$this->readiness_checks;
		if ( ! $this->ready ) {
			throw new RuntimeException( 'Test target storage is unavailable.' );
		}
	}
}

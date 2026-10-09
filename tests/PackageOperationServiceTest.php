<?php

declare(strict_types=1);

namespace RAN\Tests;

require_once __DIR__ . '/Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/Support/WPError.php';
require_once __DIR__ . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PackageAdminController;
use RAN\Booster;
use RAN\Dashboard;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\Troubleshooting\TroubleshootingService;
use RAN\WordPress\WordPressUpdaterLock;

final class PackageOperationServiceTest extends TestCase {

	/** @return list<array{string, bool}> */
	public static function operation_matrix(): array {
		return array(
			array( 'install-plugin', true ),
			array( 'install-theme', true ),
			array( 'edit-plugin', false ),
			array( 'edit-theme', false ),
			array( 'update-plugin', true ),
			array( 'update-theme', true ),
			array( 'unlink-plugin', false ),
			array( 'unlink-theme', false ),
			array( 'unlink-delete-plugin', false ),
			array( 'unlink-delete-theme', false ),
		);
	}

	#[DataProvider( 'operation_matrix' )]
	public function test_the_explicit_operation_matrix_classifies_deployments( string $action, bool $deployment ): void {
		$operation = PackageOperation::from_input( action: $action, input: $this->input( $action ) );

		self::assertSame( $deployment, $operation->is_deployment() );
		self::assertSame( str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme', $operation->package_type );
	}

	public function test_reinstall_after_save_deploys_the_authoritative_edited_package_and_returns_to_settings(): void {
		$package = $this->plugin();
		$package->set_deployment_policy( DeploymentPolicy::DISABLED );
		$coordinator = new OperationCoordinator();
		$dashboard   = $this->dashboard( $coordinator, $package );

		$redirect = $dashboard->post_package_operation(
			'edit-plugin',
			$this->input(
				'edit-plugin',
				array(
					'deployment_policy'          => DeploymentPolicy::MANUAL->value,
					'expected_deployment_policy' => DeploymentPolicy::DISABLED->value,
					'reinstall_after_save'       => '1',
				)
			)
		);

		self::assertIsString( $redirect );
		self::assertSame( 1, $coordinator->calls );
		self::assertInstanceOf( PackageOperation::class, $coordinator->last_command );
		self::assertSame( 'update', $coordinator->last_command->operation );
		self::assertTrue( $coordinator->last_command->has_expected_package() );
		self::assertSame( DeploymentPolicy::MANUAL, $coordinator->last_command->expected_package['deployment_policy'] );
		self::assertSame( 'manual', $package->get_deployment_policy()->value );
		$query = $this->redirect_query( $redirect );
		self::assertSame( 'update', $query['ran_booster_result'] );
		self::assertSame( 'example/example.php', $query['package'] );
	}

	public function test_save_and_check_redirect_uses_the_authoritative_edited_package_without_asuccess_notice(): void {
		$package   = $this->plugin();
		$dashboard = $this->dashboard( new OperationCoordinator(), $package );

		$redirect = $dashboard->post_package_operation(
			'edit-plugin',
			$this->input(
				'edit-plugin',
				array(
					'branch'                             => 'feature/verified-after-save',
					'subdirectory'                       => 'packages/example',
					'check_repository_branch_after_save' => '1',
				)
			)
		);

		self::assertIsString( $redirect );
		self::assertSame( 'feature/verified-after-save', $package->get_branch() );
		self::assertSame( 'packages/example', $package->get_subdirectory() );
		$query = $this->redirect_query( $redirect );
		self::assertSame( 'ran-booster-plugins', $query['page'] );
		self::assertSame( 'example/example.php', $query['package'] );
		self::assertSame( 'branch', $query['source_view'] );
		self::assertSame( '1', $query['ran_booster_repository_branch_check'] );
		self::assertArrayNotHasKey( 'ran_booster_result', $query );
		self::assertArrayNotHasKey( '_ran_booster_notice_nonce', $query );
		self::assertSame(
			1,
			\RAN\wp_verify_nonce(
				$query['_ran_booster_repository_branch_nonce'],
				PackageAdminController::repository_branch_check_action( $package, 'plugin' )
			)
		);
	}

	public function test_failed_save_never_produces_arepository_branch_check_redirect(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$input     = $this->input(
			'edit-plugin',
			array(
				'check_repository_branch_after_save' => '1',
				'expected_branch'                    => 'older-branch',
			)
		);

		self::assertFalse( $dashboard->post_package_operation( 'edit-plugin', $input ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertSame( 'ran_booster_package_edit_conflict', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'No settings were saved and no repository check ran.', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Save settings and check again.', $dashboard->messages[0]['message'] );

		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function test_failed_reinstall_keeps_the_saved_settings_notice_beside_the_deployment_error(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'failed',
			'correlation_id' => str_repeat( 'f', 32 ),
			'outcome_code'   => 'provider_failed',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse(
			$dashboard->post_package_operation(
				'edit-plugin',
				$this->input( 'edit-plugin', array( 'reinstall_after_save' => '1' ) )
			)
		);
		self::assertSame( 'info', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'settings were saved', $dashboard->messages[0]['message'] );
		self::assertSame( 'error', $dashboard->messages[1]['type'] );
	}

	#[DataProvider( 'conflicting_branch_operations' )]
	public function test_branch_admission_refuses_another_release_owner_before_writing( string $action ): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$themes  = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$themes->package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$database         = new RepositorySourceGuardDatabase();
		$database->rows[] = (object) array(
			'type'                   => 2,
			'package'                => 'release-owner',
			'source'                 => 'release_asset',
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
		);
		$lock             = new OperationUpdaterLock();
		$service          = $this->service( $plugins, $themes, new OperationCoordinator(), $lock, $database );
		try {
			$service->execute( PackageOperation::from_input( $action, $this->input( $action, array( 'dry-run' => '1' ) ) ) );
			self::fail( 'The destination Release owner must prevent adoption or reassignment.' );
		} catch ( \RuntimeException $failure ) {
			self::assertStringContainsString( 'release-owner', $failure->getMessage() );
			self::assertNull( $plugins->stored );
			self::assertNull( $themes->stored );
			self::assertSame( array(), $plugins->edited );
			self::assertSame( array(), $themes->edited );
			self::assertSame( array( 'acquire', 'release:fixture-lock' ), $lock->events );
		}
	}

	/** @return list<array{string}> */
	public static function conflicting_branch_operations(): array {
		return array( array( 'install-plugin' ), array( 'install-theme' ), array( 'edit-plugin' ), array( 'edit-theme' ) );
	}

	public function test_link_edit_and_unlink_use_the_explicit_repositories(): void {
		$plugin      = $this->plugin();
		$plugins     = new OperationPluginRepository( $plugin );
		$themes      = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$coordinator = new OperationCoordinator();
		$service     = $this->service( $plugins, $themes, $coordinator );

		$link = PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) );
		self::assertSame( 'linked', $service->execute( $link )['status'] );
		self::assertSame( 'owner/example', (string) $plugins->stored?->get_repository() );

		$edit = PackageOperation::from_input( 'edit-plugin', $this->input( 'edit-plugin' ) );
		self::assertSame( 'edited', $service->execute( $edit )['status'] );
		self::assertSame( 'R_example', $plugins->edited['provider_repository_id'] );
		self::assertSame( PackageSource::BRANCH->value, $plugins->edited['expected_source'] );
		self::assertSame( 1, $plugins->edited['expected_source_revision'] );

		$unlink = PackageOperation::from_input( 'unlink-plugin', $this->input( 'unlink-plugin' ) );
		self::assertSame( 'unlinked', $service->execute( $unlink )['status'] );
		self::assertSame( 'example/example.php', $plugins->unlinked );
		self::assertSame( 0, $coordinator->calls );
	}

	public function test_link_treats_the_same_release_managed_target_as_already_managed(): void {
		$plugins                       = new OperationPluginRepository( $this->plugin() );
		$plugins->fresh_after_mutation = $this->plugin();
		$plugins->fresh_after_mutation->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'release', true, 'existing-access' ) );
		$plugins->fresh_after_mutation->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$plugins->fresh_after_mutation->set_source( PackageSource::RELEASE_ASSET, 7 );
		$plugins->adoption_result = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Booster found existing package management data. No package changes were made.'
		);
		$dashboard                = new Dashboard(
			new Database(),
			$plugins,
			new Booster(),
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			( new \ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( TroubleshootingService::class ) )->newInstanceWithoutConstructor(),
			null,
			null,
			$this->service( $plugins, new OperationThemeRepository( new OperationTheme( 'example' ) ), new OperationCoordinator() )
		);

		$redirect = $dashboard->post_package_operation(
			'install-plugin',
			$this->input( 'install-plugin', array( 'dry-run' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( 'ran-booster-plugins', $query['page'] );
		self::assertSame( 'already-managed', $query['ran_booster_result'] );
		self::assertSame( 'example/example.php', $query['ran_booster_package'] );
		self::assertSame( 'example/example.php', $query['package'] );
		self::assertSame(
			1,
			\RAN\wp_verify_nonce(
				$query['_ran_booster_notice_nonce'],
				'ran-booster-package-success|plugin|already-managed|example/example.php'
			)
		);
		$_GET   = $query;
		$notice = $this->invoke_package_success_notice( $dashboard, 'plugin' );
		self::assertSame(
			array(
				'operation'  => 'already-managed',
				'identifier' => 'example/example.php',
			),
			$notice
		);
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertSame( 'Plugin is already installed and managed by Booster. No package settings were changed.', $dashboard->messages[0]['message'] );
	}

	public function test_link_keeps_mismatched_existing_management_as_storage_failure(): void {
		$plugins                       = new OperationPluginRepository( $this->plugin() );
		$plugins->fresh_after_mutation = $this->plugin();
		$plugins->fresh_after_mutation->set_repository( new ManagedRepository( 'gh', 'owner/other', 'R_other', 'main' ) );
		$plugins->adoption_result = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Booster found existing package management data. No package changes were made.'
		);
		$service                  = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);

		try {
			$service->execute(
				PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
			);
			self::fail( 'A mismatched managed package must not be reported as linked.' );
		} catch ( \RAN\Storage\PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_adoption_conflict', $failure->get_diagnostic_id() );
		}
	}

	public function test_link_and_edit_use_the_shared_updater_lock(): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$lock    = new OperationUpdaterLock();
		$service = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator(),
			$lock
		);

		$service->execute(
			PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
		);
		$service->execute(
			PackageOperation::from_input( 'edit-plugin', $this->input( 'edit-plugin' ) )
		);

		self::assertSame(
			array( 'acquire', 'release:fixture-lock', 'acquire', 'release:fixture-lock' ),
			$lock->events
		);
	}

	#[DataProvider( 'link_and_edit_actions' )]
	public function test_updater_lock_contention_prevents_link_and_edit( string $action ): void {
		$plugins         = new OperationPluginRepository( $this->plugin() );
		$lock            = new OperationUpdaterLock();
		$lock->available = false;
		$service         = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator(),
			$lock
		);

		try {
			$service->execute( PackageOperation::from_input( $action, $this->input( $action, array( 'dry-run' => '1' ) ) ) );
			self::fail( 'Lock contention must reject the package mutation.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Another package operation is in progress.', $failure->getMessage() );
			self::assertNull( $plugins->stored );
			self::assertSame( array(), $plugins->edited );
		}
	}

	/** @return list<array{string}> */
	public static function link_and_edit_actions(): array {
		return array(
			array( 'install-plugin' ),
			array( 'edit-plugin' ),
		);
	}

	/** @return list<array{string}> */
	public static function edit_actions(): array {
		return array(
			array( 'edit-plugin' ),
			array( 'edit-theme' ),
		);
	}

	#[DataProvider( 'edit_actions' )]
	public function test_edit_rejects_missing_malformed_and_stale_expected_snapshots_before_writing( string $action ): void {
		foreach (
			array(
				'missing'   => static function ( array $input ): array {
					unset( $input['expected_repository'] );
					return $input;
				},
				'malformed' => static function ( array $input ): array {
					$input['expected_source_revision'] = '01';
					return $input;
				},
				'stale'     => static function ( array $input ): array {
					$input['expected_branch'] = 'older-branch';
					return $input;
				},
			) as $case => $change
		) {
			$package = 'edit-plugin' === $action ? $this->plugin() : new OperationTheme( 'example' );
			if ( $package instanceof Theme ) {
				$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
			}
			$plugins = new OperationPluginRepository( $this->plugin() );
			$themes  = new OperationThemeRepository( $package instanceof Theme ? $package : new OperationTheme( 'example' ) );
			if ( $package instanceof Plugin ) {
				$plugins = new OperationPluginRepository( $package );
			}
			$service = $this->service( $plugins, $themes, new OperationCoordinator() );
			$result  = $service->execute( PackageOperation::from_input( $action, $change( $this->input( $action ) ) ) );

			self::assertSame( 'conflict', $result['status'], $case );
			self::assertTrue( array_key_exists( 'package', $result ) );
			self::assertSame( $package, $result['package'], $case );
			self::assertSame( array(), 'edit-plugin' === $action ? $plugins->edited : $themes->edited, $case );
		}
	}

	public function test_dashboard_keeps_astale_edit_on_the_form_with_apersistent_conflict(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$input     = $this->input( 'edit-plugin', array( 'expected_branch' => 'older-branch' ) );

		self::assertFalse( $dashboard->post_package_operation( 'edit-plugin', $input ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_package_edit_conflict', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'No settings were saved.', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'resubmit your attempted changes', $dashboard->messages[0]['message'] );

		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function test_updater_lock_release_failure_does_not_report_link_success(): void {
		$plugins          = new OperationPluginRepository( $this->plugin() );
		$lock             = new OperationUpdaterLock();
		$lock->releasable = false;
		$service          = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator(),
			$lock
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The package operation lock could not be released.' );
		$service->execute(
			PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
		);
	}

	public function test_theme_link_edit_and_unlink_use_the_explicit_repositories(): void {
		$plugins     = new OperationPluginRepository( $this->plugin() );
		$themes      = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$coordinator = new OperationCoordinator();
		$service     = $this->service( $plugins, $themes, $coordinator );

		$link = PackageOperation::from_input( 'install-theme', $this->input( 'install-theme', array( 'dry-run' => '1' ) ) );
		self::assertSame( 'linked', $service->execute( $link )['status'] );
		self::assertSame( 'owner/example', (string) $themes->stored?->get_repository() );

		$edit = PackageOperation::from_input( 'edit-theme', $this->input( 'edit-theme' ) );
		self::assertSame( 'edited', $service->execute( $edit )['status'] );
		self::assertSame( 'R_example', $themes->edited['provider_repository_id'] );

		$unlink = PackageOperation::from_input( 'unlink-theme', $this->input( 'unlink-theme' ) );
		self::assertSame( 'unlinked', $service->execute( $unlink )['status'] );
		self::assertSame( 'example', $themes->unlinked );
		self::assertSame( 0, $coordinator->calls );
	}

	public function test_release_managed_package_retains_its_repository_identity_while_updating_access_and_policy(): void {
		$plugin = $this->plugin();
		$plugin->set_repository( new ManagedRepository( 'gh', 'owner/release', 'R_release', 'stable', true, 'old-access' ) );
		$plugin->set_source( PackageSource::RELEASE_ASSET, 2 );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);

		$input = $this->input(
			'edit-plugin',
			array(
				'provider'                        => 'bb',
				'repository'                      => 'forged/repository',
				'provider_repository_id'          => 'forged-id',
				'branch'                          => 'forged-branch',
				'subdirectory'                    => 'forged/subdirectory',
				'credential_id'                   => 'new-access',
				'deployment_policy'               => DeploymentPolicy::AUTOMATIC->value,
				'expected_provider'               => 'gh',
				'expected_provider_repository_id' => 'R_release',
				'expected_repository'             => 'owner/release',
				'expected_branch'                 => 'stable',
				'expected_credential_id'          => 'old-access',
				'expected_subdirectory'           => '',
				'expected_private'                => '1',
				'expected_package_slug'           => 'example',
				'expected_deployment_policy'      => DeploymentPolicy::MANUAL->value,
				'expected_source'                 => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision'        => 2,
			)
		);
		self::assertSame( 'edited', $service->execute( PackageOperation::from_input( 'edit-plugin', $input ) )['status'] );
		self::assertSame( 'gh', $plugins->edited['provider'] );
		self::assertSame( 'owner/release', (string) $plugins->edited['repository'] );
		self::assertSame( 'R_release', $plugins->edited['provider_repository_id'] );
		self::assertSame( 'stable', $plugins->edited['branch'] );
		self::assertTrue( $plugins->edited['private'] );
		self::assertNull( $plugins->edited['subdirectory'] );
		self::assertSame( 'new-access', $plugins->edited['credential_id'] );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $plugins->edited['deployment_policy'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $plugins->edited['expected_source'] );
		self::assertSame( 2, $plugins->edited['expected_source_revision'] );
		self::assertSame(
			'unlinked',
			$service->execute(
				PackageOperation::from_input(
					'unlink-plugin',
					$this->input( 'unlink-plugin', array( 'expected_source_revision' => '2' ) )
				)
			)['status']
		);
		self::assertSame( 'example/example.php', $plugins->unlinked );
	}

	public function test_legacy_release_managed_package_with_subdirectory_cannot_be_edited(): void {
		$plugin = $this->plugin();
		$plugin->set_subdirectory( 'packages/example' );
		$plugin->set_source( PackageSource::RELEASE_ASSET, 2 );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);
		$input   = $this->input(
			'edit-plugin',
			array(
				'expected_subdirectory'    => 'packages/example',
				'expected_source'          => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision' => 2,
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'must return to Branch first' );
		$service->execute( PackageOperation::from_input( 'edit-plugin', $input ) );
	}

	public function test_link_only_returns_the_disabled_package_read_back(): void {
		$plugins   = new OperationPluginRepository( $this->plugin() );
		$service   = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);
		$operation = PackageOperation::from_input(
			'install-plugin',
			$this->input(
				'install-plugin',
				array(
					'dry-run'           => '1',
					'deployment_policy' => DeploymentPolicy::DISABLED->value,
				)
			)
		);

		$result = $service->execute( $operation );

		self::assertSame( 'linked', $result['status'] );
		self::assertTrue( array_key_exists( 'package', $result ) );
		self::assertSame( DeploymentPolicy::DISABLED, $result['package']->get_deployment_policy() );
		self::assertSame( $plugins->stored, $result['package'] );
	}

	public function test_link_only_preserves_mixed_case_installed_package_slugs(): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$themes  = new OperationThemeRepository( new OperationTheme( 'tnyGmaps' ) );
		$service = $this->service( $plugins, $themes, new OperationCoordinator() );

		$plugin_input = $this->input(
			'install-plugin',
			array(
				'dry-run'      => '1',
				'package_slug' => 'tnyGmaps',
			)
		);
		$theme_input  = $this->input(
			'install-theme',
			array(
				'dry-run'      => '1',
				'package_slug' => 'tnyGmaps',
			)
		);

		self::assertSame( 'linked', $service->execute( PackageOperation::from_input( 'install-plugin', $plugin_input ) )['status'] );
		self::assertSame( 'linked', $service->execute( PackageOperation::from_input( 'install-theme', $theme_input ) )['status'] );
		self::assertSame( 'tnyGmaps', $plugins->requested_slug );
		self::assertSame( 'tnyGmaps', $themes->requested_slug );
	}

	public function test_actual_install_and_update_use_only_the_coordinator(): void {
		$coordinator = new OperationCoordinator();
		$service     = $this->service(
			new OperationPluginRepository( $this->plugin() ),
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			$coordinator
		);

		foreach ( array( 'install-plugin', 'install-theme', 'update-plugin', 'update-theme' ) as $action ) {
			$result = $service->execute( PackageOperation::from_input( $action, $this->input( $action ) ) );
			self::assertSame( 'succeeded', $result['status'] );
			self::assertTrue( array_key_exists( 'outcome_code', $result ) );
			self::assertSame( 'deployed', $result['outcome_code'] );
			self::assertTrue( array_key_exists( 'correlation_id', $result ) );
			self::assertSame( str_repeat( 'a', 32 ), $result['correlation_id'] );
			self::assertTrue( array_key_exists( 'package', $result ) );
			self::assertInstanceOf( Package::class, $result['package'] );
		}
		self::assertSame( 4, $coordinator->calls );
	}

	public function test_already_managed_install_is_reported_as_already_managed(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'succeeded',
			'correlation_id' => str_repeat( 'a', 32 ),
			'outcome_code'   => 'already_managed',
		);
		$service             = $this->service(
			new OperationPluginRepository( $this->plugin() ),
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			$coordinator
		);

		$result = $service->execute( PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin' ) ) );

		self::assertSame( 'already-managed', $result['status'] );
		self::assertTrue( array_key_exists( 'outcome_code', $result ) );
		self::assertSame( 'already_managed', $result['outcome_code'] );
		self::assertTrue( array_key_exists( 'package', $result ) );
		self::assertInstanceOf( Package::class, $result['package'] );
	}

	public function test_already_managed_install_redirects_to_the_signed_already_managed_warning(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'succeeded',
			'correlation_id' => str_repeat( 'a', 32 ),
			'outcome_code'   => 'already_managed',
		);
		$dashboard           = $this->dashboard( $coordinator );

		$redirect = $dashboard->post_package_operation( 'install-plugin', $this->input( 'install-plugin' ) );

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( 'already-managed', $query['ran_booster_result'] );
		self::assertSame( 'example/example.php', $query['ran_booster_package'] );
		self::assertSame(
			1,
			\RAN\wp_verify_nonce(
				$query['_ran_booster_notice_nonce'],
				'ran-booster-package-success|plugin|already-managed|example/example.php'
			)
		);
		$_GET = $query;
		self::assertSame(
			array(
				'operation'  => 'already-managed',
				'identifier' => 'example/example.php',
			),
			$this->invoke_package_success_notice( $dashboard, 'plugin' )
		);
		self::assertSame(
			array(
				'type'    => 'warning',
				'message' => 'Plugin is already installed and managed by Booster. No package settings were changed.',
			),
			$dashboard->messages[0]
		);
	}

	public function test_terminal_deployment_failure_returns_only_fixed_safe_data(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'           => 'failed',
			'correlation_id'   => str_repeat( 'b', 32 ),
			'outcome_code'     => 'provider_failed',
			'provider_message' => 'secret-canary-token',
		);
		$service             = $this->service(
			new OperationPluginRepository( $this->plugin() ),
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			$coordinator
		);

		self::assertSame(
			array(
				'status'         => 'failed',
				'correlation_id' => str_repeat( 'b', 32 ),
				'outcome_code'   => 'provider_failed',
			),
			$service->execute( PackageOperation::from_input( 'install-plugin', $this->input( 'install-plugin' ) ) )
		);
	}

	/** @return list<array{string, string, string, string|null}> */
	public static function deployment_redirect_matrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins', 'install', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes', 'install', 'example' ),
			array( 'update-plugin', 'ran-booster-plugins', 'update', null ),
			array( 'update-theme', 'ran-booster-themes', 'update', null ),
		);
	}

	#[DataProvider( 'deployment_redirect_matrix' )]
	public function test_dashboard_returns_signed_matching_redirect_after_deployment_success(
		string $action,
		string $page,
		string $result,
		?string $settings_package
	): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->post_package_operation( $action, $this->input( $action ) );

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( $result, $query['ran_booster_result'] );
		self::assertArrayHasKey( '_ran_booster_notice_nonce', $query );
		self::assertArrayNotHasKey( 'open_picker', $query );
		if ( null === $settings_package ) {
			self::assertArrayNotHasKey( 'package', $query );
		} else {
			self::assertSame( $settings_package, $query['package'] );
		}
	}

	/** @return list<array{string, string, string}> */
	public static function repeat_install_redirect_matrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins-create', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes-create', 'example' ),
		);
	}

	#[DataProvider( 'repeat_install_redirect_matrix' )]
	public function test_dashboard_returns_signed_create_redirect_for_repeat_install( string $action, string $page, string $identifier ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->post_package_operation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( 'install', $query['ran_booster_result'] );
		self::assertSame( $identifier, $query['ran_booster_package'] );
		self::assertSame( 'gh', $query['provider'] );
		self::assertSame( '1', $query['open_picker'] );
		self::assertSame(
			1,
			\RAN\wp_verify_nonce(
				$query['_ran_booster_notice_nonce'],
				'ran-booster-package-success|' . ( str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme' ) . '|install|' . $identifier
			)
		);
	}

	#[DataProvider( 'repeat_install_redirect_matrix' )]
	public function test_repeat_create_redirect_consumes_signed_plain_text_notice(
		string $action,
		string $page,
		string $identifier
	): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$redirect  = $dashboard->post_package_operation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);
		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		$_GET  = $query;

		$type    = str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme';
		$success = $this->invoke_package_success_notice( $dashboard, $type );

		self::assertSame( $page, $query['page'] );
		self::assertSame( $identifier, $query['ran_booster_package'] );
		self::assertSame(
			array(
				'operation'  => 'install',
				'identifier' => $identifier,
			),
			$success
		);
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'success', $dashboard->messages[0]['type'] );
		self::assertSame(
			'plugin' === $type ? 'Plugin was successfully installed.' : 'Theme was successfully installed.',
			$dashboard->messages[0]['message']
		);
	}

	public function test_forged_get_marker_cannot_create_success_notice(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => 'example/example.php',
			'_ran_booster_notice_nonce' => 'forged',
		);

		$success = $this->invoke_package_success_notice( $dashboard, 'plugin' );

		self::assertNull( $success );
		self::assertSame( array(), $dashboard->messages );
	}

	public function test_forged_already_managed_marker_cannot_create_success_notice(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'already-managed',
			'ran_booster_package'       => 'example/example.php',
			'_ran_booster_notice_nonce' => 'forged',
		);

		self::assertNull( $this->invoke_package_success_notice( $dashboard, 'plugin' ) );
		self::assertSame( array(), $dashboard->messages );
	}

	public function test_signed_success_notice_cannot_be_cross_bound_across_type_operation_or_package(): void {
		$nonce = \RAN\wp_create_nonce( 'ran-booster-package-success|plugin|install|example/example.php' );
		$cases = array(
			'type'                      => array( 'theme', 'install', 'example/example.php' ),
			'operation'                 => array( 'plugin', 'update', 'example/example.php' ),
			'already managed operation' => array( 'plugin', 'already-managed', 'example/example.php' ),
			'package'                   => array( 'plugin', 'install', 'other/other.php' ),
		);

		foreach ( $cases as $case => $values ) {
			[ $type, $operation, $identifier ] = $values;
			$dashboard                         = $this->dashboard( new OperationCoordinator() );
			$_GET                              = array(
				'ran_booster_result'        => $operation,
				'ran_booster_package'       => $identifier,
				'_ran_booster_notice_nonce' => $nonce,
			);

			$this->invoke_package_success_notice( $dashboard, $type );

			self::assertSame( array(), $dashboard->messages, $case );
		}
	}

	#[DataProvider( 'edit_actions' )]
	public function test_edit_returns_adistinct_authoritative_repository_reread( string $action ): void {
		$plugin_original = $this->plugin();
		$plugin_fresh    = $this->plugin();
		$theme_original  = new OperationTheme( 'example' );
		$theme_original->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$theme_fresh = new OperationTheme( 'example' );
		$theme_fresh->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$plugins                       = new OperationPluginRepository( $plugin_original );
		$themes                        = new OperationThemeRepository( $theme_original );
		$plugins->fresh_after_mutation = $plugin_fresh;
		$themes->fresh_after_mutation  = $theme_fresh;

		$result = $this->service( $plugins, $themes, new OperationCoordinator() )
			->execute( PackageOperation::from_input( $action, $this->input( $action ) ) );

		$original = 'edit-plugin' === $action ? $plugin_original : $theme_original;
		$fresh    = 'edit-plugin' === $action ? $plugin_fresh : $theme_fresh;
		self::assertSame( 'edited', $result['status'] );
		self::assertTrue( array_key_exists( 'package', $result ) );
		self::assertSame( $fresh, $result['package'] );
		self::assertNotSame( $original, $result['package'] );
	}

	public function test_failed_post_does_not_reuse_picker_auto_open_marker(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array( 'open_picker' => '1' );
		$_POST     = array( 'ran_booster' => $this->input( 'install-plugin' ) );
		$method    = new \ReflectionMethod( Dashboard::class, 'requested_open_picker' );

		self::assertFalse( $method->invoke( $dashboard ) );

		$_POST = array();
		self::assertTrue( $method->invoke( $dashboard ) );

		$_GET = array();
	}

	public function test_signed_theme_update_marker_adds_fixed_success_notice_without_activation_action(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'update',
			'ran_booster_package'       => 'example',
			'_ran_booster_notice_nonce' => \RAN\wp_create_nonce( 'ran-booster-package-success|theme|update|example' ),
		);

		$this->invoke_package_success_notice( $dashboard, 'theme' );

		self::assertSame(
			array(
				array(
					'type'    => 'success',
					'message' => 'Theme was successfully updated.',
				),
			),
			$dashboard->messages
		);
	}

	/** @return list<array{string, string}> */
	public static function update_redirect_matrix(): array {
		return array(
			array( 'update-plugin', 'ran-booster-plugins' ),
			array( 'update-theme', 'ran-booster-themes' ),
		);
	}

	#[DataProvider( 'update_redirect_matrix' )]
	public function test_updates_ignore_repeat_install_intent( string $action, string $page ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->post_package_operation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( 'update', $query['ran_booster_result'] );
		self::assertArrayNotHasKey( 'provider', $query );
		self::assertArrayNotHasKey( 'open_picker', $query );
	}

	#[DataProvider( 'update_redirect_matrix' )]
	public function test_updates_preserve_normalized_package_list_filters( string $action, string $page ): void {
		$_GET      = array(
			's'        => ' release ',
			'provider' => 'GH',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
			'unsafe'   => '<script>',
		);
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->post_package_operation( $action, $this->input( $action ) );
		$_GET     = array();

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( 'release', $query['s'] );
		self::assertSame( 'gh', $query['provider'] );
		self::assertSame( 'release_asset', $query['source'] );
		self::assertSame( 'automatic', $query['policy'] );
		self::assertArrayNotHasKey( 'unsafe', $query );
	}

	#[DataProvider( 'update_redirect_matrix' )]
	public function test_settings_reinstall_returns_to_the_same_package_settings_page( string $action, string $page ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->post_package_operation(
			$action,
			$this->input( $action, array( 'return_to_settings' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame(
			str_ends_with( $action, 'plugin' ) ? 'example/example.php' : 'example',
			$query['package']
		);
	}

	public function test_dashboard_keeps_terminal_failure_on_the_form_with_safe_activity_message(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'           => 'failed',
			'correlation_id'   => str_repeat( 'c', 32 ),
			'outcome_code'     => 'provider_failed',
			'provider_message' => 'secret-canary-token',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse(
			$dashboard->post_package_operation(
				'install-theme',
				$this->input( 'install-theme', array( 'install_another' => '1' ) )
			)
		);
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'repository provider could not prepare', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( str_repeat( 'c', 32 ), $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'View deployment activity', $dashboard->messages[0]['message'] );
		self::assertStringNotContainsString( 'secret-canary-token', $dashboard->messages[0]['message'] );
	}

	public function test_malformed_deployment_correlation_cannot_fall_through_to_removal_copy(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'failed',
			'correlation_id' => 'invalid-correlation',
			'outcome_code'   => 'deletion_failed',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->post_package_operation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 400, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_manual_action_failed', $dashboard->messages[0]['code'] );
		self::assertStringNotContainsString( 'disabled in Booster', $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function test_dashboard_explains_an_already_active_deployment(): void {
		$coordinator          = new OperationCoordinator();
		$coordinator->failure = DeploymentStorageFailure::contention(
			active_attempt: array(
				'id'             => 42,
				'correlation_id' => str_repeat( 'd', 32 ),
				'state'          => 'running',
				'package_type'   => 'plugin',
				'package_slug'   => 'example',
			)
		);
		$dashboard            = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->post_package_operation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'info', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_deployment_active', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'plugin example in state running', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'attempt=42', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'reference=' . str_repeat( 'd', 32 ), $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function test_dashboard_explains_that_an_unresolved_deployment_is_not_running(): void {
		$coordinator          = new OperationCoordinator();
		$coordinator->failure = DeploymentStorageFailure::contention(
			active_attempt: array(
				'id'             => 43,
				'correlation_id' => str_repeat( 'e', 32 ),
				'state'          => 'needs_attention',
				'package_type'   => 'plugin',
				'package_slug'   => 'example',
			)
		);
		$dashboard            = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->post_package_operation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_deployment_active', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'could not confirm how an earlier deployment of the plugin example ended', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'No deployment is currently running', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Check the package and allow another attempt', $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	/** @return list<array{string, string, string}> */
	public static function linked_package_settings_redirect_matrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes', 'example' ),
		);
	}

	#[DataProvider( 'linked_package_settings_redirect_matrix' )]
	public function test_standard_dry_run_link_redirects_to_package_settings(
		string $action,
		string $page,
		string $identifier
	): void {
		$standard = $this->dashboard( new OperationCoordinator() );
		$redirect = $standard->post_package_operation(
			$action,
			$this->input( $action, array( 'dry-run' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( $identifier, $query['package'] );
		self::assertSame( 'install', $query['ran_booster_result'] );
		self::assertSame( $identifier, $query['ran_booster_package'] );
	}

	public function test_repeat_dry_run_link_redirects_to_create(): void {
		$repeat   = $this->dashboard( new OperationCoordinator() );
		$redirect = $repeat->post_package_operation(
			'install-plugin',
			$this->input(
				'install-plugin',
				array(
					'dry-run'         => '1',
					'install_another' => '1',
				)
			)
		);

		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( 'ran-booster-plugins-create', $query['page'] );
		self::assertSame( 'install', $query['ran_booster_result'] );
		self::assertSame( 'gh', $query['provider'] );
		self::assertSame( '1', $query['open_picker'] );

		$_GET = $query;
		self::assertSame(
			array(
				'operation'  => 'install',
				'identifier' => 'example/example.php',
			),
			$this->invoke_package_success_notice( $repeat, 'plugin' )
		);
	}

	public function test_linking_the_installed_booster_plugin_is_rejected_before_storage(): void {
		$plugin  = $this->plugin( 'ran-booster/ran-booster.php' );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service( $plugins, new OperationThemeRepository( new OperationTheme( 'example' ) ), new OperationCoordinator() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cannot manage its own plugin files' );
		try {
			$service->execute(
				PackageOperation::from_input(
					'install-plugin',
					$this->input(
						'install-plugin',
						array(
							'dry-run'      => '1',
							'package_slug' => 'ran-booster',
						)
					)
				)
			);
		} finally {
			self::assertNull( $plugins->stored );
		}
	}

	public function test_similar_plugin_name_can_still_be_linked(): void {
		$plugin  = $this->plugin( 'ran-booster-extra/ran-booster.php' );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service( $plugins, new OperationThemeRepository( new OperationTheme( 'example' ) ), new OperationCoordinator() );

		$result = $service->execute(
			PackageOperation::from_input(
				'install-plugin',
				$this->input(
					'install-plugin',
					array(
						'dry-run'      => '1',
						'package_slug' => 'ran-booster-extra',
					)
				)
			)
		);

		self::assertSame( 'linked', $result['status'] );
		self::assertSame( $plugin, $plugins->stored );
	}

	/** @return list<array{string, array<string, mixed>}> */
	public static function malformed_operations(): array {
		return array(
			array( 'remove-plugin', array() ),
			array( 'install-plugin', array( 'repository' => array() ) ),
			array( 'update-theme', array( 'stylesheet' => array() ) ),
			array( 'unlink-plugin', array() ),
		);
	}

	/** @param array<string, mixed> $overrides */
	#[DataProvider( 'malformed_operations' )]
	public function test_malformed_operations_are_rejected( string $action, array $overrides ): void {
		$input = array_merge( $this->input( $action ), $overrides );
		if ( 'unlink-plugin' === $action ) {
			unset( $input['file'] );
		}

		$this->expectException( InvalidArgumentException::class );
		PackageOperation::from_input( $action, $input );
	}

	/** @return array<string, array{string, string, string, string}> */
	public static function removal_redirects(): array {
		return array(
			'unlink plugin' => array( 'unlink-plugin', 'ran-booster-plugins', 'unlink', 'example/example.php' ),
			'delete plugin' => array( 'unlink-delete-plugin', 'ran-booster-plugins', 'unlink-and-delete', 'example/example.php' ),
			'unlink theme'  => array( 'unlink-theme', 'ran-booster-themes', 'unlink', 'example' ),
			'delete theme'  => array( 'unlink-delete-theme', 'ran-booster-themes', 'unlink-and-delete', 'example' ),
		);
	}

	#[DataProvider( 'removal_redirects' )]
	public function test_dashboard_returns_each_removal_to_its_ownership_index(
		string $action,
		string $page,
		string $result,
		string $identifier
	): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$themes  = new OperationThemeRepository( new OperationTheme( 'example' ) );
		if ( str_starts_with( $action, 'unlink-delete-' ) ) {
			if ( str_ends_with( $action, 'plugin' ) ) {
				$plugins->installed = false;
			} else {
				$themes->installed = false;
			}
		}
		$service   = $this->service( $plugins, $themes, new OperationCoordinator() );
		$dashboard = new Dashboard(
			new Database(),
			$plugins,
			new Booster(),
			$themes,
			( new \ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( TroubleshootingService::class ) )->newInstanceWithoutConstructor(),
			null,
			null,
			$service
		);

		$redirect = $dashboard->post_package_operation( $action, $this->input( $action ) );
		self::assertIsString( $redirect );
		$query = $this->redirect_query( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( $result, $query['ran_booster_result'] );
		self::assertSame( $identifier, $query['ran_booster_package'] );
		self::assertArrayNotHasKey( 'package', $query );
		self::assertSame(
			1,
			\RAN\wp_verify_nonce(
				$query['_ran_booster_notice_nonce'],
				'ran-booster-package-success|' . ( str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme' ) . '|' . $result . '|' . $identifier
			)
		);
		self::assertSame( array(), $dashboard->messages );
	}

	public function test_dashboard_redacts_unexpected_operation_failures(): void {
		$plugins                 = new OperationPluginRepository( $this->plugin() );
		$plugins->unlink_failure = new \RuntimeException( 'secret-canary-token' );
		$themes                  = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$dashboard               = new Dashboard(
			new Database(),
			$plugins,
			new Booster(),
			$themes,
			( new \ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( TroubleshootingService::class ) )->newInstanceWithoutConstructor(),
			null,
			null,
			$this->service( $plugins, $themes, new OperationCoordinator() )
		);

		self::assertFalse( $dashboard->post_package_operation( 'unlink-plugin', $this->input( 'unlink-plugin' ) ) );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_manual_action_failed', $dashboard->messages[0]['code'] );
		self::assertStringNotContainsString( 'secret-canary-token', $dashboard->messages[0]['message'] );
	}

	public function test_dashboard_explains_repository_release_owner_refusal(): void {
		$database       = new RepositorySourceGuardDatabase();
		$database->rows = array(
			(object) array(
				'type'                   => '1',
				'package'                => 'booster-fixture-plugin/booster-fixture-plugin.php',
				'provider'               => 'gh',
				'provider_repository_id' => '1315521150',
				'source'                 => 'release_asset',
			),
		);
		$guard          = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$coordinator    = new OperationCoordinator();
		try {
			$guard->assert_allowed( 'gh', '1315521150', 1, 'branch-fixture', PackageSource::BRANCH );
			self::fail( 'A release-owned repository must refuse another package.' );
		} catch ( \RuntimeException $failure ) {
			$coordinator->failure = $failure;
		}
		$dashboard = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->post_package_operation( 'install-plugin', $this->input( 'install-plugin', array( 'subdirectory' => 'branch-fixture' ) ) ) );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_repository_source_conflict', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'This repository already supplies releases to booster-fixture-plugin/booster-fixture-plugin.php. Additional packages cannot use it.', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'switch that package to Branch', $dashboard->messages[0]['message'] );
		self::assertStringNotContainsString( 'ran_booster_manual_action_failed', $dashboard->messages[0]['message'] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function input( string $action, array $overrides = array() ): array {
		$type = str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme';

		return array_merge(
			array(
				'file'                                => 'example/example.php',
				'stylesheet'                          => 'example',
				'provider'                            => 'gh',
				'repository'                          => 'owner/example',
				'branch'                              => 'main',
				'package_slug'                        => 'example',
				'provider_repository_id'              => 'R_example',
				'provider_repository_identity_source' => 'resolved',
				'deployment_policy'                   => DeploymentPolicy::MANUAL->value,
				'expected_provider'                   => 'gh',
				'expected_provider_repository_id'     => 'R_example',
				'expected_repository'                 => 'owner/example',
				'expected_branch'                     => 'main',
				'expected_credential_id'              => '',
				'expected_subdirectory'               => '',
				'expected_private'                    => '0',
				'expected_package_slug'               => 'example',
				'expected_deployment_policy'          => DeploymentPolicy::MANUAL->value,
				'expected_source'                     => 'branch',
				'expected_source_revision'            => '1',
				'confirm_package_removal'             => '1',
			),
			$overrides,
			array( '_type' => $type )
		);
	}

	private function service(
		OperationPluginRepository $plugins,
		OperationThemeRepository $themes,
		OperationCoordinator $coordinator,
		?OperationUpdaterLock $updater_lock = null,
		?RepositorySourceGuardDatabase $source_database = null
	): PackageOperationService {
		$updater_lock ??= new OperationUpdaterLock();
		if ( null === $source_database ) {
			$source_database = new RepositorySourceGuardDatabase();
			foreach ( array(
				1 => $plugins->package,
				2 => $themes->package,
			) as $type => $package ) {
				try {
					$package->get_repository();
				} catch ( \TypeError ) {
					continue; // Installed-only fixtures do not yet represent a managed relationship.
				}
				if ( null !== $package->get_provider_code() && null !== $package->get_provider_repository_id() ) {
					$source_database->rows[] = (object) array(
						'type'                   => $type,
						'package'                => $package->get_identifier(),
						'provider'               => $package->get_provider_code(),
						'provider_repository_id' => $package->get_provider_repository_id(),
						'source'                 => $package->get_source()->value,
					);
				}
			}
		}

		return new PackageOperationService(
			$plugins,
			$themes,
			$coordinator,
			new PackageRemovalService( $plugins, $themes, new OperationRemovalGateway(), null, $updater_lock ),
			$updater_lock,
			new RepositorySourceGuard( $source_database, $this->createStub( Database::class ) )
		);
	}

	private function dashboard( OperationCoordinator $coordinator, ?Plugin $plugin = null ): Dashboard {
		$plugins = new OperationPluginRepository( $plugin ?? $this->plugin() );
		$themes  = new OperationThemeRepository( new OperationTheme( 'example' ) );

		return new Dashboard(
			new Database(),
			$plugins,
			new Booster(),
			$themes,
			( new \ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( TroubleshootingService::class ) )->newInstanceWithoutConstructor(),
			null,
			null,
			$this->service( $plugins, $themes, $coordinator )
		);
	}

	/** @return array{operation: string, identifier: string}|null */
	private function invoke_package_success_notice( Dashboard $dashboard, string $type ): ?array {
		return ( new PackageAdminController() )->add_success_notice( $dashboard, $type );
	}

	/** @return array<string, string> */
	private function redirect_query( string $redirect ): array {
		$query = parse_url( $redirect, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress runtime is available in this unit test.
		self::assertIsString( $query );
		parse_str( $query, $parameters );

		/** @var array<string, string> $parameters */
		return $parameters;
	}

	private function plugin( string $file = 'example/example.php' ): Plugin {
		$plugin     = Plugin::from_wp_array(
			$file,
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
		$repository = new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' );
		$plugin->set_repository( $repository );

		return $plugin;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationCoordinator extends DeploymentCoordinator {
	public int $calls                      = 0;
	public ?\Throwable $failure            = null;
	public ?PackageOperation $last_command = null;
	/** @var array{status: 'failed'|'succeeded', correlation_id: string, outcome_code: string} */
	public array $result = array(
		'status'         => 'succeeded',
		'correlation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'outcome_code'   => 'deployed',
	);
	public function __construct() {}
	public function execute_manual( PackageOperation $command ): array {
		++$this->calls;
		$this->last_command = $command;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationUpdaterLock extends WordPressUpdaterLock {
	public bool $available  = true;
	public bool $releasable = true;
	/** @var list<string> */
	public array $events = array();

	public function acquire(): string {
		$this->events[] = 'acquire';
		if ( ! $this->available ) {
			throw new \RuntimeException( 'Fixture lock unavailable.' );
		}

		return 'fixture-lock';
	}

	public function release( string $token ): bool {
		$this->events[] = 'release:' . $token;
		return $this->releasable;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationPluginRepository extends PluginRepository {
	public ?Plugin $stored                                = null;
	/** @var array<string, mixed> */ public array $edited = array();
	public bool $installed                                = true;
	public ?string $unlinked                              = null;
	public ?string $requested_slug                        = null;
	public ?\Throwable $unlink_failure                    = null;
	public ?Plugin $fresh_after_mutation                  = null;
	public ?PackageMutationResult $adoption_result        = null;
	public function __construct( public Plugin $package ) {}
	public function from_slug( $slug ) {
		$this->requested_slug = (string) $slug;
		return $this->package; }
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_plugin_from_file retains the production method contract; these inputs do not affect this controlled result.
	public function booster_plugin_from_file( $file ) {
		return null !== $this->fresh_after_mutation && ( null !== $this->stored || array() !== $this->edited )
			? $this->fresh_after_mutation
			: $this->package; }
	public function store( Plugin $plugin ): PackageMutationResult {
		$this->stored = $plugin;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function adopt( Plugin $plugin ): PackageMutationResult {
		$this->stored = $plugin;
		return $this->adoption_result ?? PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of edit_plugin retains the production method contract; these inputs do not affect this controlled result.
	public function edit_plugin( $file, $input ): PackageMutationResult {
		$this->edited = $input;
		$this->package->set_repository( $input['repository'] );
		$this->package->set_deployment_policy( DeploymentPolicy::from_database( $input['deployment_policy'] ) );
		$this->package->set_subdirectory( $input['subdirectory'] );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function disable_plugin_for_removal( Plugin $plugin ): PackageMutationResult {
		unset( $plugin );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	/** @param string $file */
	public function unlink( $file ): PackageMutationResult {
		if ( null !== $this->unlink_failure ) {
			throw $this->unlink_failure;
		}
		$this->unlinked = (string) $file;
		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
	public function is_installed( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationThemeRepository extends ThemeRepository {
	public ?Theme $stored                                 = null;
	/** @var array<string, mixed> */ public array $edited = array();
	public bool $installed                                = true;
	public ?string $unlinked                              = null;
	public ?string $requested_slug                        = null;
	public ?Theme $fresh_after_mutation                   = null;
	public function __construct( public Theme $package ) {}
	public function from_slug( $slug ) {
		$this->requested_slug = (string) $slug;
		return $this->package; }
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of booster_theme_from_stylesheet retains the production method contract; these inputs do not affect this controlled result.
	public function booster_theme_from_stylesheet( $stylesheet ) {
		return null !== $this->fresh_after_mutation && ( null !== $this->stored || array() !== $this->edited )
			? $this->fresh_after_mutation
			: $this->package; }
	public function store( Theme $theme ): PackageMutationResult {
		$this->stored = $theme;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function adopt( Theme $theme ): PackageMutationResult {
		$this->stored = $theme;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of edit_theme retains the production method contract; these inputs do not affect this controlled result.
	public function edit_theme( $stylesheet, $input ): PackageMutationResult {
		$this->edited = $input;
		$this->package->set_repository( $input['repository'] );
		$this->package->set_deployment_policy( DeploymentPolicy::from_database( $input['deployment_policy'] ) );
		$this->package->set_subdirectory( $input['subdirectory'] );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function disable_theme_for_removal( Theme $theme ): PackageMutationResult {
		unset( $theme );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	/** @param string $stylesheet */
	public function unlink( $stylesheet ): PackageMutationResult {
		$this->unlinked = (string) $stylesheet;
		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
	public function is_installed( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationTheme extends Theme {
	public function __construct( string $stylesheet = '' ) {
		$this->stylesheet = $stylesheet;
		$this->name       = 'Example';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class OperationRemovalGateway implements PackageRemovalGateway {
	public function plugin_is_active( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function plugin_has_active_dependents( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function plugin_shares_directory( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function plugin_path_is_safe( string $identifier ): bool {
		unset( $identifier );
		return true;
	}

	public function deactivate_plugin( string $identifier ): void {
		unset( $identifier );
	}

	public function delete_plugin( string $identifier ): bool {
		unset( $identifier );
		return true;
	}

	public function theme_deletion_blocker( string $stylesheet ): ?string {
		unset( $stylesheet );
		return null;
	}

	public function theme_path_is_safe( string $stylesheet ): bool {
		unset( $stylesheet );
		return true;
	}

	public function delete_theme( string $stylesheet ): bool {
		unset( $stylesheet );
		return true;
	}
}

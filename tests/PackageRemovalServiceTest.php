<?php

declare(strict_types=1);

namespace RAN\Tests;

require_once __DIR__ . '/Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/Support/WPError.php';
require_once __DIR__ . '/Deployment/PackageMutationGuardWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageAdminController;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Dashboard;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageOperation;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalResult;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\WordPressUpdaterLock;
use WP_Error;

final class PackageRemovalServiceTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_repository_admin_translations']    = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_repository_admin_translations']
		);
	}

	public function test_removal_requires_exact_confirmation_and_source_revision(): void {
		foreach (
			array(
				array( 'confirm_package_removal' => '0' ),
				array( 'expected_source_revision' => '01' ),
				array( 'expected_source_revision' => '0' ),
				array( 'file' => '../example/example.php' ),
				array( 'file' => 'example/example.txt' ),
			) as $override
		) {
			try {
				PackageOperation::from_input( 'unlink-plugin', array_merge( $this->input(), $override ) );
				self::fail( 'Expected an invalid removal request.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}

		$operation = PackageOperation::from_input( 'unlink-delete-plugin', $this->input() );
		self::assertSame( 'unlink-and-delete', $operation->operation );
		self::assertSame( 7, $operation->get_expected_source_revision() );
	}

	public function test_stale_revision_changes_nothing(): void {
		$fixture = $this->fixture();
		$result  = $fixture->service->execute(
			PackageOperation::from_input(
				'unlink-delete-plugin',
				$this->input( array( 'expected_source_revision' => '6' ) )
			)
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'stale', $result->outcome_code );
		self::assertEquals( PackageRemovalResult::failed( outcome_code: 'stale' ), $result );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertSame( array(), $fixture->gateway->events );
	}

	public function test_confirmed_unlink_leaves_package_files_installed(): void {
		$fixture = $this->fixture();
		$result  = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'unlinked', $result->status );
		self::assertTrue( $fixture->plugins->unlinked );
		self::assertTrue( $fixture->plugins->installed );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
		self::assertSame( array(), $fixture->gateway->events );
	}

	public function test_confirmed_unlink_clears_branch_evidence_before_the_same_identity_can_be_reused(): void {
		$fixture  = $this->fixture();
		$evidence = new RemovalBranchCheckEvidenceStore();
		$evidence->record( 'plugin', $fixture->plugin, 'profile-a', 'verified' );
		$service = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			new RemovalUpdaterLock(),
			$evidence
		);

		self::assertNotNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
		self::assertSame( 'unlinked', $service->execute( PackageOperation::from_input( 'unlink-plugin', $this->input() ) )->status );
		self::assertNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function test_failed_unlink_invalidates_branch_evidence(): void {
		$fixture                          = $this->fixture();
		$evidence                         = new RemovalBranchCheckEvidenceStore();
		$fixture->plugins->unlink_failure = true;
		$service                          = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			new RemovalUpdaterLock(),
			$evidence
		);
		$evidence->record( 'plugin', $fixture->plugin, 'profile-a', 'verified' );

		try {
			$service->execute( PackageOperation::from_input( 'unlink-plugin', $this->input() ) );
			self::fail( 'Failed unlink should preserve the invalidated branch evidence state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Fixture unlink failed.', $failure->getMessage() );
		}
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function test_failed_branch_evidence_clear_leaves_package_management_and_evidence_untouched(): void {
		$fixture               = $this->fixture();
		$evidence              = new RemovalBranchCheckEvidenceStore();
		$evidence->clear_fails = true;
		$service               = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			new RemovalUpdaterLock(),
			$evidence
		);
		$evidence->record( 'plugin', $fixture->plugin, 'profile-a', 'verified' );

		try {
			$service->execute( PackageOperation::from_input( 'unlink-plugin', $this->input() ) );
			self::fail( 'Failed branch evidence clear should not unlink the package.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Fixture evidence clear failed.', $failure->getMessage() );
		}
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNotNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function test_confirmed_unlink_uses_the_shared_updater_lock(): void {
		$fixture = $this->fixture();
		$lock    = new RemovalUpdaterLock();
		$service = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::from_input( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'unlinked', $result->status );
		self::assertSame( array( 'acquire', 'release:fixture-lock' ), $lock->events );
	}

	public function test_plain_unlink_lock_contention_changes_nothing(): void {
		$fixture         = $this->fixture();
		$lock            = new RemovalUpdaterLock();
		$lock->available = false;
		$service         = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::from_input( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'operation_in_progress', $result->outcome_code );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function test_plain_unlink_lock_release_failure_does_not_report_success(): void {
		$fixture          = $this->fixture();
		$lock             = new RemovalUpdaterLock();
		$lock->releasable = false;
		$service          = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::from_input( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'operation_lock_failed', $result->outcome_code );
		self::assertTrue( $fixture->plugins->unlinked );
	}

	public function test_plugin_is_disabled_deactivated_uninstalled_deleted_then_unlinked(): void {
		$fixture                         = $this->fixture();
		$fixture->gateway->plugin_active = true;
		$fixture->gateway->plugin_delete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->get_deployment_policy() );
		self::assertSame( 8, $fixture->plugin->get_source_revision() );
		self::assertTrue( $fixture->plugins->unlinked );
		self::assertSame(
			array( 'plugin_path', 'plugin_shared', 'plugin_dependents', 'plugin_active', 'plugin_deactivate', 'plugin_active', 'plugin_delete' ),
			$fixture->gateway->events
		);
	}

	public function test_destructive_removal_uses_the_shared_updater_lock(): void {
		$fixture                         = $this->fixture();
		$lock                            = new RemovalUpdaterLock();
		$fixture->gateway->plugin_delete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};
		$service                         = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( array( 'acquire', 'release:fixture-lock' ), $lock->events );
	}

	public function test_updater_lock_contention_changes_nothing(): void {
		$fixture         = $this->fixture();
		$lock            = new RemovalUpdaterLock();
		$lock->available = false;
		$service         = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'operation_in_progress', $result->outcome_code );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
		self::assertSame( 7, $fixture->plugin->get_source_revision() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function test_active_plugin_dependents_leave_the_managed_package_unchanged(): void {
		$fixture                                    = $this->fixture();
		$fixture->gateway->plugin_active_dependents = true;

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'active_dependents', $result->outcome_code );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
		self::assertSame( 7, $fixture->plugin->get_source_revision() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertSame( array( 'plugin_path', 'plugin_shared', 'plugin_dependents' ), $fixture->gateway->events );
	}

	#[DataProvider( 'unsafe_plugin_deletion_states' )]
	public function test_unsafe_plugin_deletion_preconditions_change_nothing(
		bool $safe_path,
		bool $shared_directory,
		string $outcome_code
	): void {
		$fixture                                   = $this->fixture();
		$fixture->gateway->plugin_safe_path        = $safe_path;
		$fixture->gateway->plugin_shared_directory = $shared_directory;

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( $outcome_code, $result->outcome_code );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
		self::assertSame( 7, $fixture->plugin->get_source_revision() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	/** @return list<array{bool, bool, string}> */
	public static function unsafe_plugin_deletion_states(): array {
		return array(
			array( false, false, 'unsafe_path' ),
			array( true, true, 'shared_plugin_directory' ),
		);
	}

	public function test_failed_plugin_deactivation_leaves_the_managed_package_disabled(): void {
		$fixture                                     = $this->fixture();
		$fixture->gateway->plugin_active             = true;
		$fixture->gateway->deactivation_stays_active = true;

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deactivation_failed', $result->outcome_code );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->get_deployment_policy() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNotContains( 'plugin_delete', $fixture->gateway->events );
	}

	public function test_reported_deletion_must_also_remove_the_files(): void {
		$fixture                         = $this->fixture();
		$fixture->gateway->plugin_delete = static fn (): bool => true;

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'files_still_present', $result->outcome_code );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->get_deployment_policy() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function test_verified_absence_wins_over_an_unreliable_word_press_return_value(): void {
		$fixture                         = $this->fixture();
		$fixture->gateway->plugin_delete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return false;
		};

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertTrue( $fixture->plugins->unlinked );
	}

	public function test_files_deleted_but_management_unlink_failure_is_bounded(): void {
		$fixture                          = $this->fixture();
		$fixture->plugins->unlink_failure = true;
		$fixture->gateway->plugin_delete  = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'management_state_uncertain', $result->outcome_code );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->get_deployment_policy() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	/** @return list<array{string}> */
	public static function theme_blockers(): array {
		return array(
			array( 'theme_active' ),
			array( 'theme_parent_in_use' ),
			array( 'theme_has_children' ),
		);
	}

	#[DataProvider( 'theme_blockers' )]
	public function test_theme_safety_blocker_leaves_the_managed_theme_unchanged( string $blocker ): void {
		$fixture                         = $this->fixture();
		$fixture->gateway->theme_blocker = $blocker;

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-theme', $this->theme_input() )
		);

		self::assertSame( $blocker, $result->outcome_code );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->theme->get_deployment_policy() );
		self::assertSame( 7, $fixture->theme->get_source_revision() );
		self::assertFalse( $fixture->themes->unlinked );
		self::assertNotContains( 'theme_delete', $fixture->gateway->events );
	}

	public function test_theme_deletion_is_verified_before_management_is_unlinked(): void {
		$fixture                        = $this->fixture();
		$fixture->gateway->theme_delete = static function () use ( $fixture ): bool {
			$fixture->themes->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::from_input( 'unlink-delete-theme', $this->theme_input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->theme->get_deployment_policy() );
		self::assertSame( 8, $fixture->theme->get_source_revision() );
		self::assertTrue( $fixture->themes->unlinked );
		self::assertSame( array( 'theme_path', 'theme_blocker', 'theme_delete' ), $fixture->gateway->events );
	}

	public function test_disabled_file_modifications_prevent_any_state_change(): void {
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;
		$fixture = $this->fixture();

		$this->expectException( \RuntimeException::class );
		try {
			$fixture->service->execute(
				PackageOperation::from_input( 'unlink-delete-plugin', $this->input() )
			);
		} finally {
			self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->get_deployment_policy() );
			self::assertFalse( $fixture->plugins->unlinked );
		}
	}

	public function test_dashboard_maps_only_bounded_removal_failures_to_safe_notices(): void {
		$dashboard  = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$controller = ( new \ReflectionClass( PackageAdminController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( PackageAdminController::class, 'removal_failure' );
		$operation  = PackageOperation::from_input( 'unlink-delete-plugin', $this->input() );

		foreach (
			array(
				'active_dependents',
				'deactivation_failed',
				'deletion_failed',
				'files_still_present',
				'management_state_uncertain',
				'operation_in_progress',
				'operation_lock_failed',
				'shared_plugin_directory',
				'stale',
				'unsafe_path',
			) as $outcome_code
		) {
			$dashboard->messages = array();
			$method->invoke(
				$controller,
				$operation,
				$outcome_code,
				static function ( WP_Error $message ) use ( $dashboard ): void {
					$dashboard->messages[] = array(
						'type'    => 'error',
						'code'    => $message->get_error_code(),
						'data'    => $message->get_error_data(),
						'message' => $message->get_error_message(),
					);
				}
			);
			self::assertCount( 1, $dashboard->messages );
			self::assertSame(
				'ran_booster_package_removal_' . $outcome_code,
				$dashboard->messages[0]['code']
			);
			self::assertStringNotContainsString(
				'example/example.php',
				$dashboard->messages[0]['message']
			);
		}
	}

	public function test_dashboard_removal_failure_uses_contextual_package_type_translation(): void {
		$dashboard  = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$controller = ( new \ReflectionClass( PackageAdminController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( PackageAdminController::class, 'removal_failure' );
		$operation  = PackageOperation::from_input( 'unlink-delete-plugin', $this->input() );
		$GLOBALS['ran_booster_repository_admin_translations'] = array(
			'ran-booster' => array(
				"package type\4Plugin" => 'Extension',
				'%s was disabled in Booster, but WordPress could not delete it.' => '%s a été désactivée dans Booster, mais WordPress n’a pas pu la supprimer.',
			),
		);

		$method->invoke(
			$controller,
			$operation,
			'deletion_failed',
			static function ( WP_Error $message ) use ( $dashboard ): void {
				$dashboard->messages[] = array( 'message' => $message->get_error_message() );
			}
		);

		self::assertSame( 'Extension a été désactivée dans Booster, mais WordPress n’a pas pu la supprimer.', $dashboard->messages[0]['message'] );
	}

	/** @param array<string, string> $overrides */
	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'file'                     => 'example/example.php',
				'expected_source_revision' => '7',
				'confirm_package_removal'  => '1',
			),
			$overrides
		);
	}

	/** @param array<string, string> $overrides */
	private function theme_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'stylesheet'               => 'example',
				'expected_source_revision' => '7',
				'confirm_package_removal'  => '1',
			),
			$overrides
		);
	}

	private function fixture(): RemovalFixture {
		$plugin = RemovalPlugin::make( 'example/example.php' );
		$theme  = new RemovalTheme( 'example' );
		foreach ( array( $plugin, $theme ) as $package ) {
			$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
			$package->set_deployment_policy( DeploymentPolicy::MANUAL );
			$package->set_source( PackageSource::BRANCH, 7 );
		}
		$plugins = new RemovalPluginRepository( $plugin );
		$themes  = new RemovalThemeRepository( $theme );
		$gateway = new RemovalGateway();

		return new RemovalFixture(
			$plugin,
			$theme,
			$plugins,
			$themes,
			$gateway,
			new PackageRemovalService( $plugins, $themes, $gateway, null, new RemovalUpdaterLock() )
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final readonly class RemovalFixture {
	public function __construct(
		public RemovalPlugin $plugin,
		public RemovalTheme $theme,
		public RemovalPluginRepository $plugins,
		public RemovalThemeRepository $themes,
		public RemovalGateway $gateway,
		public PackageRemovalService $service
	) {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalPlugin extends Plugin {
	public static function make( string $identifier ): self {
		return self::from_wp_array(
			$identifier,
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
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalTheme extends Theme {
	public function __construct( string $stylesheet = '' ) {
		$this->stylesheet = $stylesheet;
		$this->name       = 'Example';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalPluginRepository extends PluginRepository {
	public bool $installed      = true;
	public bool $unlinked       = false;
	public bool $unlink_failure = false;

	public function __construct( private readonly RemovalPlugin $package ) {
	}

	public function booster_plugin_from_file( $file ) {
		unset( $file );
		return $this->package;
	}

	public function disable_plugin_for_removal( Plugin $plugin ): PackageMutationResult {
		$plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
		$plugin->set_source( $plugin->get_source(), $plugin->get_source_revision() + 1 );

		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}

	public function is_installed( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}

	public function unlink( $file ): PackageMutationResult {
		unset( $file );
		if ( $this->unlink_failure ) {
			return PackageMutationResult::failed(
				operation: PackageStorageOperation::DELETE,
				diagnostic_id: 'fixture_unlink_failed',
				message: 'Fixture unlink failed.',
				recovery_required: true
			);
		}
		$this->unlinked = true;

		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalThemeRepository extends ThemeRepository {
	public bool $installed = true;
	public bool $unlinked  = false;

	public function __construct( private readonly RemovalTheme $package ) {
	}

	public function booster_theme_from_stylesheet( $stylesheet ) {
		unset( $stylesheet );
		return $this->package;
	}

	public function disable_theme_for_removal( Theme $theme ): PackageMutationResult {
		$theme->set_deployment_policy( DeploymentPolicy::DISABLED );
		$theme->set_source( $theme->get_source(), $theme->get_source_revision() + 1 );

		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}

	public function is_installed( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}

	public function unlink( $stylesheet ): PackageMutationResult {
		unset( $stylesheet );
		$this->unlinked = true;

		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var array<string, mixed> */
	private array $records   = array();
	public bool $clear_fails = false;

	public function clear( string $type, \RAN\Package $package ): void {
		if ( $this->clear_fails ) {
			throw new \RuntimeException( 'Fixture evidence clear failed.' );
		}

		parent::clear( $type, $package );
	}

	protected function read_option(): array {
		return $this->records;
	}

	protected function write_option( array $records ): bool {
		$this->records = $records;
		return true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalGateway implements PackageRemovalGateway {
	public bool $plugin_active             = false;
	public bool $plugin_active_dependents  = false;
	public bool $plugin_shared_directory   = false;
	public bool $plugin_safe_path          = true;
	public bool $theme_safe_path           = true;
	public bool $deactivation_stays_active = false;
	public ?string $theme_blocker          = null;
	/** @var callable(): bool|null */
	public $plugin_delete = null;
	/** @var callable(): bool|null */
	public $theme_delete = null;
	/** @var list<string> */
	public array $events = array();

	public function plugin_is_active( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_active';
		return $this->plugin_active;
	}

	public function plugin_has_active_dependents( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_dependents';
		return $this->plugin_active_dependents;
	}

	public function plugin_shares_directory( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_shared';
		return $this->plugin_shared_directory;
	}

	public function plugin_path_is_safe( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_path';
		return $this->plugin_safe_path;
	}

	public function deactivate_plugin( string $identifier ): void {
		unset( $identifier );
		$this->events[] = 'plugin_deactivate';
		if ( ! $this->deactivation_stays_active ) {
			$this->plugin_active = false;
		}
	}

	public function delete_plugin( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_delete';
		return null === $this->plugin_delete ? false : ( $this->plugin_delete )();
	}

	public function theme_deletion_blocker( string $stylesheet ): ?string {
		unset( $stylesheet );
		$this->events[] = 'theme_blocker';
		return $this->theme_blocker;
	}

	public function theme_path_is_safe( string $stylesheet ): bool {
		unset( $stylesheet );
		$this->events[] = 'theme_path';
		return $this->theme_safe_path;
	}

	public function delete_theme( string $stylesheet ): bool {
		unset( $stylesheet );
		$this->events[] = 'theme_delete';
		return null === $this->theme_delete ? false : ( $this->theme_delete )();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class RemovalUpdaterLock extends WordPressUpdaterLock {
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

<?php

declare(strict_types=1);

namespace Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused collaborators stay beside the removal service test.

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

	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_repository_admin_translations']    = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_repository_admin_translations']
		);
	}

	public function testRemovalRequiresExactConfirmationAndSourceRevision(): void {
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
				PackageOperation::fromInput( 'unlink-plugin', array_merge( $this->input(), $override ) );
				self::fail( 'Expected an invalid removal request.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}

		$operation = PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() );
		self::assertSame( 'unlink-and-delete', $operation->operation );
		self::assertSame( 7, $operation->getExpectedSourceRevision() );
	}

	public function testStaleRevisionChangesNothing(): void {
		$fixture = $this->fixture();
		$result  = $fixture->service->execute(
			PackageOperation::fromInput(
				'unlink-delete-plugin',
				$this->input( array( 'expected_source_revision' => '6' ) )
			)
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'stale', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertSame( array(), $fixture->gateway->events );
	}

	public function testConfirmedUnlinkLeavesPackageFilesInstalled(): void {
		$fixture = $this->fixture();
		$result  = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'unlinked', $result->status );
		self::assertTrue( $fixture->plugins->unlinked );
		self::assertTrue( $fixture->plugins->installed );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
		self::assertSame( array(), $fixture->gateway->events );
	}

	public function testConfirmedUnlinkClearsBranchEvidenceBeforeTheSameIdentityCanBeReused(): void {
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
		self::assertSame( 'unlinked', $service->execute( PackageOperation::fromInput( 'unlink-plugin', $this->input() ) )->status );
		self::assertNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function testFailedUnlinkInvalidatesBranchEvidence(): void {
		$fixture                         = $this->fixture();
		$evidence                        = new RemovalBranchCheckEvidenceStore();
		$fixture->plugins->unlinkFailure = true;
		$service                         = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			new RemovalUpdaterLock(),
			$evidence
		);
		$evidence->record( 'plugin', $fixture->plugin, 'profile-a', 'verified' );

		try {
			$service->execute( PackageOperation::fromInput( 'unlink-plugin', $this->input() ) );
			self::fail( 'Failed unlink should preserve the invalidated branch evidence state.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Fixture unlink failed.', $failure->getMessage() );
		}
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function testFailedBranchEvidenceClearLeavesPackageManagementAndEvidenceUntouched(): void {
		$fixture              = $this->fixture();
		$evidence             = new RemovalBranchCheckEvidenceStore();
		$evidence->clearFails = true;
		$service              = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			new RemovalUpdaterLock(),
			$evidence
		);
		$evidence->record( 'plugin', $fixture->plugin, 'profile-a', 'verified' );

		try {
			$service->execute( PackageOperation::fromInput( 'unlink-plugin', $this->input() ) );
			self::fail( 'Failed branch evidence clear should not unlink the package.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Fixture evidence clear failed.', $failure->getMessage() );
		}
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNotNull( $evidence->find( 'plugin', $fixture->plugin, 'profile-a' ) );
	}

	public function testConfirmedUnlinkUsesTheSharedUpdaterLock(): void {
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
			PackageOperation::fromInput( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'unlinked', $result->status );
		self::assertSame( array( 'acquire', 'release:fixture-lock' ), $lock->events );
	}

	public function testPlainUnlinkLockContentionChangesNothing(): void {
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
			PackageOperation::fromInput( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'operation_in_progress', $result->outcomeCode );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function testPlainUnlinkLockReleaseFailureDoesNotReportSuccess(): void {
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
			PackageOperation::fromInput( 'unlink-plugin', $this->input() )
		);

		self::assertSame( 'failed', $result->status );
		self::assertSame( 'operation_lock_failed', $result->outcomeCode );
		self::assertTrue( $fixture->plugins->unlinked );
	}

	public function testPluginIsDisabledDeactivatedUninstalledDeletedThenUnlinked(): void {
		$fixture                        = $this->fixture();
		$fixture->gateway->pluginActive = true;
		$fixture->gateway->pluginDelete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->getDeploymentPolicy() );
		self::assertSame( 8, $fixture->plugin->getSourceRevision() );
		self::assertTrue( $fixture->plugins->unlinked );
		self::assertSame(
			array( 'plugin_path', 'plugin_shared', 'plugin_dependents', 'plugin_active', 'plugin_deactivate', 'plugin_active', 'plugin_delete' ),
			$fixture->gateway->events
		);
	}

	public function testDestructiveRemovalUsesTheSharedUpdaterLock(): void {
		$fixture                        = $this->fixture();
		$lock                           = new RemovalUpdaterLock();
		$fixture->gateway->pluginDelete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};
		$service                        = new PackageRemovalService(
			$fixture->plugins,
			$fixture->themes,
			$fixture->gateway,
			null,
			$lock
		);

		$result = $service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( array( 'acquire', 'release:fixture-lock' ), $lock->events );
	}

	public function testUpdaterLockContentionChangesNothing(): void {
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
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'operation_in_progress', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
		self::assertSame( 7, $fixture->plugin->getSourceRevision() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function testActivePluginDependentsLeaveTheManagedPackageUnchanged(): void {
		$fixture                                  = $this->fixture();
		$fixture->gateway->pluginActiveDependents = true;

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'active_dependents', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
		self::assertSame( 7, $fixture->plugin->getSourceRevision() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertSame( array( 'plugin_path', 'plugin_shared', 'plugin_dependents' ), $fixture->gateway->events );
	}

	#[DataProvider( 'unsafePluginDeletionStates' )]
	public function testUnsafePluginDeletionPreconditionsChangeNothing(
		bool $safePath,
		bool $sharedDirectory,
		string $outcomeCode
	): void {
		$fixture                                 = $this->fixture();
		$fixture->gateway->pluginSafePath        = $safePath;
		$fixture->gateway->pluginSharedDirectory = $sharedDirectory;

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( $outcomeCode, $result->outcomeCode );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
		self::assertSame( 7, $fixture->plugin->getSourceRevision() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	/** @return list<array{bool, bool, string}> */
	public static function unsafePluginDeletionStates(): array {
		return array(
			array( false, false, 'unsafe_path' ),
			array( true, true, 'shared_plugin_directory' ),
		);
	}

	public function testFailedPluginDeactivationLeavesTheManagedPackageDisabled(): void {
		$fixture                                   = $this->fixture();
		$fixture->gateway->pluginActive            = true;
		$fixture->gateway->deactivationStaysActive = true;

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deactivation_failed', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->getDeploymentPolicy() );
		self::assertFalse( $fixture->plugins->unlinked );
		self::assertNotContains( 'plugin_delete', $fixture->gateway->events );
	}

	public function testReportedDeletionMustAlsoRemoveTheFiles(): void {
		$fixture                        = $this->fixture();
		$fixture->gateway->pluginDelete = static fn (): bool => true;

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'files_still_present', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->getDeploymentPolicy() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	public function testVerifiedAbsenceWinsOverAnUnreliableWordPressReturnValue(): void {
		$fixture                        = $this->fixture();
		$fixture->gateway->pluginDelete = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return false;
		};

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertTrue( $fixture->plugins->unlinked );
	}

	public function testFilesDeletedButManagementUnlinkFailureIsBounded(): void {
		$fixture                         = $this->fixture();
		$fixture->plugins->unlinkFailure = true;
		$fixture->gateway->pluginDelete  = static function () use ( $fixture ): bool {
			$fixture->plugins->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
		);

		self::assertSame( 'management_state_uncertain', $result->outcomeCode );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->plugin->getDeploymentPolicy() );
		self::assertFalse( $fixture->plugins->unlinked );
	}

	/** @return list<array{string}> */
	public static function themeBlockers(): array {
		return array(
			array( 'theme_active' ),
			array( 'theme_parent_in_use' ),
			array( 'theme_has_children' ),
		);
	}

	#[DataProvider( 'themeBlockers' )]
	public function testThemeSafetyBlockerLeavesTheManagedThemeUnchanged( string $blocker ): void {
		$fixture                        = $this->fixture();
		$fixture->gateway->themeBlocker = $blocker;

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-theme', $this->themeInput() )
		);

		self::assertSame( $blocker, $result->outcomeCode );
		self::assertSame( DeploymentPolicy::MANUAL, $fixture->theme->getDeploymentPolicy() );
		self::assertSame( 7, $fixture->theme->getSourceRevision() );
		self::assertFalse( $fixture->themes->unlinked );
		self::assertNotContains( 'theme_delete', $fixture->gateway->events );
	}

	public function testThemeDeletionIsVerifiedBeforeManagementIsUnlinked(): void {
		$fixture                       = $this->fixture();
		$fixture->gateway->themeDelete = static function () use ( $fixture ): bool {
			$fixture->themes->installed = false;
			return true;
		};

		$result = $fixture->service->execute(
			PackageOperation::fromInput( 'unlink-delete-theme', $this->themeInput() )
		);

		self::assertSame( 'deleted', $result->status );
		self::assertSame( DeploymentPolicy::DISABLED, $fixture->theme->getDeploymentPolicy() );
		self::assertSame( 8, $fixture->theme->getSourceRevision() );
		self::assertTrue( $fixture->themes->unlinked );
		self::assertSame( array( 'theme_path', 'theme_blocker', 'theme_delete' ), $fixture->gateway->events );
	}

	public function testDisabledFileModificationsPreventAnyStateChange(): void {
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;
		$fixture = $this->fixture();

		$this->expectException( \RuntimeException::class );
		try {
			$fixture->service->execute(
				PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() )
			);
		} finally {
			self::assertSame( DeploymentPolicy::MANUAL, $fixture->plugin->getDeploymentPolicy() );
			self::assertFalse( $fixture->plugins->unlinked );
		}
	}

	public function testDashboardMapsOnlyBoundedRemovalFailuresToSafeNotices(): void {
		$dashboard  = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$controller = ( new \ReflectionClass( PackageAdminController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( PackageAdminController::class, 'removalFailure' );
		$operation  = PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() );

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
			) as $outcomeCode
		) {
			$dashboard->messages = array();
			$method->invoke(
				$controller,
				$operation,
				$outcomeCode,
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
				'ran_booster_package_removal_' . $outcomeCode,
				$dashboard->messages[0]['code']
			);
			self::assertStringNotContainsString(
				'example/example.php',
				$dashboard->messages[0]['message']
			);
		}
	}

	public function testDashboardRemovalFailureUsesContextualPackageTypeTranslation(): void {
		$dashboard  = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$controller = ( new \ReflectionClass( PackageAdminController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( PackageAdminController::class, 'removalFailure' );
		$operation  = PackageOperation::fromInput( 'unlink-delete-plugin', $this->input() );
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
	private function themeInput( array $overrides = array() ): array {
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
			$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
			$package->setDeploymentPolicy( DeploymentPolicy::MANUAL );
			$package->setSource( PackageSource::BRANCH, 7 );
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

final class RemovalPlugin extends Plugin {
	public static function make( string $identifier ): self {
		return self::fromWpArray(
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

final class RemovalTheme extends Theme {
	public function __construct( string $stylesheet ) {
		$this->stylesheet = $stylesheet;
		$this->name       = 'Example';
	}
}

final class RemovalPluginRepository extends PluginRepository {
	public bool $installed     = true;
	public bool $unlinked      = false;
	public bool $unlinkFailure = false;

	public function __construct( private readonly RemovalPlugin $package ) {
	}

	public function boosterPluginFromFile( $file ) {
		unset( $file );
		return $this->package;
	}

	public function disablePluginForRemoval( Plugin $plugin ): PackageMutationResult {
		$plugin->setDeploymentPolicy( DeploymentPolicy::DISABLED );
		$plugin->setSource( $plugin->getSource(), $plugin->getSourceRevision() + 1 );

		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}

	public function isInstalled( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}

	public function unlink( $file ): PackageMutationResult {
		unset( $file );
		if ( $this->unlinkFailure ) {
			return PackageMutationResult::failed(
				PackageStorageOperation::DELETE,
				'fixture_unlink_failed',
				'Fixture unlink failed.',
				true
			);
		}
		$this->unlinked = true;

		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
}

final class RemovalThemeRepository extends ThemeRepository {
	public bool $installed = true;
	public bool $unlinked  = false;

	public function __construct( private readonly RemovalTheme $package ) {
	}

	public function boosterThemeFromStylesheet( $stylesheet ) {
		unset( $stylesheet );
		return $this->package;
	}

	public function disableThemeForRemoval( Theme $theme ): PackageMutationResult {
		$theme->setDeploymentPolicy( DeploymentPolicy::DISABLED );
		$theme->setSource( $theme->getSource(), $theme->getSourceRevision() + 1 );

		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}

	public function isInstalled( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}

	public function unlink( $stylesheet ): PackageMutationResult {
		unset( $stylesheet );
		$this->unlinked = true;

		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
}

final class RemovalBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var array<string, mixed> */
	private array $records  = array();
	public bool $clearFails = false;

	public function clear( string $type, \RAN\Package $package ): void {
		if ( $this->clearFails ) {
			throw new \RuntimeException( 'Fixture evidence clear failed.' );
		}

		parent::clear( $type, $package );
	}

	protected function readOption(): array {
		return $this->records;
	}

	protected function writeOption( array $records ): bool {
		$this->records = $records;
		return true;
	}
}

final class RemovalGateway implements PackageRemovalGateway {
	public bool $pluginActive            = false;
	public bool $pluginActiveDependents  = false;
	public bool $pluginSharedDirectory   = false;
	public bool $pluginSafePath          = true;
	public bool $themeSafePath           = true;
	public bool $deactivationStaysActive = false;
	public ?string $themeBlocker         = null;
	/** @var callable(): bool|null */
	public $pluginDelete = null;
	/** @var callable(): bool|null */
	public $themeDelete = null;
	/** @var list<string> */
	public array $events = array();

	public function pluginIsActive( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_active';
		return $this->pluginActive;
	}

	public function pluginHasActiveDependents( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_dependents';
		return $this->pluginActiveDependents;
	}

	public function pluginSharesDirectory( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_shared';
		return $this->pluginSharedDirectory;
	}

	public function pluginPathIsSafe( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_path';
		return $this->pluginSafePath;
	}

	public function deactivatePlugin( string $identifier ): void {
		unset( $identifier );
		$this->events[] = 'plugin_deactivate';
		if ( ! $this->deactivationStaysActive ) {
			$this->pluginActive = false;
		}
	}

	public function deletePlugin( string $identifier ): bool {
		unset( $identifier );
		$this->events[] = 'plugin_delete';
		return null === $this->pluginDelete ? false : ( $this->pluginDelete )();
	}

	public function themeDeletionBlocker( string $stylesheet ): ?string {
		unset( $stylesheet );
		$this->events[] = 'theme_blocker';
		return $this->themeBlocker;
	}

	public function themePathIsSafe( string $stylesheet ): bool {
		unset( $stylesheet );
		$this->events[] = 'theme_path';
		return $this->themeSafePath;
	}

	public function deleteTheme( string $stylesheet ): bool {
		unset( $stylesheet );
		$this->events[] = 'theme_delete';
		return null === $this->themeDelete ? false : ( $this->themeDelete )();
	}
}

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

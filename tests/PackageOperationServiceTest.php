<?php

declare(strict_types=1);

namespace Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused collaborators stay beside the service test.

require_once __DIR__ . '/Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/Support/WPError.php';
require_once __DIR__ . '/Support/ProviderProfileAdminControllerWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RAN\Admin\ProviderSettingsPresenter;
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
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\Troubleshooting\TroubleshootingService;
use RAN\WordPress\WordPressUpdaterLock;

final class PackageOperationServiceTest extends TestCase {

	/** @return list<array{string, bool}> */
	public static function operationMatrix(): array {
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

	#[DataProvider( 'operationMatrix' )]
	public function testTheExplicitOperationMatrixClassifiesDeployments( string $action, bool $deployment ): void {
		$operation = PackageOperation::fromInput( $action, $this->input( $action ) );

		self::assertSame( $deployment, $operation->isDeployment() );
		self::assertSame( str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme', $operation->packageType );
	}

	public function testReinstallAfterSaveDeploysTheAuthoritativeEditedPackageAndReturnsToSettings(): void {
		$package = $this->plugin();
		$package->setDeploymentPolicy( DeploymentPolicy::DISABLED );
		$coordinator = new OperationCoordinator();
		$dashboard   = $this->dashboard( $coordinator, $package );

		$redirect = $dashboard->postPackageOperation(
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
		self::assertInstanceOf( PackageOperation::class, $coordinator->lastCommand );
		self::assertSame( 'update', $coordinator->lastCommand->operation );
		self::assertSame( DeploymentPolicy::MANUAL, $coordinator->lastCommand->expectedPackage['deployment_policy'] );
		self::assertTrue( $coordinator->lastCommand->hasExpectedPackage() );
		self::assertSame( 'manual', $package->getDeploymentPolicy()->value );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( 'update', $query['ran_booster_result'] );
		self::assertSame( 'example/example.php', $query['package'] );
	}

	public function testFailedReinstallKeepsTheSavedSettingsNoticeBesideTheDeploymentError(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'failed',
			'correlation_id' => str_repeat( 'f', 32 ),
			'outcome_code'   => 'provider_failed',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse(
			$dashboard->postPackageOperation(
				'edit-plugin',
				$this->input( 'edit-plugin', array( 'reinstall_after_save' => '1' ) )
			)
		);
		self::assertSame( 'info', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'settings were saved', $dashboard->messages[0]['message'] );
		self::assertSame( 'error', $dashboard->messages[1]['type'] );
	}

	public function testLinkEditAndUnlinkUseTheExplicitRepositories(): void {
		$plugin      = $this->plugin();
		$plugins     = new OperationPluginRepository( $plugin );
		$themes      = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$coordinator = new OperationCoordinator();
		$service     = $this->service( $plugins, $themes, $coordinator );

		$link = PackageOperation::fromInput( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) );
		self::assertSame( 'linked', $service->execute( $link )['status'] );
		self::assertSame( 'owner/example', (string) $plugins->stored?->getRepository() );

		$edit = PackageOperation::fromInput( 'edit-plugin', $this->input( 'edit-plugin' ) );
		self::assertSame( 'edited', $service->execute( $edit )['status'] );
		self::assertSame( 'R_example', $plugins->edited['provider_repository_id'] );
		self::assertSame( PackageSource::BRANCH->value, $plugins->edited['expected_source'] );
		self::assertSame( 1, $plugins->edited['expected_source_revision'] );

		$unlink = PackageOperation::fromInput( 'unlink-plugin', $this->input( 'unlink-plugin' ) );
		self::assertSame( 'unlinked', $service->execute( $unlink )['status'] );
		self::assertSame( 'example/example.php', $plugins->unlinked );
		self::assertSame( 0, $coordinator->calls );
	}

	public function testLinkTreatsTheSameReleaseManagedTargetAsAlreadyManaged(): void {
		$plugins                     = new OperationPluginRepository( $this->plugin() );
		$plugins->freshAfterMutation = $this->plugin();
		$plugins->freshAfterMutation->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'release', true, 'existing-access' ) );
		$plugins->freshAfterMutation->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );
		$plugins->freshAfterMutation->setSource( PackageSource::RELEASE_ASSET, 7 );
		$plugins->adoptionResult = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Booster found existing package management data. No package changes were made.'
		);
		$dashboard               = new Dashboard(
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

		$redirect = $dashboard->postPackageOperation(
			'install-plugin',
			$this->input( 'install-plugin', array( 'dry-run' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
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
		$notice = $this->invokePackageSuccessNotice( $dashboard, 'plugin' );
		self::assertSame(
			array(
				'operation'  => 'already-managed',
				'identifier' => 'example/example.php',
			),
			$notice
		);
		self::assertSame( 'success', $dashboard->messages[0]['type'] );
		self::assertSame( 'Plugin is already managed by Booster. No package settings were changed.', $dashboard->messages[0]['message'] );
	}

	public function testLinkKeepsMismatchedExistingManagementAsStorageFailure(): void {
		$plugins                     = new OperationPluginRepository( $this->plugin() );
		$plugins->freshAfterMutation = $this->plugin();
		$plugins->freshAfterMutation->setRepository( new ManagedRepository( 'gh', 'owner/other', 'R_other', 'main' ) );
		$plugins->adoptionResult = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Booster found existing package management data. No package changes were made.'
		);
		$service                 = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);

		try {
			$service->execute(
				PackageOperation::fromInput( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
			);
			self::fail( 'A mismatched managed package must not be reported as linked.' );
		} catch ( \RAN\Storage\PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_adoption_conflict', $failure->getDiagnosticId() );
		}
	}

	public function testLinkAndEditUseTheSharedUpdaterLock(): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$lock    = new OperationUpdaterLock();
		$service = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator(),
			$lock
		);

		$service->execute(
			PackageOperation::fromInput( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
		);
		$service->execute(
			PackageOperation::fromInput( 'edit-plugin', $this->input( 'edit-plugin' ) )
		);

		self::assertSame(
			array( 'acquire', 'release:fixture-lock', 'acquire', 'release:fixture-lock' ),
			$lock->events
		);
	}

	#[DataProvider( 'linkAndEditActions' )]
	public function testUpdaterLockContentionPreventsLinkAndEdit( string $action ): void {
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
			$service->execute( PackageOperation::fromInput( $action, $this->input( $action, array( 'dry-run' => '1' ) ) ) );
			self::fail( 'Lock contention must reject the package mutation.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Another package operation is in progress.', $failure->getMessage() );
			self::assertNull( $plugins->stored );
			self::assertSame( array(), $plugins->edited );
		}
	}

	/** @return list<array{string}> */
	public static function linkAndEditActions(): array {
		return array(
			array( 'install-plugin' ),
			array( 'edit-plugin' ),
		);
	}

	/** @return list<array{string}> */
	public static function editActions(): array {
		return array(
			array( 'edit-plugin' ),
			array( 'edit-theme' ),
		);
	}

	#[DataProvider( 'editActions' )]
	public function testEditRejectsMissingMalformedAndStaleExpectedSnapshotsBeforeWriting( string $action ): void {
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
				$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
			}
			$plugins = new OperationPluginRepository( $this->plugin() );
			$themes  = new OperationThemeRepository( $package instanceof Theme ? $package : new OperationTheme( 'example' ) );
			if ( $package instanceof Plugin ) {
				$plugins = new OperationPluginRepository( $package );
			}
			$service = $this->service( $plugins, $themes, new OperationCoordinator() );
			$result  = $service->execute( PackageOperation::fromInput( $action, $change( $this->input( $action ) ) ) );

			self::assertSame( 'conflict', $result['status'], $case );
			self::assertSame( $package, $result['package'], $case );
			self::assertSame( array(), 'edit-plugin' === $action ? $plugins->edited : $themes->edited, $case );
		}
	}

	public function testDashboardKeepsAStaleEditOnTheFormWithAPersistentConflict(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$input     = $this->input( 'edit-plugin', array( 'expected_branch' => 'older-branch' ) );

		self::assertFalse( $dashboard->postPackageOperation( 'edit-plugin', $input ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_package_edit_conflict', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'No settings were saved.', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'resubmit your attempted changes', $dashboard->messages[0]['message'] );

		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function testUpdaterLockReleaseFailureDoesNotReportLinkSuccess(): void {
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
			PackageOperation::fromInput( 'install-plugin', $this->input( 'install-plugin', array( 'dry-run' => '1' ) ) )
		);
	}

	public function testThemeLinkEditAndUnlinkUseTheExplicitRepositories(): void {
		$plugins     = new OperationPluginRepository( $this->plugin() );
		$themes      = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$coordinator = new OperationCoordinator();
		$service     = $this->service( $plugins, $themes, $coordinator );

		$link = PackageOperation::fromInput( 'install-theme', $this->input( 'install-theme', array( 'dry-run' => '1' ) ) );
		self::assertSame( 'linked', $service->execute( $link )['status'] );
		self::assertSame( 'owner/example', (string) $themes->stored?->getRepository() );

		$edit = PackageOperation::fromInput( 'edit-theme', $this->input( 'edit-theme' ) );
		self::assertSame( 'edited', $service->execute( $edit )['status'] );
		self::assertSame( 'R_example', $themes->edited['provider_repository_id'] );

		$unlink = PackageOperation::fromInput( 'unlink-theme', $this->input( 'unlink-theme' ) );
		self::assertSame( 'unlinked', $service->execute( $unlink )['status'] );
		self::assertSame( 'example', $themes->unlinked );
		self::assertSame( 0, $coordinator->calls );
	}

	public function testReleaseManagedPackageRetainsItsRepositoryIdentityWhileUpdatingAccessAndPolicy(): void {
		$plugin = $this->plugin();
		$plugin->setRepository( new ManagedRepository( 'gh', 'owner/release', 'R_release', 'stable', true, 'old-access' ) );
		$plugin->setSubdirectory( 'packages/example' );
		$plugin->setSource( PackageSource::RELEASE_ASSET, 2 );
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
				'expected_subdirectory'           => 'packages/example',
				'expected_private'                => '1',
				'expected_package_slug'           => 'example',
				'expected_deployment_policy'      => DeploymentPolicy::MANUAL->value,
				'expected_source'                 => PackageSource::RELEASE_ASSET->value,
				'expected_source_revision'        => 2,
			)
		);
		self::assertSame( 'edited', $service->execute( PackageOperation::fromInput( 'edit-plugin', $input ) )['status'] );
		self::assertSame( 'gh', $plugins->edited['provider'] );
		self::assertSame( 'owner/release', (string) $plugins->edited['repository'] );
		self::assertSame( 'R_release', $plugins->edited['provider_repository_id'] );
		self::assertSame( 'stable', $plugins->edited['branch'] );
		self::assertTrue( $plugins->edited['private'] );
		self::assertSame( 'packages/example', $plugins->edited['subdirectory'] );
		self::assertSame( 'new-access', $plugins->edited['credential_id'] );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $plugins->edited['deployment_policy'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $plugins->edited['expected_source'] );
		self::assertSame( 2, $plugins->edited['expected_source_revision'] );
		self::assertSame(
			'unlinked',
			$service->execute(
				PackageOperation::fromInput(
					'unlink-plugin',
					$this->input( 'unlink-plugin', array( 'expected_source_revision' => '2' ) )
				)
			)['status']
		);
		self::assertSame( 'example/example.php', $plugins->unlinked );
	}

	public function testLinkOnlyReturnsTheDisabledPackageReadBack(): void {
		$plugins   = new OperationPluginRepository( $this->plugin() );
		$service   = $this->service(
			$plugins,
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			new OperationCoordinator()
		);
		$operation = PackageOperation::fromInput(
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
		self::assertSame( DeploymentPolicy::DISABLED, $result['package']->getDeploymentPolicy() );
		self::assertSame( $plugins->stored, $result['package'] );
	}

	public function testLinkOnlyPreservesMixedCaseInstalledPackageSlugs(): void {
		$plugins = new OperationPluginRepository( $this->plugin() );
		$themes  = new OperationThemeRepository( new OperationTheme( 'tnyGmaps' ) );
		$service = $this->service( $plugins, $themes, new OperationCoordinator() );

		$pluginInput = $this->input(
			'install-plugin',
			array(
				'dry-run'      => '1',
				'package_slug' => 'tnyGmaps',
			)
		);
		$themeInput  = $this->input(
			'install-theme',
			array(
				'dry-run'      => '1',
				'package_slug' => 'tnyGmaps',
			)
		);

		self::assertSame( 'linked', $service->execute( PackageOperation::fromInput( 'install-plugin', $pluginInput ) )['status'] );
		self::assertSame( 'linked', $service->execute( PackageOperation::fromInput( 'install-theme', $themeInput ) )['status'] );
		self::assertSame( 'tnyGmaps', $plugins->requestedSlug );
		self::assertSame( 'tnyGmaps', $themes->requestedSlug );
	}

	public function testActualInstallAndUpdateUseOnlyTheCoordinator(): void {
		$coordinator = new OperationCoordinator();
		$service     = $this->service(
			new OperationPluginRepository( $this->plugin() ),
			new OperationThemeRepository( new OperationTheme( 'example' ) ),
			$coordinator
		);

		foreach ( array( 'install-plugin', 'install-theme', 'update-plugin', 'update-theme' ) as $action ) {
			$result = $service->execute( PackageOperation::fromInput( $action, $this->input( $action ) ) );
			self::assertSame( 'succeeded', $result['status'] );
			self::assertSame( 'deployed', $result['outcome_code'] );
			self::assertSame( str_repeat( 'a', 32 ), $result['correlation_id'] );
			self::assertInstanceOf( Package::class, $result['package'] );
		}
		self::assertSame( 4, $coordinator->calls );
	}

	public function testTerminalDeploymentFailureReturnsOnlyFixedSafeData(): void {
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
			$service->execute( PackageOperation::fromInput( 'install-plugin', $this->input( 'install-plugin' ) ) )
		);
	}

	/** @return list<array{string, string, string, string|null}> */
	public static function deploymentRedirectMatrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins', 'install', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes', 'install', 'example' ),
			array( 'update-plugin', 'ran-booster-plugins', 'update', null ),
			array( 'update-theme', 'ran-booster-themes', 'update', null ),
		);
	}

	#[DataProvider( 'deploymentRedirectMatrix' )]
	public function testDashboardReturnsSignedMatchingRedirectAfterDeploymentSuccess(
		string $action,
		string $page,
		string $result,
		?string $settingsPackage
	): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->postPackageOperation( $action, $this->input( $action ) );

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( $result, $query['ran_booster_result'] );
		self::assertArrayHasKey( '_ran_booster_notice_nonce', $query );
		self::assertArrayNotHasKey( 'open_picker', $query );
		if ( null === $settingsPackage ) {
			self::assertArrayNotHasKey( 'package', $query );
		} else {
			self::assertSame( $settingsPackage, $query['package'] );
		}
	}

	/** @return list<array{string, string, string}> */
	public static function repeatInstallRedirectMatrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins-create', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes-create', 'example' ),
		);
	}

	#[DataProvider( 'repeatInstallRedirectMatrix' )]
	public function testDashboardReturnsSignedCreateRedirectForRepeatInstall( string $action, string $page, string $identifier ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->postPackageOperation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
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

	#[DataProvider( 'repeatInstallRedirectMatrix' )]
	public function testRepeatCreateRedirectConsumesSignedPlainTextNotice(
		string $action,
		string $page,
		string $identifier
	): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$redirect  = $dashboard->postPackageOperation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);
		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		$_GET  = $query;

		$type    = str_ends_with( $action, 'plugin' ) ? 'plugin' : 'theme';
		$success = $this->invokePackageSuccessNotice( $dashboard, $type );

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

	public function testForgedGetMarkerCannotCreateSuccessNotice(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'install',
			'ran_booster_package'       => 'example/example.php',
			'_ran_booster_notice_nonce' => 'forged',
		);

		$success = $this->invokePackageSuccessNotice( $dashboard, 'plugin' );

		self::assertNull( $success );
		self::assertSame( array(), $dashboard->messages );
	}

	public function testForgedAlreadyManagedMarkerCannotCreateSuccessNotice(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'already-managed',
			'ran_booster_package'       => 'example/example.php',
			'_ran_booster_notice_nonce' => 'forged',
		);

		self::assertNull( $this->invokePackageSuccessNotice( $dashboard, 'plugin' ) );
		self::assertSame( array(), $dashboard->messages );
	}

	public function testSignedSuccessNoticeCannotBeCrossBoundAcrossTypeOperationOrPackage(): void {
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

			$this->invokePackageSuccessNotice( $dashboard, $type );

			self::assertSame( array(), $dashboard->messages, $case );
		}
	}

	#[DataProvider( 'editActions' )]
	public function testEditReturnsADistinctAuthoritativeRepositoryReread( string $action ): void {
		$pluginOriginal = $this->plugin();
		$pluginFresh    = $this->plugin();
		$themeOriginal  = new OperationTheme( 'example' );
		$themeOriginal->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$themeFresh = new OperationTheme( 'example' );
		$themeFresh->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$plugins                     = new OperationPluginRepository( $pluginOriginal );
		$themes                      = new OperationThemeRepository( $themeOriginal );
		$plugins->freshAfterMutation = $pluginFresh;
		$themes->freshAfterMutation  = $themeFresh;

		$result = $this->service( $plugins, $themes, new OperationCoordinator() )
			->execute( PackageOperation::fromInput( $action, $this->input( $action ) ) );

		$original = 'edit-plugin' === $action ? $pluginOriginal : $themeOriginal;
		$fresh    = 'edit-plugin' === $action ? $pluginFresh : $themeFresh;
		self::assertSame( 'edited', $result['status'] );
		self::assertSame( $fresh, $result['package'] );
		self::assertNotSame( $original, $result['package'] );
	}

	public function testFailedPostDoesNotReusePickerAutoOpenMarker(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array( 'open_picker' => '1' );
		$_POST     = array( 'ran_booster' => $this->input( 'install-plugin' ) );
		$method    = new \ReflectionMethod( Dashboard::class, 'requestedOpenPicker' );

		self::assertFalse( $method->invoke( $dashboard ) );

		$_POST = array();
		self::assertTrue( $method->invoke( $dashboard ) );

		$_GET = array();
	}

	public function testSignedThemeUpdateMarkerAddsFixedSuccessNoticeWithoutActivationAction(): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );
		$_GET      = array(
			'ran_booster_result'        => 'update',
			'ran_booster_package'       => 'example',
			'_ran_booster_notice_nonce' => \RAN\wp_create_nonce( 'ran-booster-package-success|theme|update|example' ),
		);

		$this->invokePackageSuccessNotice( $dashboard, 'theme' );

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
	public static function updateRedirectMatrix(): array {
		return array(
			array( 'update-plugin', 'ran-booster-plugins' ),
			array( 'update-theme', 'ran-booster-themes' ),
		);
	}

	#[DataProvider( 'updateRedirectMatrix' )]
	public function testUpdatesIgnoreRepeatInstallIntent( string $action, string $page ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->postPackageOperation(
			$action,
			$this->input( $action, array( 'install_another' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( 'update', $query['ran_booster_result'] );
		self::assertArrayNotHasKey( 'provider', $query );
		self::assertArrayNotHasKey( 'open_picker', $query );
	}

	#[DataProvider( 'updateRedirectMatrix' )]
	public function testUpdatesPreserveNormalizedPackageListFilters( string $action, string $page ): void {
		$_GET      = array(
			's'        => ' release ',
			'provider' => 'GH',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
			'unsafe'   => '<script>',
		);
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->postPackageOperation( $action, $this->input( $action ) );
		$_GET     = array();

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( 'release', $query['s'] );
		self::assertSame( 'gh', $query['provider'] );
		self::assertSame( 'release_asset', $query['source'] );
		self::assertSame( 'automatic', $query['policy'] );
		self::assertArrayNotHasKey( 'unsafe', $query );
	}

	#[DataProvider( 'updateRedirectMatrix' )]
	public function testSettingsReinstallReturnsToTheSamePackageSettingsPage( string $action, string $page ): void {
		$dashboard = $this->dashboard( new OperationCoordinator() );

		$redirect = $dashboard->postPackageOperation(
			$action,
			$this->input( $action, array( 'return_to_settings' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame(
			str_ends_with( $action, 'plugin' ) ? 'example/example.php' : 'example',
			$query['package']
		);
	}

	public function testDashboardKeepsTerminalFailureOnTheFormWithSafeActivityMessage(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'           => 'failed',
			'correlation_id'   => str_repeat( 'c', 32 ),
			'outcome_code'     => 'provider_failed',
			'provider_message' => 'secret-canary-token',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse(
			$dashboard->postPackageOperation(
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

	public function testMalformedDeploymentCorrelationCannotFallThroughToRemovalCopy(): void {
		$coordinator         = new OperationCoordinator();
		$coordinator->result = array(
			'status'         => 'failed',
			'correlation_id' => 'invalid-correlation',
			'outcome_code'   => 'deletion_failed',
		);
		$dashboard           = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->postPackageOperation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 400, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_manual_action_failed', $dashboard->messages[0]['code'] );
		self::assertStringNotContainsString( 'disabled in Booster', $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function testDashboardExplainsAnAlreadyActiveDeployment(): void {
		$coordinator          = new OperationCoordinator();
		$coordinator->failure = DeploymentStorageFailure::contention(
			array(
				'id'             => 42,
				'correlation_id' => str_repeat( 'd', 32 ),
				'state'          => 'running',
				'package_type'   => 'plugin',
				'package_slug'   => 'example',
			)
		);
		$dashboard            = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->postPackageOperation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'info', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_deployment_active', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'plugin example in state running', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'attempt=42', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'reference=' . str_repeat( 'd', 32 ), $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	public function testDashboardExplainsThatAnUnresolvedDeploymentIsNotRunning(): void {
		$coordinator          = new OperationCoordinator();
		$coordinator->failure = DeploymentStorageFailure::contention(
			array(
				'id'             => 43,
				'correlation_id' => str_repeat( 'e', 32 ),
				'state'          => 'needs_attention',
				'package_type'   => 'plugin',
				'package_slug'   => 'example',
			)
		);
		$dashboard            = $this->dashboard( $coordinator );

		self::assertFalse( $dashboard->postPackageOperation( 'update-plugin', $this->input( 'update-plugin' ) ) );
		self::assertSame( 409, $GLOBALS['ran_booster_test_status_header'] );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_deployment_active', $dashboard->messages[0]['code'] );
		self::assertStringContainsString( 'earlier deployment for the plugin example could not be verified', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'not currently running', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Open its recovery details', $dashboard->messages[0]['message'] );
		unset( $GLOBALS['ran_booster_test_status_header'] );
	}

	/** @return list<array{string, string, string}> */
	public static function linkedPackageSettingsRedirectMatrix(): array {
		return array(
			array( 'install-plugin', 'ran-booster-plugins', 'example/example.php' ),
			array( 'install-theme', 'ran-booster-themes', 'example' ),
		);
	}

	#[DataProvider( 'linkedPackageSettingsRedirectMatrix' )]
	public function testStandardDryRunLinkRedirectsToPackageSettings(
		string $action,
		string $page,
		string $identifier
	): void {
		$standard = $this->dashboard( new OperationCoordinator() );
		$redirect = $standard->postPackageOperation(
			$action,
			$this->input( $action, array( 'dry-run' => '1' ) )
		);

		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
		self::assertSame( $page, $query['page'] );
		self::assertSame( $identifier, $query['package'] );
		self::assertSame( 'install', $query['ran_booster_result'] );
		self::assertSame( $identifier, $query['ran_booster_package'] );
	}

	public function testRepeatDryRunLinkRedirectsToCreate(): void {
		$repeat   = $this->dashboard( new OperationCoordinator() );
		$redirect = $repeat->postPackageOperation(
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
		$query = $this->redirectQuery( $redirect );
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
			$this->invokePackageSuccessNotice( $repeat, 'plugin' )
		);
	}

	public function testLinkingTheInstalledBoosterPluginIsRejectedBeforeStorage(): void {
		$plugin  = $this->plugin( 'ran-booster/ran-booster.php' );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service( $plugins, new OperationThemeRepository( new OperationTheme( 'example' ) ), new OperationCoordinator() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cannot manage its own plugin files' );
		try {
			$service->execute(
				PackageOperation::fromInput(
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

	public function testSimilarPluginNameCanStillBeLinked(): void {
		$plugin  = $this->plugin( 'ran-booster-extra/ran-booster.php' );
		$plugins = new OperationPluginRepository( $plugin );
		$service = $this->service( $plugins, new OperationThemeRepository( new OperationTheme( 'example' ) ), new OperationCoordinator() );

		$result = $service->execute(
			PackageOperation::fromInput(
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
	public static function malformedOperations(): array {
		return array(
			array( 'remove-plugin', array() ),
			array( 'install-plugin', array( 'repository' => array() ) ),
			array( 'update-theme', array( 'stylesheet' => array() ) ),
			array( 'unlink-plugin', array() ),
		);
	}

	/** @param array<string, mixed> $overrides */
	#[DataProvider( 'malformedOperations' )]
	public function testMalformedOperationsAreRejected( string $action, array $overrides ): void {
		$input = array_merge( $this->input( $action ), $overrides );
		if ( 'unlink-plugin' === $action ) {
			unset( $input['file'] );
		}

		$this->expectException( InvalidArgumentException::class );
		PackageOperation::fromInput( $action, $input );
	}

	/** @return array<string, array{string, string, string, string}> */
	public static function removalRedirects(): array {
		return array(
			'unlink plugin' => array( 'unlink-plugin', 'ran-booster-plugins', 'unlink', 'example/example.php' ),
			'delete plugin' => array( 'unlink-delete-plugin', 'ran-booster-plugins', 'unlink-and-delete', 'example/example.php' ),
			'unlink theme'  => array( 'unlink-theme', 'ran-booster-themes', 'unlink', 'example' ),
			'delete theme'  => array( 'unlink-delete-theme', 'ran-booster-themes', 'unlink-and-delete', 'example' ),
		);
	}

	#[DataProvider( 'removalRedirects' )]
	public function testDashboardReturnsEachRemovalToItsOwnershipIndex(
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

		$redirect = $dashboard->postPackageOperation( $action, $this->input( $action ) );
		self::assertIsString( $redirect );
		$query = $this->redirectQuery( $redirect );
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

	public function testDashboardRedactsUnexpectedOperationFailures(): void {
		$plugins                = new OperationPluginRepository( $this->plugin() );
		$plugins->unlinkFailure = new \RuntimeException( 'secret-canary-token' );
		$themes                 = new OperationThemeRepository( new OperationTheme( 'example' ) );
		$dashboard              = new Dashboard(
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

		self::assertFalse( $dashboard->postPackageOperation( 'unlink-plugin', $this->input( 'unlink-plugin' ) ) );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_manual_action_failed', $dashboard->messages[0]['code'] );
		self::assertStringNotContainsString( 'secret-canary-token', $dashboard->messages[0]['message'] );
	}

	/** @param array<string, mixed> $overrides */
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
		?OperationUpdaterLock $updaterLock = null
	): PackageOperationService {
		$updaterLock ??= new OperationUpdaterLock();

		return new PackageOperationService(
			$plugins,
			$themes,
			$coordinator,
			new PackageRemovalService( $plugins, $themes, new OperationRemovalGateway(), null, $updaterLock ),
			$updaterLock
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
	private function invokePackageSuccessNotice( Dashboard $dashboard, string $type ): ?array {
		$method = new \ReflectionMethod( Dashboard::class, 'addPackageSuccessNotice' );
		$result = $method->invoke( $dashboard, $type );

		/** @var array{operation: string, identifier: string}|null $result */
		return $result;
	}

	/** @return array<string, string> */
	private function redirectQuery( string $redirect ): array {
		$query = parse_url( $redirect, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress runtime is available in this unit test.
		self::assertIsString( $query );
		parse_str( $query, $parameters );

		/** @var array<string, string> $parameters */
		return $parameters;
	}

	private function plugin( string $file = 'example/example.php' ): Plugin {
		$plugin     = Plugin::fromWpArray(
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
		$plugin->setRepository( $repository );

		return $plugin;
	}
}

final class OperationCoordinator extends DeploymentCoordinator {
	public int $calls                     = 0;
	public ?\Throwable $failure           = null;
	public ?PackageOperation $lastCommand = null;
	/** @var array<string, mixed> */
	public array $result = array(
		'status'         => 'succeeded',
		'correlation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'outcome_code'   => 'deployed',
	);
	public function __construct() {}
	public function executeManual( PackageOperation $command ): array {
		++$this->calls;
		$this->lastCommand = $command;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result;
	}
}

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

final class OperationPluginRepository extends PluginRepository {
	public ?Plugin $stored                                = null;
	/** @var array<string, mixed> */ public array $edited = array();
	public bool $installed                                = true;
	public ?string $unlinked                              = null;
	public ?string $requestedSlug                         = null;
	public ?\Throwable $unlinkFailure                     = null;
	public ?Plugin $freshAfterMutation                    = null;
	public ?PackageMutationResult $adoptionResult         = null;
	public function __construct( private Plugin $package ) {}
	public function fromSlug( $slug ) {
		$this->requestedSlug = (string) $slug;
		return $this->package; }
	public function boosterPluginFromFile( $file ) {
		return null !== $this->freshAfterMutation && ( null !== $this->stored || array() !== $this->edited )
			? $this->freshAfterMutation
			: $this->package; }
	public function store( Plugin $plugin ): PackageMutationResult {
		$this->stored = $plugin;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function adopt( Plugin $plugin ): PackageMutationResult {
		$this->stored = $plugin;
		return $this->adoptionResult ?? PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function editPlugin( $file, $input ): PackageMutationResult {
		$this->edited = $input;
		$this->package->setRepository( $input['repository'] );
		$this->package->setDeploymentPolicy( DeploymentPolicy::fromDatabase( $input['deployment_policy'] ) );
		$this->package->setSubdirectory( $input['subdirectory'] );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function disablePluginForRemoval( Plugin $plugin ): PackageMutationResult {
		unset( $plugin );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function unlink( $file ): PackageMutationResult {
		if ( null !== $this->unlinkFailure ) {
			throw $this->unlinkFailure;
		}
		$this->unlinked = (string) $file;
		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
	public function isInstalled( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}
}

final class OperationThemeRepository extends ThemeRepository {
	public ?Theme $stored                                 = null;
	/** @var array<string, mixed> */ public array $edited = array();
	public bool $installed                                = true;
	public ?string $unlinked                              = null;
	public ?string $requestedSlug                         = null;
	public ?Theme $freshAfterMutation                     = null;
	public function __construct( private Theme $package ) {}
	public function fromSlug( $slug ) {
		$this->requestedSlug = (string) $slug;
		return $this->package; }
	public function boosterThemeFromStylesheet( $stylesheet ) {
		return null !== $this->freshAfterMutation && ( null !== $this->stored || array() !== $this->edited )
			? $this->freshAfterMutation
			: $this->package; }
	public function store( Theme $theme ): PackageMutationResult {
		$this->stored = $theme;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function adopt( Theme $theme ): PackageMutationResult {
		$this->stored = $theme;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
	public function editTheme( $stylesheet, $input ): PackageMutationResult {
		$this->edited = $input;
		$this->package->setRepository( $input['repository'] );
		$this->package->setDeploymentPolicy( DeploymentPolicy::fromDatabase( $input['deployment_policy'] ) );
		$this->package->setSubdirectory( $input['subdirectory'] );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function disableThemeForRemoval( Theme $theme ): PackageMutationResult {
		unset( $theme );
		return PackageMutationResult::changed( PackageStorageOperation::UPDATE );
	}
	public function unlink( $stylesheet ): PackageMutationResult {
		$this->unlinked = (string) $stylesheet;
		return PackageMutationResult::changed( PackageStorageOperation::DELETE );
	}
	public function isInstalled( string $identifier ): bool {
		unset( $identifier );
		return $this->installed;
	}
}

final class OperationTheme extends Theme {
	public function __construct( string $stylesheet ) {
		$this->stylesheet = $stylesheet;
		$this->name       = 'Example';
	}
}

final class OperationRemovalGateway implements PackageRemovalGateway {
	public function pluginIsActive( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function pluginHasActiveDependents( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function pluginSharesDirectory( string $identifier ): bool {
		unset( $identifier );
		return false;
	}

	public function pluginPathIsSafe( string $identifier ): bool {
		unset( $identifier );
		return true;
	}

	public function deactivatePlugin( string $identifier ): void {
		unset( $identifier );
	}

	public function deletePlugin( string $identifier ): bool {
		unset( $identifier );
		return true;
	}

	public function themeDeletionBlocker( string $stylesheet ): ?string {
		unset( $stylesheet );
		return null;
	}

	public function themePathIsSafe( string $stylesheet ): bool {
		unset( $stylesheet );
		return true;
	}

	public function deleteTheme( string $stylesheet ): bool {
		unset( $stylesheet );
		return true;
	}
}

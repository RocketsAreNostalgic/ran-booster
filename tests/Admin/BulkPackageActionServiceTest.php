<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused service fixtures stay beside the contract tests.

require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPPluginDependencies.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageActionFailure;
use RAN\Admin\BulkPackageActionService;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;

final class BulkPackageActionServiceTest extends TestCase {

	private WordPressUpdaterLock $updaterLock;

	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite']         = false;
		$GLOBALS['ran_booster_bulk_active_plugins']                      = array();
		$GLOBALS['ran_booster_bulk_activation_results']                  = array();
		$GLOBALS['ran_booster_bulk_activation_redirects']                = array();
		$GLOBALS['ran_booster_bulk_activation_errors_with_active_state'] = array();
		$GLOBALS['ran_booster_bulk_deactivation_failures']               = array();
		$GLOBALS['ran_booster_bulk_dependency_initializations']          = 0;
		$GLOBALS['ran_booster_bulk_plugins_with_active_dependents']      = array();
		$GLOBALS['ran_booster_repository_admin_capabilities']            = array();
		$this->updaterLock = $this->createStub( WordPressUpdaterLock::class );
		$this->updaterLock->method( 'acquire' )->willReturn( 'bulk-fixture-lock' );
		$this->updaterLock->method( 'release' )->willReturn( true );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_bulk_active_plugins'],
			$GLOBALS['ran_booster_bulk_activation_results'],
			$GLOBALS['ran_booster_bulk_activation_redirects'],
			$GLOBALS['ran_booster_bulk_activation_errors_with_active_state'],
			$GLOBALS['ran_booster_bulk_deactivation_failures'],
			$GLOBALS['ran_booster_bulk_dependency_initializations'],
			$GLOBALS['ran_booster_bulk_plugins_with_active_dependents'],
			$GLOBALS['ran_booster_repository_admin_capabilities']
		);
	}

	public function testPluginActivationActionsAreRejectedForThemes(): void {
		foreach ( BulkPackageAction::pluginActivationOperations() as $operation ) {
			$this->expectInvalidBulkAction(
				'theme',
				array(
					'bulk_action' => $operation,
					'identifiers' => array( 'example-theme' ),
				)
			);
		}
	}

	public function testActivationChangesEligiblePluginsAndReportsSafePartialResults(): void {
		$plugins                                    = new BulkActionPluginRepository(
			array(
				'already/already.php' => BulkActionPackage::make( 'already/already.php', 'fixture' ),
				'broken/broken.php'   => BulkActionPackage::make( 'broken/broken.php', 'fixture' ),
				'enable/enable.php'   => BulkActionPackage::make( 'enable/enable.php', 'fixture' ),
				'throws/throws.php'   => BulkActionPackage::make( 'throws/throws.php', 'fixture' ),
			)
		);
		$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'already/already.php' );
		$GLOBALS['ran_booster_bulk_activation_results']['broken/broken.php'] = new \WP_Error(
			'plugin_error',
			'Secret-bearing fixture error.'
		);
		$GLOBALS['ran_booster_bulk_activation_results']['throws/throws.php'] = new \RuntimeException(
			'Secret-bearing fixture exception.'
		);

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::ACTIVATE_PLUGINS,
					'identifiers' => array(
						'enable/enable.php',
						'already/already.php',
						'broken/broken.php',
						'stale/stale.php',
						'throws/throws.php',
					),
				)
			)
		);

		self::assertSame( 1, $result->changed );
		self::assertSame( 1, $result->unchanged );
		self::assertSame(
			array(
				'activation_failed' => 2,
				'stale'             => 1,
			),
			$result->skippedByReason
		);
		self::assertContains( 'enable/enable.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
		self::assertStringContainsString(
			'plugins.php?error=true&plugin=enable%2Fenable.php',
			$GLOBALS['ran_booster_bulk_activation_redirects']['enable/enable.php']
		);
		self::assertSame( DeploymentPolicy::MANUAL, $plugins->packages['enable/enable.php']->getDeploymentPolicy() );
	}

	public function testActivationSkipsAPluginWithoutItsExactMetaCapability(): void {
		$plugins = new BulkActionPluginRepository(
			array( 'denied/denied.php' => BulkActionPackage::make( 'denied/denied.php', 'fixture' ) )
		);
		$GLOBALS['ran_booster_repository_admin_capabilities']['activate_plugin'] = false;

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::ACTIVATE_PLUGINS,
					'identifiers' => array( 'denied/denied.php' ),
				)
			)
		);

		self::assertSame( 0, $result->changed );
		self::assertSame( array( 'permission' => 1 ), $result->skippedByReason );
		self::assertNotContains( 'denied/denied.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
	}

	public function testActivationUsesLiveStateWhenCoreReturnsAnErrorAfterActivating(): void {
		$plugins = new BulkActionPluginRepository(
			array( 'noisy/noisy.php' => BulkActionPackage::make( 'noisy/noisy.php', 'fixture' ) )
		);
		$GLOBALS['ran_booster_bulk_activation_results']['noisy/noisy.php'] = new \WP_Error(
			'unexpected_output',
			'Secret-bearing fixture output.'
		);
		$GLOBALS['ran_booster_bulk_activation_errors_with_active_state']   = array( 'noisy/noisy.php' );

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::ACTIVATE_PLUGINS,
					'identifiers' => array( 'noisy/noisy.php' ),
				)
			)
		);

		self::assertSame( 1, $result->changed );
		self::assertSame( 0, $result->skipped() );
		self::assertContains( 'noisy/noisy.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
	}

	public function testDeactivationSkipsAPluginWithoutItsExactMetaCapability(): void {
		$plugins                                    = new BulkActionPluginRepository(
			array( 'denied/denied.php' => BulkActionPackage::make( 'denied/denied.php', 'fixture' ) )
		);
		$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'denied/denied.php' );
		$GLOBALS['ran_booster_repository_admin_capabilities']['deactivate_plugin'] = false;

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::DEACTIVATE_PLUGINS,
					'identifiers' => array( 'denied/denied.php' ),
				)
			)
		);

		self::assertSame( 0, $result->changed );
		self::assertSame( array( 'permission' => 1 ), $result->skippedByReason );
		self::assertContains( 'denied/denied.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
	}

	public function testDeactivationProtectsBoosterDependentsAndVerifiesTheWordPressPostcondition(): void {
		$plugins                                    = new BulkActionPluginRepository(
			array(
				'active/active.php'       => BulkActionPackage::make( 'active/active.php', 'fixture' ),
				'already/already.php'     => BulkActionPackage::make( 'already/already.php', 'fixture' ),
				'required/required.php'   => BulkActionPackage::make( 'required/required.php', 'fixture' ),
				'resistant/resistant.php' => BulkActionPackage::make( 'resistant/resistant.php', 'fixture' ),
			)
		);
		$GLOBALS['ran_booster_bulk_active_plugins'] = array(
			'active/active.php',
			'ran-booster/ran-booster.php',
			'required/required.php',
			'resistant/resistant.php',
		);
		$GLOBALS['ran_booster_bulk_deactivation_failures']          = array( 'resistant/resistant.php' );
		$GLOBALS['ran_booster_bulk_plugins_with_active_dependents'] = array( 'required/required.php' );

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::DEACTIVATE_PLUGINS,
					'identifiers' => array(
						'active/active.php',
						'already/already.php',
						'ran-booster/ran-booster.php',
						'required/required.php',
						'resistant/resistant.php',
					),
				)
			)
		);

		self::assertSame( 1, $result->changed );
		self::assertSame( 1, $result->unchanged );
		self::assertSame(
			array(
				'active_dependents'   => 1,
				'deactivation_failed' => 1,
				'self_deactivation'   => 1,
			),
			$result->skippedByReason
		);
		self::assertNotContains( 'active/active.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
		self::assertContains( 'ran-booster/ran-booster.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
		self::assertContains( 'required/required.php', $GLOBALS['ran_booster_bulk_active_plugins'] );
		self::assertSame( 1, $GLOBALS['ran_booster_bulk_dependency_initializations'] );
	}

	public function testDisabledPolicyWorksWithAnUnavailableProviderAndChangesOnlyPolicySnapshots(): void {
		$plugin  = BulkActionPackage::make( 'example/example.php', 'missing-provider' );
		$plugins = new BulkActionPluginRepository( array( 'example/example.php' => $plugin ) );

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'policy-disabled',
					'identifiers' => array( 'example/example.php' ),
				)
			)
		);

		self::assertSame( 1, $result->changed );
		self::assertSame( DeploymentPolicy::DISABLED, $plugins->policy );
		self::assertSame( 'missing-provider', $plugins->snapshots[0]['provider'] );
		self::assertSame( array( 'package', 'repository', 'branch', 'deployment_policy', 'provider', 'provider_repository_id', 'private', 'credential_id', 'subdirectory', 'source', 'source_revision' ), array_keys( $plugins->snapshots[0] ) );
	}

	public function testManualAndAutomaticPolicyFailAtomicallyWhenReadinessIsUnavailable(): void {
		$plugin  = BulkActionPackage::make( 'example/example.php', 'fixture', true, 'missing-profile' );
		$plugins = new BulkActionPluginRepository( array( 'example/example.php' => $plugin ) );

		try {
			$this->service( $plugins, true )->execute(
				BulkPackageAction::fromInput(
					'plugin',
					array(
						'bulk_action' => 'policy-manual',
						'identifiers' => array( 'example/example.php' ),
					)
				)
			);
			self::fail( 'Missing private credentials must block the whole policy change.' );
		} catch ( BulkPackageActionFailure $failure ) {
			self::assertSame( 'credential_unavailable', $failure->reason );
			self::assertSame( array(), $plugins->snapshots );
		}

		$public                                 = BulkActionPackage::make( 'public/public.php', 'fixture' );
		$plugins->packages['public/public.php'] = $public;
		try {
			$this->service( $plugins, true )->execute(
				BulkPackageAction::fromInput(
					'plugin',
					array(
						'bulk_action' => 'policy-automatic',
						'identifiers' => array( 'public/public.php' ),
					)
				)
			);
			self::fail( 'Providers without webhooks must not accept Automatic policy.' );
		} catch ( BulkPackageActionFailure $failure ) {
			self::assertSame( 'webhook_unavailable', $failure->reason );
		}
	}

	public function testReleaseAutomaticPolicyUsesNativeUpdatesWithoutWebhookCapability(): void {
		$release = BulkActionPackage::make( 'release/release.php', 'fixture' );
		$release->setSource( PackageSource::RELEASE_ASSET, 2 );
		$plugins = new BulkActionPluginRepository( array( 'release/release.php' => $release ) );

		$result = $this->service( $plugins, true )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'policy-automatic',
					'identifiers' => array( 'release/release.php' ),
				)
			)
		);

		self::assertSame( 1, $result->changed );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $plugins->policy );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $plugins->snapshots[0]['source'] );
	}

	public function testQueueUpdatesAdmitsEligiblePackagesAndReportsSkipsAndBusyRows(): void {
		$eligible = BulkActionPackage::make( 'eligible/eligible.php', 'fixture' );
		$disabled = BulkActionPackage::make( 'disabled/disabled.php', 'fixture' );
		$disabled->setDeploymentPolicy( DeploymentPolicy::DISABLED );
		$missing             = BulkActionPackage::make( 'missing/missing.php', 'missing-provider' );
		$plugins             = new BulkActionPluginRepository(
			array(
				'disabled/disabled.php' => $disabled,
				'eligible/eligible.php' => $eligible,
				'missing/missing.php'   => $missing,
			)
		);
		$coordinator         = new BulkActionCoordinator();
		$coordinator->result = array(
			'queued'        => 0,
			'busy'          => 1,
			'runner_status' => 'not_required',
		);

		$result = $this->service( $plugins, true, $coordinator )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'queue-update',
					'identifiers' => array(
						'eligible/eligible.php',
						'disabled/disabled.php',
						'missing/missing.php',
					),
				)
			)
		);

		self::assertSame( 0, $result->queued );
		self::assertSame(
			array(
				'busy'                 => 1,
				'disabled'             => 1,
				'provider_unavailable' => 1,
			),
			$result->skippedByReason
		);
		self::assertCount( 1, $coordinator->targets );
		self::assertSame( 'eligible', $coordinator->targets[0]['request']->packageSlug );
		self::assertSame( 'manual', $coordinator->targets[0]['request']->deploymentPolicy->value );
	}

	public function testQueueUpdatesDoesNotAdmitAnythingWhenEverySelectionIsIneligible(): void {
		$disabled = BulkActionPackage::make( 'disabled/disabled.php', 'fixture' );
		$disabled->setDeploymentPolicy( DeploymentPolicy::DISABLED );
		$missing     = BulkActionPackage::make( 'missing/missing.php', 'missing-provider' );
		$plugins     = new BulkActionPluginRepository(
			array(
				'disabled/disabled.php' => $disabled,
				'missing/missing.php'   => $missing,
			)
		);
		$coordinator = new BulkActionCoordinator();

		$result = $this->service( $plugins, true, $coordinator )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'queue-update',
					'identifiers' => array(
						'disabled/disabled.php',
						'missing/missing.php',
					),
				)
			)
		);

		self::assertSame( 0, $result->queued );
		self::assertSame( 'not_required', $result->runnerStatus );
		self::assertSame(
			array(
				'disabled'             => 1,
				'provider_unavailable' => 1,
			),
			$result->skippedByReason
		);
		self::assertSame( array(), $coordinator->targets );
	}

	public function testQueueUpdateSkipsReleaseManagedPackagesWithoutProviderOrCredentialWork(): void {
		$release = BulkActionPackage::make( 'release/release.php', 'missing-provider', true, 'missing-profile' );
		$release->setSource( PackageSource::RELEASE_ASSET, 2 );
		$plugins     = new BulkActionPluginRepository( array( 'release/release.php' => $release ) );
		$coordinator = new BulkActionCoordinator();

		$result = $this->service( $plugins, false, $coordinator )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'queue-update',
					'identifiers' => array( 'release/release.php' ),
				)
			)
		);

		self::assertSame( array( 'release_source' => 1 ), $result->skippedByReason );
		self::assertSame( array(), $coordinator->targets );
	}

	public function testDeploymentRequestsRejectDuplicateIdentifiers(): void {
		$identifiers = array( 'example/example.php', 'example/example.php' );
		try {
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::QUEUE_UPDATE,
					'identifiers' => $identifiers,
				)
			);
			self::fail( 'Duplicate bulk selections must be rejected.' );
		} catch ( \InvalidArgumentException ) {
			self::assertTrue( true );
		}
	}

	public function testQueueAndPolicyRequestsAcceptTwentyAndRejectTwentyOneIdentifiers(): void {
		$accepted = array_map( static fn ( int $index ): string => "package-$index/package-$index.php", range( 1, 20 ) );
		$rejected = array_map( static fn ( int $index ): string => "package-$index/package-$index.php", range( 1, 21 ) );
		foreach ( array( BulkPackageAction::QUEUE_UPDATE, BulkPackageAction::POLICY_DISABLED ) as $operation ) {
			$action = BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => $operation,
					'identifiers' => $accepted,
				)
			);
			self::assertCount( 20, $action->identifiers );

			try {
				BulkPackageAction::fromInput(
					'plugin',
					array(
						'bulk_action' => $operation,
						'identifiers' => $rejected,
					)
				);
				self::fail( 'Deployment selections above twenty must be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testActivationRequestsAcceptTwoHundredAndRejectTwoHundredAndOneIdentifiers(): void {
		$accepted = array_map( static fn ( int $index ): string => "package-$index/package-$index.php", range( 1, 200 ) );
		$rejected = array_map( static fn ( int $index ): string => "package-$index/package-$index.php", range( 1, 201 ) );
		foreach ( BulkPackageAction::pluginActivationOperations() as $operation ) {
			$action = BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => $operation,
					'identifiers' => $accepted,
				)
			);
			self::assertCount( 200, $action->identifiers );

			try {
				BulkPackageAction::fromInput(
					'plugin',
					array(
						'bulk_action' => $operation,
						'identifiers' => $rejected,
					)
				);
				self::fail( 'Activation selections above two hundred must be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testRequestRejectsMalformedAndWrongTypeIdentifiers(): void {
		foreach (
			array(
				array( 'plugin', ' example/example.php' ),
				array( 'plugin', 'example//example.php' ),
				array( 'plugin', 'example/../example.php' ),
				array( 'plugin', 'example/example.zip' ),
				array( 'theme', 'example/theme' ),
				array( 'package', 'example' ),
			) as [$packageType, $identifier]
		) {
			try {
				BulkPackageAction::fromInput(
					$packageType,
					array(
						'bulk_action' => 'queue-update',
						'identifiers' => array( $identifier ),
					)
				);
				self::fail( 'Malformed package identifiers and types must be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	/** @param array<string, mixed> $input */
	private function expectInvalidBulkAction( string $packageType, array $input ): void {
		try {
			BulkPackageAction::fromInput( $packageType, $input );
			self::fail( 'The invalid bulk action should have been rejected.' );
		} catch ( \InvalidArgumentException ) {
			self::assertTrue( true );
		}
	}

	public function testQueueUpdateSkipsBoosterSelfSelectionWithoutBlockingEligiblePackages(): void {
		$eligible    = BulkActionPackage::make( 'eligible/eligible.php', 'fixture' );
		$plugins     = new BulkActionPluginRepository( array( 'eligible/eligible.php' => $eligible ) );
		$coordinator = new BulkActionCoordinator();

		$result = $this->service( $plugins, true, $coordinator )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'queue-update',
					'identifiers' => array(
						'ran-booster/ran-booster.php',
						'eligible/eligible.php',
					),
				)
			)
		);

		self::assertSame( 1, $result->queued );
		self::assertSame( array( 'self_update' => 1 ), $result->skippedByReason );
		self::assertCount( 1, $coordinator->targets );
	}

	public function testMutatingActionsUseTheUpdaterLockWhileQueuedUpdatesDoNot(): void {
		$policyPackage = BulkActionPackage::make( 'policy/policy.php', 'fixture' );
		$activePackage = BulkActionPackage::make( 'active/active.php', 'fixture' );
		$queuePackage  = BulkActionPackage::make( 'queue/queue.php', 'fixture' );
		$plugins       = new BulkActionPluginRepository(
			array(
				'policy/policy.php' => $policyPackage,
				'active/active.php' => $activePackage,
				'queue/queue.php'   => $queuePackage,
			)
		);
		$coordinator   = new BulkActionCoordinator();
		$lock          = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::exactly( 2 ) )->method( 'acquire' )->willReturn( 'bulk-lock' );
		$lock->expects( self::exactly( 2 ) )->method( 'release' )->with( 'bulk-lock' )->willReturn( true );
		$this->updaterLock = $lock;
		$service           = $this->service( $plugins, true, $coordinator );

		$policy     = $service->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'policy-disabled',
					'identifiers' => array( 'policy/policy.php' ),
				)
			)
		);
		$activation = $service->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::ACTIVATE_PLUGINS,
					'identifiers' => array( 'active/active.php' ),
				)
			)
		);
		$queue      = $service->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => BulkPackageAction::QUEUE_UPDATE,
					'identifiers' => array( 'queue/queue.php' ),
				)
			)
		);

		self::assertTrue( '' === $policy->errorCode );
		self::assertTrue( '' === $activation->errorCode );
		self::assertSame( 1, $queue->queued );
		self::assertCount( 1, $coordinator->targets );
	}

	public function testUpdaterLockContentionFailsClosedBeforeBulkPolicyMutation(): void {
		$plugins = new BulkActionPluginRepository(
			array( 'policy/policy.php' => BulkActionPackage::make( 'policy/policy.php', 'fixture' ) )
		);
		$lock    = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )
			->method( 'acquire' )
			->willThrowException( new \RuntimeException( 'busy' ) );
		$lock->expects( self::never() )->method( 'release' );
		$this->updaterLock = $lock;

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'policy-disabled',
					'identifiers' => array( 'policy/policy.php' ),
				)
			)
		);

		self::assertSame( 'unavailable', $result->errorCode );
		self::assertSame( array(), $plugins->snapshots );
	}

	public function testUpdaterLockReleaseFailureDoesNotReportBulkMutationSuccess(): void {
		$plugins = new BulkActionPluginRepository(
			array( 'policy/policy.php' => BulkActionPackage::make( 'policy/policy.php', 'fixture' ) )
		);
		$lock    = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'bulk-lock' );
		$lock->expects( self::once() )->method( 'release' )->with( 'bulk-lock' )->willReturn( false );
		$this->updaterLock = $lock;

		$result = $this->service( $plugins )->execute(
			BulkPackageAction::fromInput(
				'plugin',
				array(
					'bulk_action' => 'policy-disabled',
					'identifiers' => array( 'policy/policy.php' ),
				)
			)
		);

		self::assertSame( 'unavailable', $result->errorCode );
		self::assertCount( 1, $plugins->snapshots );
	}

	private function service(
		?BulkActionPluginRepository $plugins = null,
		bool $registerProvider = false,
		?BulkActionCoordinator $coordinator = null
	): BulkPackageActionService {
		$policies = new ProviderSecretPolicyCatalog();
		$secrets  = new SecretsFile( null, array(), $policies );
		$registry = new ProviderRegistry( array(), $policies );
		if ( $registerProvider ) {
			$registry->register( new ExternalFixtureProvider( 'fixture' ) );
		}
		return new BulkPackageActionService(
			$plugins ?? new BulkActionPluginRepository(),
			new BulkActionThemeRepository(),
			$registry,
			$secrets,
			$coordinator ?? new BulkActionCoordinator(),
			$this->updaterLock
		);
	}
}

final class BulkActionPackage extends AbstractPackage {

	private function __construct( private readonly string $identifier ) {
	}

	public static function make(
		string $identifier,
		string $provider,
		bool $private = false,
		?string $credentialId = null
	): self {
		$package = new self( $identifier );
		$package->setInstallationSlug( dirname( $identifier ) );
		$package->setRepository(
			new ManagedRepository(
				$provider,
				'owner/' . dirname( $identifier ),
				'R_' . dirname( $identifier ),
				'main',
				$private,
				$credentialId
			)
		);

		return $package;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}
}

final class BulkActionPluginRepository extends PluginRepository {

	/** @var array<string, Package> */
	public array $packages;
	/** @var list<array<string, mixed>> */
	public array $snapshots          = array();
	public ?DeploymentPolicy $policy = null;

	/** @param array<string, Package> $packages */
	public function __construct( array $packages = array() ) {
		$this->packages = $packages;
	}

	public function boosterPluginFromFile( $file ) {
		if ( ! isset( $this->packages[ $file ] ) ) {
			throw new PluginNotFound( 'Missing fixture plugin.' );
		}

		return $this->packages[ $file ];
	}

	public function setPluginDeploymentPolicies( array $snapshots, DeploymentPolicy $policy ): array {
		$this->snapshots = $snapshots;
		$this->policy    = $policy;

		return array(
			'selected'  => count( $snapshots ),
			'changed'   => count( $snapshots ),
			'unchanged' => 0,
		);
	}
}

final class BulkActionThemeRepository extends ThemeRepository {

	public function boosterThemeFromStylesheet( $stylesheet ) {
		throw new ThemeNotFound( 'Missing fixture theme.' );
	}
}

final class BulkActionCoordinator extends DeploymentCoordinator {

	/** @var list<array<string, mixed>> */
	public array $targets = array();
	/** @var array{queued: int, busy: int, runner_status: string} */
	public array $result = array(
		'queued'        => 1,
		'busy'          => 0,
		'runner_status' => 'scheduled',
	);

	public function __construct() {
	}

	public function queueManualUpdates( array $targets ): array {
		$this->targets = $targets;

		return $this->result;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile

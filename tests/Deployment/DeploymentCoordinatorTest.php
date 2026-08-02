<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately create and remove isolated fixture files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The repository boundary requires a scoped wpdb double.

namespace Tests\Deployment;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/DeploymentCoordinatorWordPressFunctions.php';
require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
require_once __DIR__ . '/WordPressWorkerWakeupCron.php';
require_once __DIR__ . '/WordPressWorkerWakeupWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Storage/StorageTestEnvironment.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\BackgroundDeploymentFailureEmail;
use RAN\Deployment\DeploymentArchivePreflight;
use RAN\Deployment\DeploymentArchiveLimitFailure;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Deployment\PreparedArtifact;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\CorePackageExecutionFailure;
use RAN\WordPress\CorePackageExecutionResult;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use ReflectionMethod;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;

final class DeploymentCoordinatorTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private CoordinatorPluginRepository $plugins;
	private CoordinatorThemeRepository $themes;
	private CoordinatorProvider $provider;
	private ProviderRegistry $providers;
	private CoordinatorPreflight $preflight;
	private CoordinatorExecutor $executor;
	private CoordinatorFailureEmail $failureEmail;
	private DeploymentCoordinator $coordinator;
	private int $randomByte = 1;
	/** @var list<string> */
	private array $artifacts = array();

	protected function setUp(): void {
		$GLOBALS['ran_booster_worker_doing_cron']                = true;
		$GLOBALS['ran_booster_storage_test_options']             = array( 'active_plugins' => array() );
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		WordPressWorkerWakeupCron::reset();
		$this->database     = new AttemptRepositoryDatabase();
		$GLOBALS['wpdb']    = $this->database;
		$this->attempts     = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->randomByte++ ), $length );
			}
		);
		$this->plugins      = new CoordinatorPluginRepository();
		$this->themes       = new CoordinatorThemeRepository();
		$this->provider     = new CoordinatorProvider();
		$this->providers    = new ProviderRegistry( array( $this->provider ) );
		$this->preflight    = new CoordinatorPreflight();
		$this->executor     = new CoordinatorExecutor();
		$this->failureEmail = new CoordinatorFailureEmail();
		$this->coordinator  = new DeploymentCoordinator(
			$this->attempts,
			$this->plugins,
			$this->themes,
			$this->providers,
			$this->preflight,
			$this->executor,
			new WordPressWorkerWakeup( $this->attempts ),
			sys_get_temp_dir() . '/ran-booster-coordinator-maintenance',
			new WordPressUpdaterLock(),
			$this->failureEmail
		);
	}

	protected function tearDown(): void {
		foreach ( $this->artifacts as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		unset( $GLOBALS['wpdb'], $GLOBALS['ran_booster_worker_doing_cron'], $GLOBALS['ran_booster_storage_test_options'], $GLOBALS['ran_booster_package_mutation_guard_multisite'], $GLOBALS['ran_booster_package_mutation_guard_file_mods'], $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
		WordPressWorkerWakeupCron::reset();
	}

	public function testBulkManualAdmissionQueuesWithoutExecutingAndRequestsTheWorker(): void {
		$result = $this->coordinator->queueManualUpdates(
			array(
				array(
					'package_type'            => 'plugin',
					'provider'                => 'gh',
					'provider_repository_id'  => 'R_example',
					'requested_ref'           => 'main',
					'package_source'          => 'branch',
					'package_source_revision' => 1,
					'request'                 => new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1 ),
				),
			)
		);

		self::assertSame(
			array(
				'queued'        => 1,
				'busy'          => 0,
				'runner_status' => 'scheduled',
			),
			$result
		);
		self::assertCount( 1, $this->database->rows );
		self::assertSame( 'manual', $this->database->rows[0]['source'] );
		self::assertSame( 'queued', $this->database->rows[0]['state'] );
		self::assertSame( 0, $this->executor->calls );
		self::assertCount( 1, WordPressWorkerWakeupCron::$events );
	}

	public function testExactManualUpdateRunsImmediatelyAndReturnsTerminalReference(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$package                                  = $this->plugin();
		$this->plugins->managed                   = array( $package );
		$this->plugins->byIdentifier              = $package;
		$this->plugins->installed                 = $this->plugin( version: '2.0.0' );
		$this->preflight->artifact                = $this->artifact( '2.0.0' );

		$result = $this->coordinator->executeManual( $this->updateCommand() );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $result['outcome_code'] );
		self::assertSame( $this->database->rows[0]['correlation_id'], $result['correlation_id'] );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );
		self::assertSame( 'main', $this->database->rows[0]['requested_ref'] );
		self::assertSame( 'owner/example', DeploymentRequest::fromJson( $this->database->rows[0]['request_json'] )->repository );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
		$commit = array_search( 'COMMIT', $this->database->queries, true );
		$fence  = array_search( "UPDATE `wp_ran_booster_deployment_attempts` SET resolved_ref = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE id = 1 AND state = 'running'", $this->database->queries, true );
		self::assertIsInt( $commit );
		self::assertIsInt( $fence );
		self::assertLessThan( $fence, $commit );
	}

	public function testOlderBranchArtifactIsBlockedBeforePackageMutation(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$package                                  = $this->plugin( version: '2.0.0' );
		$this->plugins->managed                   = array( $package );
		$this->plugins->byIdentifier              = $package;
		$this->plugins->installed                 = $this->plugin( version: '2.0.0' );
		$this->preflight->artifact                = $this->artifact( '1.9.0' );

		$result = $this->coordinator->executeManual( $this->updateCommand() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_DOWNGRADE_BLOCKED, $result['outcome_code'] );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 0, $this->executor->calls );
		self::assertSame( 0, $this->plugins->stores );
	}

	public function testManualFailureReturnsTerminalSafeOutcomeWithoutScheduling(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$package                                  = $this->plugin();
		$this->plugins->managed                   = array( $package );
		$this->plugins->byIdentifier              = $package;

		$result = $this->coordinator->executeManual( $this->updateCommand() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_PREFLIGHT_FAILED, $result['outcome_code'] );
		self::assertSame( $this->database->rows[0]['correlation_id'], $result['correlation_id'] );
		self::assertSame( 'failed', $this->database->rows[0]['state'] );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
		self::assertSame( array(), $this->failureEmail->attempts );
	}

	/** @return iterable<string, array{DeploymentArchiveLimitFailure,string}> */
	public static function archiveLimitFailures(): iterable {
		yield 'compressed' => array( DeploymentArchiveLimitFailure::compressed(), DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE );
		yield 'expanded' => array( DeploymentArchiveLimitFailure::expanded(), DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE );
		yield 'configuration' => array( DeploymentArchiveLimitFailure::configuration(), DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID );
	}

	#[DataProvider( 'archiveLimitFailures' )]
	public function testArchiveLimitFailuresPersistDistinctSafeOutcomes( DeploymentArchiveLimitFailure $failure, string $code ): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$package                                  = $this->plugin();
		$this->plugins->managed                   = array( $package );
		$this->plugins->byIdentifier              = $package;
		$this->preflight->failure                 = $failure;

		$result = $this->coordinator->executeManual( $this->updateCommand() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( $code, $result['outcome_code'] );
		self::assertSame( $code, $this->database->rows[0]['outcome_code'] );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 0, $this->executor->calls );
	}

	public function testProviderRateLimitPersistsAnActionableSafeOutcome(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$package                                  = $this->plugin();
		$this->plugins->managed                   = array( $package );
		$this->plugins->byIdentifier              = $package;
		$this->provider->prepareFailure           = new RuntimeException( 'Authorization: Bearer secret-canary', 429 );

		$result = $this->coordinator->executeManual( $this->updateCommand() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_RATE_LIMITED, $result['outcome_code'] );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_RATE_LIMITED, $this->database->rows[0]['outcome_code'] );
		self::assertStringNotContainsString( 'secret-canary', json_encode( $this->database->rows, JSON_THROW_ON_ERROR ) );
		self::assertSame( 0, $this->preflight->calls );
	}

	public function testManualThemeUpdateUsesTheSameImmediateTerminalPath(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$theme                                    = $this->theme();
		$this->themes->managed                    = array( $theme );
		$this->themes->byIdentifier               = $theme;
		$this->themes->installed                  = $this->theme( '2.0.0' );
		$this->preflight->artifact                = $this->artifact( '2.0.0' );

		$result = $this->coordinator->executeManual( $this->updateThemeCommand() );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $result['outcome_code'] );
		self::assertSame( 'theme', $this->database->rows[0]['package_type'] );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->executor->calls );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
	}

	public function testManualPluginInstallRunsTheFullSynchronousCoordinatorPath(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$slug                                     = 'ran-booster-install-fixture-plugin';
		$this->plugins->installed                 = $this->plugin( '2.0.0', 'owner/install-plugin', $slug );
		$this->preflight->artifact                = $this->artifact( '2.0.0' );

		$result = $this->coordinator->executeManual( $this->installCommand( 'plugin', $slug ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $result['outcome_code'] );
		self::assertSame( 'manual', $this->database->rows[0]['source'] );
		self::assertSame( 'install', $this->database->rows[0]['operation'] );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->provider->prepareCalls );
		self::assertSame( 1, $this->preflight->calls );
		self::assertSame( array( 'install-plugin' ), $this->executor->operations );
		self::assertSame( 1, $this->plugins->stores );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
	}

	public function testExplicitPluginInstallPersistsDisabledPolicy(): void {
		$slug                      = 'ran-booster-disabled-install-fixture';
		$this->plugins->installed  = $this->plugin( '2.0.0', 'owner/install-plugin', $slug );
		$this->preflight->artifact = $this->artifact( '2.0.0' );

		$result = $this->coordinator->executeManual(
			$this->installCommand( 'plugin', $slug, DeploymentPolicy::DISABLED )
		);

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( DeploymentPolicy::DISABLED, $this->plugins->installed->getDeploymentPolicy() );
		self::assertSame( 1, $this->plugins->stores );
	}

	public function testManualThemeInstallRunsTheFullSynchronousCoordinatorPath(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$slug                                     = 'ran-booster-install-fixture-theme';
		$this->themes->installed                  = $this->theme( '2.0.0', $slug );
		$this->preflight->artifact                = $this->artifact( '2.0.0' );

		$result = $this->coordinator->executeManual( $this->installCommand( 'theme', $slug ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $result['outcome_code'] );
		self::assertSame( 'manual', $this->database->rows[0]['source'] );
		self::assertSame( 'install', $this->database->rows[0]['operation'] );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->provider->prepareCalls );
		self::assertSame( 1, $this->preflight->calls );
		self::assertSame( array( 'install-theme' ), $this->executor->operations );
		self::assertSame( 1, $this->themes->stores );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
	}

	public function testDriftedManualSnapshotIsRejectedBeforeAdmission(): void {
		$package                     = $this->plugin();
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;
		$command                     = $this->updateCommand( array( 'expected_branch' => 'stale-branch' ) );

		$this->expectExceptionMessage( 'changed after this form was opened' );
		try {
			$this->coordinator->executeManual( $command );
		} finally {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function testSourceTransitionAfterFormOpenIsRejectedBeforeAdmission(): void {
		$package = $this->plugin();
		$package->setSource( PackageSource::RELEASE_ASSET, 2 );
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;

		$this->expectExceptionMessage( 'changed after this form was opened' );
		try {
			$this->coordinator->executeManual( $this->updateCommand() );
		} finally {
			self::assertSame( array(), $this->database->rows );
			self::assertSame( 0, $this->preflight->calls );
		}
	}

	public function testTamperedManualProviderIdentityIsRejectedBeforeAdmission(): void {
		$package                     = $this->plugin();
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;
		$command                     = $this->updateCommand( array( 'expected_provider_repository_id' => 'forged-id' ) );

		$this->expectExceptionMessage( 'changed after this form was opened' );
		try {
			$this->coordinator->executeManual( $command );
		} finally {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function testWebhookMatchingRequiresProviderOwnedLocatorAndStableIdentity(): void {
		$package                = $this->plugin();
		$this->plugins->managed = array( $package );
		$wrong                  = new PushEvent( ProviderCode::parse( 'gh' ), 'other/example', 'R_example', 'main', str_repeat( 'a', 40 ), 'delivery-wrong' );

		$result = $this->coordinator->acceptWebhook( array( $wrong ), str_repeat( 'd', 64 ) );
		self::assertSame( 0, $result['accepted_targets'] );
		self::assertSame( 'delivery', $this->database->rows[0]['package_type'] );

		$exact  = new PushEvent( ProviderCode::parse( 'gh' ), 'owner/example', 'R_example', 'main', str_repeat( 'b', 40 ), 'delivery-exact' );
		$result = $this->coordinator->acceptWebhook( array( $exact ), str_repeat( 'e', 64 ) );
		self::assertSame( 1, $result['accepted_targets'] );
		self::assertSame( str_repeat( 'b', 40 ), $this->database->rows[1]['requested_ref'] );
	}

	public function testWebhookPackageReadCompatibilityFailureBecomesRetrySafeStorageFailure(): void {
		$this->plugins->readFailure = PackageStorageFailure::unsupportedDatabase();
		$event                      = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'R_example',
			'main',
			str_repeat( 'b', 40 ),
			'delivery-database-safe-state'
		);

		try {
			$this->coordinator->acceptWebhook( array( $event ), str_repeat( 'e', 64 ) );
			self::fail( 'Expected the package-storage safe state to stop webhook admission.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->isDatabaseUnsupported() );
		}

		self::assertSame( array(), $this->database->rows );
	}

	public function testTerminalWebhookFailureNotifiesAfterTheAttemptIsDurablyFinished(): void {
		$package                        = $this->plugin();
		$this->plugins->managed         = array( $package );
		$this->plugins->byIdentifier    = $package;
		$this->provider->prepareFailure = new RuntimeException( 'expired credential', 401 );
		$event                          = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'R_example',
			'main',
			str_repeat( 'b', 40 ),
			'delivery-failure-email'
		);

		$this->coordinator->acceptWebhook( array( $event ), str_repeat( 'e', 64 ) );
		$attempt = $this->attempts->claimNext();
		self::assertInstanceOf( DeploymentAttempt::class, $attempt );

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED, $outcome->getCode() );
		self::assertCount( 1, $this->failureEmail->attempts );
		self::assertSame( DeploymentState::FAILED, $this->failureEmail->attempts[0]->getState() );
		self::assertSame(
			DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED,
			$this->failureEmail->attempts[0]->getOutcome()?->getCode()
		);
	}

	public function testClaimedWorkerExecutionRefusesANonCronCaller(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$attempt                                  = $this->claimedUpdate();

		$this->expectExceptionMessage( 'outside WordPress cron' );
		$this->coordinator->executeClaimed( $attempt );
	}

	public function testZeroTargetReplayDoesNotQueueANewlyMatchingPackage(): void {
		$event  = new PushEvent( ProviderCode::parse( 'gh' ), 'other/example', 'R_example', 'main', str_repeat( 'a', 40 ), 'delivery-zero' );
		$digest = str_repeat( 'd', 64 );

		$first                  = $this->coordinator->acceptWebhook( array( $event ), $digest );
		$this->plugins->managed = array( $this->plugin( repository: 'other/example' ) );
		$replay                 = $this->coordinator->acceptWebhook( array( $event ), $digest );

		self::assertSame( 'accepted', $first['status'] );
		self::assertSame( 'duplicate', $replay['status'] );
		self::assertSame( $first['correlation_id'], $replay['correlation_id'] );
		self::assertSame( 0, $replay['accepted_targets'] );
		self::assertSame( 'not_required', $replay['runner_status'] );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( 'delivery', $this->database->rows[0]['package_type'] );
	}

	public function testStaleExpectedHeadFailsBeforeMutationFence(): void {
		$attempt                   = $this->claimedUpdate();
		$this->provider->stale     = true;
		$this->preflight->artifact = $this->artifact( '2.0.0' );

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_STALE_EVENT, $outcome->getCode() );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
	}

	public function testWpPusherActivatedAfterPreflightFailsBeforeMutationFence(): void {
		$attempt                      = $this->claimedUpdate();
		$this->preflight->artifact    = $this->artifact( '2.0.0' );
		$this->provider->beforeVerify = static function (): void {
			$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		};

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_POLICY_BLOCKED, $outcome->getCode() );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 0, $this->executor->calls );
	}

	public function testReleaseManagedPackageRejectsBranchManualAdmissionBeforeRemoteWork(): void {
		$package = $this->plugin();
		$package->setSource( PackageSource::RELEASE_ASSET, 2 );
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;
		$command                     = $this->updateCommand(
			array(
				'expected_source'          => 'release_asset',
				'expected_source_revision' => '2',
			)
		);

		try {
			$this->coordinator->executeManual( $command );
			self::fail( 'Release-managed packages must not enter the branch deployment path.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Branch deployment is unavailable for a release-managed package.', $exception->getMessage() );
		}

		self::assertSame( array(), $this->database->rows );
		self::assertSame( 0, $this->preflight->calls );
	}

	public function testQueuedBranchAttemptIsInvalidatedByASourceTransition(): void {
		$package                     = $this->plugin();
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;
		$this->plugins->installed    = $package;
		$request                     = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::AUTOMATIC, 1 );
		$attempt                     = $this->attempts->admitAndClaimManual( 'update', 'plugin', 'gh', 'R_example', $request, 'main', 'branch', 1 );
		$package->setSource( PackageSource::RELEASE_ASSET, 2 );

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_POLICY_BLOCKED, $outcome->getCode() );
		self::assertSame( 0, $this->preflight->calls );
		self::assertSame( 0, $this->executor->calls );
	}

	public function testRestoredAndRefusedCoreFailuresHaveTruthfulOutcomes(): void {
		foreach ( array(
			array( CorePackageExecutionFailure::WORDPRESS_RESTORED, DeploymentOutcome::CODE_ACTIVATION_FAILED ),
			array( CorePackageExecutionFailure::WORDPRESS_FAILED, DeploymentOutcome::CODE_UPGRADER_FAILED ),
		) as [$failure, $expected] ) {
			$this->resetExecutionState();
			$attempt                   = $this->claimedUpdate();
			$this->preflight->artifact = $this->artifact( '2.0.0' );
			$this->executor->result    = CorePackageExecutionResult::failed( $failure );

			self::assertSame( $expected, $this->coordinator->executeClaimed( $attempt )->getCode() );
		}
	}

	public function testSuccessfulCoreResultRejectsActivationDriftWithASafeSpecificOutcome(): void {
		$attempt                        = $this->claimedUpdate( active: true );
		$this->preflight->artifact      = $this->artifact( '2.0.0' );
		$this->plugins->installed       = $this->plugin( version: '2.0.0' );
		$this->executor->afterExecution = static function (): void {
			$GLOBALS['ran_booster_storage_test_options']['active_plugins'] = array();
		};

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_ACTIVATION_STATE_CHANGED, $outcome->getCode() );
	}

	public function testSuccessfulCoreResultRejectsInstalledVersionMismatchWithASafeSpecificOutcome(): void {
		$attempt                   = $this->claimedUpdate();
		$this->preflight->artifact = $this->artifact( '2.0.0' );
		$this->plugins->installed  = $this->plugin( version: '1.9.0' );

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_INSTALLED_VERSION_MISMATCH, $outcome->getCode() );
	}

	public function testPostCoreManagedSnapshotDriftCannotBecomeSuccess(): void {
		$attempt                        = $this->claimedUpdate();
		$this->preflight->artifact      = $this->artifact( '2.0.0' );
		$this->plugins->installed       = $this->plugin( version: '2.0.0' );
		$this->executor->afterExecution = function (): void {
			$this->plugins->managed = array( $this->plugin( repository: 'other/example' ) );
		};

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $outcome->getCode() );
	}

	public function testExistingNativeCoreLockFailsBeforeExecutorMutation(): void {
		$attempt                   = $this->claimedUpdate();
		$this->preflight->artifact = $this->artifact( '2.0.0' );
		$liveLock                  = (string) time();
		$this->database->optionRows['auto_updater.lock'] = $liveLock;

		$outcome = $this->coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_LOCK_UNAVAILABLE, $outcome->getCode() );
		self::assertSame( 0, $this->executor->calls );
		self::assertSame( $liveLock, $this->database->optionRows['auto_updater.lock'] );
		self::assertSame( DeploymentState::FAILED, $this->attempts->findExact( $attempt->getId() )?->getState() );
	}

	public function testStaleNativeCoreLockIsReclaimedWithExactTokenSafety(): void {
		$stale = (string) ( time() - 3601 );
		$this->database->optionRows['auto_updater.lock'] = $stale;
		$acquire = new ReflectionMethod( $this->coordinator, 'acquireCoreLock' );
		$release = new ReflectionMethod( $this->coordinator, 'releaseCoreLock' );

		$token = $acquire->invoke( $this->coordinator );

		self::assertNotSame( $stale, $token );
		self::assertSame( $token, $this->database->optionRows['auto_updater.lock'] );
		self::assertStringContainsString(
			"DELETE FROM `wp_options` WHERE option_name = 'auto_updater.lock' AND option_value = '{$stale}'",
			implode( "\n", $this->database->queries )
		);
		self::assertTrue( $release->invoke( $this->coordinator, $token ) );
	}

	public function testMalformedNativeCoreLockFailsClosed(): void {
		$this->database->optionRows['auto_updater.lock'] = 'not-a-timestamp';

		$this->expectException( RuntimeException::class );
		try {
			( new ReflectionMethod( $this->coordinator, 'acquireCoreLock' ) )->invoke( $this->coordinator );
		} finally {
			self::assertSame( 'not-a-timestamp', $this->database->optionRows['auto_updater.lock'] );
		}
	}

	public function testNativeCoreLockDatabaseFailureRemainsUnavailable(): void {
		$attempt                           = $this->claimedUpdate();
		$this->preflight->artifact         = $this->artifact( '2.0.0' );
		$this->database->failQueryContains = 'INSERT IGNORE INTO `wp_options`';

		try {
			$this->coordinator->executeClaimed( $attempt );
			self::fail( 'A native-lock database failure must remain a storage failure.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			self::assertSame( 0, $this->executor->calls );
			self::assertArrayNotHasKey( 'auto_updater.lock', $this->database->optionRows );
			self::assertSame( DeploymentState::RUNNING, $this->attempts->findExact( $attempt->getId() )?->getState() );
		}
	}

	public function testNativeCoreLockAcquisitionAndReleaseInvalidateExactCaches(): void {
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();

		$acquire = new ReflectionMethod( $this->coordinator, 'acquireCoreLock' );
		$release = new ReflectionMethod( $this->coordinator, 'releaseCoreLock' );

		$token = $acquire->invoke( $this->coordinator );
		self::assertSame(
			array( array( 'auto_updater.lock', 'options' ), array( 'notoptions', 'options' ) ),
			$GLOBALS['ran_booster_attempt_cache_deletes']
		);
		self::assertTrue( $release->invoke( $this->coordinator, $token ) );
		self::assertSame(
			array(
				array( 'auto_updater.lock', 'options' ),
				array( 'notoptions', 'options' ),
				array( 'auto_updater.lock', 'options' ),
				array( 'notoptions', 'options' ),
			),
			$GLOBALS['ran_booster_attempt_cache_deletes']
		);
	}

	public function testTerminalWriteFailureReleasesItsExactNativeCoreLock(): void {
		$attempt                           = $this->claimedUpdate();
		$this->preflight->artifact         = $this->artifact( '2.0.0' );
		$this->plugins->installed          = $this->plugin( version: '2.0.0' );
		$this->database->zeroQueryContains = 'outcome_code';

		try {
			$this->coordinator->executeClaimed( $attempt );
			self::fail( 'Terminal persistence must fail closed.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			self::assertArrayNotHasKey( 'auto_updater.lock', $this->database->optionRows );
			self::assertSame( DeploymentState::RUNNING, $this->attempts->findExact( $attempt->getId() )?->getState() );
		}
	}

	public function testExactNativeCoreLockReleasePreservesAReplacementToken(): void {
		$attempt                        = $this->claimedUpdate();
		$this->preflight->artifact      = $this->artifact( '2.0.0' );
		$this->plugins->installed       = $this->plugin( version: '2.0.0' );
		$this->executor->afterExecution = function (): void {
			$this->database->optionRows['auto_updater.lock'] = '1777777777';
		};

		try {
			$this->coordinator->executeClaimed( $attempt );
			self::fail( 'Replacing the native lock token must fail closed.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			self::assertSame( '1777777777', $this->database->optionRows['auto_updater.lock'] );
			self::assertSame( DeploymentState::RUNNING, $this->attempts->findExact( $attempt->getId() )?->getState() );
		}
	}

	public function testNativeCoreLockReleaseFailureNeverCompletesTheAttempt(): void {
		foreach ( array( 'zero', 'false' ) as $failure ) {
			$this->resetExecutionState();
			$attempt                   = $this->claimedUpdate();
			$this->preflight->artifact = $this->artifact( '2.0.0' );
			$this->plugins->installed  = $this->plugin( version: '2.0.0' );
			if ( 'zero' === $failure ) {
				$this->database->zeroQueryContains = 'DELETE FROM `wp_options`';
			} else {
				$this->database->failQueryContains = 'DELETE FROM `wp_options`';
			}

			try {
				$this->coordinator->executeClaimed( $attempt );
				self::fail( 'A failed exact native-lock release must fail closed.' );
			} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
				self::assertSame( DeploymentState::RUNNING, $this->attempts->findExact( $attempt->getId() )?->getState() );
				self::assertArrayHasKey( 'auto_updater.lock', $this->database->optionRows );
			}
		}
	}

	public function testReconciliationDuringPreflightCannotBeOverwrittenByMutation(): void {
		$attempt                       = $this->claimedUpdate();
		$this->preflight->artifact     = $this->artifact( '2.0.0' );
		$this->preflight->beforeReturn = function () use ( $attempt ): void {
			$this->coordinator->reconcileConfirmedStopped( $attempt->getId(), $attempt->getCorrelationId() );
		};

		try {
			$this->coordinator->executeClaimed( $attempt );
			self::fail( 'A reconciled preflight attempt must not cross the mutation boundary.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			$stored = $this->attempts->findExact( $attempt->getId() );
			self::assertSame( DeploymentState::FAILED, $stored?->getState() );
			self::assertSame( DeploymentOutcome::CODE_WORKER_STOPPED, $stored?->getOutcome()?->getCode() );
			self::assertSame( 0, $this->executor->calls );
		}
	}

	public function testReconciliationAfterNativeLockAcquisitionPreventsMutationAndReleasesExactToken(): void {
		$attempt                      = $this->claimedUpdate();
		$this->preflight->artifact    = $this->artifact( '2.0.0' );
		$this->provider->beforeVerify = function () use ( $attempt ): void {
			$this->coordinator->reconcileConfirmedStopped( $attempt->getId(), $attempt->getCorrelationId() );
		};

		try {
			$this->coordinator->executeClaimed( $attempt );
			self::fail( 'A reconciled attempt must not cross the mutation boundary.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			$stored = $this->attempts->findExact( $attempt->getId() );
			self::assertSame( DeploymentState::FAILED, $stored?->getState() );
			self::assertSame( DeploymentOutcome::CODE_WORKER_STOPPED, $stored?->getOutcome()?->getCode() );
			self::assertSame( 0, $this->executor->calls );
			self::assertArrayNotHasKey( 'auto_updater.lock', $this->database->optionRows );
			self::assertStringContainsString(
				"DELETE FROM `wp_options` WHERE option_name = 'auto_updater.lock' AND option_value = '",
				implode( "\n", $this->database->queries )
			);
		}
	}

	public function testProtectedReconciliationLeavesTheNativeCoreLockUntouched(): void {
		$attempt = $this->claimedUpdate();
		$token   = ( new ReflectionMethod( $this->coordinator, 'acquireCoreLock' ) )->invoke( $this->coordinator );

		$result = $this->coordinator->reconcileConfirmedStopped( $attempt->getId(), $attempt->getCorrelationId() );

		self::assertSame( DeploymentOutcome::CODE_WORKER_STOPPED, $result->getOutcome()?->getCode() );
		self::assertSame( $token, $this->database->optionRows['auto_updater.lock'] );
	}

	public function testProtectedReconciliationNeverDeletesAnotherUpdaterCoreLock(): void {
		$attempt = $this->claimedUpdate();
		$this->database->optionRows['auto_updater.lock'] = '1777777777';

		$this->coordinator->reconcileConfirmedStopped( $attempt->getId(), $attempt->getCorrelationId() );

		self::assertSame( '1777777777', $this->database->optionRows['auto_updater.lock'] );
	}

	public function testProtectedReconciliationRejectsATerminalAttempt(): void {
		$running  = $this->claimedUpdate();
		$terminal = $this->attempts->finish( $running->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_NO_CHANGE ) );

		$this->expectException( \RAN\Deployment\DeploymentStorageFailure::class );
		$this->coordinator->reconcileConfirmedStopped( $terminal->getId(), $terminal->getCorrelationId() );
	}

	public function testParentThemeIsActiveWhenSelectedAsTheChildThemeTemplate(): void {
		$GLOBALS['ran_booster_storage_test_options']['stylesheet'] = 'child-theme';
		$GLOBALS['ran_booster_storage_test_options']['template']   = 'parent-theme';
		$state = ( new ReflectionMethod( $this->coordinator, 'packageRuntimeState' ) )->invoke(
			$this->coordinator,
			new CoordinatorThemePackage( 'parent-theme', '1.0.0' )
		);

		self::assertTrue( $state['active'] );
	}

	private function resetExecutionState(): void {
		$this->database->rows              = array();
		$this->database->optionRows        = array();
		$this->database->failQueryContains = null;
		$this->database->zeroQueryContains = null;
		$this->executor->result            = CorePackageExecutionResult::succeeded();
		$this->executor->afterExecution    = null;
		$this->executor->calls             = 0;
		$this->preflight->beforeReturn     = null;
		$this->provider->beforeVerify      = null;
		$this->provider->stale             = false;
		++$this->randomByte;
	}

	private function claimedUpdate( bool $active = false ): DeploymentAttempt {
		$package                     = $this->plugin();
		$this->plugins->managed      = array( $package );
		$this->plugins->byIdentifier = $package;
		$this->plugins->installed    = $package;
		$GLOBALS['ran_booster_storage_test_options']['active_plugins'] = $active ? array( 'example/example.php' ) : array();
		$request = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::AUTOMATIC, 1 );
		return $this->attempts->admitAndClaimManual( 'update', 'plugin', 'gh', 'R_example', $request, 'main', 'branch', 1 );
	}

	private function artifact( string $version ): PreparedArtifact {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-coordinator-' );
		self::assertIsString( $path );
		file_put_contents( $path, 'artifact-' . $version );
		chmod( $path, 0600 );
		$identity = PreparedArtifact::regularFileIdentity( $path );
		self::assertIsArray( $identity );
		$this->artifacts[] = $path;

		return new PreparedArtifact( $path, str_repeat( 'a', 40 ), $version, hash_file( 'sha256', $path ), $identity['device'], $identity['inode'], $identity['size'], $identity['permissions'], $identity['links'] );
	}

	private function plugin( string $version = '1.0.0', string $repository = 'owner/example', string $slug = 'example' ): Plugin {
		$plugin = Plugin::fromWpArray(
			$slug . '/' . $slug . '.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => $version,
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
		$repo   = new ManagedRepository( 'gh', $repository, 'R_example', 'main' );
		$plugin->setRepository( $repo );
		$plugin->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );

		return $plugin;
	}

	private function theme( string $version = '1.0.0', string $slug = 'example' ): CoordinatorThemePackage {
		$theme = new CoordinatorThemePackage( $slug, $version );
		$repo  = new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' );
		$theme->setRepository( $repo );
		$theme->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );

		return $theme;
	}

	/** @param array<string, mixed> $overrides */
	private function updateCommand( array $overrides = array() ): PackageOperation {
		return PackageOperation::fromInput(
			'update-plugin',
			array_merge(
				array(
					'file'                            => 'example/example.php',
					'repository'                      => 'owner/example',
					'ref'                             => 'main',
					'expected_provider'               => 'gh',
					'expected_provider_repository_id' => 'R_example',
					'expected_repository'             => 'owner/example',
					'expected_branch'                 => 'main',
					'expected_credential_id'          => '',
					'expected_subdirectory'           => '',
					'expected_private'                => '0',
					'expected_package_slug'           => 'example',
					'expected_deployment_policy'      => 'automatic',
					'expected_source'                 => 'branch',
					'expected_source_revision'        => '1',
				),
				$overrides
			)
		);
	}

	private function updateThemeCommand(): PackageOperation {
		return PackageOperation::fromInput(
			'update-theme',
			array(
				'stylesheet'                      => 'example',
				'repository'                      => 'owner/example',
				'ref'                             => 'main',
				'expected_provider'               => 'gh',
				'expected_provider_repository_id' => 'R_example',
				'expected_repository'             => 'owner/example',
				'expected_branch'                 => 'main',
				'expected_credential_id'          => '',
				'expected_subdirectory'           => '',
				'expected_private'                => '0',
				'expected_package_slug'           => 'example',
				'expected_deployment_policy'      => 'automatic',
				'expected_source'                 => 'branch',
				'expected_source_revision'        => '1',
			)
		);
	}

	private function installCommand(
		string $type,
		string $slug,
		DeploymentPolicy $policy = DeploymentPolicy::MANUAL
	): PackageOperation {
		return PackageOperation::fromInput(
			'install-' . $type,
			array(
				'provider'                            => 'gh',
				'repository'                          => 'owner/install-' . $type,
				'branch'                              => 'main',
				'package_slug'                        => $slug,
				'provider_repository_id'              => 'R_install_' . $type,
				'provider_repository_identity_source' => 'resolved',
				'deployment_policy'                   => $policy->value,
			)
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused coordinator collaborators.
final class CoordinatorPluginRepository extends PluginRepository {
	/** @var list<Package> */ public array $managed = array();
	public ?Package $byIdentifier                   = null;
	public ?Package $installed                      = null;
	public ?PackageStorageFailure $readFailure      = null;
	public int $stores                              = 0;
	public function __construct() {}
	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		if ( null !== $this->readFailure ) {
			throw $this->readFailure;
		}
		return $this->managed; }
	public function boosterPluginFromFile( $file ) {
		return $this->byIdentifier ?? throw new RuntimeException( 'Missing package.' ); }
	public function fromSlug( $slug ) {
		return $this->installed ?? throw new RuntimeException( 'Missing installed package.' ); }
	public function store( Plugin $plugin ): PackageMutationResult {
		++$this->stores;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT ); }
	public function adopt( Plugin $plugin ): PackageMutationResult {
		++$this->stores;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT ); }
}

final class CoordinatorThemeRepository extends ThemeRepository {
	/** @var list<Package> */ public array $managed = array();
	public ?Package $byIdentifier                   = null;
	public ?Package $installed                      = null;
	public int $stores                              = 0;
	public function __construct() {}
	public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
		return $this->managed; }
	public function boosterThemeFromStylesheet( $stylesheet ) {
		return $this->byIdentifier ?? throw new RuntimeException( 'Missing theme.' ); }
	public function fromSlug( $slug ) {
		return $this->installed ?? throw new RuntimeException( 'Missing installed theme.' ); }
	public function store( Theme $theme ): PackageMutationResult {
		++$this->stores;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT ); }
	public function adopt( Theme $theme ): PackageMutationResult {
		++$this->stores;
		return PackageMutationResult::changed( PackageStorageOperation::INSERT ); }
}

final class CoordinatorThemePackage extends \RAN\Theme {
	public function __construct( string $stylesheet, string $version ) {
		$this->stylesheet = $stylesheet;
		$this->version    = $version;
	}
}

final class CoordinatorProvider implements RepositoryProvider, WebhookNormalizer {
	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public bool $stale                       = false;
	public int $prepareCalls                 = 0;
	public ?RuntimeException $prepareFailure = null;

	/** @var null|callable(): void */ public $beforeVerify = null;
	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ); }
	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ) ); }
	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored(); }
	public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		return new \RAN\RepositoryProvider\ProviderDiagnosticResult( 'warning', 'test.webhook.unverified', 'Webhook unverified.', 'Send a test delivery.' );
	}
	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		++$this->prepareCalls;
		if ( null !== $this->prepareFailure ) {
			throw $this->prepareFailure;
		}
		return new CoordinatorPreparedArchive( $this ); }
}

final readonly class CoordinatorPreparedArchive implements PreparedArchive {
	public function __construct( private CoordinatorProvider $provider ) {}
	public function getUrl(): string {
		return 'https://example.test/archive.zip'; }
	public function getResolvedRef(): string {
		return str_repeat( 'a', 40 ); }
	public function verifyCurrentHead(): void {
		if ( null !== $this->provider->beforeVerify ) {
			( $this->provider->beforeVerify )(); }
		if ( $this->provider->stale ) {
			throw new StaleDeployment( 'stale' ); } }
	public function cleanup(): void {}
}

final class CoordinatorPreflight extends DeploymentArchivePreflight {
	public ?PreparedArtifact $artifact                     = null;
	public ?RuntimeException $failure                      = null;
	public int $calls                                      = 0;
	/** @var null|callable(): void */ public $beforeReturn = null;
	public function prepare( DeploymentAttempt $attempt, PreparedArchive $archive, ?string $installedIdentifier = null ): PreparedArtifact {
		++$this->calls;
		if ( null !== $this->beforeReturn ) {
			( $this->beforeReturn )(); }
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $this->artifact ?? throw new RuntimeException( 'Missing test artifact.' );
	}
}

final class CoordinatorExecutor extends CorePackageExecutor {
	public CorePackageExecutionResult $result;
	public int $calls                                        = 0;
	/** @var list<string> */ public array $operations        = array();
	/** @var null|callable(): void */ public $afterExecution = null;
	public function __construct() {
		$this->result = CorePackageExecutionResult::succeeded(); }
	public function updatePlugin( PreparedArtifact $artifact, string $packageSlug, ?string $subdirectory, string $pluginFile ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->afterExecution ) {
			( $this->afterExecution )(); }
		return $this->result;
	}
	public function updateTheme( PreparedArtifact $artifact, string $packageSlug, ?string $subdirectory, string $stylesheet ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->afterExecution ) {
			( $this->afterExecution )(); }
		return $this->result;
	}
	public function installPlugin( PreparedArtifact $artifact, string $packageSlug, ?string $subdirectory ): CorePackageExecutionResult {
		++$this->calls;
		$this->operations[] = 'install-plugin';
		return $this->result;
	}
	public function installTheme( PreparedArtifact $artifact, string $packageSlug, ?string $subdirectory ): CorePackageExecutionResult {
		++$this->calls;
		$this->operations[] = 'install-theme';
		return $this->result;
	}
}

final class CoordinatorFailureEmail extends BackgroundDeploymentFailureEmail {
	/** @var list<DeploymentAttempt> */
	public array $attempts = array();

	public function notify( DeploymentAttempt $attempt ): bool {
		$this->attempts[] = $attempt;

		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile

<?php

declare(strict_types=1);


namespace RAN\Tests\Deployment;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
require_once __DIR__ . '/AdmittedBranchHostAdapterWordPressFunctions.php';
require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
require_once __DIR__ . '/../Storage/StorageTestEnvironment.php';
require_once __DIR__ . '/../RepositoryProvider/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/../RepositoryProvider/AuthenticatedPreparedArchiveWordPressFunctions.php';
require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';
require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';
require_once __DIR__ . '/DeploymentCoordinatorWordPressFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentFailureNotifier;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive as ProviderPreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Storage\Database;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RuntimeException;
use RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\Tests\RepositoryProvider\Support\RepositoryResolverSecretsStub;
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use ZipArchive;

final class AdmittedBranchHostAdapterParityTest extends TestCase {
	use \RAN\Tests\Support\QueuedSelectionFixture;

	private const MAXIMUM_ARTIFACT_BYTES = 1048576;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private ParityPluginRepository $plugins;
	private ParityThemeRepository $themes;
	private RepositorySourceGuardDatabase $source_database;
	private RepositorySourceGuard $source_guard;
	private int $random_byte = 1;
	private mixed $previous_activity_options;
	private bool $had_activity_options;
	/** @var list<string> */
	private array $fixtures = array();

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->had_activity_options                  = array_key_exists( 'ran_booster_activity_options', $GLOBALS );
		$this->previous_activity_options             = $GLOBALS['ran_booster_activity_options'] ?? null;
		$GLOBALS['ran_booster_storage_test_options'] = array();
		unset( $GLOBALS['ran_booster_admitted_http_request_args'], $GLOBALS['ran_booster_admitted_http_guards'] );
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		$GLOBALS['ran_booster_worker_doing_cron']                = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_admitted_filesystem_method']       = 'direct';
		$GLOBALS['ran_booster_admitted_http_calls']              = array();
		$GLOBALS['ran_booster_admitted_sleeps']                  = array();
		$GLOBALS['ran_booster_admitted_http_responses']          = array( 200 );
		$GLOBALS['ran_booster_admitted_temp_root']               = sys_get_temp_dir() . '/ran-booster-admitted-parity-temp';
		$this->ensure_directory( ABSPATH . 'wp-admin/includes' );
		$this->ensure_directory( WP_CONTENT_DIR );
		$this->ensure_directory( WP_PLUGIN_DIR );
		$this->ensure_directory( (string) $GLOBALS['ran_booster_admitted_temp_root'] );
		$file = ABSPATH . 'wp-admin/includes/file.php';
		if ( ! file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			file_put_contents( $file, "<?php\n" );
		}

		$this->database = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated test assigns the WordPress database double required by the updater lock.
		$GLOBALS['wpdb']        = $this->database;
		$this->attempts         = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-09-14 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->random_byte++ ), $length );
			},
			$this->createStub( \RAN\Storage\Database::class ),
			selection: $this->queued_selection( $this->database )
		);
		$this->plugins          = new ParityPluginRepository();
		$this->themes           = new ParityThemeRepository();
		$this->source_database  = new RepositorySourceGuardDatabase();
		$this->source_guard     = new RepositorySourceGuard( $this->source_database, $this->createStub( Database::class ) );
		$this->plugins->managed = array( 'fixture' => $this->plugin() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		if ( $this->had_activity_options ) {
			$GLOBALS['ran_booster_activity_options'] = $this->previous_activity_options;
		} else {
			unset( $GLOBALS['ran_booster_activity_options'] );
		}
		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) || is_link( $fixture ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $fixture );
			}
		}
		$this->remove_tree( (string) ( $GLOBALS['ran_booster_admitted_temp_root'] ?? '' ) . '/ran-booster-branch-updater' );
		foreach ( array( 'new-branch', 'adopt-example' ) as $slug ) {
			$this->remove_tree( WP_PLUGIN_DIR . '/' . $slug );
		}
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['ran_booster_worker_doing_cron'],
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_admitted_filesystem_method'],
			$GLOBALS['ran_booster_admitted_http_calls'],
			$GLOBALS['ran_booster_admitted_http_responses'],
			$GLOBALS['ran_booster_admitted_download_fixture'],
			$GLOBALS['ran_booster_admitted_temp_root']
		);
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue( array() );
	}

	#[DataProvider( 'overwrite_targets' )]
	public function test_target_directory_matches_word_press_destination( string $type, string $operation, bool $alternate ): void {
		$slug    = 'example';
		$request = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			$slug,
			null,
			DeploymentPolicy::AUTOMATIC,
			7,
			self::MAXIMUM_ARTIFACT_BYTES,
			installed_identifier: 'update' === $operation ? ( 'plugin' === $type ? 'example/example.php' : $slug ) : null,
			adoption_token: 'update' === $operation ? 'gh:R_example:11111111-1111-4111-8111-111111111111' : null
		);
		if ( 'update' === $operation ) {
			$identifier                                     = (string) $request->installed_identifier;
			$this->database->management[ $identifier ]      = (string) $request->adoption_token;
			$this->database->management_rows[ $identifier ] = array(
				'provider'               => 'gh',
				'provider_repository_id' => 'R_example',
				'repository'             => 'owner/example',
				'branch'                 => 'main',
				'private'                => 0,
				'credential_id'          => null,
				'subdirectory'           => null,
				'deployment_policy'      => DeploymentPolicy::AUTOMATIC->value,
				'source'                 => 'branch',
				'source_revision'        => 1,
			);
		}
		$theme = $this->createPartialMock( \RAN\Theme::class, array( 'get_identifier' ) );
		$theme->method( 'get_identifier' )->willReturn( $slug );
		$theme->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$theme->set_adoption_token( 'gh:R_example:11111111-1111-4111-8111-111111111111' );
		$theme->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$theme->set_source( PackageSource::BRANCH, 1 );
		$this->themes->managed = $theme;
		$root                  = $alternate ? sys_get_temp_dir() . '/alternate-theme-root' : \get_theme_root();
		$GLOBALS['ran_booster_parity_theme_roots'][ $slug ] = $root;
		try {
			$attempt = $this->attempts->admit_and_claim_manual( $operation, $type, 'gh', 'R_example', $request, 'main', 'branch', 'update' === $operation ? 1 : 0 );
			$adapter = $this->adapter( $attempt, new ParityRepositoryProvider( null ) );
			self::assertSame( ( 'plugin' === $type ? WP_PLUGIN_DIR : $root ) . '/' . $slug, $adapter->target_directory( $adapter->declaration() ) );
			self::assertFalse( $adapter->declaration()->allow_working_copy );
		} finally {
			unset( $GLOBALS['ran_booster_parity_theme_roots'] );
		}
	}

	/** @return iterable<string,array{string,string,bool}> */
	public static function overwrite_targets(): iterable {
		foreach ( array( 'install', 'update' ) as $operation ) {
			yield 'plugin ' . $operation => array( 'plugin', $operation, false );
			yield 'theme ' . $operation => array( 'theme', $operation, false );
			yield 'alternate theme ' . $operation => array( 'theme', $operation, true );
		}
	}

	#[DataProvider( 'working_copy_markers' )]
	public function test_working_copy_refuses_before_durable_mutation( string $marker, string $shape ): void {
		$this->enable_activity();
		$target = WP_PLUGIN_DIR . '/example';
		$this->ensure_directory( $target );
		$path = $target . '/' . $marker;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable target canary proves the real refusal leaves installed bytes alone.
		file_put_contents( $target . '/canary.txt', 'installed bytes remain' );
		try {
			if ( 'directory' === $shape ) {
				$this->ensure_directory( $path );
			} elseif ( 'link' === $shape ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Deliberately dangling marker in this disposable target.
				self::assertTrue( symlink( $target . '/absent', $path ) );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable version-control marker fixture.
				file_put_contents( $path, 'gitdir: /unavailable' );
			}
			$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
			$executor = new ParityCoreExecutor();
			$adapter  = $this->adapter( $this->running_update(), new ParityRepositoryProvider( $archive ), executor: $executor );
			$this->download_fixture( 'example' );
			self::assertSame( DeploymentOutcome::CODE_POLICY_BLOCKED, $this->deploy( $adapter ) );
			self::assertSame( 0, $executor->calls );
			self::assertStringEqualsFile( $target . '/canary.txt', 'installed bytes remain' );
			self::assertSame( 1, $archive->cleanup_calls );
			self::assertNull( $adapter->terminal_attempt()->safe_data()['mutation_started_at'] );
			self::assertSame( DeploymentState::FAILED, $adapter->terminal_attempt()->get_state() );
			self::assertSame( 'target_is_working_copy', $this->activity_facts()['branch_refusal'] ?? null );
			self::assertStringNotContainsString( 'branch_refusal', $adapter->terminal_attempt()->get_request()->to_json() );
		} finally {
			$this->remove_tree( $target );
		}
	}

	public function test_theme_working_copy_refusal_retains_the_same_closed_fact(): void {
		$this->enable_activity();
		$this->running_update();
		$theme = new \RAN\Theme();
		( new \ReflectionProperty( \RAN\Theme::class, 'stylesheet' ) )->setValue( $theme, 'example' );
		( new \ReflectionProperty( \RAN\Theme::class, 'version' ) )->setValue( $theme, '1.0.0' );
		$theme->set_repository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$theme->set_adoption_token( 'gh:R_example:11111111-1111-4111-8111-111111111111' );
		$theme->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
		$theme->set_source( PackageSource::BRANCH, 1 );
		$this->themes->managed                      = $theme;
		$this->database->management['example']      = (string) $theme->get_adoption_token();
		$this->database->management_rows['example'] = $this->database->management_rows['example/example.php'];
		$request                                    = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::AUTOMATIC, 7, self::MAXIMUM_ARTIFACT_BYTES, 'example', $theme->get_adoption_token() );
		$attempt                                    = $this->attempts->admit_and_claim_manual( 'update', 'theme', 'gh', 'R_example', $request, 'main', 'branch', 1 );
		$target                                     = \get_theme_root() . '/example';
		$this->ensure_directory( $target . '/.git' );
		$fixture = $this->download_fixture( 'example' );
		$zip     = new ZipArchive();
		self::assertTrue( $zip->open( $fixture ) );
		$zip->deleteName( 'example/example.php' );
		$zip->addFromString( 'example/style.css', "/*\nTheme Name: Example\nVersion: 2.0.0\n*/\n" );
		$zip->close();
		$adapter = $this->adapter( $attempt, new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		try {
			$declaration = $adapter->declaration();
			$updater     = BranchUpdater::for_admitted_attempt( $declaration, $adapter, $adapter, $adapter, $adapter, $adapter );
			self::assertSame( 'policy_blocked', $updater->theme( 'owner/example', 'R_example', 'main', 'example' )->deploy() );
			$facts = json_decode( (string) $this->database->activity_rows[1]['facts'], true );
			self::assertSame( 'target_is_working_copy', $facts['branch_refusal'] ?? null );
			self::assertNull( $adapter->terminal_attempt()->safe_data()['mutation_started_at'] );
			self::assertDirectoryExists( $target . '/.git' );
		} finally {
			$this->remove_tree( $target );
		}
	}

	#[\PHPUnit\Framework\Attributes\TestWith( array( true ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( false ) )]
	public function test_working_copy_observation_does_not_survive_ambiguous_lock_release( bool $storage ): void {
		$this->enable_activity();
		$target = WP_PLUGIN_DIR . '/example';
		$this->ensure_directory( $target . '/.git' );
		$adapter = $this->adapter( $this->running_update(), new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = $storage ? 'DELETE FROM `wp_options`' : null;
		$this->database->zero_query_contains = $storage ? null : 'DELETE FROM `wp_options`';
		try {
			$this->deploy( $adapter );
			self::fail( 'Ambiguous ownership cannot commit a working-copy refusal.' );
		} catch ( BranchDeploymentLockStorageFailure | BranchDeploymentLockReleaseFailure ) {
			self::assertSame( 'running', $this->database->rows[0]['state'] );
			self::assertArrayNotHasKey( 'branch_refusal', $this->activity_facts() );
			self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		} finally {
			$this->remove_tree( $target );
		}
	}

	public function test_reason_journal_omits_superseded_other_and_post_fence_reasons(): void {
		$this->enable_activity();
		foreach ( array( 'valid', 'cleanup', 'primary', 'other', 'success', 'fenced' ) as $case ) {
			$attempt = $this->running_update();
			if ( 'fenced' === $case ) {
				$attempt = $this->attempts->mark_mutation_started( $attempt->get_id() );
				self::assertInstanceOf( DeploymentAttempt::class, $attempt );
			}
			$adapter = $this->adapter( $attempt, new ParityRepositoryProvider( null ) );
			$reason  = new \RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason(
				'other' === $case ? \RAN\WPBranchUpdater\V1\Runtime\FailureReason::LOCK_HELD : \RAN\WPBranchUpdater\V1\Runtime\FailureReason::TARGET_IS_WORKING_COPY,
				'ignored detail',
				'primary' === $case ? 'policy_blocked' : null,
				'cleanup' === $case
			);
			$lines   = $this->captured_logs( static fn () => $adapter->finish_with_reason( 'success' === $case ? 'deployed' : 'policy_blocked', $reason ) );
			$facts   = json_decode( (string) $this->database->activity_rows[ count( $this->database->activity_rows ) - 1 ]['facts'], true );
			self::assertSame( 'valid' === $case ? 'target_is_working_copy' : null, $facts['branch_refusal'] ?? null );
			self::assertSame( 'valid' === $case ? 1 : 0, substr_count( $lines, '"branch_refusal":"target_is_working_copy"' ) );
			self::assertStringNotContainsString( 'ignored detail', $lines );
		}
	}

	public function test_reasoned_finish_commit_failure_leaves_no_fact_or_finished_log(): void {
		$this->enable_activity();
		$adapter                     = $this->adapter( $this->running_update(), new ParityRepositoryProvider( null ) );
		$this->database->fail_commit = true;
		$caught                      = null;
		$lines                       = $this->captured_logs(
			static function () use ( $adapter, &$caught ): void {
				try {
					$adapter->finish_with_reason( 'policy_blocked', new \RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason( \RAN\WPBranchUpdater\V1\Runtime\FailureReason::TARGET_IS_WORKING_COPY ) );
				} catch ( \RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure $failure ) {
					$caught = $failure;
				}
			}
		);
		self::assertNotNull( $caught );
		self::assertSame( 'running', $this->database->rows[0]['state'] );
		self::assertArrayNotHasKey( 'branch_refusal', $this->activity_facts() );
		self::assertStringNotContainsString( 'attempt finished', $lines );
	}

	#[\PHPUnit\Framework\Attributes\TestWith( array( false ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( true ) )]
	public function test_manual_refinement_failure_uses_fresh_settlement_transaction( bool $settlement_fails ): void {
		$this->enable_activity();
		$target = WP_PLUGIN_DIR . '/example';
		$this->ensure_directory( $target . '/.git' );
		$this->download_fixture( 'example' );
		$archive                         = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$this->database->on_lock_release = function () use ( $settlement_fails, $archive ): void {
			self::assertSame( 1, $archive->cleanup_calls );
			self::assertSame( array(), glob( (string) $GLOBALS['ran_booster_admitted_temp_root'] . '/ran-booster-branch-updater/*' ) );
			$this->database->queries              = array();
			$this->database->abort_authority_read = $this->database->authority_reads + 1;
			$this->database->fail_commit          = $settlement_fails;
		};
		$executor                        = new ParityCoreExecutor();
		$coordinator                     = $this->coordinator( new ParityRepositoryProvider( $archive ), executor: $executor );
		$token                           = 'gh:R_example:11111111-1111-4111-8111-111111111111';
		$this->database->management['example/example.php']      = $token;
		$this->database->management_rows['example/example.php'] = array(
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => DeploymentPolicy::AUTOMATIC->value,
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$command = \RAN\PackageOperation::from_input(
			'update-plugin',
			array(
				'file'                            => 'example/example.php',
				'repository'                      => 'owner/example',
				'expected_provider'               => 'gh',
				'expected_provider_repository_id' => 'R_example',
				'expected_repository'             => 'owner/example',
				'expected_branch'                 => 'main',
				'expected_credential_id'          => '',
				'expected_subdirectory'           => '',
				'expected_private'                => false,
				'expected_package_slug'           => 'example',
				'expected_deployment_policy'      => 'automatic',
				'expected_source'                 => 'branch',
				'expected_source_revision'        => '1',
				'expected_adoption_token'         => $token,
			)
		);
		$result  = null;
		$caught  = null;
		try {
			$lines = $this->captured_logs(
				static function () use ( $coordinator, $command, &$result, &$caught ): void {
					try {
						$result = $coordinator->execute_manual( $command );
					} catch ( DeploymentStorageFailure $failure ) {
						$caught = $failure;
					}
				}
			);
			self::assertSame( 1, $this->database->automatic_rollbacks, $lines );
			$queries  = $this->database->queries;
			$rollback = array_search( 'ROLLBACK', $queries, true );
			self::assertIsInt( $rollback, implode( '\n', $queries ) . '\n' . $lines );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), array_values( array_filter( array_slice( $queries, 0, $rollback + 1 ), static fn ( string $query ): bool => in_array( $query, array( 'START TRANSACTION', 'ROLLBACK', 'COMMIT' ), true ) ) ) );
			self::assertSame( array(), preg_grep( '/^UPDATE .*SET (?:state|status) =/', array_slice( $queries, 0, $rollback ) ) );
			$settlement = array_slice( $queries, $rollback + 1 );
			self::assertContains( 'START TRANSACTION', $settlement );
			self::assertContains( 'COMMIT', $settlement );
			self::assertSame( 1, preg_match_all( "/^UPDATE .*SET state = 'failed'/m", implode( "\n", $settlement ) ) );
			self::assertSame( 1, preg_match_all( "/^UPDATE .*SET status = 'failed'/m", implode( "\n", $settlement ) ) );
			self::assertArrayNotHasKey( 'branch_refusal', $this->activity_facts() );
			self::assertStringNotContainsString( 'target_is_working_copy', $lines );
			self::assertNull( $this->database->rows[0]['mutation_started_at'] );
			self::assertSame( 0, $executor->calls );
			self::assertSame( 1, $archive->cleanup_calls );
			self::assertSame( array(), $this->database->option_rows );
			self::assertDirectoryExists( $target . '/.git' );
			self::assertStringNotContainsString( 'Deadlock', $lines );
			self::assertStringContainsString( '"exception_class":"RAN\\\\Storage\\\\DatabaseLifecycleFailure"', $lines );
			self::assertStringContainsString( '"exception_code":"0"', $lines );
			self::assertStringContainsString( $this->database->rows[0]['correlation_id'], $lines );
			if ( $settlement_fails ) {
				self::assertInstanceOf( DeploymentStorageFailure::class, $caught );
				self::assertTrue( $caught->is_left_running() );
				self::assertNull( $result );
				self::assertSame( 'running', $this->database->rows[0]['state'] );
				self::assertSame( 'running', $this->activity_facts()['deployment_state'] );
				self::assertArrayNotHasKey( 'deployment_settled_by', $this->activity_facts() );
				self::assertStringNotContainsString( '"step":"attempt_finished"', $lines );
			} else {
				self::assertNull( $caught );
				self::assertIsArray( $result );
				self::assertSame( 'worker_stopped', $result['outcome_code'] );
				self::assertSame( 'failed', $this->database->rows[0]['state'] );
				self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] );
				self::assertSame( 1, substr_count( $lines, '"step":"attempt_finished"' ) );
				$this->database->on_lock_release = null;
				$healthy                         = $coordinator->execute_manual( $command );
				self::assertSame( 'policy_blocked', $healthy['outcome_code'] );
				self::assertNotSame( $result['correlation_id'], $healthy['correlation_id'] );
				$facts = json_decode( (string) $this->database->activity_rows[1]['facts'], true );
				self::assertSame( 'target_is_working_copy', $facts['branch_refusal'] ?? null );
			}
		} finally {
			$this->remove_tree( $target );
		}
	}

	public function test_cron_working_copy_refusal_keeps_one_fact_and_one_repository_log(): void {
		require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';
		$this->enable_activity();
		$target = WP_PLUGIN_DIR . '/example';
		$this->ensure_directory( $target . '/.git' );
		try {
			$this->download_fixture( 'example' );
			$attempt                          = $this->running_webhook_update( str_repeat( 'a', 40 ) );
			$this->database->rows[0]['state'] = 'queued';
			$executor                         = new ParityCoreExecutor();
			$coordinator                      = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), executor: $executor );
			$worker                           = new \RAN\Deployment\DeploymentWorker( $this->attempts, $coordinator, new WordPressWorkerWakeup( $this->attempts ) );
			$lines                            = $this->captured_logs(
				static function () use ( $worker ): void {
					self::assertSame( 'processed', $worker->run_once()['status'] );
				}
			);
			self::assertSame( 'target_is_working_copy', $this->activity_facts()['branch_refusal'] ?? null );
			self::assertSame( $attempt->get_id(), $this->activity_facts()['deployment_attempt_id'] ?? null );
			self::assertSame( 1, substr_count( $lines, '"branch_refusal":"target_is_working_copy"' ) );
			self::assertSame( 0, $executor->calls );
			self::assertDirectoryExists( $target . '/.git' );
		} finally {
			$this->remove_tree( $target );
		}
	}

	/** @return iterable<string,array{string,string}> */
	public static function working_copy_markers(): iterable {
		foreach ( array( '.git', '.svn', '.hg', '.bzr' ) as $marker ) {
			foreach ( array( 'file', 'directory', 'link' ) as $shape ) {
				yield $marker . ' ' . $shape => array( $marker, $shape );
			}
		}
	}

	public function test_missing_target_ancestor_marker_and_composer_metadata_do_not_refuse(): void {
		$target = WP_PLUGIN_DIR . '/example';
		$marker = WP_PLUGIN_DIR . '/.git';
		self::assertDirectoryDoesNotExist( $target );
		self::assertFileDoesNotExist( $marker );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ancestor marker is deliberately outside the disposable package target.
		file_put_contents( $marker, 'ancestor' );
		try {
			foreach ( array( false, true ) as $metadata ) {
				if ( $metadata ) {
					$this->ensure_directory( $target );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Composer metadata alone is a negative working-copy control.
					file_put_contents( $target . '/composer.json', '{}' );
				}
				$executor                  = new ParityCoreExecutor();
				$executor->after_execution = function (): void {
					$this->plugins->managed['fixture'] = $this->plugin( version: '2.0.0' );
				};
				$adapter                   = $this->adapter( $this->running_update(), new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), executor: $executor );
				$this->download_fixture( 'example' );
				self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $this->deploy( $adapter ) );
				self::assertSame( 1, $executor->calls );
				$this->plugins->managed['fixture'] = $this->plugin();
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test's ancestor marker.
			unlink( $marker );
			$this->remove_tree( $target );
		}
	}

	public function test_guarded_github_archive_retains_post_download_head_authority_until_runner_verification(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->github_repository_response(),
				$this->github_sha_response( $commit ),
			)
		);
		$provider = $this->guarded_github_provider();
		self::assertInstanceOf( GitHubProvider::class, $provider );
		$this->download_fixture( 'example' );
		$executor                  = new ParityCoreExecutor();
		$executor->after_execution = function (): void {
			$this->plugins->installed = $this->plugin( version: '2.0.0' );
		};
		$adapter                   = $this->adapter( $this->running_update(), $provider, executor: $executor );
		$outcome                   = $this->deploy( $adapter );

		self::assertSame(
			DeploymentOutcome::CODE_DEPLOYED,
			$outcome,
			sprintf(
				'Expected post-cleanup head verification before mutation; got outcome=%s resolved_ref=%s mutation_started_at=%s executor_calls=%d.',
				$outcome,
				(string) ( $this->database->rows[0]['resolved_ref'] ?? '' ),
				null === ( $this->database->rows[0]['mutation_started_at'] ?? null ) ? 'null' : 'set',
				$executor->calls
			)
		);
		self::assertSame( 1, $executor->calls );
		self::assertSame( $commit, $this->database->rows[0]['resolved_ref'] );
		self::assertNotNull( $this->database->rows[0]['mutation_started_at'] );
	}

	public function test_guarded_private_manual_initial_install_rechecks_after_archive_cleanup(): void {
		$commit = '1234567890abcdef1234567890abcdef12345678';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->github_repository_response( true, 'owner/install-plugin', 'R_install_plugin' ), $this->github_sha_response( $commit ) )
		);
		$this->download_fixture( 'example' );
		$executor                  = new ParityCoreExecutor();
		$executor->after_execution = function (): void {
			$this->plugins->installed = $this->plugin( version: '2.0.0' );
		};
		$adapter                   = $this->adapter(
			$this->running_install( 'example', is_private: true, credential_id: 'private-profile' ),
			$this->guarded_github_provider( array( 'private-profile' => 'github-resolution-token-canary' ) ),
			executor: $executor
		);

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $this->deploy( $adapter ) );
		self::assertSame( $commit, $this->database->rows[0]['resolved_ref'] );
		self::assertSame( 1, $executor->calls );
		self::assertNotNull( $this->database->rows[0]['mutation_started_at'] );
	}

	public function test_guarded_webhook_rechecks_the_current_head_after_archive_cleanup(): void {
		$commit = 'abcdef0123456789abcdef0123456789abcdef01';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->github_repository_response(),
				$this->github_branch_response( $commit ),
				$this->github_branch_response( $commit ),
			)
		);
		$this->download_fixture( 'example' );
		$executor                  = new ParityCoreExecutor();
		$executor->after_execution = function (): void {
			$this->plugins->installed = $this->plugin( version: '2.0.0' );
		};
		$adapter                   = $this->adapter( $this->running_webhook_update( $commit ), $this->guarded_github_provider(), executor: $executor );

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $this->deploy( $adapter ) );
		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		self::assertSame( 1, $executor->calls );
	}

	public function test_guarded_webhook_stale_head_after_archive_cleanup_refuses_before_mutation(): void {
		$commit = 'abcdef0123456789abcdef0123456789abcdef01';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->github_repository_response(),
				$this->github_branch_response( $commit ),
				$this->github_branch_response( '1234567890abcdef1234567890abcdef12345678' ),
			)
		);
		$this->download_fixture( 'example' );
		$executor = new ParityCoreExecutor();
		$adapter  = $this->adapter( $this->running_webhook_update( $commit ), $this->guarded_github_provider(), executor: $executor );

		self::assertSame( DeploymentOutcome::CODE_STALE_EVENT, $this->deploy( $adapter ) );
		self::assertSame( $commit, $this->database->rows[0]['resolved_ref'] );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 0, $executor->calls );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_initial_identity_refuses_old_branch_api_before_provider_or_admission(): void {
				$old_runtime = new class() {
					public const ADMITTED_API_VERSION = 2;
				};
		class_alias( $old_runtime::class, BranchUpdater::class );
		$provider = new ParityRepositoryProvider( null );
		$command  = \RAN\PackageOperation::from_input(
			'install-plugin',
			array(
				'provider'               => 'gh',
				'provider_repository_id' => 'repo-id',
				'repository'             => 'owner/example',
				'branch'                 => 'main',
				'package_slug'           => 'example',
				'expected_identifier'    => 'example/main.php',
			)
		);
		try {
			$this->coordinator( $provider )->execute_manual( $command );
			self::fail( 'An old updater must refuse initial identity requests.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The branch updater cannot verify the expected initial package identity.', $failure->getMessage() );
		}
		self::assertSame( array(), $this->database->rows );
		$adapter = $this->adapter( $this->running_install( 'example', expected_identifier: 'example/main.php' ), $provider );
		try {
			$adapter->declaration();
			self::fail( 'Queued work must also refuse an old updater.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $failure->outcome_code );
		}
		self::assertSame( 0, $provider->prepare_calls );
	}

	public function test_coordinator_preserves_initial_identity_and_refuses_wrong_archive_main(): void {
				$archive = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider        = new ParityRepositoryProvider( $archive );
		$this->download_fixture( 'example' );
		$command = \RAN\PackageOperation::from_input(
			'install-plugin',
			array(
				'provider'               => 'gh',
				'provider_repository_id' => 'repo-id',
				'repository'             => 'owner/example',
				'branch'                 => 'main',
				'package_slug'           => 'example',
				'expected_identifier'    => 'example/Main.php',
			)
		);
		$result  = $this->coordinator( $provider )->execute_manual( $command );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'example/Main.php', DeploymentRequest::from_json( (string) $this->database->rows[0]['request_json'] )->expected_identifier );
		self::assertSame( 0, $this->plugins->adopt_calls );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_initial_identity_survives_admission_and_blocks_wrong_installed_main_file(): void {
		$attempt = $this->running_install( 'example', expected_identifier: 'example/Main.php' );
		self::assertSame( 'example/Main.php', $attempt->get_request()->expected_identifier );
		self::assertNotNull( $attempt->get_request()->provider_selection_generation );
		self::assertNull( $attempt->get_request()->installed_identifier );
		$adapter     = $this->adapter( $attempt, new ParityRepositoryProvider( null ) );
		$declaration = $adapter->declaration();
		self::assertSame( 'example/Main.php', $declaration->expected_identifier );
		$this->plugins->installed = $this->plugin();
		try {
			$adapter->installed( $declaration );
			self::fail( 'A different installed main file must refuse.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The installed package identity is unavailable.', $failure->getMessage() );
		}
		self::assertFalse( $adapter->adopt( $declaration ) );
		self::assertSame( 0, $this->plugins->adopt_calls );
	}

	public function test_initial_identity_is_rechecked_when_it_changes_after_installed_observation(): void {
		$adapter                  = $this->adapter( $this->running_install( 'example', expected_identifier: 'example/Main.php' ), new ParityRepositoryProvider( null ) );
		$declaration              = $adapter->declaration();
		$this->plugins->installed = $this->plugin( identifier: 'example/Main.php' );
		self::assertSame( 'example/Main.php', $adapter->installed( $declaration )['identifier'] );
		$this->plugins->installed = $this->plugin( identifier: 'example/other.php' );
		self::assertFalse( $adapter->adopt( $declaration ) );
		self::assertSame( 0, $this->plugins->adopt_calls );
	}

	public function test_webhook_admission_rejects_explicit_historical_selection_without_acknowledgement(): void {
		$request = new DeploymentRequest( 'owner/repo', null, false, 'main', 'repo', null, DeploymentPolicy::AUTOMATIC, null, selected_commit: str_repeat( 'a', 40 ) );
		$before  = $this->database->rows;
		try {
			$this->attempts->admit_webhook_batch(
				'gh',
				'historical-delivery',
				str_repeat( 'b', 64 ),
				array(
					array(
						'operation'               => 'update',
						'package_type'            => 'plugin',
						'provider_repository_id'  => 'repo-id',
						'requested_ref'           => str_repeat( 'a', 40 ),
						'package_source'          => 'branch',
						'package_source_revision' => 1,
						'request'                 => $request,
					),
				)
			);
			self::fail( 'Webhooks cannot request historical acquisition.' );
		} catch ( \RAN\Deployment\DeploymentStorageFailure ) {
			self::assertSame( $before, $this->database->rows );
		}
	}

	public function test_historical_mode_refuses_version_one_provider_before_preparation(): void {
		$provider = new ParityRepositoryProvider( null );
		$adapter  = $this->adapter( $this->running_update( str_repeat( 'a', 40 ) ), $provider );
		try {
			$adapter->declaration();
			self::fail( 'Unsupported historical acquisition must refuse.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $failure->outcome_code );
		}
		self::assertSame( 0, $provider->prepare_calls );
	}

	public function test_historical_archive_keeps_tracking_branch_and_requires_exact_resolved_commit(): void {
		$commit   = str_repeat( 'a', 40 );
		$archive  = new ParityProviderArchive( $commit );
		$provider = new ParityRepositoryProvider( $archive, 2 );
		$attempt  = $this->running_update( $commit );
		self::assertSame( $commit, $attempt->get_request()->selected_commit );
		self::assertSame( 'main', $attempt->get_request()->configured_branch );
		$adapter = $this->adapter( $attempt, $provider );
		$this->download_fixture( 'example' );
		$declaration = $adapter->declaration();
		self::assertSame( $commit, $declaration->selected_commit );
		self::assertNull( $declaration->expected_head );
		$artifact = $adapter->prepare( $declaration, $this->update_baseline() );
		self::assertNotNull( $provider->last_request );
		self::assertSame( $commit, $provider->last_request->selected_commit );
		self::assertSame( $commit, $provider->last_request->ref );
		self::assertNull( $provider->last_request->expected_branch );
		$artifact->cleanup();
	}

	public function test_coordinator_preserves_historical_selection_and_checks_installed_postconditions(): void {
		$commit   = str_repeat( 'a', 40 );
		$archive  = new ParityProviderArchive( $commit );
		$provider = new ParityRepositoryProvider( $archive, 2 );
		$attempt  = $this->running_update( $commit );
		$this->download_fixture( 'example' );
		$outcome = $this->coordinator( $provider )->execute_claimed( $attempt );
		self::assertSame( 'installed_version_mismatch', $outcome->get_code() );
		self::assertSame( $commit, $provider->last_request?->selected_commit );
		self::assertSame( 'main', (string) $this->plugins->managed['fixture']->get_branch() );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_historical_wrong_resolved_commit_cleans_before_download(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'b', 40 ) );
		$provider = new ParityRepositoryProvider( $archive, 2 );
		$adapter  = $this->adapter( $this->running_update( str_repeat( 'a', 40 ) ), $provider );
		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'A different resolved commit must refuse.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID, $failure->outcome_code );
		}
		self::assertSame( 1, $archive->cleanup_calls );
		self::assertSame( array(), $GLOBALS['ran_booster_admitted_http_calls'] );
	}

	public function test_concrete_provider_download_is_bounded_and_cleans_after_success(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->update_baseline() );

		self::assertCount( 1, $GLOBALS['ran_booster_admitted_http_calls'] );
		$arguments = $GLOBALS['ran_booster_admitted_http_calls'][0]['arguments'];
		self::assertTrue( $arguments['stream'] );
		self::assertTrue( $arguments['reject_unsafe_urls'] );
		self::assertSame( self::MAXIMUM_ARTIFACT_BYTES + 1, $arguments['limit_response_size'] );
		self::assertSame( 1, $archive->cleanup_calls );
		$artifact->cleanup();
	}

	public function test_redirect_argument_callback_revocation_refuses_second_transport_and_cleans(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses']    = array(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'https://example.test/redirected.zip' ),
			),
			200,
		);
		$GLOBALS['ran_booster_admitted_http_request_args'] = function ( string $url ): void {
			if ( 'https://example.test/redirected.zip' === $url ) {
				$this->database->selection->active_bytes = 'a:0:{}';
			}
		};
		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'A late redirected-hop revocation must refuse before transport.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $failure->outcome_code );
		}
		self::assertCount( 1, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( 0, $GLOBALS['ran_booster_admitted_http_calls'][0]['arguments']['redirection'] );
		self::assertSame( array(), $GLOBALS['ran_booster_admitted_http_guards'] );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_changed_generation_refuses_before_changed_package_or_provider_preparation(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$attempt  = $this->running_update();
		$state    = json_decode( $this->database->selection->selection->rows[0]->option_value, true, 8, JSON_THROW_ON_ERROR );
		self::assertIsArray( $state );
		$state['selection_generation'] = 1;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact authority race fixture bypasses sanitization.
		$this->database->selection->selection->rows[0]->option_value = json_encode( $state, JSON_THROW_ON_ERROR );
		$this->plugins->managed                                      = array();
		$adapter = $this->adapter( $attempt, $provider );
		try {
			$adapter->declaration();
			self::fail( 'Stale queued work must require a fresh submission.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_SELECTION_CHANGED, $failure->outcome_code );
		}
		self::assertSame( 0, $provider->prepare_calls );
		self::assertCount( 0, $GLOBALS['ran_booster_admitted_http_calls'] );
	}

	public function test_selection_loss_after_mutation_preserves_captured_completion_and_cleanup(): void {
		$archive                  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider                 = new ParityRepositoryProvider( $archive );
		$executor                 = new ParityCoreExecutor();
		$adapter                  = $this->adapter( $this->running_update(), $provider, executor: $executor );
		$this->plugins->installed = $this->plugin( version: '2.0.0' );
		$this->download_fixture( 'example' );
		$executor->after_execution = function (): void {
			$this->database->selection->active_bytes = 'a:0:{}';
		};
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $this->deploy( $adapter ) );
		self::assertSame( 1, $executor->calls );
		self::assertSame( 1, $archive->cleanup_calls );
		self::assertSame( DeploymentState::SUCCEEDED->value, $this->database->rows[0]['state'] );
		self::assertSame( array(), $this->database->option_rows );
	}

	public function test_concrete_provider_download_retries_transient_status_before_success(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( $this->status_with_retry_after( 503, '2' ), 200 );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->update_baseline() );

		self::assertCount( 2, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( array( 2 ), $GLOBALS['ran_booster_admitted_sleeps'] );
		self::assertSame( 1, $archive->cleanup_calls );
		$artifact->cleanup();
	}

	/** @return iterable<string, array{list<int|array<string, mixed>>, string, int, list<int>}> */
	public static function download_refusals(): iterable {
		$limited = DeploymentOutcome::CODE_PROVIDER_RATE_LIMITED;
		yield 'rate limit: 403 with an exhausted quota' => array(
			array(
				array(
					'response' => array( 'code' => 403 ),
					'headers'  => array( 'x-ratelimit-remaining' => '0' ),
				),
			),
			$limited,
			1,
			array(),
		);
		yield 'rate limit: 403 with Retry-After' => array(
			array(
				array(
					'response' => array( 'code' => 403 ),
					'headers'  => array( 'retry-after' => '30' ),
				),
			),
			$limited,
			1,
			array(),
		);
		yield 'plain 403 stays access denied' => array(
			array(
				array(
					'response' => array( 'code' => 403 ),
					'headers'  => array( 'x-ratelimit-remaining' => '12' ),
				),
			),
			DeploymentOutcome::CODE_PROVIDER_ACCESS_DENIED,
			1,
			array(),
		);
		yield '429 beyond the cap is not waited for' => array(
			array(
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'retry-after' => '60' ),
				),
			),
			$limited,
			1,
			array(),
		);
		yield '429 with no header is not retried' => array( array( 429 ), $limited, 1, array() );
		yield '503 with no header is not retried' => array( array( 503 ), DeploymentOutcome::from_provider_failure( new RuntimeException( '', 503 ) )->get_code(), 1, array() );
		yield '503 with a non-numeric Retry-After is not retried' => array(
			array(
				array(
					'response' => array( 'code' => 503 ),
					'headers'  => array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ),
				),
			),
			DeploymentOutcome::from_provider_failure( new RuntimeException( '', 503 ) )->get_code(),
			1,
			array(),
		);
		yield '429 retried once at the cap, then refused' => array(
			array(
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'retry-after' => '5' ),
				),
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'retry-after' => '5' ),
				),
			),
			$limited,
			2,
			array( 5 ),
		);
	}

	/**
	 * @param list<int|array<string, mixed>> $responses
	 * @param list<int>                      $sleeps
	 */
	#[DataProvider( 'download_refusals' )]
	public function test_concrete_provider_download_refuses_rate_limits_and_unbounded_retries( array $responses, string $code, int $calls, array $sleeps ): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = $responses;

		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'The download must be refused.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( $code, $failure->outcome_code );
		}

		self::assertCount( $calls, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( $sleeps, $GLOBALS['ran_booster_admitted_sleeps'] );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_concrete_provider_download_waits_a_bounded_retry_after_on_429_before_the_single_retry(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( $this->status_with_retry_after( 429, '5' ), 200 );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->update_baseline() );

		self::assertCount( 2, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( array( 5 ), $GLOBALS['ran_booster_admitted_sleeps'] );
		$artifact->cleanup();
	}

	public function test_concrete_provider_download_over_the_size_limit_is_too_large_not_corrupt(): void {
		foreach ( array(
			'over the limit' => array( self::MAXIMUM_ARTIFACT_BYTES + 1, DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE ),
			'at the limit'   => array( self::MAXIMUM_ARTIFACT_BYTES, DeploymentOutcome::CODE_ARCHIVE_ZIP_INVALID ),
		) as $case => list( $bytes, $code ) ) {
			$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
			$provider = new ParityRepositoryProvider( $archive );
			$adapter  = $this->adapter( $this->running_update(), $provider );
			$path     = $this->download_fixture( 'example' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			file_put_contents( $path, str_repeat( 'a', $bytes ) );

			try {
				$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
				self::fail( $case );
			} catch ( AdmittedBranchStageFailure $failure ) {
				// An at-limit file passes the size check and then fails as an invalid ZIP, never as too large.
				self::assertSame( $code, $failure->outcome_code, $case );
			}
			$this->database->rows = array();
		}
	}

	/** @return array<string, mixed> */
	private function status_with_retry_after( int $status, string $seconds ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'retry-after' => $seconds ),
		);
	}

	public function test_concrete_provider_download_maps_wp_error_and_cleans(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 'wp_error' );

		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'A WordPress transport error must fail the admitted archive acquisition.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_DOWNLOAD_FAILED, $failure->outcome_code );
		}

		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_concrete_provider_download_maps_terminal_status_and_cleans(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 404 );

		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'A terminal provider response must fail the admitted archive acquisition.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_REPOSITORY_MISSING, $failure->outcome_code );
		}

		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_concrete_provider_download_rejects_unsafe_url_before_http_and_cleans(): void {
		$archive      = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$archive->url = 'http://example.test/archive.zip';
		$provider     = new ParityRepositoryProvider( $archive );
		$adapter      = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );

		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'An unsafe provider URL must be rejected before HTTP.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID, $failure->outcome_code );
		}

		self::assertSame( array(), $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	/** @return array<string, array{?string, string, string}> */
	public static function validator_causes(): array {
		return array(
			'not a zip'        => array( "not a zip /private/path token=abc\n", DeploymentOutcome::CODE_ARCHIVE_ZIP_INVALID, '1001', 'RAN\\\\WPBranchUpdater\\\\V1\\\\Runtime\\\\ReasonedFailure' ),
			'no plugin header' => array( null, DeploymentOutcome::CODE_PACKAGE_PLUGIN_MISSING, '1019', 'RAN\\\\WPBranchUpdater\\\\V1\\\\Runtime\\\\ReasonedFailure' ),
		);
	}

	#[DataProvider( 'validator_causes' )]
	public function test_validator_causes_map_to_reserved_outcomes_and_log_only_class_and_code( ?string $bytes, string $outcome, string $code, string $logged_class ): void {
		$directory = sys_get_temp_dir() . '/ran-booster-cause-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		mkdir( $directory, 0700 );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json', static fn(): int => strtotime( '2026-07-23T12:00:00Z' ) );
		$capture->start();
		BoosterLogger::configure_capture( $capture );
		try {
			$archive = new ParityProviderArchive( str_repeat( 'a', 40 ) );
			$adapter = $this->adapter( $this->running_update(), new ParityRepositoryProvider( $archive ) );
			$path    = $this->download_fixture( 'example' );
			if ( null === $bytes ) {
				$zip = new ZipArchive();
				$zip->open( $path, ZipArchive::OVERWRITE );
				$zip->addFromString( 'example/example.php', "<?php\n" );
				$zip->close();
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				file_put_contents( $path, $bytes );
			}
			try {
				$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
				self::fail( 'The invalid archive must be refused.' );
			} catch ( AdmittedBranchStageFailure $failure ) {
				self::assertSame( $outcome, $failure->outcome_code );
			}
			$lines = implode( "\n", array_column( $capture->snapshot()['entries'], 'line' ) );
			self::assertStringContainsString( '"exception_class":"' . $logged_class . '","exception_code":"' . $code . '"', $lines );
			self::assertStringNotContainsString( 'private', $lines );
			self::assertStringNotContainsString( 'token=abc', $lines );
			self::assertStringNotContainsString( 'not a readable', $lines );
		} finally {
			BoosterLogger::configure_capture( null );
			foreach ( array_filter( (array) glob( $directory . '/*' ), 'is_string' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			rmdir( $directory );
		}
	}

	public function test_post_mutation_managed_snapshot_drift_finishes_interrupted(): void {
		$archive                  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider                 = new ParityRepositoryProvider( $archive );
		$executor                 = new ParityCoreExecutor();
		$attempt                  = $this->running_update();
		$adapter                  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->plugins->installed = $this->plugin( version: '2.0.0' );
		$this->download_fixture( 'example' );
		$executor->after_execution = function (): void {
			$this->plugins->managed = array( 'fixture' => $this->plugin( repository: 'other/example' ) );
		};

		$code = $this->deploy( $adapter );

		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $code );
		self::assertSame( 1, $executor->calls );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $this->database->rows[0]['state'] );
		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $this->database->rows[0]['outcome_code'] );
	}

	public function test_repository_source_owner_appearing_after_preparation_blocks_mutation(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$executor = new ParityCoreExecutor();
		$attempt  = $this->running_install( 'new-branch' );
		$adapter  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->download_fixture( 'new-branch' );
		$archive->on_cleanup = function (): void {
			$this->source_database->rows[] = (object) array(
				'type'                   => '2',
				'package'                => 'release-theme',
				'source'                 => PackageSource::RELEASE_ASSET->value,
				'provider'               => 'gh',
				'provider_repository_id' => 'R_install_plugin',
			);
		};

		$code = $this->deploy( $adapter );

		self::assertSame( DeploymentOutcome::CODE_REPOSITORY_SOURCE_CONFLICT, $code );
		self::assertSame( 0, $executor->calls );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 1, $archive->cleanup_calls );
	}

	public function test_exact_replacement_lock_token_is_preserved_and_attempt_remains_running(): void {
		$attempt = $this->running_update();
		$adapter = $this->adapter( $attempt, new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );

		try {
			$adapter->run(
				function (): void {
					$this->database->option_rows['auto_updater.lock'] = '1777777777';
				}
			);
			self::fail( 'Replacing the exact updater-lock token must fail closed.' );
		} catch ( BranchDeploymentLockReleaseFailure ) {
			self::assertSame( '1777777777', $this->database->option_rows['auto_updater.lock'] );
			self::assertSame( DeploymentState::RUNNING->value, $this->database->rows[0]['state'] );
		}
	}

	public function test_lock_release_storage_failure_leaves_attempt_running(): void {
		$attempt                             = $this->running_update();
		$adapter                             = $this->adapter( $attempt, new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		$this->database->fail_query_contains = 'DELETE FROM `wp_options`';

		try {
			$adapter->run( static fn (): string => 'mutated' );
			self::fail( 'A storage failure while releasing the updater lock must remain ambiguous.' );
		} catch ( BranchDeploymentLockStorageFailure ) {
			self::assertSame( DeploymentState::RUNNING->value, $this->database->rows[0]['state'] );
			self::assertArrayHasKey( 'auto_updater.lock', $this->database->option_rows );
		}
	}

	public function test_successful_coordinator_run_is_unchanged_and_logs_no_throw(): void {
		$outcome                   = null;
		$executor                  = new ParityCoreExecutor();
		$executor->after_execution = function (): void {
			$this->plugins->installed = $this->plugin( version: '2.0.0' );
		};
		$this->download_fixture( 'example' );
		$lines = $this->captured_logs(
			function () use ( $executor, &$outcome ): void {
				$outcome = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), executor: $executor )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $outcome->get_code() );
		self::assertSame( DeploymentState::SUCCEEDED->value, $this->database->rows[0]['state'] );
		self::assertStringNotContainsString( 'deployment execution threw', $lines );
	}

	public function test_lock_release_storage_failure_is_settled_as_interrupted_and_logged_by_class_and_code(): void {
		$this->enable_activity();
		$outcome = null;
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = 'DELETE FROM `wp_options`';
		$lines                               = $this->captured_logs(
			function () use ( &$outcome ): void {
				$outcome = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $outcome->get_code() );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $this->database->rows[0]['state'] );
		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $this->database->rows[0]['outcome_code'] );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
		self::assertStringContainsString( 'deployment execution threw', $lines );
		self::assertStringContainsString( '"exception_class":"RAN\\\\WPBranchUpdater\\\\V1\\\\Runtime\\\\BranchDeploymentLockStorageFailure"', $lines );
		self::assertStringNotContainsString( 'database details must not escape', $lines );
	}

	public function test_mutation_start_record_failure_is_settled_as_worker_stopped_without_message_text(): void {
		$this->enable_activity();
		$outcome = null;
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = 'SET mutation_started_at';
		$lines                               = $this->captured_logs(
			function () use ( &$outcome ): void {
				$outcome = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_WORKER_STOPPED, $outcome->get_code() );
		self::assertSame( DeploymentState::FAILED->value, $this->database->rows[0]['state'] );
		self::assertNull( $this->database->rows[0]['mutation_started_at'] );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
		self::assertStringContainsString( 'deployment execution threw', $lines );
		self::assertStringNotContainsString( 'database details must not escape', $lines );
	}

	public function test_webhook_attempt_settled_after_a_throw_is_notified_once(): void {
		$this->enable_activity();
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = 'DELETE FROM `wp_options`';
		$notifier                            = new ParityFailureNotifier( $this->database );

		$outcome = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), $notifier )->execute_claimed( $this->running_webhook_update( str_repeat( 'a', 40 ) ) );

		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $outcome->get_code(), (string) $this->database->rows[0]['outcome_code'] );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
		self::assertCount( 1, $notifier->attempts );
		self::assertSame( 'needs_attention', $notifier->stored_states[0] );
	}

	public function test_reconcile_review_and_activity_failure_share_transaction(): void {
		$this->enable_activity();
		$attempt = $this->running_update();
		$this->attempts->mark_mutation_started( $attempt->get_id() );
		$attention = $this->attempts->reconcile_confirmed_stopped( $attempt->get_id(), runner_throw: true );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
		try {
			$this->running_update();
			self::fail( 'Unresolved automatic settlement must block another attempt.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( $attention->get_id(), $failure->get_active_attempt()['id'] ?? null );
		}
		$this->attempts->resolve_needs_attention( $attention->get_id(), $attention->get_correlation_id(), 7 );
		self::assertArrayNotHasKey( 'deployment_settled_by', $this->activity_facts() );
	}

	public function test_activity_write_failure_rolls_back_automatic_settlement(): void {
		$this->enable_activity();
		$attempt                             = $this->running_update();
		$this->database->fail_query_contains = "SET status = 'failed'";
		try {
			$this->attempts->reconcile_confirmed_stopped( $attempt->get_id(), runner_throw: true );
			self::fail( 'Activity failure must roll back the attempt.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( 'running', $this->database->rows[0]['state'] );
			self::assertSame( 'running', $this->activity_facts()['deployment_state'] ?? null );
			self::assertArrayNotHasKey( 'deployment_settled_by', $this->activity_facts() );
		}
	}

	public function test_manual_execution_records_throw_settlement_without_message(): void {
				$this->enable_activity();
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = "SET state = 'failed'";
		$this->database->query_failure       = new ParityRunnerException( 'token=secret-shaped-private-value', 42 );
		$command                             = \RAN\PackageOperation::from_input(
			'install-plugin',
			array(
				'provider'               => 'gh',
				'provider_repository_id' => 'repo-id',
				'repository'             => 'owner/example',
				'branch'                 => 'main',
				'package_slug'           => 'example',
				'expected_identifier'    => 'example/Main.php',
			)
		);
		$result                              = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) )->execute_manual( $command );
		self::assertSame( 'worker_stopped', $result['outcome_code'] );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
		self::assertStringNotContainsString( 'secret-shaped-private-value', implode( ' ', $result ) );
	}

	public function test_cron_worker_records_throw_settlement_for_queued_manual_attempt(): void {
		require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';
		$this->enable_activity();
		$this->download_fixture( 'example' );
		$this->running_update();
		$this->database->rows[0]['state']    = 'queued';
		$this->database->fail_query_contains = 'SET mutation_started_at';
		$coordinator                         = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		$worker                              = new \RAN\Deployment\DeploymentWorker( $this->attempts, $coordinator, new WordPressWorkerWakeup( $this->attempts ) );
		$result                              = $worker->run_once();
		self::assertSame( 'processed', $result['status'] );
		self::assertSame( 'worker_stopped', $this->database->rows[0]['outcome_code'] );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null );
	}

	/** @return array<string,array{\Throwable,bool}> */
	public static function thrown_classes(): array {
		return array(
			'namespaced' => array( new ParityRunnerException( 'token=secret-shaped-private-value', 42 ), true ),
			'anonymous'  => array( new class( 'token=secret-shaped-private-value', 42 ) extends \RuntimeException {}, false ),
			'overlong'   => array( new LongExceptionXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX( 'token=secret-shaped-private-value', 42 ), false ),
		);
	}

	#[DataProvider( 'thrown_classes' )]
	public function test_throwing_runner_does_not_expose_message_or_prevent_settlement( \Throwable $failure, bool $class_is_safe ): void {
		$this->enable_activity();
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = "SET state = 'succeeded'";
		$this->database->query_failure       = $failure;
		$executor                            = new ParityCoreExecutor();
		$executor->after_execution           = function (): void {
			$this->plugins->installed = $this->plugin( version: '2.0.0' );
		};
		$outcome                             = null;
		$attempt                             = $this->running_update();
		$lines                               = $this->captured_logs(
			function () use ( $attempt, $executor, &$outcome ): void {
				$outcome = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), executor: $executor )->execute_claimed( $attempt );
			}
		);
		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $outcome->get_code() );
		self::assertSame( 'runner_throw', $this->activity_facts()['deployment_settled_by'] ?? null, $lines );
		self::assertStringNotContainsString( 'secret-shaped-private-value', $lines . (string) \RAN\Activity\wp_json_encode( $this->database->activity_rows ) );
		self::assertStringContainsString( $attempt->get_correlation_id(), $lines );
		self::assertStringContainsString( '"exception_code":"42"', $lines );
		if ( $class_is_safe ) {
			self::assertStringContainsString( '"exception_class":"RAN\\\\Tests\\\\Deployment\\\\ParityRunnerException"', $lines );
		}
	}


	public function test_row_already_settled_by_someone_else_keeps_the_original_failure(): void {
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = 'DELETE FROM `wp_options`';
		$executor                            = new ParityCoreExecutor();
		$executor->after_execution           = function (): void {
			$this->database->rows[0]['state']        = DeploymentState::FAILED->value;
			$this->database->rows[0]['outcome_code'] = DeploymentOutcome::CODE_WORKER_STOPPED;
			$this->database->rows[0]['finished_at']  = '2026-09-14 00:00:00';
		};
		$before                              = null;
		$caught                              = null;
		$coordinator                         = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ), executor: $executor );
		$attempt                             = $this->running_update();
		try {
			$coordinator->execute_claimed( $attempt );
		} catch ( \Throwable $failure ) {
			$caught = $failure;
		}
		$before = $this->database->rows[0];

		self::assertInstanceOf( BranchDeploymentLockStorageFailure::class, $caught );
		self::assertSame( DeploymentOutcome::CODE_WORKER_STOPPED, $before['outcome_code'] );
		self::assertSame( DeploymentState::FAILED->value, $before['state'] );
	}

	public function test_unwritable_attempt_row_is_reported_left_running_with_fixed_text_only(): void {
		$this->download_fixture( 'example' );
		$this->database->fail_query_contains = 'UPDATE `wp_ran_booster_deployment_attempts`';
		$attempt                             = $this->running_update();
		$coordinator                         = $this->coordinator( new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		$caught                              = null;
		$lines                               = $this->captured_logs(
			static function () use ( $coordinator, $attempt, &$caught ): void {
				try {
					$coordinator->execute_claimed( $attempt );
				} catch ( DeploymentStorageFailure $failure ) {
					$caught = $failure;
				}
			}
		);

		self::assertInstanceOf( DeploymentStorageFailure::class, $caught );
		self::assertTrue( $caught->is_left_running() );
		self::assertSame( $attempt->get_correlation_id(), $caught->get_active_correlation_id() );
		self::assertSame( DeploymentState::RUNNING->value, $this->database->rows[0]['state'] );
		self::assertStringContainsString( 'deployment attempt left running', $lines );
		self::assertStringNotContainsString( 'database details must not escape', $lines );
	}

	public function test_single_file_plugin_is_rejected_before_provider_contact(): void {
		$this->plugins->managed = array( 'fixture' => $this->plugin( identifier: 'example.php' ) );
		$provider               = new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) );
		$attempt                = $this->running_update();
		$coordinator            = $this->coordinator( $provider );

		$outcome = $coordinator->execute_claimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_PACKAGE_SINGLE_FILE_UNSUPPORTED, $outcome->get_code() );
		self::assertSame( 0, $provider->prepare_calls );
		self::assertSame( DeploymentState::FAILED->value, $this->database->rows[0]['state'] );
	}

	public function test_exact_adoption_conflict_remains_verified_success(): void {
		$outcome = $this->adoption_conflict_outcome( true );

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $outcome );
		self::assertSame( DeploymentState::SUCCEEDED->value, $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->plugins->adopt_calls );
	}

	public function test_mismatched_adoption_conflict_becomes_persistence_uncertain(): void {
		$outcome = $this->adoption_conflict_outcome( false );

		self::assertSame( DeploymentOutcome::CODE_PERSISTENCE_UNCERTAIN, $outcome );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->plugins->adopt_calls );
	}

	public function test_terminal_webhook_failure_notifies_only_after_durable_finish(): void {
		$provider                  = new ParityRepositoryProvider( null );
		$provider->prepare_failure = new RuntimeException( 'expired credential', 401 );
		$notifier                  = new ParityFailureNotifier( $this->database );
		$attempt                   = $this->running_webhook_update();
		$coordinator               = $this->coordinator( $provider, $notifier );

		$outcome = $coordinator->execute_claimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED, $outcome->get_code() );
		self::assertCount( 1, $notifier->attempts );
		self::assertSame( 'failed', $notifier->stored_states[0] );
		self::assertSame( DeploymentState::FAILED, $notifier->attempts[0]->get_state() );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED, $notifier->attempts[0]->get_outcome()?->get_code() );
	}

	public function test_a_provider_archive_failure_is_logged_by_reason_and_maps_to_the_closed_outcome(): void {
		$provider                  = new ParityRepositoryProvider( null );
		$provider->prepare_failure = new RuntimeException( 'sentinel-provider-message ghp_abcdefghijklmnop1234', 404 );
		$outcome                   = null;
		$lines                     = $this->captured_logs(
			function () use ( $provider, &$outcome ): void {
				$outcome = $this->coordinator( $provider )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_REPOSITORY_MISSING, $outcome->get_code() );
		self::assertStringContainsString( 'branch provider archive request failed', $lines );
		self::assertStringContainsString( '"reason":"repository_not_found_or_no_access","http_status":404', $lines );
		self::assertStringContainsString( '"step":"prepare_archive"', $lines );
		self::assertStringNotContainsString( 'sentinel-provider-message', $lines );
		self::assertStringNotContainsString( 'ghp_', $lines );
	}

	public function test_a_carried_provider_reason_wins_over_the_exception_code_for_the_outcome(): void {
		$provider                  = new ParityRepositoryProvider( null );
		$provider->prepare_failure = new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'rate_limited_secondary', 403, 30 ) );
		$outcome                   = null;
		$lines                     = $this->captured_logs(
			function () use ( $provider, &$outcome ): void {
				$outcome = $this->coordinator( $provider )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_RATE_LIMITED, $outcome->get_code() );
		self::assertStringContainsString( '"reason":"rate_limited_secondary","http_status":403,"retry_after":30', $lines );
	}

	public function test_a_head_verification_failure_is_logged_by_reason_and_maps_to_the_closed_outcome(): void {
		$archive            = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$archive->on_verify = static function (): void {
			throw new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'http_5xx', 500 ) );
		};
		$this->download_fixture( 'example' );
		$outcome = null;
		$lines   = $this->captured_logs(
			function () use ( $archive, &$outcome ): void {
				$outcome = $this->coordinator( new ParityRepositoryProvider( $archive ) )->execute_claimed( $this->running_update() );
			}
		);

		self::assertInstanceOf( DeploymentOutcome::class, $outcome );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $outcome->get_code() );
		self::assertStringContainsString( 'branch head verification failed', $lines );
		self::assertStringContainsString( '"reason":"http_5xx","http_status":500', $lines );
		self::assertStringContainsString( '"step":"verify_current_head"', $lines );
	}

	/** @param callable(): void $operation */
	private function captured_logs( callable $operation ): string {
		$directory = sys_get_temp_dir() . '/ran-booster-left-running-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		mkdir( $directory, 0700 );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json', static fn(): int => strtotime( '2026-07-23T12:00:00Z' ) );
		$capture->start();
		BoosterLogger::configure_capture( $capture );
		try {
			$operation();
			return implode( "\n", array_column( $capture->snapshot()['entries'], 'line' ) );
		} finally {
			BoosterLogger::configure_capture( null );
			foreach ( array_filter( (array) glob( $directory . '/*' ), 'is_string' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			rmdir( $directory );
		}
	}

	private function adoption_conflict_outcome( bool $exact ): string {
		$slug     = 'adopt-example';
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$executor = new ParityCoreExecutor();
		$attempt  = $this->running_install( $slug, DeploymentPolicy::MANUAL );
		$adapter  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->download_fixture( $slug );
		$this->plugins->installed       = $this->plugin(
			identifier: $slug . '/' . $slug . '.php',
			version: '2.0.0',
			repository: 'owner/install-plugin',
			repository_id: 'R_install_plugin',
			policy: DeploymentPolicy::MANUAL
		);
		$this->plugins->by_identifier   = $this->plugin(
			identifier: $slug . '/' . $slug . '.php',
			version: '2.0.0',
			repository: $exact ? 'owner/install-plugin' : 'owner/other',
			repository_id: $exact ? 'R_install_plugin' : 'R_other',
			policy: DeploymentPolicy::MANUAL
		);
		$this->plugins->adoption_result = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Existing package management data was found.'
		);

		return $this->deploy( $adapter );
	}

	private function deploy( AdmittedBranchHostAdapter $adapter ): string {
		$declaration = $adapter->declaration();
		$updater     = BranchUpdater::for_admitted_attempt( $declaration, $adapter, $adapter, $adapter, $adapter, $adapter );
		$deployment  = $updater->plugin(
			$declaration->repository,
			$declaration->repository_id,
			$declaration->branch,
			null,
			$declaration->slug,
			$declaration->subdirectory
		);

		return $deployment->deploy();
	}

	private function enable_activity(): void {
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$this->attempts                          = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-09-14 00:00:00 UTC' ),
			fn ( int $length ): string => str_repeat( chr( $this->random_byte++ ), $length ),
			$this->createStub( Database::class ),
			activity: new \RAN\Activity\ActivityStore( $this->database, 'wp_ran_booster_activity' ),
			selection: $this->queued_selection( $this->database )
		);
	}

	#[DataProvider( 'provider_source_cases' )]
	public function test_provider_archive_failure_records_original_code_in_atomic_activity( string $source, ?string $expected ): void {
		$this->enable_activity();
		$provider                  = new ParityRepositoryProvider( null );
		$provider->prepare_failure = new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'http_4xx_other', source_code: $source ) );
		$attempt                   = $this->running_update();
		$outcome                   = $this->coordinator( $provider )->execute_claimed( $attempt );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_REQUEST_INVALID, $outcome->get_code() );
		self::assertSame( $expected, $this->activity_facts()['source_code'] ?? null );
		self::assertStringNotContainsString( $source, (string) $this->database->rows[0]['request_json'] );
	}

	/** @return iterable<string,array{string,?string}> */
	public static function provider_source_cases(): iterable {
		yield 'valid' => array( 'abuse_detected', 'abuse_detected' );
		yield 'secret' => array( 'secret_orchid', null );
		yield 'control' => array( "api.error\n", null );
		yield 'encoded' => array( 'api%2eerror', null );
		yield 'oversized' => array( str_repeat( 'a', 64 ) . '_secret', null );
	}

	public function test_a_later_success_cannot_inherit_a_failed_provider_observation(): void {
		$this->enable_activity();
		$provider                  = new ParityRepositoryProvider( null );
		$provider->prepare_failure = new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'http_4xx_other', source_code: 'abuse_detected' ) );
		$adapter                   = $this->adapter( $this->running_update(), $provider );
		try {
			$adapter->prepare( $adapter->declaration(), $this->update_baseline() );
			self::fail( 'The provider must fail before the later terminal result.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_REQUEST_INVALID, $failure->outcome_code );
		}
		$adapter->finish( DeploymentOutcome::CODE_DEPLOYED );
		self::assertArrayNotHasKey( 'source_code', $this->activity_facts() );
		self::assertSame( 'deployed', $this->database->rows[0]['outcome_code'] );
	}

	public function test_armed_authority_read_failure_cannot_replace_the_provider_outcome(): void {
		$this->enable_activity();
		$provider                  = new ParityRepositoryProvider( null );
		$provider->on_prepare      = function (): void {
			$this->database->abort_authority_read = $this->database->authority_reads + 1;
		};
		$provider->prepare_failure = new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'http_4xx_other', source_code: 'abuse_detected' ) );
		$outcome                   = $this->coordinator( $provider )->execute_claimed( $this->running_update() );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_REQUEST_INVALID, $outcome->get_code() );
		self::assertArrayNotHasKey( 'deployment_settled_by', $this->activity_facts() );
		self::assertSame( 'abuse_detected', $this->activity_facts()['source_code'] ?? null );
		self::assertNotNull( $this->database->abort_authority_read );
		self::assertSame( 0, $this->database->automatic_rollbacks );
	}

	#[DataProvider( 'cleanup_failure_cases' )]
	public function test_head_failure_records_original_code_and_cleanup_supersession_drops_it( bool $cleanup_fails ): void {
			$this->enable_activity();
			$archive            = new ParityProviderArchive( str_repeat( 'a', 40 ) );
			$archive->on_verify = static function (): void {
				throw new \RAN\RepositoryProvider\ProviderRequestFailed( new \RAN\RepositoryProvider\ProviderFailure( 'http_4xx_other', source_code: 'abuse_detected' ) );
			};
		if ( $cleanup_fails ) {
			$archive->on_cleanup = static function (): void {
				throw new RuntimeException( 'cleanup failed' );
			};
		}
			$this->download_fixture( 'example' );
			$attempt = $this->running_update();
			$outcome = $this->coordinator( new ParityRepositoryProvider( $archive ) )->execute_claimed( $attempt );
			self::assertSame( $cleanup_fails ? DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED : DeploymentOutcome::CODE_PROVIDER_REQUEST_INVALID, $outcome->get_code() );
			$rows = $this->database->activity_rows;
			$row  = end( $rows );
			self::assertIsArray( $row );
			$facts = json_decode( (string) $row['facts'], true );
			self::assertSame( $cleanup_fails ? null : 'abuse_detected', $facts['source_code'] ?? null );
	}

	/** @return iterable<string,array{bool}> */
	public static function cleanup_failure_cases(): iterable {
		yield 'normal cleanup' => array( false );
		yield 'cleanup failure' => array( true );
	}

	/** @return array<string,mixed> */
	private function activity_facts(): array {
		$facts = json_decode( (string) $this->database->activity_rows[0]['facts'], true );
		self::assertIsArray( $facts );
		return $facts;
	}

	private function coordinator( ParityRepositoryProvider $provider, ?DeploymentFailureNotifier $notifier = null, ?WordPressCorePackageExecutor $executor = null ): DeploymentCoordinator {
		return new DeploymentCoordinator(
			$this->attempts,
			$this->plugins,
			$this->themes,
			new ProviderRegistry( array( $provider ) ),
			new WordPressWorkerWakeup( $this->attempts ),
			sys_get_temp_dir() . '/ran-booster-admitted-parity-maintenance',
			new WordPressUpdaterLock(),
			$notifier,
			$this->source_guard,
			$executor ?? new ParityCoreExecutor()
		);
	}

	private function adapter(
		DeploymentAttempt $attempt,
		RepositoryProvider $provider,
		?WordPressUpdaterLock $lock = null,
		?WordPressCorePackageExecutor $executor = null
	): AdmittedBranchHostAdapter {
		return new AdmittedBranchHostAdapter(
			$attempt,
			$this->attempts,
			$this->plugins,
			$this->themes,
			new ProviderRegistry( array( $provider ) ),
			$this->source_guard,
			$lock ?? new WordPressUpdaterLock(),
			$executor ?? new ParityCoreExecutor(),
			sys_get_temp_dir() . '/ran-booster-admitted-parity-maintenance'
		);
	}

	/** @param array<string, string> $tokens */
	private function guarded_github_provider( array $tokens = array() ): RepositoryProvider {
		$registrar = new class() {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The real provider requires the registrar hook; the isolated registrar only returns itself.
			public function with_operation_guard( callable $guard ): object {
				return $this;
			}
		};
		$provider  = GitHubProvider::create(
			new RepositoryResolverSecretsStub( $tokens ),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			operation_guard: static fn (): bool => true
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		return $provider;
	}

	/** @return array{response:array{code:int},body:string} */
	private function github_repository_response( bool $is_private = false, string $full_name = 'owner/example', string $repository_id = 'R_example' ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"id":"' . $repository_id . '","full_name":"' . $full_name . '","private":' . ( $is_private ? 'true' : 'false' ) . ',"default_branch":"main"}',
		);
	}

	/** @return array{response:array{code:int},body:string} */
	private function github_sha_response( string $commit ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $commit,
		);
	}

	/** @return array{response:array{code:int},body:string} */
	private function github_branch_response( string $commit ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"name":"main","commit":{"sha":"' . $commit . '"}}',
		);
	}

	private function running_update( ?string $selected_commit = null ): DeploymentAttempt {
		$identifier                                     = (string) $this->plugins->managed['fixture']->get_identifier();
		$this->database->management[ $identifier ]      = 'gh:R_example:11111111-1111-4111-8111-111111111111';
		$this->database->management_rows[ $identifier ] = array(
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => DeploymentPolicy::AUTOMATIC->value,
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$request                                        = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			'example',
			null,
			DeploymentPolicy::AUTOMATIC,
			7,
			self::MAXIMUM_ARTIFACT_BYTES,
			$identifier,
			'gh:R_example:11111111-1111-4111-8111-111111111111',
			selected_commit: $selected_commit
		);

		return $this->attempts->admit_and_claim_manual(
			'update',
			'plugin',
			'gh',
			'R_example',
			$request,
			$selected_commit ?? 'main',
			PackageSource::BRANCH->value,
			1
		);
	}

	private function running_install( string $slug, DeploymentPolicy $policy = DeploymentPolicy::AUTOMATIC, ?string $expected_identifier = null, bool $is_private = false, ?string $credential_id = null ): DeploymentAttempt {
		$request = new DeploymentRequest(
			'owner/install-plugin',
			$credential_id,
			$is_private,
			'main',
			$slug,
			null,
			$policy,
			7,
			self::MAXIMUM_ARTIFACT_BYTES,
			expected_identifier: $expected_identifier
		);

		return $this->attempts->admit_and_claim_manual(
			'install',
			'plugin',
			'gh',
			'R_install_plugin',
			$request,
			'main',
			PackageSource::BRANCH->value,
			0
		);
	}

	private function running_webhook_update( string $commit = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ): DeploymentAttempt {
		$identifier                                     = (string) $this->plugins->managed['fixture']->get_identifier();
		$this->database->management[ $identifier ]      = 'gh:R_example:11111111-1111-4111-8111-111111111111';
		$this->database->management_rows[ $identifier ] = array(
			'provider'               => 'gh',
			'provider_repository_id' => 'R_example',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => DeploymentPolicy::AUTOMATIC->value,
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
		$request                                        = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			'example',
			null,
			DeploymentPolicy::AUTOMATIC,
			null,
			self::MAXIMUM_ARTIFACT_BYTES,
			$identifier,
			'gh:R_example:11111111-1111-4111-8111-111111111111'
		);
		$this->attempts->admit_webhook_batch(
			'gh',
			'delivery-parity-failure',
			str_repeat( 'e', 64 ),
			array(
				array(
					'operation'               => 'update',
					'package_type'            => 'plugin',
					'provider_repository_id'  => 'R_example',
					'requested_ref'           => $commit,
					'package_source'          => PackageSource::BRANCH->value,
					'package_source_revision' => 1,
					'request'                 => $request,
				),
			)
		);
		$attempt = $this->attempts->claim_next();
		return $attempt ?? throw new RuntimeException( 'Missing webhook attempt.' );
	}

	/** @return array{identifier:string,version:string,active:bool} */
	private function update_baseline(): array {
		return array(
			'identifier' => 'example/example.php',
			'version'    => '1.0.0',
			'active'     => false,
		);
	}

	private function download_fixture( string $slug, string $version = '2.0.0' ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-parity-' );
		if ( false === $path ) {
			throw new RuntimeException( 'Unable to create ZIP fixture path.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		unlink( $path );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create ZIP fixture.' );
		}
		$zip->addFromString(
			$slug . '/' . $slug . '.php',
			"<?php\n/*\nPlugin Name: Example\nVersion: {$version}\n*/\n"
		);
		$zip->close();
		$this->fixtures[]                                 = $path;
		$GLOBALS['ran_booster_admitted_download_fixture'] = $path;
		return $path;
	}

	private function plugin(
		string $identifier = 'example/example.php',
		string $version = '1.0.0',
		string $repository = 'owner/example',
		string $repository_id = 'R_example',
		DeploymentPolicy $policy = DeploymentPolicy::AUTOMATIC
	): Plugin {
		$plugin = Plugin::from_wp_array(
			$identifier,
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
		$plugin->set_adoption_token( 'gh:' . rawurlencode( $repository_id ) . ':11111111-1111-4111-8111-111111111111' );
		$plugin->set_repository( new ManagedRepository( 'gh', $repository, $repository_id, 'main' ) );
		$plugin->set_deployment_policy( $policy );
		$plugin->set_source( PackageSource::BRANCH, 1 );
		return $plugin;
	}

	private function ensure_directory( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create parity test directory.' );
		}
	}

	private function remove_tree( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}
		$items = scandir( $path );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$child = $path . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $child ) && ! is_link( $child ) ) {
				$this->remove_tree( $child );
			} elseif ( file_exists( $child ) || is_link( $child ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $child );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		rmdir( $path );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityPluginRepository extends PluginRepository {
	/** @var array<string, Plugin> */
	public array $managed                          = array();
	public ?Plugin $installed                      = null;
	public ?Plugin $by_identifier                  = null;
	public ?PackageMutationResult $adoption_result = null;
	public int $adopt_calls                        = 0;

	public function __construct() {}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_plugins( ?PackageSource $source = null ): array {
		return $this->managed;
	}

	public function from_slug( $slug ) {
		if ( null !== $this->installed ) {
			return $this->installed;
		}
		foreach ( $this->managed as $plugin ) {
			if ( (string) $plugin->get_slug() === (string) $slug ) {
				return $plugin;
			}
		}
		throw new RuntimeException( 'Missing installed plugin.' );
	}

	public function booster_plugin_from_file( $file ) {
		if ( null !== $this->by_identifier ) {
			return $this->by_identifier;
		}
		foreach ( $this->managed as $plugin ) {
			if ( (string) $plugin->get_identifier() === (string) $file ) {
				return $plugin;
			}
		}
		throw new RuntimeException( 'Missing managed plugin.' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of adopt retains the production method contract; these inputs do not affect this controlled result.
	public function adopt( Plugin $plugin ): PackageMutationResult {
		++$this->adopt_calls;
		return $this->adoption_result ?? PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityThemeRepository extends ThemeRepository {
	public ?\RAN\Theme $managed = null;
	public function __construct() {}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The SQL boundary fixture returns its one managed theme.
	public function all_deployment_themes( ?PackageSource $source = null ): array {
		return null === $this->managed ? array() : array( 'fixture' => $this->managed );
	}
	public function booster_theme_from_stylesheet( $stylesheet ) {
		return $this->managed ?? throw new RuntimeException( 'Missing managed theme: ' . $stylesheet );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityProviderArchive implements ProviderPreparedArchive {
	public string $url        = 'https://example.test/archive.zip';
	public int $cleanup_calls = 0;
	/** @var null|callable(): void */
	public $on_cleanup = null;
	/** @var null|callable(): void */
	public $on_verify = null;

	public function __construct( private string $resolved_ref ) {}

	public function get_url(): string {
		return $this->url;
	}

	public function get_resolved_ref(): string {
		return $this->resolved_ref;
	}

	public function verify_current_head(): void {
		if ( null !== $this->on_verify ) {
			( $this->on_verify )();
		}
	}

	public function cleanup(): void {
		++$this->cleanup_calls;
		if ( null !== $this->on_cleanup ) {
			( $this->on_cleanup )();
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityRepositoryProvider implements RepositoryProvider {
	use SuppliesProviderDiagnostics;

	public int $prepare_calls                 = 0;
	public ?RuntimeException $prepare_failure = null;
	/** @var null|callable(): void */
	public $on_prepare = null;

	public ?ArchiveRequest $last_request = null;
	public function __construct( private ?ParityProviderArchive $archive, private int $archive_api_version = 1 ) {}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner', archive_api_version: $this->archive_api_version );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new RuntimeException( 'Repository resolution is not part of admitted parity coverage.' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
	public function prepare_archive( ArchiveRequest $request ): ProviderPreparedArchive {
		$this->last_request = $request;
		++$this->prepare_calls;
		if ( null !== $this->on_prepare ) {
			( $this->on_prepare )();
		}
		if ( null !== $this->prepare_failure ) {
			throw $this->prepare_failure;
		}
		return $this->archive ?? throw new RuntimeException( 'Missing prepared archive.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityCoreExecutor extends WordPressCorePackageExecutor {
	public int $calls = 0;
	/** @var null|callable(): void */
	public $after_execution = null;

	public function __construct() {}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of update_plugin retains the production method contract; these inputs do not affect this controlled result.
	public function update_plugin( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory, string $plugin_file ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->after_execution ) {
			( $this->after_execution )();
		}
		return CorePackageExecutionResult::succeeded();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of install_plugin retains the production method contract; these inputs do not affect this controlled result.
	public function install_plugin( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->after_execution ) {
			( $this->after_execution )();
		}
		return CorePackageExecutionResult::succeeded();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class ParityFailureNotifier implements DeploymentFailureNotifier {
	/** @var list<DeploymentAttempt> */
	public array $attempts = array();
	/** @var list<string> */
	public array $stored_states = array();

	public function __construct( private AttemptRepositoryDatabase $database ) {}

	public function notify( DeploymentAttempt $attempt ): bool {
		$this->attempts[]      = $attempt;
		$this->stored_states[] = (string) ( $this->database->rows[0]['state'] ?? '' );
		return true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Exercises logger screening of an overlong exception class.
final class LongExceptionXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX extends \RuntimeException {}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Exercises namespaced exception logging.
final class ParityRunnerException extends \RuntimeException {}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The adapter's WordPress function double stays with its destination tests.
namespace RAN\Deployment;

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- This bounded WordPress double supplies alternate theme roots for the real adapter.
function get_theme_root( string $stylesheet = '' ): string {
	return $GLOBALS['ran_booster_parity_theme_roots'][ $stylesheet ] ?? \get_theme_root();
}

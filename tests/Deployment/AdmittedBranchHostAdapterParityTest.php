<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused host-boundary collaborators live with the parity tests.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately create and remove isolated fixture files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The updater lock deliberately uses the scoped wpdb double.

namespace RAN\Tests\Deployment;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/AdmittedBranchHostAdapterWordPressFunctions.php';
require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentFailureNotifier;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
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
use RAN\Tests\Support\RepositorySourceGuardDatabase;
use ZipArchive;

final class AdmittedBranchHostAdapterParityTest extends TestCase {
	private const MAXIMUM_ARTIFACT_BYTES = 1048576;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private ParityPluginRepository $plugins;
	private ParityThemeRepository $themes;
	private RepositorySourceGuardDatabase $source_database;
	private RepositorySourceGuard $source_guard;
	private int $random_byte = 1;
	/** @var list<string> */
	private array $fixtures = array();

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_worker_doing_cron']                = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_admitted_filesystem_method']       = 'direct';
		$GLOBALS['ran_booster_admitted_http_calls']              = array();
		$GLOBALS['ran_booster_admitted_http_responses']          = array( 200 );
		$GLOBALS['ran_booster_admitted_temp_root']               = sys_get_temp_dir() . '/ran-booster-admitted-parity-temp';
		$this->ensure_directory( ABSPATH . 'wp-admin/includes' );
		$this->ensure_directory( WP_CONTENT_DIR );
		$this->ensure_directory( WP_PLUGIN_DIR );
		$this->ensure_directory( (string) $GLOBALS['ran_booster_admitted_temp_root'] );
		$file = ABSPATH . 'wp-admin/includes/file.php';
		if ( ! file_exists( $file ) ) {
			file_put_contents( $file, "<?php\n" );
		}

		$this->database         = new AttemptRepositoryDatabase();
		$GLOBALS['wpdb']        = $this->database;
		$this->attempts         = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-09-14 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->random_byte++ ), $length );
			}
		);
		$this->plugins          = new ParityPluginRepository();
		$this->themes           = new ParityThemeRepository();
		$this->source_database  = new RepositorySourceGuardDatabase();
		$this->source_guard     = new RepositorySourceGuard( $this->source_database, $this->createStub( Database::class ) );
		$this->plugins->managed = array( $this->plugin() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) || is_link( $fixture ) ) {
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

	public function test_concrete_provider_download_retries_transient_status_before_success(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->running_update(), $provider );
		$this->download_fixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 503, 200 );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->update_baseline() );

		self::assertCount( 2, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( 1, $archive->cleanup_calls );
		$artifact->cleanup();
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

	public function test_post_mutation_managed_snapshot_drift_finishes_interrupted(): void {
		$archive                  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider                 = new ParityRepositoryProvider( $archive );
		$executor                 = new ParityCoreExecutor();
		$attempt                  = $this->running_update();
		$adapter                  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->plugins->installed = $this->plugin( version: '2.0.0' );
		$this->download_fixture( 'example' );
		$executor->after_execution = function (): void {
			$this->plugins->managed = array( $this->plugin( repository: 'other/example' ) );
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

	public function test_single_file_plugin_is_rejected_before_provider_contact(): void {
		$this->plugins->managed = array( $this->plugin( identifier: 'example.php' ) );
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

	private function coordinator( ParityRepositoryProvider $provider, ?DeploymentFailureNotifier $notifier = null ): DeploymentCoordinator {
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
			new ParityCoreExecutor()
		);
	}

	private function adapter(
		DeploymentAttempt $attempt,
		ParityRepositoryProvider $provider,
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

	private function running_update(): DeploymentAttempt {
		$request = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			'example',
			null,
			DeploymentPolicy::AUTOMATIC,
			7,
			self::MAXIMUM_ARTIFACT_BYTES
		);

		return $this->attempts->admit_and_claim_manual(
			'update',
			'plugin',
			'gh',
			'R_example',
			$request,
			'main',
			PackageSource::BRANCH->value,
			1
		);
	}

	private function running_install( string $slug, DeploymentPolicy $policy = DeploymentPolicy::AUTOMATIC ): DeploymentAttempt {
		$request = new DeploymentRequest(
			'owner/install-plugin',
			null,
			false,
			'main',
			$slug,
			null,
			$policy,
			7,
			self::MAXIMUM_ARTIFACT_BYTES
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

	private function running_webhook_update(): DeploymentAttempt {
		$request = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			'example',
			null,
			DeploymentPolicy::AUTOMATIC,
			null,
			self::MAXIMUM_ARTIFACT_BYTES
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
					'requested_ref'           => str_repeat( 'b', 40 ),
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
		$plugin->set_repository( new ManagedRepository( 'gh', $repository, $repository_id, 'main' ) );
		$plugin->set_deployment_policy( $policy );
		$plugin->set_source( PackageSource::BRANCH, 1 );
		return $plugin;
	}

	private function ensure_directory( string $path ): void {
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
				unlink( $child );
			}
		}
		rmdir( $path );
	}
}

final class ParityPluginRepository extends PluginRepository {
	/** @var list<Plugin> */
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

final class ParityThemeRepository extends ThemeRepository {
	public function __construct() {}
}

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

final class ParityRepositoryProvider implements RepositoryProvider {
	use SuppliesProviderDiagnostics;

	public int $prepare_calls                 = 0;
	public ?RuntimeException $prepare_failure = null;

	public function __construct( private ?ParityProviderArchive $archive ) {}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new RuntimeException( 'Repository resolution is not part of admitted parity coverage.' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
	public function prepare_archive( ArchiveRequest $request ): ProviderPreparedArchive {
		++$this->prepare_calls;
		if ( null !== $this->prepare_failure ) {
			throw $this->prepare_failure;
		}
		return $this->archive ?? throw new RuntimeException( 'Missing prepared archive.' );
	}
}

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

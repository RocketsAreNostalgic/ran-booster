<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused host-boundary collaborators live with the parity tests.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately create and remove isolated fixture files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The updater lock deliberately uses the scoped wpdb double.

namespace Tests\Deployment;

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
use Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;
use Tests\Support\RepositorySourceGuardDatabase;
use ZipArchive;

final class AdmittedBranchHostAdapterParityTest extends TestCase {
	private const MAXIMUM_ARTIFACT_BYTES = 1048576;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private ParityPluginRepository $plugins;
	private ParityThemeRepository $themes;
	private RepositorySourceGuardDatabase $sourceDatabase;
	private RepositorySourceGuard $sourceGuard;
	private int $randomByte = 1;
	/** @var list<string> */
	private array $fixtures = array();

	protected function setUp(): void {
		$GLOBALS['ran_booster_worker_doing_cron']                = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_admitted_filesystem_method']       = 'direct';
		$GLOBALS['ran_booster_admitted_http_calls']              = array();
		$GLOBALS['ran_booster_admitted_http_responses']          = array( 200 );
		$GLOBALS['ran_booster_admitted_temp_root']               = sys_get_temp_dir() . '/ran-booster-admitted-parity-temp';
		$this->ensureDirectory( ABSPATH . 'wp-admin/includes' );
		$this->ensureDirectory( WP_CONTENT_DIR );
		$this->ensureDirectory( WP_PLUGIN_DIR );
		$this->ensureDirectory( (string) $GLOBALS['ran_booster_admitted_temp_root'] );
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
				return str_repeat( chr( $this->randomByte++ ), $length );
			}
		);
		$this->plugins          = new ParityPluginRepository();
		$this->themes           = new ParityThemeRepository();
		$this->sourceDatabase   = new RepositorySourceGuardDatabase();
		$this->sourceGuard      = new RepositorySourceGuard( $this->sourceDatabase, $this->createStub( Database::class ) );
		$this->plugins->managed = array( $this->plugin() );
	}

	protected function tearDown(): void {
		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) || is_link( $fixture ) ) {
				unlink( $fixture );
			}
		}
		$this->removeTree( (string) ( $GLOBALS['ran_booster_admitted_temp_root'] ?? '' ) . '/ran-booster-branch-updater' );
		foreach ( array( 'new-branch', 'adopt-example' ) as $slug ) {
			$this->removeTree( WP_PLUGIN_DIR . '/' . $slug );
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

	public function testConcreteProviderDownloadIsBoundedAndCleansAfterSuccess(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->runningUpdate(), $provider );
		$this->downloadFixture( 'example' );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->updateBaseline() );

		self::assertCount( 1, $GLOBALS['ran_booster_admitted_http_calls'] );
		$arguments = $GLOBALS['ran_booster_admitted_http_calls'][0]['arguments'];
		self::assertTrue( $arguments['stream'] );
		self::assertTrue( $arguments['reject_unsafe_urls'] );
		self::assertSame( self::MAXIMUM_ARTIFACT_BYTES + 1, $arguments['limit_response_size'] );
		self::assertSame( 1, $archive->cleanupCalls );
		$artifact->cleanup();
	}

	public function testConcreteProviderDownloadRetriesTransientStatusBeforeSuccess(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->runningUpdate(), $provider );
		$this->downloadFixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 503, 200 );

		$artifact = $adapter->prepare( $adapter->declaration(), $this->updateBaseline() );

		self::assertCount( 2, $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( 1, $archive->cleanupCalls );
		$artifact->cleanup();
	}

	public function testConcreteProviderDownloadMapsWpErrorAndCleans(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->runningUpdate(), $provider );
		$this->downloadFixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 'wp_error' );

		try {
			$adapter->prepare( $adapter->declaration(), $this->updateBaseline() );
			self::fail( 'A WordPress transport error must fail the admitted archive acquisition.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_DOWNLOAD_FAILED, $failure->outcomeCode );
		}

		self::assertSame( 1, $archive->cleanupCalls );
	}

	public function testConcreteProviderDownloadMapsTerminalStatusAndCleans(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$adapter  = $this->adapter( $this->runningUpdate(), $provider );
		$this->downloadFixture( 'example' );
		$GLOBALS['ran_booster_admitted_http_responses'] = array( 404 );

		try {
			$adapter->prepare( $adapter->declaration(), $this->updateBaseline() );
			self::fail( 'A terminal provider response must fail the admitted archive acquisition.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_PROVIDER_REPOSITORY_MISSING, $failure->outcomeCode );
		}

		self::assertSame( 1, $archive->cleanupCalls );
	}

	public function testConcreteProviderDownloadRejectsUnsafeUrlBeforeHttpAndCleans(): void {
		$archive      = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$archive->url = 'http://example.test/archive.zip';
		$provider     = new ParityRepositoryProvider( $archive );
		$adapter      = $this->adapter( $this->runningUpdate(), $provider );
		$this->downloadFixture( 'example' );

		try {
			$adapter->prepare( $adapter->declaration(), $this->updateBaseline() );
			self::fail( 'An unsafe provider URL must be rejected before HTTP.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID, $failure->outcomeCode );
		}

		self::assertSame( array(), $GLOBALS['ran_booster_admitted_http_calls'] );
		self::assertSame( 1, $archive->cleanupCalls );
	}

	public function testPostMutationManagedSnapshotDriftFinishesInterrupted(): void {
		$archive                  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider                 = new ParityRepositoryProvider( $archive );
		$executor                 = new ParityCoreExecutor();
		$attempt                  = $this->runningUpdate();
		$adapter                  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->plugins->installed = $this->plugin( version: '2.0.0' );
		$this->downloadFixture( 'example' );
		$executor->afterExecution = function (): void {
			$this->plugins->managed = array( $this->plugin( repository: 'other/example' ) );
		};

		$code = $this->deploy( $adapter );

		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $code );
		self::assertSame( 1, $executor->calls );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $this->database->rows[0]['state'] );
		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $this->database->rows[0]['outcome_code'] );
	}

	public function testRepositorySourceOwnerAppearingAfterPreparationBlocksMutation(): void {
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$executor = new ParityCoreExecutor();
		$attempt  = $this->runningInstall( 'new-branch' );
		$adapter  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->downloadFixture( 'new-branch' );
		$archive->onCleanup = function (): void {
			$this->sourceDatabase->rows[] = (object) array(
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
		self::assertSame( 1, $archive->cleanupCalls );
	}

	public function testExactReplacementLockTokenIsPreservedAndAttemptRemainsRunning(): void {
		$attempt = $this->runningUpdate();
		$adapter = $this->adapter( $attempt, new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );

		try {
			$adapter->run(
				function (): void {
					$this->database->optionRows['auto_updater.lock'] = '1777777777';
				}
			);
			self::fail( 'Replacing the exact updater-lock token must fail closed.' );
		} catch ( BranchDeploymentLockReleaseFailure ) {
			self::assertSame( '1777777777', $this->database->optionRows['auto_updater.lock'] );
			self::assertSame( DeploymentState::RUNNING->value, $this->database->rows[0]['state'] );
		}
	}

	public function testLockReleaseStorageFailureLeavesAttemptRunning(): void {
		$attempt                           = $this->runningUpdate();
		$adapter                           = $this->adapter( $attempt, new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) ) );
		$this->database->failQueryContains = 'DELETE FROM `wp_options`';

		try {
			$adapter->run( static fn (): string => 'mutated' );
			self::fail( 'A storage failure while releasing the updater lock must remain ambiguous.' );
		} catch ( BranchDeploymentLockStorageFailure ) {
			self::assertSame( DeploymentState::RUNNING->value, $this->database->rows[0]['state'] );
			self::assertArrayHasKey( 'auto_updater.lock', $this->database->optionRows );
		}
	}

	public function testSingleFilePluginIsRejectedBeforeProviderContact(): void {
		$this->plugins->managed = array( $this->plugin( identifier: 'example.php' ) );
		$provider               = new ParityRepositoryProvider( new ParityProviderArchive( str_repeat( 'a', 40 ) ) );
		$attempt                = $this->runningUpdate();
		$coordinator            = $this->coordinator( $provider );

		$outcome = $coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_PACKAGE_SINGLE_FILE_UNSUPPORTED, $outcome->getCode() );
		self::assertSame( 0, $provider->prepareCalls );
		self::assertSame( DeploymentState::FAILED->value, $this->database->rows[0]['state'] );
	}

	public function testExactAdoptionConflictRemainsVerifiedSuccess(): void {
		$outcome = $this->adoptionConflictOutcome( true );

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $outcome );
		self::assertSame( DeploymentState::SUCCEEDED->value, $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->plugins->adoptCalls );
	}

	public function testMismatchedAdoptionConflictBecomesPersistenceUncertain(): void {
		$outcome = $this->adoptionConflictOutcome( false );

		self::assertSame( DeploymentOutcome::CODE_PERSISTENCE_UNCERTAIN, $outcome );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $this->database->rows[0]['state'] );
		self::assertSame( 1, $this->plugins->adoptCalls );
	}

	public function testTerminalWebhookFailureNotifiesOnlyAfterDurableFinish(): void {
		$provider                 = new ParityRepositoryProvider( null );
		$provider->prepareFailure = new RuntimeException( 'expired credential', 401 );
		$notifier                 = new ParityFailureNotifier( $this->database );
		$attempt                  = $this->runningWebhookUpdate();
		$coordinator              = $this->coordinator( $provider, $notifier );

		$outcome = $coordinator->executeClaimed( $attempt );

		self::assertSame( DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED, $outcome->getCode() );
		self::assertCount( 1, $notifier->attempts );
		self::assertSame( 'failed', $notifier->storedStates[0] );
		self::assertSame( DeploymentState::FAILED, $notifier->attempts[0]->getState() );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED, $notifier->attempts[0]->getOutcome()?->getCode() );
	}

	private function adoptionConflictOutcome( bool $exact ): string {
		$slug     = 'adopt-example';
		$archive  = new ParityProviderArchive( str_repeat( 'a', 40 ) );
		$provider = new ParityRepositoryProvider( $archive );
		$executor = new ParityCoreExecutor();
		$attempt  = $this->runningInstall( $slug, DeploymentPolicy::MANUAL );
		$adapter  = $this->adapter( $attempt, $provider, executor: $executor );
		$this->downloadFixture( $slug );
		$this->plugins->installed      = $this->plugin(
			identifier: $slug . '/' . $slug . '.php',
			version: '2.0.0',
			repository: 'owner/install-plugin',
			repositoryId: 'R_install_plugin',
			policy: DeploymentPolicy::MANUAL
		);
		$this->plugins->byIdentifier   = $this->plugin(
			identifier: $slug . '/' . $slug . '.php',
			version: '2.0.0',
			repository: $exact ? 'owner/install-plugin' : 'owner/other',
			repositoryId: $exact ? 'R_install_plugin' : 'R_other',
			policy: DeploymentPolicy::MANUAL
		);
		$this->plugins->adoptionResult = PackageMutationResult::conflict(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_adoption_conflict',
			'Existing package management data was found.'
		);

		return $this->deploy( $adapter );
	}

	private function deploy( AdmittedBranchHostAdapter $adapter ): string {
		$declaration = $adapter->declaration();
		$updater     = BranchUpdater::forAdmittedAttempt( $declaration, $adapter, $adapter, $adapter, $adapter, $adapter );
		$deployment  = $updater->plugin(
			$declaration->repository,
			$declaration->repositoryId,
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
			$this->sourceGuard,
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
			$this->sourceGuard,
			$lock ?? new WordPressUpdaterLock(),
			$executor ?? new ParityCoreExecutor(),
			sys_get_temp_dir() . '/ran-booster-admitted-parity-maintenance'
		);
	}

	private function runningUpdate(): DeploymentAttempt {
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

		return $this->attempts->admitAndClaimManual(
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

	private function runningInstall( string $slug, DeploymentPolicy $policy = DeploymentPolicy::AUTOMATIC ): DeploymentAttempt {
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

		return $this->attempts->admitAndClaimManual(
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

	private function runningWebhookUpdate(): DeploymentAttempt {
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
		$this->attempts->admitWebhookBatch(
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
		$attempt = $this->attempts->claimNext();
		return $attempt ?? throw new RuntimeException( 'Missing webhook attempt.' );
	}

	/** @return array{identifier:string,version:string,active:bool} */
	private function updateBaseline(): array {
		return array(
			'identifier' => 'example/example.php',
			'version'    => '1.0.0',
			'active'     => false,
		);
	}

	private function downloadFixture( string $slug, string $version = '2.0.0' ): string {
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
		string $repositoryId = 'R_example',
		DeploymentPolicy $policy = DeploymentPolicy::AUTOMATIC
	): Plugin {
		$plugin = Plugin::fromWpArray(
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
		$plugin->setRepository( new ManagedRepository( 'gh', $repository, $repositoryId, 'main' ) );
		$plugin->setDeploymentPolicy( $policy );
		$plugin->setSource( PackageSource::BRANCH, 1 );
		return $plugin;
	}

	private function ensureDirectory( string $path ): void {
		if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create parity test directory.' );
		}
	}

	private function removeTree( string $path ): void {
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
				$this->removeTree( $child );
			} elseif ( file_exists( $child ) || is_link( $child ) ) {
				unlink( $child );
			}
		}
		rmdir( $path );
	}
}

final class ParityPluginRepository extends PluginRepository {
	/** @var list<Plugin> */
	public array $managed                         = array();
	public ?Plugin $installed                     = null;
	public ?Plugin $byIdentifier                  = null;
	public ?PackageMutationResult $adoptionResult = null;
	public int $adoptCalls                        = 0;

	public function __construct() {}

	public function allDeploymentPlugins( ?PackageSource $source = null ): array {
		return $this->managed;
	}

	public function fromSlug( $slug ) {
		if ( null !== $this->installed ) {
			return $this->installed;
		}
		foreach ( $this->managed as $plugin ) {
			if ( (string) $plugin->getSlug() === (string) $slug ) {
				return $plugin;
			}
		}
		throw new RuntimeException( 'Missing installed plugin.' );
	}

	public function boosterPluginFromFile( $file ) {
		if ( null !== $this->byIdentifier ) {
			return $this->byIdentifier;
		}
		foreach ( $this->managed as $plugin ) {
			if ( (string) $plugin->getIdentifier() === (string) $file ) {
				return $plugin;
			}
		}
		throw new RuntimeException( 'Missing managed plugin.' );
	}

	public function adopt( Plugin $plugin ): PackageMutationResult {
		++$this->adoptCalls;
		return $this->adoptionResult ?? PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}
}

final class ParityThemeRepository extends ThemeRepository {
	public function __construct() {}
}

final class ParityProviderArchive implements ProviderPreparedArchive {
	public string $url       = 'https://example.test/archive.zip';
	public int $cleanupCalls = 0;
	/** @var null|callable(): void */
	public $onCleanup = null;
	/** @var null|callable(): void */
	public $onVerify = null;

	public function __construct( private string $resolvedRef ) {}

	public function getUrl(): string {
		return $this->url;
	}

	public function getResolvedRef(): string {
		return $this->resolvedRef;
	}

	public function verifyCurrentHead(): void {
		if ( null !== $this->onVerify ) {
			( $this->onVerify )();
		}
	}

	public function cleanup(): void {
		++$this->cleanupCalls;
		if ( null !== $this->onCleanup ) {
			( $this->onCleanup )();
		}
	}
}

final class ParityRepositoryProvider implements RepositoryProvider {
	use SuppliesProviderDiagnostics;

	public int $prepareCalls                 = 0;
	public ?RuntimeException $prepareFailure = null;

	public function __construct( private ?ParityProviderArchive $archive ) {}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new RuntimeException( 'Repository resolution is not part of admitted parity coverage.' );
	}

	public function prepareArchive( ArchiveRequest $request ): ProviderPreparedArchive {
		++$this->prepareCalls;
		if ( null !== $this->prepareFailure ) {
			throw $this->prepareFailure;
		}
		return $this->archive ?? throw new RuntimeException( 'Missing prepared archive.' );
	}
}

final class ParityCoreExecutor extends WordPressCorePackageExecutor {
	public int $calls = 0;
	/** @var null|callable(): void */
	public $afterExecution = null;

	public function __construct() {}

	public function updatePlugin( PreparedPackageArtifact $artifact, string $packageSlug, ?string $subdirectory, string $pluginFile ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->afterExecution ) {
			( $this->afterExecution )();
		}
		return CorePackageExecutionResult::succeeded();
	}

	public function installPlugin( PreparedPackageArtifact $artifact, string $packageSlug, ?string $subdirectory ): CorePackageExecutionResult {
		++$this->calls;
		if ( null !== $this->afterExecution ) {
			( $this->afterExecution )();
		}
		return CorePackageExecutionResult::succeeded();
	}
}

final class ParityFailureNotifier implements DeploymentFailureNotifier {
	/** @var list<DeploymentAttempt> */
	public array $attempts = array();
	/** @var list<string> */
	public array $storedStates = array();

	public function __construct( private AttemptRepositoryDatabase $database ) {}

	public function notify( DeploymentAttempt $attempt ): bool {
		$this->attempts[]     = $attempt;
		$this->storedStates[] = (string) ( $this->database->rows[0]['state'] ?? '' );
		return true;
	}
}

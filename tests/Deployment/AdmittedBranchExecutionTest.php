<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused admitted-boundary collaborators live with the test.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The updater lock deliberately uses the scoped wpdb double.

namespace Tests\Deployment;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/DeploymentCoordinatorWordPressFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\ManagedRepository;
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
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RuntimeException;
use Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

final class AdmittedBranchExecutionTest extends TestCase {
	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private BoundaryPluginRepository $plugins;
	private BoundaryThemeRepository $themes;
	private int $randomByte = 1;

	protected function setUp(): void {
		$this->database         = new AttemptRepositoryDatabase();
		$GLOBALS['wpdb']        = $this->database;
		$this->attempts         = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-09-12 12:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->randomByte++ ), $length );
			}
		);
		$this->plugins          = new BoundaryPluginRepository();
		$this->themes           = new BoundaryThemeRepository();
		$this->plugins->package = $this->plugin();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testExternalAdmittedRunnerOwnsTheExecutionSequence(): void {
		$declaration = new BranchDeploymentDeclaration(
			'42',
			'plugin',
			'example',
			'owner/example',
			'R_example',
			'main',
			null,
			'update',
			null,
			'example/example.php'
		);
		$host        = new BoundaryAdmittedHost();

		$updater = BranchUpdater::forAdmittedAttempt( $declaration, $host, $host, $host, $host, $host );
		$code    = $updater->plugin( 'owner/example', 'R_example', 'main', null, 'example' )->deploy();

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $code );
		self::assertSame(
			array(
				'allowed',
				'frozen:defer',
				'prepare',
				'preflight',
				'resolved',
				'lock:start',
				'frozen:live',
				'verify',
				'unchanged',
				'maintenance',
				'allowed',
				'preflight',
				'mutation',
				'execute',
				'maintenance',
				'recheck',
				'installed',
				'cleanup',
				'lock:end',
				'finish:deployed',
			),
			$host->events
		);
	}

	public function testConcreteAdapterBuildsDeclarationAndProjectsTerminalStateDurably(): void {
		$attempt = $this->runningUpdate();
		$adapter = $this->adapter( $attempt, new ProviderRegistry() );

		$declaration = $adapter->declaration();

		self::assertSame( (string) $attempt->getId(), $declaration->attemptId );
		self::assertSame( 'plugin', $declaration->packageType );
		self::assertSame( 'example', $declaration->slug );
		self::assertSame( 'owner/example', $declaration->repository );
		self::assertSame( 'R_example', $declaration->repositoryId );
		self::assertSame( 'main', $declaration->branch );
		self::assertSame( 'example/example.php', $declaration->installedIdentifier );

		$adapter->finish( DeploymentOutcome::CODE_PROVIDER_FAILED );
		$terminal = $adapter->terminalAttempt();

		self::assertSame( DeploymentState::FAILED, $terminal->getState() );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_FAILED, $terminal->getOutcome()?->getCode() );
		self::assertSame( 'failed', $this->database->rows[0]['state'] );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_FAILED, $this->database->rows[0]['outcome_code'] );
	}

	public function testConcreteAdapterUsesTheSharedWordPressUpdaterLock(): void {
		$adapter = $this->adapter( $this->runningUpdate(), new ProviderRegistry() );

		$result = $adapter->run( static fn (): string => 'inside-lock' );

		self::assertSame( 'inside-lock', $result );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->database->optionRows );
		$queries = implode( "\n", $this->database->queries );
		self::assertStringContainsString( 'INSERT IGNORE INTO `wp_options`', $queries );
		self::assertStringContainsString( 'DELETE FROM `wp_options`', $queries );
	}

	public function testProviderArchiveIsCleanedWhenItsResolvedRevisionIsInvalid(): void {
		$archive     = new BoundaryProviderArchive( '' );
		$provider    = new BoundaryRepositoryProvider( $archive );
		$adapter     = $this->adapter( $this->runningUpdate(), new ProviderRegistry( array( $provider ) ) );
		$declaration = $adapter->declaration();

		try {
			$adapter->prepare(
				$declaration,
				array(
					'identifier' => 'example/example.php',
					'version'    => '1.0.0',
					'active'     => false,
				)
			);
			self::fail( 'An invalid resolved revision must fail before artifact acquisition.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID, $failure->outcomeCode );
		}

		self::assertSame( 1, $archive->cleanupCalls );
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
			7
		);

		return $this->attempts->admitAndClaimManual(
			'update',
			'plugin',
			'gh',
			'R_example',
			$request,
			'main',
			'branch',
			1
		);
	}

	private function adapter( DeploymentAttempt $attempt, ProviderRegistry $providers ): AdmittedBranchHostAdapter {
		return new AdmittedBranchHostAdapter(
			$attempt,
			$this->attempts,
			$this->plugins,
			$this->themes,
			$providers,
			new RepositorySourceGuard( $this->database, $this->createStub( Database::class ) ),
			new WordPressUpdaterLock(),
			new WordPressCorePackageExecutor(),
			sys_get_temp_dir() . '/ran-booster-admitted-branch-maintenance'
		);
	}

	private function plugin(): Plugin {
		$plugin = Plugin::fromWpArray(
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
		$plugin->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main' ) );
		$plugin->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );
		return $plugin;
	}
}

final class BoundaryPluginRepository extends PluginRepository {
	public ?Plugin $package = null;
	public function __construct() {}
	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		return null === $this->package ? array() : array( (string) $this->package->getIdentifier() => $this->package );
	}
	public function fromSlug( $slug ) {
		return $this->package ?? throw new RuntimeException( 'Missing test plugin.' );
	}
	public function boosterPluginFromFile( $file ) {
		return $this->package ?? throw new RuntimeException( 'Missing test plugin.' );
	}
}

final class BoundaryThemeRepository extends ThemeRepository {
	public function __construct() {}
}

final class BoundaryProviderArchive implements ProviderPreparedArchive {
	public int $cleanupCalls = 0;
	public function __construct( private string $resolvedRef ) {}
	public function getUrl(): string {
		return 'https://example.test/archive.zip';
	}
	public function getResolvedRef(): string {
		return $this->resolvedRef;
	}
	public function verifyCurrentHead(): void {}
	public function cleanup(): void {
		++$this->cleanupCalls;
	}
}

final class BoundaryRepositoryProvider implements RepositoryProvider {
	use SuppliesProviderDiagnostics;

	public function __construct( private BoundaryProviderArchive $archive ) {}
	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}
	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new RuntimeException( 'Repository resolution is not part of this boundary test.' );
	}
	public function prepareArchive( ArchiveRequest $request ): ProviderPreparedArchive {
		return $this->archive;
	}
}

final class BoundaryAdmittedHost implements AdmittedAttemptJournal, AdmittedArchiveSource, AdmittedTargetFacts, AdmittedPackageExecutor, MutationLock {
	/** @var list<string> */
	public array $events = array();
	private BoundaryAdmittedArtifact $artifact;

	public function __construct() {
		$this->artifact = new BoundaryAdmittedArtifact( $this->events );
	}

	public function recordResolvedRef( string $ref ): void {
		$this->events[] = 'resolved';
	}
	public function markMutationStarted(): void {
		$this->events[] = 'mutation';
	}
	public function finish( string $code ): void {
		$this->events[] = 'finish:' . $code;
	}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->events[] = 'prepare';
		return $this->artifact;
	}
	public function verifyCurrentHead(): void {
		$this->events[] = 'verify';
	}
	public function assertMutationAllowed(): void {
		$this->events[] = 'allowed';
	}
	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array {
		$this->events[] = $deferExisting ? 'frozen:defer' : 'frozen:live';
		return array(
			'identifier' => 'example/example.php',
			'version'    => '1.0.0',
			'active'     => false,
		);
	}
	public function maintenanceActive(): bool {
		$this->events[] = 'maintenance';
		return false;
	}
	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void {
		$this->events[] = 'recheck';
	}
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->events[] = 'installed';
		return array(
			'identifier' => 'example/example.php',
			'version'    => '2.0.0',
			'active'     => false,
		);
	}
	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		$this->events[] = 'baseline-now';
		return $baseline;
	}
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		$this->events[] = 'adopt';
		return true;
	}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		$this->events[] = 'preflight';
	}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->events[] = 'execute';
		return CorePackageExecutionResult::succeeded();
	}
	public function run( callable $operation ): mixed {
		$this->events[] = 'lock:start';
		try {
			return $operation();
		} finally {
			$this->events[] = 'lock:end';
		}
	}
}

final class BoundaryAdmittedArtifact implements AdmittedBranchArtifact {
	/** @param list<string> $events */
	public function __construct( private array &$events ) {}
	public function resolvedRef(): string {
		return str_repeat( 'a', 40 );
	}
	public function expectedVersion(): string {
		return '2.0.0';
	}
	public function assertUnchanged(): void {
		$this->events[] = 'unchanged';
	}
	public function cleanup(): void {
		$this->events[] = 'cleanup';
	}
}

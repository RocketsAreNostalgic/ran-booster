<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private hook spies belong with this full-flow isolation test.

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Internal\CoreContainer;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Storage\Database;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\PluginRepository;

require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/TroubleshootingGetWordPressFunctions.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

final class TroubleshootingPassiveGetTest extends TestCase {

	protected function setUp(): void {
		$_GET                                    = array();
		$_POST                                   = array();
		$_SERVER['REQUEST_METHOD']               = 'GET';
		$GLOBALS['ran_booster_get_test_actions'] = array();
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The integration fixture must provide WordPress's global database prefix.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['ran_booster_get_test_actions'], $GLOBALS['wpdb'] );
	}

	/** @return list<array{string|null, array<string, mixed>, bool}> */
	public static function exactRequestProvider(): array {
		return array(
			array(
				'GET',
				array(
					'page' => 'ran-booster',
					'tab'  => 'troubleshooting',
				),
				true,
			),
			array(
				'GET',
				array(
					'page'  => 'ran-booster',
					'tab'   => 'troubleshooting',
					'panel' => 'diagnostics',
				),
				true,
			),
			array(
				'GET',
				array(
					'page'  => 'ran-booster',
					'tab'   => 'troubleshooting',
					'panel' => 'debug-capture',
				),
				true,
			),
			array(
				'GET',
				array(
					'page'  => 'ran-booster',
					'tab'   => 'troubleshooting',
					'panel' => 'deployment-activity',
				),
				false,
			),
			array(
				'POST',
				array(
					'page' => 'ran-booster',
					'tab'  => 'troubleshooting',
				),
				false,
			),
			array(
				null,
				array(
					'page' => 'ran-booster',
					'tab'  => 'troubleshooting',
				),
				false,
			),
			array(
				'GET',
				array(
					'page' => 'ran-booster',
					'tab'  => 'gh',
				),
				false,
			),
			array(
				'GET',
				array(
					'page' => 'ran-booster-plugins',
					'tab'  => 'troubleshooting',
				),
				false,
			),
			array(
				'GET',
				array(
					'page' => array( 'ran-booster' ),
					'tab'  => 'troubleshooting',
				),
				false,
			),
			array(
				'GET',
				array(
					'page' => 'ran-booster',
					'tab'  => array( 'troubleshooting' ),
				),
				false,
			),
			array(
				'GET',
				array(
					'page'  => 'ran-booster',
					'tab'   => 'troubleshooting',
					'panel' => array( 'deployment-activity' ),
				),
				true,
			),
		);
	}

	#[DataProvider( 'exactRequestProvider' )]
	public function testPassiveGuardMatchesOnlyTheExactGetRequest( ?string $method, array $query, bool $expected ): void {
		if ( null === $method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}
		$_GET = $query;

		self::assertSame( $expected, ( new Booster() )->isPassiveTroubleshootingRequest() );
	}

	public function testRealAdminInitHooksDeferSidecarValidationForDiagnosticsGet(): void {
		$fixture = $this->registeredFixture();
		$this->request( 'GET', 'troubleshooting' );

		$this->runAdminInit();

		self::assertSame( 0, $fixture['secrets']->validations );
		self::assertSame( 0, $fixture['database']->upgrades );
		self::assertSame( 0, $fixture['plugins']->reads );
	}

	/** @return list<array{string, string}> */
	public static function activeRequestProvider(): array {
		return array(
			array( 'POST', 'troubleshooting' ),
			array( 'GET', 'gh' ),
		);
	}

	#[DataProvider( 'activeRequestProvider' )]
	public function testPostAndOtherBoosterPagesRetainSidecarValidationSchemaAndPackageReads( string $method, string $tab ): void {
		$fixture = $this->registeredFixture();
		$this->request( $method, $tab );

		$this->runAdminInit();

		self::assertSame( 1, $fixture['secrets']->validations );
		self::assertSame( 1, $fixture['database']->upgrades );
		self::assertSame( 1, $fixture['plugins']->reads );
	}

	public function testDeploymentActivityGetRetainsDurableBootstrapReads(): void {
		$fixture = $this->registeredFixture();
		$this->request( 'GET', 'troubleshooting' );
		$_GET['panel'] = 'deployment-activity';

		$this->runAdminInit();

		self::assertSame( 1, $fixture['secrets']->validations );
		self::assertSame( 1, $fixture['database']->upgrades );
		self::assertSame( 1, $fixture['plugins']->reads );
	}

	public function testCredentialPhysicalCleanupRunsOnlyOnTheAdminLifecycle(): void {
		$fixture = $this->registeredFixture();

		self::assertArrayNotHasKey( 'init', $GLOBALS['ran_booster_get_test_actions'] );
		self::assertCount(
			1,
			array_filter(
				$GLOBALS['ran_booster_get_test_actions']['admin_init'],
				static fn ( mixed $callback ): bool => is_array( $callback )
					&& $callback[0] instanceof CredentialSelfDestructPurger
					&& 'purge' === $callback[1]
			)
		);
		$this->runAdminInit();
		self::assertSame( 1, $fixture['secrets']->purges );
	}

	/** @return array{secrets: TrackingSecretsFile, database: TrackingDatabase, plugins: TrackingPluginRepository} */
	private function registeredFixture(): array {
		$secrets   = null;
		$database  = new TrackingDatabase();
		$plugins   = new TrackingPluginRepository();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( 'RAN\\Storage\\Database', $database );
		$container->bind( 'RAN\\Storage\\PluginRepository', $plugins );
		$container->bind(
			'RAN\\Dispatcher',
			new class() {
				public function dispatchPostRequests(): void {
				}
			}
		);
		$container->bind(
			'RAN\\Admin\\RepositoryPickerController',
			new class() {
				public function handle(): void {
				}
			}
		);
		$container->bind(
			'RAN\\Webhook\\WebhookController',
			new class() {
				public function registerRoutes(): void {
				}
			}
		);

		( new BoosterServiceProvider(
			static function ( ProviderSecretPolicyCatalog $policies ) use ( &$secrets ): TrackingSecretsFile {
				$secrets = new TrackingSecretsFile( $policies );

				return $secrets;
			}
		) )->register( $container, $booster );
		$container->bind( 'RAN\\Storage\\Database', $database );
		$container->bind( 'RAN\\Storage\\PluginRepository', $plugins );
		self::assertInstanceOf( CredentialUsageReader::class, $container->make( CredentialUsageReader::class ) );
		self::assertInstanceOf( TemporaryDebugCapture::class, $container->make( TemporaryDebugCapture::class ) );
		$booster->init();
		self::assertInstanceOf( TrackingSecretsFile::class, $secrets );

		return array(
			'secrets'  => $secrets,
			'database' => $database,
			'plugins'  => $plugins,
		);
	}

	private function request( string $method, string $tab ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = array(
			'page' => 'ran-booster',
			'tab'  => $tab,
		);
		$_POST                     = 'POST' === $method ? array( 'ran_booster' => array() ) : array();
	}

	private function runAdminInit(): void {
		foreach ( $GLOBALS['ran_booster_get_test_actions']['admin_init'] ?? array() as $callback ) {
			$callback();
		}
	}
}

final class TrackingSecretsFile extends SecretsFile {
	public int $validations = 0;
	public int $purges      = 0;

	public function __construct( ProviderSecretPolicyCatalog $policies ) {
		parent::__construct( '/unused/troubleshooting-get-secrets.php', array(), $policies );
	}

	public function verifyAndSecure(): bool {
		++$this->validations;

		return false;
	}

	public function purgeExpiredCredentials(): array {
		++$this->purges;

		return array();
	}
}

final class TrackingDatabase extends Database {
	public int $upgrades = 0;

	public function requireSupported(): void {
	}

	public function maybeUpgrade(): void {
		++$this->upgrades;
	}

	public function requireReady(): void {
	}

	public function isReady(): bool {
		return true;
	}
}

final class TrackingPluginRepository extends PluginRepository {
	public int $reads = 0;

	public function __construct() {
	}

	public function allBoosterPlugins() {
		++$this->reads;

		return array();
	}
}

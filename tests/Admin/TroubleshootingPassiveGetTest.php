<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Internal\CoreContainer;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Storage\Database;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\PluginRepository;

require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/TroubleshootingGetWordPressFunctions.php';

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

final class TroubleshootingPassiveGetTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['ran_booster_get_test_actions'], $GLOBALS['wpdb'] );
	}

	/** @return list<array{string|null, array<string, mixed>, bool}> */
	public static function exact_request_provider(): array {
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

	#[DataProvider( 'exact_request_provider' )]
	public function test_passive_guard_matches_only_the_exact_get_request( ?string $method, array $query, bool $expected ): void {
		if ( null === $method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}
		$_GET = $query;

		self::assertSame( $expected, ( new Booster() )->is_passive_troubleshooting_request() );
	}

	public function test_real_admin_init_hooks_defer_sidecar_validation_for_diagnostics_get(): void {
		$fixture = $this->registered_fixture();
		$this->request( 'GET', 'troubleshooting' );

		$this->run_admin_init();

		self::assertSame( 0, $fixture['secrets']->validations );
		self::assertSame( 0, $fixture['database']->upgrades );
		self::assertSame( 0, $fixture['plugins']->reads );
	}

	/** @return list<array{string, string}> */
	public static function active_request_provider(): array {
		return array(
			array( 'POST', 'troubleshooting' ),
			array( 'GET', 'gh' ),
		);
	}

	#[DataProvider( 'active_request_provider' )]
	public function test_post_and_other_booster_pages_retain_sidecar_validation_schema_and_package_reads( string $method, string $tab ): void {
		$fixture = $this->registered_fixture();
		$this->request( $method, $tab );

		$this->run_admin_init();

		self::assertSame( 1, $fixture['secrets']->validations );
		self::assertSame( 1, $fixture['database']->upgrades );
		self::assertSame( 1, $fixture['plugins']->reads );
	}

	public function test_deployment_activity_get_retains_durable_bootstrap_reads(): void {
		$fixture = $this->registered_fixture();
		$this->request( 'GET', 'troubleshooting' );
		$_GET['panel'] = 'deployment-activity';

		$this->run_admin_init();

		self::assertSame( 1, $fixture['secrets']->validations );
		self::assertSame( 1, $fixture['database']->upgrades );
		self::assertSame( 1, $fixture['plugins']->reads );
	}

	public function test_credential_physical_cleanup_runs_only_on_the_admin_lifecycle(): void {
		$fixture = $this->registered_fixture();

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
		$this->run_admin_init();
		self::assertSame( 1, $fixture['secrets']->purges );
	}

	public function test_typed_storage_failure_uses_the_dedicated_notice_without_ageneric_duplicate(): void {
		$fixture = $this->registered_fixture();
		$fixture['dashboard']->expects( self::never() )->method( 'add_failure_message' );
		$fixture['secrets']->validation_failure = new SecretsStorageUnavailable(
			'The encrypted Booster secrets store is incomplete.',
			'storage_file_missing'
		);
		$this->request( 'GET', 'overview' );

		$this->run_admin_init();

		self::assertSame( 1, $fixture['secrets']->validations );
	}

	/** @return array{secrets: TrackingSecretsFile, database: TrackingDatabase, plugins: TrackingPluginRepository, dashboard: \RAN\Dashboard&\PHPUnit\Framework\MockObject\MockObject} */
	private function registered_fixture(): array {
		$secrets   = null;
		$database  = new TrackingDatabase();
		$plugins   = new TrackingPluginRepository();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$dashboard = $this->createMock( \RAN\Dashboard::class );
		$container->bind( \RAN\Dashboard::class, $dashboard );
		$container->bind( 'RAN\\Storage\\Database', $database );
		$container->bind( 'RAN\\Storage\\PluginRepository', $plugins );
		$container->bind(
			'RAN\\Dispatcher',
			new class() {
				public function dispatch_post_requests(): void {
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
				public function register_routes(): void {
				}
			}
		);

		( new BoosterServiceProvider(
			static function ( ProviderSecretPolicyCatalog $policies ) use ( &$secrets ): TrackingSecretsFile {
				$secrets = new TrackingSecretsFile( $policies );

				return $secrets;
			}
		) )->register( $container, $booster, new \stdClass(), 'ran-booster.php' );
		$container->bind( 'RAN\\Storage\\Database', $database );
		$container->bind( 'RAN\\Storage\\PluginRepository', $plugins );
		self::assertInstanceOf( CredentialUsageReader::class, $container->make( CredentialUsageReader::class ) );
		self::assertInstanceOf( TemporaryDebugCapture::class, $container->make( TemporaryDebugCapture::class ) );
		$booster->init();
		self::assertInstanceOf( TrackingSecretsFile::class, $secrets );

		return array(
			'secrets'   => $secrets,
			'database'  => $database,
			'plugins'   => $plugins,
			'dashboard' => $dashboard,
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

	private function run_admin_init(): void {
		foreach ( $GLOBALS['ran_booster_get_test_actions']['admin_init'] ?? array() as $callback ) {
			$callback();
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private hook spies belong with this full-flow isolation test.
final class TrackingSecretsFile extends SecretsFile {
	public int $validations                = 0;
	public int $purges                     = 0;
	public ?\Throwable $validation_failure = null;

	public function __construct( ProviderSecretPolicyCatalog $policies ) {
		parent::__construct( '/unused/troubleshooting-get-secrets.php', array(), $policies );
	}

	public function verify_and_secure(): bool {
		++$this->validations;
		if ( null !== $this->validation_failure ) {
			throw $this->validation_failure;
		}

		return false;
	}

	public function purge_expired_credentials(): array {
		++$this->purges;

		return array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private hook spies belong with this full-flow isolation test.
final class TrackingDatabase extends Database {
	public int $upgrades = 0;

	public function require_supported(): void {
	}

	public function maybe_upgrade(): void {
		++$this->upgrades;
	}

	public function require_ready(): void {
	}

	public function is_ready(): bool {
		return true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private hook spies belong with this full-flow isolation test.
final class TrackingPluginRepository extends PluginRepository {
	public int $reads = 0;

	public function __construct() {
	}

	public function all_booster_plugins() {
		++$this->reads;

		return array();
	}
}

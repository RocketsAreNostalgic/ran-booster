<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\AbstractPackageRepository;
use RAN\Storage\Database;
use RAN\Storage\GitHubSelectionStore;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\SchemaConnection;
use RAN\Storage\ThemeRepository;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';

/**
 * Refs #1181. Unregistered (damaged or deleted) managed package discovery and State B unlink recovery.
 */
final class UnregisteredManagedPackageRecoveryTest extends TestCase {
	private mixed $previous_database;
	private AttemptRepositoryDatabase $db;
	private GitHubSelectionStore $store;
	private GitHubProviderSelection $selection;
	private DeploymentAttemptRepository $attempts;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->db                = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test database for lock, attempts and selection store.
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$this->store = new GitHubSelectionStore( $this->db, 'wp_options' );
		$bundled     = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'booster.php',
				'implementation_id'      => 'bundled',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$this->store->initialize_bundled( $bundled );
		$this->selection = new GitHubProviderSelection( $this->store, '/unused', $bundled );
		$this->attempts  = new DeploymentAttemptRepository(
			$this->db,
			'wp_ran_booster_attempts',
			database_lifecycle: $this->createStub( Database::class ),
			selection: $this->selection
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore prior test database global.
		$GLOBALS['wpdb'] = $this->previous_database;
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_package_mutation_guard_multisite']
		);
	}

	/** @return iterable<string, array{string, string, int}> */
	public static function package_type_provider(): iterable {
		yield 'plugin' => array( 'plugin', 'broken-plugin/broken-plugin.php', 1 );
		yield 'theme' => array( 'theme', 'broken-theme', 2 );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_unavailable_managed_records_returns_only_unregistered_rows_across_providers_and_sources( string $type, string $unregistered_id, int $package_type ): void {
		$healthy_id = 'plugin' === $type ? 'healthy-plugin/healthy-plugin.php' : 'healthy-theme';
		$second_id  = 'plugin' === $type ? 'deleted-bb/deleted-bb.php' : 'deleted-bb-theme';
		$fake_wpdb  = new class( array(
			(object) array(
				'package'    => $healthy_id,
				'repository' => 'org/healthy',
				'provider'   => 'gh',
				'source'     => 'branch',
			),
			(object) array(
				'package'    => $unregistered_id,
				'repository' => 'org/broken',
				'provider'   => 'gh',
				'source'     => 'release_asset',
			),
			(object) array(
				'package'    => $unregistered_id,
				'repository' => 'org/broken-duplicate',
				'provider'   => 'gh',
				'source'     => 'release_asset',
			),
			(object) array(
				'package'    => $second_id,
				'repository' => 'team/deleted-bb',
				'provider'   => 'bb',
				'source'     => 'branch',
			),
		) ) extends SchemaConnection {
			public string $prefix   = 'wp_';
			public int $write_calls = 0;
			public int $read_calls  = 0;

			/** @param list<object> $rows */
			public function __construct( private array $rows ) {
			}

			public function query( string $query ): int {
				unset( $query );
				++$this->write_calls;
				return 0;
			}

			public function get_charset_collate(): string {
				return '';
			}

			public function esc_like( string $value ): string {
				return $value;
			}

			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );
				return $query;
			}

			public function get_var( string $query ): mixed {
				unset( $query );
				++$this->read_calls;
				return null;
			}

			public function get_row( string $query ): mixed {
				unset( $query );
				return null;
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				unset( $query );
				return $this->rows;
			}
		};
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Controlled storage fixture for unavailable_managed_records().
		$GLOBALS['wpdb'] = $fake_wpdb;
		$database        = $this->createStub( Database::class );
		$repository      = new class( $database, $package_type, $healthy_id ) extends AbstractPackageRepository {
			public function __construct(
				Database $database,
				private int $type_code,
				private string $registered_identifier
			) {
				parent::__construct( $database );
			}

			protected function package_type(): int {
				return $this->type_code;
			}

			protected function package_exists( string $identifier ): bool {
				return $identifier === $this->registered_identifier;
			}

			protected function package_from_installation( string $identifier ): \RAN\Package {
				unset( $identifier );
				throw new \LogicException( 'Unavailable discovery must never hydrate packages.' );
			}

			protected function not_found_exception(): \Throwable {
				return new \RuntimeException( 'Not found.' );
			}
		};

		$records = $repository->unavailable_managed_records();

		self::assertSame(
			array(
				array(
					'identifier' => $unregistered_id,
					'repository' => 'org/broken',
					'provider'   => 'gh',
					'source'     => 'release_asset',
				),
				array(
					'identifier' => $second_id,
					'repository' => 'team/deleted-bb',
					'provider'   => 'bb',
					'source'     => 'branch',
				),
			),
			$records
		);
		self::assertSame( 0, $fake_wpdb->write_calls );
	}

	/** @return iterable<string, array{string, string, bool, bool}> */
	public static function disk_state_provider(): iterable {
		foreach ( array(
			'plugin' => 'broken-plugin/broken-plugin.php',
			'theme'  => 'broken-theme',
		) as $type => $identifier ) {
			yield $type . '-absent' => array( $type, $identifier, true, false );
			yield $type . '-on-disk' => array( $type, $identifier, false, false );
			yield $type . '-unverified-postcondition' => array( $type, $identifier, false, true );
		}
	}

	#[DataProvider( 'disk_state_provider' )]
	public function test_unregistered_confirmation_and_unlink_never_delete_files_and_report_disk_state(
		string $type,
		string $identifier,
		bool $absent_on_disk,
		bool $postcondition_throws
	): void {
		$record  = $this->recovery_record( $type, $identifier );
		$tracker = new class() {
			public bool $unlinked = false;
		};
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );
		$repo    = 'plugin' === $type ? $plugins : $themes;
		$other   = 'plugin' === $type ? $themes : $plugins;
		$repo->expects( self::once() )->method( 'recovery_record' )->with( $identifier )->willReturn( $record );
		$repo->expects( self::once() )->method( 'recover_unlink' )
			->with( $identifier, $record['adoption_token'], $record['digest'], self::isCallable() )
			->willReturnCallback(
				static function ( string $target, string $token, string $digest, callable $guard ) use ( $tracker ): PackageMutationResult {
					unset( $target, $token, $digest );
					$guard();
					$tracker->unlinked = true;
					return PackageMutationResult::changed( PackageStorageOperation::DELETE );
				}
			);
		$other->expects( self::never() )->method( 'recover_unlink' );

		$gateway = $this->createMock( PackageRemovalGateway::class );
		$gateway->method( 'installed_targets' )->with( $type )->willReturn( array() );
		$gateway->method( 'deletion_postcondition' )->with( $type, $identifier, true )->willReturnCallback(
			static function () use ( $absent_on_disk, $postcondition_throws ): \Closure {
				if ( $postcondition_throws ) {
					throw new \RuntimeException( 'Root is a symlink.' );
				}
				return static fn (): bool => $absent_on_disk;
			}
		);
		$gateway->expects( self::once() )->method( 'invalidate_native_target' )->with( $type, $identifier );
		$gateway->expects( self::never() )->method( 'delete_plugin' );
		$gateway->expects( self::never() )->method( 'delete_theme' );

		$service      = new PackageRemovalService( $plugins, $themes, $gateway, $this->attempts, new WordPressUpdaterLock(), github_selection: $this->selection );
		$confirmation = $service->unregistered_recovery_confirmation( $type, $identifier );

		self::assertSame( $record + array( 'folder_absent' => $absent_on_disk ), $confirmation );
		self::assertFalse( $tracker->unlinked );

		$result = $service->recover_unregistered( $type, $identifier, $record['adoption_token'], $record['digest'] );

		self::assertSame( $absent_on_disk, $result );
		self::assertTrue( $tracker->unlinked );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	#[DataProvider( 'package_type_provider' )]
	public function test_active_native_hold_and_unresolved_attempts_refuse_unregistered_recovery( string $type, string $identifier, int $package_type ): void {
		unset( $package_type );
		$record  = $this->recovery_record( $type, $identifier );
		$plugins = $this->createMock( PluginRepository::class );
		$themes  = $this->createMock( ThemeRepository::class );
		$repo    = 'plugin' === $type ? $plugins : $themes;
		$repo->method( 'recovery_record' )->with( $identifier )->willReturn( $record );
		$repo->expects( self::never() )->method( 'recover_unlink' );
		$gateway = $this->createMock( PackageRemovalGateway::class );
		$gateway->method( 'installed_targets' )->with( $type )->willReturn( array() );
		$gateway->expects( self::never() )->method( 'delete_plugin' );
		$gateway->expects( self::never() )->method( 'delete_theme' );
		$service = new PackageRemovalService( $plugins, $themes, $gateway, $this->attempts, new WordPressUpdaterLock(), github_selection: $this->selection );

		// 1. Active #987 native install hold blocks both confirmation and execution.
		$state                   = $this->store->read();
		$held                    = $state;
		$held['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => $type,
			'installed_identity'   => $identifier,
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $state, $held ) );
		try {
			$service->unregistered_recovery_confirmation( $type, $identifier );
			self::fail( 'Active native hold must refuse confirmation.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Native install hold prevents recovery.', $failure->getMessage() );
		}
		try {
			$service->recover_unregistered( $type, $identifier, $record['adoption_token'], $record['digest'] );
			self::fail( 'Active native hold must refuse unlink.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Native mutation ownership prevents recovery.', $failure->getMessage() );
		}
		self::assertTrue( $this->store->compare_and_swap( $held, $state ) );

		// 2. Active deployment attempt blocks confirmation.
		$request        = new DeploymentRequest( 'org/broken', null, false, 'main', 'broken', null, DeploymentPolicy::MANUAL, 1, installed_identifier: $identifier, adoption_token: $record['adoption_token'] );
		$this->db->rows = array(
			array(
				'id'                      => 1,
				'correlation_id'          => str_repeat( 'c', 32 ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => $type,
				'package_slug'            => 'broken',
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repo-42',
				'requested_ref'           => 'main',
				'resolved_ref'            => null,
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => 'running',
				'mutation_started_at'     => '2026-11-23 00:00:00',
				'outcome_code'            => null,
				'request_json'            => $request->to_json(),
				'created_at'              => '2026-11-23 00:00:00',
				'finished_at'             => null,
				'resolved_at'             => null,
				'resolved_by'             => null,
			),
		);
		try {
			$service->unregistered_recovery_confirmation( $type, $identifier );
			self::fail( 'Active deployment attempt must refuse confirmation.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Unresolved deployment work prevents recovery.', $failure->getMessage() );
		}
		$this->db->rows = array();

		// 3. Occupied auto_updater.lock refuses unlink without reclaiming it.
		$this->db->option_rows['auto_updater.lock'] = (string) ( time() - 7200 );
		try {
			$service->recover_unregistered( $type, $identifier, $record['adoption_token'], $record['digest'] );
			self::fail( 'Occupied updater lock must refuse unregistered unlink without reclaiming.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'The updater lock requires recovery before continuing.', $failure->getMessage() );
		}
		self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows );
		unset( $this->db->option_rows['auto_updater.lock'] );

		// 4. Registered target is refused as not unregistered.
		$registered_gateway = $this->createStub( PackageRemovalGateway::class );
		$registered_gateway->method( 'installed_targets' )->willReturn( array( $identifier => 'Healthy Now' ) );
		$registered_service = new PackageRemovalService( $plugins, $themes, $registered_gateway, $this->attempts, new WordPressUpdaterLock(), github_selection: $this->selection );
		try {
			$registered_service->unregistered_recovery_confirmation( $type, $identifier );
			self::fail( 'Registered package must not use unregistered recovery.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'The package is registered in WordPress.', $failure->getMessage() );
		}
	}

	/** @return array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string} */
	private function recovery_record( string $type, string $identifier ): array {
		return array(
			'id'             => '42',
			'package'        => $identifier,
			'type'           => 'plugin' === $type ? 1 : 2,
			'adoption_token' => 'gh:repo-42:11111111-1111-4111-8111-111111111111',
			'source'         => 'release_asset',
			'digest'         => str_repeat( 'd', 64 ),
		);
	}
}

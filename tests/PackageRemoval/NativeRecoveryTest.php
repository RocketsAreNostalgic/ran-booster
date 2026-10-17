<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\PackageRemoval\PackageRemovalGateway;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\Database;
use RAN\Storage\GitHubSelectionStore;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';

final class NativeRecoveryTest extends TestCase {
	private AttemptRepositoryDatabase $db;
	private GitHubSelectionStore $store;
	private GitHubProviderSelection $selection;
	private mixed $previous_database;
	/** @var array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string}|null */
	private ?array $record       = null;
	private bool $present        = false;
	private bool $cache_failure  = false;
	private bool $reappear       = false;
	private bool $unlink_failure = false;
	/** @var list<string> */
	private array $events = array();
	private PackageRemovalService $service;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->db                = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database for the actual updater lock and selection store.
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$this->store               = new GitHubSelectionStore( $this->db, 'wp_options' );
		$bundled                   = new GitHubReplacementDeclaration(
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
		$state                     = $this->store->initialize_bundled( $bundled );
		$marked                    = $state;
		$marked['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'target/main.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
		$this->selection = new GitHubProviderSelection( $this->store, '/unused', $bundled );
		$plugins         = $this->createMock( PluginRepository::class );
		$plugins->method( 'native_recovery_record' )->willReturnCallback(
			function ( string $identifier, bool $locked = false ): ?array {
				self::assertSame( 'target/main.php', $identifier );
				$this->events[] = $locked ? 'locked_absence' : 'inventory';
				return $this->record;
			}
		);
		$plugins->method( 'recover_unlink' )->willReturnCallback(
			function ( string $identifier, string $token, string $digest, callable $guard ): PackageMutationResult {
				self::assertSame( 'target/main.php', $identifier );
				self::assertSame( $this->record['adoption_token'] ?? null, $token );
				self::assertSame( $this->record['digest'] ?? null, $digest );
				self::assertArrayHasKey( 'native_mutation', $this->store->read() );
				$guard();
				if ( $this->unlink_failure ) {
						throw new \RuntimeException( 'Unknown unlink result.' );
				}
				$this->events[] = 'unlink';
				$this->record   = null;
				return PackageMutationResult::changed( PackageStorageOperation::DELETE );
			}
		);
		$gateway = $this->createMock( PackageRemovalGateway::class );
		$gateway->method( 'deletion_postcondition' )->willReturnCallback(
			function ( string $type, string $identifier, bool $allow_absent ): \Closure {
				self::assertTrue( $allow_absent );
				self::assertSame( 'plugin', $type );
				self::assertSame( 'target/main.php', $identifier );
				return fn (): bool => ! $this->present;
			}
		);
		$gateway->method( 'invalidate_native_target' )->willReturnCallback(
			function (): void {
				self::assertArrayHasKey( 'native_mutation', $this->store->read() );
				$this->events[] = 'invalidate';
				if ( $this->cache_failure ) {
						throw new \RuntimeException( 'Cache invalidation failed.' );
				}
				$this->present = $this->reappear;
			}
		);
		$gateway->expects( self::never() )->method( 'delete_plugin' );
		$gateway->expects( self::never() )->method( 'delete_theme' );
		$this->service = new PackageRemovalService( $plugins, $this->createStub( ThemeRepository::class ), $gateway, new DeploymentAttemptRepository( $this->db, 'wp_attempts', database_lifecycle: $this->createStub( Database::class ), selection: $this->selection ), new WordPressUpdaterLock(), github_selection: $this->selection );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the isolated test database.
		$GLOBALS['wpdb'] = $this->previous_database;
		unset( $GLOBALS['ran_booster_package_mutation_guard_file_mods'], $GLOBALS['ran_booster_package_mutation_guard_multisite'] );
	}

	public function test_absent_unmanaged_recovery_preserves_revocation_and_generation(): void {
		$old                             = $this->store->read();
		$revoked                         = $old;
		$revoked['approval']             = 'revoked';
		$revoked['selection_generation'] = 1;
		self::assertTrue( $this->store->compare_and_swap( $old, $revoked ) );
		$review = $this->service->native_recovery_confirmation();
		self::assertSame( array( 'digest', 'type', 'identifier', 'managed' ), array_keys( $review ) );
		self::assertFalse( $review['managed'] );
		$this->recover( $review['digest'] );
		unset( $revoked['native_mutation'] );
		self::assertSame( $revoked, $this->store->read() );
		self::assertSame( array( 'inventory', 'inventory', 'invalidate', 'locked_absence' ), $this->events );
	}

	public function test_managed_recovery_unlinks_before_marker_clear(): void {
		$this->managed();
		$review = $this->service->native_recovery_confirmation();
		self::assertTrue( $review['managed'] );
		$this->recover( $review['digest'] );
		self::assertNull( $this->record );
		self::assertSame( array( 'inventory', 'inventory', 'unlink', 'invalidate', 'locked_absence' ), $this->events );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_target_presence_and_reappearance_preserve_marker(): void {
		foreach ( array( 'present', 'reappear' ) as $fault ) {
			$this->present  = 'present' === $fault;
			$this->reappear = 'reappear' === $fault;
			$this->refused( fn () => $this->recover( $this->service->native_recovery_confirmation()['digest'] ) );
			self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		}
	}

	public function test_cache_failure_after_unlink_leaves_safe_retryable_marker(): void {
		$this->managed();
		$this->cache_failure = true;
		$this->refused( fn () => $this->recover( $this->service->native_recovery_confirmation()['digest'] ) );
		self::assertNull( $this->record );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		$this->cache_failure = false;
		$this->recover( $this->service->native_recovery_confirmation()['digest'] );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_stale_management_and_uncertain_unlink_refuse(): void {
		$digest = $this->service->native_recovery_confirmation()['digest'];
		$this->managed();
		$this->refused( fn () => $this->recover( $digest ) );
		$this->unlink_failure = true;
		$this->refused( fn () => $this->recover( $this->service->native_recovery_confirmation()['digest'] ) );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_missing_consent_and_occupied_locks_never_clear_marker(): void {
		$digest = $this->service->native_recovery_confirmation()['digest'];
		foreach ( array( array( false, true, true ), array( true, false, true ), array( true, true, false ) ) as list( $stopped, $removed, $authorized ) ) {
			$this->refused( fn () => $this->service->recover_native_removal( $digest, $stopped, $removed, static fn (): bool => $authorized ) );
		}
		foreach ( array( '1', 'malformed' ) as $token ) {
			$this->db->option_rows['auto_updater.lock'] = $token;
			$this->refused( fn () => $this->recover( $digest ) );
			self::assertSame( $token, $this->db->option_rows['auto_updater.lock'] );
		}
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_unknown_commit_and_late_permission_loss_never_report_success(): void {
		$digest = $this->service->native_recovery_confirmation()['digest'];
		$calls  = 0;
		$this->refused(
			function () use ( $digest, &$calls ): void {
				$this->service->recover_native_removal(
					$digest,
					true,
					true,
					static function () use ( &$calls ): bool {
						return ++$calls < 3;
					}
				);
			}
		);
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		$this->db->fail_commit = true;
		$this->refused( fn () => $this->recover( $digest ) );
		$this->db->fail_commit = false;
		$this->refused( fn () => $this->recover( $digest ) );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_late_management_and_marker_changes_refuse_final_clear(): void {
		foreach ( array( 'management', 'marker' ) as $fault ) {
			$this->record = null;
			$digest       = $this->service->native_recovery_confirmation()['digest'];
			$calls        = 0;
			$this->refused(
				function () use ( $digest, $fault, &$calls ): void {
					$this->service->recover_native_removal(
						$digest,
						true,
						true,
						function () use ( $fault, &$calls ): bool {
							if ( 3 === ++$calls ) {
								if ( 'management' === $fault ) {
									$this->managed();
								} else {
									$old              = $this->store->read();
									$next             = $old;
									$next['approval'] = 'revoked';
									++$next['selection_generation'];
									self::assertTrue( $this->store->compare_and_swap( $old, $next ) );
								}
							}
							return true;
						}
					);
				}
			);
			self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		}
		self::assertSame( 'revoked', $this->store->read()['approval'] );
	}

	public function test_running_and_unresolved_attempts_prevent_recovery(): void {
		$digest = $this->service->native_recovery_confirmation()['digest'];
		foreach ( array( 'running', 'needs_attention' ) as $state ) {
			$this->db->rows = array(
				array(
					'id'          => 1,
					'provider'    => 'gh',
					'state'       => $state,
					'resolved_at' => null,
					'resolved_by' => null,
				),
			);
			$this->refused( fn () => $this->recover( $digest ) );
			self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		}
		self::assertNotContains( 'invalidate', $this->events );
	}

	public function test_queued_bitbucket_sibling_before_or_during_recovery_preserves_marker(): void {
		$request = new DeploymentRequest( 'org/other', null, false, 'main', 'other', null, DeploymentPolicy::AUTOMATIC, 1, installed_identifier: 'target/other.php', adoption_token: 'bb:R_other:11111111-1111-4111-8111-111111111111' );
		$row     = array(
			'id'                      => 1,
			'correlation_id'          => str_repeat( 'c', 32 ),
			'source'                  => 'webhook',
			'operation'               => 'update',
			'package_type'            => 'plugin',
			'package_slug'            => 'other',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'provider'                => 'bb',
			'provider_repository_id'  => 'R_other',
			'requested_ref'           => 'main',
			'resolved_ref'            => null,
			'delivery_id'             => 'delivery-other',
			'delivery_digest'         => str_repeat( 'd', 64 ),
			'state'                   => 'queued',
			'mutation_started_at'     => null,
			'outcome_code'            => null,
			'request_json'            => $request->to_json(),
			'created_at'              => '2026-10-16 00:00:00',
			'finished_at'             => null,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
		foreach ( array( false, true ) as $late ) {
			$this->db->rows = $late ? array() : array( $row );
			$digest         = $this->service->native_recovery_confirmation()['digest'];
			$calls          = 0;
			$this->refused(
				function () use ( $digest, $late, $row, &$calls ): void {
					$this->service->recover_native_removal(
						$digest,
						true,
						true,
						function () use ( $late, $row, &$calls ): bool {
							++$calls;
							if ( $late && 3 === $calls ) {
								$this->db->rows = array( $row );
							}
							return true;
						}
					);
				}
			);
			self::assertArrayHasKey( 'native_mutation', $this->store->read() );
			self::assertCount( 1, $this->db->rows );
		}
	}

	public function test_lock_release_failure_does_not_report_verified_recovery(): void {
		$digest                        = $this->service->native_recovery_confirmation()['digest'];
		$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
		$this->refused( fn () => $this->recover( $digest ) );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
		self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	private function managed(): void {
		$this->record = array(
			'id'             => '1',
			'package'        => 'target/main.php',
			'type'           => 1,
			'adoption_token' => 'exact-adoption',
			'source'         => 'release_asset',
			'digest'         => str_repeat( 'b', 64 ),
		);
	}

	private function recover( string $digest ): void {
		$this->service->recover_native_removal( $digest, true, true, static fn (): bool => true );
	}

	/** @param callable():void $operation */
	private function refused( callable $operation ): void {
		try {
			$operation();
			self::fail( 'Unsafe recovery succeeded.' );
		} catch ( \RuntimeException $failure ) {
			self::assertNotSame( '', $failure->getMessage() );
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\PackageRemoval;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\PackageRemoval\NativeHoldLockReleaseFailure;
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
	private ?array $record          = null;
	private bool $present           = false;
	private bool $registered        = false;
	private bool $cache_failure     = false;
	private int $cache_failure_code = 0;
	private bool $reappear          = false;
	private bool $unlink_failure    = false;
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
						throw new \RuntimeException( 'Cache invalidation failed.', $this->cache_failure_code );
				}
				if ( $this->reappear ) {
					$this->present = true;
				}
			}
		);
		$gateway->method( 'installed_targets' )->willReturnCallback(
			function ( string $type ): array {
				self::assertFalse( $this->in_selection_transaction(), 'Catalog filters must run outside SQL transactions.' );
				self::assertSame( 'plugin', $type );
				return $this->registered ? array( 'target/main.php' => 'Target' ) : array();
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
		self::assertSame( array( 'digest', 'type', 'identifier', 'managed', 'finished', 'readable', 'started_at' ), array_keys( $review ) );
		self::assertFalse( $review['managed'] );
		self::assertFalse( $review['readable'] );
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
		foreach ( array( (string) time(), 'malformed' ) as $token ) {
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
		foreach ( array( 'recover', 'release' ) as $operation ) {
			foreach ( array( 'zero', 'throw' ) as $failure_mode ) {
				unset( $this->db->option_rows['auto_updater.lock'] );
				$state = $this->store->read();
				if ( array_key_exists( 'native_mutation', $state ) ) {
					$cleared = $state;
					unset( $cleared['native_mutation'] );
					self::assertTrue( $this->store->compare_and_swap( $state, $cleared ) );
					$state = $cleared;
				}
				$marked                    = $state;
				$marked['native_mutation'] = array(
					'selection_generation' => $state['selection_generation'],
					'owner_tag'            => str_repeat( 'a', 32 ),
					'target_type'          => 'plugin',
					'installed_identity'   => 'target/main.php',
					'phase'                => 'started',
				);
				self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
				if ( 'release' === $operation ) {
					$this->present = true;
					$this->readable();
				} else {
					$this->record     = null;
					$this->present    = false;
					$this->registered = false;
				}
				if ( 'throw' === $failure_mode ) {
					$this->db->fail_query_contains = 'DELETE FROM `wp_options`';
				} else {
					$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
				}
				$digest = $this->service->native_recovery_confirmation()['digest'];
				$caught = null;
				try {
					if ( 'release' === $operation ) {
						$this->release( $digest );
					} else {
						$this->recover( $digest );
					}
				} catch ( \RuntimeException $failure ) {
					$caught = $failure;
				} finally {
					$this->db->fail_query_contains = null;
					$this->db->zero_query_contains = null;
					$this->db->last_error          = '';
				}
				self::assertInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$failure_mode" );
				if ( 'throw' === $failure_mode ) {
					self::assertInstanceOf( DeploymentStorageFailure::class, $caught->getPrevious(), "$operation/$failure_mode" );
				} else {
					self::assertNull( $caught->getPrevious(), "$operation/$failure_mode" );
				}
				self::assertArrayNotHasKey( 'native_mutation', $this->store->read(), "$operation/$failure_mode" );
				self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$failure_mode" );
			}
		}
	}

	public function test_pre_clear_failure_keeps_hold_and_never_emits_lock_release_failure(): void {
		foreach ( array( 'recover', 'release' ) as $operation ) {
			foreach ( array( 'clear', 'zero', 'throw' ) as $lock_mode ) {
				unset( $this->db->option_rows['auto_updater.lock'] );
				$this->hold( str_repeat( 'a', 32 ), 'started' );
				if ( 'release' === $operation ) {
					$this->present = true;
					$this->readable();
				} else {
					$this->record     = null;
					$this->present    = false;
					$this->registered = false;
				}
				$this->cache_failure      = true;
				$this->cache_failure_code = 1;
				if ( 'throw' === $lock_mode ) {
					$this->db->fail_query_contains = 'DELETE FROM `wp_options`';
				} elseif ( 'zero' === $lock_mode ) {
					$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
				}
				$digest = $this->service->native_recovery_confirmation()['digest'];
				$caught = null;
				try {
					if ( 'release' === $operation ) {
						$this->release( $digest );
					} else {
						$this->recover( $digest );
					}
				} catch ( \RuntimeException $failure ) {
					$caught = $failure;
				} finally {
					$this->cache_failure           = false;
					$this->cache_failure_code      = 0;
					$this->db->fail_query_contains = null;
					$this->db->zero_query_contains = null;
					$this->db->last_error          = '';
				}
				self::assertInstanceOf( \RuntimeException::class, $caught, "$operation/$lock_mode" );
				self::assertNotInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$lock_mode" );
				self::assertArrayHasKey( 'native_mutation', $this->store->read(), "$operation/$lock_mode" );
				if ( 'clear' === $lock_mode ) {
					self::assertSame( 1, $caught->getCode(), "$operation/$lock_mode" );
					self::assertSame( 'Cache invalidation failed.', $caught->getMessage(), "$operation/$lock_mode" );
					self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
				} elseif ( 'zero' === $lock_mode ) {
					self::assertSame( 0, $caught->getCode(), "$operation/$lock_mode" );
					self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
					$previous = $caught->getPrevious();
					self::assertInstanceOf( \RuntimeException::class, $previous, "$operation/$lock_mode" );
					self::assertSame( 1, $previous->getCode(), "$operation/$lock_mode" );
					self::assertSame( 'Cache invalidation failed.', $previous->getMessage(), "$operation/$lock_mode" );
				} else {
					self::assertSame( 0, $caught->getCode(), "$operation/$lock_mode" );
					self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
					self::assertInstanceOf( DeploymentStorageFailure::class, $caught->getPrevious(), "$operation/$lock_mode" );
				}
			}
		}
	}

	public function test_finished_hold_release_keeps_management_and_needs_no_stop_confirmation(): void {
		$this->hold( str_repeat( 'a', 32 ), 'finished' );
		$this->readable();
		$record = $this->record;
		$before = $this->store->read();
		$review = $this->service->native_recovery_confirmation();
		self::assertTrue( $review['finished'] );
		self::assertTrue( $review['readable'] );
		$this->service->release_native_hold( $review['digest'], false, true, static fn (): bool => true );
		unset( $before['native_mutation'] );
		self::assertSame( $before, $this->store->read() );
		self::assertSame( $record, $this->record );
		self::assertSame( array( 'inventory', 'inventory', 'invalidate', 'locked_absence' ), $this->events );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_hold_release_needs_every_confirmation_and_permission(): void {
		$this->readable();
		$digest = $this->service->native_recovery_confirmation()['digest'];
		self::assertFalse( $this->service->native_recovery_confirmation()['finished'] );
		foreach ( array( array( false, true, true ), array( true, false, true ), array( true, true, false ) ) as list( $stopped, $checked, $authorized ) ) {
			$this->refused( fn () => $this->service->release_native_hold( $digest, $stopped, $checked, static fn (): bool => $authorized ) );
		}
		$this->hold( str_repeat( 'a', 32 ), 'finished' );
		$this->refused( fn () => $this->service->release_native_hold( $this->service->native_recovery_confirmation()['digest'], true, false, static fn (): bool => true ) );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		self::assertNotContains( 'invalidate', $this->events );
	}

	/** @return iterable<string, array{string}> */
	public static function hold_operations(): iterable {
		yield 'in-place release' => array( 'release' );
		yield 'removal recovery' => array( 'recover' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'hold_operations' )]
	public function test_unfinished_hold_frees_its_own_lock_by_exact_value( string $operation ): void {
		$own = (string) ( time() - 10 );
		$this->hold( self::tag( $own ), 'started' );
		$this->db->option_rows['auto_updater.lock'] = $own;
		self::assertFalse( $this->service->native_hold_lock_busy() );
		$this->operate( $operation );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_untagged_legacy_owner_tag_reports_unknown_start_time(): void {
		foreach ( array( '00abcd1234567890' . str_repeat( 'f', 16 ), '000000006553f100' . str_repeat( 'b', 16 ) ) as $legacy_tag ) {
			$this->hold( $legacy_tag, 'started' );
			self::assertNull( $this->service->native_recovery_confirmation()['started_at'] );
		}
	}

	/** @return iterable<string, array{string, string, int}> */
	public static function foreign_live_locks(): iterable {
		foreach ( array( 'release', 'recover' ) as $operation ) {
			yield "$operation: unfinished hold, other live lock" => array( $operation, 'started', 5 );
			yield "$operation: unfinished hold, tag without a token" => array( $operation, 'started', 0 );
			yield "$operation: finished hold, even with the same value" => array( $operation, 'finished', 0 );
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreign_live_locks' )]
	public function test_live_lock_that_is_not_the_holds_own_is_never_removed( string $operation, string $phase, int $offset ): void {
		$own     = time() - 20;
		$foreign = (string) ( $own + $offset );
		$this->hold( 0 === $offset && 'started' === $phase ? str_repeat( 'a', 32 ) : self::tag( (string) $own ), $phase );
		$this->db->option_rows['auto_updater.lock'] = $foreign;
		self::assertTrue( $this->service->native_hold_lock_busy() );
		$this->refused( fn () => $this->operate( $operation ) );
		self::assertSame( $foreign, $this->db->option_rows['auto_updater.lock'] );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		self::assertNotContains( 'invalidate', $this->events );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'hold_operations' )]
	public function test_hour_old_foreign_lock_is_reclaimed_by_the_operation_itself( string $operation ): void {
		foreach ( array( 'started', 'finished' ) as $phase ) {
			$this->hold( self::tag( (string) ( time() - 100 ) ), $phase );
			$this->db->option_rows['auto_updater.lock'] = (string) ( time() - 3601 );
			$this->operate( $operation );
			self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
			self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
			$state                     = $this->store->read();
			$marked                    = $state;
			$marked['native_mutation'] = array(
				'selection_generation' => $state['selection_generation'],
				'owner_tag'            => str_repeat( 'a', 32 ),
				'target_type'          => 'plugin',
				'installed_identity'   => 'target/main.php',
				'phase'                => 'started',
			);
			self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
		}
	}

	public function test_hold_release_refuses_changed_review_record_or_attempts(): void {
		$this->registered = true;
		$digest           = $this->service->native_recovery_confirmation()['digest'];
		$this->managed();
		$this->refused( fn () => $this->release( $digest ) );
		$digest = $this->service->native_recovery_confirmation()['digest'];
		$calls  = 0;
		$this->refused(
			function () use ( $digest, &$calls ): void {
				$this->service->release_native_hold(
					$digest,
					true,
					true,
					function () use ( &$calls ): bool {
						if ( 2 === ++$calls ) {
							$record           = $this->record ?? throw new \LogicException( 'The fixture record is missing.' );
							$record['digest'] = str_repeat( 'e', 64 );
							$this->record     = $record;
						}
						return true;
					}
				);
			}
		);
		$this->db->rows = array(
			array(
				'id'           => 1,
				'provider'     => 'gh',
				'package_type' => 'plugin',
				'package_slug' => 'target',
				'source'       => 'manual',
				'state'        => 'needs_attention',
				'resolved_at'  => null,
				'resolved_by'  => null,
			),
		);
		$this->refused( fn () => $this->release() );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_hold_release_cache_failure_keeps_a_retryable_hold(): void {
		$this->readable();
		$this->cache_failure = true;
		$this->refused( fn () => $this->release() );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		$this->cache_failure = false;
		$this->release();
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
	}

	/** @return iterable<string, array{bool, bool, bool}> */
	public static function unreadable_targets(): iterable {
		yield 'never managed' => array( false, true, true );
		yield 'missing' => array( true, false, false );
		yield 'damaged so it no longer registers' => array( true, true, false );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreadable_targets' )]
	public function test_held_target_without_a_package_page_is_only_removed( bool $managed, bool $present, bool $registered ): void {
		if ( $managed ) {
			$this->managed();
		}
		$this->present    = $present;
		$this->registered = $registered;
		$review           = $this->service->native_recovery_confirmation();
		self::assertFalse( $review['readable'] );
		$this->refused( fn () => $this->release() );
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
		self::assertNotContains( 'invalidate', $this->events );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
		$this->present    = false;
		$this->registered = false;
		$this->recover( $this->service->native_recovery_confirmation()['digest'] );
		self::assertNull( $this->record );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_readable_target_is_never_removed_and_unregistering_mid_release_refuses(): void {
		$this->readable();
		$this->present = true;
		$review        = $this->service->native_recovery_confirmation();
		self::assertTrue( $review['readable'] );
		$this->refused( fn () => $this->recover( $review['digest'] ) );
		self::assertNotNull( $this->record );
		$calls = 0;
		$this->refused(
			fn () => $this->service->release_native_hold(
				$review['digest'],
				true,
				true,
				function () use ( &$calls ): bool {
					if ( 2 === ++$calls ) {
						$this->registered = false;
					}
					return true;
				}
			)
		);
		self::assertArrayHasKey( 'native_mutation', $this->store->read() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_release_and_recovery_callbacks_never_run_inside_selection_transaction(): void {
		$option_deletes                                 = 0;
		$GLOBALS['ran_booster_attempt_on_cache_delete'] = function ( int|string $key, string $group ) use ( &$option_deletes ): void {
			self::assertFalse( $this->in_selection_transaction(), 'Selection cache invalidation must run outside SQL transactions.' );
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				++$option_deletes;
			}
		};
		try {
			$this->readable();
			$digest        = $this->service->native_recovery_confirmation()['digest'];
			$release_calls = 0;
			$this->service->release_native_hold(
				$digest,
				true,
				true,
				function () use ( &$release_calls ): bool {
					self::assertFalse( $this->in_selection_transaction(), 'Release authorization callback must run outside SQL transactions.' );
					++$release_calls;
					return true;
				}
			);
			self::assertGreaterThanOrEqual( 2, $release_calls );
			self::assertSame( 1, $option_deletes );
			self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );

			$state                     = $this->store->read();
			$marked                    = $state;
			$marked['native_mutation'] = array(
				'selection_generation' => $state['selection_generation'],
				'owner_tag'            => str_repeat( 'a', 32 ),
				'target_type'          => 'plugin',
				'installed_identity'   => 'target/main.php',
				'phase'                => 'started',
			);
			$option_deletes            = 0;
			self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
			self::assertSame( 1, $option_deletes );
			$option_deletes   = 0;
			$this->present    = false;
			$this->registered = false;
			$recovery_digest  = $this->service->native_recovery_confirmation()['digest'];
			$recovery_calls   = 0;
			$this->service->recover_native_removal(
				$recovery_digest,
				true,
				true,
				function () use ( &$recovery_calls ): bool {
					self::assertFalse( $this->in_selection_transaction(), 'Recovery authorization callback must run outside SQL transactions.' );
					++$recovery_calls;
					return true;
				}
			);
			self::assertGreaterThanOrEqual( 3, $recovery_calls );
			self::assertSame( 1, $option_deletes );
			self::assertContains( 'COMMIT', $this->db->queries );
			self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_post_commit_selection_cache_failure_preserves_lock_release_failure_while_uncertain_commit_stays_uncertain(): void {
		foreach ( array( 'recover', 'release' ) as $operation ) {
			foreach ( array( 'clear', 'zero', 'throw' ) as $lock_mode ) {
				unset( $this->db->option_rows['auto_updater.lock'] );
				$this->hold( str_repeat( 'a', 32 ), 'started' );
				if ( 'release' === $operation ) {
					$this->present = true;
					$this->readable();
				} else {
					$this->record     = null;
					$this->present    = false;
					$this->registered = false;
				}
				if ( 'throw' === $lock_mode ) {
					$this->db->fail_query_contains = 'DELETE FROM `wp_options`';
				} elseif ( 'zero' === $lock_mode ) {
					$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
				}
				$digest = $this->service->native_recovery_confirmation()['digest'];
				$caught = null;
				$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ): void {
					if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
						throw new \RuntimeException( 'selection cache failed' );
					}
				};
				try {
					if ( 'release' === $operation ) {
						$this->release( $digest );
					} else {
						$this->recover( $digest );
					}
				} catch ( \RuntimeException $failure ) {
					$caught = $failure;
				} finally {
					unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
					$this->db->fail_query_contains = null;
					$this->db->zero_query_contains = null;
					$this->db->last_error          = '';
				}
				self::assertInstanceOf( \RuntimeException::class, $caught, "$operation/$lock_mode" );
				self::assertArrayNotHasKey( 'native_mutation', $this->store->read(), "$operation/$lock_mode" );
				if ( 'clear' === $lock_mode ) {
					self::assertNotInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$lock_mode" );
					self::assertSame( 'selection cache failed', $caught->getMessage(), "$operation/$lock_mode" );
					self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
				} elseif ( 'zero' === $lock_mode ) {
					self::assertInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$lock_mode" );
					self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
					$previous = $caught->getPrevious();
					self::assertInstanceOf( \RuntimeException::class, $previous, "$operation/$lock_mode" );
					self::assertSame( 'selection cache failed', $previous->getMessage(), "$operation/$lock_mode" );
				} else {
					self::assertInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$lock_mode" );
					self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$lock_mode" );
					self::assertInstanceOf( DeploymentStorageFailure::class, $caught->getPrevious(), "$operation/$lock_mode" );
				}
			}
		}

		foreach ( array( 'recover', 'release' ) as $operation ) {
			foreach ( array( 'zero', 'throw' ) as $failure_mode ) {
				unset( $this->db->option_rows['auto_updater.lock'] );
				$this->hold( str_repeat( 'a', 32 ), 'started' );
				$fresh_service         = $this->uncertain_commit_service( $operation );
				$digest                = $fresh_service->native_recovery_confirmation()['digest'];
				$this->db->fail_commit = true;
				if ( 'throw' === $failure_mode ) {
					$this->db->fail_query_contains = 'DELETE FROM `wp_options`';
				} else {
					$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
				}
				$caught = null;
				try {
					if ( 'release' === $operation ) {
						$fresh_service->release_native_hold( $digest, true, true, static fn (): bool => true );
					} else {
						$fresh_service->recover_native_removal( $digest, true, true, static fn (): bool => true );
					}
				} catch ( \RuntimeException $failure ) {
					$caught = $failure;
				} finally {
					$this->db->fail_commit         = false;
					$this->db->fail_query_contains = null;
					$this->db->zero_query_contains = null;
					$this->db->last_error          = '';
				}
				self::assertInstanceOf( \RuntimeException::class, $caught, "$operation/$failure_mode" );
				self::assertNotInstanceOf( NativeHoldLockReleaseFailure::class, $caught, "$operation/$failure_mode" );
				self::assertArrayHasKey( 'native_mutation', $this->store->read(), "$operation/$failure_mode" );
				self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows, "$operation/$failure_mode" );
			}
		}
	}

	private function uncertain_commit_service( string $operation ): PackageRemovalService {
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
		$fresh_store = new GitHubSelectionStore( $this->db, 'wp_options' );
		$selection   = new GitHubProviderSelection( $fresh_store, '/unused', $bundled );
		$plugins     = $this->createMock( PluginRepository::class );
		$gateway     = $this->createMock( PackageRemovalGateway::class );
		if ( 'release' === $operation ) {
			$plugins->method( 'native_recovery_record' )->willReturn(
				array(
					'id'             => '1',
					'package'        => 'target/main.php',
					'type'           => 1,
					'adoption_token' => 'exact-adoption',
					'source'         => 'release_asset',
					'digest'         => str_repeat( 'b', 64 ),
				)
			);
			$gateway->method( 'installed_targets' )->willReturn( array( 'target/main.php' => 'Target' ) );
		} else {
			$plugins->method( 'native_recovery_record' )->willReturn( null );
			$gateway->method( 'deletion_postcondition' )->willReturn( static fn (): bool => true );
		}
		$gateway->expects( self::never() )->method( 'delete_plugin' );
		$gateway->expects( self::never() )->method( 'delete_theme' );
		$attempts = new DeploymentAttemptRepository( $this->db, 'wp_attempts', database_lifecycle: $this->createStub( Database::class ), selection: $selection );
		return new PackageRemovalService( $plugins, $this->createStub( ThemeRepository::class ), $gateway, $attempts, new WordPressUpdaterLock(), github_selection: $selection );
	}

	private function readable(): void {
		$this->managed();
		$this->registered = true;
	}

	private function operate( string $operation ): void {
		if ( 'release' === $operation ) {
			$this->readable();
			$this->release();
			return;
		}
		$this->recover( $this->service->native_recovery_confirmation()['digest'] );
	}

	private function hold( string $tag, string $phase ): void {
		$state = $this->store->read();
		if ( array_key_exists( 'native_mutation', $state ) ) {
			$cleared = $state;
			unset( $cleared['native_mutation'] );
			self::assertTrue( $this->store->compare_and_swap( $state, $cleared ) );
			$state = $cleared;
		}
		$marked                    = $state;
		$marked['native_mutation'] = array(
			'selection_generation' => $state['selection_generation'],
			'owner_tag'            => $tag,
			'target_type'          => 'plugin',
			'installed_identity'   => 'target/main.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
		if ( 'finished' === $phase ) {
			$finished                             = $marked;
			$finished['native_mutation']['phase'] = 'finished';
			self::assertTrue( $this->store->compare_and_swap( $marked, $finished ) );
		}
	}

	private static function tag( string $lock_token ): string {
		$prefix = sprintf( '%016x', (int) $lock_token );
		$nonce  = 'bbbbbbbb';
		return $prefix . $nonce . substr( hash( 'sha256', 'ran-booster-native-lock-v1:' . $prefix . ':' . $nonce ), 0, 8 );
	}

	private function release( ?string $digest = null ): void {
		$this->service->release_native_hold( $digest ?? $this->service->native_recovery_confirmation()['digest'], true, true, static fn (): bool => true );
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

	private function in_selection_transaction(): bool {
		$depth = 0;
		foreach ( $this->db->queries as $query ) {
			if ( 'START TRANSACTION' === $query ) {
				++$depth;
			} elseif ( 'COMMIT' === $query || 'ROLLBACK' === $query ) {
				--$depth;
			}
		}

		return $depth > 0;
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

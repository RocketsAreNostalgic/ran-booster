<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RuntimeException;

require_once __DIR__ . '/../Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';

final class WordPressUpdaterLockTest extends TestCase {
	private const OWNER = 'ran_booster_updater_lock_owner';

	private AttemptRepositoryDatabase $database;
	private mixed $previous_database;

	#[Before]
	protected function prepare_database(): void {
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->database          = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated lock test supplies the real facade's WordPress database boundary.
		$GLOBALS['wpdb']                              = $this->database;
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
	}

	#[After]
	protected function restore_database(): void {
		WordPressUpdaterLock::take_refusal();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the WordPress database boundary installed by this isolated test.
		$GLOBALS['wpdb'] = $this->previous_database;
	}

	public function test_run_preserves_result_identity_and_invalidates_both_caches_on_acquire_and_release(): void {
		$lock   = new WordPressUpdaterLock();
		$result = new \stdClass();
		self::assertInstanceOf( MutationLock::class, $lock );
		self::assertSame(
			$result,
			$lock->run(
				function () use ( $result, $lock ): object {
					self::assertSame( $this->database->option_rows['auto_updater.lock'], $lock->current_token() );
					return $result;
				}
			)
		);
		self::assertSame( array(), $this->database->option_rows );
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

	public function test_stale_reclamation_dispatches_release_with_the_observed_token(): void {
		$stale = (string) ( time() - 7200 );
		$this->database->option_rows['auto_updater.lock'] = $stale;
		$lock  = new class() extends WordPressUpdaterLock {
			/** @var list<string> */
			public array $released = array();
			public function release( string $token ): bool {
				$this->released[] = $token;
				return parent::release( $token );
			}
		};
		$token = $lock->acquire();
		self::assertSame( array( $stale ), $lock->released );
		self::assertNotSame( $stale, $token );
		self::assertSame( $token, $this->database->option_rows['auto_updater.lock'] );
		self::assertTrue( $lock->release( $token ) );
	}

	public function test_current_token_is_read_only_and_release_cannot_remove_a_replacement(): void {
		$lock  = new WordPressUpdaterLock();
		$fresh = (string) ( time() + 600 );
		$this->database->option_rows['auto_updater.lock'] = $fresh;
		self::assertSame( $fresh, $lock->current_token() );
		self::assertFalse( $lock->release( 'foreign-token' ) );
		self::assertSame( $fresh, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( array(), $GLOBALS['ran_booster_attempt_cache_deletes'] );
		foreach ( array( 'malformed', (string) ( time() - 7200 ) ) as $inactive ) {
			$this->database->option_rows['auto_updater.lock'] = $inactive;
			self::assertNull( $lock->current_token() );
			self::assertSame( $inactive, $this->database->option_rows['auto_updater.lock'] );
		}
	}

	public function test_fresh_and_malformed_contention_preserve_the_existing_token_and_core_message(): void {
		$lock = new WordPressUpdaterLock();
		foreach ( array( 'malformed', (string) ( time() + 600 ) ) as $held ) {
			$this->database->option_rows['auto_updater.lock'] = $held;
			try {
				$lock->acquire();
				self::fail( 'An occupied updater lock must not admit another owner.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
				self::assertSame( $held, $this->database->option_rows['auto_updater.lock'] );
			}
		}
		self::assertSame( array(), $GLOBALS['ran_booster_attempt_cache_deletes'] );
	}

	public function test_storage_failures_use_the_core_safe_failure_without_database_details(): void {
		$lock                                = new WordPressUpdaterLock();
		$this->database->fail_query_contains = 'INSERT IGNORE';
		$this->assert_core_storage_failure( $lock->acquire( ... ) );
		$this->database->fail_query_contains = null;
		$this->database->fail_reads          = true;
		$this->assert_core_storage_failure( $lock->current_token( ... ) );
		$this->database->fail_reads                       = false;
		$this->database->fail_query_contains              = 'DELETE FROM';
		$this->database->option_rows['auto_updater.lock'] = 'retained';
		$this->assert_core_storage_failure( static fn (): bool => $lock->release( 'retained' ) );
		self::assertSame( 'retained', $this->database->option_rows['auto_updater.lock'] );
	}

	public function test_missing_or_magic_only_options_fail_closed_before_database_access(): void {
		$lock  = new WordPressUpdaterLock();
		$magic = new class() {
			public int $reads = 0;
			public function __get( string $name ): string {
				++$this->reads;
				return 'options' === $name ? 'wp_options' : '';
			}
		};
		foreach ( array( null, new \stdClass(), $magic ) as $host ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Exercise the accepted fail-closed invalid-host boundary without changing a real WordPress database.
			$GLOBALS['wpdb'] = $host;
			$this->assert_core_storage_failure( $lock->acquire( ... ) );
			$this->assert_core_storage_failure( $lock->current_token( ... ) );
			$this->assert_core_storage_failure( static fn (): bool => $lock->release( 'token' ) );
		}
		self::assertSame( 0, $magic->reads );
	}

	public function test_run_keeps_virtual_dispatch_and_callback_exception_identity(): void {
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'subclass-token' );
		$lock->expects( self::once() )->method( 'release' )->with( 'subclass-token' )->willReturn( true );
		$failure = new RuntimeException( 'callback failure' );
		$this->expectExceptionObject( $failure );
		try {
			$lock->run( static fn () => throw $failure );
		} catch ( RuntimeException $caught ) {
			self::assertSame( $failure, $caught );
			throw $caught;
		}
	}

	public function test_run_keeps_named_acquisition_message_and_previous_cause(): void {
		$failure = new RuntimeException( 'acquisition failure' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'custom acquisition' );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willThrowException( $failure );
		$lock->expects( self::never() )->method( 'release' );
		try {
			$lock->run( static fn () => self::fail( 'Acquisition failed before this operation.' ), acquire_failure_message: 'custom acquisition' );
		} catch ( RuntimeException $caught ) {
			self::assertSame( 'custom acquisition', $caught->getMessage() );
			self::assertSame( $failure, $caught->getPrevious() );
			throw $caught;
		}
	}

	public function test_run_keeps_named_release_message_and_the_failed_operation_as_cause(): void {
		$failure           = new RuntimeException( 'release failure' );
		$operation_failure = new RuntimeException( 'operation failure' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'custom release' );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'subclass-token' );
		$lock->expects( self::once() )->method( 'release' )->with( 'subclass-token' )->willThrowException( $failure );
		try {
			$lock->run( static fn () => throw $operation_failure, release_failure_message: 'custom release' );
		} catch ( RuntimeException $caught ) {
			self::assertSame( 'custom release', $caught->getMessage() );
			// The failed operation stays the cause; the release failure is the outcome.
			self::assertSame( $operation_failure, $caught->getPrevious() );
			throw $caught;
		}
	}

	public function test_run_keeps_the_failed_operation_as_cause_when_release_fails_without_a_message(): void {
		$operation_failure = new RuntimeException( 'operation failure' );
		$releases          = array(
			'thrown'  => new RuntimeException( 'release failure' ),
			'refused' => false,
		);
		$caught_cases      = array();
		$logged            = array();
		$directory         = sys_get_temp_dir() . '/ran-updater-lock-log-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
		BoosterLogger::configure_capture( $capture );
		try {
			foreach ( $releases as $case => $release ) {
				$capture->start();
				$lock   = $this->createMock( WordPressUpdaterLock::class );
				$method = $lock->expects( self::once() )->method( 'release' )->with( 'subclass-token' );
				$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'subclass-token' );
				if ( false === $release ) {
					$method->willReturn( false );
				} else {
					$method->willThrowException( $release );
				}
				try {
					$lock->run( static fn () => throw $operation_failure );
				} catch ( RuntimeException $caught ) {
					self::assertSame( 'The WordPress updater lock could not be released.', $caught->getMessage(), $case );
					self::assertSame( $operation_failure, $caught->getPrevious(), $case );
					$caught_cases[] = $case;
				}
				$logged[ $case ] = array_column( $capture->snapshot()['entries'], 'line' );
			}
		} finally {
			BoosterLogger::configure_capture( null );
			foreach ( (array) glob( $directory . '/*' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
				unlink( (string) $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
		}
		self::assertSame( array_keys( $releases ), $caught_cases, 'A failed release must not be hidden.' );
		// The thrown and the refused release stay distinguishable in the log.
		self::assertSame(
			array(
				'thrown'  => array( '[ran-booster] updater lock release failed {"step":"updater_lock_release","event":"release_failed","outcome_code":"operation_failed"}' ),
				'refused' => array( '[ran-booster] updater lock release failed {"step":"updater_lock_release","event":"not_released","outcome_code":"operation_failed"}' ),
			),
			$logged
		);
	}

	public function test_core_accepts_and_issues_the_branch_updater_token_grammar(): void {
		$lock     = new WordPressUpdaterLock();
		$upstream = $lock->try_acquire_without_reclaim();
		self::assertIsString( $upstream );
		self::assertMatchesRegularExpression( '/\A[0-9]+\.[0-9]{27}\z/D', $upstream );
		self::assertSame( $upstream, $lock->current_token() );
		self::assertTrue( $lock->release( $upstream ) );
	}

	public function test_core_and_the_branch_updater_agree_on_a_fractional_row_at_the_expiry_cutoff(): void {
		// The Branch updater reads time() itself: repeat until every check ran inside one second.
		do {
			$now     = time();
			$lock    = $this->lock_at( $now );
			$cutoff  = $now - 3600;
			$results = array();
			foreach ( array( $cutoff . '.500000000000000000000000000', (string) $cutoff ) as $row ) {
				$this->database->option_rows['auto_updater.lock'] = $row;
				$upstream_live                                    = $row === $lock->current_token();
				try {
					$lock->acquire();
					$reclaimed = true;
				} catch ( RuntimeException $failure ) {
					self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
					$reclaimed = false;
				}
				unset( $this->database->option_rows[ self::OWNER ] );
				$results[ $row ] = array( $upstream_live, $reclaimed );
			}
		} while ( time() !== $now );
		// A row one half-second past the cutoff is live for both; a row exactly at the cutoff is expired for both.
		self::assertSame( array( true, false ), $results[ $cutoff . '.500000000000000000000000000' ] );
		self::assertSame( array( false, true ), $results[ (string) $cutoff ] );
	}

	public function test_every_booster_acquisition_records_its_owner_and_release_removes_it(): void {
		$lock  = $this->lock_at( 1700000000 );
		$token = $lock->acquire( 'branch_deployment' );
		self::assertSame( $token . '|branch_deployment|1700000000', $this->database->option_rows[ self::OWNER ] );
		self::assertSame(
			array(
				'token'      => $token,
				'operation'  => 'branch_deployment',
				'started_at' => 1700000000,
			),
			$lock->recorded_owner()
		);
		self::assertTrue( $lock->release( $token ) );
		self::assertSame( array(), $this->database->option_rows );

		// Callers that pass no operation, or an invalid code, record the generic one.
		$token = (string) $lock->try_acquire_without_reclaim();
		self::assertSame( WordPressUpdaterLock::DEFAULT_OPERATION, $lock->recorded_owner()['operation'] ?? null );
		self::assertTrue( $lock->release( $token ) );
		$token = (string) $lock->try_acquire_without_reclaim( 'Not A Code' );
		self::assertSame( $token . '|package_operation|1700000000', $this->database->option_rows[ self::OWNER ] );
		self::assertTrue( $lock->release( $token ) );
		$seen = null;
		$lock->run(
			function () use ( $lock, &$seen ): void {
				$seen = $lock->recorded_owner()['operation'] ?? null;
			},
			owner_operation: 'extension_install'
		);
		self::assertSame( 'extension_install', $seen );
		self::assertSame( array(), $this->database->option_rows );
	}

	public function test_a_lock_the_record_does_not_name_is_never_shown_or_released(): void {
		$lock   = new WordPressUpdaterLock();
		$token  = $lock->acquire();
		$record = $this->database->option_rows[ self::OWNER ];
		// A different lock row (WordPress's own, or another owner) or a malformed record names no Booster owner.
		foreach ( array(
			(string) time() => $record,
			$token          => $token . '|package_operation',
		) as $row => $owner ) {
			$this->database->option_rows['auto_updater.lock'] = (string) $row;
			$this->database->option_rows[ self::OWNER ]       = $owner;
			self::assertNull( $lock->recorded_owner() );
			self::assertFalse( $lock->release_recorded_owner( $token ) );
			self::assertSame( (string) $row, $this->database->option_rows['auto_updater.lock'] );
		}
		unset( $this->database->option_rows['auto_updater.lock'] );
		self::assertNull( $lock->recorded_owner() );

		$this->database->option_rows['auto_updater.lock'] = $token;
		$this->database->option_rows[ self::OWNER ]       = $record;
		self::assertFalse( $lock->release_recorded_owner( $token . '1' ) );
		self::assertSame( $token, $this->database->option_rows['auto_updater.lock'] );
		self::assertTrue( $lock->release_recorded_owner( $token ) );
		self::assertSame( array(), $this->database->option_rows );
	}

	public function test_a_lost_owner_record_keeps_the_lock_and_shows_no_owner(): void {
		// A failed write, and zero rows that come with a database error, both keep the lock without an owner.
		// The log carries the normalised operation code, never the raw operation.
		foreach ( array(
			'fail_query_contains' => 'Not a code <b>',
			'zero_query_contains' => 'package_operation',
		) as $failure => $operation ) {
			$database               = $this->database;
			$database->{$failure}   = 'ON DUPLICATE KEY UPDATE';
			$database->before_query = static function ( string $query ) use ( $database ): void {
				$database->last_error = str_contains( $query, 'ON DUPLICATE KEY UPDATE' ) ? 'database details must not escape' : '';
			};
			$lock                   = new WordPressUpdaterLock();
			$token                  = '';
			$logged                 = $this->logged_lines(
				static function () use ( $lock, $operation, &$token ): void {
					$token = $lock->acquire( $operation );
				}
			);
			$database->{$failure}   = null;
			$database->before_query = null;
			$database->last_error   = '';
			self::assertSame( array( '[ran-booster] updater lock owner not recorded {"step":"updater_lock_owner","operation":"package_operation","event":"write_failed"}' ), $logged, $failure );
			self::assertSame( $token, $database->option_rows['auto_updater.lock'] );
			self::assertArrayNotHasKey( self::OWNER, $database->option_rows );
			self::assertNull( $lock->recorded_owner() );
			self::assertTrue( $lock->release( $token ) );
		}
	}

	public function test_a_request_that_lost_the_lock_before_recording_its_owner_never_runs_the_operation(): void {
		$other  = '1700000000.' . str_repeat( '7', 27 );
		$lock   = new class( $this->database, $other ) extends WordPressUpdaterLock {
			private bool $paused = false;

			public function __construct( private AttemptRepositoryDatabase $db, private string $other ) {}

			protected function now(): int {
				// Another owner takes the lock while this request pauses between acquiring it and recording its owner.
				if ( ! $this->paused ) {
					$this->paused                               = true;
					$this->db->option_rows['auto_updater.lock'] = $this->other;
					$this->db->option_rows['ran_booster_updater_lock_owner'] = $this->other . '|package_operation|1700000000';
				}
				return time();
			}
		};
		$ran    = array();
		$logged = $this->logged_lines(
			static function () use ( $lock, &$ran ): void {
				try {
					$lock->run(
						static function () use ( &$ran ): void {
							$ran[] = 'operation';
						}
					);
				} catch ( RuntimeException $failure ) {
					$ran[] = $failure->getMessage();
				}
			}
		);
		self::assertSame( array( 'The WordPress updater is already running.' ), $ran );
		self::assertSame( array( '[ran-booster] updater lock owner not recorded {"step":"updater_lock_owner","operation":"package_operation","event":"ownership_lost"}' ), $logged );
		self::assertSame( $other, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $other . '|package_operation|1700000000', $this->database->option_rows[ self::OWNER ] );
		// The lost request observed no holder that refused it, so no owner is named for it.
		self::assertNull( WordPressUpdaterLock::take_refusal() );
	}

	/**
	 * @param callable(): void $action
	 * @return list<string>
	 */
	private function logged_lines( callable $action ): array {
		$directory = sys_get_temp_dir() . '/ran-updater-lock-log-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
		BoosterLogger::configure_capture( $capture );
		try {
			$capture->start();
			$action();
			return array_values( array_map( 'strval', array_column( $capture->snapshot()['entries'], 'line' ) ) );
		} finally {
			BoosterLogger::configure_capture( null );
			foreach ( (array) glob( $directory . '/*' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
				unlink( (string) $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
		}
	}

	private function lock_at( int $now ): WordPressUpdaterLock {
		return new class( $now ) extends WordPressUpdaterLock {
			public function __construct( private int $fixed_now ) {}

			protected function now(): int {
				return $this->fixed_now;
			}
		};
	}

	public function test_ordinary_release_override_failure_preserves_its_identity_during_stale_acquisition(): void {
		$this->database->option_rows['auto_updater.lock'] = (string) ( time() - 7200 );
		$failure = new RuntimeException( 'ordinary release callback' );
		$lock    = new class( $failure ) extends WordPressUpdaterLock {
			public string $seen = '';
			public function __construct( private RuntimeException $failure ) {}
			public function release( string $token ): bool {
				$this->seen = $token;
				throw $this->failure;
			}
		};
		try {
			$lock->acquire();
			self::fail( 'An ordinary override exception must propagate without reclassification.' );
		} catch ( RuntimeException $caught ) {
			self::assertSame( $failure, $caught );
			self::assertSame( $this->database->option_rows['auto_updater.lock'], $lock->seen );
		}
	}

	public function test_foreign_branch_storage_failure_from_a_release_override_is_reclassified(): void {
		$this->database->option_rows['auto_updater.lock'] = (string) ( time() - 7200 );
		$lock = new class() extends WordPressUpdaterLock {
			public function release( string $token ): bool {
				throw new BranchDeploymentLockStorageFailure( $token );
			}
		};
		$this->assert_core_storage_failure( $lock->acquire( ... ) );
	}

	/** @param callable(): mixed $operation */
	private function assert_core_storage_failure( callable $operation ): void {
		try {
			$operation();
			self::fail( 'Uncertain database access must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( DeploymentStorageFailure::unavailable()->getMessage(), $failure->getMessage() );
			self::assertSame( 0, $failure->getCode() );
			self::assertNull( $failure->getPrevious() );
		}
	}
}

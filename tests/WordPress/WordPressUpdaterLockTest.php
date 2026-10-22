<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RuntimeException;

require_once __DIR__ . '/../Deployment/AttemptRepositoryDatabase.php';

final class WordPressUpdaterLockTest extends TestCase {
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

	public function test_run_keeps_named_release_message_cause_and_finally_precedence(): void {
		$failure = new RuntimeException( 'release failure' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'custom release' );
		$lock = $this->createMock( WordPressUpdaterLock::class );
		$lock->expects( self::once() )->method( 'acquire' )->willReturn( 'subclass-token' );
		$lock->expects( self::once() )->method( 'release' )->with( 'subclass-token' )->willThrowException( $failure );
		try {
			$lock->run( static fn () => throw new RuntimeException( 'operation failure' ), release_failure_message: 'custom release' );
		} catch ( RuntimeException $caught ) {
			self::assertSame( 'custom release', $caught->getMessage() );
			self::assertSame( $failure, $caught->getPrevious() );
			throw $caught;
		}
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

<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RuntimeException;

require_once __DIR__ . '/../Deployment/AttemptRepositoryDatabase.php';

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

	public function test_current_token_sql_uses_pinned_executor_and_validates_connection(): void {
		$lock   = new WordPressUpdaterLock();
		$fresh  = (string) ( time() + 600 );
		$stale  = (string) ( time() - 7200 );
		$store  = new GitHubSelectionStore( $this->database, 'wp_options' );
		$bundle = new GitHubReplacementDeclaration(
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
		$store->initialize_bundled( $bundle );
		$selection = new GitHubProviderSelection( $store, '/unused', $bundle );

		$this->database->option_rows['auto_updater.lock'] = $fresh;
		$observed = $store->selection_transaction(
			fn ( callable $execute ): ?string => $lock->current_token_sql( $selection->uses_connection( ... ), $execute )
		);
		self::assertSame( $fresh, $observed );

		foreach ( array( null, 'malformed', $stale ) as $inactive ) {
			if ( null === $inactive ) {
				unset( $this->database->option_rows['auto_updater.lock'] );
			} else {
				$this->database->option_rows['auto_updater.lock'] = $inactive;
			}
			self::assertNull(
				$store->selection_transaction(
					fn ( callable $execute ): ?string => $lock->current_token_sql( $selection->uses_connection( ... ), $execute )
				)
			);
		}

		$this->assert_core_storage_failure(
			fn (): ?string => $store->selection_transaction(
				fn ( callable $execute ): ?string => $lock->current_token_sql( static fn (): bool => false, $execute )
			)
		);
		$this->assert_core_storage_failure(
			static fn (): ?string => $lock->current_token_sql(
				static fn (): bool => true,
				static function (): never {
					throw new RuntimeException( 'sql failure' );
				}
			)
		);
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
			$called          = false;
			$this->assert_core_storage_failure( $lock->acquire( ... ) );
			$this->assert_core_storage_failure(
				static function () use ( $lock, &$called ): ?string {
					return $lock->acquire_or_reclaim_expired_sql(
						static function () use ( &$called ): bool {
							$called = true;
							return true;
						},
						static function () use ( &$called ): null {
							$called = true;
							return null;
						}
					);
				}
			);
			self::assertFalse( $called );
			$this->assert_core_storage_failure( $lock->current_token( ... ) );
			$this->assert_core_storage_failure( static fn (): bool => $lock->release( 'token' ) );
			$this->assert_core_storage_failure( static fn (): string => $lock->acquire_replacing_owner( '1' ) );
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
		self::assertNotSame( $upstream, $lock->acquire_replacing_owner( $upstream ) );
		self::assertMatchesRegularExpression( '/\A[0-9]+\.[0-9]{27}\z/D', (string) $lock->current_token() );
	}

	public function test_core_and_the_branch_updater_agree_on_a_fractional_row_at_the_expiry_cutoff(): void {
		$declaration = new GitHubReplacementDeclaration(
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
		$store       = new GitHubSelectionStore( $this->database, 'wp_options' );
		$store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $store, '/unused', $declaration );
		// The Branch updater reads time() itself: repeat until every check ran inside one second.
		do {
			$now     = time();
			$lock    = new class( $now ) extends WordPressUpdaterLock {
				public function __construct( private int $fixed_now ) {}

				protected function now(): int {
					return $this->fixed_now;
				}
			};
			$cutoff  = $now - 3600;
			$results = array();
			foreach ( array( $cutoff . '.500000000000000000000000000', (string) $cutoff ) as $row ) {
				$this->database->option_rows['auto_updater.lock'] = $row;
				$upstream_live                                    = $row === $lock->current_token();
				$core_live                                        = $row === $store->selection_transaction( fn ( callable $execute ): ?string => $lock->current_token_sql( $selection->uses_connection( ... ), $execute ) );
				try {
					$lock->acquire_replacing_owner( '1.000000000000000000000000000' );
					$replaced = true;
				} catch ( RuntimeException ) {
					$replaced = false;
				}
				$replaced = $replaced && $row !== $this->database->option_rows['auto_updater.lock'];
				$this->database->option_rows['auto_updater.lock'] = $row;
				$reclaimed                                        = null !== $selection->without_native_mutation_hold( fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute ) );
				$results[ $row ]                                  = array( $upstream_live, $core_live, $replaced, $reclaimed );
			}
		} while ( time() !== $now );
		// A row one half-second past the cutoff is live for both; a row exactly at the cutoff is expired for both.
		self::assertSame( array( true, true, false, false ), $results[ $cutoff . '.500000000000000000000000000' ] );
		self::assertSame( array( false, false, true, true ), $results[ (string) $cutoff ] );
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
		$replacement = $lock->acquire_replacing_owner( $token, 'Not A Code' );
		self::assertSame( $replacement . '|package_operation|1700000000', $this->database->option_rows[ self::OWNER ] );
		self::assertTrue( $lock->release( $replacement ) );
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
		$this->database->fail_query_contains = 'ON DUPLICATE KEY UPDATE';
		$lock                                = new WordPressUpdaterLock();
		$token                               = $lock->acquire();
		// WordPress clears the failed write's error on its next query; this fake keeps it.
		$this->database->last_error = '';
		self::assertSame( $token, $this->database->option_rows['auto_updater.lock'] );
		self::assertArrayNotHasKey( self::OWNER, $this->database->option_rows );
		self::assertNull( $lock->recorded_owner() );
		self::assertTrue( $lock->release( $token ) );
	}

	public function test_transactional_acquisition_records_its_owner_in_the_same_transaction(): void {
		$now         = time();
		$lock        = $this->lock_at( $now );
		$declaration = new GitHubReplacementDeclaration(
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
		$store       = new GitHubSelectionStore( $this->database, 'wp_options' );
		$store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $store, '/unused', $declaration );
		$acquire   = fn (): ?string => $selection->without_native_mutation_hold( fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute, 'release_install' ) );
		// A free lock, then an expired one reclaimed.
		foreach ( array( null, ( $now - 3601 ) . '.000000000000000000000000000' ) as $held ) {
			if ( null !== $held ) {
				$this->database->option_rows['auto_updater.lock'] = $held;
			}
			$token = (string) $acquire();
			self::assertSame( $token . '|release_install|' . $now, $this->database->option_rows[ self::OWNER ] );
			self::assertSame( $token, $this->database->option_rows['auto_updater.lock'] );
		}
		// The record is part of the acquisition: when it cannot be written, the lock is not taken either.
		unset( $this->database->option_rows['auto_updater.lock'], $this->database->option_rows[ self::OWNER ] );
		$this->database->fail_query_contains = 'ON DUPLICATE KEY UPDATE';
		try {
			$acquire();
			self::fail( 'A lost owner record must abort the transactional acquisition.' );
		} catch ( DeploymentStorageFailure | DatabaseLifecycleFailure ) {
			self::assertArrayNotHasKey( 'auto_updater.lock', $this->database->option_rows );
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

	public function test_acquire_or_reclaim_expired_sql_is_atomic_inside_selection_transaction_without_cache_callbacks(): void {
		$lock      = new WordPressUpdaterLock();
		$store     = new GitHubSelectionStore( $this->database, 'wp_options' );
		$bundled   = new GitHubReplacementDeclaration(
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
		$state     = $store->initialize_bundled( $bundled );
		$selection = new GitHubProviderSelection( $store, '/unused', $bundled );

		$selection_cache_deletes = array(
			array( 'ran_booster_github_selection', 'options' ),
			array( 'alloptions', 'options' ),
			array( 'notoptions', 'options' ),
		);
		foreach ( array( 'malformed', (string) ( time() + 600 ) ) as $held ) {
			$this->database->option_rows['auto_updater.lock'] = $held;
			$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
			self::assertNull(
				$selection->without_native_mutation_hold(
					fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute )
				)
			);
			self::assertSame( $held, $this->database->option_rows['auto_updater.lock'] );
			self::assertSame( $selection_cache_deletes, $GLOBALS['ran_booster_attempt_cache_deletes'] );
		}

		$stale = (string) ( time() - 7200 );
		$this->database->option_rows['auto_updater.lock'] = $stale;
		$marked                    = $state;
		$marked['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'a', 16 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'other/other.php',
			'phase'                => 'started',
		);
		self::assertTrue( $store->compare_and_swap( $state, $marked ) );
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		self::assertNull(
			$selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute )
			)
		);
		self::assertSame( $stale, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $selection_cache_deletes, $GLOBALS['ran_booster_attempt_cache_deletes'] );

		$cleared = $marked;
		unset( $cleared['native_mutation'] );
		self::assertTrue( $store->compare_and_swap( $marked, $cleared ) );

		$this->assert_core_storage_failure(
			fn (): ?string => $selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( static fn (): bool => false, $execute )
			)
		);
		self::assertSame( $stale, $this->database->option_rows['auto_updater.lock'] );

		$this->assert_core_storage_failure(
			fn (): ?string => $selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql(
					static fn ( object $connection ): bool => $selection->uses_connection( $connection, 'other_options' ),
					$execute
				)
			)
		);
		self::assertSame( $stale, $this->database->option_rows['auto_updater.lock'] );

		$aborted_commit_calls                = 0;
		$this->database->fail_query_contains = 'DELETE FROM';
		try {
			$this->assert_core_storage_failure(
				fn (): ?string => $selection->without_native_mutation_hold(
					fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute ),
					static function () use ( &$aborted_commit_calls ): void {
						++$aborted_commit_calls;
					}
				)
			);
			self::assertSame( 0, $aborted_commit_calls );
			self::assertSame( $stale, $this->database->option_rows['auto_updater.lock'] );
			self::assertContains( 'ROLLBACK', $this->database->queries );
		} finally {
			$this->database->fail_query_contains = null;
		}

		$this->database->zero_query_contains = 'INSERT IGNORE';
		try {
			$selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute )
			);
			self::fail( 'Failed reinsertion after expired-lock deletion must abort the transaction.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
			self::assertSame( $stale, $this->database->option_rows['auto_updater.lock'] );
			self::assertContains( 'ROLLBACK', $this->database->queries );
		} finally {
			$this->database->zero_query_contains = null;
		}

		$this->database->queries                      = array();
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		$committed_token                              = null;
		$reclaimed                                    = $selection->without_native_mutation_hold(
			fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute ),
			static function ( ?string $token ) use ( &$committed_token ): void {
				$committed_token = $token;
			}
		);
		self::assertIsString( $reclaimed );
		self::assertSame( $reclaimed, $committed_token );
		self::assertNotSame( $stale, $reclaimed );
		self::assertSame( $reclaimed, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $selection_cache_deletes, $GLOBALS['ran_booster_attempt_cache_deletes'] );

		$start_index  = array_search( 'START TRANSACTION', $this->database->queries, true );
		$commit_index = array_search( 'COMMIT', $this->database->queries, true );
		$delete_index = null;
		foreach ( $this->database->queries as $index => $query ) {
			if ( str_starts_with( $query, 'DELETE FROM `wp_options` WHERE option_name = \'auto_updater.lock\'' ) ) {
				$delete_index = $index;
				break;
			}
		}
		self::assertIsInt( $start_index );
		self::assertIsInt( $delete_index );
		self::assertIsInt( $commit_index );
		self::assertGreaterThan( $start_index, $delete_index );
		self::assertLessThan( $commit_index, $delete_index );

		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		$lock->invalidate_lock_cache();
		self::assertSame(
			array(
				array( 'auto_updater.lock', 'options' ),
				array( 'notoptions', 'options' ),
			),
			$GLOBALS['ran_booster_attempt_cache_deletes']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_without_native_mutation_hold_delivers_reclaimed_token_before_selection_cache_exception_and_skips_callback_on_uncertain_commit(): void {
		$lock    = new WordPressUpdaterLock();
		$store   = new GitHubSelectionStore( $this->database, 'wp_options' );
		$bundled = new GitHubReplacementDeclaration(
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
		$store->initialize_bundled( $bundled );
		$selection = new GitHubProviderSelection( $store, '/unused', $bundled );

		$stale = (string) ( time() - 7200 );
		$this->database->option_rows['auto_updater.lock'] = $stale;
		$this->database->queries                          = array();
		$committed_token                                  = null;
		$GLOBALS['ran_booster_attempt_on_cache_delete']   = static function ( int|string $key, string $group ): void {
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				throw new RuntimeException( 'selection cache failed after commit' );
			}
		};
		try {
			$selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute ),
				static function ( ?string $token ) use ( &$committed_token ): void {
					$committed_token = $token;
				}
			);
			self::fail( 'Post-commit selection cache exception must propagate.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'selection cache failed after commit', $failure->getMessage() );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}

		self::assertIsString( $committed_token );
		self::assertNotSame( $stale, $committed_token );
		self::assertSame( $committed_token, $this->database->option_rows['auto_updater.lock'] );
		self::assertNotContains( 'ROLLBACK', $this->database->queries );
		self::assertTrue( $lock->release( $committed_token ) );

		$this->database->option_rows['auto_updater.lock'] = $stale;
		$this->database->fail_commit                      = true;
		$uncertain_callback_called                        = false;
		$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
		try {
			$selection->without_native_mutation_hold(
				fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute ),
				static function () use ( &$uncertain_callback_called ): void {
					$uncertain_callback_called = true;
				}
			);
			self::fail( 'Uncertain commit must fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_commit_uncertain', $failure->reason() );
		}
		self::assertFalse( $uncertain_callback_called );
		self::assertSame(
			array(
				array( GitHubSelectionStore::OPTION, 'options' ),
				array( 'alloptions', 'options' ),
				array( 'notoptions', 'options' ),
			),
			$GLOBALS['ran_booster_attempt_cache_deletes']
		);
		$this->database->fail_commit = false;
		$this->expectException( DatabaseLifecycleFailure::class );
		$selection->without_native_mutation_hold(
			fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $selection->uses_connection( ... ), $execute )
		);
	}

	public function test_acquire_replacing_owner_uses_distinct_atomic_handoff_and_refuses_concurrent_recovery(): void {
		$fixed_now = time();
		$lock      = new class( $fixed_now ) extends WordPressUpdaterLock {
			public function __construct( private int $fixed_now ) {}

			protected function now(): int {
				return $this->fixed_now;
			}
		};
		$own       = $fixed_now . '.' . str_repeat( '1', 27 );
		$fresh     = static fn ( string $token ): string => 1 === preg_match( '/\A' . $fixed_now . '\.[0-9]{27}\z/D', $token ) && $own !== $token ? $token : self::fail( 'Recovery must take a fresh fractional token.' );
		$caches    = array(
			array( 'auto_updater.lock', 'options' ),
			array( 'notoptions', 'options' ),
		);

		$this->database->option_rows['auto_updater.lock'] = $own;
		$this->database->queries                          = array();
		$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
		$expected = $fresh( $lock->acquire_replacing_owner( $own ) );
		self::assertSame( $expected, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $caches, $GLOBALS['ran_booster_attempt_cache_deletes'] );
		$saw_binary_update = false;
		foreach ( $this->database->queries as $query ) {
			self::assertStringNotContainsString( 'DELETE FROM', $query );
			if ( str_starts_with( $query, 'UPDATE `wp_options`' ) ) {
				self::assertStringContainsString( 'CAST(option_value AS BINARY) = CAST(', $query );
				$saw_binary_update = true;
			}
		}
		self::assertTrue( $saw_binary_update );

		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		try {
			$lock->acquire_replacing_owner( $own );
			self::fail( 'Concurrent recovery against the same interrupted owner must be refused.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
			self::assertSame( $expected, $this->database->option_rows['auto_updater.lock'] );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_attempt_cache_deletes'] );

		unset( $this->database->option_rows['auto_updater.lock'] );
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		$expected                                     = $fresh( $lock->acquire_replacing_owner( $own ) );
		self::assertSame( $expected, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $caches, $GLOBALS['ran_booster_attempt_cache_deletes'] );
		try {
			$lock->acquire_replacing_owner( $own );
			self::fail( 'Absent-row acquisition within the same second must still keep the recovery lock distinct.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
			self::assertSame( $expected, $this->database->option_rows['auto_updater.lock'] );
		}

		$older_own                                        = (string) ( $fixed_now - 10 );
		$this->database->option_rows['auto_updater.lock'] = $older_own;
		$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
		$replaced = $fresh( $lock->acquire_replacing_owner( $older_own ) );
		self::assertSame( $replaced, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $caches, $GLOBALS['ran_booster_attempt_cache_deletes'] );

		foreach ( array( 'malformed', $own . ' ', (string) ( $fixed_now - 3601 ) . ' ', (string) ( $fixed_now + 5 ) ) as $foreign ) {
			$this->database->option_rows['auto_updater.lock'] = $foreign;
			$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
			try {
				$lock->acquire_replacing_owner( $own );
				self::fail( 'Live or malformed foreign locks must never be replaced.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
				self::assertSame( $foreign, $this->database->option_rows['auto_updater.lock'] );
			}
			self::assertSame( array(), $GLOBALS['ran_booster_attempt_cache_deletes'] );
		}

		$expired_foreign                                  = (string) ( $fixed_now - 3601 );
		$this->database->option_rows['auto_updater.lock'] = $expired_foreign;
		$this->database->queries                          = array();
		$GLOBALS['ran_booster_attempt_cache_deletes']     = array();
		$expected = $fresh( $lock->acquire_replacing_owner( $own ) );
		self::assertSame( $expected, $this->database->option_rows['auto_updater.lock'] );
		self::assertSame( $caches, $GLOBALS['ran_booster_attempt_cache_deletes'] );
		foreach ( $this->database->queries as $query ) {
			self::assertStringNotContainsString( 'DELETE FROM', $query );
		}

		$this->database->fail_query_contains = 'UPDATE';
		$this->assert_core_storage_failure( static fn (): string => $lock->acquire_replacing_owner( $own ) );
		$this->database->fail_query_contains = null;

		unset( $this->database->option_rows['auto_updater.lock'] );
		$this->database->fail_query_contains = 'INSERT IGNORE';
		$this->assert_core_storage_failure( static fn (): string => $lock->acquire_replacing_owner( $own ) );
		$this->database->fail_query_contains = null;

		$this->database->option_rows['auto_updater.lock'] = 'other';
		$this->database->fail_reads                       = true;
		$this->assert_core_storage_failure( static fn (): string => $lock->acquire_replacing_owner( $own ) );
		$this->database->fail_reads = false;
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

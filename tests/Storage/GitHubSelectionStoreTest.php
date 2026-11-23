<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

final class GitHubSelectionStoreTest extends TestCase {
	private function declaration(): GitHubReplacementDeclaration {
		return new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'booster/main.php',
				'implementation_id'      => 'bundled-test',
				'compatibility_revision' => 'test-1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array( 'github-classic-pat-v1' ),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
	}

	public function test_runtime_absence_is_not_an_install_or_fallback(): void {
		$db = new SelectionDatabase();
		try {
			( new GitHubSelectionStore( $db, 'wp_options' ) )->read();
			self::fail();
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_read_failed', $failure->reason() );
		}
		self::assertSame( array(), $db->rows );
	}

	public function test_initialization_retains_contract_and_retry_preserves_newer_selection(): void {
		$db      = new SelectionDatabase();
		$store   = new GitHubSelectionStore( $db, 'wp_options' );
		$initial = $store->initialize_bundled( $this->declaration() );
		self::assertSame( 'no', $db->rows[0]->autoload );
		self::assertSame( 'test-1', $initial['declaration']['compatibility_revision'] );
		$next                         = $initial;
		$next['selection_generation'] = 1;
		$next['selected']             = 'replacement';
		self::assertTrue( $store->compare_and_swap( $initial, $next ) );
		self::assertSame( $next, $store->initialize_bundled( $this->declaration() ) );
		self::assertFalse( $store->compare_and_swap( $initial, $next ) );
		self::assertSame( $next, $store->read( true ) );
		self::assertStringContainsString( 'BINARY option_value', implode( '\n', $db->queries ) );
		self::assertStringContainsString( 'FOR UPDATE', implode( '\n', $db->queries ) );
	}

	public function test_uncertain_applied_write_invalidates_all_caches_and_requires_fresh_read(): void {
		$db                           = new SelectionDatabase();
		$store                        = new GitHubSelectionStore( $db, 'wp_options' );
		$old                          = $store->initialize_bundled( $this->declaration() );
		$next                         = $old;
		$next['selection_generation'] = 1;
		$db->fail_write               = true;
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		try {
			$store->compare_and_swap( $old, $next );
			self::fail();
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_write_failed', $failure->reason() );
		}
		self::assertSame( $next, $store->read() );
		self::assertSame( array( array( GitHubSelectionStore::OPTION, 'options' ), array( 'alloptions', 'options' ), array( 'notoptions', 'options' ) ), $GLOBALS['ran_booster_attempt_cache_deletes'] );
	}

	public function test_missing_ambiguous_autoloaded_and_noncanonical_rows_refuse(): void {
		foreach ( array( 'missing', 'duplicate', 'autoload', 'bytes' ) as $case ) {
			$db    = new SelectionDatabase();
			$store = new GitHubSelectionStore( $db, 'wp_options' );
			$store->initialize_bundled( $this->declaration() );
			if ( 'missing' === $case ) {
				$db->rows = array(); }
			if ( 'duplicate' === $case ) {
				$db->rows[] = clone $db->rows[0]; }
			if ( 'autoload' === $case ) {
				$db->rows[0]->autoload = 'yes'; }
			if ( 'bytes' === $case ) {
				$db->rows[0]->option_value .= ' '; }
			try {
				$store->read();
				self::fail( $case );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertContains( $failure->reason(), array( 'selection_read_failed', 'selection_invalid' ) );
			}
		}
	}

	public function test_unsupported_engine_or_nonwhole_unique_index_refuses_before_write(): void {
		foreach ( array( 'engine', 'unique', 'prefix' ) as $case ) {
			$db = new SelectionDatabase();
			if ( 'engine' === $case ) {
				$db->engine = 'MyISAM'; }
			if ( 'unique' === $case ) {
				$db->unique = false; }
			if ( 'prefix' === $case ) {
				$db->prefix = true; }
			try {
				( new GitHubSelectionStore( $db, 'wp_options' ) )->initialize_bundled( $this->declaration() );
				self::fail( $case );
			} catch ( DatabaseLifecycleFailure ) {
				self::assertSame( array(), $db->rows );
			}
		}
	}

	public function test_storage_shape_is_checked_once_until_invalidated_but_the_row_is_read_every_call(): void {
		$db      = new SelectionDatabase();
		$writer  = new GitHubSelectionStore( $db, 'wp_options' );
		$initial = $writer->initialize_bundled( $this->declaration() );
		$store   = new GitHubSelectionStore( $db, 'wp_options' );
		$count   = static fn ( string $sql ): int => count( array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, $sql ) ) );
		$checks  = static fn (): array => array( $count( 'SHOW TABLE STATUS' ), $count( 'SHOW COLUMNS' ), $count( 'SHOW INDEX' ) );
		$next    = $initial;
		++$next['selection_generation'];

		$db->queries = array();
		self::assertSame( $initial, $store->read() );
		self::assertSame( $initial, $store->read( true ) );
		self::assertSame( array( 1, 1, 1 ), $checks() );
		self::assertSame( 2, $count( 'SELECT option_name, option_value, autoload' ) );

		// Another writer changes the row; this store reads it fresh without checking the shape again.
		self::assertTrue( $writer->compare_and_swap( $initial, $next ) );
		$db->queries = array();
		self::assertSame( $next, $store->read() );
		self::assertSame( array( 0, 0, 0 ), $checks() );
		self::assertSame( 1, $count( 'SELECT option_name, option_value, autoload' ) );

		$store->invalidate_cache();
		$db->queries = array();
		self::assertSame( $next, $store->read() );
		self::assertSame( $next, $store->read( true ) );
		self::assertSame( array( 1, 1, 1 ), $checks() );

		// A write through this store invalidates, so the next call verifies the shape again.
		$last = $next;
		++$last['selection_generation'];
		self::assertTrue( $store->compare_and_swap( $next, $last ) );
		$db->queries = array();
		self::assertSame( $last, $store->read() );
		self::assertSame( array( 1, 1, 1 ), $checks() );
	}

	public function test_failed_storage_shape_refuses_on_every_call(): void {
		$db         = new SelectionDatabase();
		$db->engine = 'MyISAM';
		$store      = new GitHubSelectionStore( $db, 'wp_options' );
		foreach ( array( 1, 2 ) as $attempt ) {
			try {
				$store->read();
				self::fail( (string) $attempt );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 'wrong_storage_engine', $failure->reason() );
			}
			self::assertCount( $attempt, array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );
		}
		self::assertSame( array(), array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SELECT' ) ) );
	}

	public function test_native_owner_cannot_be_overwritten_or_switched_but_revocation_retains_it(): void {
		$db                         = new SelectionDatabase();
		$store                      = new GitHubSelectionStore( $db, 'wp_options' );
		$initial                    = $store->initialize_bundled( $this->declaration() );
		$started                    = $initial;
		$started['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'one/one.php',
			'phase'                => 'started',
		);
		self::assertTrue( $store->compare_and_swap( $initial, $started ) );
		foreach ( array( 'overwrite', 'switch' ) as $case ) {
			$bad = $started;
			if ( 'overwrite' === $case ) {
				$bad['native_mutation']['owner_tag'] = str_repeat( 'b', 32 ); }
			if ( 'switch' === $case ) {
				$bad['selection_generation'] = 1;
				$bad['selected']             = 'replacement'; }
			try {
				$store->compare_and_swap( $started, $bad );
				self::fail( $case );
			} catch ( \InvalidArgumentException ) {
				self::assertSame( $started, $store->read() );
			}
		}
		$revoked                         = $started;
		$revoked['selection_generation'] = 1;
		$revoked['approval']             = 'revoked';
		self::assertTrue( $store->compare_and_swap( $started, $revoked ) );
		$completed = $revoked;
		unset( $completed['native_mutation'] );
		self::assertTrue( $store->compare_and_swap( $revoked, $completed ) );
		self::assertSame( 'revoked', $store->read()['approval'] );
	}

	public function test_native_marker_identity_bounds_are_specific_to_target_type(): void {
		foreach ( array( array( 'plugin', 195, true ), array( 'plugin', 255, true ), array( 'plugin', 256, false ), array( 'theme', 191, true ), array( 'theme', 192, false ) ) as $case ) {
			list( $type, $length, $accepted ) = $case;
			$db                               = new SelectionDatabase();
			$store                            = new GitHubSelectionStore( $db, 'wp_options' );
			$initial                          = $store->initialize_bundled( $this->declaration() );
			$next                             = $initial;
			$identity                         = 'plugin' === $type
				? ( 195 === $length ? str_repeat( 'A', 100 ) . '/' . str_repeat( 'b', 90 ) . '.php' : str_repeat( 'A', $length - 105 ) . '/' . str_repeat( 'b', 100 ) . '.php' )
				: str_repeat( 'a', $length );
			$next['native_mutation']          = array(
				'selection_generation' => 0,
				'owner_tag'            => str_repeat( 'a', 32 ),
				'target_type'          => $type,
				'installed_identity'   => $identity,
				'phase'                => 'started',
			);
			try {
				self::assertTrue( $store->compare_and_swap( $initial, $next ) );
				self::assertTrue( $accepted );
				self::assertSame( $next, $store->read() );
			} catch ( \InvalidArgumentException ) {
				self::assertFalse( $accepted );
				self::assertSame( $initial, $store->read() );
			}
		}
	}

	public function test_serialized_active_plugins_identity_length_boundary(): void {
		foreach ( array( 195, 255, 256 ) as $length ) {
			$identity = 195 === $length ? str_repeat( 'A', 100 ) . '/' . str_repeat( 'b', 90 ) . '.php' : str_repeat( 'A', $length - 105 ) . '/' . str_repeat( 'b', 100 ) . '.php';
			$db       = new SelectionDatabase();
			$db->rows = array(
				(object) array(
					'option_name'  => 'active_plugins',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The WordPress option uses this format.
					'option_value' => serialize( array( $identity ) ),
					'autoload'     => 'yes',
				),
			);
			try {
				self::assertSame( array( $identity ), ( new GitHubSelectionStore( $db, 'wp_options' ) )->active_plugins() );
				self::assertLessThanOrEqual( 255, $length );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 256, $length );
				self::assertSame( 'active_plugins_invalid', $failure->reason() );
			}
		}
	}

	public function test_cas_rejects_unretained_contract_and_invalid_generation_without_write(): void {
		$db      = new SelectionDatabase();
		$store   = new GitHubSelectionStore( $db, 'wp_options' );
		$initial = $store->initialize_bundled( $this->declaration() );
		foreach ( array( 'declaration', 'formats', 'generation', 'unknown' ) as $case ) {
			$next                         = $initial;
			$next['selection_generation'] = 1;
			if ( 'declaration' === $case ) {
				$next['declaration'] = null;
			}
			if ( 'formats' === $case ) {
				$next['approved_api_formats'] = array();
			}
			if ( 'generation' === $case ) {
				$next['selection_generation'] = 2;
			}
			if ( 'unknown' === $case ) {
				$next['ignored_candidate'] = 'other/main.php';
			}
			try {
				$store->compare_and_swap( $initial, $next );
				self::fail( $case );
			} catch ( \InvalidArgumentException ) {
				self::assertSame( $initial, $store->read() );
			}
		}
	}

	public function test_successful_query_without_exact_readback_is_not_success(): void {
		$db                           = new SelectionDatabase();
		$store                        = new GitHubSelectionStore( $db, 'wp_options' );
		$initial                      = $store->initialize_bundled( $this->declaration() );
		$next                         = $initial;
		$next['selection_generation'] = 1;
		$db->corrupt_readback         = true;
		$this->expectException( DatabaseLifecycleFailure::class );
		$store->compare_and_swap( $initial, $next );
	}
	public function test_thrown_commit_pauses_request_regardless_of_database_outcome(): void {
		foreach ( array( false, true ) as $applied ) {
			$db                           = new SelectionDatabase();
			$store                        = new GitHubSelectionStore( $db, 'wp_options' );
			$old                          = $store->initialize_bundled( $this->declaration() );
			$next                         = $old;
			$next['selection_generation'] = 1;
			$db->throw_commit             = true;
			$db->commit_applied           = $applied;
			try {
				$store->selection_transaction( fn (): bool => $store->compare_and_swap( $old, $next ) );
				self::fail( 'Thrown commit reported success.' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'commit threw', $failure->getMessage() );
			}
			self::assertSame( $applied ? $next : $old, $store->read() );
			$db->throw_commit = false;
			$this->assert_paused( $store, $db );
		}
	}

	public function test_rollback_failure_preserves_original_error_and_pauses_request(): void {
		foreach ( array( 'false', 'throw', 'last_error' ) as $mode ) {
			$db                 = new SelectionDatabase();
			$store              = new GitHubSelectionStore( $db, 'wp_options' );
			$db->fail_rollback  = 'false' === $mode;
			$db->throw_rollback = 'throw' === $mode;
			$db->boundary_error = 'last_error' === $mode ? 'ROLLBACK' : null;
			try {
				$store->selection_transaction( static fn (): never => throw new \RuntimeException( 'original' ) );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'original', $failure->getMessage() );
			}
			$this->assert_paused( $store, $db );
		}
	}

	public function test_transaction_boundaries_clear_stale_errors_and_reject_new_errors(): void {
		$db             = new SelectionDatabase();
		$store          = new GitHubSelectionStore( $db, 'wp_options' );
		$db->last_error = 'prior query';
		self::assertSame( 'success', $store->selection_transaction( static fn (): string => 'success' ) );
		foreach ( array( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'START TRANSACTION', 'COMMIT' ) as $statement ) {
			$db                 = new SelectionDatabase();
			$store              = new GitHubSelectionStore( $db, 'wp_options' );
			$db->boundary_error = $statement;
			try {
				$store->selection_transaction( static fn (): string => 'never returned' );
				self::fail( $statement );
			} catch ( DatabaseLifecycleFailure ) {
				self::assertContains( $statement, $db->queries );
			}
		}
	}

	private function assert_paused( GitHubSelectionStore $store, SelectionDatabase $db ): void {
		$queries = $db->queries;
		try {
			$store->selection_transaction( static fn (): bool => true );
			self::fail( 'Uncertain connection reused.' );
		} catch ( DatabaseLifecycleFailure ) {
			self::assertSame( $queries, $db->queries );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_without_native_mutation_hold_verifies_storage_before_transaction_and_delivers_committed_result_before_cache_failure(): void {
		$db          = new SelectionDatabase();
		$store       = new GitHubSelectionStore( $db, 'wp_options' );
		$initial     = $store->initialize_bundled( $this->declaration() );
		$db->queries = array();

		$committed                                      = null;
		$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ): void {
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				throw new \RuntimeException( 'selection cache failed' );
			}
		};
		try {
			$store->without_native_mutation_hold(
				static fn (): string => 'committed-token',
				static function ( string $token ) use ( &$committed ): void {
					$committed = $token;
				}
			);
			self::fail( 'Selection cache failure after COMMIT must propagate.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'selection cache failed', $failure->getMessage() );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}

		self::assertSame( 'committed-token', $committed );
		$tx_index = array_search( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $db->queries, true );
		self::assertIsInt( $tx_index );
		$show_index = array_key_first( array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );
		self::assertIsInt( $show_index );
		self::assertLessThan( $tx_index, $show_index );

		// invalidate_cache() must have cleared storage_verified before throwing, so the next read re-verifies storage.
		$db->queries = array();
		self::assertSame( $initial, $store->read() );
		self::assertNotEmpty( array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );
	}

	public function test_without_native_mutation_hold_skips_committed_callback_on_hold_failure_or_uncertain_commit(): void {
		$db                        = new SelectionDatabase();
		$store                     = new GitHubSelectionStore( $db, 'wp_options' );
		$initial                   = $store->initialize_bundled( $this->declaration() );
		$marked                    = $initial;
		$marked['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'one/one.php',
			'phase'                => 'started',
		);
		self::assertTrue( $store->compare_and_swap( $initial, $marked ) );

		$called = false;
		$result = $store->without_native_mutation_hold(
			static function (): string {
				self::fail( 'Operation must not run while a hold is present.' );
			},
			static function () use ( &$called ): void {
				$called = true;
			}
		);
		self::assertNull( $result );
		self::assertFalse( $called );

		foreach ( array( 'operation_throws', 'fail_commit', 'throw_commit_applied', 'throw_commit_unapplied' ) as $case ) {
			$case_db    = new SelectionDatabase();
			$case_store = new GitHubSelectionStore( $case_db, 'wp_options' );
			$case_store->initialize_bundled( $this->declaration() );
			$case_called = false;
			if ( 'fail_commit' === $case ) {
				$case_db->fail_commit = true;
			} elseif ( str_starts_with( $case, 'throw_commit' ) ) {
				$case_db->throw_commit   = true;
				$case_db->commit_applied = 'throw_commit_applied' === $case;
			}
			$caught = null;
			try {
				$case_store->without_native_mutation_hold(
					static function () use ( $case ): string {
						if ( 'operation_throws' === $case ) {
							throw new \RuntimeException( 'operation failed' );
						}
						return 'token-123';
					},
					static function () use ( &$case_called ): void {
						$case_called = true;
					}
				);
			} catch ( \Throwable $throwable ) {
				$caught = $throwable;
			}
			self::assertInstanceOf(
				'fail_commit' === $case ? DatabaseLifecycleFailure::class : \RuntimeException::class,
				$caught,
				$case
			);
			self::assertFalse( $case_called, $case );
			if ( 'operation_throws' !== $case ) {
				$case_db->fail_commit  = false;
				$case_db->throw_commit = false;
				$this->assert_paused( $case_store, $case_db );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_selection_transaction_defers_cas_cache_invalidation_after_commit_and_runs_committed_callback(): void {
		$db                           = new SelectionDatabase();
		$store                        = new GitHubSelectionStore( $db, 'wp_options' );
		$old                          = $store->initialize_bundled( $this->declaration() );
		$next                         = $old;
		$next['selection_generation'] = 1;
		$db->queries                  = array();
		$option_deletes               = 0;
		$committed                    = null;
		$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ) use ( $db, &$option_deletes ): void {
			self::assertContains( 'COMMIT', $db->queries, 'Selection cache invalidation must wait until after COMMIT.' );
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				++$option_deletes;
			}
		};
		try {
			$result = $store->selection_transaction(
				static function ( callable $execute ) use ( $store, $old, $next ): bool {
					$rows = $execute( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT %d', array( 'wp_options', GitHubSelectionStore::OPTION, 1 ), 'results' );
					self::assertIsArray( $rows );
					self::assertCount( 1, $rows );
					return $store->compare_and_swap( $old, $next );
				},
				static function ( bool $swapped ) use ( &$committed ): void {
					$committed = $swapped;
				}
			);
			self::assertTrue( $result );
			self::assertTrue( $committed );
			self::assertFalse( $store->has_unclosed_transaction() );
			self::assertFalse( $store->has_uncertain_commit() );
			self::assertSame( 1, $option_deletes );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}

		$next2                         = $next;
		$next2['selection_generation'] = 2;
		$db->queries                   = array();
		$committed2                    = null;
		$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ): void {
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				throw new \RuntimeException( 'selection cache failed' );
			}
		};
		try {
			$store->selection_transaction(
				fn (): bool => $store->compare_and_swap( $next, $next2 ),
				static function ( bool $swapped ) use ( &$committed2 ): void {
					$committed2 = $swapped;
				}
			);
			self::fail( 'Selection cache failure after COMMIT must propagate.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'selection cache failed', $failure->getMessage() );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}
		self::assertTrue( $committed2 );
		$db->queries = array();
		self::assertSame( $next2, $store->read() );
		self::assertNotEmpty( array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );

		$uncertain_db                           = new SelectionDatabase();
		$uncertain_store                        = new GitHubSelectionStore( $uncertain_db, 'wp_options' );
		$uncertain_old                          = $uncertain_store->initialize_bundled( $this->declaration() );
		$uncertain_next                         = $uncertain_old;
		$uncertain_next['selection_generation'] = 1;
		$uncertain_db->throw_commit             = true;
		$uncertain_db->commit_applied           = true;
		$uncertain_committed                    = null;
		try {
			$uncertain_store->selection_transaction(
				fn (): bool => $uncertain_store->compare_and_swap( $uncertain_old, $uncertain_next ),
				static function ( bool $swapped ) use ( &$uncertain_committed ): void {
					$uncertain_committed = $swapped;
				}
			);
			self::fail( 'Thrown commit must remain uncertain.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'commit threw', $failure->getMessage() );
		}
		self::assertNull( $uncertain_committed );
		self::assertTrue( $uncertain_store->has_uncertain_commit() );
		self::assertFalse( $uncertain_store->has_unclosed_transaction() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_failed_rollback_clears_storage_verification_without_running_cache_callbacks(): void {
		$trigger_cases = array( 'operation_throws', 'fail_commit', 'throw_commit' );
		foreach ( $trigger_cases as $trigger ) {
			foreach ( array( 'false', 'throw', 'last_error' ) as $rollback_mode ) {
				$db    = new SelectionDatabase();
				$store = new GitHubSelectionStore( $db, 'wp_options' );
				$old   = $store->initialize_bundled( $this->declaration() );
				$next  = $old;
				++$next['selection_generation'];
				if ( 'fail_commit' === $trigger ) {
					$db->fail_commit = true;
				} elseif ( 'throw_commit' === $trigger ) {
					$db->throw_commit = true;
				}
				$db->fail_rollback                              = 'false' === $rollback_mode;
				$db->throw_rollback                             = 'throw' === $rollback_mode;
				$db->boundary_error                             = 'last_error' === $rollback_mode ? 'ROLLBACK' : null;
				$cache_deletes                                  = 0;
				$committed                                      = false;
				$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function () use ( &$cache_deletes ): void {
					++$cache_deletes;
				};
				try {
					$store->selection_transaction(
						static function () use ( $store, $old, $next, $trigger ): bool {
							$swapped = $store->compare_and_swap( $old, $next );
							if ( 'operation_throws' === $trigger ) {
								throw new \RuntimeException( 'operation failed' );
							}
							return $swapped;
						},
						static function () use ( &$committed ): void {
							$committed = true;
						}
					);
					self::fail( "$trigger/$rollback_mode must throw." );
				} catch ( \RuntimeException ) {
					self::assertFalse( $committed, "$trigger/$rollback_mode" );
				} finally {
					unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
				}
				self::assertTrue( $store->has_unclosed_transaction(), "$trigger/$rollback_mode" );
				self::assertTrue( $store->has_uncertain_commit(), "$trigger/$rollback_mode" );
				self::assertSame( 0, $cache_deletes, "$trigger/$rollback_mode must skip cache backend while transaction stays open." );
				if ( 'last_error' !== $rollback_mode ) {
					self::assertNotNull( $db->transaction_rows, "$trigger/$rollback_mode" );
				}
				$db->fail_commit    = false;
				$db->throw_commit   = false;
				$db->fail_rollback  = false;
				$db->throw_rollback = false;
				$db->boundary_error = null;
				$db->last_error     = '';
				$this->assert_paused( $store, $db );

				// storage_verified must clear immediately even when cache eviction is skipped.
				if ( 'last_error' !== $rollback_mode ) {
					self::assertTrue( $db->query( 'ROLLBACK' ) );
				}
				$db->queries = array();
				self::assertSame( $old, $store->read() );
				self::assertNotEmpty( array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );
			}
		}

		// Successful ROLLBACK closes the transaction and then invalidates selection caches once.
		$rolled_db    = new SelectionDatabase();
		$rolled_store = new GitHubSelectionStore( $rolled_db, 'wp_options' );
		$rolled_old   = $rolled_store->initialize_bundled( $this->declaration() );
		$rolled_next  = $rolled_old;
		++$rolled_next['selection_generation'];
		$rolled_option_deletes                          = 0;
		$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ) use ( $rolled_db, &$rolled_option_deletes ): void {
			self::assertNull( $rolled_db->transaction_rows, 'Rollback cache eviction must run only after ROLLBACK closes the transaction.' );
			if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
				++$rolled_option_deletes;
			}
		};
		try {
			$rolled_store->selection_transaction(
				static function () use ( $rolled_store, $rolled_old, $rolled_next ): void {
					$rolled_store->compare_and_swap( $rolled_old, $rolled_next );
					throw new \RuntimeException( 'rollback closes cleanly' );
				}
			);
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'rollback closes cleanly', $failure->getMessage() );
		} finally {
			unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
		}
		self::assertSame( $rolled_old, $rolled_store->read() );
		self::assertFalse( $rolled_store->has_unclosed_transaction() );
		self::assertSame( 1, $rolled_option_deletes );

		foreach ( array( 'false', 'throw' ) as $rollback_mode ) {
			$hold_db                                        = new SelectionDatabase();
			$hold_store                                     = new GitHubSelectionStore( $hold_db, 'wp_options' );
			$hold_initial                                   = $hold_store->initialize_bundled( $this->declaration() );
			$hold_db->fail_commit                           = true;
			$hold_db->fail_rollback                         = 'false' === $rollback_mode;
			$hold_db->throw_rollback                        = 'throw' === $rollback_mode;
			$hold_deletes                                   = 0;
			$hold_committed                                 = false;
			$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function () use ( &$hold_deletes ): void {
				++$hold_deletes;
			};
			try {
				$hold_store->without_native_mutation_hold(
					static fn (): string => 'token-unused',
					static function () use ( &$hold_committed ): void {
						$hold_committed = true;
					}
				);
				self::fail( "hold/$rollback_mode must throw." );
			} catch ( DatabaseLifecycleFailure ) {
				self::assertFalse( $hold_committed, "hold/$rollback_mode" );
			} finally {
				unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
			}
			self::assertTrue( $hold_store->has_unclosed_transaction(), "hold/$rollback_mode" );
			self::assertSame( 0, $hold_deletes, "hold/$rollback_mode must skip cache backend while transaction stays open." );
			self::assertNotNull( $hold_db->transaction_rows, "hold/$rollback_mode" );
			$hold_db->fail_commit    = false;
			$hold_db->fail_rollback  = false;
			$hold_db->throw_rollback = false;
			$this->assert_paused( $hold_store, $hold_db );
			self::assertTrue( $hold_db->query( 'ROLLBACK' ) );
			$hold_db->queries = array();
			self::assertSame( $hold_initial, $hold_store->read() );
			self::assertNotEmpty( array_filter( $hold_db->queries, static fn ( string $query ): bool => str_contains( $query, 'SHOW TABLE STATUS' ) ) );
		}
	}
}

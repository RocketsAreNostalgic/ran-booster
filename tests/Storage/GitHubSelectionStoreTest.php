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
				self::fail( $case );
			} catch ( \Throwable ) {
				self::assertFalse( $case_called, $case );
			}
			if ( 'operation_throws' !== $case ) {
				$case_db->fail_commit  = false;
				$case_db->throw_commit = false;
				$this->assert_paused( $case_store, $case_db );
			}
		}
	}
}

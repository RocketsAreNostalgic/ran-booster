<?php

declare(strict_types=1);

namespace RAN\Tests\Activity;

use RAN\Activity\ActivityRequest;
use RAN\Activity\ActivityStore;
use RAN\Activity\BoundActivityReporter;
use RAN\Activity\ProgressOnly;
use RAN\Activity\RecordedActivityOperation;
use RAN\AdoptionIdentity;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\Tests\RANBoosterTestCase;

require_once __DIR__ . '/ActivityWordPressFunctions.php';
require_once __DIR__ . '/ActivityConnection.php';

final class ActivityStoreTest extends RANBoosterTestCase {
	private ActivityConnection $connection;
	private ActivityStore $store;

	#[\PHPUnit\Framework\Attributes\Before]
	protected function prepare_store(): void {
		$this->connection                        = new ActivityConnection();
		$this->store                             = new ActivityStore( $this->connection );
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
	}

	/** @return array{subject_type:string,subject_identity:string,adoption_token:string,context:string,purpose:string,provenance:string} */
	public static function capture(): array {
		return array(
			'subject_type'     => 'plugin',
			'subject_identity' => 'example/example.php',
			'adoption_token'   => AdoptionIdentity::create( 'gh', '123' ),
			'context'          => 'source-and-access-fingerprint',
			'purpose'          => 'release.check',
			'provenance'       => 'core',
		);
	}

	public static function request(): ActivityRequest {
		return new ActivityRequest( 'release.check', new RepositoryReference( 'owner/repository', '123', false, null ), 'plugin', 'example/example.php' );
	}

	public function test_optional_unavailable_storage_does_not_capture_or_throw(): void {
		$GLOBALS['ran_booster_activity_options'] = array();
		self::assertNull( $this->store->begin( 'gh', self::request(), self::capture() ) );
		self::assertSame( array(), $this->connection->inserted );
		$operation = new RecordedActivityOperation( $this->store, null );
		$operation->progress( 'checking' );
		$operation->complete( 'succeeded', 'no_candidates' );
		self::assertSame( array(), $this->connection->queries );
	}

	public function test_unexpected_option_callback_exception_is_not_an_optional_failure(): void {
		$failure                                        = new \RuntimeException( 'Unexpected option callback failure.' );
		$GLOBALS['ran_booster_activity_option_failure'] = $failure;
		try {
			$this->store->begin( 'gh', self::request(), self::capture() );
			self::fail( 'The callback exception must propagate.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( $failure, $caught );
		} finally {
			unset( $GLOBALS['ran_booster_activity_option_failure'] );
		}
		self::assertSame( array(), $this->connection->inserted );
	}

	public function test_bound_capture_exception_propagates_without_returning_a_noop_operation(): void {
		$reporter = new BoundActivityReporter(
			$this->store,
			'gh',
			static function (): never {
				throw new \RuntimeException( 'Unexpected capture failure.' );
			}
		);
		$this->expectExceptionMessage( 'Unexpected capture failure.' );
		$reporter->begin( self::request() );
	}

	public function test_missing_connection_and_malformed_rows_remain_unavailable(): void {
		$store = new ActivityStore( new \stdClass() );
		self::assertNull( $store->begin( 'gh', self::request(), self::capture() ) );
		self::assertFalse( $store->install() );
		self::assertFalse( $store->progress( 1, 'checking' ) );
		self::assertFalse( $store->complete( 1, 'succeeded', 'checked' ) );
		self::assertFalse( $store->activity_history()['available'] );
		self::assertFalse( $store->prune( array() ) );
		$this->connection->read_results = array( array( array( 'unsafe' => array() ) ) );
		self::assertFalse( $this->store->activity_history()['available'] );
	}

	public function test_begin_captures_exact_lifetime_and_rejects_wrong_provider(): void {
		$capture = self::capture();
		self::assertSame( 1, $this->store->begin( 'gh', self::request(), $capture ) );
		self::assertSame( $capture['adoption_token'], $this->connection->inserted[0]['adoption_token'] );
		self::assertNull( $this->store->begin( 'bb', self::request(), $capture ) );
		self::assertCount( 1, $this->connection->inserted );
	}

	public function test_deployment_lifecycle_can_reuse_its_correlation_for_atomic_updates(): void {
		$correlation = str_repeat( 'a', 32 );
		self::assertSame( 1, $this->store->begin( 'gh', self::request(), self::capture(), $correlation ) );
		self::assertSame( $correlation, $this->connection->inserted[0]['correlation'] );
		self::assertTrue( $this->store->progress_by_correlation( $correlation, 'deployment.running', facts: array( 'deployment_requested_ref' => str_repeat( 'x', 255 ) ) ) );
		$query = end( $this->connection->queries );
		self::assertIsString( $query );
		self::assertStringContainsString( 'WHERE correlation = %s AND status = \'incomplete\'', $query );
		self::assertTrue( $this->store->complete_by_correlation( $correlation, 'succeeded', 'deployed', 'deployed' ) );
		$query = end( $this->connection->queries );
		self::assertIsString( $query );
		self::assertStringContainsString( 'WHERE correlation = %s AND status = \'incomplete\'', $query );
	}

	public function test_coupled_writes_reject_nontransactional_or_unknown_engines_before_mutation(): void {
		foreach ( array( 'MyISAM', 'MEMORY', null ) as $engine ) {
			$this->connection->engine = $engine;
			$correlation              = str_repeat( 'b', 32 );
			self::assertNull( $this->store->begin( 'gh', self::request(), self::capture(), $correlation ) );
			self::assertFalse( $this->store->progress_by_correlation( $correlation, 'deployment.running' ) );
			self::assertFalse( $this->store->complete_by_correlation( $correlation, 'succeeded', 'deployed' ) );
		}
		self::assertSame( array(), $this->connection->inserted );
		self::assertStringNotContainsString( 'UPDATE ', implode( "\n", $this->connection->queries ) );
	}

	public function test_coupled_admission_rejects_failed_table_inspection_before_insert(): void {
		$this->connection->read_results = array( false );
		self::assertNull( $this->store->begin( 'gh', self::request(), self::capture(), str_repeat( 'b', 32 ) ) );
		self::assertSame( array(), $this->connection->inserted );
	}

	public function test_install_and_coupled_writes_reject_missing_or_weakened_indexes(): void {
		$indexes = $this->connection->indexes;
		self::assertNotNull( $indexes );
		self::assertArrayHasKey( 1, $indexes );
		$invalid    = array( null, array(), array_slice( $indexes, 1 ) );
		$changed    = $indexes;
		$changed[1] = array(
			'index_name' => 'correlation',
			'non_unique' => 1,
			'columns'    => 'correlation',
			'prefixes'   => 0,
		);
		$invalid[]  = $changed;
		$changed    = $indexes;
		$changed[1] = array(
			'index_name' => 'correlation',
			'non_unique' => 0,
			'columns'    => 'correlation,id',
			'prefixes'   => 0,
		);
		$invalid[]  = $changed;
		$changed    = $indexes;
		$changed[1] = array(
			'index_name' => 'correlation',
			'non_unique' => 0,
			'columns'    => 'correlation',
			'prefixes'   => 1,
		);
		$invalid[]  = $changed;
		foreach ( $invalid as $index_rows ) {
			$this->connection->indexes = $index_rows;
			self::assertFalse( $this->store->install() );
			self::assertNull( $this->store->begin( 'gh', self::request(), self::capture(), str_repeat( 'd', 32 ) ) );
			self::assertFalse( $this->store->progress_by_correlation( str_repeat( 'd', 32 ), 'deployment.running' ) );
			self::assertFalse( $this->store->complete_by_correlation( str_repeat( 'd', 32 ), 'succeeded', 'deployed' ) );
		}
		self::assertSame( array(), $this->connection->inserted );
		self::assertStringNotContainsString( 'UPDATE ', implode( "\n", $this->connection->queries ) );
	}

	public function test_provider_cannot_allocate_protected_purpose_and_progress_has_no_complete(): void {
		$reporter  = new BoundActivityReporter( $this->store, 'gh', static fn (): array => self::capture() );
		$operation = $reporter->begin( self::request() );
		self::assertNull( $this->connection->inserted[0]['purpose'] );
		self::assertSame( 'provider', $this->connection->inserted[0]['provenance'] );
		$methods = ( new \ReflectionObject( new ProgressOnly( $operation ) ) )->getMethods( \ReflectionMethod::IS_PUBLIC );
		self::assertNotContains( 'complete', array_map( static fn ( \ReflectionMethod $method ): string => $method->getName(), $methods ) );
	}

	public function test_secret_text_and_unknown_facts_are_rejected_before_sql(): void {
		self::assertFalse( $this->store->progress( 1, 'checking', 'Authorization: Bearer abcdefghijklmnop' ) );
		self::assertFalse( $this->store->complete( 1, 'succeeded', 'checked', '', '', array( 'response' => 'remote body' ) ) );
		self::assertSame( array(), $this->connection->queries );
	}

	public function test_disabled_and_active_handles_discard_invalid_scalar_payloads_without_sql(): void {
		foreach ( array( null, 1 ) as $id ) {
			$operation = new RecordedActivityOperation( $this->store, $id );
			$operation->progress( 'invalid stage', 'Authorization: Bearer abcdefghijklmnop' );
			$operation->complete( 'unknown', 'checked' );
			$operation->complete( 'succeeded', 'checked', '<script>remote</script>' );
			$operation->complete( 'succeeded', 'checked', '', 'Authorization: secret' );
		}
		self::assertSame( array(), $this->connection->prepared );
		self::assertSame( array(), $this->connection->queries );
		self::assertFalse( $this->store->complete( null, 'succeeded', 'checked', '', '', array( 'response' => 'raw response' ) ) );
	}

	public function test_phase_two_facts_reject_invalid_dates_counts_and_state_before_sql(): void {
		foreach ( array(
			array(
				'expiry_state' => 'known',
				'expires_at'   => '2026-02-30T00:00:00Z',
			),
			array(
				'expiry_state' => 'unknown',
				'expires_at'   => '2026-12-01T00:00:00Z',
			),
			array(
				'expiry_state' => 'known',
				'expires_at'   => null,
			),
			array( 'diagnostic_passed' => 9 ),
			array( 'diagnostic_failed' => '1' ),
			array( 'partial_reason' => 'Authorization: secret' ),
			array( 'webhook_persistence' => 'rolled_back' ),
		) as $facts ) {
			self::assertFalse( $this->store->complete( 1, 'succeeded', 'checked', facts: $facts ) );
		}
		self::assertSame( array(), $this->connection->queries );
		self::assertTrue(
			$this->store->complete(
				1,
				'succeeded',
				'checked',
				facts: array(
					'expiry_state' => 'known',
					'expires_at'   => '2028-02-29T00:00:00Z',
				)
			)
		);
	}

	public function test_null_byte_expiry_is_rejected_before_sql(): void {
		self::assertFalse(
			$this->store->complete(
				1,
				'succeeded',
				'credential.valid',
				facts: array(
					'expiry_state' => 'known',
					'expires_at'   => "2027-01-01T00:00:00Z\0",
				)
			)
		);
		self::assertSame( array(), $this->connection->queries );
	}

	public function test_terminal_compare_and_set_duplicate_and_conflicting_completion(): void {
		self::assertTrue( $this->store->complete( 1, 'succeeded', 'no_candidates' ) );
		self::assertStringContainsString( "AND status = 'incomplete'", $this->connection->queries[0] );
		$this->connection->query_results = array( 0, 0, 0 );
		$row                             = (object) array(
			'status'      => 'succeeded',
			'reason'      => 'no_candidates',
			'message'     => '',
			'remediation' => '',
			'facts'       => '[]',
			'effect'      => 'none',
		);
		$this->connection->read_results  = array( array( $row ), array( $row ) );
		self::assertTrue( $this->store->complete( 1, 'succeeded', 'no_candidates' ) );
		self::assertFalse( $this->store->complete( 1, 'failed', 'remote_failed' ) );
		self::assertFalse( $this->store->progress( 1, 'late_stage' ) );
	}

	public function test_summary_compares_full_identity_and_keeps_latest_and_observation_separate(): void {
		$this->connection->read_results = array(
			array(
				(object) array(
					'id'     => '3',
					'status' => 'failed',
				),
			),
			array(
				(object) array(
					'id'     => '2',
					'effect' => 'established',
				),
			),
		);
		$capture                        = self::capture();
		$result                         = $this->store->summary( 'plugin', 'example/example.php', $capture['adoption_token'], 'access-context', 'release.check' );
		self::assertTrue( $result['available'] );
		self::assertSame( '3', $result['latest']['id'] ?? null );
		self::assertSame( '2', $result['observation']['id'] ?? null );
		self::assertContains( $capture['adoption_token'], $this->connection->prepared[0]['args'] );
		self::assertContains( 'access-context', $this->connection->prepared[0]['args'] );
		self::assertStringContainsString( 'adoption_token = %s AND context = %s', $this->connection->queries[0] );
	}

	public function test_failed_source_and_invalid_boundary_return_unavailable(): void {
		$this->connection->read_results = array( false );
		self::assertSame(
			array(
				'available' => false,
				'records'   => array(),
			),
			$this->store->activity_history()
		);
		self::assertFalse(
			$this->store->activity_history(
				array(),
				array(
					'time'   => 'invalid',
					'source' => 'activity',
					'id'     => 1,
				)
			)['available']
		);
	}

	public function test_filter_choices_keep_binary_provider_and_package_dimensions_without_payload_reads(): void {
		$this->connection->read_results = array(
			array(
				(object) array(
					'provider'        => 'Gh',
					'has_non_package' => '1',
				),
				(object) array(
					'provider'        => 'gh',
					'has_non_package' => '0',
				),
				(object) array(
					'provider'        => '',
					'has_non_package' => '1',
				),
			),
			array(
				(object) array(
					'provider'         => 'Gh',
					'subject_type'     => 'plugin',
					'subject_identity' => 'Case/plugin.php',
				),
				(object) array(
					'provider'         => 'gh',
					'subject_type'     => 'theme',
					'subject_identity' => 'case',
				),
			),
		);
		self::assertSame(
			array(
				'available' => true,
				'providers' => array(
					array(
						'provider'        => 'Gh',
						'has_non_package' => true,
					),
					array(
						'provider'        => 'gh',
						'has_non_package' => false,
					),
				),
				'packages'  => array(
					array(
						'provider'         => 'Gh',
						'subject_type'     => 'plugin',
						'subject_identity' => 'Case/plugin.php',
					),
					array(
						'provider'         => 'gh',
						'subject_type'     => 'theme',
						'subject_identity' => 'case',
					),
				),
			),
			$this->store->activity_filter_choices()
		);
		self::assertCount( 2, $this->connection->queries );
		self::assertStringContainsString( 'SELECT BINARY provider AS provider, MAX(CASE WHEN BINARY subject_type IN', $this->connection->queries[0] );
		self::assertStringContainsString( 'GROUP BY BINARY provider', $this->connection->queries[0] );
		self::assertStringContainsString( 'SELECT DISTINCT BINARY provider AS provider, BINARY subject_type AS subject_type, BINARY subject_identity AS subject_identity', $this->connection->queries[1] );
		self::assertStringContainsString( "BINARY subject_type IN ('plugin','theme')", $this->connection->queries[1] );
		self::assertStringNotContainsString( 'SELECT *', implode( "\n", $this->connection->queries ) );
		self::assertStringNotContainsString( 'SELECT facts', implode( "\n", $this->connection->queries ) );
		self::assertStringContainsString( 'LIMIT 2000', $this->connection->queries[0] );
		self::assertStringContainsString( "JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_state'))", $this->connection->queries[0] );
	}

	public function test_filter_choices_make_each_failed_read_unavailable(): void {
		$this->connection->read_results = array( array(), false );
		self::assertSame(
			array(
				'available' => false,
				'providers' => array(),
				'packages'  => array(),
			),
			$this->store->activity_filter_choices()
		);
	}

	public function test_prune_is_bounded_and_rechecks_protection_and_terminal_state(): void {
		$capture                        = self::capture();
		$this->connection->read_results = array(
			array(
				(object) array(
					'id'     => '1',
					'status' => 'incomplete',
				),
			),
		);
		self::assertSame( 1, $this->store->prune( array( $capture ) ) );
		self::assertStringContainsString( 'id NOT IN (SELECT retained_id', $this->connection->queries[0] );
		self::assertCount( 2, $this->connection->queries );
		self::assertStringContainsString( 'LIMIT 100', $this->connection->queries[0] );
		self::assertStringContainsString( 'LIMIT 2000', $this->connection->queries[0] );
		self::assertStringContainsString( 'AND status = %s)) AND id NOT IN', $this->connection->queries[1] );
		self::assertStringContainsString( 'protected_activity', $this->connection->queries[1] );
		self::assertContains( $capture['adoption_token'], $this->connection->prepared[0]['args'] );
	}

	public function test_prune_reports_actual_deletions_when_a_candidate_changes(): void {
		$this->connection->read_results  = array(
			array(
				(object) array(
					'id'     => '1',
					'status' => 'incomplete',
				),
			),
		);
		$this->connection->query_results = array( 0 );
		self::assertSame( 0, $this->store->prune( array() ) );
	}

	public function test_prune_batches_candidates_without_dropping_snapshot_or_live_protection(): void {
		$this->connection->read_results  = array(
			array(
				(object) array(
					'id'     => '1',
					'status' => 'incomplete',
				),
				(object) array(
					'id'     => '2',
					'status' => 'failed',
				),
			),
		);
		$this->connection->query_results = array( 1 );
		self::assertSame( 1, $this->store->prune( array() ) );
		self::assertCount( 2, $this->connection->queries );
		self::assertStringContainsString( 'WHERE ((id = %d AND status = %s) OR (id = %d AND status = %s)) AND id NOT IN', $this->connection->queries[1] );
		self::assertStringContainsString( 'protected_activity', $this->connection->queries[1] );
		self::assertStringContainsString( "'needs_attention'", $this->connection->queries[1] );
	}

	public function test_prune_requires_its_own_schema_readiness(): void {
		$GLOBALS['ran_booster_activity_options'] = array();
		self::assertFalse( $this->store->prune( array( self::capture() ) ) );
		self::assertSame( array(), $this->connection->queries );
	}

	public function test_prune_read_failure_leaves_records_untouched(): void {
		$this->connection->read_results = array( false );
		self::assertFalse( $this->store->prune( array( self::capture() ) ) );
		self::assertCount( 1, $this->connection->queries );
		self::assertStringStartsWith( 'SELECT', $this->connection->queries[0] );
	}

	public function test_prune_preserves_unresolved_deployment_rows(): void {
		self::assertSame( 0, $this->store->prune( array() ) );
		self::assertStringContainsString( "JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_state'))", $this->connection->queries[0] );
		self::assertStringContainsString( "'needs_attention'", $this->connection->queries[0] );
		self::assertStringContainsString( "JSON_EXTRACT(facts, '$.deployment_attempt_id') IS NOT NULL", $this->connection->queries[0] );
		self::assertStringContainsString( "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_resolved_at')), 'null') = 'null'", $this->connection->queries[0] );
		self::assertStringContainsString( 'AND NOT (', $this->connection->queries[0] );
	}

	public function test_install_refuses_future_schema_and_missing_columns(): void {
		$GLOBALS['ran_booster_activity_options'][ ActivityStore::VERSION_OPTION ] = '2';
		self::assertFalse( $this->store->install() );
		self::assertSame( array(), $this->connection->queries );
		$GLOBALS['ran_booster_activity_options'] = array();
		$this->connection->read_results          = array( false );
		self::assertFalse( $this->store->install() );
		self::assertFalse( $this->store->available() );
	}

	public function test_internal_history_filters_preserve_exact_context_before_limit(): void {
		$capture = self::capture();
		$filters = array(
			'adoption_token' => $capture['adoption_token'],
			'context'        => str_repeat( 'a', 2048 ),
			'purpose'        => 'workflow.assessment',
			'status'         => 'failed',
		);
		self::assertTrue( $this->store->activity_history( $filters, limit: 12 )['available'] );
		foreach ( $filters as $key => $value ) {
			self::assertStringContainsString( 'BINARY ' . $key . ' = %s', $this->connection->queries[0] );
		}
		self::assertStringContainsString( "JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_state'))", $this->connection->queries[0] );
		self::assertStringContainsString( "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_resolved_at')), 'null') = 'null'", $this->connection->queries[0] );
		self::assertStringContainsString( 'OR (', $this->connection->queries[0] );
		self::assertStringEndsWith( 'LIMIT 12', $this->connection->queries[0] );
		self::assertTrue( $this->store->activity_history( array( 'non_package' => '1' ) )['available'] );
		self::assertStringContainsString( "NOT (BINARY subject_type IN ('plugin','theme') AND BINARY subject_identity <> '')", $this->connection->queries[1] );
		self::assertFalse( $this->store->activity_history( array( 'non_package' => '0' ) )['available'] );
		self::assertFalse( $this->store->activity_history( array( 'invented' => 'value' ) )['available'] );
		self::assertFalse( $this->store->activity_history( array( 'context' => str_repeat( 'a', 2049 ) ) )['available'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_shared_retention_settings_apply_to_both_stores_and_activity_queries(): void {
		define( 'RAN_BOOSTER_HISTORY_ROWS', 350 );
		define( 'RAN_BOOSTER_HISTORY_DAYS', 7 );
		$policy = ActivityStore::retention_policy();
		self::assertSame( 350, $policy['maximum_rows'] );
		self::assertSame( 7, $policy['maximum_days'] );
		$journal = new \RAN\Deployment\DeploymentAttemptRepository( $this->connection, 'wp_attempts' );
		self::assertSame( 350, $journal->retention_configuration_status()['maximum_rows'] );
		self::assertTrue( $this->store->activity_history()['available'] );
		self::assertSame( 0, $this->store->prune( array() ) );
		foreach ( $this->connection->queries as $query ) {
			self::assertStringContainsString( 'LIMIT 350', $query );
		}
		self::assertSame( gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ), $this->connection->prepared[0]['args'][0] );
	}

	public function test_invalid_shared_retention_settings_use_bounded_defaults(): void {
		foreach ( array( 199, 100001, '250', 250.0, true ) as $invalid ) {
			$policy = ActivityStore::retention_policy( $invalid );
			self::assertFalse( $policy['valid'] );
			self::assertSame( 2000, $policy['maximum_rows'] );
		}
		foreach ( array( 0, 366, '7', 7.0, true ) as $invalid ) {
			$policy = ActivityStore::retention_policy( days: $invalid );
			self::assertFalse( $policy['valid'] );
			self::assertSame( 30, $policy['maximum_days'] );
		}
	}

	public function test_mixed_source_timestamp_tie_includes_activity_after_deployment(): void {
		$boundary = array(
			'time'   => '2026-10-12 17:00:00',
			'source' => 'deployment',
			'id'     => 4,
		);
		self::assertTrue( $this->store->activity_history( array(), $boundary )['available'] );
		self::assertStringContainsString( 'sort_at <= %s', $this->connection->queries[0] );
		$boundary['source'] = 'activity';
		self::assertTrue( $this->store->activity_history( array(), $boundary )['available'] );
		self::assertStringContainsString( 'id < %d', $this->connection->queries[1] );
	}
}

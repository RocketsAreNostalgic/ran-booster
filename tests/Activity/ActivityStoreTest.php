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

	public function test_begin_captures_exact_lifetime_and_rejects_wrong_provider(): void {
		$capture = self::capture();
		self::assertSame( 1, $this->store->begin( 'gh', self::request(), $capture ) );
		self::assertSame( $capture['adoption_token'], $this->connection->inserted[0]['adoption_token'] );
		self::assertNull( $this->store->begin( 'bb', self::request(), $capture ) );
		self::assertCount( 1, $this->connection->inserted );
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

	public function test_failed_source_and_invalid_cursor_do_not_advance_history(): void {
		$this->connection->read_results = array( false );
		self::assertSame(
			array(
				'available'   => false,
				'records'     => array(),
				'next_cursor' => null,
			),
			$this->store->history()
		);
		self::assertFalse( $this->store->history( array(), 'invented-cursor' )['available'] );
	}
	public function test_prune_is_bounded_and_rechecks_protection_and_terminal_state(): void {
		$capture                        = self::capture();
		$this->connection->read_results = array(
			array( (object) array( 'id' => '40' ) ),
			array( (object) array( 'id' => '35' ) ),
			array(
				(object) array(
					'id'     => '1',
					'status' => 'incomplete',
				),
			),
		);
		self::assertSame( 1, $this->store->prune( array( $capture ) ) );
		self::assertStringContainsString( 'id NOT IN (0,40,35)', $this->connection->queries[2] );
		self::assertStringContainsString( 'LIMIT 100', $this->connection->queries[2] );
		self::assertStringContainsString( 'LIMIT 2000', $this->connection->queries[2] );
		self::assertStringContainsString( 'AND status = %s AND id NOT IN', $this->connection->queries[3] );
		self::assertStringContainsString( 'protected_activity', $this->connection->queries[3] );
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

	public function test_prune_read_failure_leaves_records_untouched(): void {
		$this->connection->read_results = array( false );
		self::assertFalse( $this->store->prune( array( self::capture() ) ) );
		self::assertCount( 1, $this->connection->queries );
		self::assertStringStartsWith( 'SELECT', $this->connection->queries[0] );
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

	public function test_history_cursor_binds_filters_and_page_boundary(): void {
		$this->connection->read_results = array(
			array(
				(object) array(
					'id'      => '9',
					'sort_at' => '2026-10-12 17:00:00',
				),
				(object) array(
					'id'      => '8',
					'sort_at' => '2026-10-12 17:00:00',
				),
			),
		);
		$page                           = $this->store->history( array( 'provider' => 'gh' ), null, 1 );
		self::assertTrue( $page['available'] );
		self::assertCount( 1, $page['records'] );
		self::assertNotNull( $page['next_cursor'] );
		self::assertFalse( $this->store->history( array( 'provider' => 'bb' ), $page['next_cursor'] )['available'] );
		self::assertTrue( $this->store->history( array( 'provider' => 'gh' ), $page['next_cursor'] )['available'] );
		self::assertStringContainsString( 'id < %d', $this->connection->queries[1] );
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

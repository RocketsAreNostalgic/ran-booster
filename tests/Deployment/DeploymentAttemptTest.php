<?php

declare(strict_types=1);

namespace Tests\Deployment;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentStorageFailure;

final class DeploymentAttemptTest extends TestCase {

	public function test_hydrates_the_exact_safe_projection_without_digest_or_request_json(): void {
		$attempt = DeploymentAttempt::from_database( record: $this->row() );

		self::assertSame( DeploymentState::QUEUED, $attempt->get_state() );
		self::assertSame( 'group/package', $attempt->get_request()->repository );
		self::assertArrayNotHasKey( 'delivery_digest', $attempt->safe_data() );
		self::assertArrayNotHasKey( 'request_json', $attempt->safe_data() );
		self::assertNull( $attempt->safe_data()['resolved_at'] );
		self::assertNull( $attempt->safe_data()['resolved_by'] );
	}

	public function test_rejects_terminal_state_without_matching_fixed_outcome(): void {
		$row                 = $this->row();
		$row['state']        = 'succeeded';
		$row['finished_at']  = '2026-07-19 00:01:00';
		$row['outcome_code'] = 'upgrader_failed';

		$this->expectException( DeploymentStorageFailure::class );
		DeploymentAttempt::from_database( $row );
	}

	public function test_resolved_needs_attention_remains_historical_without_blocking_admission(): void {
		$row                        = $this->row();
		$row['state']               = 'needs_attention';
		$row['mutation_started_at'] = '2026-07-19 00:00:30';
		$row['outcome_code']        = 'interrupted';
		$row['finished_at']         = '2026-07-19 00:01:00';
		$row['resolved_at']         = '2026-07-19 00:02:00';
		$row['resolved_by']         = 7;

		$attempt = DeploymentAttempt::from_database( $row );

		self::assertSame( '2026-07-19 00:02:00', $attempt->safe_data()['resolved_at'] );
		self::assertSame( 7, $attempt->safe_data()['resolved_by'] );
		self::assertFalse( $attempt->requires_operator_resolution() );
	}

	public function test_rejects_incomplete_operator_resolution_metadata(): void {
		$row                 = $this->row();
		$row['state']        = 'needs_attention';
		$row['outcome_code'] = 'interrupted';
		$row['finished_at']  = '2026-07-19 00:01:00';
		$row['resolved_at']  = '2026-07-19 00:02:00';

		$this->expectException( DeploymentStorageFailure::class );
		DeploymentAttempt::from_database( $row );
	}

	/** @return array<string, mixed> */
	private function row(): array {
		$request = new DeploymentRequest( 'group/package', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1 );

		return array(
			'id'                      => 1,
			'correlation_id'          => str_repeat( 'a', 32 ),
			'source'                  => 'manual',
			'operation'               => 'install',
			'package_type'            => 'plugin',
			'package_slug'            => 'example',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'provider'                => 'gh',
			'provider_repository_id'  => 'R_123',
			'requested_ref'           => 'main',
			'resolved_ref'            => null,
			'delivery_id'             => null,
			'delivery_digest'         => null,
			'state'                   => 'queued',
			'mutation_started_at'     => null,
			'outcome_code'            => null,
			'request_json'            => $request->to_json(),
			'created_at'              => '2026-07-19 00:00:00',
			'finished_at'             => null,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
	}
}

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

	public function testHydratesTheExactSafeProjectionWithoutDigestOrRequestJson(): void {
		$attempt = DeploymentAttempt::fromDatabase( $this->row() );

		self::assertSame( DeploymentState::QUEUED, $attempt->getState() );
		self::assertSame( 'group/package', $attempt->getRequest()->repository );
		self::assertArrayNotHasKey( 'delivery_digest', $attempt->safeData() );
		self::assertArrayNotHasKey( 'request_json', $attempt->safeData() );
		self::assertNull( $attempt->safeData()['resolved_at'] );
		self::assertNull( $attempt->safeData()['resolved_by'] );
	}

	public function testRejectsTerminalStateWithoutMatchingFixedOutcome(): void {
		$row                 = $this->row();
		$row['state']        = 'succeeded';
		$row['finished_at']  = '2026-07-19 00:01:00';
		$row['outcome_code'] = 'upgrader_failed';

		$this->expectException( DeploymentStorageFailure::class );
		DeploymentAttempt::fromDatabase( $row );
	}

	public function testResolvedNeedsAttentionRemainsHistoricalWithoutBlockingAdmission(): void {
		$row                        = $this->row();
		$row['state']               = 'needs_attention';
		$row['mutation_started_at'] = '2026-07-19 00:00:30';
		$row['outcome_code']        = 'interrupted';
		$row['finished_at']         = '2026-07-19 00:01:00';
		$row['resolved_at']         = '2026-07-19 00:02:00';
		$row['resolved_by']         = 7;

		$attempt = DeploymentAttempt::fromDatabase( $row );

		self::assertSame( '2026-07-19 00:02:00', $attempt->safeData()['resolved_at'] );
		self::assertSame( 7, $attempt->safeData()['resolved_by'] );
		self::assertFalse( $attempt->requiresOperatorResolution() );
	}

	public function testRejectsIncompleteOperatorResolutionMetadata(): void {
		$row                 = $this->row();
		$row['state']        = 'needs_attention';
		$row['outcome_code'] = 'interrupted';
		$row['finished_at']  = '2026-07-19 00:01:00';
		$row['resolved_at']  = '2026-07-19 00:02:00';

		$this->expectException( DeploymentStorageFailure::class );
		DeploymentAttempt::fromDatabase( $row );
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
			'request_json'            => $request->toJson(),
			'created_at'              => '2026-07-19 00:00:00',
			'finished_at'             => null,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
	}
}

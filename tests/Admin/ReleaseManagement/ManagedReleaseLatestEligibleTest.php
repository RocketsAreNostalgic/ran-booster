<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once dirname( __DIR__, 3 ) . '/vendor/ran/wp-release-updater/src/Contract/ReleaseVersion.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowserOperations;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

final class ManagedReleaseLatestEligibleTest extends TestCase {
	public function test_branch_marker_uses_canonical_order_and_exactly_one_asset(): void {
		$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$tracking->candidate_list = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( 'missing', 'v5.0.0', '5.0.0', false, '2026-01-01T00:00:00Z', array() ),
				new RepositoryReleaseCandidate( 'lower', 'v2.0.0', '2.0.0', false, '2026-01-01T00:00:00Z', array( 'one.zip' ) ),
				new RepositoryReleaseCandidate( 'winner', 'v3.0.0', '3.0.0', false, '2026-01-01T00:00:00Z', array( 'one.zip' ) ),
				new RepositoryReleaseCandidate( 'ambiguous', 'v4.0.0', '4.0.0', false, '2026-01-01T00:00:00Z', array( 'one.zip', 'two.zip' ) ),
			)
		);
		$result                   = ( new ManagedReleaseBrowserOperations( $tracking, $tracking ) )->list_candidates( 'plugin', 'example/example.php', 3, 'stable', 'nonce', true );
		self::assertTrue( $result['successful'] );
		self::assertSame( array( false, false, true, false ), array_column( $result['data']['candidates'] ?? array(), 'latest_eligible' ) );
	}
}

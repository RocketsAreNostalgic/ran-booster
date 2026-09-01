<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;

final class SetupRecordStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']        = array();
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_update_result'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_acquired_callback'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double exercises connection-local advisory-lock ownership.
		$GLOBALS['wpdb'] = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
	}
	protected function tearDown(): void {
		$GLOBALS['wpdb']->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
	}
	public function testSchemaTwoIsExactBoundedAndNonAutoloaded(): void {
		$store  = new SetupRecordStore();
		$record = $this->record();
		self::assertTrue( $store->save( $record ) );
		self::assertSame( $record, $store->find( '123456789' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact ordered scalar-array bytes are the compatibility subject under test.
		self::assertSame( hash( 'sha256', serialize( $record ) ), hash( 'sha256', serialize( $store->find( '123456789' ) ) ) );
		self::assertFalse( $GLOBALS['ran_booster_release_deployments_test_option_updates'][0][2] );
		self::assertFalse( $store->save( $record + array( 'github_token' => 'secret' ) ) );
		self::assertFalse( $store->save( $record + array( 'booster_credential_id' => 'credential_1' ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'consumer_api' => 1 ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'setup_branch' => 'ran-booster/release-setup-v1-old' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_name' => 'attacker/templates' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_id' => '1' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_name' => 'other.zip' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_size' => 2097153 ) ) ) );
	}
	public function testSourceRevisionRefreshIsMonotonicAndBoundToTheExactPackage(): void {
		$store  = new SetupRecordStore();
		$record = $this->record();
		self::assertTrue( $store->save( $record ) );

		$refreshed = $store->refreshSourceRevision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 );
		self::assertNotNull( $refreshed );
		self::assertSame( 4, $refreshed['source_revision'] );
		self::assertNull( $store->refreshSourceRevision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertNull( $store->refreshSourceRevision( '123456789', 'theme', 'example-plugin/example-plugin.php', 5 ) );
		self::assertNull( $store->refreshSourceRevision( '123456789', 'plugin', 'other/example.php', 5 ) );
		self::assertSame( 4, $store->find( '123456789' )['source_revision'] );
	}
	public function testExistingUnknownRowsOccupyTheirKeyWithoutByteChanges(): void {
		foreach ( array(
			'legacy'    => array(
				'repo_id'        => '123456789',
				'schema_version' => 1,
			),
			'future'    => array(
				'schema_version' => 3,
				'opaque'         => "future\0bytes",
			),
			'non_array' => 'opaque-row',
			'null_row'  => null,
		) as $name => $existing ) {
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] = array( '123456789' => $existing );
			$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			$before = serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] );
			$store  = new SetupRecordStore();

			self::assertTrue( $store->occupied( '123456789' ), $name );
			self::assertFalse( $store->save( $this->record() ), $name );
			self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'], $name );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ), $name );
		}
	}
	public function testClaimIsAtomicAndReleaseRequiresTheExactOwner(): void {
		$store = new SetupRecordStore();
		$claim = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse( $store->releaseClaim( '123456789', $claim . 'tampered' ) );
		self::assertTrue( $store->releaseClaim( '123456789', $claim ) );
		self::assertNotNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
	}
	public function testFailedClaimReleaseKeepsTheCurrentConnectionClaimForFailureHistory(): void {
		$store      = new SetupRecordStore();
		$connection = $GLOBALS['wpdb'];
		$claim      = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		self::assertFalse( $store->releaseClaim( '123456789', $claim ) );
		$failure = array(
			'operation'             => 'setup',
			'outcome_code'          => 'workflow_local_persistence_unavailable',
			'failure_stage'         => 'local_persistence',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'diagnostic_code'       => 'local_persistence_unavailable',
			'diagnostic_available'  => true,
			'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'recorded_at'           => '2026-09-01T12:34:56Z',
		);

		self::assertTrue( $store->recordFailure( $failure ) );
		self::assertSame( array( $failure ), $store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertTrue( $connection->isLockHeld() );
		self::assertSame( 1, $connection->lockAcquisitions );
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		self::assertTrue( $store->releaseClaim( '123456789', $claim ) );
	}
	public function testConnectionReplacementDropsAStaleClaimBeforeFailureHistorySerialization(): void {
		$store      = new SetupRecordStore();
		$connection = $GLOBALS['wpdb'];
		$claim      = $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$GLOBALS['ran_booster_release_deployments_test_lock_release_result'] = false;
		self::assertFalse( $store->releaseClaim( '123456789', $claim ) );
		$connection->disconnect();
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_release_result'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double simulates a new connection after the failed release.
		$GLOBALS['wpdb'] = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		$failure         = array(
			'operation'             => 'setup',
			'outcome_code'          => 'workflow_local_persistence_unavailable',
			'failure_stage'         => 'local_persistence',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'diagnostic_code'       => 'local_persistence_unavailable',
			'diagnostic_available'  => true,
			'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'recorded_at'           => '2026-09-01T12:34:56Z',
		);

		self::assertTrue( $store->recordFailure( $failure ) );
		self::assertSame( 1, $GLOBALS['wpdb']->lockAcquisitions );
		self::assertSame( array( $failure ), $store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
	}
	public function testOneGlobalClaimSerializesDistinctRepositoryRecords(): void {
		$first           = new SetupRecordStore();
		$second          = new SetupRecordStore();
		$firstConnection = $GLOBALS['wpdb'];
		$claim           = $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$secondConnection = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $secondConnection;
		self::assertNull( $second->claim( '987654321', 'theme', 'example-theme', 2 ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection after the cross-connection assertion.
		$GLOBALS['wpdb'] = $firstConnection;
		self::assertTrue( $first->save( $this->record() ) );
		self::assertTrue( $first->releaseClaim( '123456789', $claim ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $secondConnection;
		$otherClaim      = $second->claim( '987654321', 'theme', 'example-theme', 2 );
		self::assertNotNull( $otherClaim );
		self::assertTrue(
			$second->save(
				array_replace(
					$this->record(),
					array(
						'repo_id'            => '987654321',
						'repository'         => 'owner/example-theme',
						'package_type'       => 'theme',
						'package_identifier' => 'example-theme',
						'source_revision'    => 2,
					)
				)
			)
		);
		self::assertTrue( $second->releaseClaim( '987654321', $otherClaim ) );
		self::assertNotNull( $second->find( '123456789' ) );
		self::assertNotNull( $second->find( '987654321' ) );
		self::assertCount( 2, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] );
	}
	public function testConnectionCloseRecoversAnAbandonedClaimWithoutPersistentState(): void {
		$first = new SetupRecordStore();
		self::assertNotNull( $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertTrue( $GLOBALS['wpdb']->isLockHeld() );
		$GLOBALS['wpdb']->disconnect();

		$second = new SetupRecordStore();
		$claim  = $second->claim( '987654321', 'theme', 'example-theme', 2 );
		self::assertNotNull( $claim );
		self::assertTrue( $second->releaseClaim( '987654321', $claim ) );
		self::assertSame(
			array(),
			array_filter(
				array_keys( $GLOBALS['ran_booster_release_deployments_test_options'] ),
				static fn ( string $key ): bool => str_contains( $key, 'setup_claim' )
			)
		);
	}
	public function testSourceRevisionRefreshUsesTheSameCrossConnectionLock(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertTrue(
			$store->save(
				array_replace(
					$this->record(),
					array(
						'repo_id'            => '987654321',
						'repository'         => 'owner/example-theme',
						'package_type'       => 'theme',
						'package_identifier' => 'example-theme',
						'source_revision'    => 2,
					)
				)
			)
		);

		$firstConnection = $GLOBALS['wpdb'];
		$claim           = $store->claim( '555555555', 'plugin', 'lock-holder/lock-holder.php', 1 );
		self::assertNotNull( $claim );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		$other           = new SetupRecordStore();
		self::assertNull( $other->refreshSourceRevision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		self::assertSame( 3, $other->find( '123456789' )['source_revision'] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection to release its lock.
		$GLOBALS['wpdb'] = $firstConnection;
		self::assertTrue( $store->releaseClaim( '555555555', $claim ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		$refreshed       = $other->refreshSourceRevision( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 );
		self::assertSame( 4, $refreshed['source_revision'] );
		self::assertNotNull( $other->find( '987654321' ) );
		self::assertCount( 2, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] );
	}
	public function testExistingRecordClaimRequiresItsExactPackageAndRevision(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertNull( $store->claim( '987654321', 'plugin', 'example-plugin/example-plugin.php', 3, true ) );
		self::assertNull( $store->claim( '123456789', 'theme', 'example-plugin/example-plugin.php', 3, true ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'other/other.php', 3, true ) );
		self::assertNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4, true ) );
		self::assertNotNull( $store->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3, true ) );
	}
	public function testSaveNeverTransfersARepositoryRecordToAnotherPackage(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->save( $this->record() ) );
		self::assertFalse( $store->save( array_replace( $this->record(), array( 'package_identifier' => 'other/other.php' ) ) ) );
		self::assertSame( 'example-plugin/example-plugin.php', $store->find( '123456789' )['package_identifier'] );
	}
	public function testSchemaOneIsDisplayOnlyAndNeverCurrentAuthority(): void {
		$legacy                 = array_intersect_key( $this->record(), array_flip( array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' ) ) );
		$legacy['setup_branch'] = 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef';
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789'] = $legacy;
		$store = new SetupRecordStore();
		self::assertNull( $store->find( '123456789' ) );
		self::assertSame(
			array(
				'schema_version' => 1,
				'repository'     => $legacy['repository'],
				'setup_branch'   => $legacy['setup_branch'],
				'pr_number'      => 42,
			),
			$store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 )
		);
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789']['token'] = 'secret';
		$unsupported = array(
			'schema_version' => 1,
			'unsupported'    => 1,
		);
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789'] = $legacy;
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'theme', 'example-plugin/example-plugin.php', 3 ) );
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'other/example.php', 3 ) );
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
	}
	public function testReadbackAndRecordCapFailClosed(): void {
		$GLOBALS['ran_booster_release_deployments_test_option_override'] = array();
		self::assertFalse( ( new SetupRecordStore() )->save( $this->record() ) );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
		$records = array();
		for ( $index = 1; $index <= 100; ++$index ) {
			$record                     = array_replace( $this->record(), array( 'repo_id' => (string) $index ) );
			$records[ (string) $index ] = $record;
		}
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] = $records;
		self::assertTrue( ( new SetupRecordStore() )->occupied( '100' ) );
		self::assertFalse( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '101' ) ) ) );
		self::assertTrue( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '100' ) ) ) );
	}
	public function testAssessmentObservationIsExactBoundedAndReplacesOnlyItsLatestTuple(): void {
		$store       = new SetupRecordStore();
		$observation = $this->observation();
		self::assertTrue( $store->saveAssessmentObservation( $observation ) );
		self::assertSame( $observation, $store->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertNull( $store->assessmentObservation( '123456789', 'theme', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse( $store->saveAssessmentObservation( $observation + array( 'token' => 'secret' ) ) );
		self::assertFalse( $store->saveAssessmentObservation( array_replace( $observation, array( 'kind' => 'unexpected' ) ) ) );
		self::assertFalse( $store->saveAssessmentObservation( array_replace( $observation, array( 'observed_at' => '2026-08-27' ) ) ) );

		$replacement = array_replace(
			$observation,
			array(
				'kind'        => 'booster_setup_verified',
				'observed_at' => '2026-08-27T12:35:56Z',
			)
		);
		self::assertTrue( $store->saveAssessmentObservation( $replacement ) );
		self::assertSame( $replacement, $store->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertCount( 1, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_assessment_observations'] );
		self::assertFalse( $GLOBALS['ran_booster_release_deployments_test_option_updates'][0][2] );
	}
	public function testAssessmentObservationUsesTheSameCrossConnectionLock(): void {
		$first           = new SetupRecordStore();
		$firstConnection = $GLOBALS['wpdb'];
		$claim           = $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$observation      = $this->observation();
		$secondConnection = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $secondConnection;
		$second          = new SetupRecordStore();
		self::assertFalse( $second->saveAssessmentObservation( $observation ) );
		self::assertNull( $second->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection to release its claim.
		$GLOBALS['wpdb'] = $firstConnection;
		self::assertTrue( $first->releaseClaim( '123456789', $claim ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the second connection to prove it can save after release.
		$GLOBALS['wpdb'] = $secondConnection;
		self::assertTrue( $second->saveAssessmentObservation( $observation ) );
		self::assertSame( $observation, $second->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
	}
	public function testMalformedAssessmentObservationOptionFailsClosed(): void {
		$observation = $this->observation();
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_assessment_observations'] = array( $observation, $observation );
		$store = new SetupRecordStore();
		self::assertNull( $store->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertFalse( $store->saveAssessmentObservation( $observation ) );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'] );
	}
	public function testAssessmentObservationPrunesSupersededSourceRevisionsBeforeTheCap(): void {
		$store = new SetupRecordStore();
		self::assertTrue( $store->saveAssessmentObservation( $this->observation() ) );
		$current = array_replace(
			$this->observation(),
			array(
				'kind'            => 'no_recognisable_automation',
				'source_revision' => 4,
				'observed_at'     => '2026-08-27T12:35:56Z',
			)
		);
		self::assertTrue( $store->saveAssessmentObservation( $current ) );
		self::assertNull( $store->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		self::assertSame( $current, $store->assessmentObservation( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		self::assertCount( 1, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_assessment_observations'] );
	}
	public function testAssessmentObservationDeterministicallyEvictsTheOldestValidEntryAtCapacity(): void {
		$store = new SetupRecordStore();
		for ( $index = 1; $index <= 100; ++$index ) {
			self::assertTrue(
				$store->saveAssessmentObservation(
					array_replace(
						$this->observation(),
						array(
							'repository_id'      => (string) $index,
							'package_identifier' => 'example-' . $index . '/example.php',
							'observed_at'        => 1 === $index ? '2025-08-27T12:34:56Z' : '2026-08-27T12:34:56Z',
						)
					)
				)
			);
		}
		$new = array_replace(
			$this->observation(),
			array(
				'repository_id'      => '101',
				'package_identifier' => 'example-101/example.php',
				'observed_at'        => '2027-08-27T12:34:56Z',
			)
		);
		self::assertTrue( $store->saveAssessmentObservation( $new ) );
		self::assertNull( $store->assessmentObservation( '1', 'plugin', 'example-1/example.php', 3 ) );
		self::assertSame( $new, $store->assessmentObservation( '101', 'plugin', 'example-101/example.php', 3 ) );
		self::assertCount( 100, $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_assessment_observations'] );
	}
	public function testFailureHistoryIsBoundedToSafeValidatedFailureEvidence(): void {
		$store   = new SetupRecordStore();
		$failure = array(
			'operation'             => 'inspect',
			'outcome_code'          => 'workflow_remote_unavailable',
			'failure_stage'         => 'repository_snapshot',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'diagnostic_code'       => 'provider_unavailable',
			'diagnostic_available'  => true,
			'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'recorded_at'           => '2026-08-27T12:34:56Z',
		);

		self::assertTrue( $store->recordFailure( $failure ) );
		self::assertSame(
			array( $failure ),
			$store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 )
		);
		self::assertSame( array(), $store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
		self::assertFalse( $store->recordFailure( $failure + array( 'token' => 'secret' ) ) );
		self::assertFalse( $store->recordFailure( $failure + array( 'message' => 'secret' ) ) );
		self::assertFalse( $store->recordFailure( $failure + array( 'url' => 'https://example.test' ) ) );
		self::assertFalse( $store->recordFailure( array_replace( $failure, array( 'failure_stage' => 'invalid_stage' ) ) ) );
		self::assertFalse( $store->recordFailure( array_replace( $failure, array( 'diagnostic_code' => str_repeat( 'a', 65 ) ) ) ) );
		self::assertFalse( $store->recordFailure( array_replace( $failure, array( 'diagnostic_available' => 'yes' ) ) ) );

		for ( $index = 1; $index <= 20; ++$index ) {
			self::assertTrue( $store->recordFailure( array_replace( $failure, array( 'correlation_reference' => sprintf( '%032x', $index ) ) ) ) );
		}

		$history = $store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertLessThanOrEqual( 12, count( $history ) );
		self::assertContains( sprintf( '%032x', 20 ), array_column( $history, 'correlation_reference' ) );

		$GLOBALS['ran_booster_release_deployments_test_option_update_result'] = false;
		self::assertFalse( $store->recordFailure( array_replace( $failure, array( 'correlation_reference' => str_repeat( 'b', 32 ) ) ) ) );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_update_result'] );
	}
	public function testFailureHistoryAppendUsesTheSameCrossConnectionLock(): void {
		$first           = new SetupRecordStore();
		$firstConnection = $GLOBALS['wpdb'];
		$claim           = $first->claim( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertNotNull( $claim );
		$failure          = array(
			'operation'             => 'inspect',
			'outcome_code'          => 'workflow_remote_unavailable',
			'failure_stage'         => 'repository_snapshot',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'diagnostic_code'       => 'provider_unavailable',
			'diagnostic_available'  => true,
			'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'recorded_at'           => '2026-08-27T12:34:56Z',
		);
		$secondConnection = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused database double switches to a distinct connection.
		$GLOBALS['wpdb'] = $secondConnection;
		$second          = new SetupRecordStore();
		self::assertFalse( $second->recordFailure( $failure ) );
		self::assertSame( array(), $second->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original connection to release its claim.
		$GLOBALS['wpdb'] = $firstConnection;
		self::assertTrue( $first->releaseClaim( '123456789', $claim ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the second connection to prove it can append after release.
		$GLOBALS['wpdb'] = $secondConnection;
		self::assertTrue( $second->recordFailure( $failure ) );
		self::assertSame( array( $failure ), $second->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
	}
	public function testLegacyFailureHistoryIsRetainedAndUpgradedWhenRecordingANewFailure(): void {
		$legacy = array(
			'operation'             => 'inspect',
			'outcome_code'          => 'workflow_remote_unavailable',
			'failure_stage'         => 'repository_snapshot',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			'recorded_at'           => '2026-08-27T12:34:56Z',
		);
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_failure_history'] = array( $legacy );
		$store = new SetupRecordStore();
		$new   = array_merge(
			array_slice( $legacy, 0, 7, true ),
			array(
				'diagnostic_code'       => 'provider_unavailable',
				'diagnostic_available'  => true,
				'correlation_reference' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
				'recorded_at'           => '2026-08-27T12:35:56Z',
			)
		);

		self::assertTrue( $store->recordFailure( $new ) );
		$history = $store->failureHistory( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 );
		self::assertCount( 2, $history );
		self::assertSame( 'diagnostic_detail_unavailable', $history[0]['diagnostic_code'] );
		self::assertFalse( $history[0]['diagnostic_available'] );
		self::assertSame( $new, $history[1] );
		self::assertSame(
			array(
				'operation',
				'outcome_code',
				'failure_stage',
				'package_type',
				'package_identifier',
				'source_revision',
				'repository_id',
				'diagnostic_code',
				'diagnostic_available',
				'correlation_reference',
				'recorded_at',
			),
			array_keys( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_failure_history'][0] )
		);
	}
	/** @return array<string,int|string> */
	private function record(): array {
		return array(
			'schema_version'        => 2,
			'operation'             => 'bootstrap',
			'repo_id'               => '123456789',
			'repository'            => 'RocketsAreNostalgic/example-plugin',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'default_branch'        => 'main',
			'base_sha'              => str_repeat( 'a', 40 ),
			'setup_branch'          => 'ran-booster/release-setup-v2-aaaaaaaaaaaa-deadbeef',
			'head_sha'              => str_repeat( 'b', 40 ),
			'pr_number'             => 42,
			'profile_id'            => 'source-ready-wordpress-plugin/2',
			'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'template_repo_id'      => '1322743261',
			'template_release_id'   => 41,
			'template_tag'          => 'v1.2.3',
			'template_commit'       => str_repeat( 'c', 40 ),
			'template_asset_id'     => 73,
			'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
			'template_asset_size'   => 1000,
			'template_asset_digest' => str_repeat( 'd', 64 ),
			'manifest_digest'       => str_repeat( 'e', 64 ),
			'receipt_digest'        => str_repeat( 'f', 64 ),
			'consumer_api'          => 2,
			'pack_version'          => '1.2.3',
			'bundle_hash'           => str_repeat( '1', 64 ),
			'changed_path_hash'     => str_repeat( '2', 64 ),
		);
	}
	/** @return array<string,int|string> */
	private function observation(): array {
		return array(
			'kind'               => 'existing_automation_detected',
			'repository_id'      => '123456789',
			'package_type'       => 'plugin',
			'package_identifier' => 'example-plugin/example-plugin.php',
			'source_revision'    => 3,
			'observed_at'        => '2026-08-27T12:34:56Z',
		);
	}
}

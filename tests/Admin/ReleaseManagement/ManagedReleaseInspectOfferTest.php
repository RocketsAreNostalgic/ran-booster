<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowserOperations;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

/**
 * inspect() decides whether the exact inspected release can be updated through WordPress. The
 * updater's status only holds an offer in the request that ran its check, so a persisted WordPress
 * offer with the same version is what matches. Each test runs in its own process because it defines
 * the global WordPress transient function.
 */
final class ManagedReleaseInspectOfferTest extends TestCase {

	/** @return array<string,mixed> */
	private function inspect( ?string $offered_version, bool $status_update_available, string $status_release_id, string $status_latest = '1.1.0' ): array {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$GLOBALS['ran_booster_test_site_transients'] = null === $offered_version
			? array()
			: array( 'update_plugins' => (object) array( 'response' => array( 'example/example.php' => (object) array( 'new_version' => $offered_version ) ) ) );

		$status                         = new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'release_asset',
			3,
			'101',
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/example/example', 'example-plugin' ),
			null,
			'example-plugin',
			'1.0.0',
			$status_latest,
			$status_update_available,
			'2026-07-24T20:00:00+00:00',
			'',
			'',
			'stable',
			$status_release_id
		);
		$tracking                       = new ReleaseTrackingFacadeDouble( $status );
		$tracking->candidate_inspection = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0', 'v1.2.0', '1.2.0', 'newer' );

		$result = ( new ManagedReleaseBrowserOperations( $tracking, $tracking ) )->inspect( 'plugin', 'example/example.php', 3, '42', 'v1.2.0', 'stable', 'nonce' );
		self::assertTrue( $result['successful'] );

		return $result['data']['native_offer'];
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_persisted_offer_for_the_inspected_version_is_the_offer_even_when_the_status_holds_none(): void {
		self::assertSame(
			array(
				'available'  => true,
				'release_id' => '42',
				'version'    => '1.2.0',
			),
			$this->inspect( '1.2.0', false, '' )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_persisted_offer_for_another_version_is_not_the_inspected_release(): void {
		self::assertSame(
			array(
				'available'  => false,
				'release_id' => '',
				'version'    => '1.1.0',
			),
			$this->inspect( '1.1.0', false, '' )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_release_id_the_status_knows_is_kept_so_a_different_inspected_release_still_blocks(): void {
		$offer = $this->inspect( '1.2.0', false, 'known-release' );

		self::assertTrue( $offer['available'] );
		self::assertSame( 'known-release', $offer['release_id'], 'The script compares this with the inspected release id.' );
		self::assertNotSame( '42', $offer['release_id'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_without_a_persisted_offer_the_status_alone_decides_and_no_release_id_is_invented(): void {
		self::assertSame(
			array(
				'available'  => false,
				'release_id' => '',
				'version'    => '1.1.0',
			),
			$this->inspect( null, false, '' )
		);
		self::assertSame(
			array(
				'available'  => true,
				'release_id' => '',
				'version'    => '1.1.0',
			),
			$this->inspect( null, true, '' )
		);
	}
}

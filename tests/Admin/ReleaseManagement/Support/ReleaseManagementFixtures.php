<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;

final class ReleaseManagementFixture {
	public static function controls(
		?ReleaseTrackingFacadeDouble $tracking = null,
		?ProspectiveReleaseFacadeDouble $prospective = null,
		?callable $readCandidates = null
	): ReleaseManagementControls {
		$prospective ??= new ProspectiveReleaseFacadeDouble();
		$tracking    ??= new ReleaseTrackingFacadeDouble( self::status() );
		return new ReleaseManagementControls(
			$tracking,
			$prospective,
			$readCandidates ?? static fn ( string $type, array $repository, string $channel ): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult => $prospective->listCandidates( $type, $repository, $channel, '' ),
			$tracking
		);
	}

	public static function status(
		string $source = 'branch',
		string $type = 'plugin',
		string $eligibilityCode = ReleaseTrackingEligibility::ELIGIBLE,
		bool $updateAvailable = false,
		string $channel = 'stable',
		string $failureCode = ''
	): ReleaseTrackingStatus {
		$identifier = 'theme' === $type ? 'example-theme' : 'example/example.php';

		return new ReleaseTrackingStatus(
			$type,
			$identifier,
			$source,
			3,
			'101',
			'manual',
			new ReleaseTrackingEligibility(
				$eligibilityCode,
				'https://github.com/example/example',
				'example-plugin'
			),
			new ReleaseTrackingPreflight(
				ReleaseTrackingPreflight::READY,
				'example-plugin',
				'1.1.0',
				'https://github.com/example/example/releases/tag/v1.1.0'
			),
			'example-plugin',
			'1.0.0',
			'1.1.0',
			$updateAvailable,
			'2026-07-24T20:00:00+00:00',
			'',
			$failureCode,
			$channel
		);
	}

	public static function resetWordPress(): void {
		foreach ( array(
			'actions',
			'filters',
			'scripts',
			'styles',
			'localized',
			'denied_capabilities',
			'nonce_age',
			'redirect',
			'header',
			'json',
		) as $suffix ) {
			unset( $GLOBALS[ 'ran_booster_release_management_test_' . $suffix ] );
		}
		$_GET  = array();
		$_POST = array();
	}
}

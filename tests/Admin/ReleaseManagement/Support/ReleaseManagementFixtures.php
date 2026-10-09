<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;

final class ReleaseManagementFixture {
	public static function controls(
		?ReleaseTrackingFacadeDouble $tracking = null,
		?ProspectiveReleaseFacadeDouble $prospective = null,
		?callable $read_candidates = null,
		?\RAN\Storage\RepositorySourceGuard $source_guard = null
	): ReleaseManagementControls {
		$prospective ??= new ProspectiveReleaseFacadeDouble();
		$tracking    ??= new ReleaseTrackingFacadeDouble( self::status() );
		return new ReleaseManagementControls(
			$tracking,
			$prospective,
			$read_candidates ?? static function ( string $type, array $repository, string $channel ) use ( $prospective ): \RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult {
				if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
					\PHPUnit\Framework\Assert::fail( 'Release operations must validate the channel before invoking the reader.' );
				}
				return $prospective->list_candidates( $type, $repository, $channel, '' );
			},
			$tracking,
			$source_guard
		);
	}

	public static function status(
		string $source = 'branch',
		string $type = 'plugin',
		string $eligibility_code = ReleaseTrackingEligibility::ELIGIBLE,
		bool $update_available = false,
		string $channel = 'stable',
		string $failure_code = '',
		string $deployment_policy = 'manual'
	): ReleaseTrackingStatus {
		$identifier = 'theme' === $type ? 'example-theme' : 'example/example.php';

		return new ReleaseTrackingStatus(
			$type,
			$identifier,
			$source,
			3,
			'101',
			$deployment_policy,
			new ReleaseTrackingEligibility(
				$eligibility_code,
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
			$update_available,
			'2026-07-24T20:00:00+00:00',
			'',
			$failure_code,
			$channel
		);
	}

	public static function reset_word_press(): void {
		foreach ( array(
			'actions',
			'filters',
			'scripts',
			'script_translations',
			'styles',
			'localized',
			'denied_capabilities',
			'nonce_age',
			'redirect',
			'header',
			'json',
			'multisite',
		) as $suffix ) {
			unset( $GLOBALS[ 'ran_booster_release_management_test_' . $suffix ] );
		}
		$_GET  = array();
		$_POST = array();
	}
}

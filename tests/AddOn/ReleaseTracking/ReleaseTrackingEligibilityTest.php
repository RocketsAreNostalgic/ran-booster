<?php

declare(strict_types=1);

namespace Tests\AddOn\ReleaseTracking;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;

final class ReleaseTrackingEligibilityTest extends TestCase {

	public function testEligibleIdentityCarriesOnlySafeGuidance(): void {
		$eligibility = new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::ELIGIBLE,
			'https://github.com/example/example',
			'example'
		);

		self::assertTrue( $eligibility->eligible() );
		self::assertSame( 'example', $eligibility->packageRoot() );
	}

	public function testTargetAlreadyUsingTheRANUpdaterIsIneligible(): void {
		$eligibility = new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER,
			'https://github.com/example/example',
			'example'
		);

		self::assertFalse( $eligibility->eligible() );
		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $eligibility->code() );
	}

	public function testPreflightCarriesTheValidatedPackageRootAndRelease(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::READY,
			'example',
			'1.2.3',
			'https://github.com/example/example/releases/tag/v1.2.3'
		);

		self::assertTrue( $preflight->ready() );
		self::assertSame( 'example', $preflight->packageRoot() );
		self::assertSame( '1.2.3', $preflight->latestVersion() );
		self::assertSame( 'https://github.com/example/example/releases/tag/v1.2.3', $preflight->releaseUrl() );
	}

	public function testPreflightCarriesOnlySafeVersionMismatchGuidance(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::RELEASE_VERSION_MISMATCH,
			'example',
			'2.1.0',
			'https://github.com/example/example/releases/tag/v2.1.0',
			'v2.1.0',
			'2.0.0'
		);

		self::assertFalse( $preflight->ready() );
		self::assertSame( 'v2.1.0', $preflight->releaseTag() );
		self::assertSame( '2.0.0', $preflight->packageHeaderVersion() );
	}
}

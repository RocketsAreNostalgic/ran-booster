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

	public function testPreflightCarriesOnlyAnAllowlistedReasonCode(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			'example',
			reasonCode: 'invalid_release'
		);

		self::assertSame( 'invalid_release', $preflight->reasonCode() );

		$this->expectException( \InvalidArgumentException::class );
		new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'example',
			reasonCode: 'provider_secret_detail'
		);
	}

	public function testPreflightRejectsUnsafeReleaseUrls(): void {
		foreach (
			array(
				"https://example.com/releases/tag/v1.2.3\nsecret",
				'https://example.com/releases/tag/v1.2.3 secret',
				'https://user:password@example.com/releases/tag/v1.2.3',
				'http://example.com/releases/tag/v1.2.3',
				'https://example.com',
				'https://invalid_host.example/releases/tag/v1.2.3',
			) as $url
		) {
			try {
				new ReleaseTrackingPreflight(
					ReleaseTrackingPreflight::READY,
					'example',
					'1.2.3',
					$url
				);
				self::fail( 'Unsafe provider release URLs must be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}
}

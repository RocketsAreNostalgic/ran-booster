<?php

declare(strict_types=1);

namespace Tests\AddOn\ReleaseTracking;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;

final class ReleaseTrackingEligibilityTest extends TestCase {

	public function test_eligible_identity_carries_only_safe_guidance(): void {
		$eligibility = new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::ELIGIBLE,
			'https://github.com/example/example',
			'example'
		);

		self::assertTrue( $eligibility->eligible() );
		self::assertSame( 'example', $eligibility->package_root() );
	}

	public function test_target_already_using_the_r_a_n_updater_is_ineligible(): void {
		$eligibility = new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER,
			'https://github.com/example/example',
			'example'
		);

		self::assertFalse( $eligibility->eligible() );
		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $eligibility->code() );
	}

	public function test_preflight_carries_the_validated_package_root_and_release(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::READY,
			'example',
			'1.2.3',
			'https://github.com/example/example/releases/tag/v1.2.3'
		);

		self::assertTrue( $preflight->ready() );
		self::assertSame( 'example', $preflight->package_root() );
		self::assertSame( '1.2.3', $preflight->latest_version() );
		self::assertSame( 'https://github.com/example/example/releases/tag/v1.2.3', $preflight->release_url() );
	}

	public function test_preflight_carries_only_safe_version_mismatch_guidance(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::RELEASE_VERSION_MISMATCH,
			'example',
			'2.1.0',
			'https://github.com/example/example/releases/tag/v2.1.0',
			'v2.1.0',
			'2.0.0'
		);

		self::assertFalse( $preflight->ready() );
		self::assertSame( 'v2.1.0', $preflight->release_tag() );
		self::assertSame( '2.0.0', $preflight->package_header_version() );
	}

	public function test_preflight_carries_only_an_allowlisted_reason_code(): void {
		$preflight = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			'example',
			reason_code: 'invalid_release'
		);

		self::assertSame( 'invalid_release', $preflight->reason_code() );

		$this->expectException( \InvalidArgumentException::class );
		new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'example',
			reason_code: 'provider_secret_detail'
		);
	}

	public function test_preflight_rejects_unsafe_release_urls(): void {
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

<?php

declare(strict_types=1);

namespace Tests\AddOn\ReleaseTracking;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

final class ReleaseTrackingContractTest extends TestCase {

	public function test_status_exposes_only_bounded_display_values(): void {
		$status = new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'release_asset',
			4,
			'123456789',
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE ),
			null,
			'example',
			'1.0.0',
			'1.1.0',
			true,
			'2026-07-24T20:00:00+00:00',
			'',
			'',
			'prerelease'
		);

		self::assertSame( 'example/example.php', $status->identifier() );
		self::assertSame( 'release_asset', $status->source() );
		self::assertSame( 4, $status->source_revision() );
		self::assertSame( '123456789', $status->provider_repository_id() );
		self::assertSame( 'prerelease', $status->channel() );
		self::assertSame( 'example', $status->package_root() );
		self::assertTrue( $status->eligible() );
		self::assertTrue( $status->update_available() );
	}

	public function test_status_does_not_apply_workflow_update_uri_validation(): void {
		$status = new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'release_asset',
			4,
			'123456789',
			'manual',
			new ReleaseTrackingEligibility(
				ReleaseTrackingEligibility::ELIGIBLE,
				'http://git.example.test/owner/example'
			)
		);

		self::assertSame( 'http://git.example.test/owner/example', $status->eligibility()->expected_update_uri() );
	}

	public function test_status_rejects_unbounded_provider_repository_identity(): void {
		$this->expectException( \InvalidArgumentException::class );

		new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'branch',
			1,
			str_repeat( 'a', 192 ),
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE )
		);
	}

	public function test_result_provides_stable_success_and_failure_notices(): void {
		$success = ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled' );
		$failure = ReleaseTrackingResult::failed( 'source_changed', 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.' );

		self::assertTrue( $success->successful() );
		self::assertSame( 'release_enabled', $success->code() );
		self::assertFalse( $failure->successful() );
		self::assertSame( 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.', $failure->message() );
	}
}

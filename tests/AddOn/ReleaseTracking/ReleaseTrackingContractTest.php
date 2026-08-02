<?php

declare(strict_types=1);

namespace Tests\AddOn\ReleaseTracking;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

final class ReleaseTrackingContractTest extends TestCase {

	public function testStatusExposesOnlyBoundedDisplayValues(): void {
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
		self::assertSame( 4, $status->sourceRevision() );
		self::assertSame( '123456789', $status->providerRepositoryId() );
		self::assertSame( 'prerelease', $status->channel() );
		self::assertSame( 'example', $status->packageRoot() );
		self::assertTrue( $status->eligible() );
		self::assertTrue( $status->updateAvailable() );
	}

	public function testStatusRejectsUnboundedProviderRepositoryIdentity(): void {
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

	public function testResultProvidesStableSuccessAndFailureNotices(): void {
		$success = ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled' );
		$failure = ReleaseTrackingResult::failed( 'source_changed', 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.' );

		self::assertTrue( $success->successful() );
		self::assertSame( 'release_enabled', $success->code() );
		self::assertFalse( $failure->successful() );
		self::assertSame( 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.', $failure->message() );
	}
}

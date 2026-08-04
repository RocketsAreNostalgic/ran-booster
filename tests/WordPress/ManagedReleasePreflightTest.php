<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/ProspectiveReleaseUpdaterFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\Secrets\SecretsFile;
use RAN\WordPress\ManagedReleasePreflight;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ManagedCandidateValidationFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;

final class ManagedReleasePreflightTest extends TestCase {

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
	}

	#[DataProvider( 'updaterFailures' )]
	public function testUpdaterFailuresRetainOnlyTheirBoundedCause(
		string $updaterCode,
		string $expectedCategory,
		string $expectedReason
	): void {
		ReleaseCandidatePreflight::$check = new \WP_Error( $updaterCode, 'Sensitive provider detail.' );

		$result = $this->preflight()( 'theme', $this->package(), 'example', 'style.css', true );

		self::assertSame( $expectedCategory, $result->code() );
		self::assertSame( $expectedReason, $result->reasonCode() );
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function updaterFailures(): iterable {
		yield 'missing or multiple ZIP assets' => array(
			'github_updater_ambiguous_release_asset',
			ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			'github_updater_ambiguous_release_asset',
		);
		yield 'no eligible release' => array(
			'github_updater_no_eligible_release',
			ReleaseTrackingPreflight::RELEASE_UNAVAILABLE,
			'github_updater_no_eligible_release',
		);
		yield 'credentials' => array(
			'github_updater_github_authentication_failed',
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'github_updater_github_authentication_failed',
		);
		yield 'rate limit' => array(
			'github_updater_rate_limited',
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'github_updater_rate_limited',
		);
		yield 'integrity' => array(
			'github_updater_downloaded_digest_mismatch',
			ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			'github_updater_downloaded_digest_mismatch',
		);
		yield 'artifact runtime failure' => array(
			'github_updater_release_artifact_unavailable',
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'github_updater_release_artifact_unavailable',
		);
		yield 'coordination detail is normalized' => array(
			'github_updater_operation_fence_lost',
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'github_updater_operation_failed',
		);
		yield 'assurance detail is normalized' => array(
			'github_updater_release_assurance_rejected',
			ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			'github_updater_release_assurance_failed',
		);
	}

	public function testCandidateValidationRetainsItsExactSafeFailure(): void {
		ReleaseCandidatePreflight::$check = new ManagedCandidateValidationFixture( 'package_update_uri_invalid' );

		$result = $this->preflight()( 'theme', $this->package(), 'example', 'style.css', true );

		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result->code() );
		self::assertSame( 'package_update_uri_invalid', $result->reasonCode() );
	}

	public function testUnknownUpdaterFailureRemainsSafeAndHonest(): void {
		ReleaseCandidatePreflight::$check = new \WP_Error( 'provider_secret_detail', 'token=do-not-render' );

		$result = $this->preflight()( 'theme', $this->package(), 'example', 'style.css', true );

		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result->code() );
		self::assertSame( '', $result->reasonCode() );
	}

	private function preflight(): ManagedReleasePreflight {
		return new ManagedReleasePreflight(
			new SecretsFile( sys_get_temp_dir() . '/ran-booster-managed-preflight-test.php', array() )
		);
	}

	private function package(): Package {
		$package = $this->createMock( Package::class );
		$package->method( 'getRepository' )->willReturn(
			new ManagedRepository( 'gh', 'example/example', '101', 'main' )
		);
		$package->method( 'getProviderRepositoryId' )->willReturn( '101' );
		$package->method( 'getCredentialId' )->willReturn( '' );
		$package->method( 'getPrivate' )->willReturn( false );
		$package->method( 'getVersion' )->willReturn( '1.2.3' );

		return $package;
	}
}

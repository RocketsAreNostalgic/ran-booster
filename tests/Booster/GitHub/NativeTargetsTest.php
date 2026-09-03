<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubReleaseNativeTarget;

final class NativeTargetsTest extends TestCase {

	public function testConstructionDoesNotResolvePrivateCredentials(): void {
		$reads  = 0;
		$target = $this->target(
			static function () use ( &$reads ): string {
				++$reads;

				return 'github_pat_current';
			}
		);

		self::assertSame( 0, $reads );
		self::assertFalse( $target->status()->active );
		self::assertFalse( $target->refresh() );
		self::assertSame( 0, $reads );
	}

	public function testCallableLookingAccessTokenRemainsCredentialMaterial(): void {
		$target      = $this->target( 'strlen' );
		$accessToken = ( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'accessToken' ) )->getValue( $target );

		self::assertSame( 'strlen', $accessToken );
	}

	public function testNativeStatusFailsClosedUntilTheNeutralRuntimeSuppliesOne(): void {
		$status = $this->target( null )->status();

		self::assertFalse( $status->active );
		self::assertSame( '', $status->offeredVersion );
		self::assertSame( '', $status->failureCode );
		self::assertSame( '', $status->candidateCode );
	}

	public function testRegisteredNeutralUpdaterIsActive(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new \stdClass()
		);

		$status = $target->status();
		self::assertTrue( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failureCode );
	}

	public function testRegisteredNeutralUpdaterProjectsItsBoundedStatus(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, int|string|null> */
				public function status(): array {
					return array(
						'candidate_tag'             => 'v1.2.0',
						'candidate_validation_code' => 'archive_identity_verified',
						'candidate_version'         => '1.2.0',
						'candidate_header_version'  => '1.2.0',
						'failure_code'              => null,
						'installed_version'         => '1.0.0',
						'last_check'                => 1_700_000_000,
						'offered_version'           => '1.2.0',
						'relationship'              => 'newer',
					);
				}
			}
		);

		$status = $target->status();

		self::assertTrue( $status->active );
		self::assertSame( '1.2.0', $status->offeredVersion );
		self::assertSame( 'newer', $status->versionRelationship );
		self::assertSame( 1_700_000_000, $status->lastCheck );
		self::assertNull( $status->nextCheck );
		self::assertSame( 'release_identity_verified', $status->candidateCode );
		self::assertSame( 'v1.2.0', $status->candidateReleaseTag );
		self::assertSame( '1.2.0', $status->candidateReleaseVersion );
		self::assertSame( '1.2.0', $status->candidatePackageHeaderVersion );
	}

	public function testRegisteredNeutralUpdaterFailsClosedOnMalformedStatus(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, int|string|null> */
				public function status(): array {
					return array();
				}
			}
		);

		$status = $target->status();

		self::assertTrue( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failureCode );
		self::assertSame( '', $status->offeredVersion );
		self::assertSame( '', $status->candidateCode );
	}

	private function target( string|callable|null $accessToken ): GitHubReleaseNativeTarget {
		return new GitHubReleaseNativeTarget(
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			'example',
			'example/example.php',
			$accessToken,
			'stable',
			'manual'
		);
	}
}

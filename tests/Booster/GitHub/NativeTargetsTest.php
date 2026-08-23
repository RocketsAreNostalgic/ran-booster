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
		self::assertSame( '', $status->failureCode );
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

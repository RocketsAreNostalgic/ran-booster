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

		self::assertInstanceOf( \Closure::class, $accessToken );
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
		self::assertFalse( $status->active );
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
						'state'                => 'active',
						'declaration_accepted' => true,
						'hooks_registered'     => true,
						'code'                 => 'target_active',
						'native'               => array(
							'candidate_tag'             => 'v1.2.0',
							'candidate_validation_code' => 'archive_identity_verified',
							'candidate_version'         => '1.2.0',
							'candidate_header_version'  => '1.2.0',
							'failure_code'              => null,
							'installed_version'         => '1.0.0',
							'last_check'                => 1_700_000_000,
							'offered_version'           => '1.2.0',
							'relationship'              => 'newer',
						),
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

	public function testQueuedAndInactiveNeutralStatesDoNotClaimNativeAuthority(): void {
		$target = $this->target( null );
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'queued',
						'declaration_accepted' => true,
						'hooks_registered'     => false,
						'code'                 => 'awaiting_activation',
						'native'               => null,
					);
				}
			}
		);

		self::assertFalse( $target->status()->active );
		self::assertSame( '', $target->status()->failureCode );

		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue(
			$target,
			new class() {
				/** @return array<string, mixed> */
				public function status(): array {
					return array(
						'state'                => 'inactive',
						'declaration_accepted' => false,
						'hooks_registered'     => false,
						'code'                 => 'runtime_handoff_invalid',
						'native'               => null,
					);
				}
			}
		);

		$status = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_runtime_handoff_invalid', $status->failureCode );
		self::assertLessThanOrEqual( 64, strlen( $status->failureCode ) );
	}

	public function testRepeatedRegistrationReflectsDeclinedAndThrowingPublicHandles(): void {
		$declined  = new class() {
			public int $calls = 0;

			public function register(): bool {
				++$this->calls;

				return false;
			}
		};
		$registrar = new class( $declined ) {
			public int $calls = 0;

			public function __construct( private object $handle ) {
			}

			public function plugin( mixed ...$arguments ): object {
				unset( $arguments );
				++$this->calls;

				return $this->handle;
			}
		};
		$target    = $this->target( null, $registrar );

		self::assertFalse( $target->register() );
		self::assertFalse( $target->register() );
		self::assertSame( 1, $registrar->calls );
		self::assertSame( 2, $declined->calls );

		$throwing = new class() {
			public function register(): bool {
				throw new \RuntimeException( 'declaration failed' );
			}
		};
		$target   = $this->target(
			null,
			new class( $throwing ) {
				public function __construct( private object $handle ) {
				}

				public function plugin( mixed ...$arguments ): object {
					unset( $arguments );

					return $this->handle;
				}
			}
		);

		self::assertFalse( $target->register() );
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

		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failureCode );
		self::assertSame( '', $status->offeredVersion );
		self::assertSame( '', $status->candidateCode );
	}

	private function target( string|callable|null $accessToken, ?object $registrar = null ): GitHubReleaseNativeTarget {
		return new GitHubReleaseNativeTarget(
			$registrar ?? new \stdClass(),
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			$accessToken,
			'stable',
			'manual'
		);
	}
}

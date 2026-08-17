<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/WPError.php';
require_once dirname( __DIR__, 2 ) . '/Support/ProspectiveReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveInspectionFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
use RuntimeException;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseInspectionTest extends TestCase {

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
	}

	protected function tearDown(): void {
		ReleaseCandidatePreflight::reset();
	}

	public function testInspectsOneExactReleaseIntoPathFreeEvidenceWithoutAcquiringForInstallation(): void {
		ReleaseCandidatePreflight::$inspection = new ProspectiveInspectionFixture();
		$credentials                           = new RepositoryResolverSecretsStub();
		$inspector                             = $this->provider( $credentials );

		$result = $inspector->inspectRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);

		self::assertSame( '42', $result->providerReleaseId );
		self::assertSame( 'v1.2.3', $result->tag );
		self::assertSame( '1.2.3', $result->version );
		self::assertSame( str_repeat( 'a', 40 ), $result->providerCommitId );
		self::assertSame( 'example', $result->packageRoot );
		self::assertSame( 'example.php', $result->mainFile );
		self::assertSame( 'v1:' . str_repeat( 'a', 64 ), $result->fingerprint );
		self::assertSame( 1, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 'owner/example', ReleaseCandidatePreflight::$target['repository'] );
		self::assertSame( '123456789', ReleaseCandidatePreflight::$target['providerRepositoryId'] );
		self::assertSame( 'plugin', ReleaseCandidatePreflight::$target['packageType'] );
		self::assertSame( 'stable', ReleaseCandidatePreflight::$target['channel'] );
		self::assertNull( ReleaseCandidatePreflight::$target['accessToken'] );
		self::assertSame( array(), $credentials->lookups );
	}

	public function testExactReleaseHttp404RemainsOperationalBecauseItsStageIsAmbiguous(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'github_updater_github_http_error',
			'upstream-secret-message',
			array( 'status' => 404 )
		);

		$this->assertOperationalFailureIsRedacted();
	}

	public function testOtherGitHubHttpFailuresRemainOperationalAndRedacted(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'github_updater_github_http_error',
			'upstream-secret-message',
			array( 'status' => 500 )
		);

		$this->assertOperationalFailureIsRedacted();
	}

	public function testInvalidReleaseMapsToThePurposeSpecificRejection(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'package_archive_path_unsafe',
			'upstream-secret-message'
		);

		try {
			$this->inspectPublicRelease();
			self::fail( 'An invalid release must reject the inspection.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $exception->reason );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testNoEligibleReleaseMapsToThePurposeSpecificRejection(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'github_updater_no_eligible_release',
			'upstream-secret-message'
		);

		try {
			$this->inspectPublicRelease();
			self::fail( 'An unavailable exact release must reject the inspection.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::NO_RELEASES, $exception->reason );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testPackageIncompatibilityRemainsASeparateFallbackReason(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'github_updater_release_incompatible',
			'upstream-secret-message'
		);

		try {
			$this->inspectPublicRelease();
			self::fail( 'An incompatible package must reject the inspection.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $exception->reason );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testInvalidGitHubReleaseIdentityRejectsBeforeUpdaterOrCredentialWork(): void {
		$credentials = new RepositoryResolverSecretsStub(
			array( 'private-release' => 'secret-token' )
		);

		try {
			$this->provider( $credentials )->inspectRelease(
				'plugin',
				new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
				'9999999999999999999',
				'v1.2.3',
				'stable'
			);
			self::fail( 'A non-representable GitHub release identity must be rejected.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $exception->reason );
		}

		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
		self::assertSame( array(), $credentials->lookups );
	}

	public function testOperationalFailureDoesNotExposeTheUpstreamMessage(): void {
		ReleaseCandidatePreflight::$inspection = new \WP_Error(
			'github_updater_http_transport_failed',
			'upstream-secret-message'
		);

		try {
			$this->inspectPublicRelease();
			self::fail( 'Operational inspection failures must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertNotInstanceOf( RepositoryReleaseInspectionRejected::class, $exception );
			self::assertSame( 'GitHub could not inspect the selected release.', $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testPrivateInspectionKeepsCredentialResolutionProviderBoundAndLazy(): void {
		ReleaseCandidatePreflight::$inspection = new ProspectiveInspectionFixture( '1.2.3', 'theme' );
		$credentials                           = new RepositoryResolverSecretsStub(
			array( 'private-release' => 'secret-token' )
		);

		$this->provider( $credentials )->inspectRelease(
			'theme',
			new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
			'42',
			'v1.2.3',
			'prerelease'
		);

		$accessToken = ReleaseCandidatePreflight::$target['accessToken'] ?? null;
		self::assertInstanceOf( \Closure::class, $accessToken );
		self::assertSame( array(), $credentials->lookups );
		self::assertSame( 'secret-token', $accessToken() );
		self::assertSame( array( 'private-release' ), $credentials->lookups );
	}

	public function testMalformedPackageTypeEvidenceFailsClosed(): void {
		ReleaseCandidatePreflight::$inspection = new ProspectiveInspectionFixture( '1.2.3', 'theme' );

		try {
			$this->inspectPublicRelease();
			self::fail( 'Contradictory inspection evidence must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'GitHub returned invalid release inspection evidence.', $exception->getMessage() );
		}
	}

	public function testStableBuildMetadataWithAHyphenRemainsValid(): void {
		ReleaseCandidatePreflight::$inspection = new ProspectiveInspectionFixture( '1.2.3+build-7' );

		$inspection = $this->provider( new RepositoryResolverSecretsStub() )->inspectRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);

		self::assertSame( '1.2.3+build-7', $inspection->version );
	}

	public function testMismatchedInspectionIdentityFailsClosedWithRedactedEvidenceError(): void {
		ReleaseCandidatePreflight::$inspection = new class() {
			public function releaseId(): int {
				return 43;
			}

			public function tag(): string {
				return 'v1.2.3';
			}

			public function version(): string {
				return '1.2.3';
			}

			public function commit(): string {
				return str_repeat( 'a', 40 );
			}

			public function packageType(): string {
				return 'plugin';
			}

			public function packageRoot(): string {
				return 'example';
			}

			public function mainFile(): string {
				return 'example.php';
			}

			public function fingerprint(): object {
				return new class() {
					public function value(): string {
						return 'v1:' . str_repeat( 'a', 64 );
					}
				};
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub returned invalid release inspection evidence.' );
		$this->inspectPublicRelease();
	}

	private function inspectPublicRelease(): void {
		$this->provider( new RepositoryResolverSecretsStub() )->inspectRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);
	}

	private function assertOperationalFailureIsRedacted(): void {
		try {
			$this->inspectPublicRelease();
			self::fail( 'A GitHub service failure must remain operational.' );
		} catch ( RuntimeException $exception ) {
			self::assertNotInstanceOf( RepositoryReleaseInspectionRejected::class, $exception );
			self::assertSame( 'GitHub could not inspect the selected release.', $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	private function provider( RepositoryResolverSecretsStub $credentials ): RepositoryReleaseInspector {
		$provider = GitHubProvider::create(
			$credentials,
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			}
		);
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseInspector::class, $provider );

		return $provider;
	}
}

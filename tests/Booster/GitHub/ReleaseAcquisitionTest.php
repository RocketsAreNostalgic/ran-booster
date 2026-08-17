<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/WPError.php';
require_once dirname( __DIR__, 2 ) . '/Support/CoreUpdateClaimFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/ProspectiveReleaseUpdaterFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Deployment\PreparedArtifact;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveAcquisitionFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveInspectionFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
use RuntimeException;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseAcquisitionTest extends TestCase {

	private ?string $artifactPath = null;

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
	}

	protected function tearDown(): void {
		ReleaseCandidatePreflight::reset();
		if ( null !== $this->artifactPath && is_file( $this->artifactPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			unlink( $this->artifactPath );
		}
		$this->artifactPath = null;
	}

	public function testAcquiresOneExactReleaseWithoutExposingProviderCustody(): void {
		$inspection                            = new ProspectiveInspectionFixture();
		$acquisition                           = new ProspectiveAcquisitionFixture( $this->temporaryArtifact(), $inspection );
		ReleaseCandidatePreflight::$inspection = $inspection;
		ReleaseCandidatePreflight::$acquired   = $acquisition;
		$credentials                           = new RepositoryResolverSecretsStub();

		$artifact = $this->provider( $credentials )->acquireRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'v1:' . str_repeat( 'a', 64 ),
			'stable'
		);

		self::assertSame( '1.2.3', $artifact->version() );
		self::assertSame( 'example', $artifact->packageRoot() );
		self::assertSame( 'example.php', $artifact->mainFile() );
		self::assertSame( 'example/example.php', $artifact->identifier( 'plugin' ) );
		self::assertSame( 1, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 'owner/example', ReleaseCandidatePreflight::$target['repository'] );
		self::assertSame( '123456789', ReleaseCandidatePreflight::$target['providerRepositoryId'] );
		self::assertSame( 'plugin', ReleaseCandidatePreflight::$target['packageType'] );
		self::assertSame( 'stable', ReleaseCandidatePreflight::$target['channel'] );
		self::assertNull( ReleaseCandidatePreflight::$target['accessToken'] );
		self::assertSame( array(), $credentials->lookups );

		$prepared = $artifact->handoffToCore();
		self::assertInstanceOf( PreparedArtifact::class, $prepared );
		self::assertSame( str_repeat( 'a', 40 ), $prepared->getResolvedRef() );
		self::assertSame( 1, $acquisition->handoffCalls );
		self::assertFileExists( (string) $this->artifactPath );
		$prepared->cleanup();
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testInvalidFingerprintRejectsBeforeUpdaterOrCredentialWork(): void {
		$credentials = new RepositoryResolverSecretsStub(
			array( 'private-release' => 'secret-token' )
		);

		try {
			$this->provider( $credentials )->acquireRelease(
				'plugin',
				new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
				'42',
				'v1.2.3',
				'invalid-fingerprint',
				'stable'
			);
			self::fail( 'An invalid fingerprint must reject acquisition.' );
		} catch ( RepositoryReleaseAcquisitionRejected $exception ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $exception->reason );
		}

		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
		self::assertSame( array(), $credentials->lookups );
	}

	public function testInvalidReleaseFailureMapsToPurposeSpecificRejection(): void {
		ReleaseCandidatePreflight::$acquired = new \WP_Error(
			'github_updater_artifact_continuity_failed',
			'upstream-secret-message'
		);

		try {
			$this->acquirePublicRelease();
			self::fail( 'An invalid release must reject acquisition.' );
		} catch ( RepositoryReleaseAcquisitionRejected $exception ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $exception->reason );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testOperationalFailureRemainsOperationalAndRedacted(): void {
		ReleaseCandidatePreflight::$acquired = new \WP_Error(
			'github_updater_http_transport_failed',
			'upstream-secret-message'
		);

		try {
			$this->acquirePublicRelease();
			self::fail( 'An operational acquisition failure must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertNotInstanceOf( RepositoryReleaseAcquisitionRejected::class, $exception );
			self::assertSame( 'GitHub could not acquire the selected release.', $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
	}

	public function testMalformedAcquisitionEvidenceFailsClosedAndIsDiscarded(): void {
		$inspection                            = new ProspectiveInspectionFixture( '1.2.3', 'theme' );
		$acquisition                           = new ProspectiveAcquisitionFixture( $this->temporaryArtifact(), $inspection );
		ReleaseCandidatePreflight::$inspection = $inspection;
		ReleaseCandidatePreflight::$acquired   = $acquisition;

		try {
			$this->acquirePublicRelease();
			self::fail( 'Contradictory acquisition evidence must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'GitHub returned invalid release acquisition evidence.', $exception->getMessage() );
		}

		self::assertSame( 1, $acquisition->discardCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	#[DataProvider( 'malformedEvidenceCleanupFailureProvider' )]
	public function testMalformedEvidenceCleanupFailureIsReported( bool $throws ): void {
		$inspection                            = new ProspectiveInspectionFixture( '1.2.3', 'theme' );
		$acquisition                           = new ProspectiveAcquisitionFixture( $this->temporaryArtifact(), $inspection );
		$acquisition->discardResult            = false;
		$acquisition->discardThrows            = $throws;
		ReleaseCandidatePreflight::$inspection = $inspection;
		ReleaseCandidatePreflight::$acquired   = $acquisition;

		try {
			$this->acquirePublicRelease();
			self::fail( 'A failed evidence cleanup must be reported.' );
		} catch ( RepositoryReleaseAcquisitionRejected $exception ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::CLEANUP_FAILED, $exception->reason );
		}

		self::assertSame( 1, $acquisition->discardCalls );
		self::assertFileExists( (string) $this->artifactPath );
	}

	/** @return array<string, array{bool}> */
	public static function malformedEvidenceCleanupFailureProvider(): array {
		return array(
			'false result' => array( false ),
			'thrown error' => array( true ),
		);
	}

	public function testPrivateAcquisitionKeepsCredentialResolutionProviderBoundAndLazy(): void {
		$inspection                            = new ProspectiveInspectionFixture();
		ReleaseCandidatePreflight::$inspection = $inspection;
		ReleaseCandidatePreflight::$acquired   = new ProspectiveAcquisitionFixture( $this->temporaryArtifact(), $inspection );
		$credentials                           = new RepositoryResolverSecretsStub(
			array( 'private-release' => 'secret-token' )
		);

		$artifact = $this->provider( $credentials )->acquireRelease(
			'plugin',
			new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
			'42',
			'v1.2.3',
			'v1:' . str_repeat( 'a', 64 ),
			'stable'
		);

		$accessToken = ReleaseCandidatePreflight::$target['accessToken'] ?? null;
		self::assertInstanceOf( \Closure::class, $accessToken );
		self::assertSame( array(), $credentials->lookups );
		self::assertSame( 'secret-token', $accessToken() );
		self::assertSame( array( 'private-release' ), $credentials->lookups );
		self::assertTrue( $artifact->discard() );
	}

	private function acquirePublicRelease(): RepositoryReleaseArtifact {
		return $this->provider( new RepositoryResolverSecretsStub() )->acquireRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'v1:' . str_repeat( 'a', 64 ),
			'stable'
		);
	}

	private function provider( RepositoryResolverSecretsStub $credentials ): RepositoryReleaseAcquirer {
		$provider = GitHubProvider::create(
			$credentials,
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			}
		);
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseAcquirer::class, $provider );

		return $provider;
	}

	private function temporaryArtifact(): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-acquisition-' );
		if ( false === $path ) {
			throw new RuntimeException( 'The test artifact could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
		file_put_contents( $path, 'verified-release-archive' );
		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
		$this->artifactPath = $path;

		return $path;
	}
}

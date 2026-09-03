<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Deployment\PreparedArtifact;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RuntimeException;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseAcquisitionTest extends TestCase {
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	protected function tearDown(): void {
		NeutralReleaseUpdaterFixtures::cleanup();
	}

	public function testProviderCustodyTransfersOnlyThroughBoundedCoreCopy(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue( array_merge( NeutralReleaseUpdaterFixtures::proof(), NeutralReleaseUpdaterFixtures::proof() ) );

		$artifact = $provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.3', $fingerprint, 'stable' );
		self::assertSame( 'example/example.php', $artifact->identifier( 'plugin' ) );
		$providerPaths = $GLOBALS['ran_booster_release_temp_paths'];
		self::assertNotEmpty( $providerPaths );
		self::assertFileExists( $providerPaths[ count( $providerPaths ) - 1 ] );

		$prepared = $artifact->handoffToCore();
		self::assertInstanceOf( PreparedArtifact::class, $prepared );
		self::assertSame( str_repeat( 'a', 40 ), $prepared->getResolvedRef() );
		self::assertNotContains( $prepared->getPath(), $providerPaths );
		foreach ( $providerPaths as $path ) {
			self::assertFileDoesNotExist( $path );
		}
		self::assertSame( 0600, fileperms( $prepared->getPath() ) & 0777 );
		self::assertSame( 0700, fileperms( dirname( $prepared->getPath() ) ) & 0777 );
		$prepared->assertUnchanged();
		$directory = dirname( $prepared->getPath() );
		$prepared->cleanup();
		self::assertDirectoryDoesNotExist( $directory );
	}

	public function testPrivateAcquisitionResolvesCredentialForEachFreshRequestChain(): void {
		$public      = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $public, $repository );
		$credentials = new RepositoryResolverSecretsStub( array( 'private-release' => 'secret-token' ) );
		$private     = $this->provider( $credentials );
		NeutralReleaseUpdaterFixtures::queue( array_merge( NeutralReleaseUpdaterFixtures::proof(), NeutralReleaseUpdaterFixtures::proof() ) );

		$artifact = $private->acquireRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', true, 'private-release' ),
			'42',
			'v1.2.3',
			$fingerprint,
			'stable'
		);

		self::assertSame( array( 'private-release', 'private-release' ), $credentials->lookups );
		self::assertTrue( $artifact->discard() );
	}

	public function testFingerprintContinuityRejectsChangedReleaseAndCleansProviderFile(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof( version: '1.2.4', tag: 'v1.2.4' ) );

		try {
			$provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.4', $fingerprint, 'stable' );
			self::fail( 'Changed prospective evidence must reject acquisition.' );
		} catch ( RepositoryReleaseAcquisitionRejected $exception ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $exception->reason );
		}

		foreach ( $GLOBALS['ran_booster_release_temp_paths'] as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	public function testAcquisitionFailureIsRedactedAndCleansInspectionArtifact(): void {
		$provider    = $this->provider( new RepositoryResolverSecretsStub() );
		$repository  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$fingerprint = $this->fingerprint( $provider, $repository );
		NeutralReleaseUpdaterFixtures::queue(
			array_merge(
				NeutralReleaseUpdaterFixtures::proof(),
				array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) )
			)
		);

		try {
			$provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.3', $fingerprint, 'stable' );
			self::fail( 'Operational acquisition failure must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'GitHub could not acquire the selected release.', $exception->getMessage() );
		}
		foreach ( $GLOBALS['ran_booster_release_temp_paths'] as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	private function fingerprint( GitHubProvider $provider, RepositoryReference $repository ): string {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof() );

		return $provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint;
	}

	private function provider( RepositoryResolverSecretsStub $credentials ): GitHubProvider&RepositoryReleaseAcquirer {
		$provider = GitHubProvider::create(
			$credentials,
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			}
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseAcquirer::class, $provider );

		return $provider;
	}
}

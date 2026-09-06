<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RuntimeException;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ReleaseCandidateListingTest extends TestCase {
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	protected function tearDown(): void {
		NeutralReleaseUpdaterFixtures::cleanup();
	}

	public function testListsNeutralUpdaterCandidatesAndPreservesDetailsIdentity(): void {
		NeutralReleaseUpdaterFixtures::queue(
			array(
				NeutralReleaseUpdaterFixtures::listing(
					array(
						NeutralReleaseUpdaterFixtures::listedRelease( tag: 'v2.0.0-beta.2', prerelease: true, id: 52 ),
						NeutralReleaseUpdaterFixtures::listedRelease(),
					)
				),
			)
		);

		$result = $this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'prerelease'
		);

		self::assertCount( 2, $result->candidates );
		self::assertSame( '52', $result->candidates[0]->providerReleaseId );
		self::assertSame( 'v2.0.0-beta.2', $result->candidates[0]->tag );
		self::assertSame( '2.0.0-beta.2', $result->candidates[0]->version );
		self::assertTrue( $result->candidates[0]->prerelease );
		self::assertSame( array( 'example.zip' ), $result->candidates[0]->expectedAssetNames );
		self::assertStringContainsString( '/repos/owner/example/releases', NeutralReleaseUpdaterFixtures::requests()[0][0] );
	}

	public function testThemeListingUsesStableChannel(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::listing( array() ) ) );

		$result = $this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'theme',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);

		self::assertSame( array(), $result->candidates );
	}

	public function testPrivateListingResolvesProviderCredentialOnlyAtRequestTime(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::listing( array() ) ) );
		$credentials = new RepositoryResolverSecretsStub( array( 'private-release' => 'secret-token' ) );
		$provider    = $this->provider( $credentials );
		self::assertSame( array(), $credentials->lookups );

		$provider->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
			'stable'
		);

		self::assertSame( array( 'private-release' ), $credentials->lookups );
		self::assertSame( 'Bearer secret-token', NeutralReleaseUpdaterFixtures::requests()[0][1]['headers']['Authorization'] ?? null );
	}

	public function testListingInitializesTheUnconfiguredDirectFilesystemBeforeReadingTheRelease(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::listing( array() ) ) );
		self::assertArrayNotHasKey( 'wp_filesystem', $GLOBALS );

		$result = $this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);

		self::assertSame( array(), $result->candidates );
		self::assertInstanceOf( \WP_Filesystem_Direct::class, $GLOBALS['wp_filesystem'] );
	}

	public function testListingRejectsANonDirectFilesystemBeforeCredentialsOrHttp(): void {
		$GLOBALS['ran_booster_release_filesystem_method'] = 'ftpext';
		$credentials = new RepositoryResolverSecretsStub( array( 'private-release' => 'secret-token' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub release candidate listing is unavailable.' );
		try {
			$this->provider( $credentials )->listReleaseCandidates(
				'plugin',
				new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
				'stable'
			);
		} finally {
			self::assertSame( array(), $credentials->lookups );
			self::assertSame( array(), NeutralReleaseUpdaterFixtures::requests() );
		}
	}

	public function testListingRejectsACurrentNonDirectFilesystemBeforeCredentialsOrHttp(): void {
		$GLOBALS['wp_filesystem'] = new \stdClass();
		$credentials = new RepositoryResolverSecretsStub( array( 'private-release' => 'secret-token' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub release candidate listing is unavailable.' );
		try {
			$this->provider( $credentials )->listReleaseCandidates(
				'plugin',
				new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
				'stable'
			);
		} finally {
			self::assertSame( array(), $credentials->lookups );
			self::assertSame( array(), NeutralReleaseUpdaterFixtures::requests() );
		}
	}

	public function testOperationalListingFailureIsRedacted(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub returned invalid release candidates.' );
		$this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);
	}

	#[DataProvider( 'unavailableStatusProvider' )]
	public function testRepositoryAccessFailurePreservesFallbackSignal( int $status ): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( $status, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RepositoryReleaseReadUnavailable::class );
		$this->expectExceptionMessage( 'GitHub release candidate access is unavailable.' );
		$this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);
	}

	/** @return iterable<string,array{0:int}> */
	public static function unavailableStatusProvider(): iterable {
		yield 'authentication' => array( 401 );
		yield 'forbidden' => array( 403 );
		yield 'concealed or missing repository' => array( 404 );
	}

	public function testRateLimitPreservesFallbackWhileTransportFailureStaysOperational(): void {
		foreach ( array(
			NeutralReleaseUpdaterFixtures::response( 429, array(), array( 'retry-after' => '30' ) ),
			new \WP_Error( 'http_request_failed', 'upstream-secret-message' ),
		) as $failure ) {
			NeutralReleaseUpdaterFixtures::queue( array( $failure ) );
			try {
				$this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
					'plugin',
					new RepositoryReference( 'owner/example', '123456789', false, null ),
					'stable'
				);
				self::fail( 'Repository read failures must preserve the fallback signal.' );
			} catch ( \RuntimeException $exception ) {
				if ( $failure instanceof \WP_Error ) {
					self::assertNotInstanceOf( RepositoryReleaseReadUnavailable::class, $exception );
					self::assertSame( 'GitHub returned invalid release candidates.', $exception->getMessage() );
				} else {
					self::assertInstanceOf( RepositoryReleaseReadUnavailable::class, $exception );
					self::assertSame( 'GitHub release candidate access is unavailable.', $exception->getMessage() );
				}
			}
		}
	}

	private function provider( RepositoryResolverSecretsStub $credentials ): RepositoryReleaseCandidateListing {
		$provider = GitHubProvider::create(
			$credentials,
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			},
			NeutralReleaseUpdaterFixtures::registrar()
		);
		self::assertInstanceOf( RepositoryReleaseCandidateListing::class, $provider );

		return $provider;
	}
}

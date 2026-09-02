<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

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

	public function testOperationalListingFailureIsRedacted(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub release candidate listing is unavailable.' );
		$this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);
	}

	/** @dataProvider unavailableStatusProvider */
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

	public function testRateLimitAndTransportPreserveFallbackSignal(): void {
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
			} catch ( RepositoryReleaseReadUnavailable $exception ) {
				self::assertSame( 'GitHub release candidate access is unavailable.', $exception->getMessage() );
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
			}
		);
		self::assertInstanceOf( RepositoryReleaseCandidateListing::class, $provider );

		return $provider;
	}
}

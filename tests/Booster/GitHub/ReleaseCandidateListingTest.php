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
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveCandidateFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
use RuntimeException;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseCandidateListingTest extends TestCase {

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
	}

	protected function tearDown(): void {
		ReleaseCandidatePreflight::reset();
	}

	public function testListsOnlyBoundedTypedCandidateEvidenceWithoutReadingPublicCredentials(): void {
		ReleaseCandidatePreflight::$candidates = array(
			new ProspectiveCandidateFixture(
				52,
				'v2.0.0-beta.2',
				'2.0.0-beta.2',
				true,
				'2026-08-17T12:00:00Z',
				array( 'example-2.0.0-beta.2.zip' )
			),
			new ProspectiveCandidateFixture(
				42,
				'v1.2.3',
				'1.2.3',
				false,
				'2026-08-16T12:00:00Z',
				array( 'example-1.2.3.zip' )
			),
		);
		$credentials                           = new RepositoryResolverSecretsStub();
		$listing                               = $this->provider( $credentials );

		$result = $listing->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'prerelease'
		);

		self::assertCount( 2, $result->candidates );
		self::assertSame( '52', $result->candidates[0]->providerReleaseId );
		self::assertSame( 'v2.0.0-beta.2', $result->candidates[0]->tag );
		self::assertSame( '2.0.0-beta.2', $result->candidates[0]->version );
		self::assertTrue( $result->candidates[0]->prerelease );
		self::assertSame( '2026-08-17T12:00:00Z', $result->candidates[0]->publishedAt );
		self::assertSame( array( 'example-2.0.0-beta.2.zip' ), $result->candidates[0]->expectedAssetNames );
		self::assertSame( 1, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 'owner/example', ReleaseCandidatePreflight::$target['repository'] );
		self::assertSame( '123456789', ReleaseCandidatePreflight::$target['providerRepositoryId'] );
		self::assertSame( 'plugin', ReleaseCandidatePreflight::$target['packageType'] );
		self::assertSame( 'prerelease', ReleaseCandidatePreflight::$target['channel'] );
		self::assertNull( ReleaseCandidatePreflight::$target['accessToken'] );
		self::assertSame( array(), $credentials->lookups );
	}

	public function testNoEligibleReleaseIsTheOnlyUpstreamFailureMappedToAnEmptyList(): void {
		ReleaseCandidatePreflight::$candidates = new \WP_Error(
			'github_updater_no_eligible_release',
			'No release is eligible.'
		);

		$result = $this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
			'theme',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);

		self::assertSame( array(), $result->candidates );
		self::assertSame( 1, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 'theme', ReleaseCandidatePreflight::$target['packageType'] );
		self::assertSame( 'stable', ReleaseCandidatePreflight::$target['channel'] );
	}

	public function testOtherUpstreamFailuresDoNotExposeTheirMessage(): void {
		ReleaseCandidatePreflight::$candidates = new \WP_Error(
			'github_updater_operation_failed',
			'upstream-secret-message'
		);

		try {
			$this->provider( new RepositoryResolverSecretsStub() )->listReleaseCandidates(
				'plugin',
				new RepositoryReference( 'owner/example', '123456789', false, null ),
				'stable'
			);
			self::fail( 'Operational release-listing failures must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'GitHub could not list release candidates.', $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-secret-message', $exception->getMessage() );
		}
		self::assertSame( 1, ReleaseCandidatePreflight::$listCalls );
	}

	public function testPrivateListingKeepsCredentialResolutionProviderBoundAndLazy(): void {
		ReleaseCandidatePreflight::$candidates = array();
		$credentials                           = new RepositoryResolverSecretsStub(
			array( 'private-release' => 'secret-token' )
		);

		$this->provider( $credentials )->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/private-example', '123456789', true, 'private-release' ),
			'stable'
		);

		$accessToken = ReleaseCandidatePreflight::$target['accessToken'] ?? null;
		self::assertInstanceOf( \Closure::class, $accessToken );
		self::assertSame( array(), $credentials->lookups );
		self::assertSame( 'secret-token', $accessToken() );
		self::assertSame( array( 'private-release' ), $credentials->lookups );
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
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseCandidateListing::class, $provider );

		return $provider;
	}
}

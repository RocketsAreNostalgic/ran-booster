<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RuntimeException;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ReleaseInspectionTest extends TestCase {
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	protected function tearDown(): void {
		NeutralReleaseUpdaterFixtures::cleanup();
	}

	public function testMapsExactNeutralInspectionWithoutExposingProviderPath(): void {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof() );
		$provider   = $this->provider();
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		$result = $provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' );

		self::assertSame( '42', $result->providerReleaseId );
		self::assertSame( 'v1.2.3', $result->tag );
		self::assertSame( '1.2.3', $result->version );
		self::assertSame( str_repeat( 'a', 40 ), $result->providerCommitId );
		self::assertSame( 'example', $result->packageRoot );
		self::assertSame( 'example.php', $result->mainFile );
		self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $result->fingerprint );
		self::assertSame( 'https://github.com/owner/example/releases/tag/v1.2.3', $provider->releaseDetailsUrl( $repository, $result->tag ) );
		foreach ( NeutralReleaseUpdaterFixtures::requests() as $request ) {
			self::assertStringNotContainsString( sys_get_temp_dir(), $request[0] );
		}
	}

	public function testInspectsThemeIdentityThroughTheSameService(): void {
		NeutralReleaseUpdaterFixtures::queue( NeutralReleaseUpdaterFixtures::proof( 'theme' ) );

		$result = $this->provider()->inspectRelease(
			'theme',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);

		self::assertSame( 'example', $result->packageRoot );
		self::assertSame( 'style.css', $result->mainFile );
	}

	public function testOpaqueCoreIdentityIsRejectedByTheGitHubServiceWithoutHttp(): void {
		try {
			$this->provider()->inspectRelease(
				'plugin',
				new RepositoryReference( 'owner/example', '123456789', false, null ),
				'release:opaque/42',
				'v1.2.3',
				'stable'
			);
			self::fail( 'GitHub must reject its non-decimal release identity.' );
		} catch ( RepositoryReleaseInspectionRejected $exception ) {
			self::assertSame( RepositoryReleaseInspectionRejected::INVALID_RELEASE, $exception->reason );
		}

		self::assertSame( array(), NeutralReleaseUpdaterFixtures::requests() );
	}

	public function testOperationalInspectionFailureIsRedacted(): void {
		NeutralReleaseUpdaterFixtures::queue( array( NeutralReleaseUpdaterFixtures::response( 500, array( 'message' => 'upstream-secret-message' ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'GitHub could not inspect the selected release.' );
		$this->provider()->inspectRelease(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'42',
			'v1.2.3',
			'stable'
		);
	}

	private function provider(): RepositoryReleaseInspector&GitHubProvider {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			}
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( RepositoryReleaseInspector::class, $provider );

		return $provider;
	}
}

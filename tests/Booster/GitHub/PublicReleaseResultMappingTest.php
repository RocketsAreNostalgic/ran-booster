<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Narrow structural public-source fixtures belong with mapping cases.

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class PublicReleaseResultMappingTest extends TestCase {
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	public function testMapsPublicSuccessAndAcquiresWithoutAnExtraInspection(): void {
		$source     = new PublicReleaseSourceFixture(
			$this->listing(),
			$this->envelope( true, 'release_inspected', $this->facts(), 'complete' ),
			$this->envelope(
				true,
				'release_acquired',
				array(
					'inspection' => $this->facts(),
					'artifact'   => new PublicReleaseArtifactFixture( $this->archive() ),
				),
				'retained'
			)
		);
		$provider   = $this->provider( $source );
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		self::assertCount( 1, $provider->listReleaseCandidates( 'plugin', $repository, 'stable' )->candidates );
		self::assertSame( 'v2:' . str_repeat( 'b', 64 ), $provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint );
		$provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' )->discard();
		self::assertSame( 1, $source->inspectCalls );
		self::assertSame( 1, $source->acquireCalls );
	}

	public function testRejectsV1BeforeThePublicSourceAndMapsCleanupBeforeFailure(): void {
		$source     = new PublicReleaseSourceFixture( $this->listing(), $this->envelope( true, 'release_inspected', $this->facts(), 'complete' ), $this->envelope( false, 'release_changed', null, 'failed' ) );
		$provider   = $this->provider( $source );
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		try {
			$provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.3', 'v1:' . str_repeat( 'b', 64 ), 'stable' );
			self::fail( 'v1 was accepted.' );
		} catch ( RepositoryReleaseAcquisitionRejected $failure ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::INVALID_RELEASE, $failure->reason );
			self::assertSame( 0, $source->acquireCalls ); }
		try {
			$provider->acquireRelease( 'plugin', $repository, '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' );
			self::fail( 'failed cleanup was accepted.' );
		} catch ( RepositoryReleaseAcquisitionRejected $failure ) {
			self::assertSame( RepositoryReleaseAcquisitionRejected::CLEANUP_FAILED, $failure->reason ); }
	}

	public function testRateLimitWithNullRetryUsesExistingReadFallback(): void {
		$provider = $this->provider( new PublicReleaseSourceFixture( $this->envelope( false, 'rate_limited', null ), $this->envelope( true, 'release_inspected', $this->facts(), 'complete' ), $this->envelope( false, 'operation_failed', null ) ) );
		$this->expectException( RepositoryReleaseReadUnavailable::class );
		$provider->listReleaseCandidates( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), 'stable' );
	}

	public function testRejectsNonBooleanSuccessAndMismatchedInspectionFacts(): void {
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );
		$provider   = $this->provider(
			new PublicReleaseSourceFixture(
				array(
					'ok'             => 'true',
					'code'           => 'releases_listed',
					'value'          => array(),
					'retry_after'    => null,
					'cleanup_status' => 'not_applicable',
				),
				$this->envelope( true, 'release_inspected', array_replace( $this->facts(), array( 'channel' => 'preview' ) ), 'complete' ),
				$this->envelope( false, 'operation_failed', null )
			)
		);

		try {
			$provider->listReleaseCandidates( 'plugin', $repository, 'stable' );
			self::fail( 'A non-boolean success result was accepted.' );
		} catch ( \RuntimeException ) {
			self::addToAssertionCount( 1 );
		}
		$provider = $this->provider(
			new PublicReleaseSourceFixture(
				$this->listing(),
				$this->envelope( true, 'release_inspected', array_replace( $this->facts(), array( 'channel' => 'preview' ) ), 'complete' ),
				$this->envelope( false, 'operation_failed', null )
			)
		);
		$this->expectException( \RuntimeException::class );
		$provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' );
	}

	public function testDiscardsMalformedRetainedAcquisitionBeforeRejectingIt(): void {
		$fixture  = new PublicReleaseArtifactFixture( $this->archive() );
		$provider = $this->provider(
			new PublicReleaseSourceFixture(
				$this->listing(),
				$this->envelope( true, 'release_inspected', $this->facts(), 'complete' ),
				$this->envelope(
					true,
					'release_acquired',
					array(
						'inspection' => $this->facts(),
						'artifact'   => $fixture,
						'unexpected' => true,
					),
					'retained'
				)
			)
		);

		$this->expectException( \RuntimeException::class );
		$provider->acquireRelease( 'plugin', new RepositoryReference( 'owner/example', '123456789', false, null ), '42', 'v1.2.3', 'v2:' . str_repeat( 'b', 64 ), 'stable' );
	}

	private function provider( PublicReleaseSourceFixture $source ): GitHubProvider {
		return GitHubProvider::create( new RepositoryResolverSecretsStub(), new EmptyAuthenticatedWebhookDeliveryEvidenceReader(), new PublicReleaseRegistrarFixture( $source ) ); }
	private function envelope( bool $ok, string $code, mixed $value, string $cleanup = 'not_applicable' ): array {
		return array(
			'ok'             => $ok,
			'code'           => $code,
			'value'          => $value,
			'retry_after'    => null,
			'cleanup_status' => $cleanup,
		); }
	private function listing(): array {
		return $this->envelope(
			true,
			'releases_listed',
			array(
				'candidates'   => array(
					array(
						'release_identity'     => '42',
						'tag'                  => 'v1.2.3',
						'version'              => '1.2.3',
						'prerelease'           => false,
						'published_at'         => '2026-09-06T12:00:00Z',
						'expected_asset_names' => array( 'example.zip' ),
						'details_url'          => 'https://github.com/owner/example/releases/tag/v1.2.3',
					),
				),
				'not_modified' => false,
			)
		); }
	private function archive(): string {
		$path = tempnam( sys_get_temp_dir(), 'p3-mapping-' );
		file_put_contents( $path, 'protocol3-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact temporary structural-artifact fixture.
		return $path; }
	private function facts(): array {
		return array(
			'release_identity'       => '42',
			'tag'                    => 'v1.2.3',
			'version'                => '1.2.3',
			'commit_identity'        => str_repeat( 'a', 40 ),
			'package_root'           => 'example',
			'main_file'              => 'example.php',
			'fingerprint'            => 'v2:' . str_repeat( 'b', 64 ),
			'target_type'            => 'plugin',
			'channel'                => 'stable',
			'canonical_update_uri'   => 'https://github.com/owner/example',
			'repository_locator'     => 'owner/example',
			'repository_identity'    => '123456789',
			'maximum_artifact_bytes' => 52428800,
			'artifact_size'          => 15,
			'artifact_sha256'        => hash( 'sha256', 'protocol3-bytes' ),
		); }
}

final class PublicReleaseRegistrarFixture {
	public function __construct( private PublicReleaseSourceFixture $source ) {} public function releases( mixed ...$arguments ): object {
		return $this->source; }
}
final class PublicReleaseSourceFixture {
	public int $inspectCalls = 0;
	public int $acquireCalls = 0;
	public function __construct( private array $list, private array $inspect, private array $acquire ) {} public function list(): array {
		return $this->list;
	} public function inspect( string $id, string $tag ): array {
		++$this->inspectCalls;
		return $this->inspect;
	} public function acquire( string $id, string $tag, string $fingerprint ): array {
		++$this->acquireCalls;
		return $this->acquire; }
}
final class PublicReleaseArtifactFixture {
	public function __construct( private string $path ) {} public function inspect( callable $reader ): mixed {
		return $reader( $this->path );
	} public function discard(): bool {
		if ( is_file( $this->path ) ) {
			unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact temporary structural-artifact fixture.
		} return ! file_exists( $this->path ); }
}

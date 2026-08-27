<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi2Fixture.php';

final class TemplatePackRepositoryClientTest extends TestCase {

	public function testSelectsHighestCompatibleStableImmutablePackAndReportsNewerApi(): void {
		$compatibleManifest   = TemplatePackApi2Fixture::manifest();
		$compatibleArchive    = TemplatePackApi2Fixture::archive( $compatibleManifest );
		$incompatibleManifest = $this->manifestIdentity( TemplatePackApi2Fixture::manifest( 3, '2.0.0' ), 42, 'v2.0.0' );
		$incompatibleArchive  = TemplatePackApi2Fixture::archive( $incompatibleManifest );

		$compatible   = $this->release( 41, 'v1.2.3', $compatibleArchive );
		$incompatible = $this->release( 42, 'v2.0.0', $incompatibleArchive );
		$draft        = $this->release( 43, 'v9.0.0', $compatibleArchive, draft: true );
		$prerelease   = $this->release( 44, 'v8.0.0', $compatibleArchive, prerelease: true );
		$mutable      = $this->release( 45, 'v7.0.0', $compatibleArchive, immutable: false );
		$transport    = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi2Fixture::REPOSITORY,
					)
				),
				$this->response( 200, array( $draft, $prerelease, $mutable, $compatible, $incompatible ) ),
				$this->response( 200, $incompatible ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $incompatibleArchive ),
				$this->response( 200, $compatible ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $compatibleArchive ),
			)
		);
		$client       = $this->client( $transport );

		$result = $client->discover();

		self::assertSame( 'ok', $result['code'] );
		self::assertTrue( $result['newer_incompatible'] );
		self::assertSame( '1.2.3', $result['pack']->packVersion() );
		self::assertSame( TemplatePackApi2Fixture::REPOSITORY_ID, $result['pack']->identity()['repository_id'] );
		self::assertCount( 10, $transport->requests );
		self::assertStringEndsWith( '/releases/42', $transport->requests[2]['url'] );
		self::assertStringEndsWith( '/releases/41', $transport->requests[6]['url'] );
		self::assertSame( 'application/octet-stream', $transport->requests[5]['args']['headers']['Accept'] );
		self::assertSame( 3, $transport->requests[5]['args']['redirection'] );
		self::assertTrue( $transport->requests[5]['args']['reject_unsafe_urls'] );
		self::assertArrayNotHasKey( 'Authorization', $transport->requests[5]['args']['headers'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'GET', $request['method'] );
			self::assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		}
	}

	public function testExactRefetchRequiresEveryPinnedReleaseAndAssetIdentity(): void {
		$archive              = TemplatePackApi2Fixture::archive();
		$release              = $this->release( 41, 'v1.2.3', $archive );
		$transport            = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi2Fixture::REPOSITORY,
					)
				),
				$this->response( 200, $release ),
				$this->response( 200, $release ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $archive ),
			)
		);
		$identity             = TemplatePackApi2Fixture::identity( $archive );
		$identity['asset_id'] = $release['assets'][0]['id'];

		$result = $this->client( $transport )->exact( $identity );

		self::assertSame( 'ok', $result['code'] );
		self::assertSame( $identity, $result['pack']->identity() );

		$missing = $identity;
		unset( $missing['tag_target'] );
		$missingTransport = new TemplatePackScriptedTransport( array() );
		self::assertSame( 'template_pack_changed', $this->client( $missingTransport )->exact( $missing )['code'] );
		self::assertCount( 0, $missingTransport->requests );

		$changed             = $identity;
		$changed['asset_id'] = 999;
		$changedTransport    = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi2Fixture::REPOSITORY,
					)
				),
				$this->response( 200, $release ),
			)
		);
		self::assertSame(
			'template_pack_changed',
			$this->client( $changedTransport )->exact( $changed )['code']
		);
	}

	public function testAuthenticatedRequestsSendTheOperationTokenForJsonAndAssetReads(): void {
		$archive   = TemplatePackApi2Fixture::archive();
		$release   = $this->release( 41, 'v1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repositoryResponse(),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $archive ),
			)
		);

		self::assertSame( 'ok', $this->client( $transport )->discover( 'operation-token' )['code'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
		self::assertSame( 'application/octet-stream', $transport->requests[5]['args']['headers']['Accept'] );
	}

	public function testRepositoryAndAssetDigestMismatchesFailClosed(): void {
		$wrongRepository = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => '123',
						'full_name' => TemplatePackApi2Fixture::REPOSITORY,
					)
				),
			)
		);
		self::assertSame( 'template_pack_changed', $this->client( $wrongRepository )->discover()['code'] );
		self::assertCount( 1, $wrongRepository->requests );

		$archive                        = TemplatePackApi2Fixture::archive();
		$release                        = $this->release( 41, 'v1.2.3', $archive );
		$release['assets'][0]['digest'] = 'sha256:' . str_repeat( '0', 64 );
		$transport                      = new TemplatePackScriptedTransport(
			array(
				$this->response(
					200,
					array(
						'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
						'full_name' => TemplatePackApi2Fixture::REPOSITORY,
					)
				),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $archive ),
			)
		);
		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
	}

	public function testMalformedHigherStableReleaseAndDuplicateVersionRefuseFallback(): void {
		$archive             = TemplatePackApi2Fixture::archive();
		$older               = $this->release( 41, 'v1.2.3', $archive );
		$malformed           = $this->release( 42, 'v2.0.0', $archive );
		$malformed['assets'] = array();
		$transport           = new TemplatePackScriptedTransport(
			array(
				$this->repositoryResponse(),
				$this->response( 200, array( $older, $malformed ) ),
			)
		);

		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
		self::assertCount( 2, $transport->requests );

		$duplicate = $this->release( 43, '1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repositoryResponse(),
				$this->response( 200, array( $older, $duplicate ) ),
			)
		);

		self::assertSame( 'template_pack_invalid', $this->client( $transport )->discover()['code'] );
		self::assertCount( 2, $transport->requests );
	}

	public function testHistoricalApi1PackIsRefusedWithoutFallbackOrAdapter(): void {
		$manifest  = TemplatePackApi2Fixture::manifest( 1 );
		$archive   = TemplatePackApi2Fixture::archive( $manifest );
		$release   = $this->release( 41, 'v1.2.3', $archive );
		$transport = new TemplatePackScriptedTransport(
			array(
				$this->repositoryResponse(),
				$this->response( 200, array( $release ) ),
				$this->response( 200, $release ),
				$this->tagResponse(),
				$this->response( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) ),
				$this->binaryResponse( 200, $archive ),
			)
		);

		self::assertSame( 'template_pack_incompatible', $this->client( $transport )->discover()['code'] );
		self::assertCount( 6, $transport->requests );
	}

	public function testExactRefetchKeepsRemoteUnavailabilityDistinctFromIdentityDrift(): void {
		$archive              = TemplatePackApi2Fixture::archive();
		$release              = $this->release( 41, 'v1.2.3', $archive );
		$identity             = TemplatePackApi2Fixture::identity( $archive );
		$identity['asset_id'] = $release['assets'][0]['id'];
		$transport            = new TemplatePackScriptedTransport(
			array(
				$this->repositoryResponse(),
				$this->response( 200, $release ),
				$this->response( 503, array() ),
			)
		);

		self::assertSame( 'template_pack_unavailable', $this->client( $transport )->exact( $identity )['code'] );
		self::assertCount( 3, $transport->requests );
	}

	private function client( TemplatePackScriptedTransport $transport ): TemplatePackRepositoryClient {
		return new TemplatePackRepositoryClient( $transport );
	}

	/** @return array<string, mixed> */
	private function release( int $id, string $tag, string $archive, bool $draft = false, bool $prerelease = false, bool $immutable = true ): array {
		return array(
			'id'               => $id,
			'tag_name'         => $tag,
			'target_commitish' => TemplatePackApi2Fixture::COMMIT,
			'draft'            => $draft,
			'prerelease'       => $prerelease,
			'immutable'        => $immutable,
			'assets'           => array(
				array(
					'id'           => TemplatePackApi2Fixture::ASSET_ID + $id,
					'name'         => TemplatePackApi2Fixture::ASSET_NAME,
					'size'         => strlen( $archive ),
					'state'        => 'uploaded',
					'content_type' => 'application/zip',
					'digest'       => 'sha256:' . hash( 'sha256', $archive ),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function tagResponse( string $sha = TemplatePackApi2Fixture::COMMIT, string $type = 'commit' ): array {
		return $this->response(
			200,
			array(
				'object' => array(
					'type' => $type,
					'sha'  => $sha,
				),
			)
		);
	}

	/** @return array<string, mixed> */
	private function repositoryResponse(): array {
		return $this->response(
			200,
			array(
				'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
				'full_name' => TemplatePackApi2Fixture::REPOSITORY,
			)
		);
	}

	/** @param array<string, mixed> $manifest @return array<string, mixed> */
	private function manifestIdentity( array $manifest, int $releaseId, string $tag ): array {
		$manifest['release']['id']  = $releaseId;
		$manifest['release']['tag'] = $tag;

		return $manifest;
	}

	/** @return array<string, mixed> */
	private function response( int $status, array $body ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport requires throwing deterministic JSON encoding.
		return $this->binaryResponse( $status, (string) json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	}

	/** @return array<string, mixed> */
	private function binaryResponse( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}
}

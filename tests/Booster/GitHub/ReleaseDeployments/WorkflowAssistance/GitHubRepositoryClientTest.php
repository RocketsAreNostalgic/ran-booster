<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use function RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\wp_json_encode;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

final class GitHubRepositoryClientTest extends TestCase {
	private const REPOSITORY = 'owner/example-plugin';
	private const SHA        = '0123456789abcdef0123456789abcdef01234567';
	private const TREE       = '1111111111111111111111111111111111111111';
	private const BLOB       = '2222222222222222222222222222222222222222';

	public function testExactRepositoryBranchCommitAndSnapshotReadsAreBounded(): void {
		$header    = "<?php\n/** Plugin Name: Example\n * Version: 1.2.3\n */\n";
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'id'             => 101,
						'full_name'      => self::REPOSITORY,
						'default_branch' => 'main',
					)
				),
				$this->response(
					200,
					array(
						'ref'    => 'refs/heads/main',
						'object' => array( 'sha' => self::SHA ),
					)
				),
				$this->response(
					200,
					array(
						'sha'     => self::SHA,
						'tree'    => array( 'sha' => self::TREE ),
						'parents' => array(),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array(
							array(
								'path' => 'example.php',
								'type' => 'blob',
								'mode' => '100644',
								'sha'  => self::BLOB,
								'size' => strlen( $header ),
							),
						),
					)
				),
				$this->response(
					200,
					array(
						'encoding' => 'base64',
						'size'     => strlen( $header ),
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
						'content'  => base64_encode( $header ),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertSame( '101', $client->repository( self::REPOSITORY )['repository_id'] );
		self::assertSame( self::SHA, $client->branchRef( self::REPOSITORY, 'main' )['sha'] );
		self::assertSame( self::TREE, $client->gitCommit( self::REPOSITORY, self::SHA )['tree_sha'] );
		$snapshot = $client->snapshot( self::REPOSITORY, '101', 'main', self::SHA );
		self::assertSame( $header, $snapshot['snapshot']->document( 'example.php' ) );
		self::assertSame( array( 'GET', 'GET', 'GET', 'GET', 'GET' ), array_column( $transport->requests, 'method' ) );
	}

	public function testSnapshotRejectsDuplicateTruncatedUnsafeAndNonTextEvidence(): void {
		$entry     = array(
			'path' => 'example.php',
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => self::BLOB,
			'size' => 4,
		);
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'truncated' => true,
						'tree'      => array(),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( $entry, $entry ),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( array_replace( $entry, array( 'path' => '../bad' ) ) ),
					)
				),
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => array( $entry ),
					)
				),
				$this->response(
					200,
					array(
						'encoding' => 'base64',
						'size'     => 4,
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
						'content'  => base64_encode( "a\0bc" ),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		for ( $index = 0; $index < 4; ++$index ) {
			self::assertSame( 'invalid_response', $client->snapshot( self::REPOSITORY, '101', 'main', self::SHA )['code'] );
		}
	}

	public function testSnapshotReadsExactAlternateAutomationEvidenceAndSkipsUnrelatedDocuments(): void {
		$documents = array(
			'example.php'                       => "<?php\n/** Plugin Name: Example */\n",
			'.github/workflows/ci.yml'          => "steps:\n  - run: composer check\n",
			'scripts/package.sh'                => "#!/bin/sh\ngh release view v1.2.3\n",
			'.github/scripts/release.sh'        => "#!/bin/sh\nprintf release\n",
			'.ci/publish.sh'                    => "#!/bin/sh\nprintf publish\n",
			'composer.json'                     => '{}',
			'Makefile'                          => "check:\n\tcomposer check\n",
			'config/release-please-config.json' => '{}',
			'docs/release.sh'                   => "#!/bin/sh\ngh release create v1\n",
		);
		$tree      = array();
		$responses = array();
		foreach ( $documents as $path => $content ) {
			$tree[] = array(
				'path' => $path,
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $content ),
			);
		}
		$responses[] = $this->response(
			200,
			array(
				'truncated' => false,
				'tree'      => $tree,
			)
		);
		foreach ( $documents as $path => $content ) {
			if ( in_array( $path, array( 'config/release-please-config.json', 'docs/release.sh' ), true ) ) {
				continue;
			}
			$responses[] = $this->response(
				200,
				array(
					'encoding' => 'base64',
					'size'     => strlen( $content ),
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
					'content'  => base64_encode( $content ),
				)
			);
		}

		$transport = new D23GitHubTransport( $responses );
		$result    = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA );
		self::assertSame( 'ok', $result['code'] );
		self::assertSame(
			array( '.ci/publish.sh', '.github/scripts/release.sh', '.github/workflows/ci.yml', 'Makefile', 'composer.json', 'example.php', 'scripts/package.sh' ),
			$result['snapshot']->documentPaths()
		);
		self::assertCount( 8, $transport->requests );
	}

	public function testSnapshotRefusesMoreThanTheBoundedAdmissionDocumentSetBeforeBlobReads(): void {
		$tree = array();
		for ( $index = 0; $index < 257; ++$index ) {
			$tree[] = array(
				'path' => '.github/workflows/check-' . $index . '.yml',
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( 'workflow-' . $index ),
				'size' => 1,
			);
		}
		$transport = new D23GitHubTransport(
			array(
				$this->response(
					200,
					array(
						'truncated' => false,
						'tree'      => $tree,
					)
				),
			)
		);
		$result    = ( new GitHubRepositoryClient( $transport ) )->snapshot( self::REPOSITORY, '101', 'main', self::SHA );

		self::assertSame( 'invalid_response', $result['code'] );
		self::assertCount( 1, $transport->requests );
	}

	public function testGitObjectDraftAndRefWritesHaveNoUpdateMergeOrSecretAuthority(): void {
		$pull      = $this->pull( 17, 'open', 'ran-booster/setup', 'main', self::SHA );
		$transport = new D23GitHubTransport(
			array(
				$this->response( 201, array( 'sha' => self::BLOB ) ),
				$this->response( 201, array( 'sha' => self::TREE ) ),
				$this->response( 201, array( 'sha' => self::SHA ) ),
				$this->response(
					201,
					array(
						'ref'    => 'refs/heads/ran-booster/setup',
						'object' => array( 'sha' => self::SHA ),
					)
				),
				$this->response( 201, $pull ),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertSame( 'ok', $client->createBlob( self::REPOSITORY, 'bytes', 'secret-token' )['code'] );
		self::assertSame(
			'ok',
			$client->createTree(
				self::REPOSITORY,
				self::TREE,
				array(
					array(
						'path' => 'file.php',
						'sha'  => self::BLOB,
						'mode' => '100644',
					),
				),
				'secret-token'
			)['code']
		);
		self::assertSame( 'ok', $client->createCommit( self::REPOSITORY, self::TREE, self::BLOB, 'chore: exact', 'secret-token' )['code'] );
		self::assertSame( 'ok', $client->createRef( self::REPOSITORY, 'ran-booster/setup', 'main', self::SHA, 'secret-token' )['code'] );
		self::assertSame( 'ok', $client->createDraftPullRequest( self::REPOSITORY, 'ran-booster/setup', 'main', 'Title', 'Body', 'secret-token' )['code'] );
		foreach ( $transport->requests as $request ) {
			self::assertSame( 'Bearer secret-token', $request['args']['headers']['Authorization'] );
			self::assertStringNotContainsString( 'secret-token', (string) ( $request['args']['body'] ?? '' ) );
		}
		self::assertSame( array( 'POST', 'POST', 'POST', 'POST', 'POST' ), array_column( $transport->requests, 'method' ) );
		self::assertTrue( $this->body( $transport, 4 )['draft'] );
	}

	public function testPullReadbackAndFileSetAreExactSortedAndBounded(): void {
		$transport = new D23GitHubTransport(
			array(
				$this->response( 200, array( $this->pull( 17, 'open', 'ran-booster/setup', 'main', self::SHA ) ) ),
				$this->response( 200, $this->pull( 17, 'closed', 'ran-booster/setup', 'main', self::SHA, 'now' ) ),
				$this->response(
					200,
					array(
						array(
							'filename' => 'z.php',
							'status'   => 'added',
							'sha'      => self::BLOB,
						),
						array(
							'filename' => 'a.php',
							'status'   => 'modified',
							'sha'      => self::SHA,
						),
					)
				),
			)
		);
		$client    = new GitHubRepositoryClient( $transport );
		self::assertFalse( $client->pullRequests( self::REPOSITORY, 'ran-booster/setup' )['pulls'][0]['merged'] );
		self::assertTrue( $client->pullRequest( self::REPOSITORY, 17 )['pull']['merged'] );
		$files = $client->pullRequestFileSet( self::REPOSITORY, 17 );
		self::assertSame( array( 'a.php', 'z.php' ), array_column( $files['files'], 'path' ) );
		self::assertStringEndsWith( '/pulls/17/files?per_page=100', $transport->requests[2]['url'] );
	}

	public function testMalformedInputsResponsesAndConflictsFailClosed(): void {
		$wrong                              = $this->pull( 17, 'open', 'setup', 'main', self::SHA );
		$wrong['head']['repo']['full_name'] = 'owner/other';
		$transport                          = new D23GitHubTransport( array( $this->response( 200, $wrong ), $this->response( 422, array() ), $this->response( 422, array() ) ) );
		$client                             = new GitHubRepositoryClient( $transport );
		self::assertSame( 'invalid_response', $client->pullRequest( self::REPOSITORY, 17 )['code'] );
		self::assertSame( 'invalid_request', $client->createBlob( self::REPOSITORY, "bad\0bytes", 'token' )['code'] );
		self::assertSame( 'invalid_request', $client->createRef( self::REPOSITORY, 'main', 'main', self::SHA, 'token' )['code'] );
		self::assertSame( 'conflict', $client->createRef( self::REPOSITORY, 'setup', 'main', self::SHA, 'token' )['code'] );
		self::assertSame( 'conflict', $client->createDraftPullRequest( self::REPOSITORY, 'setup', 'main', 'Title', 'Body', 'token' )['code'] );
		self::assertSame( 'invalid_request', $client->repository( '../unsafe', "bad\ntoken" )['code'] );
	}

	/** @return array<string,mixed> */
	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}
	/** @return array<string,mixed> */
	private function pull( int $number, string $state, string $head, string $base, string $sha, ?string $merged = null ): array {
		return array(
			'number'    => $number,
			'state'     => $state,
			'draft'     => true,
			'merged_at' => $merged,
			'head'      => array(
				'ref'  => $head,
				'sha'  => $sha,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
			'base'      => array(
				'ref'  => $base,
				'sha'  => self::SHA,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
		);
	}
	/** @return array<string,mixed> */
	private function body( D23GitHubTransport $transport, int $index ): array {
		$body = json_decode( (string) $transport->requests[ $index ]['args']['body'], true );
		self::assertIsArray( $body );
		return $body;
	}
}

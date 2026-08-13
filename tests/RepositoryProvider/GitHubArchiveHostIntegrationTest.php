<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once dirname( __DIR__ ) . '/Booster/GitHub/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\StaleDeployment;
use RuntimeException;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubArchiveHostIntegrationTest extends TestCase {

	private const TOKEN = 'github-resolution-token-canary';

	protected function setUp(): void {
		parent::setUp();

		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => 987654321,
					'full_name'      => 'RocketsAreNostalgic/ran-booster',
					'private'        => false,
					'default_branch' => 'main',
				)
			)
		);
	}

	protected function tearDown(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();

		parent::tearDown();
	}

	public function testWebhookCommitChecksCurrentPublicBranchBeforePreparingImmutableArchive(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse(),
				$this->response(
					200,
					array(
						'name'   => 'release/candidate',
						'commit' => array( 'sha' => strtoupper( $commit ) ),
					)
				),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub();
		$provider = $this->provider( $secrets );
		$archive  = $provider->prepareArchive(
			$this->archiveRequest( $commit, false, null, 'release/candidate' )
		);
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/branches/release%2Fcandidate',
			$requests[1]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertArrayNotHasKey( 'Authorization', $requests[1]['arguments']['headers'] );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->getUrl()
		);
		self::assertSame( array(), $secrets->lookups );
		$this->assertNoArchiveHooks();
		$archive->cleanup();
	}

	public function testPrivateWebhookHeadResolutionUsesSelectedCredentialBeforeArchiveAuthentication(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse( true ),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $commit ),
					)
				),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );
		$archive  = $provider->prepareArchive(
			$this->archiveRequest( $commit, true, 'private-profile', 'main' )
		);
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'Bearer ' . self::TOKEN, $requests[1]['arguments']['headers']['Authorization'] );
		self::assertSame( array( 'private-profile', 'private-profile', 'private-profile' ), $secrets->lookups );
		self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ) );
		self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ) );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testStaleWebhookCommitFailsBeforeArchiveAuthenticationIsRegistered(): void {
		$current = '0123456789abcdef0123456789abcdef01234567';
		$stale   = '89abcdef0123456789abcdef0123456789abcdef';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse( true ),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $current ),
					)
				),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepareArchive( $this->archiveRequest( $stale, true, 'private-profile', 'main' ) );
			self::fail( 'A delayed GitHub webhook commit must not replace the current branch head.' );
		} catch ( StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
			self::assertSame( 'The GitHub deployment event is stale because the configured branch has moved.', $exception->getMessage() );
		}

		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	public function testRepositoryIdentityMismatchFailsBeforeBranchLookupOrArchiveAuthentication(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array( $this->repositoryIdentityResponse( true, 'different-repository-id' ) )
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepareArchive(
				$this->archiveRequest(
					'0123456789abcdef0123456789abcdef01234567',
					true,
					'private-profile',
					'main'
				)
			);
			self::fail( 'A repository identity mismatch must prevent deployment.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 502, $exception->getCode() );
			self::assertSame( 'GitHub returned an invalid repository identity while resolving the branch.', $exception->getMessage() );
		}

		self::assertCount( 1, \RAN\Booster\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	#[DataProvider( 'branchHeadFailureProvider' )]
	public function testBranchHeadFailuresAreExplicitAndNeverPrepareArchiveAuthentication(
		mixed $response,
		int $expectedCode,
		?int $retryAfterSeconds = null
	): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array( $this->repositoryIdentityResponse( true ), $response )
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepareArchive(
				$this->archiveRequest(
					'0123456789abcdef0123456789abcdef01234567',
					true,
					'private-profile',
					'main'
				)
			);
			self::fail( 'A failed GitHub branch-head lookup must prevent deployment.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expectedCode, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
		}

		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	/**
	 * @return array<string, array{mixed, int, int|null}>
	 */
	public static function branchHeadFailureProvider(): array {
		return array(
			'transport error'    => array( new \RAN\Booster\GitHub\RepositoryResolverWpError( 'http_request_failed' ), 502, null ),
			'blocked transport'  => array( new \RAN\Booster\GitHub\RepositoryResolverWpError( 'http_request_not_executed' ), 502, null ),
			'local policy error' => array( new \RAN\Booster\GitHub\RepositoryResolverWpError( 'local_policy_canary' ), 502, null ),
			'no transport'       => array( new \RAN\Booster\GitHub\RepositoryResolverWpError( 'http_failure' ), 502, null ),
			'rate limit'         => array(
				array(
					'response' => array( 'code' => 429 ),
					'headers'  => array( 'Retry-After' => '12' ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				429,
				null,
			),
			'bad gateway'        => array(
				array(
					'response' => array( 'code' => 502 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'temporary service'  => array(
				array(
					'response' => array( 'code' => 503 ),
					'headers'  => array( 'Retry-After' => '1200' ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'gateway timeout'    => array(
				array(
					'response' => array( 'code' => 504 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'not implemented'    => array(
				array(
					'response' => array( 'code' => 501 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'http unsupported'   => array(
				array(
					'response' => array( 'code' => 505 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'missing branch'     => array(
				array(
					'response' => array( 'code' => 404 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				404,
				null,
			),
			'provider failure'   => array(
				array(
					'response' => array( 'code' => 500 ),
					'body'     => '{"message":"upstream-response-canary"}',
				),
				502,
				null,
			),
			'malformed success'  => array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"name":"main"}',
				),
				502,
				null,
			),
		);
	}

	public function testManualBranchResolvesOnceToAnImmutableGitHubCommit(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse(),
				$this->shaResponse( strtoupper( $commit ) ),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub();
		$provider = $this->provider( $secrets );
		$archive  = $provider->prepareArchive( $this->archiveRequest( 'release', false ) );

		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->getUrl()
		);
		self::assertSame( $commit, $archive->getResolvedRef() );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		self::assertSame( 'application/vnd.github.sha', $requests[1]['arguments']['headers']['Accept'] );
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
		$archive->verifyCurrentHead();
		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	public function testManualRefRejectsAnOversizedShaOnlyResponseAtTheBoundedHttpLayer(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse(),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => str_repeat( 'a', 200 ),
				),
			)
		);

		try {
			$this->provider( new RepositoryResolverSecretsStub() )
				->prepareArchive( $this->archiveRequest( 'release', false ) );
			self::fail( 'An oversized SHA-only response must not be accepted as an immutable ref.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 502, $exception->getCode() );
			self::assertStringContainsString( 'invalid revision-resolution response', $exception->getMessage() );
		}

		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
	}

	public function testAutomaticArchiveRechecksTheBranchImmediatelyBeforeMutation(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		$moved  = '89abcdef0123456789abcdef0123456789abcdef';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse(),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $commit ),
					)
				),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $moved ),
					)
				),
			)
		);
		$provider = $this->provider( new RepositoryResolverSecretsStub() );
		$archive  = $provider->prepareArchive( $this->archiveRequest( $commit, false, null, 'main' ) );

		try {
			$archive->verifyCurrentHead();
			self::fail( 'The second GitHub head check must reject a branch that moved before mutation.' );
		} catch ( StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
		}

		self::assertCount( 3, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testManualTagAndCommitAlsoResolveToImmutableGitHubCommits(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';

		foreach ( array( 'v1.2.3', strtoupper( $commit ) ) as $ref ) {
			\RAN\Booster\GitHub\repository_resolver_http_queue(
				array(
					$this->repositoryIdentityResponse(),
					$this->shaResponse( strtoupper( $commit ) ),
				)
			);
			$archive = $this->provider( new RepositoryResolverSecretsStub() )
				->prepareArchive( $this->archiveRequest( $ref, false ) );

			self::assertSame( $commit, $archive->getResolvedRef(), $ref );
			self::assertStringEndsWith( '/zipball/' . $commit, $archive->getUrl(), $ref );
			self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests(), $ref );
			$archive->cleanup();
		}
	}

	public function testExpectedBranchRejectsNonCommitRefBeforeHttpOrArchiveAuthentication(): void {
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepareArchive( $this->archiveRequest( 'main', true, 'private-profile', 'main' ) );
			self::fail( 'An expected branch must be paired with an immutable commit.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
		}

		self::assertSame( array(), \RAN\Booster\GitHub\repository_resolver_http_requests() );
		self::assertSame( array(), $secrets->lookups );
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveAuthenticationIsOneShotAndBoundToTheExactImmutableArchive(): void {
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$archive  = $this->privateImmutableArchive( $secrets );
		$callback = \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'];
		$url      = $archive->getUrl();
		$hostile  = array(
			'https://example.test/archive.zip',
			'https://api.github.com.evil.test/repos/RocketsAreNostalgic/example-plugin/zipball/' . $archive->getResolvedRef(),
			'https://api.github.com/repos/RocketsAreNostalgic/other-plugin/zipball/' . $archive->getResolvedRef(),
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/89abcdef0123456789abcdef0123456789abcdef',
			'http://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $archive->getResolvedRef(),
			$url . '?download=1',
			$url . '#fragment',
		);

		foreach ( $hostile as $candidate ) {
			$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $candidate );

			self::assertSame( array( 'Existing' => 'value' ), $arguments['headers'], $candidate );
			self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ), $candidate );
		}

		$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $url );

		self::assertSame( 'value', $arguments['headers']['Existing'] );
		self::assertSame( 'Bearer ' . self::TOKEN, $arguments['headers']['Authorization'] );
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ) );
		self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ) );
		self::assertSame(
			array( 'private-profile', 'private-profile', 'private-profile' ),
			$secrets->lookups
		);

		try {
			$callback( array( 'headers' => array() ), $url );
			self::fail( 'Consumed GitHub archive authentication must not be inherited by a later request.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
		}

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testRedirectScrubberOnlyRemovesAuthInheritedFromTheExactGitHubArchiveOrigin(): void {
		$archive          = $this->privateImmutableArchive( new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) ) );
		$requestCallback  = \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'];
		$redirectCallback = \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK )[0]['callback'];
		$url              = $archive->getUrl();
		$arguments        = $requestCallback( array( 'headers' => array() ), $url );
		$location         = 'https://codeload.github.com/RocketsAreNostalgic/example-plugin/legacy.zip/tokenless';
		$unrelatedHeaders = $arguments['headers'];
		$archiveHeaders   = array(
			'authorization' => $arguments['headers']['Authorization'],
			'Existing'      => 'value',
		);

		call_user_func_array(
			$redirectCallback,
			array( &$location, &$unrelatedHeaders, null, array(), (object) array( 'url' => 'https://example.test/' ) )
		);
		self::assertArrayHasKey( 'Authorization', $unrelatedHeaders );

		call_user_func_array(
			$redirectCallback,
			array( &$location, &$archiveHeaders, null, array(), (object) array( 'url' => $url ) )
		);
		self::assertArrayNotHasKey( 'authorization', $archiveHeaders );
		self::assertSame( 'value', $archiveHeaders['Existing'] );
		self::assertStringNotContainsString( self::TOKEN, $location );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testCleanupIsIdempotentAndAutomaticHeadVerificationSurvivesArchiveAuthenticationCleanup(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse( true ),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $commit ),
					)
				),
				$this->response(
					200,
					array(
						'name'   => 'main',
						'commit' => array( 'sha' => $commit ),
					)
				),
			)
		);
		$archive = $this->provider( new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) ) )
			->prepareArchive( $this->archiveRequest( $commit, true, 'private-profile', 'main' ) );

		\RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'](
			array( 'headers' => array() ),
			$archive->getUrl()
		);
		$archive->verifyCurrentHead();
		self::assertCount( 3, \RAN\Booster\GitHub\repository_resolver_http_requests() );

		$archive->cleanup();
		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	private function privateImmutableArchive( RepositoryResolverSecretsStub $secrets ): AuthenticatedPreparedArchive {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse( true ),
				$this->shaResponse( $commit ),
			)
		);
		$archive = $this->provider( $secrets )
			->prepareArchive( $this->archiveRequest( 'release', true, 'private-profile' ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );

		return $archive;
	}

	private function provider( RepositoryResolverSecretsStub $secrets ): GitHubProvider {
		$provider = GitHubProvider::create(
			$secrets,
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}

	private function archiveRequest(
		string $ref,
		bool $private,
		?string $credentialId = null,
		?string $expectedBranch = null
	): ArchiveRequest {
		return new ArchiveRequest(
			new RepositoryReference(
				'RocketsAreNostalgic/example-plugin',
				'987654321',
				$private,
				$credentialId
			),
			$ref,
			$expectedBranch
		);
	}

	private function assertNoArchiveHooks(): void {
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ) );
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ) );
	}

	private function repositoryIdentityResponse( bool $private = false, string $id = '987654321' ): array {
		return $this->response(
			200,
			array(
				'id'             => $id,
				'full_name'      => 'RocketsAreNostalgic/example-plugin',
				'private'        => $private,
				'default_branch' => 'main',
			)
		);
	}

	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}

	private function shaResponse( string $sha ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $sha,
		);
	}

	private function errorResponse( int $status, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '{"message":"upstream-response-canary"}',
		);
	}
}

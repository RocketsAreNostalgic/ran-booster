<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\StaleDeployment;
use RuntimeException;
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\RepositoryProvider\Support\RepositoryResolverSecretsStub;

final class GitHubArchiveHostIntegrationTest extends TestCase {

	private const TOKEN = 'github-resolution-token-canary';

	protected function setUp(): void {
		parent::setUp();

		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
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

	public function test_webhook_commit_checks_current_public_branch_before_preparing_immutable_archive(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response(),
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
		$archive  = $provider->prepare_archive(
			$this->archive_request( $commit, false, null, 'release/candidate' )
		);
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/branches/release%2Fcandidate',
			$requests[1]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertArrayNotHasKey( 'Authorization', $requests[1]['arguments']['headers'] );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->get_url()
		);
		self::assertSame( array(), $secrets->lookups );
		$this->assert_no_archive_hooks();
		$archive->cleanup();
	}

	public function test_private_webhook_head_resolution_uses_selected_credential_before_archive_authentication(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response( true ),
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
		$archive  = $provider->prepare_archive(
			$this->archive_request( $commit, true, 'private-profile', 'main' )
		);
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();

		self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'Bearer ' . self::TOKEN, $requests[1]['arguments']['headers']['Authorization'] );
		self::assertSame( array( 'private-profile', 'private-profile', 'private-profile' ), $secrets->lookups );
		self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ) );
		self::assertCount( 1, \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ) );

		$archive->cleanup();
		$this->assert_no_archive_hooks();
	}

	public function test_stale_webhook_commit_fails_before_archive_authentication_is_registered(): void {
		$current = '0123456789abcdef0123456789abcdef01234567';
		$stale   = '89abcdef0123456789abcdef0123456789abcdef';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response( true ),
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
			$provider->prepare_archive( $this->archive_request( $stale, true, 'private-profile', 'main' ) );
			self::fail( 'A delayed GitHub webhook commit must not replace the current branch head.' );
		} catch ( StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
			self::assertSame( 'The GitHub deployment event is stale because the configured branch has moved.', $exception->getMessage() );
		}

		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		$this->assert_no_archive_hooks();
	}

	public function test_repository_identity_mismatch_fails_before_branch_lookup_or_archive_authentication(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->repository_identity_response( true, 'different-repository-id' ) )
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepare_archive(
				$this->archive_request(
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

		self::assertCount( 1, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		$this->assert_no_archive_hooks();
	}

	#[DataProvider( 'branch_head_failure_provider' )]
	public function test_branch_head_failures_are_explicit_and_never_prepare_archive_authentication(
		mixed $response,
		int $expected_code,
		?int $retry_after_seconds = null
	): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array( $this->repository_identity_response( true ), $response )
		);
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepare_archive(
				$this->archive_request(
					'0123456789abcdef0123456789abcdef01234567',
					true,
					'private-profile',
					'main'
				)
			);
			self::fail( 'A failed GitHub branch-head lookup must prevent deployment.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expected_code, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
		}

		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		$this->assert_no_archive_hooks();
	}

	/**
	 * @return array<string, array{mixed, int, int|null}>
	 */
	public static function branch_head_failure_provider(): array {
		return array(
			'transport error'    => array( new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'http_request_failed' ), 502, null ),
			'blocked transport'  => array( new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'http_request_not_executed' ), 502, null ),
			'local policy error' => array( new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'local_policy_canary' ), 502, null ),
			'no transport'       => array( new \RAN\BoosterGitHubProvider\V1\RepositoryResolverWpError( 'http_failure' ), 502, null ),
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

	public function test_manual_branch_resolves_once_to_an_immutable_git_hub_commit(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response(),
				$this->sha_response( strtoupper( $commit ) ),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub();
		$provider = $this->provider( $secrets );
		$archive  = $provider->prepare_archive( $this->archive_request( 'release', false ) );

		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->get_url()
		);
		self::assertSame( $commit, $archive->get_resolved_ref() );
		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertSame( 'application/vnd.github.sha', $requests[1]['arguments']['headers']['Accept'] );
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
		$archive->verify_current_head();
		self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		$this->assert_no_archive_hooks();
	}

	public function test_manual_ref_rejects_an_oversized_sha_only_response_at_the_bounded_http_layer(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response(),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => str_repeat( 'a', 200 ),
				),
			)
		);

		try {
			$this->provider( new RepositoryResolverSecretsStub() )
				->prepare_archive( $this->archive_request( 'release', false ) );
			self::fail( 'An oversized SHA-only response must not be accepted as an immutable ref.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 502, $exception->getCode() );
			self::assertStringContainsString( 'invalid revision-resolution response', $exception->getMessage() );
		}

		$requests = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests();
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
	}

	public function test_automatic_archive_rechecks_the_branch_immediately_before_mutation(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		$moved  = '89abcdef0123456789abcdef0123456789abcdef';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response(),
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
		$archive  = $provider->prepare_archive( $this->archive_request( $commit, false, null, 'main' ) );

		try {
			$archive->verify_current_head();
			self::fail( 'The second GitHub head check must reject a branch that moved before mutation.' );
		} catch ( StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
		}

		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
	}

	public function test_manual_tag_and_commit_also_resolve_to_immutable_git_hub_commits(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';

		foreach ( array( 'v1.2.3', strtoupper( $commit ) ) as $ref ) {
			\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
				array(
					$this->repository_identity_response(),
					$this->sha_response( strtoupper( $commit ) ),
				)
			);
			$archive = $this->provider( new RepositoryResolverSecretsStub() )
				->prepare_archive( $this->archive_request( $ref, false ) );

			self::assertSame( $commit, $archive->get_resolved_ref(), $ref );
			self::assertStringEndsWith( '/zipball/' . $commit, $archive->get_url(), $ref );
			self::assertCount( 2, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests(), $ref );
			$archive->cleanup();
		}
	}

	public function test_expected_branch_rejects_non_commit_ref_before_http_or_archive_authentication(): void {
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$provider = $this->provider( $secrets );

		try {
			$provider->prepare_archive( $this->archive_request( 'main', true, 'private-profile', 'main' ) );
			self::fail( 'An expected branch must be paired with an immutable commit.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
		}

		self::assertSame( array(), \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );
		self::assertSame( array(), $secrets->lookups );
		$this->assert_no_archive_hooks();
	}

	public function test_private_archive_authentication_is_one_shot_and_bound_to_the_exact_immutable_archive(): void {
		$secrets  = new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) );
		$archive  = $this->private_immutable_archive( $secrets );
		$callback = \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'];
		$url      = $archive->get_url();
		$hostile  = array(
			'https://example.test/archive.zip',
			'https://api.github.com.evil.test/repos/RocketsAreNostalgic/example-plugin/zipball/' . $archive->get_resolved_ref(),
			'https://api.github.com/repos/RocketsAreNostalgic/other-plugin/zipball/' . $archive->get_resolved_ref(),
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/89abcdef0123456789abcdef0123456789abcdef',
			'http://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $archive->get_resolved_ref(),
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
		$this->assert_no_archive_hooks();
	}

	public function test_redirect_scrubber_only_removes_auth_inherited_from_the_exact_git_hub_archive_origin(): void {
		$archive           = $this->private_immutable_archive( new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) ) );
		$request_callback  = \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'];
		$redirect_callback = \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK )[0]['callback'];
		$url               = $archive->get_url();
		$arguments         = $request_callback( array( 'headers' => array() ), $url );
		$location          = 'https://codeload.github.com/RocketsAreNostalgic/example-plugin/legacy.zip/tokenless';
		$unrelated_headers = $arguments['headers'];
		$archive_headers   = array(
			'authorization' => $arguments['headers']['Authorization'],
			'Existing'      => 'value',
		);

		call_user_func_array(
			$redirect_callback,
			array( &$location, &$unrelated_headers, null, array(), (object) array( 'url' => 'https://example.test/' ) )
		);
		self::assertArrayHasKey( 'Authorization', $unrelated_headers );

		call_user_func_array(
			$redirect_callback,
			array( &$location, &$archive_headers, null, array(), (object) array( 'url' => $url ) )
		);
		self::assertArrayNotHasKey( 'authorization', $archive_headers );
		self::assertSame( 'value', $archive_headers['Existing'] );
		self::assertStringNotContainsString( self::TOKEN, $location );

		$archive->cleanup();
		$this->assert_no_archive_hooks();
	}

	public function test_cleanup_is_idempotent_and_automatic_head_verification_survives_archive_authentication_cleanup(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response( true ),
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
			->prepare_archive( $this->archive_request( $commit, true, 'private-profile', 'main' ) );

		\RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' )[0]['callback'](
			array( 'headers' => array() ),
			$archive->get_url()
		);
		$archive->verify_current_head();
		self::assertCount( 3, \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() );

		$archive->cleanup();
		$archive->cleanup();
		$this->assert_no_archive_hooks();
	}

	private function private_immutable_archive( RepositoryResolverSecretsStub $secrets ): AuthenticatedPreparedArchive {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->repository_identity_response( true ),
				$this->sha_response( $commit ),
			)
		);
		$archive = $this->provider( $secrets )
			->prepare_archive( $this->archive_request( 'release', true, 'private-profile' ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );

		return $archive;
	}

	private function provider( RepositoryResolverSecretsStub $secrets ): GitHubProvider {
		$provider = GitHubProvider::create(
			$secrets,
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new \stdClass()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}

	private function archive_request(
		string $ref,
		bool $private,
		?string $credential_id = null,
		?string $expected_branch = null
	): ArchiveRequest {
		return new ArchiveRequest(
			new RepositoryReference(
				'RocketsAreNostalgic/example-plugin',
				'987654321',
				$private,
				$credential_id
			),
			$ref,
			$expected_branch
		);
	}

	private function assert_no_archive_hooks(): void {
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ) );
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ) );
	}

	private function repository_identity_response( bool $private = false, string $id = '987654321' ): array {
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

	private function sha_response( string $sha ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $sha,
		);
	}

	private function error_response( int $status, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '{"message":"upstream-response-canary"}',
		);
	}
}

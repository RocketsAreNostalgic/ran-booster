<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\RepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RuntimeException;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class RepositoryResolverTest extends TestCase {

	private const TOKEN = 'github-resolution-token-canary';

	private bool $hadWebhookTransients;
	private mixed $previousWebhookTransients;

	protected function setUp(): void {
		parent::setUp();

		$this->hadWebhookTransients                     = array_key_exists( 'ran_booster_webhook_test_transients', $GLOBALS );
		$this->previousWebhookTransients                = $GLOBALS['ran_booster_webhook_test_transients'] ?? null;
		$GLOBALS['ran_booster_webhook_test_transients'] = array();

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
		if ( $this->hadWebhookTransients ) {
			$GLOBALS['ran_booster_webhook_test_transients'] = $this->previousWebhookTransients;
		} else {
			unset( $GLOBALS['ran_booster_webhook_test_transients'] );
		}

		parent::tearDown();
	}

	public function testDocumentsOrganisationScopedFineGrainedTokenProfiles(): void {
		$setup = $this->provider( new RepositoryResolverSecretsStub() )->getMetadata()->admin?->setup;

		self::assertNotNull( $setup );
		self::assertStringContainsString( 'limited to one user or organisation', $setup->credentialSummary );
		self::assertStringContainsString( 'select the project repositories once', $setup->credentialSummary );
		self::assertStringContainsString( 'Booster does not change that GitHub repository selection', $setup->credentialSummary );
	}

	public function testAnonymousLookupResolvesCanonicalPublicRepositoryMetadata(): void {
		$secrets    = new RepositoryResolverSecretsStub();
		$repository = ( new RepositoryBrowser( $secrets ) )->repository( 'rocketsarenostalgic/ran-booster' );

		self::assertSame(
			array(
				'provider'               => 'gh',
				'locator'                => 'RocketsAreNostalgic/ran-booster',
				'package_slug'           => 'ran-booster',
				'provider_repository_id' => '987654321',
				'private'                => false,
				'default_branch'         => 'main',
				'credential_id'          => null,
			),
			$repository->toArray()
		);
		self::assertSame( array(), $secrets->lookups );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.github.com/repos/rocketsarenostalgic/ran-booster',
			$requests[0]['url']
		);
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertArrayNotHasKey(
			'Authorization',
			$requests[0]['arguments']['headers']
		);
		self::assertSame(
			array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => RepositoryBrowser::API_VERSION,
				'User-Agent'           => 'RAN-Booster',
			),
			$requests[0]['arguments']['headers']
		);
	}

	public function testMixedCaseRepositoryNameKeepsCanonicalProviderIdentity(): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => 565105478,
					'full_name'      => 'RocketsAreNostalgic/tnyGmaps',
					'private'        => false,
					'default_branch' => 'master',
				)
			)
		);

		$repository = ( new RepositoryBrowser( new RepositoryResolverSecretsStub() ) )->repository(
			'RocketsAreNostalgic/tnyGmaps'
		);

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $repository->locator );
		self::assertSame( 'tnyGmaps', $repository->packageSlug );
		self::assertSame( '565105478', $repository->providerRepositoryId );
	}

	public function testSelectedGitHubCredentialResolvesActualPrivateRepositoryMetadata(): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'id'             => '9223372036854775807123',
					'full_name'      => 'RocketsAreNostalgic/private-plugin',
					'private'        => true,
					'default_branch' => 'develop',
				)
			)
		);
		$secrets    = new RepositoryResolverSecretsStub(
			array(
				'private-profile' => self::TOKEN,
			)
		);
		$repository = ( new RepositoryBrowser( $secrets ) )->repository(
			'RocketsAreNostalgic/private-plugin',
			'private-profile'
		);
		$requests   = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertTrue( $repository->private );
		self::assertSame( 'develop', $repository->defaultBranch );
		self::assertSame( '9223372036854775807123', $repository->providerRepositoryId );
		self::assertSame( 'private-profile', $repository->credentialId );
		self::assertSame( array( 'private-profile' ), $secrets->lookups );
		self::assertSame(
			'Bearer ' . self::TOKEN,
			$requests[0]['arguments']['headers']['Authorization']
		);
		self::assertStringNotContainsString( self::TOKEN, $requests[0]['url'] );
	}

	public function testInvalidGitHubHandleIsRejectedBeforeAnyHttpRequest(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'invalid owner/repository' );
			self::fail( 'Invalid GitHub handles must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( array(), \RAN\Booster\GitHub\repository_resolver_http_requests() );
		}
	}

	public function testMissingSelectedCredentialFailsBeforeAnyHttpRequest(): void {
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'RocketsAreNostalgic/private-plugin', 'missing-profile' );
			self::fail( 'A missing selected credential must not become an anonymous request.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertStringNotContainsString( 'missing-profile', $exception->getMessage() );
			self::assertSame( array(), \RAN\Booster\GitHub\repository_resolver_http_requests() );
		}
	}

	public function testRejectedCredentialNeverAppearsInUrlOrError(): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"credential":"' . self::TOKEN . '"}',
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'rejected-profile' => self::TOKEN ) )
		);

		try {
			$browser->repository( 'RocketsAreNostalgic/private-plugin', 'rejected-profile' );
			self::fail( 'Rejected credentials must fail repository resolution.' );
		} catch ( RuntimeException $exception ) {
			$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

			self::assertSame( 401, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( self::TOKEN, $requests[0]['url'] );
		}
	}

	#[DataProvider( 'rateLimitResponseProvider' )]
	public function testExactRepositoryMapsRateLimitResponses(
		int $status,
		array $headers,
		int $expectedStatus
	): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->errorResponse( $status, $headers )
		);
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->repository( 'RocketsAreNostalgic/rate-limited-plugin' );
			self::fail( 'A failed exact-repository request must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expectedStatus, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	#[DataProvider( 'rateLimitResponseProvider' )]
	public function testAuthenticatedListingMapsRateLimitResponses(
		int $status,
		array $headers,
		int $expectedStatus
	): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->errorResponse( $status, $headers )
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );
			self::fail( 'A failed authenticated listing must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expectedStatus, $exception->getCode() );
			self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	#[DataProvider( 'rateLimitResponseProvider' )]
	public function testPublicListingMapsRateLimitResponses(
		int $status,
		array $headers,
		int $expectedStatus
	): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->errorResponse( $status, $headers )
		);
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' ) );
			self::fail( 'A failed public listing must not return repository data.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $expectedStatus, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'header-canary', $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, array{string, array{token: string, kind: string, owner: string}}>
	 */
	public static function publicLookupProfileProvider(): array {
		return array(
			'classic PAT across owners'      => array(
				'classic-public',
				array(
					'token' => 'github-classic-public-canary',
					'kind'  => 'classic',
					'owner' => '',
				),
			),
			'fine-grained PAT across owners' => array(
				'fine-public',
				array(
					'token' => 'github-fine-public-canary',
					'kind'  => 'fine-grained',
					'owner' => 'ConfiguredElsewhere',
				),
			),
		);
	}

	/**
	 * @param array{token: string, kind: string, owner: string} $profile
	 */
	#[DataProvider( 'publicLookupProfileProvider' )]
	public function testExplicitPublicProfileAuthenticatesOwnerAndEveryPageWithoutAssociatingResults(
		string $profileId,
		array $profile
	): void {
		$firstPage = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$firstPage[] = array(
				'id'             => $index,
				'full_name'      => 'UnrelatedOwner/package-' . $index,
				'private'        => 30 === $index,
				'default_branch' => 'main',
			);
		}
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'Organization',
						'login' => 'UnrelatedOwner',
					)
				),
				$this->response( 200, $firstPage ),
				$this->response(
					200,
					array(
						array(
							'id'             => 31,
							'full_name'      => 'UnrelatedOwner/package-31',
							'private'        => false,
							'default_branch' => 'main',
						),
					)
				),
			)
		);
		$secrets = new RepositoryResolverSecretsStub( array( $profileId => $profile ) );
		$result  = ( new RepositoryBrowser( $secrets ) )->browse(
			RepositoryBrowseRequest::publicOwner( 'UnrelatedOwner', $profileId )
		);

		self::assertCount( 30, $result->repositories );
		self::assertSame( array( $profileId ), $secrets->lookups );
		foreach ( $result->repositories as $repository ) {
			self::assertFalse( $repository->private );
			self::assertNull( $repository->credentialId );
		}

		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		self::assertCount( 3, $requests );
		self::assertSame( 'https://api.github.com/users/UnrelatedOwner', $requests[0]['url'] );
		self::assertStringContainsString( '/orgs/UnrelatedOwner/repos?type=public&', $requests[1]['url'] );
		foreach ( $requests as $request ) {
			self::assertSame( 'Bearer ' . $profile['token'], $request['arguments']['headers']['Authorization'] );
			self::assertStringNotContainsString( $profile['token'], $request['url'] );
		}
	}

	public function testMissingExplicitPublicProfileFailsBeforeAnyHttpRequest(): void {
		$secrets = new RepositoryResolverSecretsStub();
		$browser = new RepositoryBrowser( $secrets );

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'missing-profile' ) );
			self::fail( 'A missing public lookup profile must not become an anonymous request.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertStringNotContainsString( 'missing-profile', $exception->getMessage() );
			self::assertSame( array( 'missing-profile' ), $secrets->lookups );
			self::assertSame( array(), \RAN\Booster\GitHub\repository_resolver_http_requests() );
		}
	}

	#[DataProvider( 'rateLimitResponseProvider' )]
	public function testExplicitPublicProfileNeverRetriesDenialsAnonymously(
		int $status,
		array $headers,
		int $expectedStatus
	): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset( $this->errorResponse( $status, $headers ) );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'public-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'public-profile' ) );
			self::fail( 'An authenticated public lookup denial must fail closed.' );
		} catch ( RuntimeException $exception ) {
			$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
			self::assertSame( $expectedStatus, $exception->getCode() );
			self::assertCount( 1, $requests );
			self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		}
	}

	public function testExplicitPublicProfileFailsClosedWhenALaterPageIsRateLimited(): void {
		$firstPage = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$firstPage[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/package-' . $index,
				'private'        => false,
				'default_branch' => 'main',
			);
		}
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, $firstPage ),
				$this->errorResponse( 429, array() ),
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'public-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'public-profile' ) );
			self::fail( 'A later authenticated page denial must not return partial results.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 429, $exception->getCode() );
			$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
			self::assertCount( 3, $requests );
			foreach ( $requests as $request ) {
				self::assertSame( 'Bearer ' . self::TOKEN, $request['arguments']['headers']['Authorization'] );
			}
		}
	}

	public function testAnonymousPublicLookupNeverInfersAConstantOrRemembersAPriorProfile(): void {
		$secrets = new RepositoryResolverSecretsStub(
			array(
				'constant'       => 'github-constant-canary',
				'public-profile' => self::TOKEN,
			)
		);
		$browser = new RepositoryBrowser( $secrets );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, array() ),
			)
		);

		$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'public-profile' ) );
		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		foreach ( \RAN\Booster\GitHub\repository_resolver_http_requests() as $request ) {
			self::assertSame( 'Bearer ' . self::TOKEN, $request['arguments']['headers']['Authorization'] );
		}

		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response(
					200,
					array(
						'type'  => 'User',
						'login' => 'RocketsAreNostalgic',
					)
				),
				$this->response( 200, array() ),
			)
		);
		$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' ) );

		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		foreach ( \RAN\Booster\GitHub\repository_resolver_http_requests() as $request ) {
			self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
		}

		\RAN\Booster\GitHub\repository_resolver_http_reset( $this->repositoryIdentityResponse() );
		$browser->repository( 'RocketsAreNostalgic/example-plugin' );
		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		self::assertArrayNotHasKey(
			'Authorization',
			\RAN\Booster\GitHub\repository_resolver_http_requests()[0]['arguments']['headers']
		);
	}

	public function testListingKeepsEarlierResultsWhenALaterPageIsRateLimited(): void {
		$items = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$items[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/package-' . $index,
				'private'        => true,
				'default_branch' => 'main',
			);
		}
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $items ),
				$this->errorResponse( 429, array() ),
			)
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		$result = $browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );

		self::assertTrue( $result->isPartial() );
		self::assertSame( RepositoryBrowseResult::RATE_LIMIT, $result->partialReason );
		self::assertCount( 30, $result->repositories );
		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testListingReturnsAnExplicitPartialResultAfterFivePages(): void {
		$pages = array();
		for ( $page = 0; $page < RepositoryBrowseRequest::MAX_REMOTE_CALLS; ++$page ) {
			$items = array();
			for ( $index = 1; $index <= 30; ++$index ) {
				$id      = $page * 30 + $index;
				$items[] = array(
					'id'             => $id,
					'full_name'      => 'RocketsAreNostalgic/package-' . $id,
					'private'        => true,
					'default_branch' => 'main',
				);
			}
			$pages[] = $this->response( 200, $items );
		}
		\RAN\Booster\GitHub\repository_resolver_http_queue( $pages );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		$result = $browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );

		self::assertTrue( $result->isPartial() );
		self::assertSame( RepositoryBrowseResult::LIMIT, $result->partialReason );
		self::assertCount( 150, $result->repositories );
		self::assertCount( RepositoryBrowseRequest::MAX_REMOTE_CALLS, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testThirtyRepresentativeRepositoryObjectsFitWithinTheResponseBudget(): void {
		$items = array();
		for ( $index = 1; $index <= 30; ++$index ) {
			$items[] = array(
				'id'             => $index,
				'full_name'      => 'RocketsAreNostalgic/representative-package-' . $index,
				'private'        => false,
				'default_branch' => 'main',
				'description'    => str_repeat( 'Representative repository metadata. ', 150 ),
			);
		}
		$largePage = $this->response( 200, $items );
		self::assertGreaterThan( 150000, strlen( $largePage['body'] ) );
		self::assertLessThanOrEqual( RepositoryBrowseRequest::PER_RESPONSE_BYTES, strlen( $largePage['body'] ) );

		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$largePage,
				$this->response( 200, array() ),
			)
		);
		$result = ( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) ) ) )->browse(
			RepositoryBrowseRequest::accessible( 'listing-profile' )
		);

		self::assertFalse( $result->isPartial() );
		self::assertCount( 30, $result->repositories );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		self::assertCount( 2, $requests );
		self::assertStringContainsString( 'per_page=30', $requests[0]['url'] );
		self::assertSame( RepositoryBrowseRequest::PER_RESPONSE_BYTES + 1, $requests[0]['arguments']['limit_response_size'] );
	}

	public function testListingRejectsAnInvalidSuccessResponseWithoutLeakingIt(): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response( 200, array( 'message' => 'upstream-response-canary' ) )
		);
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );
			self::fail( 'An invalid repository-list response must not be accepted.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 422, $exception->getCode() );
			self::assertStringNotContainsString( 'upstream-response-canary', $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, array{int, array<string, string>, int}>
	 */
	public static function rateLimitResponseProvider(): array {
		return array(
			'explicit 429'                   => array( 429, array(), 429 ),
			'exhausted rate-limit allowance' => array( 403, array( 'X-RateLimit-Remaining' => '0' ), 429 ),
			'valid retry-after delay'        => array( 403, array( 'Retry-After' => '120' ), 429 ),
			'valid retry-after date'         => array( 403, array( 'Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT' ), 429 ),
			'ordinary permission denial'     => array( 403, array(), 403 ),
			'malformed rate-limit headers'   => array(
				403,
				array(
					'X-RateLimit-Remaining' => 'header-canary',
					'Retry-After'           => 'header-canary',
				),
				403,
			),
		);
	}

	public function testDiscoveryAndCredentialValidationDoNotExposeRetryableDeploymentFailures(): void {
		$transport = new \RAN\Booster\GitHub\RepositoryResolverWpError( 'http_request_failed' );
		\RAN\Booster\GitHub\repository_resolver_http_reset( $transport );
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' ) );
			self::fail( 'A discovery transport failure must be reported normally.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringNotContainsString( 'http_request_failed', $failure->getMessage() );
		}

		\RAN\Booster\GitHub\repository_resolver_http_reset( $transport );
		$result = ( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile-1' => self::TOKEN ) ) ) )
			->validateCredential( 'profile-1' );

		self::assertFalse( $result->isValid() );
		self::assertSame( 'unavailable', $result->reason );
	}

	public function testRepositoryPathCheckDistinguishesDirectoriesFromFilesAndMissingPaths(): void {
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'private-profile' => self::TOKEN ) )
		);
		$ref     = str_repeat( 'a', 40 );

		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					array(
						'name' => 'plugin.php',
						'type' => 'file',
					),
				)
			)
		);
		self::assertTrue(
			$browser->pathExists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/My Plugin', 'private-profile', true )
		);
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/private-plugin/contents/packages/My%20Plugin?ref=' . $ref,
			$requests[0]['url']
		);
		self::assertSame( 'Bearer ' . self::TOKEN, $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 1024, $requests[0]['arguments']['limit_response_size'] );

		\RAN\Booster\GitHub\repository_resolver_http_reset(
			$this->response(
				200,
				array(
					'name' => 'plugin.php',
					'type' => 'file',
				)
			)
		);
		self::assertFalse( $browser->pathExists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/plugin.php', 'private-profile', true ) );

		\RAN\Booster\GitHub\repository_resolver_http_reset( $this->errorResponse( 404, array() ) );
		self::assertFalse( $browser->pathExists( 'RocketsAreNostalgic/private-plugin', $ref, 'packages/missing', 'private-profile', true ) );
	}

	private function provider( RepositoryResolverSecretsStub $secrets ): GitHubProvider {
		$provider = GitHubProvider::create(
			$secrets,
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
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

	private function errorResponse( int $status, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '{"message":"upstream-response-canary"}',
		);
	}
}

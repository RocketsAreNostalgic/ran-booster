<?php

declare(strict_types=1);

namespace Tests\GitHub;

require_once __DIR__ . '/RepositoryResolverWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/RepositoryProvider/AuthenticatedPreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;
use RuntimeException;
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;

final class RepositoryResolverTest extends TestCase {

	private const TOKEN = 'github-resolution-token-canary';

	private bool $hadWebhookTransients;
	private mixed $previousWebhookTransients;

	protected function setUp(): void {
		parent::setUp();

		$this->hadWebhookTransients                     = array_key_exists( 'ran_booster_webhook_test_transients', $GLOBALS );
		$this->previousWebhookTransients                = $GLOBALS['ran_booster_webhook_test_transients'] ?? null;
		$GLOBALS['ran_booster_webhook_test_transients'] = array();
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();

		\RAN\GitHub\repository_resolver_http_reset(
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
		$requests = \RAN\GitHub\repository_resolver_http_requests();

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

	public function testAnonymousPublicLookupRemainsAvailableWhenEncryptedSecretsRuntimeIsUnavailable(): void {
		$secrets = new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$repository = ( new RepositoryBrowser( $secrets->credentialsFor( 'gh' ) ) )->repository( 'rocketsarenostalgic/ran-booster' );

		self::assertFalse( $repository->private );
		self::assertNull( $repository->credentialId );
		$request = \RAN\GitHub\repository_resolver_http_requests()[0];
		self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
	}

	public function testMixedCaseRepositoryNameKeepsCanonicalProviderIdentity(): void {
		\RAN\GitHub\repository_resolver_http_reset(
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
		\RAN\GitHub\repository_resolver_http_reset(
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
		$requests   = \RAN\GitHub\repository_resolver_http_requests();

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
			self::assertSame( array(), \RAN\GitHub\repository_resolver_http_requests() );
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
			self::assertSame( array(), \RAN\GitHub\repository_resolver_http_requests() );
		}
	}

	public function testRejectedCredentialNeverAppearsInUrlOrError(): void {
		\RAN\GitHub\repository_resolver_http_reset(
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
			$requests = \RAN\GitHub\repository_resolver_http_requests();

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
		\RAN\GitHub\repository_resolver_http_reset(
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
		\RAN\GitHub\repository_resolver_http_reset(
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
		\RAN\GitHub\repository_resolver_http_reset(
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
		\RAN\GitHub\repository_resolver_http_queue(
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

		$requests = \RAN\GitHub\repository_resolver_http_requests();
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
			self::assertSame( array(), \RAN\GitHub\repository_resolver_http_requests() );
		}
	}

	#[DataProvider( 'rateLimitResponseProvider' )]
	public function testExplicitPublicProfileNeverRetriesDenialsAnonymously(
		int $status,
		array $headers,
		int $expectedStatus
	): void {
		\RAN\GitHub\repository_resolver_http_reset( $this->errorResponse( $status, $headers ) );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'public-profile' => self::TOKEN ) )
		);

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'public-profile' ) );
			self::fail( 'An authenticated public lookup denial must fail closed.' );
		} catch ( RuntimeException $exception ) {
			$requests = \RAN\GitHub\repository_resolver_http_requests();
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
		\RAN\GitHub\repository_resolver_http_queue(
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
			$requests = \RAN\GitHub\repository_resolver_http_requests();
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
		\RAN\GitHub\repository_resolver_http_queue(
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
		foreach ( \RAN\GitHub\repository_resolver_http_requests() as $request ) {
			self::assertSame( 'Bearer ' . self::TOKEN, $request['arguments']['headers']['Authorization'] );
		}

		\RAN\GitHub\repository_resolver_http_queue(
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
		foreach ( \RAN\GitHub\repository_resolver_http_requests() as $request ) {
			self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
		}

		\RAN\GitHub\repository_resolver_http_reset( $this->repositoryIdentityResponse() );
		$browser->repository( 'RocketsAreNostalgic/example-plugin' );
		self::assertSame( array( 'public-profile' ), $secrets->lookups );
		self::assertArrayNotHasKey(
			'Authorization',
			\RAN\GitHub\repository_resolver_http_requests()[0]['arguments']['headers']
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
		\RAN\GitHub\repository_resolver_http_queue(
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
		self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests() );
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
		\RAN\GitHub\repository_resolver_http_queue( $pages );
		$browser = new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'listing-profile' => self::TOKEN ) )
		);

		$result = $browser->browse( RepositoryBrowseRequest::accessible( 'listing-profile' ) );

		self::assertTrue( $result->isPartial() );
		self::assertSame( RepositoryBrowseResult::LIMIT, $result->partialReason );
		self::assertCount( 150, $result->repositories );
		self::assertCount( RepositoryBrowseRequest::MAX_REMOTE_CALLS, \RAN\GitHub\repository_resolver_http_requests() );
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

		\RAN\GitHub\repository_resolver_http_queue(
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
		$requests = \RAN\GitHub\repository_resolver_http_requests();
		self::assertCount( 2, $requests );
		self::assertStringContainsString( 'per_page=30', $requests[0]['url'] );
		self::assertSame( RepositoryBrowseRequest::PER_RESPONSE_BYTES + 1, $requests[0]['arguments']['limit_response_size'] );
	}

	public function testListingRejectsAnInvalidSuccessResponseWithoutLeakingIt(): void {
		\RAN\GitHub\repository_resolver_http_reset(
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
		$transport = new \RAN\GitHub\RepositoryResolverWpError( 'http_request_failed' );
		\RAN\GitHub\repository_resolver_http_reset( $transport );
		$browser = new RepositoryBrowser( new RepositoryResolverSecretsStub() );

		try {
			$browser->browse( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' ) );
			self::fail( 'A discovery transport failure must be reported normally.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringNotContainsString( 'http_request_failed', $failure->getMessage() );
		}

		\RAN\GitHub\repository_resolver_http_reset( $transport );
		$result = ( new RepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile-1' => self::TOKEN ) ) ) )
			->validateCredential( 'profile-1' );

		self::assertFalse( $result->isValid() );
		self::assertSame( 'unavailable', $result->reason );
	}

	public function testWebhookCommitChecksCurrentPublicBranchBeforePreparingImmutableArchive(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\GitHub\repository_resolver_http_queue(
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
		$requests = \RAN\GitHub\repository_resolver_http_requests();

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
		\RAN\GitHub\repository_resolver_http_queue(
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
		$requests = \RAN\GitHub\repository_resolver_http_requests();

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
		\RAN\GitHub\repository_resolver_http_queue(
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

		self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	public function testRepositoryIdentityMismatchFailsBeforeBranchLookupOrArchiveAuthentication(): void {
		\RAN\GitHub\repository_resolver_http_queue(
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

		self::assertCount( 1, \RAN\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	#[DataProvider( 'branchHeadFailureProvider' )]
	public function testBranchHeadFailuresAreExplicitAndNeverPrepareArchiveAuthentication(
		mixed $response,
		int $expectedCode,
		?int $retryAfterSeconds = null
	): void {
		\RAN\GitHub\repository_resolver_http_queue(
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

		self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	/**
	 * @return array<string, array{mixed, int, int|null}>
	 */
	public static function branchHeadFailureProvider(): array {
		return array(
			'transport error'    => array( new \RAN\GitHub\RepositoryResolverWpError( 'http_request_failed' ), 502, null ),
			'blocked transport'  => array( new \RAN\GitHub\RepositoryResolverWpError( 'http_request_not_executed' ), 502, null ),
			'local policy error' => array( new \RAN\GitHub\RepositoryResolverWpError( 'local_policy_canary' ), 502, null ),
			'no transport'       => array( new \RAN\GitHub\RepositoryResolverWpError( 'http_failure' ), 502, null ),
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
		\RAN\GitHub\repository_resolver_http_queue(
			array(
				$this->repositoryIdentityResponse(),
				$this->shaResponse( strtoupper( $commit ) ),
			)
		);
		$secrets  = new RepositoryResolverSecretsStub();
		$provider = $this->provider( $secrets );
		$archive  = $provider->prepareArchive( $this->archiveRequest( 'release', false ) );

		self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests() );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->getUrl()
		);
		self::assertSame( $commit, $archive->getResolvedRef() );
		$requests = \RAN\GitHub\repository_resolver_http_requests();
		self::assertSame( 'application/vnd.github.sha', $requests[1]['arguments']['headers']['Accept'] );
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
		$archive->verifyCurrentHead();
		self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests() );
		$this->assertNoArchiveHooks();
	}

	public function testManualRefRejectsAnOversizedShaOnlyResponseAtTheBoundedHttpLayer(): void {
		\RAN\GitHub\repository_resolver_http_queue(
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

		$requests = \RAN\GitHub\repository_resolver_http_requests();
		self::assertSame( 128, $requests[1]['arguments']['limit_response_size'] );
	}

	public function testAutomaticArchiveRechecksTheBranchImmediatelyBeforeMutation(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		$moved  = '89abcdef0123456789abcdef0123456789abcdef';
		\RAN\GitHub\repository_resolver_http_queue(
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

		self::assertCount( 3, \RAN\GitHub\repository_resolver_http_requests() );
	}

	public function testManualTagAndCommitAlsoResolveToImmutableGitHubCommits(): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';

		foreach ( array( 'v1.2.3', strtoupper( $commit ) ) as $ref ) {
			\RAN\GitHub\repository_resolver_http_queue(
				array(
					$this->repositoryIdentityResponse(),
					$this->shaResponse( strtoupper( $commit ) ),
				)
			);
			$archive = $this->provider( new RepositoryResolverSecretsStub() )
				->prepareArchive( $this->archiveRequest( $ref, false ) );

			self::assertSame( $commit, $archive->getResolvedRef(), $ref );
			self::assertStringEndsWith( '/zipball/' . $commit, $archive->getUrl(), $ref );
			self::assertCount( 2, \RAN\GitHub\repository_resolver_http_requests(), $ref );
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

		self::assertSame( array(), \RAN\GitHub\repository_resolver_http_requests() );
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
		\RAN\GitHub\repository_resolver_http_queue(
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
		self::assertCount( 3, \RAN\GitHub\repository_resolver_http_requests() );

		$archive->cleanup();
		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	private function privateImmutableArchive( RepositoryResolverSecretsStub $secrets ): AuthenticatedPreparedArchive {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\GitHub\repository_resolver_http_queue(
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
		return new GitHubProvider(
			$secrets,
			new RepositoryBrowser( $secrets ),
			new GitHubWebhookNormalizer( $secrets, new EmptyAuthenticatedWebhookDeliveryEvidenceReader() )
		);
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

	/** @return array<string, mixed> */
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

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}

	/** @return array<string, mixed> */
	private function shaResponse( string $sha ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $sha,
		);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function errorResponse( int $status, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '{"message":"upstream-response-canary"}',
		);
	}
}

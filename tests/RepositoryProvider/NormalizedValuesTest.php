<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseMode;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRequest;

final class NormalizedValuesTest extends TestCase {

	private const GITHUB_HEADERS    = array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );
	private const BITBUCKET_HEADERS = array( 'x-event-key', 'x-request-uuid', 'x-hub-signature' );

	public function testRepositoryDescriptorHasTheExactNormalizedEdgeShape(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			'RocketsAreNostalgic/ran-booster',
			'ran-booster',
			'1234',
			true,
			'main',
			'credential-one'
		);

		self::assertSame(
			array(
				'provider'               => 'gh',
				'locator'                => 'RocketsAreNostalgic/ran-booster',
				'package_slug'           => 'ran-booster',
				'provider_repository_id' => '1234',
				'private'                => true,
				'default_branch'         => 'main',
				'credential_id'          => 'credential-one',
			),
			$repository->toArray()
		);
		self::assertSame(
			$repository->toArray(),
			array_intersect_key( $repository->toArray(), array_flip( array_keys( $repository->toArray() ) ) )
		);
	}

	public function testRepositoryContractsKeepOpaqueNestedLocatorsWithoutRewritingProviderData(): void {
		$request    = new RepositoryLookupRequest(
			' group/subgroup/package '
		);
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'fixture' ),
			' group/subgroup/package ',
			'package',
			'fixture:group/subgroup/package',
			false,
			'main',
			null
		);
		$reference  = RepositoryReference::fromDescriptor( $repository );

		self::assertSame( ' group/subgroup/package ', $request->locator );
		self::assertSame( ' group/subgroup/package ', $repository->locator );
		self::assertSame( ' group/subgroup/package ', $reference->locator );
		self::assertSame( 'package', $repository->packageSlug );
	}

	public function testRepositoryDescriptorPreservesProviderIdentityCasing(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			'RocketsAreNostalgic/tnyGmaps',
			'tnyGmaps',
			'565105478',
			false,
			'master',
			null
		);

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $repository->locator );
		self::assertSame( 'tnyGmaps', $repository->packageSlug );
		self::assertSame( '565105478', $repository->providerRepositoryId );
	}

	public function testRepositoryContractsAcceptTheExactLocatorAndPackageSlugByteBounds(): void {
		$locator = str_repeat( 'l', 512 );
		$slug    = str_repeat( 's', 191 );

		self::assertSame( $locator, ( new RepositoryLookupRequest( $locator ) )->locator );
		self::assertSame(
			$slug,
			( new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), $locator, $slug, 'fixture-id', false, 'main', null ) )->packageSlug
		);
	}

	#[DataProvider( 'invalidOpaqueLocators' )]
	public function testRepositoryContractsRejectInvalidOpaqueLocators( string $locator ): void {
		foreach ( array( 'lookup', 'descriptor', 'reference' ) as $contract ) {
			try {
				match ( $contract ) {
					'lookup' => new RepositoryLookupRequest( $locator ),
					'descriptor' => new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), $locator, 'package', 'fixture-id', false, 'main', null ),
					'reference' => new RepositoryReference( $locator, 'fixture-id', false, null ),
				};
				self::fail( 'Expected an invalid repository locator to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	/** @return list<array{string}> */
	public static function invalidOpaqueLocators(): array {
		return array(
			array( '' ),
			array( "group/pack\nage" ),
			array( str_repeat( 'l', 513 ) ),
		);
	}

	#[DataProvider( 'invalidProviderPackageSlugs' )]
	public function testRepositoryDescriptorRejectsInvalidProviderPackageSlugs( string $slug ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), 'group/subgroup/package', $slug, 'fixture-id', false, 'main', null );
	}

	/** @return list<array{string}> */
	public static function invalidProviderPackageSlugs(): array {
		return array(
			array( '' ),
			array( 'group/package' ),
			array( '../package' ),
			array( str_repeat( 's', 192 ) ),
		);
	}

	public function testRepositoryReferencesAreDerivedWithoutCredentialMaterial(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'bb' ),
			'workspace/project',
			'project',
			'{repository-uuid}',
			false,
			'main',
			null
		);
		$reference  = RepositoryReference::fromDescriptor( $repository );
		$request    = new ArchiveRequest( $reference, 'd34db33f', 'main' );

		self::assertSame( 'workspace/project', $reference->locator );
		self::assertFalse( $reference->private );
		self::assertSame( 'd34db33f', $request->ref );
		self::assertSame( 'main', $request->expectedBranch );
		self::assertSame( $reference, $request->repository );
	}

	public function testArchiveRequestLeavesTheExpectedBranchOptionalAndRejectsABlankExpectation(): void {
		$reference = new RepositoryReference(
			'workspace/project',
			'{repository-uuid}',
			false,
			null
		);

		self::assertNull( ( new ArchiveRequest( $reference, 'd34db33f' ) )->expectedBranch );

		$this->expectException( InvalidArgumentException::class );

		new ArchiveRequest( $reference, 'd34db33f', " \t\n" );
	}

	public function testRepositoryBrowseRequestCarriesOnlyScopeAndCredentialIdentity(): void {
		$request = RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' );

		self::assertSame( RepositoryBrowseMode::PUBLIC_OWNER, $request->getMode() );
		self::assertSame( 'RocketsAreNostalgic', $request->getOwner() );
		self::assertNull( $request->getCredentialId() );

		$accessible = RepositoryBrowseRequest::accessible( 'credential-one' );

		self::assertSame( RepositoryBrowseMode::ACCESSIBLE, $accessible->getMode() );
		self::assertNull( $accessible->getOwner() );
		self::assertSame( 'credential-one', $accessible->getCredentialId() );
	}

	public function testPublicRepositoryBrowseRequestMayCarryOneProfileIdentity(): void {
		$request = RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic', 'public-lookup' );

		self::assertSame( RepositoryBrowseMode::PUBLIC_OWNER, $request->getMode() );
		self::assertSame( 'RocketsAreNostalgic', $request->getOwner() );
		self::assertSame( 'public-lookup', $request->getCredentialId() );
	}

	public function testAccessibleBrowseRequiresOneCredentialProfile(): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryBrowseRequest( RepositoryBrowseMode::ACCESSIBLE );
	}

	public function testPushEventHasTheExactNormalizedEdgeShape(): void {
		$event = new PushEvent(
			ProviderCode::parse( 'bb' ),
			'workspace/project',
			'{repository-uuid}',
			'main',
			'd34db33f',
			'delivery-one'
		);

		self::assertSame(
			array(
				'provider'               => 'bb',
				'repository'             => 'workspace/project',
				'provider_repository_id' => '{repository-uuid}',
				'branch'                 => 'main',
				'commit'                 => 'd34db33f',
				'delivery_id'            => 'delivery-one',
			),
			$event->toArray()
		);
	}

	public function testWebhookEnvelopeSupportsMultiplePushEvents(): void {
		$first  = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'main', 'aaa', 'request' );
		$second = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'release', 'bbb', 'request' );
		$result = WebhookEnvelope::events( $first, $second );

		self::assertTrue( $result->hasEvents() );
		self::assertFalse( $result->isProbe() );
		self::assertFalse( $result->isIgnored() );
		self::assertSame( array( $first, $second ), $result->getEvents() );
	}

	public function testWebhookEnvelopeDistinguishesProbeAndIgnoredRequests(): void {
		self::assertTrue( WebhookEnvelope::probe()->isProbe() );
		self::assertSame( array(), WebhookEnvelope::probe()->getEvents() );
		self::assertTrue( WebhookEnvelope::ignored()->isIgnored() );
		self::assertSame( array(), WebhookEnvelope::ignored()->getEvents() );
	}

	public function testEventEnvelopeRejectsAnEmptyEventList(): void {
		$this->expectException( InvalidArgumentException::class );

		WebhookEnvelope::events();
	}

	public function testWebhookRequestAcceptsNativeWordPressHeaderShape(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{"ref":"refs/heads/main"}',
			array(
				'X-GitHub-Event'      => array( 'push' ),
				'X-GitHub-Delivery'   => array( 'delivery-one' ),
				'X-Hub-Signature-256' => array( 'sha256=signature' ),
			),
			self::GITHUB_HEADERS
		);

		self::assertSame( '{"ref":"refs/heads/main"}', $request->getBody() );
		self::assertSame( 'push', $request->getHeader( 'x-github-event' ) );
		self::assertSame( 'delivery-one', $request->getHeader( 'X-GITHUB-DELIVERY' ) );
		self::assertSame( 'sha256=signature', $request->getHeader( 'x-hub-signature-256' ) );
		self::assertSame( array( 'push' ), $request->getRawHeaderValues( 'X_GITHUB_EVENT' ) );
	}

	public function testWebhookRequestRetainsEquivalentRawValuesAndAliases(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'X-GitHub-Event' => array( ' push ', 'push' ),
				'X_GitHub_Event' => 'push',
			),
			self::GITHUB_HEADERS
		);

		self::assertSame( 'push', $request->getHeader( 'x-github-event' ) );
		self::assertSame( array( ' push ', 'push', 'push' ), $request->getRawHeaderValues( 'x-github-event' ) );
		self::assertSame( array(), $request->getRawHeaderValues( 'authorization' ) );
	}

	public function testWebhookHeaderNamesAreCaseAndSeparatorInsensitive(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			'{}',
			array(
				'X_EVENT_KEY'     => array( 'repo:push' ),
				'x_request_UUID'  => 'request-one',
				'X_HUB_SIGNATURE' => array( 'signature' ),
			),
			self::BITBUCKET_HEADERS
		);

		self::assertSame( 'repo:push', $request->getHeader( 'x-event-key' ) );
		self::assertSame( 'request-one', $request->getHeader( 'X_REQUEST_UUID' ) );
		self::assertSame( 'signature', $request->getHeader( 'x-hub-signature' ) );
	}

	public function testWebhookRequestDropsAllUnplannedAndSensitiveHeaders(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'Authorization'   => array( 'Bearer omitted' ),
				'Cookie'          => array( 'session=omitted' ),
				'X-Custom-Secret' => array( 'omitted' ),
				'X-GitHub-Event'  => array( 'push' ),
			),
			self::GITHUB_HEADERS
		);

		self::assertNull( $request->getHeader( 'authorization' ) );
		self::assertNull( $request->getHeader( 'cookie' ) );
		self::assertNull( $request->getHeader( 'x-custom-secret' ) );
		self::assertSame( 'push', $request->getHeader( 'x-github-event' ) );
	}

	public function testWebhookRequestRejectsAmbiguousRetainedHeaderValues(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => array( 'push', 'ping' ) ),
			self::GITHUB_HEADERS
		);
	}

	public function testWebhookRequestRejectsMissingRetainedHeaderValues(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => array() ),
			self::GITHUB_HEADERS
		);
	}

	public function testWebhookRequestRejectsOversizedRetainedHeaderValues(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => str_repeat( 'e', 257 ) ),
			self::GITHUB_HEADERS
		);
	}

	public function testWebhookRequestRejectsOversizedAggregateRetainedHeaders(): void {
		$headers = array();
		$policy  = array();
		foreach ( range( 1, 16 ) as $index ) {
			$name             = 'x-provider-' . $index;
			$headers[ $name ] = str_repeat( 'v', 128 );
			$policy[]         = $name;
		}

		$this->expectException( InvalidArgumentException::class );
		new WebhookRequest( ProviderCode::parse( 'gh' ), '{}', $headers, $policy );
	}

	public function testValidCredentialValidationResultContainsNoDisplayMessage(): void {
		self::assertTrue( CredentialValidationResult::valid()->isValid() );
		self::assertNull( CredentialValidationResult::valid()->getDisplayMessage() );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function untrustedCredentialValidationMessages(): array {
		return array(
			'canary'    => array(
				'invalid',
				'provider-validation-canary',
				CredentialValidationResult::INVALID,
				'The repository provider rejected this credential.',
			),
			'header'    => array(
				'unavailable',
				'Authorization: Bearer provider-header-canary',
				CredentialValidationResult::UNAVAILABLE,
				'The repository provider could not validate this credential. Try again later.',
			),
			'multiline' => array(
				'rateLimited',
				"Validation failed\r\nSet-Cookie: provider-multiline-canary=1",
				CredentialValidationResult::RATE_LIMITED,
				'The repository provider rate-limited credential validation. Try again later.',
			),
			'oversize'  => array(
				'invalidResponse',
				str_repeat( 'provider-oversize-canary-', 256 ),
				CredentialValidationResult::INVALID_RESPONSE,
				'The repository provider returned an invalid credential-validation response.',
			),
		);
	}

	#[DataProvider( 'untrustedCredentialValidationMessages' )]
	public function testCredentialValidationResultDiscardsUntrustedProviderText(
		string $factory,
		string $untrustedMessage,
		string $reason,
		string $expectedMessage
	): void {
		$factoryMethod = new \ReflectionMethod( CredentialValidationResult::class, $factory );
		$result        = $factoryMethod->invokeArgs( null, array( $untrustedMessage ) );

		self::assertSame( 0, $factoryMethod->getNumberOfParameters() );
		self::assertInstanceOf( CredentialValidationResult::class, $result );
		self::assertFalse( $result->isValid() );
		self::assertSame( $reason, $result->reason );
		self::assertSame( $expectedMessage, $result->getDisplayMessage() );
		self::assertLessThanOrEqual( 160, strlen( $expectedMessage ) );
		self::assertDoesNotMatchRegularExpression( '/[\x00-\x1F\x7F]/', $expectedMessage );
		self::assertStringNotContainsString( 'canary', $expectedMessage );
		self::assertStringNotContainsString( $untrustedMessage, $expectedMessage );
	}

	/**
	 * @return list<array{class-string}>
	 */
	public static function credentialFreeDataTransferObjects(): array {
		return array(
			array( RepositoryDescriptor::class ),
			array( RepositoryBrowseRequest::class ),
			array( RepositoryReference::class ),
			array( ArchiveRequest::class ),
			array( PushEvent::class ),
		);
	}

	#[DataProvider( 'credentialFreeDataTransferObjects' )]
	public function testDataTransferObjectsDoNotExposeRawSecretOrTokenFields( string $class ): void {
		$properties = array_map(
			static fn ( \ReflectionProperty $property ): string => $property->getName(),
			( new ReflectionClass( $class ) )->getProperties()
		);

		self::assertDoesNotMatchRegularExpression( '/(?:token|secret)/i', implode( ' ', $properties ) );
	}
}

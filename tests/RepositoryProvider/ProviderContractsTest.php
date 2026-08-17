<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;

final class ProviderContractsTest extends TestCase {
	public function testReleaseCandidateListingIsOneExactTypedCapability(): void {
		self::assertTrue( is_subclass_of( RepositoryReleaseCandidateListing::class, \RAN\Provider\ProviderCapability::class ) );

		$methods = get_class_methods( RepositoryReleaseCandidateListing::class );
		self::assertSame( array( 'listReleaseCandidates' ), $methods );

		$method = new \ReflectionMethod( RepositoryReleaseCandidateListing::class, 'listReleaseCandidates' );
		self::assertSame( RepositoryReleaseCandidateList::class, (string) $method->getReturnType() );
		self::assertSame(
			array( 'packageType', 'repository', 'channel' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, $method->getParameters() )
		);
		self::assertSame( RepositoryReference::class, (string) $method->getParameters()[1]->getType() );
	}

	public function testReleaseCandidateValuesAreBoundedAndTyped(): void {
		$candidate = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);
		$list      = new RepositoryReleaseCandidateList( array( $candidate ) );

		self::assertSame( array( $candidate ), $list->candidates );
		self::assertSame( '42', $candidate->providerReleaseId );
		self::assertSame( array( 'example-1.2.3.zip' ), $candidate->expectedAssetNames );
	}

	public function testReleaseCandidateListRejectsUnboundedLists(): void {
		$candidate = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);

		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidateList( array_fill( 0, 9, $candidate ) );
	}

	public function testReleaseCandidateListRejectsDuplicateIdentities(): void {
		$candidate = new RepositoryReleaseCandidate(
			'42',
			'v1.2.3',
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);

		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidateList( array( $candidate, $candidate ) );
	}

	public function testReleaseCandidateRejectsUnboundedProviderValues(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RepositoryReleaseCandidate(
			'42',
			"invalid\ntag",
			'1.2.3',
			false,
			'2026-08-17T12:00:00Z',
			array( 'example-1.2.3.zip' )
		);
	}

	public function testReleaseMetadataIsAnExactOptionalCapability(): void {
		self::assertTrue( is_subclass_of( RepositoryReleaseMetadata::class, \RAN\Provider\ProviderCapability::class ) );

		$methods = get_class_methods( RepositoryReleaseMetadata::class );
		sort( $methods );

		self::assertSame( array( 'expectedUpdateUri', 'releaseDetailsUrl' ), $methods );
		self::assertSame(
			array( 'repository' ),
			array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryReleaseMetadata::class, 'expectedUpdateUri' ) )->getParameters()
			)
		);
		self::assertSame(
			array( 'repository', 'tag' ),
			array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryReleaseMetadata::class, 'releaseDetailsUrl' ) )->getParameters()
			)
		);
	}

	public function testRepositoryProviderHasTheExactMandatoryApiFourSurface(): void {
		$methods = get_class_methods( RepositoryProvider::class );
		sort( $methods );

		self::assertSame(
			array( 'getMetadata', 'getProviderDiagnostics', 'prepareArchive', 'resolveRepository' ),
			$methods
		);
	}

	public function testCredentialedPublicBrowsingIsAnAdditiveOptionalCapability(): void {
		self::assertTrue( is_subclass_of( CredentialedPublicRepositoryBrowser::class, RepositoryBrowser::class ) );

		$methods = get_class_methods( CredentialedPublicRepositoryBrowser::class );
		sort( $methods );

		self::assertSame(
			array( 'browseRepositories', 'getPublicRepositoryBrowseMetadata' ),
			$methods
		);
		self::assertTrue( ( new PublicRepositoryBrowseMetadata( true ) )->supportsProviderDefaultProfile );
		self::assertFalse( ( new PublicRepositoryBrowseMetadata( false ) )->supportsProviderDefaultProfile );
	}

	public function testRepositoryWebhookOperationHasOnlyTheFourFixedActions(): void {
		$fitnessMethods    = get_class_methods( RepositoryWebhookFitness::class );
		$managementMethods = get_class_methods( RepositoryWebhookManagement::class );
		sort( $fitnessMethods );
		sort( $managementMethods );

		self::assertSame( array( 'assessCheck', 'assessReconfigure', 'assessRemove', 'assessSetup' ), $fitnessMethods );
		self::assertSame( array( 'check', 'reconfigure', 'remove', 'setup' ), $managementMethods );
		self::assertSame( 'repository-webhook-management', RepositoryWebhookFitness::OPERATION );
		self::assertSame( 1, RepositoryWebhookFitness::VERSION );
		self::assertSame( RepositoryWebhookFitness::OPERATION, RepositoryWebhookManagement::OPERATION );
		self::assertSame( RepositoryWebhookFitness::VERSION, RepositoryWebhookManagement::VERSION );
		self::assertSame(
			array( 'repositoryId', 'repository', 'credentialProfileId', 'requestCredential' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, ( new \ReflectionMethod( RepositoryWebhookFitness::class, 'assessSetup' ) )->getParameters() )
		);
		self::assertSame(
			array( 'repositoryId', 'repository', 'credentialProfileId', 'hookId', 'requestCredential' ),
			array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->name, ( new \ReflectionMethod( RepositoryWebhookFitness::class, 'assessRemove' ) )->getParameters() )
		);

		foreach ( array( 'check', 'remove' ) as $method ) {
			$names = array_map(
				static fn ( \ReflectionParameter $parameter ): string => $parameter->name,
				( new \ReflectionMethod( RepositoryWebhookManagement::class, $method ) )->getParameters()
			);
			self::assertSame(
				array( 'repositoryId', 'repository', 'hookId', 'callbackUrl', 'credentialProfileId', 'requestCredential' ),
				$names,
				'Exact endpoint ownership requires the Core-derived callback URL.'
			);
		}
	}

	public function testManualCapabilityFixtureResolvesReadonlyLookupValues(): void {
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'fixture' ), 'Fixture', 'https://example.test/', 'Owner' );
			}
		};
		$request  = new RepositoryLookupRequest( 'group/subgroup/package', 'credential-one', true );

		$repository = $provider->resolveRepository( $request );

		self::assertTrue( $provider->getMetadata()->code->equals( $repository->provider ) );
		self::assertSame( $request->locator, $repository->locator );
		self::assertSame( 'package', $repository->packageSlug );
		self::assertSame( 'test:' . hash( 'sha256', $request->locator ), $repository->providerRepositoryId );
		self::assertFalse( $repository->private );
		self::assertSame( 'main', $repository->defaultBranch );
		self::assertSame( $request->credentialId, $repository->credentialId );
		self::assertTrue( $request->publicOnly );
	}

	public function testPreparedArchiveCleanupCanBeImplementedIdempotently(): void {
		$archive = new class() implements PreparedArchive {

			public int $cleanupCount = 0;

			private bool $cleaned = false;

			public function getUrl(): string {
				return 'https://example.test/archive.zip';
			}

			public function getResolvedRef(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verifyCurrentHead(): void {
			}

			public function cleanup(): void {
				if ( $this->cleaned ) {
					return;
				}

				$this->cleaned = true;
				++$this->cleanupCount;
			}
		};

		$archive->cleanup();
		$archive->cleanup();

		self::assertSame( 1, $archive->cleanupCount );
	}

	public function testArchiveCapabilityUsesNormalizedRequestsAndPreparedArchives(): void {
		$archive  = new class() implements PreparedArchive {

			public function getUrl(): string {
				return 'https://example.test/archive.zip';
			}

			public function getResolvedRef(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verifyCurrentHead(): void {
			}

			public function cleanup(): void {
			}
		};
		$provider = new class( $archive ) implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?ArchiveRequest $request = null;

			public function __construct( private PreparedArchive $archive ) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				$this->request = $request;
				return $this->archive;
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );
		$request  = new ArchiveRequest(
			new RepositoryReference( 'owner/repository', '42', true, 'credential-one' ),
			'abcdef'
		);

		$capability = $registry->get( ProviderCode::parse( 'gh' ) );

		self::assertSame( $provider, $capability );
		self::assertSame( $archive, $capability->prepareArchive( $request ) );
		self::assertSame( $request, $provider->request );
	}
}

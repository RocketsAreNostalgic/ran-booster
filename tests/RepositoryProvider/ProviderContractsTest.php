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
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;

final class ProviderContractsTest extends TestCase {
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

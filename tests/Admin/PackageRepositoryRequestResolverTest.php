<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Deployment\DeploymentPolicy;
use RAN\PackageOperation;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RuntimeException;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;

final class PackageRepositoryRequestResolverTest extends TestCase {

	#[DataProvider( 'invalidCommandProviders' )]
	public function testInstallCommandsRequireAnExactProviderCode( array $input ): void {
		$this->expectException( \InvalidArgumentException::class );

		PackageOperation::fromInput(
			'install-plugin',
			array_merge(
				array(
					'repository'   => 'owner/repository',
					'branch'       => 'main',
					'package_slug' => 'repository',
				),
				$input
			)
		);
	}

	/** @return list<array{array<string, mixed>}> */
	public static function invalidCommandProviders(): array {
		return array(
			array( array() ),
			array( array( 'provider' => 'GitHub!' ) ),
		);
	}

	public function testResolvedMetadataOverridesClientValuesAndScopesTheSelectedCredential(): void {
		$opaqueLocator = 'workspace/%2Frepository<tag>';
		$provider      = $this->resolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'bb' ),
				$opaqueLocator,
				'resolved-repository',
				'provider-id-42',
				true,
				'trunk',
				'bitbucket-deploy'
			)
		);
		$resolver      = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		$result = $resolver->resolve(
			array(
				'provider'                            => 'bb',
				'repository'                          => $opaqueLocator,
				'provider_repository_id'              => 'forged-id',
				'provider_repository_identity_source' => 'client',
				'private'                             => '0',
				'credential_id'                       => 'bitbucket-deploy',
				'branch'                              => '',
				'subdirectory'                        => 'packages/resolved-package',
				'deployment_policy'                   => DeploymentPolicy::AUTOMATIC->value,
			)
		);

		self::assertSame( 'bb', $result['provider'] );
		self::assertSame( $opaqueLocator, $result['repository'] );
		self::assertSame( 'provider-id-42', $result['provider_repository_id'] );
		self::assertSame( 'resolved', $result['provider_repository_identity_source'] );
		self::assertSame( '1', $result['private'] );
		self::assertSame( 'bitbucket-deploy', $result['credential_id'] );
		self::assertSame( 'trunk', $result['branch'] );
		self::assertInstanceOf( RepositoryLookupRequest::class, $provider->request );
		self::assertSame( $opaqueLocator, $provider->request->locator );
		self::assertSame( 'resolved-package', $result['package_slug'] );
		self::assertSame( 'packages/resolved-package', $result['subdirectory'] );
		self::assertSame( 'bitbucket-deploy', $provider->request->credentialId );
		self::assertFalse( $provider->request->publicOnly );
	}

	public function testInstallCommandDerivesItsSlugFromTheConfiguredSubdirectory(): void {
		$operation = PackageOperation::fromInput(
			'install-plugin',
			array(
				'provider'     => 'gh',
				'repository'   => 'owner/repository',
				'branch'       => 'main',
				'package_slug' => 'forged-repository-slug',
				'subdirectory' => 'packages/example-plugin',
			)
		);

		self::assertSame( 'packages/example-plugin', $operation->subdirectory );
		self::assertSame( 'example-plugin', $operation->packageSlug );
	}

	public function testMixedCaseRepositoryNameBecomesOneDeployableInstallationSlug(): void {
		$provider  = $this->resolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'RocketsAreNostalgic/tnyGmaps',
				'tnyGmaps',
				'565105478',
				false,
				'master',
				null
			),
			ProviderCode::parse( 'gh' )
		);
		$resolver  = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );
		$result    = $resolver->resolve(
			array(
				'provider'   => 'gh',
				'repository' => 'RocketsAreNostalgic/tnyGmaps',
				'branch'     => '',
			)
		);
		$operation = PackageOperation::fromInput( 'install-plugin', $result );

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $result['repository'] );
		self::assertSame( '565105478', $result['provider_repository_id'] );
		self::assertSame( 'tnyGmaps', $result['package_slug'] );
		self::assertSame( 'tnygmaps', $operation->packageSlug );
	}

	public function testExplicitBranchIsPreservedOverTheResolvedDefaultBranch(): void {
		$provider = $this->resolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'owner/repository',
				'repository',
				'1001',
				false,
				'main',
				null
			),
			ProviderCode::parse( 'gh' )
		);
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		$result = $resolver->resolve(
			array(
				'provider'   => 'gh',
				'repository' => 'owner/repository',
				'branch'     => 'release/candidate',
			)
		);

		self::assertSame( 'release/candidate', $result['branch'] );
	}

	public function testEveryProviderHasTheManualDeploymentCapabilities(): void {
		self::assertTrue( method_exists( RepositoryProvider::class, 'resolveRepository' ) );
		self::assertTrue( method_exists( RepositoryProvider::class, 'prepareArchive' ) );
	}

	public function testPushToDeployRequiresWebhookCapabilityBeforeResolution(): void {
		$provider = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public int $resolveCalls = 0;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				++$this->resolveCalls;

				throw new RuntimeException( 'Resolution must not be reached.' );
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		try {
			$resolver->resolve(
				array(
					'provider'          => 'gh',
					'repository'        => 'owner/repository',
					'deployment_policy' => DeploymentPolicy::AUTOMATIC->value,
				)
			);
			self::fail( 'Expected Push-to-Deploy to require webhook normalization.' );
		} catch ( UnsupportedProviderCapability ) {
			self::assertSame( 0, $provider->resolveCalls );
		}
	}

	public function testMismatchedProviderResponseIsRejected(): void {
		$provider = $this->resolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'owner/repository',
				'repository',
				'1001',
				false,
				'main',
				null
			),
			ProviderCode::parse( 'bb' )
		);
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'mismatched repository identity' );

		$resolver->resolve(
			array(
				'provider'   => 'bb',
				'repository' => 'owner/repository',
			)
		);
	}

	public function testPublicLookupProfileVerifiesExactlyThenIsRemovedBeforePersistence(): void {
		$provider = $this->credentialedResolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'owner/repository',
				'repository',
				'1001',
				false,
				'main',
				'public_lookup'
			)
		);
		$result   = ( new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) ) )->resolve(
			array(
				'provider'                            => 'gh',
				'repository'                          => 'owner/repository',
				'credential_id'                       => '',
				'public_lookup_profile_id'            => 'public_lookup',
				'provider_repository_identity_source' => 'picker',
			)
		);

		self::assertSame( 'public_lookup', $provider->request->credentialId );
		self::assertTrue( $provider->request->publicOnly );
		self::assertSame( '', $result['credential_id'] );
		self::assertSame( '0', $result['private'] );
		self::assertArrayNotHasKey( 'public_lookup_profile_id', $result );
		self::assertNull( PackageOperation::fromInput( 'install-plugin', $result )->credentialId );
	}

	public function testTransientLookupIdentityRejectsInvalidShapeAndDurableCredentialConflict(): void {
		$provider = $this->credentialedResolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'owner/repository',
				'repository',
				'1001',
				false,
				'main',
				'public_lookup'
			)
		);
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		foreach (
			array(
				array(
					'credential_id'            => '',
					'public_lookup_profile_id' => array( 'public_lookup' ),
				),
				array(
					'credential_id'            => 'package_access',
					'public_lookup_profile_id' => 'public_lookup',
				),
			) as $input
		) {
			try {
				$resolver->resolve(
					$input + array(
						'provider'   => 'gh',
						'repository' => 'owner/repository',
						'provider_repository_identity_source' => 'picker',
					)
				);
				self::fail( 'Expected the conflicting transient identity to be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::assertNull( $provider->request );
			}
		}
	}

	public function testPublicLookupRejectsPrivateExactVerification(): void {
		$provider = $this->credentialedResolvingProvider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'gh' ),
				'owner/private-repository',
				'private-repository',
				'1002',
				true,
				'main',
				'public_lookup'
			)
		);
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'mismatched repository identity' );

		$resolver->resolve(
			array(
				'provider'                            => 'gh',
				'repository'                          => 'owner/private-repository',
				'public_lookup_profile_id'            => 'public_lookup',
				'provider_repository_identity_source' => 'picker',
			)
		);
	}

	private function resolvingProvider(
		RepositoryDescriptor $descriptor,
		?ProviderCode $registeredCode = null
	): RepositoryProvider&WebhookNormalizer {
		$registeredCode ??= ProviderCode::parse( 'bb' );
		return new class( $descriptor, $registeredCode ) implements RepositoryProvider, WebhookNormalizer {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryLookupRequest $request;

			public function __construct(
				private RepositoryDescriptor $descriptor,
				private ProviderCode $registeredCode
			) {
				$this->request = null;
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( $this->registeredCode, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				$this->request = $request;

				return $this->descriptor;
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}

			public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
				throw new RuntimeException( 'Webhook normalization is not used by this test.' );
			}

			public function getWebhookPolicy(): ProviderWebhookPolicy {
				return new InertWebhookPolicy( $this->registeredCode );
			}

			public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
				throw new RuntimeException( 'Webhook readiness is not used by this test.' );
			}
		};
	}

	private function credentialedResolvingProvider(
		RepositoryDescriptor $descriptor
	): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return new class( $descriptor ) implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryLookupRequest $request = null;

			public function __construct( private RepositoryDescriptor $descriptor ) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				throw new RuntimeException( 'Repository browsing is not used by this test.' );
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				$this->request = $request;

				return $this->descriptor;
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
	}
}

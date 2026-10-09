<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

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
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;

final class PackageRepositoryRequestResolverTest extends TestCase {

	/** @param array<string, mixed> $input */
	#[DataProvider( 'invalid_command_providers' )]
	public function test_install_commands_require_an_exact_provider_code( array $input ): void {
		$this->expectException( \InvalidArgumentException::class );

		PackageOperation::from_input(
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
	public static function invalid_command_providers(): array {
		return array(
			array( array() ),
			array( array( 'provider' => 'GitHub!' ) ),
		);
	}

	public function test_resolved_metadata_overrides_client_values_and_scopes_the_selected_credential(): void {
		$opaque_locator = 'workspace/%2Frepository<tag>';
		$provider       = $this->resolving_provider(
			new RepositoryDescriptor(
				ProviderCode::parse( 'bb' ),
				$opaque_locator,
				'resolved-repository',
				'provider-id-42',
				true,
				'trunk',
				'bitbucket-deploy'
			)
		);
		$resolver       = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

		$result = $resolver->resolve(
			array(
				'provider'                            => 'bb',
				'repository'                          => $opaque_locator,
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
		self::assertSame( $opaque_locator, $result['repository'] );
		self::assertSame( 'provider-id-42', $result['provider_repository_id'] );
		self::assertSame( 'resolved', $result['provider_repository_identity_source'] );
		self::assertSame( '1', $result['private'] );
		self::assertSame( 'bitbucket-deploy', $result['credential_id'] );
		self::assertSame( 'trunk', $result['branch'] );
		self::assertInstanceOf( RepositoryLookupRequest::class, $provider->request );
		self::assertSame( $opaque_locator, $provider->request->locator );
		self::assertSame( 'resolved-package', $result['package_slug'] );
		self::assertSame( 'packages/resolved-package', $result['subdirectory'] );
		self::assertSame( 'bitbucket-deploy', $provider->request->credential_id );
		self::assertFalse( $provider->request->public_only );
	}

	public function test_install_command_derives_its_slug_from_the_configured_subdirectory(): void {
		$operation = PackageOperation::from_input(
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
		self::assertSame( 'example-plugin', $operation->package_slug );
	}

	public function test_mixed_case_repository_name_becomes_one_deployable_installation_slug(): void {
		$provider  = $this->resolving_provider(
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
		$resolver  = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );
		$result    = $resolver->resolve(
			array(
				'provider'   => 'gh',
				'repository' => 'RocketsAreNostalgic/tnyGmaps',
				'branch'     => '',
			)
		);
		$operation = PackageOperation::from_input( 'install-plugin', $result );

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $result['repository'] );
		self::assertSame( '565105478', $result['provider_repository_id'] );
		self::assertSame( 'tnyGmaps', $result['package_slug'] );
		self::assertSame( 'tnygmaps', $operation->package_slug );
	}

	public function test_nested_fixture_install_uses_the_same_directory_with_or_without_atrailing_slash(): void {
		$provider = $this->resolving_provider(
			new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'RocketsAreNostalgic/booster-fixture-plugin', 'booster-fixture-plugin', '1315521150', false, 'main', null ),
			ProviderCode::parse( 'gh' )
		);
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );
		foreach ( array( 'branch-fixture', 'branch-fixture/' ) as $subdirectory ) {
			$request   = $resolver->resolve(
				array(
					'provider'     => 'gh',
					'repository'   => 'RocketsAreNostalgic/booster-fixture-plugin',
					'branch'       => 'main',
					'subdirectory' => $subdirectory,
				)
			);
			$operation = PackageOperation::from_input( 'install-plugin', $request );

			self::assertSame( 'branch-fixture', $operation->subdirectory );
			self::assertSame( 'branch-fixture', $operation->package_slug );
			self::assertSame( '1315521150', $operation->provider_repository_id );
		}
	}

	public function test_explicit_branch_is_preserved_over_the_resolved_default_branch(): void {
		$provider = $this->resolving_provider(
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
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

		$result = $resolver->resolve(
			array(
				'provider'   => 'gh',
				'repository' => 'owner/repository',
				'branch'     => 'release/candidate',
			)
		);

		self::assertSame( 'release/candidate', $result['branch'] );
	}

	public function test_every_provider_has_the_manual_deployment_capabilities(): void {
		self::assertContains( 'resolve_repository', get_class_methods( RepositoryProvider::class ) );
		self::assertContains( 'prepare_archive', get_class_methods( RepositoryProvider::class ) );
	}

	public function test_push_to_deploy_requires_webhook_capability_before_resolution(): void {
		$provider = new class() implements RepositoryProvider {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public int $resolve_calls = 0;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of resolve_repository retains the production method contract; these inputs do not affect this controlled result.
			public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				++$this->resolve_calls;

				throw new RuntimeException( 'Resolution must not be reached.' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
			public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

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
			self::assertSame( 0, $provider->resolve_calls );
		}
	}

	public function test_mismatched_provider_response_is_rejected(): void {
		$provider = $this->resolving_provider(
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
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'mismatched repository identity' );

		$resolver->resolve(
			array(
				'provider'   => 'bb',
				'repository' => 'owner/repository',
			)
		);
	}

	public function test_public_lookup_profile_verifies_exactly_then_is_removed_before_persistence(): void {
		$provider = $this->credentialed_resolving_provider(
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
		$result   = ( new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) ) )->resolve(
			array(
				'provider'                            => 'gh',
				'repository'                          => 'owner/repository',
				'credential_id'                       => '',
				'public_lookup_profile_id'            => 'public_lookup',
				'provider_repository_identity_source' => 'picker',
			)
		);

		self::assertNotNull( $provider->request );
		self::assertSame( 'public_lookup', $provider->request->credential_id );
		self::assertTrue( $provider->request->public_only );
		self::assertSame( '', $result['credential_id'] );
		self::assertSame( '0', $result['private'] );
		self::assertArrayNotHasKey( 'public_lookup_profile_id', $result );
		self::assertNull( PackageOperation::from_input( 'install-plugin', $result )->credential_id );
	}

	public function test_transient_lookup_identity_rejects_invalid_shape_and_durable_credential_conflict(): void {
		$provider = $this->credentialed_resolving_provider(
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
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

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

	public function test_public_lookup_rejects_private_exact_verification(): void {
		$provider = $this->credentialed_resolving_provider(
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
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

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

	/** @return RepositoryProvider&WebhookNormalizer&object{request: ?RepositoryLookupRequest} */
	private function resolving_provider(
		RepositoryDescriptor $descriptor,
		?ProviderCode $registered_code = null
	): RepositoryProvider&WebhookNormalizer {
		$registered_code ??= ProviderCode::parse( 'bb' );
		return new class( $descriptor, $registered_code ) implements RepositoryProvider, WebhookNormalizer {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryLookupRequest $request;

			public function __construct(
				private RepositoryDescriptor $descriptor,
				private ProviderCode $registered_code
			) {
				$this->request = null;
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( $this->registered_code, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				$this->request = $request;

				return $this->descriptor;
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
			public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
			public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
				throw new RuntimeException( 'Webhook normalization is not used by this test.' );
			}

			public function get_webhook_policy(): ProviderWebhookPolicy {
				return new InertWebhookPolicy( $this->registered_code );
			}

			public function diagnose_webhook_readiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
				throw new RuntimeException( 'Webhook readiness is not used by this test.' );
			}
		};
	}

	/** @return RepositoryProvider&CredentialedPublicRepositoryBrowser&object{request: ?RepositoryLookupRequest} */
	private function credentialed_resolving_provider(
		RepositoryDescriptor $descriptor
	): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return new class( $descriptor ) implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryLookupRequest $request = null;

			public function __construct( private RepositoryDescriptor $descriptor ) {
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of browse_repositories retains the production method contract; these inputs do not affect this controlled result.
			public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				throw new RuntimeException( 'Repository browsing is not used by this test.' );
			}

			public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				$this->request = $request;

				return $this->descriptor;
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
			public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
				throw new RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
	}
}

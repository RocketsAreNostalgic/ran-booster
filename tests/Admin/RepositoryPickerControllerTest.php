<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\RepositoryPickerController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryProvider;
use RuntimeException;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

final class RepositoryPickerControllerTest extends TestCase {

	private InMemoryPublicRepositoryLookupProfileStore $publicLookupProfiles;

	protected function setUp(): void {
		parent::setUp();

		$_POST                      = array();
		$this->publicLookupProfiles = new InMemoryPublicRepositoryLookupProfileStore();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_repository_admin_translations'] );

		parent::tearDown();
	}

	public function testAccessibleBrowseRoutesToTheSelectedProviderAndCredential(): void {
		$provider = $this->browserProvider(
			ProviderCode::parse( 'bb' ),
			array(
				new RepositoryDescriptor(
					ProviderCode::parse( 'bb' ),
					'workspace/repository',
					'repository',
					'provider-id-42',
					true,
					'trunk',
					'bitbucket-deploy'
				),
			)
		);
		$_POST    = array(
			'provider'      => 'bb',
			'mode'          => 'accessible',
			'credential_id' => 'bitbucket-deploy',
		);

		$result = $this->controller( $provider )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( 'bb', $result['data']['repositories'][0]['provider'] );
		self::assertSame( 'workspace/repository', $result['data']['repositories'][0]['locator'] );
		self::assertSame( 'repository', $result['data']['repositories'][0]['package_slug'] );
		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'bitbucket-deploy', $provider->request->getCredentialId() );
	}

	public function testAccessibleBrowseRequiresOneSelectedCredential(): void {
		$provider = $this->browserProvider( ProviderCode::parse( 'bb' ), array() );
		$_POST    = array(
			'provider'      => 'bb',
			'mode'          => 'accessible',
			'credential_id' => 'all',
		);

		$this->controller( $provider )->handle();

		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'all', $provider->request->getCredentialId() );

		$_POST = array(
			'provider' => 'bb',
			'mode'     => 'accessible',
		);

		$missing = $this->controller( $provider )->handle();

		self::assertFalse( $missing['success'] );
		self::assertSame( 400, $missing['status'] );
		self::assertSame( 'all', $provider->request->getCredentialId() );
	}

	public function testAnonymousPublicBrowseKeepsUsingTheExistingBrowserCapability(): void {
		$provider = $this->browserProvider( ProviderCode::parse( 'gh' ), array() );
		$_POST    = array(
			'provider' => 'gh',
			'mode'     => 'public',
			'owner'    => 'RocketsAreNostalgic',
		);

		$result = $this->controller( $provider )->handle();

		self::assertTrue( $result['success'] );
		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'RocketsAreNostalgic', $provider->request->getOwner() );
		self::assertNull( $provider->request->getCredentialId() );
	}

	public function testUnreadableSidecarBlocksCredentialedBrowsingBeforeTheProviderRequest(): void {
		foreach ( array( 'accessible', 'public' ) as $mode ) {
			$provider = 'accessible' === $mode
				? $this->browserProvider( ProviderCode::parse( 'gh' ), array() )
				: $this->credentialedPublicBrowserProviderWithDefaultSupport( ProviderCode::parse( 'gh' ), array(), false );
			$_POST    = array(
				'provider'                 => 'gh',
				'mode'                     => $mode,
				'credential_id'            => 'broken-profile',
				'owner'                    => 'RocketsAreNostalgic',
				'public_lookup_identity'   => 'profile',
				'public_lookup_profile_id' => 'broken-profile',
			);

			$result = $this->controller( $provider, array( 'broken-profile' ), true )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 409, $result['status'] );
			self::assertStringContainsString( 'Restore the matching sidecar and site key', $result['data']['message'] );
			self::assertStringNotContainsString( '/private/path-canary', $result['data']['message'] );
			self::assertNull( $provider->request );
		}
	}

	public function testConfiguredDefaultIsResolvedOnceAndReturnedForSaveVerification(): void {
		$provider = $this->credentialedPublicBrowserProvider( ProviderCode::parse( 'gh' ), array() );
		$this->publicLookupProfiles->set( 'gh', 'Public_Profile' );
		$_POST = array(
			'provider'               => 'gh',
			'mode'                   => 'public',
			'owner'                  => 'RocketsAreNostalgic',
			'public_lookup_identity' => 'default',
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( 'Public_Profile', $provider->request->getCredentialId() );
		self::assertSame( 'Public_Profile', $result['data']['public_lookup_profile_id'] );
	}

	public function testMissingOrStaleDefaultFailsWithoutAnonymousFallback(): void {
		foreach ( array( null, 'missing_profile' ) as $configuredId ) {
			$provider = $this->credentialedPublicBrowserProvider( ProviderCode::parse( 'gh' ), array() );
			$this->publicLookupProfiles->set( 'gh', $configuredId );
			$_POST = array(
				'provider'               => 'gh',
				'mode'                   => 'public',
				'owner'                  => 'RocketsAreNostalgic',
				'public_lookup_identity' => 'default',
			);

			$result = $this->controller( $provider )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 400, $result['status'] );
			self::assertNull( $provider->request );
		}
	}

	public function testAnonymousOverrideDoesNotUseTheConfiguredDefault(): void {
		$provider = $this->credentialedPublicBrowserProvider( ProviderCode::parse( 'gh' ), array() );
		$this->publicLookupProfiles->set( 'gh', 'Public_Profile' );
		$_POST = array(
			'provider'               => 'gh',
			'mode'                   => 'public',
			'owner'                  => 'RocketsAreNostalgic',
			'public_lookup_identity' => 'anonymous',
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertNull( $provider->request->getCredentialId() );
		self::assertSame( '', $result['data']['public_lookup_profile_id'] );
	}

	public function testArrayPublicLookupProfileIsRejected(): void {
		$provider = $this->credentialedPublicBrowserProviderWithDefaultSupport( ProviderCode::parse( 'gh' ), array(), false );
		$_POST    = array(
			'provider'                 => 'gh',
			'mode'                     => 'public',
			'owner'                    => 'RocketsAreNostalgic',
			'public_lookup_identity'   => 'profile',
			'public_lookup_profile_id' => array( 'Public_Profile' ),
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 400, $result['status'] );
		self::assertNull( $provider->request );
	}

	public function testCredentialedPublicBrowseRequiresTheOptionalCapability(): void {
		$provider = $this->browserProvider( ProviderCode::parse( 'gh' ), array() );
		$_POST    = array(
			'provider'                 => 'gh',
			'mode'                     => 'public',
			'owner'                    => 'RocketsAreNostalgic',
			'public_lookup_identity'   => 'profile',
			'public_lookup_profile_id' => 'public-lookup',
		);

		$result = $this->controller( $provider, array( 'public-lookup' ) )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 501, $result['status'] );
		self::assertSame(
			'The selected repository provider does not support authenticated public repository browsing.',
			$result['data']['message']
		);
		self::assertNull( $provider->request );
	}

	public function testMalformedCredentialedPublicIdentityDoesNotFallBackToAnonymous(): void {
		foreach ( array( 'Public_Profile!', '   ', "Public_Profile\n" ) as $credentialId ) {
			$provider = $this->credentialedPublicBrowserProvider( ProviderCode::parse( 'gh' ), array() );
			$_POST    = array(
				'provider'                 => 'gh',
				'mode'                     => 'public',
				'owner'                    => 'RocketsAreNostalgic',
				'public_lookup_identity'   => 'profile',
				'public_lookup_profile_id' => $credentialId,
			);

			$result = $this->controller( $provider )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 400, $result['status'] );
			self::assertNull( $provider->request );
		}
	}

	public function testExplicitPublicProfileWorksAlongsideProviderDefaultSupport(): void {
		$provider = $this->credentialedPublicBrowserProviderWithDefaultSupport( ProviderCode::parse( 'gh' ), array(), true );
		$_POST    = array(
			'provider'                 => 'gh',
			'mode'                     => 'public',
			'owner'                    => 'RocketsAreNostalgic',
			'public_lookup_identity'   => 'profile',
			'public_lookup_profile_id' => 'Public_Profile',
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'RocketsAreNostalgic', $provider->request->getOwner() );
		self::assertSame( 'Public_Profile', $provider->request->getCredentialId() );
		self::assertSame( 'Public_Profile', $result['data']['public_lookup_profile_id'] );
	}

	public function testPublicBrowseRejectsPrivateOrCredentialBearingDescriptors(): void {
		foreach ( array( 'private', 'credential' ) as $case ) {
			$provider = $this->credentialedPublicBrowserProviderWithDefaultSupport(
				ProviderCode::parse( 'gh' ),
				array(
					new RepositoryDescriptor(
						ProviderCode::parse( 'gh' ),
						'owner/repository',
						'repository',
						'42',
						'private' === $case,
						'main',
						'credential' === $case ? 'public-lookup' : null
					),
				),
				false
			);
			$_POST    = array(
				'provider'                 => 'gh',
				'mode'                     => 'public',
				'owner'                    => 'owner',
				'public_lookup_identity'   => 'profile',
				'public_lookup_profile_id' => 'public-lookup',
			);

			$result = $this->controller( $provider, array( 'public-lookup' ) )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 502, $result['status'] );
			self::assertArrayNotHasKey( 'repositories', $result['data'] );
		}
	}

	public function testProviderWithoutBrowsingCapabilityFailsClosed(): void {
		$provider = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}
		};
		$_POST    = array(
			'provider' => 'gh',
			'mode'     => 'accessible',
		);

		$result = $this->controller( $provider )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 501, $result['status'] );
		self::assertSame(
			'The selected repository provider does not support repository browsing.',
			$result['data']['message']
		);
	}

	public function testUnavailableProviderNeverFallsBackAndSameProviderReactivationRestoresBrowsing(): void {
		$github = $this->browserProvider( ProviderCode::parse( 'gh' ), array() );
		$_POST  = array(
			'provider'      => 'temporarily-offline',
			'mode'          => 'accessible',
			'credential_id' => 'fixture-profile',
		);

		$unavailable = $this->controller( $github )->handle();

		self::assertFalse( $unavailable['success'] );
		self::assertSame( 400, $unavailable['status'] );
		self::assertSame( 'The selected repository provider is not available.', $unavailable['data']['message'] );
		self::assertNull( $github->request );

		$code        = ProviderCode::parse( 'temporarily-offline' );
		$reactivated = $this->browserProvider(
			$code,
			array(
				new RepositoryDescriptor(
					$code,
					'owner/repository',
					'repository',
					'stable-repository-id',
					false,
					'main',
					null
				),
			)
		);

		$restored = $this->controller( $reactivated )->handle();

		self::assertTrue( $restored['success'] );
		self::assertSame( 'temporarily-offline', $restored['data']['repositories'][0]['provider'] );
		self::assertSame( 'stable-repository-id', $restored['data']['repositories'][0]['provider_repository_id'] );
	}

	public function testMismatchedProviderResponseIsRejectedWithoutReturningRepositoryData(): void {
		$provider = $this->browserProvider(
			ProviderCode::parse( 'bb' ),
			array(
				new RepositoryDescriptor(
					ProviderCode::parse( 'gh' ),
					'owner/repository',
					'repository',
					'1001',
					false,
					'main',
					null
				),
			)
		);
		$_POST    = array(
			'provider'      => 'bb',
			'mode'          => 'accessible',
			'credential_id' => 'fixture-profile',
		);

		$result = $this->controller( $provider )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 502, $result['status'] );
		self::assertArrayNotHasKey( 'repositories', $result['data'] );
		self::assertSame( 'Repository browsing failed. Please try again.', $result['data']['message'] );
	}

	public function testRateLimitFailureReturnsAProviderNeutralNoticeWithoutUpstreamDetails(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				throw new RuntimeException(
					'upstream-response-canary; Retry-After: header-canary; token-canary',
					429
				);
			}
		};
		$_POST    = array(
			'provider'      => 'gh',
			'mode'          => 'accessible',
			'credential_id' => 'fixture-profile',
		);

		$result = $this->controller( $provider )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 429, $result['status'] );
		self::assertSame(
			'The repository provider rate limit has been reached. Try again later.',
			$result['data']['message']
		);
		self::assertStringNotContainsString( 'upstream-response-canary', $result['data']['message'] );
		self::assertStringNotContainsString( 'header-canary', $result['data']['message'] );
		self::assertStringNotContainsString( 'token-canary', $result['data']['message'] );
	}

	public function testPartialResultsUseOnlyTheControllersFixedSafeMessage(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				return new \RAN\RepositoryProvider\RepositoryBrowseResult(
					array(
						new RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'repository', '42', false, 'main', null ),
					),
					\RAN\RepositoryProvider\RepositoryBrowseResult::RATE_LIMIT
				);
			}
		};
		$_POST    = array(
			'provider'      => 'gh',
			'mode'          => 'accessible',
			'credential_id' => 'profile',
		);

		$result = $this->controller( $provider )->handle();

		self::assertTrue( $result['success'], isset( $result['data']['message'] ) ? (string) $result['data']['message'] : 'Expected successful partial results.' );
		self::assertTrue( $result['data']['partial'] );
		self::assertSame(
			'Some repositories are shown. The provider rate limit was reached; try again later for a complete list.',
			$result['data']['message']
		);
	}

	public function testPartialResultDisplayCopyUsesThePluginTranslationDomain(): void {
		$source = 'Some repositories are shown. The provider rate limit was reached; try again later for a complete list.';
		$GLOBALS['ran_booster_repository_admin_translations'] = array(
			'ran-booster' => array( $source => 'Les dépôts affichés sont incomplets.' ),
		);
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				return new \RAN\RepositoryProvider\RepositoryBrowseResult( array(), \RAN\RepositoryProvider\RepositoryBrowseResult::RATE_LIMIT );
			}
		};
		$_POST    = array(
			'provider'      => 'gh',
			'mode'          => 'accessible',
			'credential_id' => 'profile',
		);

		$result = $this->controller( $provider )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( 'Les dépôts affichés sont incomplets.', $result['data']['message'] );
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	private function browserProvider( ProviderCode $code, array $repositories ): RepositoryProvider&RepositoryBrowser {
		return new class( $code, $repositories ) implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryBrowseRequest $request;

			/**
			 * @param list<RepositoryDescriptor> $repositories
			 */
			public function __construct(
				private ProviderCode $code,
				private array $repositories
			) {
				$this->request = null;
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( $this->code, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				$this->request = $request;

				return new \RAN\RepositoryProvider\RepositoryBrowseResult( $this->repositories );
			}
		};
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	private function credentialedPublicBrowserProvider( ProviderCode $code, array $repositories ): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return $this->credentialedPublicBrowserProviderWithDefaultSupport( $code, $repositories, true );
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	private function credentialedPublicBrowserProviderWithDefaultSupport(
		ProviderCode $code,
		array $repositories,
		bool $supportsDefault
	): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return new class( $code, $repositories, $supportsDefault ) implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryBrowseRequest $request = null;

			/**
			 * @param list<RepositoryDescriptor> $repositories
			 */
			public function __construct(
				private ProviderCode $code,
				private array $repositories,
				private bool $supportsDefault
			) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( $this->code, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( $this->supportsDefault );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				$this->request = $request;

				return new \RAN\RepositoryProvider\RepositoryBrowseResult( $this->repositories );
			}
		};
	}

	/**
	 * @param list<string> $profileIds
	 */
	private function controller(
		RepositoryProvider $provider,
		array $profileIds = array(),
		bool $storageUnavailable = false
	): RepositoryPickerController {
		$providerCode = $provider->getMetadata()->code->value;
		$profiles     = array();
		foreach ( $profileIds as $profileId ) {
			$profiles[ $profileId ] = array(
				'id'         => $profileId,
				'configured' => true,
			);
		}

		return new RepositoryPickerController(
			new ProviderRegistry( array( $provider ) ),
			new RepositoryPickerSecretsFile( array( $providerCode => $profiles ), $storageUnavailable ),
			$this->publicLookupProfiles
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused display-safe secrets fixture.
final class RepositoryPickerSecretsFile extends SecretsFile {

	/** @param array<string, array<string, array<string, mixed>>> $profiles */
	public function __construct( private array $profiles, private bool $storageUnavailable = false ) {
	}

	public function credentialProfiles( ProviderCode|string $provider ): array {
		if ( $this->storageUnavailable ) {
			throw new SecretsStorageUnavailable( 'Unreadable sidecar at /private/path-canary.' );
		}
		$code = $provider instanceof ProviderCode ? $provider->value : $provider;

		return $this->profiles[ $code ] ?? array();
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

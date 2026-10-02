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

	private InMemoryPublicRepositoryLookupProfileStore $public_lookup_profiles;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$_POST                        = array();
		$this->public_lookup_profiles = new InMemoryPublicRepositoryLookupProfileStore();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_repository_admin_translations'] );

		parent::tearDown();
	}

	public function test_accessible_browse_routes_to_the_selected_provider_and_credential(): void {
		$provider = $this->browser_provider(
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
		self::assertSame( 'bitbucket-deploy', $provider->request->get_credential_id() );
	}

	public function test_accessible_browse_requires_one_selected_credential(): void {
		$provider = $this->browser_provider( ProviderCode::parse( 'bb' ), array() );
		$_POST    = array(
			'provider'      => 'bb',
			'mode'          => 'accessible',
			'credential_id' => 'all',
		);

		$this->controller( $provider )->handle();

		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'all', $provider->request->get_credential_id() );

		$_POST = array(
			'provider' => 'bb',
			'mode'     => 'accessible',
		);

		$missing = $this->controller( $provider )->handle();

		self::assertFalse( $missing['success'] );
		self::assertSame( 400, $missing['status'] );
		self::assertSame( 'all', $provider->request->get_credential_id() );
	}

	public function test_anonymous_public_browse_keeps_using_the_existing_browser_capability(): void {
		$provider = $this->browser_provider( ProviderCode::parse( 'gh' ), array() );
		$_POST    = array(
			'provider' => 'gh',
			'mode'     => 'public',
			'owner'    => 'RocketsAreNostalgic',
		);

		$result = $this->controller( $provider )->handle();

		self::assertTrue( $result['success'] );
		self::assertInstanceOf( RepositoryBrowseRequest::class, $provider->request );
		self::assertSame( 'RocketsAreNostalgic', $provider->request->get_owner() );
		self::assertNull( $provider->request->get_credential_id() );
	}

	public function test_unreadable_sidecar_blocks_credentialed_browsing_before_the_provider_request(): void {
		foreach ( array( 'accessible', 'public' ) as $mode ) {
			$provider = 'accessible' === $mode
				? $this->browser_provider( ProviderCode::parse( 'gh' ), array() )
				: $this->credentialed_public_browser_provider_with_default_support( ProviderCode::parse( 'gh' ), array(), false );
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

	public function test_configured_default_is_resolved_once_and_returned_for_save_verification(): void {
		$provider = $this->credentialed_public_browser_provider( ProviderCode::parse( 'gh' ), array() );
		$this->public_lookup_profiles->set( 'gh', 'Public_Profile' );
		$_POST = array(
			'provider'               => 'gh',
			'mode'                   => 'public',
			'owner'                  => 'RocketsAreNostalgic',
			'public_lookup_identity' => 'default',
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( 'Public_Profile', $provider->request->get_credential_id() );
		self::assertSame( 'Public_Profile', $result['data']['public_lookup_profile_id'] );
	}

	public function test_missing_or_stale_default_fails_without_anonymous_fallback(): void {
		foreach ( array( null, 'missing_profile' ) as $configured_id ) {
			$provider = $this->credentialed_public_browser_provider( ProviderCode::parse( 'gh' ), array() );
			$this->public_lookup_profiles->set( 'gh', $configured_id );
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

	public function test_anonymous_override_does_not_use_the_configured_default(): void {
		$provider = $this->credentialed_public_browser_provider( ProviderCode::parse( 'gh' ), array() );
		$this->public_lookup_profiles->set( 'gh', 'Public_Profile' );
		$_POST = array(
			'provider'               => 'gh',
			'mode'                   => 'public',
			'owner'                  => 'RocketsAreNostalgic',
			'public_lookup_identity' => 'anonymous',
		);

		$result = $this->controller( $provider, array( 'Public_Profile' ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertNull( $provider->request->get_credential_id() );
		self::assertSame( '', $result['data']['public_lookup_profile_id'] );
	}

	public function test_array_public_lookup_profile_is_rejected(): void {
		$provider = $this->credentialed_public_browser_provider_with_default_support( ProviderCode::parse( 'gh' ), array(), false );
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

	public function test_credentialed_public_browse_requires_the_optional_capability(): void {
		$provider = $this->browser_provider( ProviderCode::parse( 'gh' ), array() );
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

	public function test_malformed_credentialed_public_identity_does_not_fall_back_to_anonymous(): void {
		foreach ( array( 'Public_Profile!', '   ', "Public_Profile\n" ) as $credential_id ) {
			$provider = $this->credentialed_public_browser_provider( ProviderCode::parse( 'gh' ), array() );
			$_POST    = array(
				'provider'                 => 'gh',
				'mode'                     => 'public',
				'owner'                    => 'RocketsAreNostalgic',
				'public_lookup_identity'   => 'profile',
				'public_lookup_profile_id' => $credential_id,
			);

			$result = $this->controller( $provider )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 400, $result['status'] );
			self::assertNull( $provider->request );
		}
	}

	public function test_explicit_public_profile_works_alongside_provider_default_support(): void {
		$provider = $this->credentialed_public_browser_provider_with_default_support( ProviderCode::parse( 'gh' ), array(), true );
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
		self::assertSame( 'RocketsAreNostalgic', $provider->request->get_owner() );
		self::assertSame( 'Public_Profile', $provider->request->get_credential_id() );
		self::assertSame( 'Public_Profile', $result['data']['public_lookup_profile_id'] );
	}

	public function test_public_browse_rejects_private_or_credential_bearing_descriptors(): void {
		foreach ( array( 'private', 'credential' ) as $case ) {
			$provider = $this->credentialed_public_browser_provider_with_default_support(
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

	public function test_provider_without_browsing_capability_fails_closed(): void {
		$provider = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
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

	public function test_unavailable_provider_never_falls_back_and_same_provider_reactivation_restores_browsing(): void {
		$github = $this->browser_provider( ProviderCode::parse( 'gh' ), array() );
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
		$reactivated = $this->browser_provider(
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

	public function test_mismatched_provider_response_is_rejected_without_returning_repository_data(): void {
		$provider = $this->browser_provider(
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

	public function test_rate_limit_failure_returns_aprovider_neutral_notice_without_upstream_details(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of browse_repositories retains the production method contract; these inputs do not affect this controlled result.
			public function browse_repositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
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

	public function test_partial_results_use_only_the_controllers_fixed_safe_message(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of browse_repositories retains the production method contract; these inputs do not affect this controlled result.
			public function browse_repositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
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

	public function test_partial_result_display_copy_uses_the_plugin_translation_domain(): void {
		$source = 'Some repositories are shown. The provider rate limit was reached; try again later for a complete list.';
		$GLOBALS['ran_booster_repository_admin_translations'] = array(
			'ran-booster' => array( $source => 'Les dépôts affichés sont incomplets.' ),
		);
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of browse_repositories retains the production method contract; these inputs do not affect this controlled result.
			public function browse_repositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
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
	private function browser_provider( ProviderCode $code, array $repositories ): RepositoryProvider&RepositoryBrowser {
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

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( $this->code, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function browse_repositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				$this->request = $request;

				return new \RAN\RepositoryProvider\RepositoryBrowseResult( $this->repositories );
			}
		};
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	private function credentialed_public_browser_provider( ProviderCode $code, array $repositories ): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return $this->credentialed_public_browser_provider_with_default_support( $code, $repositories, true );
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	private function credentialed_public_browser_provider_with_default_support(
		ProviderCode $code,
		array $repositories,
		bool $supports_default
	): RepositoryProvider&CredentialedPublicRepositoryBrowser {
		return new class( $code, $repositories, $supports_default ) implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public ?RepositoryBrowseRequest $request = null;

			/**
			 * @param list<RepositoryDescriptor> $repositories
			 */
			public function __construct(
				private ProviderCode $code,
				private array $repositories,
				private bool $supports_default
			) {
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( $this->code, 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( $this->supports_default );
			}

			public function browse_repositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				$this->request = $request;

				return new \RAN\RepositoryProvider\RepositoryBrowseResult( $this->repositories );
			}
		};
	}

	/**
	 * @param list<string> $profile_ids
	 */
	private function controller(
		RepositoryProvider $provider,
		array $profile_ids = array(),
		bool $storage_unavailable = false
	): RepositoryPickerController {
		$provider_code = $provider->get_metadata()->code->value;
		$profiles      = array();
		foreach ( $profile_ids as $profile_id ) {
			$profiles[ $profile_id ] = array(
				'id'         => $profile_id,
				'configured' => true,
			);
		}

		return new RepositoryPickerController(
			new ProviderRegistry( array( $provider ) ),
			new RepositoryPickerSecretsFile( array( $provider_code => $profiles ), $storage_unavailable ),
			$this->public_lookup_profiles
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused display-safe secrets fixture.
final class RepositoryPickerSecretsFile extends SecretsFile {

	/** @param array<string, array<string, array<string, mixed>>> $profiles */
	public function __construct( private array $profiles, private bool $storage_unavailable = false ) {
	}

	public function credential_profiles( ProviderCode|string $provider ): array {
		if ( $this->storage_unavailable ) {
			throw new SecretsStorageUnavailable( 'Unreadable sidecar at /private/path-canary.' );
		}
		$code = $provider instanceof ProviderCode ? $provider->value : $provider;

		return $this->profiles[ $code ] ?? array();
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Tests\Secrets\SecretsFileTestFactory;

#[CoversClass( BlueprintRepositoryVerifier::class )]
final class BlueprintRepositoryVerifierTest extends TestCase {
	// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Keep fixture tokens split so credential scanners do not treat them as live PATs.
	private const CLASSIC_TOKEN = 'ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
	// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Keep fixture tokens split so credential scanners do not treat them as live PATs.
	private const FINE_GRAINED_TOKEN = 'github_pat_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	private string $directory;
	private string $path;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-portability-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $this->directory );
		}
	}

	public function test_it_retries_only_an_access_failure_with_atemporary_credential(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertSame( array( $provider->temporary_credential_id ), $provider->credential_ids );
		self::assertNotNull( $provider->temporary_credential_id );
		self::assertNull( $secrets->credential_material( 'gh', $provider->temporary_credential_id ) );
		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	/** @param array<string, string> $configuration */
	#[DataProvider( 'transferred_provider_credential_provider' )]
	public function test_transferred_credential_verification_is_provider_neutral(
		string $provider_code,
		string $kind,
		array $configuration,
		string $secret
	): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id', false, $provider_code, $secret );

		$result = $verifier->verify(
			$this->install_item( $provider_code ),
			$this->credential( provider: $provider_code, kind: $kind, configuration: $configuration, secret: $secret ),
			BlueprintCredentialAction::IMPORT
		);

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertCount( 1, $provider->credential_ids );
		self::assertNotNull( $provider->temporary_credential_id );
		self::assertNull( $secrets->credential_material( $provider_code, $provider->temporary_credential_id ) );
		self::assertSame( array(), $secrets->credential_profiles( $provider_code ) );
	}

	/** @return iterable<string, array{string,string,array<string,string>,string}> */
	public static function transferred_provider_credential_provider(): iterable {
		yield 'GitHub classic' => array( 'gh', 'classic', array( 'owner' => '' ), self::CLASSIC_TOKEN );
		yield 'GitHub fine-grained' => array( 'gh', 'fine-grained', array( 'owner' => 'RocketsAreNostalgic' ), self::FINE_GRAINED_TOKEN );
		yield 'Bitbucket API token' => array( 'bb', 'api-token', array( 'account_email' => 'canary@example.test' ), 'sentinel-bitbucket-portability-token' );
	}

	public function test_transferred_material_is_attempted_before_an_explicit_target_credential_without_preview_persistence(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->save_credential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		$before = (string) file_get_contents( $this->path );

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertNotNull( $provider->temporary_credential_id );
		self::assertSame( array( $provider->temporary_credential_id ), $provider->credential_ids );
		self::assertNotSame( 'target-pat', $provider->temporary_credential_id );
		self::assertNull( $secrets->credential_material( 'gh', $provider->temporary_credential_id ) );
		self::assertSame( array( 'target-pat' ), array_keys( $secrets->credential_profiles( 'gh' ) ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		self::assertSame( $before, (string) file_get_contents( $this->path ) );
	}

	#[DataProvider( 'repository_privacy_provider' )]
	public function test_it_preserves_an_associated_credential_for_public_and_private_repositories( bool $is_private ): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id', $is_private );

		$repository_private = null;
		$result             = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT, null, $repository_private );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporary_credential_id ), $provider->credential_ids );
		self::assertNotNull( $provider->temporary_credential_id );
		self::assertSame( $is_private, $repository_private );
	}

	/** @return iterable<string, array{bool}> */
	public static function repository_privacy_provider(): iterable {
		yield 'public' => array( false );
		yield 'private' => array( true );
	}

	public function test_it_leaves_apackage_only_public_repository_anonymous(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$repository_private = null;
		$result             = $verifier->verify( $this->install_item(), null, null, null, $repository_private );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( null ), $provider->credential_ids );
		self::assertFalse( $repository_private );
	}

	public function test_it_does_not_silently_drop_an_invalid_transferred_credential(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$result = $verifier->verify( $this->install_item(), $this->credential( 'example/example.php', 'expired-token' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_package_only_missing_target_choice_never_falls_back_to_anonymous_access(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$result = $verifier->verify( $this->install_item(), null, null, 'deleted-profile' );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_it_does_not_resolve_managed_or_protected_rows(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$result = $verifier->verify( $managed, $this->credential() );
		self::assertSame( $managed->package, $result->package );
		self::assertSame( $managed->action, $result->action );
		self::assertSame( $managed->reason, $result->reason );
		self::assertNotNull( $result->provider_expectation );
		self::assertEquals( $result, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::LEAVE ) );
		self::assertEquals( $result, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::TARGET, 'target-pat' ) );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_it_verifies_only_an_explicit_managed_row_import(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$managed                         = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$repository_private = null;
		$result             = $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::IMPORT, null, $repository_private );

		self::assertSame( $managed->package, $result->package );
		self::assertSame( $managed->action, $result->action );
		self::assertSame( $managed->reason, $result->reason );
		self::assertNotNull( $result->provider_expectation );
		self::assertFalse( $repository_private );
		self::assertSame( array( $provider->temporary_credential_id ), $provider->credential_ids );
		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function test_it_blocks_managed_row_import_bound_to_another_package(): void {
		[$verifier, $provider] = $this->verifier( 404, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$result = $verifier->verify( $managed, $this->credential( 'other/other.php' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_it_blocks_an_access_failure_without_transferred_credentials(): void {
		[$verifier] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->install_item() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
	}

	public function test_it_does_not_use_acredential_bound_to_another_package(): void {
		[$verifier, $provider] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->install_item(), $this->credential( 'other/other.php' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_it_uses_an_explicit_existing_target_credential_after_anonymous_access_fails(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->save_credential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->install_item(), null, null, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( 'target-pat' ), $provider->credential_ids );
	}

	public function test_carried_credential_target_choice_uses_only_the_submitted_target_profile(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->save_credential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::TARGET, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( 'target-pat' ), $provider->credential_ids );
		self::assertSame( 'target-pat', $provider->temporary_credential_id );
	}

	public function test_wrong_provider_target_choice_blocks_without_any_fallback(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$provider = new TemporaryCredentialProvider( $secrets->credentials_for( 'gh' ), 404, 'repository-id' );
		$registry = new ProviderRegistry( array( $provider ), $catalog );
		$registry;
		$catalog->register( ProviderCode::parse( 'bb' ), new TemporaryProviderCredentialPolicy( ProviderCode::parse( 'bb' ) ), null );
		$secrets->save_credential(
			'bb',
			'wrong-provider-profile',
			array(
				'label'         => 'Wrong provider token',
				'kind'          => 'api-token',
				'configuration' => array( 'account_email' => 'canary@example.test' ),
			),
			'sentinel-bitbucket-portability-token'
		);

		$verifier = new BlueprintRepositoryVerifier( $registry, $secrets, GitHubSelectionTestFixture::bind( $registry ) );
		$result   = $verifier->verify(
			$this->install_item(),
			$this->credential(),
			BlueprintCredentialAction::TARGET,
			'wrong-provider-profile'
		);

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );

		$package_only = $verifier->verify( $this->install_item(), null, null, 'wrong-provider-profile' );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $package_only->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	#[DataProvider( 'descriptor_credential_mismatches' )]
	public function test_each_selected_credential_source_requires_the_exact_descriptor_identity( string $source, ?string $reported_id ): void {
		[$verifier, $provider, $secrets] = $this->verifier( 0, 'repository-id' );
		$secrets->save_credential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);
		$provider->reported_credential_id = $reported_id;
		if ( 'candidate' === $source ) {
			$result = $verifier->resolve_candidate( new \RAN\AddOn\Portability\PortabilityCandidate( 'plugin', 'example/example.php', 'Example', 'gh', 'owner/repository', 'main', null, 'target-pat' ) );
			self::assertNull( $result['package'] );
			self::assertNull( $result['private'] );
			self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $result['reason'] );
		} else {
			$result = $verifier->verify( $this->install_item(), 'package' === $source ? null : $this->credential(), 'import' === $source ? BlueprintCredentialAction::IMPORT : ( 'target' === $source ? BlueprintCredentialAction::TARGET : null ), 'import' === $source ? null : 'target-pat' );
			self::assertSame( TargetPackageAction::BLOCKED, $result->action );
			self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $result->reason );
		}
		self::assertCount( 1, $provider->credential_ids );
		self::assertNotNull( $provider->credential_ids[0] );
		if ( 'import' === $source ) {
			self::assertNotNull( $provider->temporary_credential_id );
			self::assertNull( $secrets->credential_material( 'gh', $provider->temporary_credential_id ) );
		} else {
			self::assertSame( array( 'target-pat' ), $provider->credential_ids );
		}
		self::assertSame( array( 'target-pat' ), array_keys( $secrets->credential_profiles( 'gh' ) ) );
	}

	/** @return iterable<string, array{string,string|null}> */
	public static function descriptor_credential_mismatches(): iterable {
		foreach ( array( 'package', 'target', 'import', 'candidate' ) as $source ) {
			foreach ( array(
				'missing'      => null,
				'other'        => 'other-profile',
				'case changed' => 'TARGET-PAT',
			) as $label => $id ) {
				yield $source . ': ' . $label => array( $source, $id );
			}
		}
	}

	public function test_anonymous_lookup_cannot_accept_a_credentialed_or_private_descriptor(): void {
		[$verifier, $provider]            = $this->verifier( 0, 'repository-id' );
		$provider->reported_credential_id = 'target-pat';
		self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $verifier->verify( $this->install_item() )->reason );
		self::assertSame( array( null ), $provider->credential_ids );

		[$private_verifier, $private_provider] = $this->verifier( 0, 'repository-id', true );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $private_verifier->verify( $this->install_item() )->reason );
		self::assertSame( array( null ), $private_provider->credential_ids );
	}

	public function test_public_candidate_retains_existing_constant_profile_support(): void {
		$secrets = $this->createStub( SecretsFile::class );
		$secrets->method( 'credential_profiles' )->willReturn(
			array(
				SecretsFile::CONSTANT_PROFILE => array(
					'id'            => SecretsFile::CONSTANT_PROFILE,
					'provider'      => 'gh',
					'label'         => 'Configured credential',
					'kind'          => 'classic',
					'configuration' => array(),
					'source'        => 'constant',
					'immutable'     => true,
					'configured'    => true,
					'self_destruct' => false,
					'destroy_on'    => null,
				),
			)
		);
		$provider = $this->createMock( \RAN\RepositoryProvider\RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new \RAN\RepositoryProvider\ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' ) );
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturnCallback(
			static function ( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				self::assertSame( SecretsFile::CONSTANT_PROFILE, $request->credential_id );
				return new \RAN\RepositoryProvider\RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/repository', 'example', 'repository-id', true, 'main', $request->credential_id );
			}
		);
		$registry = new ProviderRegistry( array( $provider ) );
		$verifier = new BlueprintRepositoryVerifier( $registry, $secrets, GitHubSelectionTestFixture::bind( $registry ) );
		$result   = $verifier->resolve_candidate( new \RAN\AddOn\Portability\PortabilityCandidate( 'plugin', 'example/example.php', 'Example', 'gh', 'owner/repository', 'main', null, SecretsFile::CONSTANT_PROFILE ) );
		self::assertNotNull( $result['package'] );
		self::assertTrue( $result['private'] );
		self::assertSame( TargetPackageReason::NONE, $result['reason'] );
	}

	public function test_inactive_provider_blocks_transferred_material_and_cleans_the_temporary_profile(): void {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$registry = new ProviderRegistry();
		$verifier = new BlueprintRepositoryVerifier( $registry, $secrets, GitHubSelectionTestFixture::bind( $registry ) );

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_UNAVAILABLE, $result->reason );
		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function test_it_retries_an_anonymous_rate_limit_with_atransferred_credential(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporary_credential_id ), $provider->credential_ids );
	}

	public function test_it_offers_saved_target_credentials_when_anonymous_quota_is_exhausted(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 429, 'repository-id' );
		$secrets->save_credential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->install_item() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array( null ), $provider->credential_ids );
	}

	public function test_it_keeps_an_anonymous_rate_limit_blocked_without_credentials(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );

		$result = $verifier->verify( $this->install_item() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE, $result->reason );
		self::assertSame( array( null ), $provider->credential_ids );
	}

	public function test_it_blocks_temporary_provider_failures_without_credential_intent(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );

		$result = $verifier->verify( $this->install_item() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE, $result->reason );
		self::assertSame( array( null ), $provider->credential_ids );
	}

	public function test_it_blocks_astable_repository_identity_mismatch(): void {
		[$verifier] = $this->verifier( 0, 'different-repository-id' );

		$result = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $result->reason );
	}

	public function test_credential_bearing_rows_require_an_explicit_decision_without_any_provider_attempt(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$unresolved = $verifier->verify( $this->install_item(), $this->credential() );
		$leave      = $verifier->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::LEAVE );

		self::assertSame( TargetPackageAction::BLOCKED, $unresolved->action );
		self::assertSame( TargetPackageAction::BLOCKED, $leave->action );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_preview_reuses_128_successful_rows_but_next_preview_and_apply_are_fresh(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );
		foreach ( array( $verifier->for_preview(), $verifier->for_preview() ) as $preview ) {
			for ( $row = 0; $row < 128; ++$row ) {
				self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item() )->action );
			}
		}
		self::assertCount( 2, $provider->credential_ids );
		$verifier->verify( $this->install_item() );
		self::assertCount( 3, $provider->credential_ids );
	}

	public function test_preview_keeps_each_row_identity_check_after_reuse(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );
		$preview               = $verifier->for_preview();
		$preview->verify( $this->install_item() );
		$other = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'other/other.php', 'Other', 'gh', 'wrong-id', 'owner/repository', 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $preview->verify( $other )->reason );
		self::assertCount( 1, $provider->credential_ids );
	}

	public function test_preview_temporary_equivalence_requires_identical_validated_material(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 0, 'repository-id' );
		$preview                         = $verifier->for_preview();
		for ( $row = 0; $row < 128; ++$row ) {
			self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT )->action );
		}
		self::assertCount( 1, $provider->credential_ids );
		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
		self::assertNull( $secrets->credential_material( 'gh', $provider->temporary_credential_id ) );
		// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Split deliberately fake fixture token for credential scanners.
		$other_secret = 'ghp_' . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd';
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $preview->verify( $this->install_item(), $this->credential( secret: $other_secret ), BlueprintCredentialAction::IMPORT )->reason );
		self::assertCount( 2, $provider->credential_ids );
	}

	public function test_preview_saved_identity_and_rotated_material_never_share_observations(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 0, 'repository-id' );
		$preview                         = $verifier->for_preview();
		$metadata                        = array(
			'label'         => 'Saved',
			'kind'          => 'classic',
			'configuration' => array(),
		);
		foreach ( array( 'one', 'two' ) as $id ) {
			$secrets->save_credential( 'gh', $id, $metadata, self::CLASSIC_TOKEN );
			self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item(), null, null, $id )->action );
		}
		self::assertCount( 2, $provider->credential_ids );
		// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Split deliberately fake fixture token for credential scanners.
		$secrets->save_credential( 'gh', 'one', $metadata, 'ghp_' . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd' );
		self::assertSame( TargetPackageReason::REPOSITORY_ACCESS_FAILED, $preview->verify( $this->install_item(), null, null, 'one' )->reason );
		self::assertCount( 3, $provider->credential_ids );
	}

	public function test_preview_rate_limit_stops_its_scope_but_not_explicit_credentials_or_next_preview(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );
		$preview               = $verifier->for_preview();
		self::assertSame( TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE, $preview->verify( $this->install_item() )->reason );
		for ( $row = 1; $row < 128; ++$row ) {
			$item = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'other/other.php', 'Other', 'gh', 'repository-id', 'owner/repository-' . $row, 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
			self::assertSame( TargetPackageReason::PREVIEW_RATE_LIMITED, $preview->verify( $item )->reason );
		}
		self::assertCount( 1, $provider->credential_ids );
		self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item(), $this->credential(), BlueprintCredentialAction::IMPORT )->action );
		$verifier->for_preview()->verify( $this->install_item() );
		self::assertCount( 3, $provider->credential_ids );
	}

	public function test_preview_repository_failures_are_not_cached_or_scope_failures(): void {
		foreach ( array( 403, 404, 502 ) as $status ) {
			[$verifier, $provider] = $this->verifier( $status, 'repository-id' );
			$preview               = $verifier->for_preview();
			$preview->verify( $this->install_item() );
			$preview->verify( $this->install_item() );
			self::assertCount( 2, $provider->credential_ids );
		}
	}

	public function test_preview_budget_preserves_successful_observations_and_marks_unchecked_rows(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );
		$preview               = $verifier->for_preview();
		$preview->verify( $this->install_item() );
		( new \ReflectionProperty( $preview, 'preview_started' ) )->setValue( $preview, hrtime( true ) / 1e9 - 9.0 );
		self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item() )->action );
		$other = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'other/other.php', 'Other', 'gh', 'repository-id', 'owner/other', 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		self::assertSame( TargetPackageReason::PREVIEW_BUDGET_EXHAUSTED, $preview->verify( $other )->reason );
		self::assertCount( 1, $provider->credential_ids );
		self::assertSame( TargetPackageAction::INSTALL, $verifier->verify( $other )->action );
		self::assertCount( 2, $provider->credential_ids );
	}

	public function test_preview_rate_limit_never_poisons_another_provider(): void {
		$catalog   = new ProviderSecretPolicyCatalog();
		$secrets   = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$github    = new TemporaryCredentialProvider( $secrets->credentials_for( 'gh' ), 429, 'repository-id' );
		$bitbucket = new TemporaryCredentialProvider( $secrets->credentials_for( 'bb' ), 0, 'repository-id', false, 'bb' );
		$registry  = new ProviderRegistry( array( $github, $bitbucket ), $catalog );
		$preview   = ( new BlueprintRepositoryVerifier( $registry, $secrets, GitHubSelectionTestFixture::bind( $registry ) ) )->for_preview();
		$preview->verify( $this->install_item() );
		self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $this->install_item( 'bb' ) )->action );
		self::assertCount( 1, $github->credential_ids );
		self::assertCount( 1, $bitbucket->credential_ids );
	}

	public function test_preview_repository_specific_forbidden_does_not_skip_accessible_repository(): void {
		$provider = $this->createMock( \RAN\RepositoryProvider\RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new \RAN\RepositoryProvider\ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' ) );
		$provider->expects( self::exactly( 2 ) )->method( 'resolve_repository' )->willReturnCallback(
			static function ( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				if ( 'owner/repository' === $request->locator ) {
					throw new \RuntimeException( 'Forbidden fixture repository.', 403 );
				}
				return new \RAN\RepositoryProvider\RepositoryDescriptor( ProviderCode::parse( 'gh' ), 'owner/accessible', 'example', 'repository-id', false, 'main', null );
			}
		);
		$registry = new ProviderRegistry( array( $provider ) );
		$preview  = ( new BlueprintRepositoryVerifier( $registry, $this->createStub( SecretsFile::class ), GitHubSelectionTestFixture::bind( $registry ) ) )->for_preview();
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $preview->verify( $this->install_item() )->reason );
		$item = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'other/other.php', 'Other', 'gh', 'repository-id', 'owner/accessible', 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		self::assertSame( TargetPackageAction::INSTALL, $preview->verify( $item )->action );
	}

	public function test_preview_stops_after_a_transport_failure_consumes_the_elapsed_budget(): void {
		$provider = $this->createMock( \RAN\RepositoryProvider\RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new \RAN\RepositoryProvider\ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://example.test/', 'Owner' ) );
		$registry = new ProviderRegistry( array( $provider ) );
		$preview  = ( new BlueprintRepositoryVerifier( $registry, $this->createStub( SecretsFile::class ), GitHubSelectionTestFixture::bind( $registry ) ) )->for_preview();
		$provider->expects( self::once() )->method( 'resolve_repository' )->willReturnCallback(
			static function () use ( $preview ): never {
				// Simulate a slow transport boundary without sleeping or claiming latency.
				( new \ReflectionProperty( $preview, 'preview_started' ) )->setValue( $preview, hrtime( true ) / 1e9 - 9.0 );
				throw new \RuntimeException( 'Simulated transport timeout.' );
			}
		);
		self::assertSame( TargetPackageReason::REPOSITORY_ACCESS_FAILED, $preview->verify( $this->install_item() )->reason );
		for ( $row = 1; $row < 128; ++$row ) {
			$item = new BlueprintPlanItem( new BlueprintPackage( 'plugin', 'other/other.php', 'Other', 'gh', 'repository-id', 'owner/repository-' . $row, 'main', null ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
			self::assertSame( TargetPackageReason::PREVIEW_BUDGET_EXHAUSTED, $preview->verify( $item )->reason );
		}
	}

	/** @return array{BlueprintRepositoryVerifier, TemporaryCredentialProvider, SecretsFile} */
	private function verifier(
		int $anonymous_failure,
		string $provider_repository_id,
		bool $is_private = false,
		string $provider_code = 'gh',
		string $accepted_secret = self::CLASSIC_TOKEN
	): array {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$provider = new TemporaryCredentialProvider(
			$secrets->credentials_for( $provider_code ),
			$anonymous_failure,
			$provider_repository_id,
			$is_private,
			$provider_code,
			'gh' === $provider_code ? 'GitHub' : 'Bitbucket',
			$accepted_secret
		);
		$registry = new ProviderRegistry( array( $provider ), $catalog );
		$registry;

		return array( new BlueprintRepositoryVerifier( $registry, $secrets, GitHubSelectionTestFixture::bind( $registry ) ), $provider, $secrets );
	}

	private function package( string $provider = 'gh' ): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', $provider, 'repository-id', 'owner/repository', 'main', null );
	}

	private function install_item( string $provider = 'gh' ): BlueprintPlanItem {
		return new BlueprintPlanItem( $this->package( $provider ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
	}

	/** @param array<string, string> $configuration */
	private function credential(
		string $identifier = 'example/example.php',
		string $secret = self::CLASSIC_TOKEN,
		string $provider = 'gh',
		string $kind = 'classic',
		array $configuration = array( 'owner' => '' )
	): BlueprintCredential {
		return new BlueprintCredential(
			$provider,
			'Imported credential',
			$kind,
			$configuration,
			$secret,
			array(
				array(
					'type'       => 'plugin',
					'identifier' => $identifier,
				),
			),
		);
	}
}

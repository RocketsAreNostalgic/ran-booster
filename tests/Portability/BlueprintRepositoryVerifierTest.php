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

	public function test_it_does_not_resolve_managed_or_protected_rows(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		self::assertSame( $managed, $verifier->verify( $managed, $this->credential() ) );
		self::assertSame( $managed, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::LEAVE ) );
		self::assertSame( $managed, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::TARGET, 'target-pat' ) );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_it_verifies_only_an_explicit_managed_row_import(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$managed                         = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$repository_private = null;
		$result             = $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::IMPORT, null, $repository_private );

		self::assertSame( $managed, $result );
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

		$result = ( new BlueprintRepositoryVerifier( $registry, $secrets ) )->verify(
			$this->install_item(),
			$this->credential(),
			BlueprintCredentialAction::TARGET,
			'wrong-provider-profile'
		);

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credential_ids );
	}

	public function test_inactive_provider_blocks_transferred_material_and_cleans_the_temporary_profile(): void {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$verifier = new BlueprintRepositoryVerifier( new ProviderRegistry(), $secrets );

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

		return array( new BlueprintRepositoryVerifier( $registry, $secrets ), $provider, $secrets );
	}

	private function package( string $provider = 'gh' ): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', $provider, 'repository-id', 'owner/repository', 'main', null );
	}

	private function install_item( string $provider = 'gh' ): BlueprintPlanItem {
		return new BlueprintPlanItem( $this->package( $provider ), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
	}

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

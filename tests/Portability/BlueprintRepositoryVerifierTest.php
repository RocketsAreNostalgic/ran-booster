<?php

declare(strict_types=1);

namespace Tests\Portability;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions.error_log_var_export

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
use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use Tests\Secrets\SecretsFileTestFactory;

#[CoversClass( BlueprintRepositoryVerifier::class )]
final class BlueprintRepositoryVerifierTest extends TestCase {
	// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Keep fixture tokens split so credential scanners do not treat them as live PATs.
	private const CLASSIC_TOKEN = 'ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
	// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Keep fixture tokens split so credential scanners do not treat them as live PATs.
	private const FINE_GRAINED_TOKEN = 'github_pat_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	private string $directory;
	private string $path;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-portability-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
	}

	public function testItRetriesOnlyAnAccessFailureWithATemporaryCredential(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertNull( $secrets->credentialMaterial( 'gh', $provider->temporaryCredentialId ) );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	#[DataProvider( 'transferredProviderCredentialProvider' )]
	public function testTransferredCredentialVerificationIsProviderNeutral(
		string $providerCode,
		string $kind,
		array $configuration,
		string $secret
	): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id', false, $providerCode, $secret );

		$result = $verifier->verify(
			$this->installItem( $providerCode ),
			$this->credential( provider: $providerCode, kind: $kind, configuration: $configuration, secret: $secret ),
			BlueprintCredentialAction::IMPORT
		);

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertCount( 1, $provider->credentialIds );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertNull( $secrets->credentialMaterial( $providerCode, $provider->temporaryCredentialId ) );
		self::assertSame( array(), $secrets->credentialProfiles( $providerCode ) );
	}

	/** @return iterable<string, array{string,string,array<string,string>,string}> */
	public static function transferredProviderCredentialProvider(): iterable {
		yield 'GitHub classic' => array( 'gh', 'classic', array( 'owner' => '' ), self::CLASSIC_TOKEN );
		yield 'GitHub fine-grained' => array( 'gh', 'fine-grained', array( 'owner' => 'RocketsAreNostalgic' ), self::FINE_GRAINED_TOKEN );
		yield 'Bitbucket API token' => array( 'bb', 'api-token', array( 'account_email' => 'canary@example.test' ), 'sentinel-bitbucket-portability-token' );
	}

	public function testTransferredMaterialIsAttemptedBeforeAnExplicitTargetCredentialWithoutPreviewPersistence(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->saveCredential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);
		$before = (string) file_get_contents( $this->path );

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertNotSame( 'target-pat', $provider->temporaryCredentialId );
		self::assertNull( $secrets->credentialMaterial( 'gh', $provider->temporaryCredentialId ) );
		self::assertSame( array( 'target-pat' ), array_keys( $secrets->credentialProfiles( 'gh' ) ) );
		self::assertSame( $before, (string) file_get_contents( $this->path ) );
	}

	#[DataProvider( 'repositoryPrivacyProvider' )]
	public function testItPreservesAnAssociatedCredentialForPublicAndPrivateRepositories( bool $private ): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id', $private );

		$repositoryPrivate = null;
		$result            = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT, null, $repositoryPrivate );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertSame( $private, $repositoryPrivate );
	}

	/** @return iterable<string, array{bool}> */
	public static function repositoryPrivacyProvider(): iterable {
		yield 'public' => array( false );
		yield 'private' => array( true );
	}

	public function testItLeavesAPackageOnlyPublicRepositoryAnonymous(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$repositoryPrivate = null;
		$result            = $verifier->verify( $this->installItem(), null, null, null, $repositoryPrivate );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( null ), $provider->credentialIds );
		self::assertFalse( $repositoryPrivate );
	}

	public function testItDoesNotSilentlyDropAnInvalidTransferredCredential(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$result = $verifier->verify( $this->installItem(), $this->credential( 'example/example.php', 'expired-token' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credentialIds );
	}

	public function testItDoesNotResolveManagedOrProtectedRows(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		self::assertSame( $managed, $verifier->verify( $managed, $this->credential() ) );
		self::assertSame( $managed, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::LEAVE ) );
		self::assertSame( $managed, $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::TARGET, 'target-pat' ) );
		self::assertSame( array(), $provider->credentialIds );
	}

	public function testItVerifiesOnlyAnExplicitManagedRowImport(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$managed                         = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$repositoryPrivate = null;
		$result            = $verifier->verify( $managed, $this->credential(), BlueprintCredentialAction::IMPORT, null, $repositoryPrivate );

		self::assertSame( $managed, $result );
		self::assertFalse( $repositoryPrivate );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testItBlocksManagedRowImportBoundToAnotherPackage(): void {
		[$verifier, $provider] = $this->verifier( 404, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		$result = $verifier->verify( $managed, $this->credential( 'other/other.php' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credentialIds );
	}

	public function testItBlocksAnAccessFailureWithoutTransferredCredentials(): void {
		[$verifier] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->installItem() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
	}

	public function testItDoesNotUseACredentialBoundToAnotherPackage(): void {
		[$verifier, $provider] = $this->verifier( 404, 'repository-id' );

		$result = $verifier->verify( $this->installItem(), $this->credential( 'other/other.php' ), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credentialIds );
	}

	public function testItUsesAnExplicitExistingTargetCredentialAfterAnonymousAccessFails(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->saveCredential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->installItem(), null, null, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( 'target-pat' ), $provider->credentialIds );
	}

	public function testCarriedCredentialTargetChoiceUsesOnlyTheSubmittedTargetProfile(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 404, 'repository-id' );
		$secrets->saveCredential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::TARGET, 'target-pat' );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( 'target-pat' ), $provider->credentialIds );
		self::assertSame( 'target-pat', $provider->temporaryCredentialId );
	}

	public function testWrongProviderTargetChoiceBlocksWithoutAnyFallback(): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$provider = new TemporaryCredentialProvider( $secrets->credentialsFor( 'gh' ), 404, 'repository-id' );
		$registry = new ProviderRegistry( array( $provider ), $catalog );
		$catalog->register( ProviderCode::parse( 'bb' ), new TemporaryProviderCredentialPolicy( ProviderCode::parse( 'bb' ) ), null );
		$secrets->saveCredential(
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
			$this->installItem(),
			$this->credential(),
			BlueprintCredentialAction::TARGET,
			'wrong-provider-profile'
		);

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array(), $provider->credentialIds );
	}

	public function testInactiveProviderBlocksTransferredMaterialAndCleansTheTemporaryProfile(): void {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$verifier = new BlueprintRepositoryVerifier( new ProviderRegistry(), $secrets );

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_UNAVAILABLE, $result->reason );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testItRetriesAnAnonymousRateLimitWithATransferredCredential(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
	}

	public function testItOffersSavedTargetCredentialsWhenAnonymousQuotaIsExhausted(): void {
		[$verifier, $provider, $secrets] = $this->verifier( 429, 'repository-id' );
		$secrets->saveCredential(
			'gh',
			'target-pat',
			array(
				'label'         => 'Target PAT',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$result = $verifier->verify( $this->installItem() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array( null ), $provider->credentialIds );
	}

	public function testItKeepsAnAnonymousRateLimitBlockedWithoutCredentials(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );

		$result = $verifier->verify( $this->installItem() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE, $result->reason );
		self::assertSame( array( null ), $provider->credentialIds );
	}

	public function testItBlocksTemporaryProviderFailuresWithoutCredentialIntent(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );

		$result = $verifier->verify( $this->installItem() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE, $result->reason );
		self::assertSame( array( null ), $provider->credentialIds );
	}

	public function testItBlocksAStableRepositoryIdentityMismatch(): void {
		[$verifier] = $this->verifier( 0, 'different-repository-id' );

		$result = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::IMPORT );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $result->reason );
	}

	public function testCredentialBearingRowsRequireAnExplicitDecisionWithoutAnyProviderAttempt(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$unresolved = $verifier->verify( $this->installItem(), $this->credential() );
		$leave      = $verifier->verify( $this->installItem(), $this->credential(), BlueprintCredentialAction::LEAVE );

		self::assertSame( TargetPackageAction::BLOCKED, $unresolved->action );
		self::assertSame( TargetPackageAction::BLOCKED, $leave->action );
		self::assertSame( array(), $provider->credentialIds );
	}

	/** @return array{BlueprintRepositoryVerifier, TemporaryCredentialProvider, SecretsFile} */
	private function verifier(
		int $anonymousFailure,
		string $providerRepositoryId,
		bool $private = false,
		string $providerCode = 'gh',
		string $acceptedSecret = self::CLASSIC_TOKEN
	): array {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$provider = new TemporaryCredentialProvider(
			$secrets->credentialsFor( $providerCode ),
			$anonymousFailure,
			$providerRepositoryId,
			$private,
			$providerCode,
			'gh' === $providerCode ? 'GitHub' : 'Bitbucket',
			$acceptedSecret
		);
		$registry = new ProviderRegistry( array( $provider ), $catalog );

		return array( new BlueprintRepositoryVerifier( $registry, $secrets ), $provider, $secrets );
	}

	private function package( string $provider = 'gh' ): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', $provider, 'repository-id', 'owner/repository', 'main', null );
	}

	private function installItem( string $provider = 'gh' ): BlueprintPlanItem {
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

<?php

declare(strict_types=1);

namespace Tests\Portability;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions.error_log_var_export

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use Tests\Secrets\SecretsFileTestFactory;

#[CoversClass( BlueprintRepositoryVerifier::class )]
final class BlueprintRepositoryVerifierTest extends TestCase {

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

		$source = null;
		$result = $verifier->verify( $this->installItem(), $this->credential(), null, $source );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( TargetPackageReason::NONE, $result->reason );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertNull( $secrets->credentialMaterial( 'gh', $provider->temporaryCredentialId ) );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
		self::assertSame( 'transferred', $source );
	}

	public function testItPreservesAnAssociatedCredentialForAPublicRepository(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$source            = null;
		$repositoryPrivate = null;
		$result            = $verifier->verify( $this->installItem(), $this->credential(), null, $source, $repositoryPrivate );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertNotNull( $provider->temporaryCredentialId );
		self::assertSame( 'transferred', $source );
		self::assertFalse( $repositoryPrivate );
	}

	public function testItLeavesAPackageOnlyPublicRepositoryAnonymous(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$source            = null;
		$repositoryPrivate = null;
		$result            = $verifier->verify( $this->installItem(), null, null, $source, $repositoryPrivate );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( null ), $provider->credentialIds );
		self::assertNull( $source );
		self::assertFalse( $repositoryPrivate );
	}

	public function testItDoesNotSilentlyDropAnInvalidTransferredCredential(): void {
		[$verifier, $provider] = $this->verifier( 0, 'repository-id' );

		$source = null;
		$result = $verifier->verify( $this->installItem(), $this->credential( 'example/example.php', 'expired-token' ), null, $source );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertCount( 1, $provider->credentialIds );
		self::assertNotNull( $provider->credentialIds[0] );
		self::assertNull( $source );
	}

	public function testItDoesNotResolveManagedOrProtectedRows(): void {
		[$verifier, $provider] = $this->verifier( 502, 'repository-id' );
		$managed               = new BlueprintPlanItem( $this->package(), TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		self::assertSame( $managed, $verifier->verify( $managed, $this->credential() ) );
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

		$result = $verifier->verify( $this->installItem(), $this->credential( 'other/other.php' ) );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::CREDENTIAL_REQUIRED, $result->reason );
		self::assertSame( array( null ), $provider->credentialIds );
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
			'sentinel-portability-token'
		);

		$source = null;
		$result = $verifier->verify( $this->installItem(), null, 'target-pat', $source );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( 'target-pat' ), $provider->credentialIds );
		self::assertSame( 'target', $source );
	}

	public function testItRetriesAnAnonymousRateLimitWithATransferredCredential(): void {
		[$verifier, $provider] = $this->verifier( 429, 'repository-id' );

		$source = null;
		$result = $verifier->verify( $this->installItem(), $this->credential(), null, $source );

		self::assertSame( TargetPackageAction::INSTALL, $result->action );
		self::assertSame( array( $provider->temporaryCredentialId ), $provider->credentialIds );
		self::assertSame( 'transferred', $source );
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
			'sentinel-portability-token'
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

		$result = $verifier->verify( $this->installItem(), $this->credential() );

		self::assertSame( TargetPackageAction::BLOCKED, $result->action );
		self::assertSame( TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH, $result->reason );
	}

	/** @return array{BlueprintRepositoryVerifier, TemporaryCredentialProvider, SecretsFile} */
	private function verifier( int $anonymousFailure, string $providerRepositoryId ): array {
		$catalog  = new ProviderSecretPolicyCatalog();
		$secrets  = SecretsFileTestFactory::create( $this->path, array(), $catalog );
		$provider = new TemporaryCredentialProvider( $secrets->credentialsFor( 'gh' ), $anonymousFailure, $providerRepositoryId );
		$registry = new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ), $catalog );

		return array( new BlueprintRepositoryVerifier( $registry, $secrets ), $provider, $secrets );
	}

	private function package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/repository', 'main', null );
	}

	private function installItem(): BlueprintPlanItem {
		return new BlueprintPlanItem( $this->package(), TargetPackageAction::INSTALL, TargetPackageReason::NONE );
	}

	private function credential( string $identifier = 'example/example.php', string $secret = 'sentinel-portability-token' ): BlueprintCredential {
		return new BlueprintCredential(
			'gh',
			'Imported credential',
			'classic',
			array( 'owner' => '' ),
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

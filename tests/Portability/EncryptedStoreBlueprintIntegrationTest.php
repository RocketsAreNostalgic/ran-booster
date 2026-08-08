<?php

declare(strict_types=1);

namespace Tests\Portability;

// Native temporary files model two independent target sites.
// phpcs:disable WordPress.WP.AlternativeFunctions
// Base64 is used only to prove that key material is absent from the envelope.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

use PHPUnit\Framework\TestCase;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RuntimeException;
use Tests\Secrets\InMemorySiteKeyStore;

final class EncryptedStoreBlueprintIntegrationTest extends TestCase {

	private string $root;
	private string $sourcePath;
	private string $targetPath;
	private string $archivePath;

	protected function setUp(): void {
		$this->root        = sys_get_temp_dir() . '/ran-booster-two-site-' . bin2hex( random_bytes( 8 ) );
		$this->sourcePath  = $this->root . '/source/secrets.json';
		$this->targetPath  = $this->root . '/target/secrets.json';
		$this->archivePath = $this->root . '/blueprint.zip';
		self::assertTrue( mkdir( dirname( $this->sourcePath ), 0700, true ) );
		self::assertTrue( mkdir( dirname( $this->targetPath ), 0700, true ) );
		InMemorySiteKeyStore::reset( $this->sourcePath );
		InMemorySiteKeyStore::reset( $this->targetPath );
	}

	protected function tearDown(): void {
		$this->removeTree( $this->root );
		InMemorySiteKeyStore::reset( $this->sourcePath );
		InMemorySiteKeyStore::reset( $this->targetPath );
	}

	public function testBlueprintV1ReencryptsImportedCredentialAndRetainsItAfterALaterPackageFailure(): void {
		$codec          = new EncryptedSecretsEnvelopeCodec();
		$sourceKeyStore = new InMemorySiteKeyStore( $this->sourcePath );
		$targetKeyStore = new InMemorySiteKeyStore( $this->targetPath );
		$sourcePolicies = new ProviderSecretPolicyCatalog();
		$sourcePolicies->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$targetPolicies = new ProviderSecretPolicyCatalog();
		$targetPolicies->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$sourceSecrets = new SecretsFile(
			$this->sourcePath,
			array(),
			$sourcePolicies,
			$sourceKeyStore,
			$codec
		);
		$targetSecrets = new SecretsFile(
			$this->targetPath,
			array(),
			$targetPolicies,
			$targetKeyStore,
			$codec
		);
		$sourceSecrets->saveCredential(
			'gh',
			'source-credential',
			array(
				'label'         => 'Portable credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'sentinel-portability-token'
		);

		$blueprint = $this->exporter( $sourceSecrets )->export( array( 'gh' => array( 'source-credential' ) ) );
		$password  = 'correct-horse-battery-staple';
		( new BlueprintArchive() )->writeTo( $this->archivePath, $blueprint, $password );
		$imported = ( new BlueprintArchive() )->readFrom( $this->archivePath, $password );
		self::assertSame( $blueprint->canonicalJson(), $imported->canonicalJson() );

		$targetSecrets->assertManagedStorageReady();
		self::assertFileDoesNotExist( $this->targetPath );
		self::assertNull( $targetKeyStore->load() );

		$credential = $imported->credentials[0];
		$provider   = new TemporaryCredentialProvider( $targetSecrets->credentialsFor( 'gh' ), 0, 'repository-id' );
		$catalog    = new ProviderSecretPolicyCatalog();
		$verifier   = new BlueprintRepositoryVerifier(
			new ProviderRegistry( array( $provider ), $catalog ),
			$targetSecrets
		);
		$source     = null;
		$preview    = $verifier->verify(
			new BlueprintPlanItem( $imported->packages[0], TargetPackageAction::INSTALL, TargetPackageReason::NONE ),
			$credential,
			null,
			$source
		);

		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertSame( 'transferred', $source );
		self::assertFileDoesNotExist( $this->targetPath );
		self::assertNull( $targetKeyStore->load() );

		$targetId = $targetSecrets->importCredentialsIfAbsent( $imported, $credential )[0];
		try {
			throw new RuntimeException( 'Simulated package mutation failure after credential persistence.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame(
				'Simulated package mutation failure after credential persistence.',
				$failure->getMessage()
			);
		}
		self::assertSame(
			'sentinel-portability-token',
			$targetSecrets->credentialMaterial( 'gh', $targetId )['secret']
		);
		self::assertSame( $targetId, $targetSecrets->importCredentialsIfAbsent( $imported, $credential )[0] );

		$sourceKey = $sourceKeyStore->load();
		$targetKey = $targetKeyStore->load();
		self::assertIsString( $sourceKey );
		self::assertIsString( $targetKey );
		self::assertNotSame( $sourceKey, $targetKey );
		$envelope = (string) file_get_contents( $this->targetPath );
		self::assertStringNotContainsString( 'sentinel-portability-token', $envelope );
		self::assertStringNotContainsString( base64_encode( $sourceKey ), $envelope );
		self::assertStringNotContainsString( base64_encode( $targetKey ), $envelope );

		$this->expectException( RuntimeException::class );
		$codec->decrypt( $envelope, $sourceKey );
	}

	private function exporter( SecretsFile $secrets ): ManagedPackageBlueprintExporter {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( 'example/example.php' );
		$package->method( 'getDisplayName' )->willReturn( 'Example' );
		$package->method( 'getSlug' )->willReturn( 'example' );
		$package->method( 'getProviderCode' )->willReturn( 'gh' );
		$package->method( 'getProviderRepositoryId' )->willReturn( 'repository-id' );
		$package->method( 'getRepository' )->willReturn(
			new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', true, 'source-credential' )
		);
		$package->method( 'getBranch' )->willReturn( 'main' );
		$package->method( 'isPrivate' )->willReturn( true );
		$package->method( 'getSubdirectory' )->willReturn( null );
		$package->method( 'getCredentialId' )->willReturn( 'source-credential' );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getSourceRevision' )->willReturn( 1 );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'example/example.php' => $package ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );

		return new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets );
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->removeTree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

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
use RAN\PackageOperationService;
use RAN\PackageSource;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PortabilityApplicationService;
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
use ReflectionClass;
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

		$credential  = $imported->credentials[0];
		$orphanedKey = $targetKeyStore->loadOrCreate()['key'];
		try {
			$targetSecrets->importCredentialsIfAbsent( $imported, $credential );
			self::fail( 'Blueprint import must not overwrite a key whose encrypted sidecar is missing.' );
		} catch ( \RAN\Secrets\SecretsStorageUnavailable $failure ) {
			self::assertSame( 'storage_file_missing', $failure->reason() );
		}
		self::assertSame( $orphanedKey, $targetKeyStore->load( false ) );
		self::assertTrue( $targetSecrets->canResetOrphanedKeyAt( $this->targetPath ) );
		$targetSecrets->resetOrphanedKeyAt( $this->targetPath );
		self::assertNull( $targetKeyStore->load( false ) );
		self::assertFileDoesNotExist( $this->targetPath );
		self::assertFileExists( $this->targetPath . '.lock' );

		$targetSecrets->assertManagedStorageReady();
		$provider = new TemporaryCredentialProvider( $targetSecrets->credentialsFor( 'gh' ), 0, 'repository-id' );
		$catalog  = new ProviderSecretPolicyCatalog();
		$verifier = new BlueprintRepositoryVerifier(
			new ProviderRegistry( array( $provider ), $catalog ),
			$targetSecrets
		);
		$preview  = $verifier->verify(
			new BlueprintPlanItem( $imported->packages[0], TargetPackageAction::INSTALL, TargetPackageReason::NONE ),
			$credential,
			BlueprintCredentialAction::IMPORT
		);

		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertFileDoesNotExist( $this->targetPath );
		self::assertNull( $targetKeyStore->load() );

		$application = new PortabilityApplicationService(
			( new ReflectionClass( BlueprintReviewer::class ) )->newInstanceWithoutConstructor(),
			$verifier,
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$targetSecrets
		);
		$applyItem   = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'applyItem' );
		$managed     = $applyItem->invoke(
			$application,
			$imported,
			new BlueprintPlanItem( $imported->packages[0], TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED ),
			$credential,
			'import',
			null,
			false,
			true,
			true
		);
		self::assertSame( 'unchanged', $managed['status'] );
		self::assertSame( 'none', $managed['credential_state'] );
		self::assertFileDoesNotExist( $this->targetPath );
		self::assertNull( $targetKeyStore->load( false ) );

		$result     = $applyItem->invoke( $application, $imported, $preview, $credential, 'import', null, false, true, true );
		$secondItem = new BlueprintPlanItem( $imported->packages[1], TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		$second     = $applyItem->invoke( $application, $imported, $secondItem, $credential, 'import', null, false, true, true );
		$retry      = $applyItem->invoke( $application, $imported, $preview, $credential, 'import', null, false, true, true );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'transferred_available', $result['credential_state'] );
		self::assertStringContainsString( 'could not apply this package', $result['message'] );
		self::assertSame( 'failed', $second['status'] );
		self::assertSame( 'transferred_available', $second['credential_state'] );
		self::assertSame( 'failed', $retry['status'] );
		self::assertSame( 'transferred_available', $retry['credential_state'] );
		$targetId = $targetSecrets->importCredentialsIfAbsent( $imported, $credential )[0];
		self::assertSame(
			'sentinel-portability-token',
			$targetSecrets->credentialMaterial( 'gh', $targetId )['secret']
		);
		self::assertSame( $targetId, $targetSecrets->importCredentialsIfAbsent( $imported, $credential )[0] );
		self::assertSame( array( $targetId ), array_keys( $targetSecrets->credentialProfiles( 'gh' ) ) );

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
		$packages = array();
		foreach ( array(
			'example/example.php' => 'Example',
			'second/second.php'   => 'Second',
		) as $identifier => $displayName ) {
			$package = $this->createStub( Package::class );
			$package->method( 'getIdentifier' )->willReturn( $identifier );
			$package->method( 'getDisplayName' )->willReturn( $displayName );
			$package->method( 'getSlug' )->willReturn( explode( '/', $identifier, 2 )[0] );
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
			$packages[ $identifier ] = $package;
		}
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( $packages );
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

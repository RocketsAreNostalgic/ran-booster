<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Test-only inspection proves PHP exception arguments redact secret material.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
// phpcs:disable WordPress.WP.AlternativeFunctions -- Exact local sidecar fixtures exercise the native cleanup boundary.

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsRuntimeAvailability;
use RAN\Secrets\SecretsStorageUnavailable;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;

final class SecretsFileRuntimeAvailabilityTest extends TestCase {

	public function testSelfDestructCredentialIsWithheldAndPhysicallyPurgedAfterDeadline(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-destruct-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.php';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		$secrets->saveCredential(
			'gh',
			'expired_profile',
			array(
				'label'         => 'Temporary credential',
				'kind'          => 'classic',
				'configuration' => array(),
				'self_destruct' => true,
				'destroy_on'    => '2020-01-01',
			),
			'self-destruct-secret-canary'
		);

		self::assertArrayNotHasKey( 'expired_profile', $secrets->credentialProfiles( 'gh' ) );
		self::assertNull( $secrets->credentialMaterial( 'gh', 'expired_profile' ) );
		self::assertSame( array( 'gh' => array( 'expired_profile' ) ), $secrets->purgeExpiredCredentials() );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );

		InMemorySiteKeyStore::reset( $path );
		foreach ( array( $path, $path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $directory );
	}

	public function testProviderExpiryCanOnlyShortenSelfDestructRetention(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-destruct-provider-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.php';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		$secrets->saveCredential(
			'gh',
			'provider_expiry',
			array(
				'label'         => 'Temporary credential',
				'kind'          => 'classic',
				'configuration' => array(),
				'self_destruct' => true,
				'destroy_on'    => '2030-01-01',
			),
			'self-destruct-provider-secret-canary'
		);
		$secrets->recordCredentialProviderExpiry( 'gh', 'provider_expiry', '2020-01-01' );

		self::assertNull( $secrets->credentialMaterial( 'gh', 'provider_expiry' ) );
		self::assertSame( array( 'gh' => array( 'provider_expiry' ) ), $secrets->purgeExpiredCredentials() );

		InMemorySiteKeyStore::reset( $path );
		foreach ( array( $path, $path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $directory );
	}

	/** @return list<array{bool, bool}> */
	public static function unavailableRuntimeProvider(): array {
		return array(
			array( false, false ),
			array( true, true ),
		);
	}

	#[DataProvider( 'unavailableRuntimeProvider' )]
	public function testUnavailableRuntimeKeepsDisplayAndPackageOnlyPathsBootstrapSafe( bool $sodium, bool $multisite ): void {
		$secrets = $this->secrets( $sodium, $multisite );

		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertSame( array(), $secrets->webhookProfiles( 'gh' ) );
		self::assertFalse( $secrets->verifyAndSecure() );
		self::assertSame(
			array(),
			$secrets->importCredentialsIfAbsent( new PackageBlueprint( array() ) )
		);
	}

	/** @return list<array{string}> */
	public static function managedOperationProvider(): array {
		return array(
			array( 'credential_material' ),
			array( 'credential_write' ),
			array( 'credential_delete' ),
			array( 'webhook_material' ),
			array( 'webhook_write' ),
			array( 'webhook_delete' ),
			array( 'temporary_credential' ),
			array( 'storage_ready' ),
			array( 'credential_material_invalid_provider' ),
			array( 'credential_write_invalid_provider' ),
			array( 'credential_delete_invalid_id' ),
			array( 'webhook_write_invalid_provider' ),
			array( 'webhook_delete_invalid_id' ),
		);
	}

	#[DataProvider( 'managedOperationProvider' )]
	public function testUnavailableRuntimeBlocksManagedCredentialAndWebhookUseWithSafeErrors( string $operation ): void {
		$secrets = $this->secrets( false, false );

		try {
			match ( $operation ) {
				'credential_material' => $secrets->credentialMaterial( 'gh', 'managed-id' ),
				'credential_write'    => $secrets->saveCredential( 'gh', null, array(), 'secret-canary' ),
				'credential_delete'   => $secrets->deleteCredential( 'gh', 'managed-id' ),
				'webhook_material'    => $secrets->webhookMaterials( 'gh' ),
				'webhook_write'       => $secrets->saveWebhook( 'gh', null, array(), 'secret-canary' ),
				'webhook_delete'      => $secrets->deleteWebhook( 'gh', 'managed-id' ),
				'credential_material_invalid_provider' => $secrets->credentialMaterial( 'invalid-provider' ),
				'credential_write_invalid_provider' => $secrets->saveCredential( 'invalid-provider', null, array(), 'secret-canary' ),
				'credential_delete_invalid_id' => $secrets->deleteCredential( 'gh', '' ),
				'webhook_write_invalid_provider' => $secrets->saveWebhook( 'invalid-provider', null, array(), 'secret-canary' ),
				'webhook_delete_invalid_id' => $secrets->deleteWebhook( 'gh', '' ),
				'temporary_credential' => $secrets->withTemporaryCredential(
					'gh',
					array(),
					'secret-canary',
					static fn (): null => null
				),
				'storage_ready'       => $secrets->assertManagedStorageReady(),
			};
			self::fail( 'Managed secret use must fail closed when the runtime is unsupported.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure->getDiagnosticId() );
			self::assertStringContainsString( 'Sodium extension is missing', $failure->getMessage() );
			self::assertStringNotContainsString( 'secret-canary', $failure->getMessage() );
			self::assertStringNotContainsString( '/srv/', $failure->getMessage() );
			self::assertStringNotContainsString( 'secret-canary', var_export( $failure->getTrace(), true ) );
		}
	}

	public function testUnavailableRuntimeRedactsImportedCredentialArgumentsFromTheWholeTrace(): void {
		$canary     = 'import-trace-secret-canary';
		$identity   = array(
			'type'       => 'plugin',
			'identifier' => 'example/example.php',
		);
		$package    = new BlueprintPackage(
			'plugin',
			$identity['identifier'],
			'Example Plugin',
			'gh',
			'repository-id',
			'example/example',
			'main',
			null
		);
		$credential = new BlueprintCredential(
			'gh',
			'Imported credential',
			'classic',
			array( 'owner' => '' ),
			$canary,
			array( $identity )
		);
		$blueprint  = new PackageBlueprint( array( $package ), array( $credential ) );

		try {
			$this->secrets( false, false )->importCredentialsIfAbsent( $blueprint, $credential );
			self::fail( 'Credential import must fail closed when the runtime is unavailable.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringNotContainsString( $canary, var_export( $failure->getTrace(), true ) );
		}
	}

	public function testExplicitConstantCredentialBypassesUnavailableRuntime(): void {
		$secrets = new SecretsFile(
			constants: array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'constant-secret-canary' ),
			providerPolicies: ShippedSecretPolicyCatalog::create(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		self::assertSame(
			'constant-secret-canary',
			$secrets->credentialMaterial( 'gh', SecretsFile::CONSTANT_PROFILE )['secret']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfirmedUninstallGetsADeletionOnlySingleSiteAvailabilityContext(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'renamed-booster/ran-booster.php' );

		$availability = SecretsRuntimeAvailability::forConfirmedUninstall(
			'/srv/wp-content/plugins/renamed-booster/ran-booster.php'
		);

		self::assertSame( 'available', $availability->code() );
		self::assertTrue( $availability->isAvailable() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfirmedUninstallRejectsAnUnrelatedSameBasenamePlugin(): void {
		define( 'WP_UNINSTALL_PLUGIN', 'other-plugin/ran-booster.php' );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'cleanup context is unavailable' );
		SecretsRuntimeAvailability::forConfirmedUninstall(
			'/srv/wp-content/plugins/ran-booster/ran-booster.php'
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfirmedUninstallRejectsAMissingWordPressIdentity(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'cleanup context is unavailable' );
		SecretsRuntimeAvailability::forConfirmedUninstall(
			'/srv/wp-content/plugins/ran-booster/ran-booster.php'
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfirmedConvertedUninstallAuthenticatesAndDeletesARealSidecarAndKey(): void {
		[$root, $path] = $this->sidecarFixture();
		$keyStore      = new InMemorySiteKeyStore( $path );
		$codec         = new EncryptedSecretsEnvelopeCodec();
		$secrets       = $this->realSecrets( $path, $keyStore, $codec, new SecretsRuntimeAvailability( true, false ) );
		$secrets->saveCredential(
			'gh',
			'pre_conversion',
			array(
				'label'         => 'Pre-conversion credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'pre-conversion-secret-canary'
		);
		self::assertFileExists( $path );
		self::assertFileExists( $path . '.lock' );
		self::assertNotNull( $keyStore->load( false ) );

		define( 'WP_UNINSTALL_PLUGIN', 'renamed-booster/ran-booster.php' );
		$confirmed = $this->realSecrets(
			$path,
			$keyStore,
			$codec,
			SecretsRuntimeAvailability::forConfirmedUninstall(
				'/srv/wp-content/plugins/renamed-booster/ran-booster.php'
			)
		);
		$confirmed->assertManagedStorageDeletable();
		self::assertFileExists( $path );
		self::assertFileExists( $path . '.lock' );
		self::assertNotNull( $keyStore->load( false ) );
		$confirmed->deleteManagedStorage();

		self::assertFileDoesNotExist( $path );
		self::assertFileDoesNotExist( $path . '.lock' );
		self::assertNull( $keyStore->load( false ) );
		rmdir( $root );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfirmedConvertedUninstallPreservesIncompleteSidecarMaterial(): void {
		[$root, $path] = $this->sidecarFixture();
		$keyStore      = new InMemorySiteKeyStore( $path );
		$codec         = new EncryptedSecretsEnvelopeCodec();
		$secrets       = $this->realSecrets( $path, $keyStore, $codec, new SecretsRuntimeAvailability( true, false ) );
		$secrets->saveCredential(
			'gh',
			'pre_conversion',
			array(
				'label'         => 'Pre-conversion credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'pre-conversion-secret-canary'
		);
		$key = $keyStore->load( false );
		self::assertNotNull( $key );
		self::assertTrue( $keyStore->deleteExact( $key ) );
		define( 'WP_UNINSTALL_PLUGIN', 'ran-booster/ran-booster.php' );

		try {
			$this->realSecrets(
				$path,
				$keyStore,
				$codec,
				SecretsRuntimeAvailability::forConfirmedUninstall(
					'/srv/wp-content/plugins/ran-booster/ran-booster.php'
				)
			)->assertManagedStorageDeletable();
			self::fail( 'Incomplete converted-install material must stop uninstall.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'incomplete', $failure->getMessage() );
			self::assertSame( 'storage_key_missing', $failure->reason() );
			self::assertStringNotContainsString( $path, $failure->getMessage() );
		}

		self::assertFileExists( $path );
		self::assertFileExists( $path . '.lock' );
		unlink( $path );
		unlink( $path . '.lock' );
		rmdir( $root );
	}

	public function testDeletionPreflightRejectsRecoverablePartialStoresButDeletesSafeLockOnlyResidue(): void {
		[$keyRoot, $keyPath] = $this->sidecarFixture();
		$keyStore            = new InMemorySiteKeyStore( $keyPath );
		$key                 = $keyStore->loadOrCreate()['key'];
		$keyOnly             = $this->realSecrets(
			$keyPath,
			$keyStore,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$this->assertStoragePreflightRefused( $keyOnly, 'storage_lock_missing' );
		self::assertSame( $key, $keyStore->load( false ) );
		self::assertTrue( $keyStore->deleteExact( $key ) );
		rmdir( $keyRoot );

		[$lockRoot, $lockPath] = $this->sidecarFixture();
		self::assertNotFalse( file_put_contents( $lockPath . '.lock', '' ) );
		self::assertTrue( chmod( $lockPath . '.lock', 0600 ) );
		$lockOnly = $this->realSecrets(
			$lockPath,
			new InMemorySiteKeyStore( $lockPath ),
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		self::assertFalse( $lockOnly->hasHealthyManagedStorage() );
		$lockOnly->assertManagedStorageDeletable();
		$lockOnly->deleteManagedStorage();
		self::assertFileDoesNotExist( $lockPath . '.lock' );
		rmdir( $lockRoot );

		[$missingRoot, $missingPath] = $this->sidecarFixture();
		$missingKeyStore             = new InMemorySiteKeyStore( $missingPath );
		$missingLock                 = $this->realSecrets(
			$missingPath,
			$missingKeyStore,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$missingLock->saveCredential(
			'gh',
			'pre_conversion',
			array(
				'label'         => 'Pre-conversion credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'pre-conversion-secret-canary'
		);
		self::assertTrue( unlink( $missingPath . '.lock' ) );
		$this->assertStoragePreflightRefused( $missingLock, 'storage_lock_missing' );
		self::assertFileExists( $missingPath );
		self::assertNotNull( $missingKeyStore->load( false ) );
		unlink( $missingPath );
		$missingKey = $missingKeyStore->load( false );
		self::assertNotNull( $missingKey );
		self::assertTrue( $missingKeyStore->deleteExact( $missingKey ) );
		rmdir( $missingRoot );
	}

	private function secrets( bool $sodium, bool $multisite ): SecretsFile {
		return new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( $sodium, $multisite )
		);
	}

	/** @return array{string, string} */
	private function sidecarFixture(): array {
		$root = sys_get_temp_dir() . '/ran-booster-converted-uninstall-' . bin2hex( random_bytes( 6 ) );
		self::assertTrue( mkdir( $root, 0700 ) );

		return array( $root, $root . '/secrets.json' );
	}

	private function realSecrets(
		string $path,
		InMemorySiteKeyStore $keyStore,
		EncryptedSecretsEnvelopeCodec $codec,
		SecretsRuntimeAvailability $availability
	): SecretsFile {
		return new SecretsFile(
			$path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$keyStore,
			$codec,
			availability: $availability
		);
	}

	private function assertStoragePreflightRefused( SecretsFile $secrets, string $expectedReason ): void {
		try {
			$secrets->assertManagedStorageDeletable();
			self::fail( 'Incomplete managed storage must fail the deletion preflight.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'incomplete', $failure->getMessage() );
			self::assertSame( $expectedReason, $failure->reason() );
		}
	}
}

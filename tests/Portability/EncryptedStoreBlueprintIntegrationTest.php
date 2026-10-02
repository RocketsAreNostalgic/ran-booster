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
use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
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

require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';

final class EncryptedStoreBlueprintIntegrationTest extends TestCase {
	// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Keep the fixture token split so credential scanners do not treat it as a live PAT.
	private const CLASSIC_TOKEN = 'ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	private string $root;
	private string $source_path;
	private string $target_path;
	private string $archive_path;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->root         = sys_get_temp_dir() . '/ran-booster-two-site-' . bin2hex( random_bytes( 8 ) );
		$this->source_path  = $this->root . '/source/secrets.json';
		$this->target_path  = $this->root . '/target/secrets.json';
		$this->archive_path = $this->root . '/blueprint.zip';
		self::assertTrue( mkdir( dirname( $this->source_path ), 0700, true ) );
		self::assertTrue( mkdir( dirname( $this->target_path ), 0700, true ) );
		InMemorySiteKeyStore::reset( $this->source_path );
		InMemorySiteKeyStore::reset( $this->target_path );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->remove_tree( $this->root );
		InMemorySiteKeyStore::reset( $this->source_path );
		InMemorySiteKeyStore::reset( $this->target_path );
	}

	public function test_blueprint_v1_reencrypts_imported_credential_and_retains_it_after_alater_package_failure(): void {
		$codec            = new EncryptedSecretsEnvelopeCodec();
		$source_key_store = new InMemorySiteKeyStore( $this->source_path );
		$target_key_store = new InMemorySiteKeyStore( $this->target_path );
		$source_policies  = new ProviderSecretPolicyCatalog();
		$source_policies->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$target_policies = new ProviderSecretPolicyCatalog();
		$target_policies->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$source_secrets = new SecretsFile(
			$this->source_path,
			array(),
			$source_policies,
			$source_key_store,
			$codec
		);
		$target_secrets = new SecretsFile(
			$this->target_path,
			array(),
			$target_policies,
			$target_key_store,
			$codec
		);
		$source_secrets->save_credential(
			'gh',
			'source-credential',
			array(
				'label'         => 'Portable credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			self::CLASSIC_TOKEN
		);

		$blueprint = $this->exporter( $source_secrets )->export( array( 'gh' => array( 'source-credential' ) ) );
		$password  = 'correct-horse-battery-staple';
		( new BlueprintArchive() )->write_to( $this->archive_path, $blueprint, $password );
		$imported = ( new BlueprintArchive() )->read_from( $this->archive_path, $password );
		self::assertSame( $blueprint->canonical_json(), $imported->canonical_json() );

		$credential   = $imported->credentials[0];
		$orphaned_key = $target_key_store->load_or_create()['key'];
		try {
			$target_secrets->import_credentials_if_absent( $imported, $credential );
			self::fail( 'Blueprint import must not overwrite a key whose encrypted sidecar is missing.' );
		} catch ( \RAN\Secrets\SecretsStorageUnavailable $failure ) {
			self::assertSame( 'storage_file_missing', $failure->reason() );
		}
		self::assertSame( $orphaned_key, $target_key_store->load( false ) );
		self::assertTrue( $target_secrets->can_reset_orphaned_key_at( $this->target_path ) );
		$target_secrets->reset_orphaned_key_at( $this->target_path );
		self::assertNull( $target_key_store->load( false ) );
		self::assertFileDoesNotExist( $this->target_path );
		self::assertFileExists( $this->target_path . '.lock' );

		$target_secrets->assert_managed_storage_ready();
		$provider = new TemporaryCredentialProvider( $target_secrets->credentials_for( 'gh' ), 0, 'repository-id', false, 'gh', 'GitHub', self::CLASSIC_TOKEN );
		$catalog  = new ProviderSecretPolicyCatalog();
		$verifier = new BlueprintRepositoryVerifier(
			new ProviderRegistry( array( $provider ), $catalog ),
			$target_secrets
		);
		$preview  = $verifier->verify(
			new BlueprintPlanItem( $imported->packages[0], TargetPackageAction::INSTALL, TargetPackageReason::NONE ),
			$credential,
			BlueprintCredentialAction::IMPORT
		);

		self::assertSame( TargetPackageAction::INSTALL, $preview->action );
		self::assertFileDoesNotExist( $this->target_path );
		self::assertNull( $target_key_store->load() );

		$managed_package = $this->createStub( Package::class );
		$managed_package->method( 'get_identifier' )->willReturn( $imported->packages[0]->identifier );
		$managed_package->method( 'get_display_name' )->willReturn( $imported->packages[0]->display_name );
		$managed_package->method( 'get_provider_code' )->willReturn( $imported->packages[0]->provider );
		$managed_package->method( 'get_provider_repository_id' )->willReturn( $imported->packages[0]->provider_repository_id );
		$managed_package->method( 'get_repository' )->willReturn(
			new ManagedRepository(
				$imported->packages[0]->provider,
				$imported->packages[0]->repository,
				$imported->packages[0]->provider_repository_id,
				$imported->packages[0]->branch,
				true,
				'missing-source-profile'
			)
		);
		$managed_package->method( 'get_branch' )->willReturn( $imported->packages[0]->branch );
		$managed_package->method( 'get_subdirectory' )->willReturn( $imported->packages[0]->subdirectory );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'is_installed' )->willReturn( true );
		$plugins->method( 'has_management_record' )->willReturn( true );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $managed_package );

		$application = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			$verifier,
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$target_secrets
		);
		$decisions   = array(
			0 => array(
				'action'    => BlueprintCredentialAction::IMPORT,
				'target_id' => null,
			),
		);
		$review      = $application->review( $imported, $decisions );
		self::assertSame( TargetPackageAction::MANAGED, $review[0]->action );
		self::assertFileDoesNotExist( $this->target_path );
		$apply_item = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'apply_item' );
		$managed    = $application->apply(
			$imported,
			0,
			'managed',
			$decisions,
			null,
			false,
			false
		);
		self::assertSame( 'credential_available', $managed['status'] );
		self::assertSame( 'transferred_available', $managed['credential_state'] );
		self::assertStringContainsString( 'managed package settings were not changed', $managed['message'] );
		self::assertFileExists( $this->target_path );
		self::assertIsString( $target_key_store->load( false ) );

		$lost_target_key = $target_key_store->load( false );
		self::assertNotNull( $lost_target_key );
		self::assertTrue( $target_key_store->delete_exact( $lost_target_key ) );
		try {
			$target_secrets->import_credentials_if_absent( $imported, $credential );
			self::fail( 'Blueprint import must not overwrite ciphertext whose database key is missing.' );
		} catch ( \RAN\Secrets\SecretsStorageUnavailable $failure ) {
			self::assertSame( 'storage_key_missing', $failure->reason() );
		}
		self::assertTrue( $target_secrets->can_reset_orphaned_ciphertext_at( $this->target_path ) );
		$target_secrets->reset_orphaned_ciphertext_at( $this->target_path );
		self::assertFileDoesNotExist( $this->target_path );
		self::assertFileExists( $this->target_path . '.lock' );
		self::assertNull( $target_key_store->load( false ) );

		$recovered = $application->apply(
			$imported,
			0,
			'managed',
			$decisions,
			null,
			false,
			false
		);
		self::assertSame( 'credential_available', $recovered['status'] );
		self::assertFileExists( $this->target_path );
		self::assertNotNull( $target_key_store->load( false ) );

		$result      = $apply_item->invoke( $application, $imported, $preview, $credential, 'import', null, false, true, true );
		$second_item = new BlueprintPlanItem( $imported->packages[1], TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		$second      = $apply_item->invoke( $application, $imported, $second_item, $credential, 'import', null, false, true, true );
		$retry       = $apply_item->invoke( $application, $imported, $preview, $credential, 'import', null, false, true, true );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'transferred_available', $result['credential_state'] );
		self::assertStringContainsString( 'could not apply this package', $result['message'] );
		self::assertSame( 'failed', $second['status'] );
		self::assertSame( 'transferred_available', $second['credential_state'] );
		self::assertSame( 'failed', $retry['status'] );
		self::assertSame( 'transferred_available', $retry['credential_state'] );
		$target_id = $target_secrets->import_credentials_if_absent( $imported, $credential )[0];
		self::assertSame(
			self::CLASSIC_TOKEN,
			$target_secrets->credential_material( 'gh', $target_id )['secret']
		);
		self::assertSame( $target_id, $target_secrets->import_credentials_if_absent( $imported, $credential )[0] );
		self::assertSame( array( $target_id ), array_keys( $target_secrets->credential_profiles( 'gh' ) ) );

		$source_key = $source_key_store->load();
		$target_key = $target_key_store->load();
		self::assertIsString( $source_key );
		self::assertIsString( $target_key );
		self::assertNotSame( $source_key, $target_key );
		$envelope = (string) file_get_contents( $this->target_path );
		self::assertStringNotContainsString( self::CLASSIC_TOKEN, $envelope );
		self::assertStringNotContainsString( base64_encode( $source_key ), $envelope );
		self::assertStringNotContainsString( base64_encode( $target_key ), $envelope );

		$this->expectException( RuntimeException::class );
		$codec->decrypt( $envelope, $source_key );
	}

	private function exporter( SecretsFile $secrets ): ManagedPackageBlueprintExporter {
		$packages = array();
		foreach ( array(
			'example/example.php' => 'Example',
			'second/second.php'   => 'Second',
		) as $identifier => $display_name ) {
			$package = $this->createStub( Package::class );
			$package->method( 'get_identifier' )->willReturn( $identifier );
			$package->method( 'get_display_name' )->willReturn( $display_name );
			$package->method( 'get_slug' )->willReturn( explode( '/', $identifier, 2 )[0] );
			$package->method( 'get_provider_code' )->willReturn( 'gh' );
			$package->method( 'get_provider_repository_id' )->willReturn( 'repository-id' );
			$package->method( 'get_repository' )->willReturn(
				new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', true, 'source-credential' )
			);
			$package->method( 'get_branch' )->willReturn( 'main' );
			$package->method( 'is_private' )->willReturn( true );
			$package->method( 'get_subdirectory' )->willReturn( null );
			$package->method( 'get_credential_id' )->willReturn( 'source-credential' );
			$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
			$package->method( 'get_source_revision' )->willReturn( 1 );
			$packages[ $identifier ] = $package;
		}
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( $packages );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );

		return new ManagedPackageBlueprintExporter( $plugins, $themes, $secrets );
	}

	private function remove_tree( string $path ): void {
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
				$this->remove_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

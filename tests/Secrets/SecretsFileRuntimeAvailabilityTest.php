<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Test-only inspection proves PHP exception arguments redact secret material.

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
use RAN\Secrets\SiteKeyStore;
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;

final class SecretsFileRuntimeAvailabilityTest extends TestCase {

	public function test_self_destruct_credential_is_withheld_and_physically_purged_after_deadline(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-destruct-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		$secrets->save_credential(
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

		self::assertArrayNotHasKey( 'expired_profile', $secrets->credential_profiles( 'gh' ) );
		self::assertNull( $secrets->credential_material( 'gh', 'expired_profile' ) );
		self::assertSame( array( 'gh' => array( 'expired_profile' ) ), $secrets->purge_expired_credentials() );
		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );

		InMemorySiteKeyStore::reset( $path );
		foreach ( array( $path, $path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $directory );
	}

	public function test_provider_expiry_can_only_shorten_self_destruct_retention(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-destruct-provider-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$secrets = SecretsFileTestFactory::create( $path, array(), ShippedSecretPolicyCatalog::create() );
		$secrets->save_credential(
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
		$secrets->record_credential_provider_expiry( 'gh', 'provider_expiry', '2020-01-01' );

		self::assertNull( $secrets->credential_material( 'gh', 'provider_expiry' ) );
		self::assertSame( array( 'gh' => array( 'provider_expiry' ) ), $secrets->purge_expired_credentials() );

		InMemorySiteKeyStore::reset( $path );
		foreach ( array( $path, $path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $directory );
	}

	/** @return list<array{bool, bool}> */
	public static function unavailable_runtime_provider(): array {
		return array(
			array( false, false ),
			array( true, true ),
		);
	}

	#[DataProvider( 'unavailable_runtime_provider' )]
	public function test_unavailable_runtime_keeps_display_and_package_only_paths_bootstrap_safe( bool $sodium, bool $multisite ): void {
		$secrets = $this->secrets( $sodium, $multisite );

		self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
		self::assertSame( array(), $secrets->webhook_profiles( 'gh' ) );
		self::assertFalse( $secrets->verify_and_secure() );
		self::assertSame(
			array(),
			$secrets->import_credentials_if_absent( new PackageBlueprint( array() ) )
		);
	}

	/** @return list<array{string}> */
	public static function managed_operation_provider(): array {
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

	#[DataProvider( 'managed_operation_provider' )]
	public function test_unavailable_runtime_blocks_managed_credential_and_webhook_use_with_safe_errors( string $operation ): void {
		$secrets = $this->secrets( false, false );

		try {
			match ( $operation ) {
				'credential_material' => $secrets->credential_material( 'gh', 'managed-id' ),
				'credential_write'    => $secrets->save_credential( 'gh', null, array(), 'secret-canary' ),
				'credential_delete'   => $secrets->delete_credential( 'gh', 'managed-id' ),
				'webhook_material'    => $secrets->webhook_materials( 'gh' ),
				'webhook_write'       => $secrets->save_webhook( 'gh', null, array(), 'secret-canary' ),
				'webhook_delete'      => $secrets->delete_webhook( 'gh', 'managed-id' ),
				'credential_material_invalid_provider' => $secrets->credential_material( 'invalid-provider' ),
				'credential_write_invalid_provider' => $secrets->save_credential( 'invalid-provider', null, array(), 'secret-canary' ),
				'credential_delete_invalid_id' => $secrets->delete_credential( 'gh', '' ),
				'webhook_write_invalid_provider' => $secrets->save_webhook( 'invalid-provider', null, array(), 'secret-canary' ),
				'webhook_delete_invalid_id' => $secrets->delete_webhook( 'gh', '' ),
				'temporary_credential' => $secrets->with_temporary_credential(
					'gh',
					array(),
					'secret-canary',
					static fn (): null => null
				),
				'storage_ready'       => $secrets->assert_managed_storage_ready(),
				default               => throw new \UnhandledMatchError( 'Unknown managed-operation fixture.' ),
			};
			self::fail( 'Managed secret use must fail closed when the runtime is unsupported.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure->get_diagnostic_id() );
			self::assertStringContainsString( 'Sodium extension is missing', $failure->getMessage() );
			self::assertStringNotContainsString( 'secret-canary', $failure->getMessage() );
			self::assertStringNotContainsString( '/srv/', $failure->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Inspect exception trace bytes to prove secret redaction; no production logging occurs.
			self::assertStringNotContainsString( 'secret-canary', var_export( $failure->getTrace(), true ) );
		}
	}

	public function test_unavailable_runtime_redacts_imported_credential_arguments_from_the_whole_trace(): void {
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
			$this->secrets( false, false )->import_credentials_if_absent( $blueprint, $credential );
			self::fail( 'Credential import must fail closed when the runtime is unavailable.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Inspect exception trace bytes to prove secret redaction; no production logging occurs.
			self::assertStringNotContainsString( $canary, var_export( $failure->getTrace(), true ) );
		}
	}

	public function test_explicit_constant_credential_bypasses_unavailable_runtime(): void {
		$secrets = new SecretsFile(
			constants: array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'constant-secret-canary' ),
			provider_policies: ShippedSecretPolicyCatalog::create(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		self::assertSame(
			'constant-secret-canary',
			$secrets->credential_material( 'gh', SecretsFile::CONSTANT_PROFILE )['secret']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_confirmed_uninstall_gets_adeletion_only_single_site_availability_context(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WP_UNINSTALL_PLUGIN', 'renamed-booster/ran-booster.php' );

		$availability = SecretsRuntimeAvailability::for_confirmed_uninstall(
			plugin_file: '/srv/wp-content/plugins/renamed-booster/ran-booster.php'
		);

		self::assertSame( 'available', $availability->code() );
		self::assertTrue( $availability->is_available() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_confirmed_uninstall_rejects_an_unrelated_same_basename_plugin(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WP_UNINSTALL_PLUGIN', 'other-plugin/ran-booster.php' );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'cleanup context is unavailable' );
		SecretsRuntimeAvailability::for_confirmed_uninstall(
			'/srv/wp-content/plugins/ran-booster/ran-booster.php'
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_confirmed_uninstall_rejects_amissing_word_press_identity(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'cleanup context is unavailable' );
		SecretsRuntimeAvailability::for_confirmed_uninstall(
			'/srv/wp-content/plugins/ran-booster/ran-booster.php'
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_confirmed_converted_uninstall_authenticates_and_deletes_areal_sidecar_and_key(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$codec         = new EncryptedSecretsEnvelopeCodec();
		$secrets       = $this->real_secrets( $path, $key_store, $codec, new SecretsRuntimeAvailability( true, false ) );
		$secrets->save_credential(
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
		self::assertNotNull( $key_store->load( false ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WP_UNINSTALL_PLUGIN', 'renamed-booster/ran-booster.php' );
		$confirmed = $this->real_secrets(
			$path,
			$key_store,
			$codec,
			SecretsRuntimeAvailability::for_confirmed_uninstall(
				'/srv/wp-content/plugins/renamed-booster/ran-booster.php'
			)
		);
		$confirmed->assert_managed_storage_deletable();
		self::assertFileExists( $path );
		self::assertFileExists( $path . '.lock' );
		self::assertNotNull( $key_store->load( false ) );
		$confirmed->delete_managed_storage();

		self::assertFileDoesNotExist( $path );
		self::assertFileDoesNotExist( $path . '.lock' );
		self::assertNull( $key_store->load( false ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_confirmed_converted_uninstall_preserves_incomplete_sidecar_material(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$codec         = new EncryptedSecretsEnvelopeCodec();
		$secrets       = $this->real_secrets( $path, $key_store, $codec, new SecretsRuntimeAvailability( true, false ) );
		$secrets->save_credential(
			'gh',
			'pre_conversion',
			array(
				'label'         => 'Pre-conversion credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'pre-conversion-secret-canary'
		);
		$key = $key_store->load( false );
		self::assertNotNull( $key );
		self::assertTrue( $key_store->delete_exact( $key ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WP_UNINSTALL_PLUGIN', 'ran-booster/ran-booster.php' );

		try {
			$this->real_secrets(
				$path,
				$key_store,
				$codec,
				SecretsRuntimeAvailability::for_confirmed_uninstall(
					'/srv/wp-content/plugins/ran-booster/ran-booster.php'
				)
			)->assert_managed_storage_deletable();
			self::fail( 'Incomplete converted-install material must stop uninstall.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'incomplete', $failure->getMessage() );
			self::assertSame( 'storage_key_missing', $failure->reason() );
			self::assertStringNotContainsString( $path, $failure->getMessage() );
		}

		self::assertFileExists( $path );
		self::assertFileExists( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	public function test_deletion_preflight_rejects_recoverable_partial_stores_but_deletes_safe_lock_only_residue(): void {
		[$key_root, $key_path] = $this->sidecar_fixture();
		$key_store             = new InMemorySiteKeyStore( $key_path );
		$key                   = $key_store->load_or_create()['key'];
		$key_only              = $this->real_secrets(
			$key_path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$this->assert_storage_preflight_refused( $key_only, 'storage_lock_missing' );
		self::assertSame( $key, $key_store->load( false ) );
		self::assertTrue( $key_store->delete_exact( $key ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $key_root );

		[$lock_root, $lock_path] = $this->sidecar_fixture();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $lock_path . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $lock_path . '.lock', 0600 ) );
		$lock_only = $this->real_secrets(
			$lock_path,
			new InMemorySiteKeyStore( $lock_path ),
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		self::assertFalse( $lock_only->has_healthy_managed_storage() );
		$lock_only->assert_managed_storage_deletable();
		$lock_only->delete_managed_storage();
		self::assertFileDoesNotExist( $lock_path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $lock_root );

		[$missing_root, $missing_path] = $this->sidecar_fixture();
		$missing_key_store             = new InMemorySiteKeyStore( $missing_path );
		$missing_lock                  = $this->real_secrets(
			$missing_path,
			$missing_key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$missing_lock->save_credential(
			'gh',
			'pre_conversion',
			array(
				'label'         => 'Pre-conversion credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'pre-conversion-secret-canary'
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		self::assertTrue( unlink( $missing_path . '.lock' ) );
		$this->assert_storage_preflight_refused( $missing_lock, 'storage_lock_missing' );
		self::assertFileExists( $missing_path );
		self::assertNotNull( $missing_key_store->load( false ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $missing_path );
		$missing_key = $missing_key_store->load( false );
		self::assertNotNull( $missing_key );
		self::assertTrue( $missing_key_store->delete_exact( $missing_key ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $missing_root );
	}

	public function test_explicit_orphaned_key_reset_rechecks_state_and_leaves_the_managed_lock_for_fresh_initialization(): void {
		foreach ( array( false, true ) as $existing_lock ) {
			[$root, $path] = $this->sidecar_fixture();
			$key_store     = new InMemorySiteKeyStore( $path );
			$old_key       = $key_store->load_or_create()['key'];
			$secrets       = $this->real_secrets(
				$path,
				$key_store,
				new EncryptedSecretsEnvelopeCodec(),
				new SecretsRuntimeAvailability( true, false )
			);
			if ( $existing_lock ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
				self::assertNotFalse( file_put_contents( $path . '.lock', '' ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
				self::assertTrue( chmod( $path . '.lock', 0600 ) );
			}

			self::assertTrue( $secrets->can_reset_orphaned_key_at( $path ) );
			$secrets->reset_orphaned_key_at( $path );

			self::assertNull( $key_store->load( false ) );
			self::assertFileDoesNotExist( $path );
			self::assertFileExists( $path . '.lock' );
			clearstatcache( true, $path . '.lock' );
			self::assertSame( 0600, fileperms( $path . '.lock' ) & 0777 );
			self::assertFalse( $secrets->can_reset_orphaned_key_at( $path ) );

			$secrets->save_credential(
				'gh',
				'fresh-profile',
				array(
					'label'         => 'Fresh credential',
					'kind'          => 'classic',
					'configuration' => array(),
				),
				'fresh-secret-canary'
			);
			self::assertFileExists( $path );
			self::assertNotNull( $key_store->load( false ) );
			self::assertNotSame( $old_key, $key_store->load( false ) );
			self::assertTrue( $secrets->has_healthy_managed_storage() );

			InMemorySiteKeyStore::reset( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $path . '.lock' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $root );
		}
	}

	public function test_explicit_orphaned_ciphertext_reset_rechecks_state_and_leaves_the_managed_lock_for_fresh_initialization(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$secrets       = $this->real_secrets(
			$path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$secrets->save_credential(
			'gh',
			'orphaned-ciphertext',
			array(
				'label'         => 'Orphaned ciphertext credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'orphaned-ciphertext-secret-canary'
		);
		$old_key = $key_store->load( false );
		self::assertNotNull( $old_key );
		self::assertTrue( $key_store->delete_exact( $old_key ) );

		self::assertTrue( $secrets->can_reset_orphaned_ciphertext_at( $path ) );
		$secrets->reset_orphaned_ciphertext_at( $path );

		self::assertFileDoesNotExist( $path );
		self::assertFileExists( $path . '.lock' );
		self::assertNull( $key_store->load( false ) );
		self::assertFalse( $secrets->can_reset_orphaned_ciphertext_at( $path ) );

		$secrets->save_credential(
			'gh',
			'fresh-after-ciphertext-reset',
			array(
				'label'         => 'Fresh credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'fresh-after-ciphertext-reset-secret-canary'
		);
		self::assertFileExists( $path );
		self::assertNotNull( $key_store->load( false ) );
		self::assertTrue( $secrets->has_healthy_managed_storage() );

		InMemorySiteKeyStore::reset( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	public function test_orphaned_ciphertext_reset_refuses_areappeared_database_key_or_untrusted_file(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$secrets       = $this->real_secrets(
			$path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		$secrets->save_credential(
			'gh',
			'restored-key',
			array(
				'label'         => 'Restored key credential',
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'restored-key-secret-canary'
		);
		$key = $key_store->load( false );
		self::assertNotNull( $key );
		self::assertTrue( $key_store->delete_exact( $key ) );
		self::assertTrue( $secrets->can_reset_orphaned_ciphertext_at( $path ) );

		$key_store->load_or_create();
		self::assertFalse( $secrets->can_reset_orphaned_ciphertext_at( $path ) );
		try {
			$secrets->reset_orphaned_ciphertext_at( $path );
			self::fail( 'A restored database key must stop ciphertext reset.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'changed', $failure->getMessage() );
		}
		self::assertFileExists( $path );

		$restored = $key_store->load( false );
		self::assertNotNull( $restored );
		self::assertTrue( $key_store->delete_exact( $restored ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $path, 0660 ) );
		try {
			$secrets->reset_orphaned_ciphertext_at( $path );
			self::fail( 'An insecure ciphertext file must stop reset.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'invalid encrypted Booster secrets file', $failure->getMessage() );
		}
		self::assertFileExists( $path );

		InMemorySiteKeyStore::reset( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	public function test_orphaned_key_reset_refuses_achanged_path_or_restored_ciphertext(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$key           = $key_store->load_or_create()['key'];
		$secrets       = $this->real_secrets(
			$path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);

		self::assertFalse( $secrets->can_reset_orphaned_key_at( $path . '.changed' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $path, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $path, 0600 ) );
		self::assertFalse( $secrets->can_reset_orphaned_key_at( $path ) );
		try {
			$secrets->reset_orphaned_key_at( $path );
			self::fail( 'Restored ciphertext must stop the orphaned-key reset.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'changed', $failure->getMessage() );
		}
		self::assertSame( $key, $key_store->load( false ) );

		InMemorySiteKeyStore::reset( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	public function test_orphaned_key_reset_does_not_repair_an_untrusted_existing_lock(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new InMemorySiteKeyStore( $path );
		$key           = $key_store->load_or_create()['key'];
		$secrets       = $this->real_secrets(
			$path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $path . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $path . '.lock', 0660 ) );

		try {
			$secrets->reset_orphaned_key_at( $path );
			self::fail( 'An insecure existing lock must block reset.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'secure', $failure->getMessage() );
		}
		self::assertSame( 0660, fileperms( $path . '.lock' ) & 0777 );
		self::assertSame( $key, $key_store->load( false ) );

		InMemorySiteKeyStore::reset( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	public function test_orphaned_key_reset_preserves_akey_that_changes_before_exact_deletion(): void {
		[$root, $path] = $this->sidecar_fixture();
		$key_store     = new class() extends SiteKeyStore {
			public string $key;

			public function __construct() {
				$this->key = random_bytes( 32 );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of load retains the production method contract; these inputs do not affect this controlled result.
			public function load( bool $repair_autoload = true ): string {
				return $this->key;
			}

			public function load_or_create(): array {
				return array(
					'key'     => $this->key,
					'created' => false,
				);
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of delete_exact retains the production method contract; these inputs do not affect this controlled result.
			public function delete_exact( #[\SensitiveParameter] string $key ): bool {
				$this->key = random_bytes( 32 );

				return false;
			}
		};
		$old_key       = $key_store->key;
		$secrets       = $this->real_secrets(
			$path,
			$key_store,
			new EncryptedSecretsEnvelopeCodec(),
			new SecretsRuntimeAvailability( true, false )
		);

		try {
			$secrets->reset_orphaned_key_at( $path );
			self::fail( 'A replaced database key must not be treated as the exact orphaned key.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'could not be removed safely', $failure->getMessage() );
		}
		self::assertNotSame( $old_key, $key_store->key );
		self::assertFileDoesNotExist( $path );
		self::assertFileExists( $path . '.lock' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		unlink( $path . '.lock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $root );
	}

	private function secrets( bool $sodium, bool $multisite ): SecretsFile {
		return new SecretsFile(
			constants: array(),
			provider_policies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( $sodium, $multisite )
		);
	}

	/** @return array{string, string} */
	private function sidecar_fixture(): array {
		$root = sys_get_temp_dir() . '/ran-booster-converted-uninstall-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $root, 0700 ) );

		return array( $root, $root . '/secrets.json' );
	}

	private function real_secrets(
		string $path,
		SiteKeyStore $key_store,
		EncryptedSecretsEnvelopeCodec $codec,
		SecretsRuntimeAvailability $availability
	): SecretsFile {
		return new SecretsFile(
			$path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$key_store,
			$codec,
			availability: $availability
		);
	}

	private function assert_storage_preflight_refused( SecretsFile $secrets, string $expected_reason ): void {
		try {
			$secrets->assert_managed_storage_deletable();
			self::fail( 'Incomplete managed storage must fail the deletion preflight.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertStringContainsString( 'incomplete', $failure->getMessage() );
			self::assertSame( $expected_reason, $failure->reason() );
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Native temporary files exercise the authenticated encrypted-sidecar contract.

use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RuntimeException;

/**
 * Proves schema-v2 structural validation and exact provider-policy boundaries.
 *
 * Secret values are reduced to booleans at the instrumentation boundary so a
 * failed assertion cannot print them.
 */
final class SecretsFileCanonicalPolicyCharacterizationTest extends TestCase {

	private string $directory;
	private string $path;
	private InMemorySiteKeyStore $key_store;
	private EncryptedSecretsEnvelopeCodec $codec;
	private CanonicalPolicyCallRecorder $calls;
	private ProviderSecretPolicyCatalog $policies;
	private SecretsFile $secrets;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-canonical-policy-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->key_store = new InMemorySiteKeyStore( $this->path );
		$this->codec     = new EncryptedSecretsEnvelopeCodec();
		$this->calls     = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$this->policies  = $this->catalog( $this->calls );
		$this->secrets   = $this->new_secrets( array(), $this->policies );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $file );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	public function test_structural_storage_reads_never_invoke_provider_policy(): void {
		$this->seed_representative_document();

		$operations = array(
			'managed-storage readiness' => function (): null {
				$this->secrets->assert_managed_storage_ready();

				return null;
			},
			'healthy-storage check'     => fn (): bool => $this->secrets->has_healthy_managed_storage(),
			'storage verification'      => fn (): bool => $this->secrets->verify_and_secure(),
			'deletion preflight'        => function (): null {
				$this->secrets->assert_managed_storage_deletable();

				return null;
			},
		);

		foreach ( $operations as $label => $operation ) {
			$this->calls->reset();
			$operation();
			self::assertSame( array(), $this->calls->counts(), $label );
			self::assertSame( 0, $this->calls->events_under_lock(), $label );
		}
	}

	public function test_display_reads_use_only_the_requested_constant_overlay(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		self::assertArrayHasKey( 'alpha-credential', $this->secrets->credential_profiles( 'alpha' ) );
		self::assertSame( array( 'alpha:credential:constants' => 1 ), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$this->calls->reset();
		self::assertCount( 2, $this->secrets->webhook_profiles( 'alpha' ) );
		self::assertSame( array( 'alpha:webhook:constants' => 1 ), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_exact_credential_read_revalidates_only_the_selected_record_outside_lock(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		self::assertSame( 'file', $this->secrets->credential_material( 'alpha', 'alpha-credential' )['source'] );
		self::assertSame( array( 'alpha:credential:normalize' => 1 ), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$this->calls->reset();
		self::assertNull( $this->secrets->credential_material( 'alpha', 'missing-credential' ) );
		self::assertSame( array(), $this->calls->counts() );
	}

	public function test_provider_bound_store_cannot_select_or_enumerate_another_provider(): void {
		$this->seed_representative_document();
		$store    = $this->secrets->credentials_for( 'alpha' );
		$material = $store->credential_material( 'alpha-credential' );
		$profiles = $store->credential_profiles();

		self::assertSame( 'alpha', $material['provider'] ?? null );
		self::assertSame( 'alpha-credential', $store->credential_material()['id'] ?? null );
		self::assertNull( $store->credential_material( 'beta-credential' ) );
		self::assertSame( array( 'alpha-credential' ), array_keys( $profiles ) );
		self::assertArrayNotHasKey( 'secret', $profiles['alpha-credential'] );
	}

	public function test_default_credential_revalidates_only_one_structurally_selected_stored_record(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		self::assertSame( 'alpha-credential', $this->secrets->credential_material( 'alpha' )['id'] );
		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:credential:normalize' => 1,
			),
			$this->calls->counts()
		);
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_ambiguous_default_credential_returns_null_without_stored_policy_callbacks(): void {
		$this->seed_representative_document();
		$this->secrets->save_credential(
			'alpha',
			'another-credential',
			$this->credential_metadata( 'Another credential' ),
			'synthetic-another-value'
		);
		$this->calls->reset();

		self::assertNull( $this->secrets->credential_material( 'alpha' ) );
		self::assertSame( array( 'alpha:credential:constants' => 1 ), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_default_constant_credential_precedes_stored_material_without_stored_policy_callbacks(): void {
		$this->seed_representative_document();
		$before        = hash_file( 'sha256', $this->path );
		$this->secrets = $this->new_secrets(
			array( 'RAN_BOOSTER_ALPHA_TOKEN' => 'synthetic-alpha-overlay-value' ),
			$this->policies
		);
		$this->calls->reset();

		self::assertSame( 'constant', $this->secrets->credential_material( 'alpha' )['source'] );
		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:credential:normalize' => 1,
			),
			$this->calls->counts()
		);
		self::assertSame( 0, $this->calls->events_under_lock() );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
	}

	public function test_requested_webhook_read_revalidates_only_its_bounded_candidates_outside_lock(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		self::assertCount( 2, $this->secrets->webhook_materials( 'alpha' ) );
		self::assertSame(
			array(
				'alpha:webhook:constants' => 1,
				'alpha:webhook:normalize' => 2,
			),
			$this->calls->counts()
		);
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_credential_replace_normalizes_only_the_changed_record_outside_lock(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		$this->secrets->save_credential(
			'alpha',
			'alpha-credential',
			$this->credential_metadata( 'Alpha credential renamed' ),
			null
		);

		self::assertSame(
			array( 'alpha:credential:normalize' => 1 ),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->all_normalizations_saw_plaintext() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_webhook_replace_normalizes_only_the_changed_record_outside_lock(): void {
		$this->seed_representative_document();
		$this->calls->reset();

		$this->secrets->save_webhook(
			'alpha',
			'alpha-owner-one',
			$this->webhook_metadata( 'Alpha owner renamed', 'owner-one' ),
			null
		);

		self::assertSame(
			array( 'alpha:webhook:normalize' => 1 ),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->all_normalizations_saw_plaintext() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_empty_reads_call_only_the_requested_overlay_policy_and_do_not_create_storage(): void {
		$this->calls->reset();

		self::assertSame( array(), $this->secrets->credential_profiles( 'alpha' ) );
		self::assertSame( array(), $this->secrets->webhook_profiles( 'alpha' ) );
		self::assertFalse( $this->secrets->verify_and_secure() );
		self::assertFalse( $this->secrets->has_healthy_managed_storage() );

		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:webhook:constants'    => 1,
			),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->all_events_ran_outside_lock() );
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
	}

	public function test_maximum_webhook_candidate_read_revalidates_sixteen_requested_records_outside_lock(): void {
		foreach ( range( 1, SecretsFile::MAX_WEBHOOK_PROFILES ) as $index ) {
			$this->secrets->save_webhook(
				'alpha',
				'alpha-owner-' . $index,
				$this->webhook_metadata( 'Alpha owner ' . $index, 'owner-' . $index ),
				str_repeat( chr( 96 + $index ), 32 )
			);
		}
		$this->calls->reset();

		self::assertSame( SecretsFile::MAX_WEBHOOK_PROFILES, count( $this->secrets->webhook_materials( 'alpha' ) ) );
		self::assertSame(
			array(
				'alpha:webhook:constants' => 1,
				'alpha:webhook:normalize' => SecretsFile::MAX_WEBHOOK_PROFILES,
			),
			$this->calls->counts()
		);
		self::assertSame( 0, $this->calls->events_under_lock() );
		self::assertSame( SecretsFile::MAX_WEBHOOK_PROFILES, $this->calls->normalizations_outside_lock() );
	}

	public function test_constant_overlays_receive_only_requested_declared_names_outside_the_lock_and_never_persist(): void {
		$this->seed_representative_document();
		$before = hash_file( 'sha256', $this->path );
		self::assertIsString( $before );

		$constants     = array(
			'RAN_BOOSTER_ALPHA_TOKEN'          => 'synthetic-alpha-overlay-value',
			'RAN_BOOSTER_ALPHA_UNUSED'         => 'synthetic-alpha-unused-value',
			'RAN_BOOSTER_ALPHA_WEBHOOK_SECRET' => str_repeat( 'w', 32 ),
			'RAN_BOOSTER_BETA_TOKEN'           => 'synthetic-beta-overlay-value',
			'RAN_BOOSTER_UNDECLARED'           => 'synthetic-undeclared-overlay-value',
		);
		$this->secrets = $this->new_secrets( $constants, $this->policies );

		$this->calls->reset();
		self::assertSame( 'constant', $this->secrets->credential_material( 'alpha', SecretsFile::CONSTANT_PROFILE )['source'] );
		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:credential:normalize' => 1,
			),
			$this->calls->counts()
		);
		self::assertSame(
			array( 'RAN_BOOSTER_ALPHA_TOKEN', 'RAN_BOOSTER_ALPHA_UNUSED' ),
			$this->calls->constant_names( 'alpha', 'credential' )
		);
		self::assertTrue( $this->calls->all_events_ran_outside_lock() );

		$this->calls->reset();
		self::assertTrue(
			array_key_exists( SecretsFile::CONSTANT_PROFILE, $this->secrets->webhook_materials( 'alpha' ) ),
			'The requested synthetic webhook overlay was not returned.'
		);
		self::assertSame(
			array(
				'alpha:webhook:constants' => 1,
				'alpha:webhook:normalize' => 3,
			),
			$this->calls->counts()
		);
		self::assertSame(
			array( 'RAN_BOOSTER_ALPHA_WEBHOOK_SECRET' ),
			$this->calls->constant_names( 'alpha', 'webhook' )
		);
		self::assertFalse( $this->calls->provider_was_called( 'beta', 'constants' ) );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$after = hash_file( 'sha256', $this->path );
		self::assertSame( $before, $after );
		$plaintext = $this->decrypted_document();
		self::assertFalse( str_contains( $plaintext, 'synthetic-alpha-overlay-value' ), 'Credential overlay entered the sidecar.' );
		self::assertFalse( str_contains( $plaintext, str_repeat( 'w', 32 ) ), 'Webhook overlay entered the sidecar.' );
		self::assertFalse( str_contains( $plaintext, SecretsFile::CONSTANT_PROFILE ), 'A constant profile entered the sidecar.' );
	}

	public function test_inactive_provider_records_stay_opaque_and_survive_an_unrelated_canonical_rewrite(): void {
		$this->seed_representative_document();
		$before = $this->decoded_document();

		$active_calls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$active_policies = new ProviderSecretPolicyCatalog();
		$active_policies->register(
			ProviderCode::parse( 'beta' ),
			new RecordingCredentialPolicy( 'beta', $active_calls ),
			new RecordingWebhookPolicy( 'beta', $active_calls )
		);
		$active_secrets = $this->new_secrets( array(), $active_policies );

		self::assertTrue(
			array_key_exists( 'beta-credential', $active_secrets->credential_profiles( 'beta' ) ),
			'The active provider could not read its display-safe profile.'
		);
		self::assertSame(
			array(
				'beta:credential:constants' => 1,
			),
			$active_calls->counts()
		);

		$active_calls->reset();
		$active_secrets->save_credential(
			'beta',
			'beta-credential',
			$this->credential_metadata( 'Beta credential renamed' ),
			null
		);
		$after = $this->decoded_document();

		self::assertSame(
			$this->record_digest( $before[ SecretsFile::CREDENTIALS ]['alpha'] ),
			$this->record_digest( $after[ SecretsFile::CREDENTIALS ]['alpha'] )
		);
		self::assertSame(
			$this->record_digest( $before[ SecretsFile::WEBHOOKS ]['alpha'] ),
			$this->record_digest( $after[ SecretsFile::WEBHOOKS ]['alpha'] )
		);
		self::assertSame(
			array( 'beta:credential:normalize' => 1 ),
			$active_calls->counts()
		);
		self::assertSame( 0, $active_calls->events_under_lock() );
	}

	public function test_self_destruct_filtering_and_purge_remain_core_structural_operations(): void {
		$this->secrets->save_credential(
			'alpha',
			'expired-credential',
			$this->credential_metadata( 'Expired credential' ) + array(
				'self_destruct' => true,
				'destroy_on'    => '2020-01-01',
			),
			'synthetic-expired-value'
		);
		$this->secrets->save_credential(
			'alpha',
			'live-credential',
			$this->credential_metadata( 'Live credential' ),
			'synthetic-live-value'
		);
		$this->calls->reset();

		self::assertArrayNotHasKey( 'expired-credential', $this->secrets->credential_profiles( 'alpha' ) );
		self::assertSame( array( 'alpha:credential:constants' => 1 ), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$this->calls->reset();
		self::assertNull( $this->secrets->credential_material( 'alpha', 'expired-credential' ) );
		self::assertSame( array(), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$this->calls->reset();
		self::assertSame( array( 'alpha' => array( 'expired-credential' ) ), $this->secrets->purge_expired_credentials() );
		self::assertSame( array(), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_portability_validates_once_outside_lock_on_first_and_idempotent_import(): void {
		$this->secrets->save_credential(
			'alpha',
			'alpha-credential',
			$this->credential_metadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$credential = new BlueprintCredential(
			'alpha',
			'Portable credential',
			'api-key',
			array( 'tenant' => 'fixture' ),
			'synthetic-portable-value',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'fixture/fixture.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint(
			array(
				new BlueprintPackage(
					'plugin',
					'fixture/fixture.php',
					'Fixture',
					'alpha',
					'fixture-repository-id',
					'fixture/repository',
					'main',
					null
				),
			),
			array( $credential )
		);
		$this->calls->reset();

		$ids = $this->secrets->import_credentials_if_absent( $blueprint, $credential );
		self::assertCount( 1, $ids );
		self::assertSame( 1, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertSame( 1, $this->calls->normalizations_outside_lock() );
		self::assertSame( 0, $this->calls->events_under_lock() );

		$this->calls->reset();
		self::assertSame( $ids, $this->secrets->import_credentials_if_absent( $blueprint, $credential ) );
		self::assertSame( 1, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertSame( 1, $this->calls->normalizations_outside_lock() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_tampered_and_authenticated_non_canonical_documents_fail_before_policy(): void {
		$this->seed_representative_document();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		$canonical_envelope = (string) file_get_contents( $this->path );
		$tampered           = json_decode( $canonical_envelope, true, 4, JSON_THROW_ON_ERROR );
		self::assertIsArray( $tampered );
		$tampered['ciphertext'][12] = 'A' === $tampered['ciphertext'][12] ? 'B' : 'A';
		self::assertNotFalse(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
			file_put_contents( $this->path, json_encode( $tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n" )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->path, 0600 ) );
		$this->calls->reset();

		$this->expect_storage_failure( fn (): bool => $this->secrets->has_healthy_managed_storage() );
		self::assertSame( array(), $this->calls->counts() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->path, $canonical_envelope ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->path, 0600 ) );
		$document      = $this->decoded_document();
		$non_canonical = array(
			SecretsFile::WEBHOOKS    => $document[ SecretsFile::WEBHOOKS ],
			SecretsFile::CREDENTIALS => $document[ SecretsFile::CREDENTIALS ],
			'schema_version'         => SecretsFile::SCHEMA_VERSION,
		);
		$this->write_authenticated_document( $non_canonical );
		$this->calls->reset();

		$this->expect_storage_failure( fn (): bool => $this->secrets->has_healthy_managed_storage() );
		self::assertSame( array(), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_authenticated_malformed_shape_fails_before_any_policy_callback(): void {
		$this->seed_representative_document();
		$this->write_authenticated_document(
			array(
				'schema_version'         => SecretsFile::SCHEMA_VERSION,
				SecretsFile::CREDENTIALS => 'not-a-provider-map',
				SecretsFile::WEBHOOKS    => array(),
			)
		);
		$this->calls->reset();

		$this->expect_storage_failure( fn (): bool => $this->secrets->has_healthy_managed_storage() );
		self::assertSame( array(), $this->calls->counts() );
		self::assertSame( 0, $this->calls->events_under_lock() );
	}

	public function test_policy_drift_fails_only_at_exact_credential_use_without_rewriting(): void {
		$this->secrets->save_credential(
			'alpha',
			'alpha-credential',
			$this->credential_metadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$before = hash_file( 'sha256', $this->path );
		self::assertIsString( $before );

		$drift_calls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$drift_policies = new ProviderSecretPolicyCatalog();
		$drift_policies->register(
			ProviderCode::parse( 'alpha' ),
			new RecordingCredentialPolicy( 'alpha', $drift_calls, true ),
			null
		);
		$drifted = $this->new_secrets( array(), $drift_policies );

		self::assertArrayHasKey( 'alpha-credential', $drifted->credential_profiles( 'alpha' ) );
		self::assertSame(
			array( 'alpha:credential:constants' => 1 ),
			$drift_calls->counts()
		);

		$drift_calls->reset();
		$this->expect_runtime_failure( fn (): ?array => $drifted->credential_material( 'alpha', 'alpha-credential' ) );
		self::assertSame( array( 'alpha:credential:normalize' => 1 ), $drift_calls->counts() );
		self::assertSame( 0, $drift_calls->events_under_lock() );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
	}

	public function test_policy_drift_fails_only_when_requested_webhook_candidates_are_used(): void {
		$this->secrets->save_webhook(
			'alpha',
			'alpha-owner-one',
			$this->webhook_metadata( 'Alpha owner one', 'owner-one' ),
			str_repeat( 'a', 32 )
		);
		$before = hash_file( 'sha256', $this->path );
		self::assertIsString( $before );

		$drift_calls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$drift_policies = new ProviderSecretPolicyCatalog();
		$drift_policies->register(
			ProviderCode::parse( 'alpha' ),
			null,
			new RecordingWebhookPolicy( 'alpha', $drift_calls, true )
		);
		$drifted = $this->new_secrets( array(), $drift_policies );

		self::assertArrayHasKey( 'alpha-owner-one', $drifted->webhook_profiles( 'alpha' ) );
		self::assertSame( array( 'alpha:webhook:constants' => 1 ), $drift_calls->counts() );

		$drift_calls->reset();
		$this->expect_runtime_failure( fn (): array => $drifted->webhook_materials( 'alpha' ) );
		self::assertSame(
			array(
				'alpha:webhook:constants' => 1,
				'alpha:webhook:normalize' => 1,
			),
			$drift_calls->counts()
		);
		self::assertSame( 0, $drift_calls->events_under_lock() );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
	}

	public function test_credential_replacement_rejects_an_exact_target_race_without_holding_the_lock(): void {
		$this->secrets->save_credential(
			'alpha',
			'alpha-credential',
			$this->credential_metadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$racer      = $this->new_secrets( array(), $this->policies );
		$race_calls = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$policies   = new ProviderSecretPolicyCatalog();
		$policies->register(
			ProviderCode::parse( 'alpha' ),
			new RecordingCredentialPolicy(
				'alpha',
				$race_calls,
				false,
				function () use ( $racer ): void {
					$racer->save_credential(
						'alpha',
						'alpha-credential',
						$this->credential_metadata( 'Raced credential' ),
						'synthetic-raced-value'
					);
				}
			),
			null
		);
		$raced = $this->new_secrets( array(), $policies );

		$this->expect_runtime_failure(
			fn (): string => $raced->save_credential(
				'alpha',
				'alpha-credential',
				$this->credential_metadata( 'Outer credential' ),
				'synthetic-outer-value'
			)
		);

		self::assertSame( 'Raced credential', $this->decoded_document()[ SecretsFile::CREDENTIALS ]['alpha']['alpha-credential']['label'] );
		self::assertSame( array( 'alpha:credential:normalize' => 1 ), $race_calls->counts() );
		self::assertSame( 0, $race_calls->events_under_lock() );
	}

	public function test_credential_creation_rejects_an_exact_target_race_without_holding_the_lock(): void {
		$racer      = $this->new_secrets( array(), $this->policies );
		$race_calls = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$policies   = new ProviderSecretPolicyCatalog();
		$policies->register(
			ProviderCode::parse( 'alpha' ),
			new RecordingCredentialPolicy(
				'alpha',
				$race_calls,
				false,
				function () use ( $racer ): void {
					$racer->save_credential(
						'alpha',
						'new-credential',
						$this->credential_metadata( 'Raced creation' ),
						'synthetic-raced-value'
					);
				}
			),
			null
		);
		$raced = $this->new_secrets( array(), $policies );

		$this->expect_runtime_failure(
			fn (): string => $raced->save_credential(
				'alpha',
				'new-credential',
				$this->credential_metadata( 'Outer creation' ),
				'synthetic-outer-value'
			)
		);

		self::assertSame( 'Raced creation', $this->decoded_document()[ SecretsFile::CREDENTIALS ]['alpha']['new-credential']['label'] );
		self::assertSame( array( 'alpha:credential:normalize' => 1 ), $race_calls->counts() );
		self::assertSame( 0, $race_calls->events_under_lock() );
	}

	public function test_webhook_replacement_rejects_an_exact_target_race_without_holding_the_lock(): void {
		$this->secrets->save_webhook(
			'alpha',
			'alpha-owner-one',
			$this->webhook_metadata( 'Alpha owner one', 'owner-one' ),
			str_repeat( 'a', 32 )
		);
		$racer      = $this->new_secrets( array(), $this->policies );
		$race_calls = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$policies   = new ProviderSecretPolicyCatalog();
		$policies->register(
			ProviderCode::parse( 'alpha' ),
			null,
			new RecordingWebhookPolicy(
				'alpha',
				$race_calls,
				false,
				function () use ( $racer ): void {
					$racer->save_webhook(
						'alpha',
						'alpha-owner-one',
						$this->webhook_metadata( 'Raced owner', 'owner-one' ),
						str_repeat( 'r', 32 )
					);
				}
			)
		);
		$raced = $this->new_secrets( array(), $policies );

		$this->expect_runtime_failure(
			fn (): string => $raced->save_webhook(
				'alpha',
				'alpha-owner-one',
				$this->webhook_metadata( 'Outer owner', 'owner-one' ),
				str_repeat( 'o', 32 )
			)
		);

		self::assertSame( 'Raced owner', $this->decoded_document()[ SecretsFile::WEBHOOKS ]['alpha']['alpha-owner-one']['label'] );
		self::assertSame( array( 'alpha:webhook:normalize' => 1 ), $race_calls->counts() );
		self::assertSame( 0, $race_calls->events_under_lock() );
	}

	public function test_canonical_writes_sort_providers_and_ids_and_replace_the_ciphertext_atomically(): void {
		$this->secrets->save_credential(
			'beta',
			'z-credential',
			$this->credential_metadata( 'Z credential' ),
			'synthetic-z-value'
		);
		$before = lstat( $this->path );
		self::assertIsArray( $before );

		$this->secrets->save_credential(
			'alpha',
			'a-credential',
			$this->credential_metadata( 'A credential' ),
			'synthetic-a-value'
		);
		$after = lstat( $this->path );
		self::assertIsArray( $after );
		$document = $this->decoded_document();

		self::assertSame( array( 'alpha', 'beta' ), array_keys( $document[ SecretsFile::CREDENTIALS ] ) );
		self::assertNotSame( $before['ino'], $after['ino'] );
		self::assertSame( 0600, $after['mode'] & 0777 );
		self::assertSame( 1, $after['nlink'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve native JSON bytes for the isolated fixture/trace assertion without loading WordPress.
		$canonical = json_encode( $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		self::assertSame( hash( 'sha256', $canonical ), hash( 'sha256', $this->decrypted_document() ) );
	}

	private function seed_representative_document(): void {
		$this->secrets->save_credential(
			'alpha',
			'alpha-credential',
			$this->credential_metadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$this->secrets->save_credential(
			'beta',
			'beta-credential',
			$this->credential_metadata( 'Beta credential' ),
			'synthetic-beta-value'
		);
		$this->secrets->save_webhook(
			'alpha',
			'alpha-owner-one',
			$this->webhook_metadata( 'Alpha owner one', 'owner-one' ),
			str_repeat( 'a', 32 )
		);
		$this->secrets->save_webhook(
			'alpha',
			'alpha-owner-two',
			$this->webhook_metadata( 'Alpha owner two', 'owner-two' ),
			str_repeat( 'b', 32 )
		);
		$this->secrets->save_webhook(
			'beta',
			'beta-owner-one',
			$this->webhook_metadata( 'Beta owner one', 'owner-one' ),
			str_repeat( 'c', 32 )
		);
	}

	/** @return array<string, mixed> */
	private function credential_metadata( string $label ): array {
		return array(
			'label'         => $label,
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'fixture' ),
		);
	}

	/** @return array<string, mixed> */
	private function webhook_metadata( string $label, string $owner ): array {
		return array(
			'label'        => $label,
			'scope'        => 'owner',
			'target'       => $owner,
			'authority_id' => '',
			'origin'       => 'manual',
		);
	}

	private function catalog( CanonicalPolicyCallRecorder $calls ): ProviderSecretPolicyCatalog {
		$catalog = new ProviderSecretPolicyCatalog();
		foreach ( array( 'alpha', 'beta' ) as $provider ) {
			$catalog->register(
				ProviderCode::parse( $provider ),
				new RecordingCredentialPolicy( $provider, $calls ),
				new RecordingWebhookPolicy( $provider, $calls )
			);
		}

		return $catalog;
	}

	/** @param array<string, mixed> $constants */
	private function new_secrets( array $constants, ProviderSecretPolicyCatalog $policies ): SecretsFile {
		return new SecretsFile(
			$this->path,
			$constants,
			$policies,
			$this->key_store,
			$this->codec
		);
	}

	/** @return array<string, mixed> */
	private function decoded_document(): array {
		$document = json_decode( $this->decrypted_document(), true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $document ) ) {
			throw new RuntimeException( 'The synthetic encrypted document did not decode to an array.' );
		}

		return $document;
	}

	/** @param array<string, mixed> $records */
	private function record_digest( array $records ): string {
		return hash(
			'sha256',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve native JSON bytes for the isolated fixture/trace assertion without loading WordPress.
			json_encode( $records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	private function decrypted_document(): string {
		$key = $this->key_store->load( false );
		self::assertIsString( $key );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		return $this->codec->decrypt( (string) file_get_contents( $this->path ), $key );
	}

	/** @param array<string, mixed> $document */
	private function write_authenticated_document( array $document ): void {
		$key = $this->key_store->load( false );
		self::assertIsString( $key );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve native JSON bytes for the isolated fixture/trace assertion without loading WordPress.
		$plaintext = json_encode( $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->path, $this->codec->encrypt( $plaintext, $key ) ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->path, 0600 ) );
	}

	/** @param callable(): mixed $operation */
	private function expect_storage_failure( callable $operation ): void {
		try {
			$operation();
			self::fail( 'The invalid authenticated sidecar must fail closed.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure->get_diagnostic_id() );
		}
	}

	/** @param callable(): mixed $operation */
	private function expect_runtime_failure( callable $operation ): void {
		try {
			$operation();
			self::fail( 'The bounded operation must fail closed.' );
		} catch ( RuntimeException $failure ) {
			self::assertNotSame( '', $failure->getMessage() );
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class CanonicalPolicyCallRecorder {

	/** @var list<array{provider:string,kind:string,method:string,has_plaintext:bool,lock_held:bool,names:list<string>}> */
	private array $events = array();

	public function __construct( private readonly string $lock_path ) {
	}

	/** @param list<string> $names */
	public function record( string $provider, string $kind, string $method, bool $has_plaintext, array $names = array() ): void {
		$this->events[] = array(
			'provider'      => $provider,
			'kind'          => $kind,
			'method'        => $method,
			'has_plaintext' => $has_plaintext,
			'lock_held'     => $this->lock_is_held(),
			'names'         => $names,
		);
	}

	public function reset(): void {
		$this->events = array();
	}

	/** @return array<string, int> */
	public function counts(): array {
		$counts = array();
		foreach ( $this->events as $event ) {
			$key            = $event['provider'] . ':' . $event['kind'] . ':' . $event['method'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		ksort( $counts, SORT_STRING );

		return $counts;
	}

	public function count( string $provider, string $kind, string $method ): int {
		return $this->counts()[ $provider . ':' . $kind . ':' . $method ] ?? 0;
	}

	/** @return list<string> */
	public function constant_names( string $provider, string $kind ): array {
		foreach ( $this->events as $event ) {
			if ( $provider === $event['provider'] && $kind === $event['kind'] && 'constants' === $event['method'] ) {
				return $event['names'];
			}
		}

		return array();
	}

	public function provider_was_called( string $provider, string $method ): bool {
		foreach ( $this->events as $event ) {
			if ( $provider === $event['provider'] && $method === $event['method'] ) {
				return true;
			}
		}

		return false;
	}

	public function all_normalizations_saw_plaintext(): bool {
		$normalizations = array_filter(
			$this->events,
			static fn ( array $event ): bool => 'normalize' === $event['method']
		);

		return array() !== $normalizations
			&& array_reduce(
				$normalizations,
				static fn ( bool $carry, array $event ): bool => $carry && $event['has_plaintext'],
				true
			);
	}

	public function all_normalizations_ran_under_lock(): bool {
		$normalizations = array_filter(
			$this->events,
			static fn ( array $event ): bool => 'normalize' === $event['method']
		);

		return array() !== $normalizations
			&& array_reduce(
				$normalizations,
				static fn ( bool $carry, array $event ): bool => $carry && $event['lock_held'],
				true
			);
	}

	public function all_events_ran_outside_lock(): bool {
		return array() !== $this->events
			&& array_reduce(
				$this->events,
				static fn ( bool $carry, array $event ): bool => $carry && ! $event['lock_held'],
				true
			);
	}

	public function events_under_lock(): int {
		return count(
			array_filter(
				$this->events,
				static fn ( array $event ): bool => $event['lock_held']
			)
		);
	}

	public function normalizations_under_lock(): int {
		return count(
			array_filter(
				$this->events,
				static fn ( array $event ): bool => 'normalize' === $event['method'] && $event['lock_held']
			)
		);
	}

	public function normalizations_outside_lock(): int {
		return count(
			array_filter(
				$this->events,
				static fn ( array $event ): bool => 'normalize' === $event['method'] && ! $event['lock_held']
			)
		);
	}

	private function lock_is_held(): bool {
		if ( ! is_file( $this->lock_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Use the native stream required by the fixture lock-contention contract.
		$handle = fopen( $this->lock_path, 'r+b' );
		if ( false === $handle ) {
			return true;
		}

		try {
			$acquired = flock( $handle, LOCK_EX | LOCK_NB );
			if ( $acquired ) {
				flock( $handle, LOCK_UN );
			}

			return ! $acquired;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Use the native stream required by the fixture lock-contention contract.
			fclose( $handle );
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final readonly class RecordingCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct(
		private string $provider,
		private CanonicalPolicyCallRecorder $calls,
		private bool $reject_stored_material = false,
		private ?\Closure $before_normalize = null
	) {
	}

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( $this->provider );
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		$this->calls->record( $this->provider, 'credential', 'normalize', is_string( $secret ) && '' !== $secret );
		if ( null !== $this->before_normalize ) {
			( $this->before_normalize )();
		}
		if ( $this->reject_stored_material ) {
			throw new RuntimeException( 'The upgraded synthetic policy rejects the stored record.' );
		}

		return array(
			'label'         => is_string( $metadata['label'] ?? null ) ? trim( $metadata['label'] ) : '',
			'kind'          => is_string( $metadata['kind'] ?? null ) ? $metadata['kind'] : '',
			'configuration' => is_array( $metadata['configuration'] ?? null ) ? $metadata['configuration'] : array(),
			'secret'        => is_string( $secret ) ? trim( $secret ) : '',
		);
	}

	public function get_constant_names(): array {
		return array(
			'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_TOKEN',
			'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_UNUSED',
		);
	}

	public function credential_from_constants( array $constants ): ?array {
		$this->calls->record( $this->provider, 'credential', 'constants', false, array_keys( $constants ) );
		$name   = 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_TOKEN';
		$secret = $constants[ $name ] ?? null;
		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return null;
		}

		return array(
			'label'         => ucfirst( $this->provider ) . ' constant',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'constant' ),
			'secret'        => $secret,
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final readonly class RecordingWebhookPolicy implements ProviderWebhookPolicy {

	public function __construct(
		private string $provider,
		private CanonicalPolicyCallRecorder $calls,
		private bool $reject_stored_material = false,
		private ?\Closure $before_normalize = null
	) {
	}

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( $this->provider );
	}

	public function get_retained_headers(): array {
		return array( 'x-fixture-signature' );
	}

	public function get_signature_header(): string {
		return 'x-fixture-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		$this->calls->record( $this->provider, 'webhook', 'normalize', is_string( $secret ) && '' !== $secret );
		if ( null !== $this->before_normalize ) {
			( $this->before_normalize )();
		}
		if ( $this->reject_stored_material ) {
			throw new RuntimeException( 'The upgraded synthetic policy rejects the stored webhook.' );
		}

		return array(
			'label'        => is_string( $metadata['label'] ?? null ) ? trim( $metadata['label'] ) : '',
			'scope'        => is_string( $metadata['scope'] ?? null ) ? $metadata['scope'] : '',
			'target'       => is_string( $metadata['target'] ?? null ) ? $metadata['target'] : '',
			'authority_id' => is_string( $metadata['authority_id'] ?? null ) ? $metadata['authority_id'] : '',
			'secret'       => is_string( $secret ) ? $secret : '',
		);
	}

	public function get_constant_names(): array {
		return array( 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_WEBHOOK_SECRET' );
	}

	public function webhook_from_constants( array $constants ): ?array {
		$this->calls->record( $this->provider, 'webhook', 'constants', false, array_keys( $constants ) );
		$name   = 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_WEBHOOK_SECRET';
		$secret = $constants[ $name ] ?? null;
		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return null;
		}

		return array(
			'label'        => ucfirst( $this->provider ) . ' constant webhook',
			'scope'        => 'owner',
			'target'       => $this->provider . '-owner',
			'authority_id' => '',
			'secret'       => $secret,
		);
	}

	public function authorize_webhook( SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return $target === $repository_locator;
	}
}

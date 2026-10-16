<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use RAN\Tests\Secrets\SecretsFileTestFactory;
use RAN\Tests\Secrets\InMemorySiteKeyStore;
use RAN\WordPress\WordPressUpdaterLock;

require_once __DIR__ . '/../Deployment/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/../Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/../Support/ProviderProfileAdminControllerWordPressFunctions.php';
require_once __DIR__ . '/../Support/WPError.php';

final class GitHubAdoptionTransitionTest extends TestCase {
	private string $root;
	private AttemptRepositoryDatabase $db;
	private GitHubSelectionStore $store;
	private GitHubProviderSelection $selection;
	private DeploymentAttemptRepository $attempts;
	private SecretsFile $secrets;
	private mixed $previous_database;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ran-adoption-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable private filesystem fixture.
		mkdir( $this->root, 0700 );
		$this->db                = new AttemptRepositoryDatabase();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Supply the real updater lock's isolated database boundary.
		$GLOBALS['wpdb'] = $this->db;
		$this->store     = new GitHubSelectionStore( $this->db, 'wp_options' );
		$bundled         = $this->declaration( 'booster.php' );
		$this->write( 'booster.php', '<?php // bundled' );
		$this->activate( array( 'booster.php' ) );
		$this->store->initialize_bundled( $bundled );
		$this->selection = new GitHubProviderSelection( $this->store, $this->root, $bundled );
		$this->attempts  = new DeploymentAttemptRepository( $this->db, 'wp_attempts', database_lifecycle: $this->createStub( Database::class ), selection: $this->selection );
		$this->secrets   = SecretsFileTestFactory::create( $this->root . '/secrets', array(), ShippedSecretPolicyCatalog::create() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_nonce_valid'], $GLOBALS['ran_booster_test_nonce_checks'] );
		$files = glob( $this->root . '/*' );
		foreach ( false === $files ? array() : $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable test-owned files only.
			unlink( $file );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable test-owned directory only.
		rmdir( $this->root );
		InMemorySiteKeyStore::reset( $this->root . '/secrets' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the isolated database boundary.
		$GLOBALS['wpdb'] = $this->previous_database;
	}

	public function test_admin_adoption_checks_capability_and_digest_bound_nonce_before_inventory(): void {
		$this->candidate();
		$digest                                   = $this->selection->adoption_review( $this->secrets )['digest'];
		$GLOBALS['ran_booster_test_capabilities'] = array( 'activate_plugins' => false );
		$GLOBALS['ran_booster_test_nonce_checks'] = array();
		try {
			$this->admin_controller()->adopt_github(
				array(
					'review_digest' => $digest,
					'confirmed'     => '1',
				)
			);
			self::fail( 'Missing activation capability must refuse.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'permissions', $error->getMessage() );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		$GLOBALS['ran_booster_test_capabilities'] = array();
		$GLOBALS['ran_booster_test_nonce_valid']  = false;
		try {
			$this->admin_controller()->adopt_github(
				array(
					'review_digest' => $digest,
					'confirmed'     => '1',
				)
			);
			self::fail( 'Invalid nonce must refuse.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Invalid nonce.', $error->getMessage() );
		}
		self::assertSame( array( 'ran-booster-adopt-github-' . $digest ), $GLOBALS['ran_booster_test_nonce_checks'] );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_admin_adoption_rejects_stale_or_malformed_consent_then_accepts_fresh_bulk_approval(): void {
		$this->candidate();
		$GLOBALS['ran_booster_test_capabilities'] = array();
		$GLOBALS['ran_booster_test_nonce_valid']  = true;
		$review                                   = $this->selection->adoption_review( $this->secrets );
		$this->credential( 'classic' );
		$this->admin_controller()->adopt_github(
			array(
				'review_digest' => $review['digest'],
				'confirmed'     => '1',
			)
		);
		self::assertSame( 0, $this->store->read()['selection_generation'] );
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( null, array( '1' ), true ) as $reuse ) {
			$this->admin_controller()->adopt_github(
				array(
					'review_digest'  => $review['digest'],
					'confirmed'      => '1',
					'reuse_existing' => $reuse,
				)
			);
			self::assertSame( 0, $this->store->read()['selection_generation'] );
		}
		$this->admin_controller()->adopt_github(
			array(
				'review_digest'  => $review['digest'],
				'confirmed'      => '1',
				'reuse_existing' => '1',
			)
		);
		self::assertSame( 1, $this->store->read()['selection_generation'] );
	}

	public function test_paused_settings_are_passive_and_other_provider_settings_remain_available(): void {
		$this->candidate();
		$registry  = new \RAN\RepositoryProvider\ProviderRegistry( array( new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( 'bb' ) ) );
		$presenter = new \RAN\Admin\ProviderSettingsPresenter(
			$registry,
			$this->secrets,
			new \RAN\Storage\CredentialUsageReader(),
			github_selection: $this->selection
		);
		$github    = $presenter->build( 'gh' );
		self::assertSame( 'gh', $github['selected_provider'] );
		self::assertSame( array(), $github['credential_profiles'] );
		self::assertFalse( $github['github_selection']['available'] );
		self::assertSame( 0, $github['github_selection']['review']['compatible_count'] );
		self::assertSame( 'bb', $presenter->build( 'bb' )['selected_provider'] );
		self::assertArrayNotHasKey( 'gh', $registry->all() );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_stale_registered_github_is_never_called_by_paused_settings_or_bitbucket_tabs(): void {
		$provider = $this->createMock( \RAN\RepositoryProvider\RepositoryProvider::class );
		$provider->expects( self::once() )->method( 'get_metadata' )->willReturn(
			new \RAN\RepositoryProvider\ProviderMetadata(
				\RAN\RepositoryProvider\ProviderCode::parse( 'gh' ),
				'GitHub',
				'https://github.com/',
				'Owner',
				new \RAN\RepositoryProvider\Admin\ProviderAdminMetadata( array(), array() )
			)
		);
		$provider->expects( self::never() )->method( 'prepare_archive' );
		$registry = new \RAN\RepositoryProvider\ProviderRegistry( array( $provider, new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( 'bb' ) ) );
		$this->candidate();
		$presenter = new \RAN\Admin\ProviderSettingsPresenter( $registry, $this->secrets, new \RAN\Storage\CredentialUsageReader(), github_selection: $this->selection );
		$github    = $presenter->build( 'gh' );
		self::assertSame( 'gh', $github['selected_provider'] );
		self::assertFalse( $github['github_selection']['available'] );
		self::assertSame( array(), $github['credential_profiles'] );
		self::assertSame( 'bb', $presenter->build( 'bb' )['selected_provider'] );
	}

	private function admin_controller(): \RAN\Admin\ProviderProfileAdminController {
		return new \RAN\Admin\ProviderProfileAdminController(
			$this->createStub( \RAN\Dashboard::class ),
			new \RAN\RepositoryProvider\ProviderRegistry(),
			$this->secrets,
			new \RAN\Admin\ManagedPackageWebhookAuthorityResolver( $this->createStub( \RAN\Storage\PluginRepository::class ), $this->createStub( \RAN\Storage\ThemeRepository::class ) ),
			new WordPressUpdaterLock(),
			new \RAN\Storage\CredentialUsageReader(),
			new \RAN\Admin\PublicRepositoryLookupProfileStore(),
			new \RAN\Admin\CredentialExpiryObservationStore(),
			github_selection: $this->selection,
			deployment_attempts: $this->attempts
		);
	}

	public function test_empty_adoption_and_return_increment_generation_without_creating_credentials(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		self::assertFalse( $review['has_material'] );
		self::assertFileExists( $this->root . '/secrets.lock' );
		self::assertFileDoesNotExist( $this->root . '/secrets' );
		$this->transition( $review['digest'] );
		self::assertSame( 1, $this->store->read()['selection_generation'] );
		self::assertSame( 'replacement', $this->store->read()['selected'] );
		self::assertFalse( $this->selection->operation_allowed() );
		$this->activate( array( 'booster.php' ) );
		$this->transition( $this->selection->adoption_review( $this->secrets )['digest'] );
		self::assertSame( 2, $this->store->read()['selection_generation'] );
		self::assertSame( 'bundled', $this->store->read()['selected'] );
		self::assertSame( GitHubReplacementDeclaration::API_CREDENTIAL_FORMATS, $this->store->read()['approved_api_formats'] );
		self::assertNull( SecretsFileTestFactory::key_store( $this->root . '/secrets' )->load() );
	}

	public function test_mixed_inventory_requires_one_reuse_consent_and_preserves_every_profile(): void {
		$this->credential( 'classic' );
		$this->credential( 'fine-grained' );
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		self::assertSame( 1, $review['compatible_count'] );
		self::assertSame( 1, $review['incompatible_count'] );
		$before = hash_file( 'sha256', $this->root . '/secrets' );
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
		$this->transition( $review['digest'], true );
		self::assertSame( $before, hash_file( 'sha256', $this->root . '/secrets' ) );
		self::assertCount( 2, $this->secrets->github_adoption_inventory() );
	}

	public function test_new_credential_after_empty_review_requires_fresh_review(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		$this->credential( 'classic' );
		$this->refused( fn () => $this->transition( $review['digest'], true ) );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_incompatible_only_inventory_requires_explicit_acknowledgement(): void {
		$this->credential( 'fine-grained' );
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		$this->transition( $review['digest'], false, true );
		self::assertSame( array( 'github-classic-pat-v1' ), $this->store->read()['approved_api_formats'] );
	}

	public function test_expired_and_malformed_updater_locks_are_never_reclaimed(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( '1', 'malformed' ) as $token ) {
			$this->db->option_rows['auto_updater.lock'] = $token;
			$this->refused( fn () => $this->transition( $review['digest'] ) );
			self::assertSame( $token, $this->db->option_rows['auto_updater.lock'] );
		}
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_running_and_unresolved_github_block_but_queued_and_bitbucket_do_not(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( 'running', 'needs_attention' ) as $state ) {
			$this->db->rows = array(
				array(
					'id'          => 1,
					'provider'    => 'gh',
					'state'       => $state,
					'resolved_at' => null,
					'resolved_by' => null,
				),
			);
			$this->refused( fn () => $this->transition( $review['digest'] ) );
		}
		$this->db->rows = array(
			array(
				'id'       => 1,
				'provider' => 'gh',
				'state'    => 'queued',
			),
			array(
				'id'       => 2,
				'provider' => 'bb',
				'state'    => 'running',
			),
		);
		$this->transition( $review['digest'] );
		self::assertSame( 1, $this->store->read()['selection_generation'] );
	}

	public function test_authority_callback_changes_target_before_cas_and_refuses(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		$calls  = 0;
		$this->refused(
			function () use ( $review, &$calls ): void {
				$this->selection->transition_selection(
					$review['digest'],
					true,
					false,
					false,
					$this->secrets,
					$this->attempts,
					new WordPressUpdaterLock(),
					function () use ( &$calls ): bool {
						if ( 2 === ++$calls ) {
							$this->activate( array( 'booster.php' ) );
						}
						return true;
					}
				);
			}
		);
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_unknown_commit_never_returns_success_or_retries_on_same_store(): void {
		$this->candidate();
		$review                = $this->selection->adoption_review( $this->secrets );
		$this->db->fail_commit = true;
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		$this->db->fail_commit = false;
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_empty_inventory_lock_blocks_a_second_writer_without_creating_material(): void {
		$this->secrets->with_github_adoption_inventory(
			function ( array $inventory ): void {
				self::assertSame( array(), $inventory );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Inspect the real disposable lock through an independent handle.
				$handle = fopen( $this->root . '/secrets.lock', 'r+b' );
				self::assertIsResource( $handle );
				try {
					self::assertFalse( flock( $handle, LOCK_EX | LOCK_NB ) );
					self::assertFileDoesNotExist( $this->root . '/secrets' );
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only the independent test handle.
					fclose( $handle );
				}
			}
		);
	}

	public function test_missing_main_confirmation_or_capability_never_acquires_lock(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( array( false, true ), array( true, false ) ) as list( $confirmed, $authorized ) ) {
			$this->refused( fn () => $this->selection->transition_selection( $review['digest'], $confirmed, false, false, $this->secrets, $this->attempts, new WordPressUpdaterLock(), static fn (): bool => $authorized ) );
		}
		self::assertSame( 0, $this->store->read()['selection_generation'] );
		self::assertArrayNotHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_write_and_readback_failures_preserve_selection(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( 'fail_write', 'corrupt_readback' ) as $fault ) {
			$this->db->selection->selection->$fault = true;
			$this->refused( fn () => $this->transition( $review['digest'] ) );
			$this->db->selection->selection->$fault = false;
			self::assertSame( 0, $this->store->read()['selection_generation'] );
		}
	}

	public function test_lock_release_failure_reports_failure_even_after_verified_commit(): void {
		$this->candidate();
		$review                        = $this->selection->adoption_review( $this->secrets );
		$this->db->zero_query_contains = 'DELETE FROM `wp_options`';
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		self::assertSame( 1, $this->store->read()['selection_generation'] );
		self::assertArrayHasKey( 'auto_updater.lock', $this->db->option_rows );
	}

	public function test_constant_inventory_requires_reuse_and_remains_redacted(): void {
		$this->candidate();
		$this->secrets = SecretsFileTestFactory::create( $this->root . '/secrets', array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'test-only-secret-token' ), ShippedSecretPolicyCatalog::create() );
		$review        = $this->selection->adoption_review( $this->secrets );
		self::assertSame( 1, $review['compatible_count'] );
		self::assertArrayNotHasKey( 'secret', $review['profiles']['constant'] );
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		$this->transition( $review['digest'], true );
		self::assertSame( 1, $this->store->read()['selection_generation'] );
	}

	public function test_native_uncertainty_and_conflicting_candidate_prevent_review(): void {
		$this->candidate();
		$state                     = $this->store->read();
		$marked                    = $state;
		$marked['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'target/target.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $state, $marked ) );
		$this->refused(
			function (): void {
				$this->selection->adoption_review( $this->secrets );
			}
		);
		self::assertTrue( $this->store->compare_and_swap( $marked, $state ) );
		$this->write(
			'other.php',
			'<?php
/*
RAN Booster GitHub Replacement: 1
*/'
		);
		$this->activate( array( 'booster.php', 'candidate.php', 'other.php' ) );
		$this->refused(
			function (): void {
				$this->selection->adoption_review( $this->secrets );
			}
		);
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_different_attempt_connection_is_rejected(): void {
		$this->candidate();
		$review         = $this->selection->adoption_review( $this->secrets );
		$this->attempts = new DeploymentAttemptRepository( new AttemptRepositoryDatabase(), 'wp_attempts', database_lifecycle: $this->createStub( Database::class ) );
		$this->refused( fn () => $this->transition( $review['digest'] ) );
		self::assertSame( 0, $this->store->read()['selection_generation'] );
	}

	public function test_final_capability_and_lock_loss_refuse_cas(): void {
		$this->candidate();
		$review = $this->selection->adoption_review( $this->secrets );
		foreach ( array( 'capability', 'lock' ) as $fault ) {
			$calls = 0;
			$this->refused(
				function () use ( $review, $fault, &$calls ): void {
					$this->selection->transition_selection(
						$review['digest'],
						true,
						false,
						false,
						$this->secrets,
						$this->attempts,
						new WordPressUpdaterLock(),
						function () use ( $fault, &$calls ): bool {
							if ( 2 === ++$calls ) {
								if ( 'capability' === $fault ) {
									return false;
								}
									$this->db->option_rows['auto_updater.lock'] = 'foreign-owner';
							}
							return true;
						}
					);
				}
			);
			self::assertSame( 0, $this->store->read()['selection_generation'] );
		}
	}

	private function transition( string $digest, bool $reuse = false, bool $acknowledge = false ): void {
		$this->selection->transition_selection( $digest, true, $reuse, $acknowledge, $this->secrets, $this->attempts, new WordPressUpdaterLock(), static fn (): bool => true );
	}

	/** @param callable():void $operation */
	private function refused( callable $operation ): void {
		try {
			$operation();
			self::fail( 'Unsafe transition was accepted.' );
		} catch ( \RuntimeException $failure ) {
			self::assertNotSame( '', $failure->getMessage() );
		}
	}

	private function credential( string $kind ): void {
		$this->secrets->save_credential(
			'gh',
			null,
			array(
				'label'         => $kind,
				'kind'          => $kind,
				'configuration' => array( 'owner' => 'classic' === $kind ? '' : 'owner' ),
			),
			str_repeat( 'a', 40 )
		);
	}

	private function candidate(): void {
		$manifest = get_object_vars( $this->declaration( 'candidate.php', array( 'github-classic-pat-v1' ) ) );
		unset( $manifest['implementation_fingerprint'] );
		$this->write( 'candidate.php', "<?php\n/*\nVersion: 1.0\nRAN Booster GitHub Replacement: 1\n*/" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact passive test manifest.
		$this->write( 'candidate.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$this->activate( array( 'booster.php', 'candidate.php' ) );
	}

	/** @param list<string> $formats */
	private function declaration( string $basename, array $formats = GitHubReplacementDeclaration::API_CREDENTIAL_FORMATS ): GitHubReplacementDeclaration {
		return new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => $basename,
				'implementation_id'      => $basename,
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => $formats,
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
	}

	/** @param list<string> $plugins */
	private function activate( array $plugins ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact WordPress active plugin list under test.
		$this->db->selection->active_bytes = serialize( $plugins );
	}

	private function write( string $name, string $bytes ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable passive plugin fixtures, never executed.
		file_put_contents( $this->root . '/' . $name, $bytes );
	}
}

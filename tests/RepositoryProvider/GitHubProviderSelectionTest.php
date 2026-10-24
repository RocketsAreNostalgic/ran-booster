<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\Secrets\GitHubAccessPolicySpy;
use RAN\Tests\Secrets\InMemorySiteKeyStore;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once dirname( __DIR__ ) . '/Admin/AdminViewWordPressFunctions.php';

final class GitHubProviderSelectionTest extends TestCase {
	private string $root;
	private SelectionContextDatabase $db;
	private GitHubSelectionStore $store;
	private GitHubReplacementDeclaration $bundled;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ran-selection-context-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Random test-owned directory.
		mkdir( $this->root, 0700 );
		$this->db      = new SelectionContextDatabase();
		$this->store   = new GitHubSelectionStore( $this->db, 'wp_options' );
		$this->bundled = $this->declaration( 'booster.php' );
		$this->write( 'booster.php', '<?php // ordinary bundled host' );
		$this->activate( array( 'booster.php' ) );
		$this->store->initialize_bundled( $this->bundled );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		$files = glob( $this->root . '/*' );
		foreach ( false === $files ? array() : $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Only random test-local plugin fixtures and encrypted sidecars.
			unlink( $file );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty random test-owned directory.
		rmdir( $this->root );
		InMemorySiteKeyStore::reset( $this->root . '/secrets' );
	}

	private function write( string $name, string $bytes ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-owned passive fixture, never plugin execution.
		file_put_contents( $this->root . '/' . $name, $bytes );
	}

	/** @param array<int, string> $plugins */
	private function activate( array $plugins ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact WordPress option storage bytes under test.
		$this->db->active_bytes = serialize( $plugins );
	}

	private function declaration( string $basename, string $revision = '1' ): GitHubReplacementDeclaration {
		return new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => $basename,
				'implementation_id'      => $basename,
				'compatibility_revision' => $revision,
				'provider_api_version'   => 15,
				'api_credential_formats' => array( 'github-classic-pat-v1' ),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
	}

	private function candidate( string $basename, string $revision = '1', string $version = '1.0' ): GitHubReplacementDeclaration {
		$declaration = $this->declaration( $basename, $revision );
		$manifest    = get_object_vars( $declaration );
		unset( $manifest['implementation_fingerprint'] );
		$this->write( $basename, "<?php\n/*\nVersion: $version\nRAN Booster GitHub Replacement: 1\n*/" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact test manifest bytes.
		$this->write( substr( $basename, 0, -4 ) . '.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		return $declaration;
	}

	private function adopt( GitHubReplacementDeclaration $declaration ): void {
		$old                          = $this->store->read();
		$next                         = $old;
		$next['selection_generation'] = (int) $old['selection_generation'] + 1;
		$next['selected']             = 'replacement';
		$next['declaration']          = get_object_vars( $declaration );
		unset( $next['declaration']['implementation_fingerprint'] );
		$next['approved_api_formats'] = $declaration->api_credential_formats;
		self::assertTrue( $this->store->compare_and_swap( $old, $next ) );
	}

	public function test_status_is_passive_before_candidate_adoption_and_reports_claimed_headers(): void {
		$this->candidate( 'replacement.php' );
		$this->write( 'replacement.php', "<?php throw new \\LogicException( 'Never execute discovery.' );\n/*\nPlugin Name: Claimed provider\nVersion: 2.3\nRAN Booster GitHub Replacement: 1\n*/" );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		$context  = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$factory  = static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $registration_context ): RepositoryProvider {
			unset( $credentials, $evidence, $registration_context );
			self::fail( 'Passive status must not construct a provider.' );
		};
		$registry = new ProviderRegistry(
			github_selection_guard: $context->registry_selection( ... ),
			github_candidates: $context->candidates(),
			bundled_github_factory: $factory
		);
		$context->bind_registry( $registry );
		$registry->declare_github_replacement( $this->declaration( 'replacement.php' ), $factory );
		$context->complete_collection();
		$registry->seal();
		$status = $context->status();
		self::assertSame( 'decision_required', $status['state'] );
		self::assertFalse( $status['available'] );
		self::assertArrayHasKey( 'replacement.php', $status['candidates'] );
		self::assertIsArray( $status['candidates']['replacement.php'] );
		self::assertSame( 'Claimed provider', $status['candidates']['replacement.php']['display_name'] );
		self::assertSame( '2.3', $status['candidates']['replacement.php']['display_version'] );
		self::assertIsArray( $status['current'] );
		self::assertSame( array( 'selection_generation', 'selected', 'declaration', 'approval' ), array_keys( $status['current'] ) );
	}

	public function test_healthy_provider_presentation_never_reviews_credential_adoption(): void {
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'with_github_adoption_inventory' );
		$providers = new ProviderRegistry( array( new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( 'gh' ) ) );
		$usage     = new \RAN\Storage\CredentialUsageReader( new \RAN\Tests\Support\CredentialUsageDatabase(), 'wp_packages' );
		$context   = $this->context();
		$presenter = new \RAN\Admin\ProviderSettingsPresenter( $providers, $secrets, $usage, github_selection: $context );
		self::assertNull( $presenter->build( 'gh' )['github_selection'] );
		$diagnostics = $presenter->github_selection_diagnostics();
		self::assertNotNull( $diagnostics );
		self::assertSame( 'available', $diagnostics['state'] );

		$candidate = $this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		$this->adopt( $candidate );
		$context   = $this->context();
		$presenter = new \RAN\Admin\ProviderSettingsPresenter( $providers, $secrets, $usage, github_selection: $context );
		$selection = $presenter->build( 'gh' )['github_selection'];
		self::assertIsArray( $selection );
		self::assertTrue( $selection['available'] );
		self::assertSame( 'replacement', $selection['status']['current']['selected'] );
		self::assertNull( $selection['review'] );
		self::assertNull( $selection['package_counts'] );
		self::assertNull( $selection['recovery'] );
	}

	public function test_missing_selected_replacement_without_candidates_keeps_return_review_visible(): void {
		$candidate = $this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		$this->adopt( $candidate );
		$this->activate( array( 'booster.php' ) );
		$context = $this->context();
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )->method( 'with_github_adoption_inventory' )->willReturnCallback( static fn ( callable $operation ): mixed => $operation( array() ) );
		$presenter = new \RAN\Admin\ProviderSettingsPresenter(
			new ProviderRegistry(),
			$secrets,
			new \RAN\Storage\CredentialUsageReader( new \RAN\Tests\Support\CredentialUsageDatabase(), 'wp_packages' ),
			github_selection: $context
		);
		$data      = $presenter->build( 'gh' );
		self::assertSame( 'gh', $data['selected_provider'] );
		self::assertFalse( $data['github_selection']['available'] );
		self::assertSame( 'missing', $data['github_selection']['status']['state'] );
		self::assertSame( array(), $data['github_selection']['status']['candidates'] );
		self::assertSame( '', $data['github_selection']['error'] );
		self::assertSame( 'bundled', $data['github_selection']['review']['selected'] );
		self::assertSame( 'replacement', $this->store->read()['selected'] );
	}

	public function test_status_refuses_conflict_malformed_candidate_and_missing_selected_plugin(): void {
		$context = $this->context();
		self::assertTrue( $context->status()['available'] );
		$this->candidate( 'one.php' );
		$this->candidate( 'two.php' );
		$this->activate( array( 'booster.php', 'one.php', 'two.php' ) );
		self::assertSame( 'conflict', $context->status()['state'] );
		$this->activate( array( 'booster.php', 'missing.php' ) );
		self::assertSame( 'incompatible', $context->status()['state'] );
		self::assertNull( $context->status()['candidates']['missing.php'] );
		$this->activate( array() );
		self::assertSame( 'missing', $context->status()['state'] );
		$this->db->fail_active = true;
		self::assertSame(
			array(
				'state'      => 'storage_unavailable',
				'available'  => false,
				'current'    => null,
				'candidates' => array(),
			),
			$context->status()
		);
	}

	public function test_status_detects_fingerprint_drift_and_redacts_native_owner(): void {
		$candidate = $this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		$this->adopt( $candidate );
		$context = $this->context();
		$owner   = $context->begin_native_mutation( 'plugin', 'target/main.php', static fn (): bool => true );
		$status  = $context->status();
		self::assertSame( 'recovery_required', $status['state'] );
		self::assertTrue( $status['available'] );
		self::assertIsArray( $status['current'] );
		self::assertArrayNotHasKey( 'native_mutation', $status['current'] );
		$context->complete_native_mutation( $owner, 'plugin', 'target/main.php', 'verified_success' );
		$this->candidate( 'replacement.php', '2' );
		self::assertSame( 'decision_required', $context->status()['state'] );
		self::assertFalse( $context->status()['available'] );
	}

	private function context(): GitHubProviderSelection {
		$context  = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$registry = $this->registry( $context );
		$context->complete_collection();
		$registry->seal();
		return $context;
	}

	private function registry( GitHubProviderSelection $context, bool $declare_candidates = true ): ProviderRegistry {
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
		$credentials = $this->createMock( ProviderCredentialStore::class );
		$evidence    = $this->createMock( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$factory     = static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $registration_context ) use ( $provider ): RepositoryProvider {
			unset( $credentials, $evidence );
			self::assertTrue( $registration_context->operation_allowed() );
			return $provider;
		};
		$registry    = new ProviderRegistry(
			credential_store_factory: static fn (): ProviderCredentialStore => $credentials,
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
			github_selection_guard: array( $context, 'registry_selection' ),
			github_candidates: $context->candidates(),
			bundled_github_factory: $factory
		);
		$context->bind_registry( $registry );
		if ( $declare_candidates ) {
			foreach ( $context->candidates() as $candidate ) {
				if ( null !== $candidate ) {
					$registry->declare_github_replacement( $candidate, $factory );
				}
			}
		}
		return $registry;
	}

	public function test_collection_and_authoritative_active_set_fence_captured_requests(): void {
		$context = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		self::assertNull( $context->expectation() );
		$registry = $this->registry( $context );
		$context->complete_collection();
		self::assertFalse( $context->operation_allowed() );
		$registry->seal();
		self::assertTrue( $context->operation_allowed() );
		self::assertSame( array( 'provider_code', 'selection_generation', 'implementation_fingerprint' ), array_keys( $context->expectation() ?? array() ) );
		$this->write( 'unrelated.php', '<?php' );
		$this->activate( array( 'booster.php', 'unrelated.php' ) );
		self::assertFalse( $context->operation_allowed() );
		self::assertTrue( $this->context()->operation_allowed() );
		self::assertGreaterThan( 5, $this->db->active_reads );
	}

	public function test_candidate_states_never_fall_back_and_version_only_update_remains_approved(): void {
		$replacement = $this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		self::assertFalse( $this->context()->operation_allowed() );
		$this->adopt( $replacement );
		$context = $this->context();
		self::assertTrue( $context->operation_allowed() );
		$this->candidate( 'replacement.php', '1', '2.0' );
		self::assertTrue( $context->operation_allowed() );
		$this->candidate( 'replacement.php', '2' );
		self::assertFalse( $context->operation_allowed() );
		self::assertFalse( $this->context()->operation_allowed() );
		$this->candidate( 'replacement.php' );
		$this->candidate( 'second.php' );
		$this->activate( array( 'booster.php', 'replacement.php', 'second.php' ) );
		self::assertFalse( $this->context()->operation_allowed() );
		$this->activate( array( 'booster.php' ) );
		self::assertFalse( $this->context()->operation_allowed() );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		self::assertTrue( $this->context()->operation_allowed() );
		$this->write( 'replacement.php', '<?php /* RAN Booster GitHub Replacement: 2 */' );
		self::assertFalse( $this->context()->operation_allowed() );
	}

	public function test_missing_files_bad_manifest_and_new_header_without_active_option_change_refuse(): void {
		$this->write( 'ordinary.php', '<?php' );
		$this->activate( array( 'booster.php', 'ordinary.php' ) );
		$context = $this->context();
		self::assertTrue( $context->operation_allowed() );
		$this->candidate( 'ordinary.php' );
		self::assertFalse( $context->operation_allowed() );
		$this->adopt( $this->declaration( 'ordinary.php' ) );
		$context = $this->context();
		self::assertTrue( $context->operation_allowed() );
		$this->write( 'ordinary.gh-replacement.json', '{}' );
		self::assertFalse( $context->operation_allowed() );
		$this->candidate( 'ordinary.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test transient upgrade absence of exact local fixture.
		unlink( $this->root . '/ordinary.php' );
		self::assertFalse( $context->operation_allowed() );
		$this->candidate( 'ordinary.php' );
		self::assertTrue( $this->context()->operation_allowed() );
	}

	public function test_native_deactivation_gaps_preserve_authoritative_membership(): void {
		$this->write( 'ordinary.php', '<?php' );
		foreach ( array( array( 0 ), array( 2 ), array( 0, 2 ) ) as $removed ) {
			$plugins = array( 'first.php', 'booster.php', 'middle.php', 'ordinary.php' );
			foreach ( $removed as $key ) {
				// WordPress deactivate_plugins() persists this array without reindexing.
				unset( $plugins[ $key ] );
			}
			$this->activate( $plugins );
			$bytes    = $this->db->active_bytes;
			$expected = array_values( $plugins );
			sort( $expected, SORT_STRING );
			self::assertSame( $expected, $this->store->active_plugins() );
			self::assertSame( $bytes, $this->db->active_bytes );
		}
		foreach ( array(
			array( 1 => 'booster.php' ),
			array( PHP_INT_MAX => 'booster.php' ),
			array(
				9 => 'ordinary.php',
				2 => 'booster.php',
			),
		) as $plugins ) {
			$this->activate( $plugins );
			$bytes = $this->db->active_bytes;
			self::assertTrue( $this->context()->operation_allowed() );
			self::assertSame( $bytes, $this->db->active_bytes );
		}
	}

	public function test_strict_active_bytes_and_storage_errors_refuse_without_unserialization(): void {
		foreach ( array(
			'a:1:{i:0;s:11:"booster.php";}trailing',
			'O:8:"stdClass":0:{}',
			'a:1:{i:-1;s:11:"booster.php";}',
			'a:1:{i:01;s:11:"booster.php";}',
			'a:1:{i:+1;s:11:"booster.php";}',
			'a:1:{s:1:"1";s:11:"booster.php";}',
			'a:1:{i:' . PHP_INT_MAX . '0;s:11:"booster.php";}',
			'a:1:{i:9223372036854775808;s:11:"booster.php";}',
			'a:2:{i:2;s:11:"booster.php";i:2;s:12:"ordinary.php";}',
			'a:2:{i:0;s:11:"booster.php";i:1;s:11:"booster.php";}',
			'a:1:{i:0;s:9:"../a.php";}',
			'a:1025:{}',
		) as $bad ) {
			$this->db->active_bytes = $bad;
			try {
				$this->store->active_plugins();
				self::fail( 'Invalid active list accepted.' );
			} catch ( DatabaseLifecycleFailure ) {
				self::assertFalse( $this->context()->operation_allowed() );
			}
		}
		$this->activate( array( 'booster.php' ) );
		$this->db->fail_active = true;
		self::assertFalse( $this->context()->operation_allowed() );
		$this->db->fail_active     = false;
		$this->db->selection->rows = array();
		self::assertFalse( $this->context()->operation_allowed() );
	}

	public function test_new_request_switch_changes_expectation_and_old_registry_never_constructs(): void {
		$a = $this->candidate( 'a.php' );
		$this->activate( array( 'booster.php', 'a.php' ) );
		$this->adopt( $a );
		$preview       = $this->context();
		$expected      = $preview->expectation();
		$factory_calls = 0;
		$stale         = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$stale->complete_collection();
		$registry = new ProviderRegistry(
			github_selection_guard: array( $stale, 'registry_selection' ),
			github_candidates: $stale->candidates()
		);
		$stale->bind_registry( $registry );
		$registry->declare_github_replacement(
			$a,
			static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ) use ( &$factory_calls ): RepositoryProvider {
				unset( $credentials, $evidence, $context );
				++$factory_calls;
				throw new \LogicException( 'No stale factory.' );
			}
		);
		$b = $this->candidate( 'b.php' );
		$this->activate( array( 'booster.php', 'b.php' ) );
		$this->adopt( $b );
		$apply = $this->context();
		self::assertNotSame( $expected, $apply->expectation() );
		self::assertSame( 'provider_selection_changed', $apply->review_refusal( $expected ) );
		self::assertNull( $apply->review_refusal( $apply->expectation() ) );
		self::assertSame( 'provider_unavailable', $preview->review_refusal( $expected ) );
		self::assertNull( $preview->expectation() );
		$registry->seal();
		self::assertSame( 0, $factory_calls );
		self::assertSame( array(), $registry->all() );
	}

	public function test_real_registry_context_and_encrypted_credentials_refuse_live_drift(): void {
		$selection = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$selection->complete_collection();
		$policy  = new GitHubAccessPolicySpy();
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), $policy, null );
		$secrets = new SecretsFile(
			$this->root . '/secrets',
			array(),
			$catalog,
			new InMemorySiteKeyStore( $this->root . '/secrets' ),
			github_credential_access: array( $selection, 'credential_access_allowed' )
		);
		$seed    = new SecretsFile( $this->root . '/secrets', array(), $catalog, new InMemorySiteKeyStore( $this->root . '/secrets' ) );
		$seed->save_credential(
			'gh',
			'classic',
			array(
				'label'         => 'Fixture',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'fixture-only-secret'
		);
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
		$evidence     = $this->createMock( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$captured     = null;
		$native_calls = array();
		$host_context = new ProviderRegistrationContext(
			static fn (): int => 1,
			native_mutation_begin: static function ( string $type, string $identity ) use ( &$native_calls ): string {
				$native_calls[] = array( $type, $identity );
				return 'bundled-owner'; },
			native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$native_calls ): void {
				$native_calls[] = array( $owner, $type, $identity, $outcome ); }
		);
		$registry     = new ProviderRegistry(
			credential_store_factory: static fn (): ProviderCredentialStore => $secrets->credentials_for( 'gh' ),
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
			registration_context: $host_context,
			github_selection_guard: array( $selection, 'registry_selection' ),
			github_candidates: $selection->candidates(),
			bundled_github_factory: static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ) use ( $provider, &$captured ): RepositoryProvider {
				unset( $evidence );
				$captured = $context;
				self::assertNotNull( $credentials->credential_material( 'classic' ) );
				return $provider;
			}
		);
		$selection->bind_registry( $registry );
		$registry->seal();
		self::assertSame( $provider, $registry->get( 'gh' ) );
		self::assertInstanceOf( ProviderRegistrationContext::class, $captured );
		self::assertTrue( $captured->operation_allowed() );
		self::assertSame( 'bundled-owner', $captured->begin_native_mutation( 'plugin', 'fixture/main.php' ) );
		self::assertFalse(
			$selection->credential_access_allowed(
				array(
					'kind'          => 'fine-grained',
					'configuration' => array( 'owner' => 'owner' ),
				)
			)
		);
		$this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		self::assertFalse( $captured->operation_allowed() );
		$captured->complete_native_mutation( 'bundled-owner', 'plugin', 'fixture/main.php', 'uncertain' );
		self::assertSame( array( array( 'plugin', 'fixture/main.php' ), array( 'bundled-owner', 'plugin', 'fixture/main.php', 'uncertain' ) ), $native_calls );
		$policy->normalizations = 0;
		self::assertNull( $secrets->credentials_for( 'gh' )->credential_material( 'classic' ) );
		self::assertSame( array(), $secrets->credentials_for( 'gh' )->credential_profiles() );
		self::assertSame( 0, $policy->normalizations );
	}

	public function test_only_exact_normalized_credential_formats_are_recognized(): void {
		self::assertSame(
			'github-classic-pat-v1',
			GitHubProviderSelection::credential_format(
				array(
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				)
			)
		);
		self::assertSame(
			'github-fine-grained-pat-v1',
			GitHubProviderSelection::credential_format(
				array(
					'kind'          => 'fine-grained',
					'configuration' => array( 'owner' => 'Owner_1' ),
				)
			)
		);
		foreach ( array(
			null,
			array(
				'kind'          => 'classic',
				'configuration' => array(),
			),
			array(
				'kind'          => 'classic',
				'configuration' => array( 'owner' => 'other' ),
			),
			array(
				'kind'          => 'fine-grained',
				'configuration' => array( 'owner' => ' owner' ),
			),
			array(
				'kind'          => 'fine-grained',
				'configuration' => array( 'owner' => "owner\n" ),
			),
			array(
				'kind'          => 'classic',
				'configuration' => array(
					'owner' => '',
					'extra' => 'ignored',
				),
			),
			array(
				'kind'          => 'unknown',
				'configuration' => array( 'owner' => '' ),
			),
		) as $metadata ) {
			self::assertNull( GitHubProviderSelection::credential_format( $metadata ) );
		}
	}

	public function test_authority_change_during_passive_collection_cannot_capture_mixed_snapshot(): void {
		$this->db->on_active_read = function (): void {
			$this->db->on_active_read     = null;
			$old                          = $this->store->read();
			$next                         = $old;
			$next['selection_generation'] = 1;
			self::assertTrue( $this->store->compare_and_swap( $old, $next ) );
		};
		self::assertFalse( $this->context()->operation_allowed() );
		self::assertTrue( $this->context()->operation_allowed() );
	}
	public function test_review_rejects_absent_malformed_and_extra_authority_fields(): void {
		$context     = $this->context();
		$expectation = $context->expectation();
		self::assertNotNull( $expectation );
		foreach ( array( null, array(), array_replace( $expectation, array( 'provider_code' => 'bb' ) ), array_replace( $expectation, array( 'selection_generation' => '0' ) ), array_replace( $expectation, array( 'implementation_fingerprint' => str_repeat( 'A', 64 ) ) ), $expectation + array( 'plugin_basename' => 'booster.php' ) ) as $bad ) {
			try {
				$context->review_refusal( $bad );
				self::fail( 'Malformed review granted authority.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( $context->operation_allowed() );
			}
		}
		self::assertNull( $context->review_refusal( array_reverse( $expectation, true ) ) );
		self::assertSame( $expectation, GitHubProviderSelection::normalize_expectation( array_reverse( $expectation, true ) ) );
	}

	public function test_native_marker_is_separate_from_generic_read_authority(): void {
		$context                 = $this->context();
		$old                     = $this->store->read();
		$next                    = $old;
		$next['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'target.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $old, $next ) );
		self::assertTrue( $context->operation_allowed() );
		$fresh = $this->context();
		self::assertTrue( $fresh->operation_allowed() );
		self::assertSame( $next['native_mutation'], $this->store->read()['native_mutation'] );
		$revoked                         = $next;
		$revoked['selection_generation'] = 1;
		$revoked['approval']             = 'revoked';
		self::assertTrue( $this->store->compare_and_swap( $next, $revoked ) );
		self::assertFalse( $fresh->operation_allowed() );
		self::assertFalse( $this->context()->operation_allowed() );
	}
	public function test_missing_duplicate_and_failed_factories_never_publish_availability(): void {
		$declaration = $this->candidate( 'replacement.php' );
		$this->activate( array( 'booster.php', 'replacement.php' ) );
		$this->adopt( $declaration );
		foreach ( array( 'missing', 'duplicate', 'throw' ) as $case ) {
			$context  = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
			$registry = $this->registry( $context, 'duplicate' === $case );
			$context->complete_collection();
			self::assertFalse( $context->operation_allowed() );
			if ( 'missing' !== $case ) {
				$registry->declare_github_replacement(
					$declaration,
					static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $registration_context ): RepositoryProvider {
						unset( $credentials, $evidence );
						self::assertTrue( $registration_context->operation_allowed() );
						throw new \RuntimeException( 'Failed fixture factory.' );
					}
				);
			}
			$registry->seal();
			self::assertSame( array(), $registry->all() );
			self::assertFalse( $context->operation_allowed() );
			self::assertFalse( $context->credential_access_allowed( null ) );
			self::assertNull( $context->registry_selection() );
			self::assertNull( $context->expectation() );
		}
	}

	public function test_native_begin_blocks_second_target_and_completion_survives_guard_loss(): void {
		$context = $this->context();
		$owner   = $context->begin_native_mutation( 'plugin', 'first/main.php', static fn (): bool => true );
		$marker  = $this->store->read()['native_mutation'];
		self::assertSame( $owner, $marker['owner_tag'] );
		self::assertTrue( $context->operation_allowed() );
		foreach ( array( $context, $this->context() ) as $request ) {
			try {
				$request->begin_native_mutation( 'theme', 'second', static fn (): bool => true );
				self::fail( 'Unresolved first target was overwritten.' );
			} catch ( \RuntimeException ) {
				self::assertSame( $marker, $this->store->read()['native_mutation'] );
			}
		}
		$this->activate( array() );
		self::assertFalse( $context->operation_allowed() );
		$context->complete_native_mutation( $owner, 'plugin', 'first/main.php', 'verified_success' );
		self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
	}

	public function test_native_mutation_marker_blocks_only_its_installed_target(): void {
		$state                    = $this->store->read();
		$state['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'target/main.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $this->store->read(), $state ) );

		$context = $this->context();
		self::assertTrue( $context->native_mutation_blocks_target( 'plugin', 'target/alternate.php' ) );
		self::assertFalse( $context->native_mutation_blocks_target( 'plugin', 'other/main.php' ) );
		self::assertFalse( $context->native_mutation_blocks_target( 'theme', 'target' ) );

		$without_marker = $this->store->read();
		unset( $without_marker['native_mutation'] );
		self::assertTrue( $this->store->compare_and_swap( $this->store->read(), $without_marker ) );
		$theme_marker                    = $this->store->read();
		$theme_marker['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'b', 32 ),
			'target_type'          => 'theme',
			'installed_identity'   => 'target',
			'phase'                => 'started',
		);
		self::assertTrue( $this->store->compare_and_swap( $this->store->read(), $theme_marker ) );
		self::assertTrue( $context->native_mutation_blocks_target( 'theme', 'target' ) );
		self::assertFalse( $context->native_mutation_blocks_target( 'theme', 'other' ) );
		self::assertFalse( $context->native_mutation_blocks_target( 'plugin', 'target/main.php' ) );

		$revoked                         = $this->store->read();
		$revoked['selection_generation'] = 1;
		$revoked['approval']             = 'revoked';
		self::assertTrue( $this->store->compare_and_swap( $this->store->read(), $revoked ) );
		self::assertTrue( $context->native_mutation_blocks_target( 'theme', 'target' ) );
	}

	public function test_native_mutation_marker_read_failure_propagates(): void {
		$this->db->selection->rows[0]->option_value = '{}';
		$this->expectException( DatabaseLifecycleFailure::class );
		( new GitHubProviderSelection( $this->store, $this->root, $this->bundled ) )->native_mutation_blocks_target( 'plugin', 'target/main.php' );
	}

	public function test_native_completion_preserves_revocation_and_requires_captured_owner(): void {
		$context                         = $this->context();
		$owner                           = $context->begin_native_mutation( 'plugin', 'first.php', static fn (): bool => true );
		$state                           = $this->store->read();
		$revoked                         = $state;
		$revoked['selection_generation'] = 1;
		$revoked['approval']             = 'revoked';
		self::assertTrue( $this->store->compare_and_swap( $state, $revoked ) );
		foreach ( array( array( $context, $owner, 'other.php' ), array( $this->context(), $owner, 'first.php' ), array( $context, 'wrong', 'first.php' ) ) as [ $request, $tag, $identity ] ) {
			try {
				$request->complete_native_mutation( $tag, 'plugin', $identity, 'verified_success' );
				self::fail( 'Uncaptured completion accepted.' );
			} catch ( \RuntimeException ) {
				self::assertSame( $revoked, $this->store->read() );
			}
		}
		$context->complete_native_mutation( $owner, 'plugin', 'first.php', 'verified_success' );
		unset( $revoked['native_mutation'] );
		self::assertSame( $revoked, $this->store->read() );
	}

	public function test_uncertain_native_outcome_is_not_later_promoted_to_success(): void {
		$context = $this->context();
		$owner   = $context->begin_native_mutation( 'theme', 'first', static fn (): bool => true );
		$state   = $this->store->read();
		$context->complete_native_mutation( $owner, 'theme', 'first', 'uncertain' );
		self::assertSame( $state, $this->store->read() );
		$this->expectException( \RuntimeException::class );
		$context->complete_native_mutation( $owner, 'theme', 'first', 'verified_success' );
	}

	public function test_native_begin_requires_live_selection_lock_and_valid_target(): void {
		$context = $this->context();
		foreach ( array( array( 'plugin', 'first.php', false ), array( 'other', 'first.php', true ), array( 'theme', '', true ) ) as [ $type, $identity, $locked ] ) {
			try {
				$context->begin_native_mutation( $type, $identity, static fn (): bool => $locked );
				self::fail( 'Invalid native begin accepted.' );
			} catch ( \RuntimeException | \InvalidArgumentException ) {
				self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
			}
		}
		$this->activate( array() );
		$this->expectException( \RuntimeException::class );
		$context->begin_native_mutation( 'theme', 'first', static fn (): bool => true );
	}

	public function test_unknown_native_begin_commit_retains_marker_and_grants_no_completion(): void {
		$context                             = $this->context();
		$this->db->selection->fail_commit    = true;
		$this->db->selection->commit_applied = true;
		try {
			$context->begin_native_mutation( 'theme', 'first', static fn (): bool => true );
			self::fail( 'Unknown commit admitted mutation.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_commit_uncertain', $failure->reason() );
		}
		$state = $this->store->read();
		self::assertIsArray( $state['native_mutation'] );
		$this->expectException( \RuntimeException::class );
		$context->complete_native_mutation( (string) $state['native_mutation']['owner_tag'], 'theme', 'first', 'verified_success' );
	}

	public function test_native_transaction_cannot_commit_an_outer_transaction(): void {
		$this->db->query( 'START TRANSACTION' );
		$before = $this->store->read();
		try {
			$this->context()->begin_native_mutation( 'theme', 'first', static fn (): bool => true );
			self::fail( 'Nested transaction accepted.' );
		} catch ( DatabaseLifecycleFailure ) {
			self::assertNotNull( $this->db->selection->transaction_rows );
			self::assertSame( $before, $this->store->read() );
			self::assertNotContains( 'COMMIT', $this->db->selection->queries );
		}
		$this->db->query( 'ROLLBACK' );
	}

	public function test_unknown_completion_commit_retains_marker_when_not_committed_and_pauses_request(): void {
		$context                          = $this->context();
		$owner                            = $context->begin_native_mutation( 'theme', 'first', static fn (): bool => true );
		$before                           = $this->store->read();
		$this->db->selection->fail_commit = true;
		try {
			$context->complete_native_mutation( $owner, 'theme', 'first', 'verified_success' );
			self::fail( 'Unknown commit reported completion.' );
		} catch ( DatabaseLifecycleFailure ) {
			self::assertSame( $before, $this->store->read() );
		}
		$this->db->selection->fail_commit = false;
		$this->expectException( DatabaseLifecycleFailure::class );
		$context->begin_native_mutation( 'theme', 'second', static fn (): bool => true );
	}

	public function test_lock_check_cannot_change_active_selection_then_admit_old_work(): void {
		$context = $this->context();
		try {
			$context->begin_native_mutation(
				'theme',
				'first',
				function (): bool {
					$this->activate( array() );
					return true;
				}
			);
			self::fail( 'Changed selection admitted after lock check.' );
		} catch ( \RuntimeException ) {
			self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
		}
	}

	public function test_host_binding_cannot_replace_registry_or_bind_already_sealed_registry(): void {
		$context  = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$registry = $this->registry( $context );
		try {
			$context->bind_registry( $registry );
			self::fail( 'Rebinding accepted.' );
		} catch ( \LogicException ) {
			self::assertFalse( $context->operation_allowed() );
		}
		$registry->seal();
		$other = new GitHubProviderSelection( $this->store, $this->root, $this->bundled );
		$this->expectException( \LogicException::class );
		$other->bind_registry( $registry );
	}
	public function test_native_begin_rechecks_exact_lock_before_cas(): void {
		$checks = 0;
		try {
			$this->context()->begin_native_mutation(
				'theme',
				'first',
				static function () use ( &$checks ): bool {
					return 1 === ++$checks;
				}
			);
			self::fail( 'Lost lock admitted mutation.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 2, $checks );
			self::assertArrayNotHasKey( 'native_mutation', $this->store->read() );
		}
	}
}

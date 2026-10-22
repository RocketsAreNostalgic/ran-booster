<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

final class GitHubReplacementRegistrationTest extends TestCase {
	private bool $collection_complete = false;
	/** @return array<string, mixed> */
	private function manifest(): array {
		return array(
			'plugin_basename'        => 'replacement/main.php',
			'implementation_id'      => 'replacement',
			'compatibility_revision' => '1',
			'provider_api_version'   => 15,
			'api_credential_formats' => array( 'github-classic-pat-v1', 'github-fine-grained-pat-v1' ),
			'webhook_signing_format' => null,
			'facets'                 => array(),
		);
	}

	public function test_contract_sets_are_order_independent_but_semantic_changes_are_not(): void {
		$manifest                           = $this->manifest();
		$first                              = new GitHubReplacementDeclaration( $manifest );
		$manifest['api_credential_formats'] = array( 'github-fine-grained-pat-v1', 'github-classic-pat-v1' );
		self::assertSame( $first->implementation_fingerprint, ( new GitHubReplacementDeclaration( $manifest ) )->implementation_fingerprint );
		$manifest['compatibility_revision'] = '2';
		self::assertNotSame( $first->implementation_fingerprint, ( new GitHubReplacementDeclaration( $manifest ) )->implementation_fingerprint );
	}

	public function test_closed_manifest_refuses_unknown_fields_formats_facets_and_api(): void {
		foreach ( array(
			array( 'extra' => 'ignored' ),
			array( 'provider_api_version' => 14 ),
			array( 'api_credential_formats' => array( 'token' ) ),
			array( 'api_credential_formats' => array( 'github-classic-pat-v1', 'github-classic-pat-v1' ) ),
			array( 'facets' => array( RepositoryProvider::class ) ),
			array( 'plugin_basename' => '../other.php' ),
			array( 'webhook_signing_format' => 'arbitrary' ),
		) as $change ) {
			try {
				new GitHubReplacementDeclaration( array_replace( $this->manifest(), $change ) );
				self::fail( 'Invalid declaration accepted.' );
			} catch ( \InvalidArgumentException $failure ) {
				self::assertNotSame( '', $failure->getMessage() );
			}
		}
	}

	public function test_reserved_code_refuses_legacy_factory_before_credential_disclosure(): void {
		$reads    = 0;
		$registry = new ProviderRegistry(
			credential_store_factory: static function () use ( &$reads ): never {
				++$reads;
				throw new \LogicException( 'Must not resolve credentials.' );
			},
			github_selection_guard: static fn (): ?array => null
		);
		try {
			$registry->register_with_credential_store( 'gh', static fn (): never => throw new \LogicException( 'Must not execute.' ) );
			self::fail( 'Reserved code accepted.' );
		} catch ( \LogicException $failure ) {
			self::assertSame( 'GitHub is reserved for host selection.', $failure->getMessage() );
		}
		self::assertSame( 0, $reads );
	}

	public function test_factory_is_deferred_and_bound_context_refuses_generation_drift(): void {
		$declaration               = new GitHubReplacementDeclaration( $this->manifest() );
		$selection                 = array(
			'provider_code'              => 'gh',
			'selection_generation'       => 2,
			'implementation_fingerprint' => $declaration->implementation_fingerprint,
			'plugin_basename'            => $declaration->plugin_basename,
		);
		$this->collection_complete = false;
		$invocations               = 0;
		$observed                  = null;
		$provider                  = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
		$credentials  = $this->createMock( ProviderCredentialStore::class );
		$evidence     = $this->createMock( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$native_calls = array();
		$context      = new ProviderRegistrationContext(
			static fn (): int => 1,
			native_mutation_begin: static function ( string $type, string $identity ) use ( &$native_calls ): string {
				$native_calls[] = array( $type, $identity );
				return 'exact-owner'; },
			native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$native_calls ): void {
				$native_calls[] = array( $owner, $type, $identity, $outcome ); }
		);
		$registry     = new ProviderRegistry(
			credential_store_factory: static fn (): ProviderCredentialStore => $credentials,
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
			registration_context: $context,
			github_selection_guard: function () use ( &$selection ): ?array {
				return $this->collection_complete ? $selection : null; },
			github_candidates: array( $declaration->plugin_basename => $declaration )
		);
		$registry->declare_github_replacement(
			$declaration,
			static function (
				ProviderCredentialStore $store,
				AuthenticatedWebhookDeliveryEvidenceReader $reader,
				ProviderRegistrationContext $context
			) use (
				&$invocations,
				&$observed,
				$provider
			): RepositoryProvider {
				unset( $store, $reader );
				++$invocations;
				$observed = $context;
				return $provider;
			}
		);
		self::assertSame( 0, $invocations );
		self::assertSame( array(), $native_calls );
		$this->collection_complete = true;
		$registry->seal();
		self::assertSame( 1, $invocations );
		self::assertSame( $provider, $registry->get( 'gh' ) );
		self::assertInstanceOf( ProviderRegistrationContext::class, $observed );
		self::assertTrue( $observed->operation_allowed() );
		self::assertSame( 'exact-owner', $observed->begin_native_mutation( 'theme', 'fixture' ) );
		++$selection['selection_generation'];
		self::assertFalse( $observed->operation_allowed() );
		try {
			$observed->begin_native_mutation( 'theme', 'fixture' );
			self::fail( 'Stale selection reached native host admission.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Native provider mutation coordination is unavailable.', $failure->getMessage() );
		}
		$observed->complete_native_mutation( 'exact-owner', 'theme', 'fixture', 'uncertain' );
		self::assertSame( array( array( 'theme', 'fixture' ), array( 'exact-owner', 'theme', 'fixture', 'uncertain' ) ), $native_calls );
		$registry->seal();
		self::assertSame( 1, $invocations );
	}

	public function test_invalid_missing_duplicate_and_conflicting_candidates_never_construct(): void {
		$declaration = new GitHubReplacementDeclaration( $this->manifest() );
		foreach ( array( 'missing-callback', 'duplicate', 'signature', 'invalid', 'conflict', 'early-seal', 'drift' ) as $case ) {
			$reads      = 0;
			$calls      = 0;
			$candidates = array( $declaration->plugin_basename => 'invalid' === $case ? null : $declaration );
			if ( 'conflict' === $case ) {
				$candidates['other/main.php'] = null;
			}
			$registry = new ProviderRegistry(
				credential_store_factory: static function () use ( &$reads ): never {
					++$reads;
					throw new \LogicException( 'Credential disclosure.' ); },
				github_selection_guard: static function () use ( $declaration, $case, &$calls ): ?array {
					++$calls;
					return 'early-seal' === $case || ( 'drift' === $case && $calls > 1 ) ? null : array(
						'provider_code'              => 'gh',
						'selection_generation'       => 1,
						'implementation_fingerprint' => $declaration->implementation_fingerprint,
						'plugin_basename'            => $declaration->plugin_basename,
					);
				},
				github_candidates: $candidates
			);
			$factory  = static function ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $reader, ProviderRegistrationContext $context ): never {
				unset( $store, $reader, $context );
				throw new \LogicException( 'Must not execute.' );
			};
			if ( 'missing-callback' !== $case ) {
				$registry->declare_github_replacement( $declaration, 'signature' === $case ? static fn (): bool => true : $factory );
			}
			if ( 'duplicate' === $case ) {
				$registry->declare_github_replacement( $declaration, $factory );
			}
			$registry->seal();
			self::assertSame( 0, $reads, $case );
			self::assertSame( array(), $registry->all(), $case );
		}
	}
	public function test_passive_header_and_manifest_are_read_without_executing_plugin_php(): void {
		$root = sys_get_temp_dir() . '/ran-github-passive-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated local passive-discovery fixture.
		self::assertTrue( mkdir( $root, 0700 ) );
		$main                        = $root . '/main.php';
		$path                        = $root . '/main.gh-replacement.json';
		$manifest                    = $this->manifest();
		$manifest['plugin_basename'] = 'main.php';
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only this test-owned passive fixture, which must never execute.
			file_put_contents( $main, "<?php\n/* RAN Booster GitHub Replacement: 1 */\nthrow new Exception('Must never execute');" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write this test-owned adjacent manifest.
			file_put_contents( $path, $this->encode_manifest( $manifest ) );
			self::assertSame( ( new GitHubReplacementDeclaration( $manifest ) )->implementation_fingerprint, GitHubReplacementDeclaration::from_plugin_file( $root, 'main.php' )?->implementation_fingerprint );

			foreach ( array( '{}', '{', str_repeat( ' ', 16385 ), $this->encode_manifest( array_replace( $manifest, array( 'plugin_basename' => 'different.php' ) ) ) ) as $invalid ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Negative passive manifest fixture, never executable code.
				file_put_contents( $path, $invalid );
				try {
					GitHubReplacementDeclaration::from_plugin_file( $root, 'main.php' );
					self::fail( 'Malformed passive manifest accepted.' );
				} catch ( \InvalidArgumentException | \JsonException $failure ) {
					self::assertNotSame( '', $failure->getMessage() );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore valid manifest so the header is the sole negative control.
			file_put_contents( $path, $this->encode_manifest( $manifest ) );
			foreach ( array( '', '0', '2', 'invalid' ) as $header ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Change only this isolated plugin-header fixture.
				file_put_contents( $main, "<?php\n/* RAN Booster GitHub Replacement: " . $header . ' */' );
				try {
					GitHubReplacementDeclaration::from_plugin_file( $root, 'main.php' );
					self::fail( 'An unsupported present header must remain an invalid candidate.' );
				} catch ( \InvalidArgumentException $failure ) {
					self::assertNotSame( '', $failure->getMessage() );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ordinary plugin fixture has no replacement marker.
			file_put_contents( $main, '<?php /* Plugin Name: Ordinary */' );
			self::assertNull( GitHubReplacementDeclaration::from_plugin_file( $root, 'main.php' ) );
		} finally {
			foreach ( array( $main, $path ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned local fixture.
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this isolated fixture directory.
			rmdir( $root );
		}
	}
	/** @param array<string, mixed> $manifest */
	private function encode_manifest( array $manifest ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode exact passive JSON fixture bytes without WordPress runtime.
		return json_encode( $manifest, JSON_THROW_ON_ERROR );
	}
	public function test_selection_drift_during_store_or_factory_creation_prevents_publication(): void {
		$declaration = new GitHubReplacementDeclaration( $this->manifest() );
		foreach ( array( 'store', 'factory' ) as $phase ) {
			$selection = array(
				'provider_code'              => 'gh',
				'selection_generation'       => 1,
				'implementation_fingerprint' => $declaration->implementation_fingerprint,
				'plugin_basename'            => $declaration->plugin_basename,
			);
			$store     = $this->createMock( ProviderCredentialStore::class );
			$store->expects( self::never() )->method( 'credential_material' );
			$reader   = $this->createMock( AuthenticatedWebhookDeliveryEvidenceReader::class );
			$provider = $this->createMock( RepositoryProvider::class );
			$provider->expects( self::never() )->method( 'get_metadata' );
			$calls    = 0;
			$registry = new ProviderRegistry(
				credential_store_factory: static function () use ( &$selection, $phase, $store ): ProviderCredentialStore {
					if ( 'store' === $phase ) {
						++$selection['selection_generation']; }
					return $store;
				},
				delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $reader,
				github_selection_guard: static function () use ( &$selection ): array {
					return $selection; },
				github_candidates: array( $declaration->plugin_basename => $declaration )
			);
			$registry->declare_github_replacement(
				$declaration,
				static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ) use ( &$selection, &$calls, $provider ): RepositoryProvider {
					unset( $credentials, $evidence, $context );
					++$calls;
					++$selection['selection_generation'];
					return $provider;
				}
			);
			$registry->seal();
			self::assertSame( 'store' === $phase ? 0 : 1, $calls );
			self::assertSame( array(), $registry->all() );
		}
	}
	public function test_validation_callback_drift_publishes_neither_provider_nor_policy(): void {
		foreach ( array( 'metadata', 'diagnostics', 'policy', 'policy_identity' ) as $phase ) {
			$manifest           = $this->manifest();
			$manifest['facets'] = array( \RAN\RepositoryProvider\ProviderCredentialPolicySupplier::class );
			$declaration        = new GitHubReplacementDeclaration( $manifest );
			$selection          = array(
				'provider_code'              => 'gh',
				'selection_generation'       => 1,
				'implementation_fingerprint' => $declaration->implementation_fingerprint,
				'plugin_basename'            => $declaration->plugin_basename,
			);
			$provider           = $this->createMockForIntersectionOfInterfaces( array( RepositoryProvider::class, \RAN\RepositoryProvider\ProviderCredentialPolicySupplier::class ) );
			self::assertInstanceOf( RepositoryProvider::class, $provider );
			$policy = $this->createMock( \RAN\RepositoryProvider\ProviderCredentialPolicy::class );
			$policy->method( 'get_provider' )->willReturnCallback(
				static function () use ( &$selection, $phase ): ProviderCode {
					if ( 'policy_identity' === $phase ) {
						++$selection['selection_generation']; }
					return ProviderCode::parse( 'gh' );
				}
			);
			$provider->method( 'get_credential_policy' )->willReturnCallback(
				static function () use ( &$selection, $phase, $policy ): \RAN\RepositoryProvider\ProviderCredentialPolicy {
					if ( 'policy' === $phase ) {
						++$selection['selection_generation']; }
					return $policy;
				}
			);
			$provider->method( 'get_metadata' )->willReturnCallback(
				static function () use ( &$selection, $phase ): ProviderMetadata {
					if ( 'metadata' === $phase ) {
						++$selection['selection_generation']; }
					return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
				}
			);
			$diagnostics = $this->createMock( ProviderDiagnostics::class );
			$provider->method( 'get_provider_diagnostics' )->willReturnCallback(
				static function () use ( &$selection, $phase, $diagnostics ): ProviderDiagnostics {
					if ( 'diagnostics' === $phase ) {
						++$selection['selection_generation']; }
					return $diagnostics;
				}
			);
			$store = $this->createMock( ProviderCredentialStore::class );
			$store->expects( self::never() )->method( 'credential_material' );
			$reader   = $this->createMock( AuthenticatedWebhookDeliveryEvidenceReader::class );
			$catalog  = new \RAN\RepositoryProvider\ProviderSecretPolicyCatalog();
			$registry = new ProviderRegistry(
				secret_policies: $catalog,
				credential_store_factory: static fn (): ProviderCredentialStore => $store,
				delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $reader,
				github_selection_guard: static function () use ( &$selection ): array {
					return $selection; },
				github_candidates: array( $declaration->plugin_basename => $declaration )
			);
			$registry->declare_github_replacement(
				$declaration,
				static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ) use ( $provider ): RepositoryProvider {
					unset( $credentials, $evidence, $context );
					return $provider;
				}
			);
			$registry->seal();
			self::assertSame( array(), $registry->all(), $phase );
			self::assertNull( $catalog->find_credential_policy( 'gh' ), $phase );
			self::assertNull( $catalog->find_webhook_policy( 'gh' ), $phase );
		}
	}
	public function test_passive_discovery_refreshes_a_directory_symlink_changed_by_another_process(): void {
		$root = sys_get_temp_dir() . '/ran-github-symlink-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated passive-discovery fixture root.
		self::assertTrue( mkdir( $root, 0700 ) );
		$manifest                    = $this->manifest();
		$manifest['plugin_basename'] = 'plugin/main.php';
		try {
			foreach ( array(
				'old' => '1',
				'new' => '2',
			) as $directory => $revision ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated symlink target owned by this test.
				self::assertTrue( mkdir( $root . '/' . $directory, 0700 ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write passive plugin fixture, never executable registration code.
				file_put_contents( $root . '/' . $directory . '/main.php', "<?php\n/* RAN Booster GitHub Replacement: 1 */" );
				$manifest['compatibility_revision'] = $revision;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write this isolated adjacent passive manifest.
				file_put_contents( $root . '/' . $directory . '/main.gh-replacement.json', $this->encode_manifest( $manifest ) );
			}
			self::assertTrue( symlink( $root . '/old', $root . '/plugin' ) );
			self::assertSame( '1', GitHubReplacementDeclaration::from_plugin_file( $root, 'plugin/main.php' )?->compatibility_revision );
			// PHP in this process would invalidate its own realpath cache on unlink.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A separate local PHP process must change the test-owned symlink without clearing this process's realpath cache.
			$process = proc_open( array( PHP_BINARY, '-r', 'exit(unlink($argv[1]) && symlink($argv[2], $argv[1]) ? 0 : 1);', $root . '/plugin', $root . '/new' ), array(), $pipes );
			self::assertIsResource( $process );
			self::assertSame( 0, proc_close( $process ) );
			self::assertSame( '2', GitHubReplacementDeclaration::from_plugin_file( $root, 'plugin/main.php' )->compatibility_revision );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove this test-owned symlink, never its destination.
			unlink( $root . '/plugin' );
			foreach ( array( 'old', 'new' ) as $directory ) {
				foreach ( array( 'main.php', 'main.gh-replacement.json' ) as $file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this isolated fixture's files.
					unlink( $root . '/' . $directory . '/' . $file );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this test-owned target directory.
				rmdir( $root . '/' . $directory );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove this isolated fixture root.
			rmdir( $root );
		}
	}
}

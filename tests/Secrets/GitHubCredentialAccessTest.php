<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;

final class GitHubCredentialAccessTest extends TestCase {
	private string $path;
	private bool $available    = true;
	private bool $classic      = true;
	private bool $fine_grained = true;
	private GitHubAccessPolicySpy $policy;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->path   = sys_get_temp_dir() . '/ran-gh-access-' . bin2hex( random_bytes( 8 ) );
		$this->policy = new GitHubAccessPolicySpy();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		foreach ( array( $this->path, $this->path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only this test's encrypted sidecar and lock.
				unlink( $file );
			}
		}
		InMemorySiteKeyStore::reset( $this->path );
	}

	/** @param array<string, mixed> $constants */
	private function store( array $constants = array() ): SecretsFile {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), $this->policy, null );
		return new SecretsFile(
			$this->path,
			$constants,
			$catalog,
			new InMemorySiteKeyStore( $this->path ),
			github_credential_access: function ( ?array $metadata ): bool {
				if ( null === $metadata ) {
					return $this->available;
				}
				self::assertSame( array( 'kind', 'configuration' ), array_keys( $metadata ) );
				return $this->available && match ( $metadata['kind'] ) {
					'classic' => $this->classic,
					'fine-grained' => $this->fine_grained,
					default => false,
				};
			}
		);
	}

	private function seed( SecretsFile $store, string $id, string $kind ): void {
		$store->save_credential(
			'gh',
			$id,
			array(
				'label'         => $id,
				'kind'          => $kind,
				'configuration' => array( 'owner' => 'fine-grained' === $kind ? 'owner' : '' ),
			),
			'fixture-secret'
		);
		$this->policy->normalizations = 0;
	}

	public function test_mixed_inventory_filters_before_policy_without_default_substitution(): void {
		$store = $this->store();
		$this->seed( $store, 'classic', 'classic' );
		$this->seed( $store, 'fine', 'fine-grained' );
		$before        = hash_file( 'sha256', $this->path );
		$this->classic = false;
		$bound         = $store->credentials_for( 'gh' );
		self::assertSame( array( 'fine' ), array_keys( $bound->credential_profiles() ) );
		self::assertNull( $bound->credential_material( 'classic' ) );
		self::assertNull( $bound->credential_material() );
		self::assertSame( 0, $this->policy->normalizations );
		self::assertSame( 'fine', $bound->credential_material( 'fine' )['id'] ?? null );
		self::assertSame( 1, $this->policy->normalizations );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
	}

	public function test_paused_selection_never_calls_constant_policy_or_exposes_material(): void {
		$store           = $this->store( array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'fixture-secret' ) );
		$this->available = false;
		self::assertSame( array(), $store->credentials_for( 'gh' )->credential_profiles() );
		self::assertNull( $store->credentials_for( 'gh' )->credential_material( 'constant' ) );
		self::assertSame( 0, $this->policy->constant_reads );
	}

	public function test_incompatible_constant_never_falls_back_to_file_or_reaches_policy(): void {
		$store = $this->store( array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'fixture-secret' ) );
		$this->seed( $store, 'fine', 'fine-grained' );
		$this->classic = false;
		self::assertNull( $store->credential_material( 'gh' ) );
		self::assertNull( $store->credential_material( 'gh', 'constant' ) );
		self::assertSame( 0, $this->policy->constant_reads );
	}

	public function test_candidate_cannot_suppress_existing_constant_and_substitute_file(): void {
		$store = $this->store( array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'fixture-secret' ) );
		$this->seed( $store, 'fine', 'fine-grained' );
		$this->policy->suppress_constant = true;
		try {
			$store->credential_material( 'gh' );
			self::fail( 'A retained constant cannot silently disappear.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 0, $this->policy->normalizations );
		}
	}

	public function test_constant_names_callback_drift_refuses_before_constant_disclosure(): void {
		$store                  = $this->store( array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'fixture-secret' ) );
		$this->policy->on_names = function (): void {
			$this->available = false;
		};
		try {
			$store->credential_material( 'gh', 'constant' );
			self::fail( 'Selection drift must refuse.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 0, $this->policy->constant_reads );
		}
	}

	public function test_constant_names_format_drift_refuses_before_disclosure(): void {
		$store                  = $this->store( array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'fixture-secret' ) );
		$this->policy->on_names = function (): void {
			$this->classic = false;
		};
		try {
			$store->credential_material( 'gh', 'constant' );
			self::fail( 'Format drift must refuse.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 0, $this->policy->constant_reads );
		}
	}

	public function test_policy_drift_prevents_return_and_temporary_operation(): void {
		$store = $this->store();
		$this->seed( $store, 'classic', 'classic' );
		$this->policy->on_normalize = function (): void {
			$this->available = false;
		};
		try {
			$store->credential_material( 'gh', 'classic' );
			self::fail( 'Policy drift must refuse.' );
		} catch ( \RuntimeException ) {
			self::assertFalse( $this->available );
		}
		$this->available = true;
		$called          = false;
		try {
			$store->with_temporary_credential(
				'gh',
				array(
					'label'         => 'temporary',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				'fixture-secret',
				static function () use ( &$called ): void {
					$called = true;
				}
			);
			self::fail( 'Temporary policy drift must refuse.' );
		} catch ( \RuntimeException ) {
			self::assertFalse( $called );
		}
	}

	public function test_incompatible_import_never_reaches_policy_or_persistence(): void {
		$store         = $this->store();
		$credential    = new \RAN\Portability\BlueprintCredential(
			'gh',
			'Portable',
			'classic',
			array( 'owner' => '' ),
			'fixture-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'fixture/fixture.php',
				),
			)
		);
		$blueprint     = new \RAN\Portability\PackageBlueprint( array( new \RAN\Portability\BlueprintPackage( 'plugin', 'fixture/fixture.php', 'Fixture', 'gh', '42', 'owner/repository', 'main', null ) ), array( $credential ) );
		$this->classic = false;
		try {
			$store->import_credentials_if_absent( $blueprint, $credential );
			self::fail( 'Import must refuse before policy disclosure.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 0, $this->policy->normalizations );
			self::assertFileDoesNotExist( $this->path );
		}
	}

	public function test_import_rechecks_under_sidecar_lock_before_persistence(): void {
		$checks     = 0;
		$store      = new SecretsFile(
			$this->path,
			array(),
			key_store: new InMemorySiteKeyStore( $this->path ),
			github_credential_access: static function ( ?array $metadata ) use ( &$checks ): bool {
				return null === $metadata || ++$checks < 4;
			}
		);
		$credential = new \RAN\Portability\BlueprintCredential(
			'gh',
			'Portable',
			'classic',
			array( 'owner' => '' ),
			'fixture-secret',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'fixture/fixture.php',
				),
			)
		);
		$blueprint  = new \RAN\Portability\PackageBlueprint( array( new \RAN\Portability\BlueprintPackage( 'plugin', 'fixture/fixture.php', 'Fixture', 'gh', '42', 'owner/repository', 'main', null ) ), array( $credential ) );
		try {
			$store->import_credentials_if_absent( $blueprint, $credential );
			self::fail( 'Import must recheck under the lock.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 4, $checks );
			self::assertFileDoesNotExist( $this->path );
		}
	}

	public function test_recovery_fitness_refuses_submitted_validator_drift(): void {
		$store = $this->store();
		$this->seed( $store, 'classic', 'classic' );
		$this->policy->on_submitted = function (): void {
			$this->available = false;
		};
		self::assertFalse( $store->recovery_credentials_fit_at( $this->path ) );
	}

	public function test_non_github_credential_behavior_is_unchanged(): void {
		$store           = $this->store();
		$this->available = false;
		$store->save_credential(
			'bb',
			'bitbucket',
			array(
				'label'         => 'Bitbucket',
				'kind'          => 'api-key',
				'configuration' => array(),
			),
			'fixture-secret'
		);
		self::assertSame( 'bitbucket', $store->credential_material( 'bb', 'bitbucket' )['id'] ?? null );
	}

	public function test_non_boolean_host_guard_refuses_material(): void {
		// Reflection deliberately supplies a foreign callback that violates the host PHPDoc contract.
		$store = ( new \ReflectionClass( SecretsFile::class ) )->newInstanceArgs(
			array(
				'path'                     => $this->path,
				'constants'                => array(),
				'github_credential_access' => static fn () => 1,
			)
		);
		self::assertNull( $store->credential_material( 'gh' ) );
	}

	public function test_throwing_host_guard_refuses_material(): void {
		$store = new SecretsFile(
			$this->path,
			array(),
			github_credential_access: static function (): bool {
				throw new \RuntimeException( 'unavailable' );
			}
		);
		self::assertNull( $store->credential_material( 'gh' ) );
		self::assertSame( array(), $store->credential_profiles( 'gh' ) );
	}

	public function test_temporary_read_is_guarded_and_cleanup_survives_refusal(): void {
		$store         = $this->store();
		$id            = $store->with_temporary_credential(
			'gh',
			array(
				'label'         => 'temporary',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'fixture-secret',
			function ( string $id ) use ( $store ): string {
				self::assertSame( $id, $store->credential_material( 'gh', $id )['id'] ?? null );
				$this->classic = false;
				self::assertNull( $store->credential_material( 'gh', $id ) );
				return $id;
			}
		);
		$this->classic = true;
		self::assertNull( $store->credential_material( 'gh', $id ) );
	}
}

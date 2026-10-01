<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RAN\Portability\BlueprintCredential;
use RAN\Portability\PackageBlueprint;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SubmittedCredentialValidator;
use JsonException;
use RuntimeException;

/**
 * Stores provider credentials outside the plugin and WordPress database.
 *
 * Deployment constants are runtime-only overlays. File records use a
 * provider-scoped, versioned schema so a credential ID has meaning only with
 * its provider and secret material is never returned by display APIs.
 */
class SecretsFile {

	public const SCHEMA_VERSION = 2;

	public const CREDENTIALS          = 'credentials';
	public const WEBHOOKS             = 'webhooks';
	public const CONSTANT_PROFILE     = 'constant';
	public const MAX_WEBHOOK_PROFILES = 16;

	private ?string $path;

	/** @var array<string, mixed>|null */
	private ?array $constants;
	private ProviderSecretPolicyCatalog $provider_policies;
	private SiteKeyStore $key_store;
	private EncryptedSecretsEnvelopeCodec $codec;
	private PrivateLocationCandidateResolver $location_resolver;
	private SecretsRuntimeAvailability $availability;
	private bool $validate_configured_path;

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $temporary_credentials = array();

	/**
	 * @param string|null                     $path             Absolute encrypted sidecar path. Null reads the encrypted-path constant.
	 * @param array<string, mixed>|null        $constants        Test-only constant values. Null reads PHP constants.
	 * @param ProviderSecretPolicyCatalog|null $providerPolicies Registered provider-owned secret policies.
	 */
	public function __construct(
		?string $path = null,
		?array $constants = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		?ProviderSecretPolicyCatalog $providerPolicies = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		?SiteKeyStore $keyStore = null,
		?EncryptedSecretsEnvelopeCodec $codec = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		?PrivateLocationCandidateResolver $locationResolver = null,
		?SecretsRuntimeAvailability $availability = null
	) {
		$this->validate_configured_path = null === $path;
		$this->path                     = null === $path ? $this->default_path() : $path;
		$this->constants                = $constants;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		$this->provider_policies = $providerPolicies ?? new ProviderSecretPolicyCatalog();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		$this->key_store = $keyStore ?? new SiteKeyStore();
		$this->codec     = $codec ?? new EncryptedSecretsEnvelopeCodec();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		$this->location_resolver = $locationResolver ?? new PrivateLocationCandidateResolver();
		$this->availability      = $availability ?? new SecretsRuntimeAvailability();
	}

	/**
	 * Issue a read-only credential view restricted to one registered provider.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function credentialsFor( ProviderCode|string $provider ): ProviderCredentialStore {
		$provider = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );

		return new BoundProviderCredentialStore( $this, $provider );
	}

	/**
	 * Validate the current sidecar schema and repair its file permissions.
	 *
	 * @return bool True when insecure permissions were repaired.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function verifyAndSecure(): bool {
		if ( ! $this->availability->is_available() || ! $this->has_managed_material() ) {
			return false;
		}

		return $this->with_lock(
			LOCK_EX,
			false,
			function (): bool {
				$key      = $this->load_key();
				$has_file = $this->has_file();
				if ( null === $key && ! $has_file ) {
					return false;
				}
				if ( null === $key || ! $has_file ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incomplete_store( $key, $has_file );
				}

				$permissions_changed = $this->secure_existing_file();
				$this->read_encrypted_document( $key );

				return $permissions_changed;
			}
		);
	}

	/**
	 * Prove that managed credentials can be read or initialized without mutating
	 * the key, ciphertext or final lock.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function assertManagedStorageReady(): void {
		$this->assert_available();
		$this->assert_configured_location();
		$this->file_document();
	}

	/**
	 * Authenticate and validate provider credential fitness at a discovered path.
	 *
	 * This does not authorize the filesystem location. Recovery callers must
	 * independently verify the candidate path and its metadata first.
	 * Authentication failures throw; provider-fitness failures return false.
	 */
	public function recovery_credentials_fit_at( string $path ): bool {
		$candidate = new self(
			$path,
			$this->constants,
			$this->provider_policies,
			$this->key_store,
			$this->codec,
			$this->location_resolver,
			$this->availability
		);
		$document  = $candidate->file_document();
		try {
			$candidate->assert_recovery_credential_fitness( $document );

			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Report whether the configured store is the narrow key-only recovery case.
	 *
	 * The check is read-only. A later reset must repeat it while holding the
	 * managed exclusive lock.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
	public function can_reset_orphaned_key_at( string $expectedPath ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( ! $this->can_recover_from_missing_ciphertext_at( $expectedPath ) ) {
			return false;
		}

		return null !== $this->load_key( false );
	}

	/**
	 * Verify that missing ciphertext is paired with no lock or a secure managed lock.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
	public function can_recover_from_missing_ciphertext_at( string $expectedPath ): bool {
		$this->assert_available();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			return false;
		}

		$this->assert_configured_location();
		if ( $this->has_file() ) {
			return false;
		}

		$lock = $this->lock_path();
		if ( ! file_exists( $lock ) && ! is_link( $lock ) ) {
			return true;
		}

		return $this->with_lock(
			LOCK_SH,
			false,
			fn (): bool => ! $this->has_file()
		);
	}

	/**
	 * Remove only the exact orphaned database key after a locked state recheck.
	 *
	 * The secure lock remains so the next normal credential write can initialize
	 * a fresh key and authenticated sidecar through the existing first-write path.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
	public function reset_orphaned_key_at( string $expectedPath ): void {
		$this->assert_available();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			throw $this->unavailable( 'The encrypted Booster secrets path changed before reset.' );
		}

		$lock        = $this->lock_path();
		$create_lock = ! file_exists( $lock ) && ! is_link( $lock );
		$this->with_lock(
			LOCK_EX,
			$create_lock,
			function (): void {
				$key = $this->load_key( false );
				if ( null === $key || $this->has_file() ) {
					throw $this->unavailable( 'The encrypted Booster secrets state changed before reset.' );
				}

				$this->delete_exact_key( $key );
			}
		);
	}

	/**
	 * Report whether the configured store is the narrow ciphertext-only recovery case.
	 *
	 * The ciphertext cannot be authenticated without its database key, so this
	 * verifies only the exact managed path, ownership, inode and permission
	 * boundaries. A later reset repeats every check under the exclusive lock.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
	public function can_reset_orphaned_ciphertext_at( string $expectedPath ): bool {
		$this->assert_available();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			return false;
		}

		$this->assert_configured_location();
		if ( null !== $this->load_key( false ) || ! $this->has_file() ) {
			return false;
		}

		return $this->with_lock(
			LOCK_SH,
			false,
			function (): bool {
				if ( null !== $this->load_key( false ) || ! $this->has_file() ) {
					return false;
				}

				$this->deletable_file_stat( (string) $this->path, 'encrypted Booster secrets file' );

				return true;
			}
		);
	}

	/**
	 * Remove only secure orphaned ciphertext after a locked state recheck.
	 *
	 * The secure lock remains so the next normal credential write can create a
	 * fresh database key and authenticated sidecar through the first-write path.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
	public function reset_orphaned_ciphertext_at( string $expectedPath ): void {
		$this->assert_available();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			throw $this->unavailable( 'The encrypted Booster secrets path changed before reset.' );
		}

		$this->with_lock(
			LOCK_EX,
			false,
			function (): void {
				if ( null !== $this->load_key( false ) || ! $this->has_file() ) {
					throw $this->unavailable( 'The encrypted Booster secrets state changed before reset.' );
				}

				$ciphertext = $this->deletable_file_stat(
					(string) $this->path,
					'encrypted Booster secrets file'
				);
				$this->delete_exact_file(
					(string) $this->path,
					$ciphertext,
					'Could not remove the orphaned encrypted Booster secrets file safely.'
				);
			}
		);
	}

	/**
	 * Report whether managed storage contains an authenticated document.
	 *
	 * A configured but pristine location returns false. Incomplete, unsafe or
	 * unauthenticated material throws the existing typed storage exception.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function hasHealthyManagedStorage(): bool {
		$this->assert_available();
		$this->assert_configured_location();

		if ( ! $this->has_managed_material( false ) ) {
			return false;
		}

		return $this->with_lock(
			LOCK_SH,
			false,
			function (): bool {
				$key      = $this->load_key( false );
				$has_file = $this->has_file();
				if ( null === $key && ! $has_file ) {
					return false;
				}
				if ( null === $key || ! $has_file ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incomplete_store( $key, $has_file );
				}

				$this->read_encrypted_document( $key );

				return true;
			}
		);
	}

	/**
	 * Return display-safe credential profiles for one provider.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function credentialProfiles( ProviderCode|string $provider ): array {
		if ( ! $this->availability->is_available() ) {
			return array();
		}

		$provider_code = $this->provider_value( $provider );
		$profiles      = array();
		$records       = array();
		$constant      = $this->constant_credential(
			$provider_code,
			$this->provider_policies->credentialPolicy( $provider )
		);
		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = $constant;
		}

		$document = $this->file_document();
		foreach ( $document[ self::CREDENTIALS ][ $provider_code ] ?? array() as $id => $record ) {
			$runtime = $this->runtime_credential_record( $provider_code, $id, $record );
			if ( ! $this->credential_destroyed( $runtime ) ) {
				$records[ $id ] = $runtime;
			}
		}

		foreach ( $records as $id => $record ) {
			$profiles[ $id ] = array(
				'id'            => $id,
				'provider'      => $record['provider'],
				'label'         => $record['label'],
				'kind'          => $record['kind'],
				'configuration' => $record['configuration'],
				'source'        => $record['source'],
				'immutable'     => $record['immutable'],
				'configured'    => true,
				'self_destruct' => $record['self_destruct'] ?? false,
				'destroy_on'    => $record['destroy_on'] ?? null,
			);
		}

		return $profiles;
	}

	/**
	 * Return one secret-bearing credential, or the provider default when ID is null.
	 *
	 * @return array<string, mixed>|null
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
		if ( ! $this->availability->is_available() && self::CONSTANT_PROFILE !== $id ) {
			$this->assert_available();
		}

		$provider_code = $this->provider_value( $provider );
		if ( null !== $id && '' !== trim( $id ) ) {
			$id = trim( $id );
			if ( self::CONSTANT_PROFILE === $id ) {
				$constant = $this->constant_credential(
					$provider_code,
					$this->provider_policies->credentialPolicy( $provider )
				);

				return null === $constant
					? null
					: $this->runtime_constant_credential( $provider_code, $constant );
			}
			if ( isset( $this->temporary_credentials[ $provider_code ][ $id ] ) ) {
				return $this->temporary_credentials[ $provider_code ][ $id ];
			}

			$document = $this->file_document();
			$record   = $document[ self::CREDENTIALS ][ $provider_code ][ $id ] ?? null;
			if ( ! is_array( $record ) ) {
				return null;
			}

			$runtime = $this->runtime_credential_record( $provider_code, $id, $record );
			if ( $this->credential_destroyed( $runtime ) ) {
				return null;
			}

			$runtime = $this->runtime_credential_record(
				$provider_code,
				$id,
				$this->revalidate_stored_credential( $provider_code, $id, $record )
			);

			return $runtime;
		}

		$constant = $this->constant_credential(
			$provider_code,
			$this->provider_policies->credentialPolicy( $provider )
		);
		if ( null !== $constant ) {
			return $constant;
		}

		$candidates = $this->temporary_credentials[ $provider_code ] ?? array();
		$document   = $this->file_document();
		foreach ( $document[ self::CREDENTIALS ][ $provider_code ] ?? array() as $stored_id => $record ) {
			$runtime = $this->runtime_credential_record( $provider_code, $stored_id, $record );
			if ( ! $this->credential_destroyed( $runtime ) ) {
				$candidates[ $stored_id ] = $runtime;
			}
		}
		if ( 1 !== count( $candidates ) ) {
			return null;
		}

		$selected_id = (string) array_key_first( $candidates );
		$selected    = $candidates[ $selected_id ];
		if ( 'file' !== ( $selected['source'] ?? null ) ) {
			return $selected;
		}

		return $this->runtime_credential_record(
			$provider_code,
			$selected_id,
			$this->revalidate_stored_credential(
				$provider_code,
				$selected_id,
				$document[ self::CREDENTIALS ][ $provider_code ][ $selected_id ]
			)
		);
	}

	/**
	 * Make validated credential material available only during one callback.
	 *
	 * The record never reaches the sidecar or display-safe profile API. Provider
	 * adapters receive it through their existing provider-bound credential store.
	 *
	 * @template TResult
	 * @param array<string, mixed> $metadata
	 * @param callable(string): TResult $operation
	 * @return TResult
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function withTemporaryCredential(
		ProviderCode|string $provider,
		array $metadata,
		#[\SensitiveParameter] string $secret,
		#[\SensitiveParameter] callable $operation
	): mixed {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$id            = 'tmp_' . bin2hex( random_bytes( 16 ) );
		$record        = $this->validate_credential( $provider_code, $id, $metadata, $secret, true );

		$this->temporary_credentials[ $provider_code ][ $id ] = array(
			'id'            => $id,
			'provider'      => $provider_code,
			'label'         => $record['label'],
			'kind'          => $record['kind'],
			'configuration' => $record['configuration'],
			'secret'        => $record['secret'],
			'source'        => 'temporary',
			'immutable'     => true,
		);

		try {
			return $operation( $id );
		} finally {
			unset( $this->temporary_credentials[ $provider_code ][ $id ] );
			if ( array() === $this->temporary_credentials[ $provider_code ] ) {
				unset( $this->temporary_credentials[ $provider_code ] );
			}
		}
	}

	/**
	 * Persist selected encrypted-blueprint credentials without replacing a target record.
	 *
	 * The returned IDs correspond to the supplied credentials in order. They are
	 * deterministic for the canonical blueprint, but only have meaning in this
	 * target sidecar. A selected record must be present in that blueprint; source
	 * credential IDs and webhook material cannot enter this boundary.
	 *
	 * @return list<string>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function importCredentialsIfAbsent(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] BlueprintCredential ...$credentials
	): array {
		if ( array() === $credentials ) {
			return array();
		}
		$this->assert_available();

		$records = $this->portable_credential_records( $blueprint, $credentials );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $records ): array {
				$changed = false;
				$ids     = array();

				foreach ( $records as $entry ) {
					$existing = $document[ self::CREDENTIALS ][ $entry['provider'] ][ $entry['id'] ] ?? null;
					if ( null === $existing ) {
						$document[ self::CREDENTIALS ][ $entry['provider'] ][ $entry['id'] ] = $entry['record'];
						$changed = true;
					} elseif ( $existing !== $entry['record'] ) {
						throw new RuntimeException( 'A portability credential conflicts with an existing target credential.' );
					}

					$ids[] = $entry['id'];
				}

				return array( $document, $ids, $changed );
			}
		);
	}

	/**
	 * Create or update a provider credential and return its stable ID.
	 *
	 * @param array<string, mixed> $metadata Non-secret label, kind and configuration.
	 * @param bool                 $submitted Apply provider checks for a newly submitted admin secret.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function saveCredential(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		#[\SensitiveParameter] ?string $secret,
		bool $submitted = false
	): string {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$id            = $this->writable_id( $id, 'cred' );
		$document      = $this->file_document();
		$existing      = $document[ self::CREDENTIALS ][ $provider_code ][ $id ] ?? null;
		$retain_secret = null === $secret || '' === $secret;
		if ( $retain_secret ) {
			if ( is_array( $existing ) ) {
				$secret = $existing['secret'];
				if ( ! array_key_exists( 'provider_destroy_on', $metadata ) && isset( $existing['provider_destroy_on'] ) ) {
					$metadata['provider_destroy_on'] = $existing['provider_destroy_on'];
				}
			}
		}
		$record = $this->validate_credential( $provider_code, $id, $metadata, $secret, $submitted && ! $retain_secret );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id, $record, $existing ): array {
				if ( ( $document[ self::CREDENTIALS ][ $provider_code ][ $id ] ?? null ) !== $existing ) {
					throw new RuntimeException( 'Credential material changed while it was being validated.' );
				}

				$document[ self::CREDENTIALS ][ $provider_code ][ $id ] = $record;

				return array( $document, $id );
			}
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function deleteCredential( ProviderCode|string $provider, string $id ): bool {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$this->assert_writable_id( $id );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id ): array {
				if ( ! isset( $document[ self::CREDENTIALS ][ $provider_code ][ $id ] ) ) {
					return array( $document, false, false );
				}

				unset( $document[ self::CREDENTIALS ][ $provider_code ][ $id ] );
				$this->remove_empty_provider( $document[ self::CREDENTIALS ], $provider_code );

				return array( $document, true );
			}
		);
	}

	/**
	 * Persist a provider-reported expiry for a self-destruct credential.
	 *
	 * This is deliberately separate from the advisory expiry observation option.
	 * It remains encrypted with the credential and can only shorten its local
	 * retention window.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function recordCredentialProviderExpiry( ProviderCode|string $provider, string $id, string $expiresOn ): void {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$this->assert_writable_id( $id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		$this->require_date( $expiresOn, 'Credential provider expiry' );

		$this->mutate(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id, $expiresOn ): array {
				$record = $document[ self::CREDENTIALS ][ $provider_code ][ $id ] ?? null;
				if ( ! is_array( $record ) || empty( $record['self_destruct'] ) ) {
					return array( $document, null, false );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
				$record['provider_destroy_on']                          = $expiresOn;
				$document[ self::CREDENTIALS ][ $provider_code ][ $id ] = $record;

				return array( $document, null );
			}
		);
	}

	/**
	 * Remove credentials whose encrypted self-destruct deadline has passed.
	 *
	 * @return array<string, list<string>> Provider-scoped removed IDs.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function purgeExpiredCredentials(): array {
		$this->assert_available();

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ): array {
				$removed = array();
				foreach ( $document[ self::CREDENTIALS ] as $provider => &$records ) {
					foreach ( $records as $id => $record ) {
						if ( $this->credential_destroyed( $record ) ) {
							unset( $records[ $id ] );
							$removed[ $provider ][] = $id;
						}
					}
					$this->remove_empty_provider( $document[ self::CREDENTIALS ], $provider );
				}
				unset( $records );

				return array( $document, $removed, array() !== $removed );
			}
		);
	}

	/**
	 * Return display-safe webhook profiles for one provider.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function webhookProfiles( ProviderCode|string $provider ): array {
		if ( ! $this->availability->is_available() ) {
			return array();
		}

		$profiles      = array();
		$provider_code = $this->provider_value( $provider );
		$records       = array();
		$constant      = $this->constant_webhook(
			$provider_code,
			$this->provider_policies->webhookPolicy( $provider )
		);
		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = $constant + array(
				'id'        => self::CONSTANT_PROFILE,
				'provider'  => $provider_code,
				'source'    => 'constant',
				'immutable' => true,
			);
		}

		$document = $this->file_document();
		foreach ( $document[ self::WEBHOOKS ][ $provider_code ] ?? array() as $id => $record ) {
			$records[ $id ] = $record + array(
				'id'        => $id,
				'provider'  => $provider_code,
				'source'    => 'file',
				'immutable' => false,
			);
		}

		foreach ( $records as $id => $record ) {
			$profiles[ $id ] = array(
				'id'           => $id,
				'provider'     => $provider_code,
				'label'        => $record['label'],
				'scope'        => $record['scope'],
				'target'       => $record['target'],
				'authority_id' => $record['authority_id'] ?? '',
				'revision'     => $record['revision'] ?? 1,
				'origin'       => $record['origin'] ?? 'manual',
				'source'       => $record['source'],
				'immutable'    => $record['immutable'],
				'configured'   => true,
			);
		}

		return $profiles;
	}

	/**
	 * Return secret-bearing webhook records for signature verification only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function webhookMaterials( ProviderCode|string $provider ): array {
		$this->assert_available();
		$policy        = $this->provider_policies->webhookPolicy( $provider );
		$provider_code = $this->provider_value( $provider );
		$records       = array();
		$constant      = $this->constant_webhook( $provider_code, $policy );

		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = array(
				'id'           => self::CONSTANT_PROFILE,
				'provider'     => $provider_code,
				'label'        => $constant['label'],
				'scope'        => $constant['scope'],
				'target'       => $constant['target'],
				'authority_id' => $constant['authority_id'] ?? '',
				'revision'     => 1,
				'origin'       => 'manual',
				'secret'       => $constant['secret'],
				'source'       => 'constant',
				'immutable'    => true,
			);
		}

		$document = $this->file_document();
		$stored   = $document[ self::WEBHOOKS ][ $provider_code ] ?? array();

		foreach ( $stored as $id => $record ) {
			$record         = $this->revalidate_stored_webhook( $provider_code, $id, $record );
			$records[ $id ] = array(
				'id'           => $id,
				'provider'     => $provider_code,
				'label'        => $record['label'],
				'scope'        => $record['scope'],
				'target'       => $record['target'],
				'authority_id' => $record['authority_id'] ?? '',
				'revision'     => $record['revision'],
				'origin'       => $record['origin'],
				'secret'       => $record['secret'],
				'source'       => 'file',
				'immutable'    => false,
			);
		}

		return $records;
	}

	/**
	 * Create or update a provider webhook secret and return its stable ID.
	 *
	 * @param array<string, mixed> $metadata Non-secret label, scope and target.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function saveWebhook(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		#[\SensitiveParameter] ?string $secret
	): string {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$id            = $this->writable_id( $id, 'wh' );
		$document      = $this->file_document();
		$existing      = $document[ self::WEBHOOKS ][ $provider_code ][ $id ] ?? null;
		if ( ( null === $secret || '' === $secret ) && is_array( $existing ) ) {
			$secret = $existing['secret'];
		}
		$record = $this->validate_webhook( $provider_code, $id, $metadata, $secret, true );
		if ( is_array( $existing ) ) {
			foreach ( array( 'scope', 'target', 'authority_id', 'origin' ) as $immutable ) {
				if ( ! hash_equals( (string) $existing[ $immutable ], (string) $record[ $immutable ] ) ) {
					throw new RuntimeException( 'Webhook secret scope, target, authority and origin are immutable.' );
				}
			}
			$record['revision'] = hash_equals( (string) $existing['secret'], (string) $record['secret'] )
				? (int) $existing['revision']
				: (int) $existing['revision'] + 1;
		}

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id, $record, $existing ): array {
				if ( ( $document[ self::WEBHOOKS ][ $provider_code ][ $id ] ?? null ) !== $existing ) {
					throw new RuntimeException( 'Webhook material changed while it was being validated.' );
				}

				$records        = $document[ self::WEBHOOKS ][ $provider_code ] ?? array();
				$records[ $id ] = $record;
				$this->assert_webhook_collection( $records, true );
				$document[ self::WEBHOOKS ][ $provider_code ] = $records;

				return array( $document, $id );
			}
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function deleteWebhook( ProviderCode|string $provider, string $id ): bool {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$this->assert_writable_id( $id );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id ): array {
				if ( ! isset( $document[ self::WEBHOOKS ][ $provider_code ][ $id ] ) ) {
					return array( $document, false, false );
				}

				unset( $document[ self::WEBHOOKS ][ $provider_code ][ $id ] );
				$this->remove_empty_provider( $document[ self::WEBHOOKS ], $provider_code );

				return array( $document, true );
			}
		);
	}

	/**
	 * Delete a webhook only when the stored material still has the expected revision.
	 *
	 * @internal Core operation recovery only.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain caller and override contracts.
	public function deleteWebhookIfRevision( ProviderCode|string $provider, string $id, int $expectedRevision ): bool {
		$this->assert_available();
		$provider_code = $this->provider_value( $provider );
		$this->assert_writable_id( $id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
		if ( $expectedRevision < 1 ) {
			throw new RuntimeException( 'Webhook secret revision must be positive.' );
		}

		return $this->mutate(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
			function ( #[\SensitiveParameter] array $document ) use ( $provider_code, $id, $expectedRevision ): array {
				$record = $document[ self::WEBHOOKS ][ $provider_code ][ $id ] ?? null;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names retain named-argument and constructor contracts.
				if ( ! is_array( $record ) || (int) ( $record['revision'] ?? 0 ) !== $expectedRevision ) {
					return array( $document, false, false );
				}

				unset( $document[ self::WEBHOOKS ][ $provider_code ][ $id ] );
				$this->remove_empty_provider( $document[ self::WEBHOOKS ], $provider_code );

				return array( $document, true );
			}
		);
	}

	public function path(): ?string {
		return $this->path;
	}

	/**
	 * Verify exact managed storage ownership without changing the filesystem or key.
	 */
	public function assert_managed_storage_deletable(): void {
		$key = $this->load_key( false );

		if ( ! is_string( $this->path ) || '' === $this->path ) {
			if ( null === $key ) {
				return;
			}

			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$has_file  = $this->has_file();
		$lock_path = $this->lock_path();
		$has_lock  = file_exists( $lock_path ) || is_link( $lock_path );
		if ( ! $has_file && null === $key && ! $has_lock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assert_configured_location();
			}

			return;
		}

		$this->assert_available();
		$this->assert_configured_location();
		if ( ! $has_lock ) {
			throw $this->unavailable(
				'The encrypted Booster secrets store is incomplete because its lock is missing.',
				'storage_lock_missing'
			);
		}

		$this->with_lock(
			LOCK_SH,
			false,
			function ( mixed $lock ): void {
				$key      = $this->load_key( false );
				$has_file = $this->has_file();
				if ( ( null !== $key ) !== $has_file ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incomplete_store( $key, $has_file );
				}
				if ( $has_file ) {
					$this->deletable_file_stat(
						(string) $this->path,
						'encrypted Booster secrets file'
					);
					$this->read_encrypted_document( (string) $key );
				}

				$this->assert_handle_matches_path( $lock, $this->lock_path(), 'secrets lock' );
			}
		);
	}

	/**
	 * Permanently remove the authenticated managed store, its database key and lock.
	 *
	 * Missing material is an idempotent success. Existing ciphertext must be a
	 * secure, process-owned, single-link file that authenticates with the current
	 * database key. The key is removed only after the ciphertext is gone.
	 */
	public function delete_managed_storage(): void {
		$this->assert_managed_storage_deletable();

		$key = $this->load_key( false );

		if ( ! is_string( $this->path ) || '' === $this->path ) {
			if ( null === $key ) {
				return;
			}

			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$has_file  = $this->has_file();
		$lock_path = $this->lock_path();
		$has_lock  = file_exists( $lock_path ) || is_link( $lock_path );
		if ( ! $has_file && null === $key && ! $has_lock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assert_configured_location();
			}

			return;
		}
		if ( $has_file ) {
			$this->assert_available();
		}

		$this->assert_configured_location();
		$this->with_lock(
			LOCK_EX,
			! $has_lock,
			function ( mixed $lock ): void {
				$key      = $this->load_key( false );
				$has_file = $this->has_file();

				if ( $has_file ) {
					if ( null === $key ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
						throw $this->incomplete_store( $key, $has_file );
					}

					$authenticated = $this->deletable_file_stat(
						(string) $this->path,
						'encrypted Booster secrets file'
					);
					$this->read_encrypted_document( $key );
					$this->delete_exact_file(
						(string) $this->path,
						$authenticated,
						'Could not remove the encrypted Booster secrets file safely.'
					);
				}

				if ( null !== $key ) {
					$this->delete_managed_key( $key );
				}

				$lock_path = $this->lock_path();
				$this->assert_handle_matches_path( $lock, $lock_path, 'secrets lock' );
				if ( ! $this->remove_file( $lock_path ) ) {
					throw $this->unavailable( 'Could not remove the encrypted Booster secrets lock safely.' );
				}
				clearstatcache( true, $lock_path );
			}
		);
	}

	private function provider_value( ProviderCode|string $provider ): string {
		try {
			return $provider instanceof ProviderCode ? $provider->value : ProviderCode::parse( $provider )->value;
		} catch ( InvalidProviderCode ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}
	}

	/** @return array<string, mixed>|null */
	private function constant_credential( string $provider, ProviderCredentialPolicy $policy ): ?array {
		try {
			$record = $policy->credential_from_constants( $this->declared_constants( $policy->get_constant_names() ) );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider credential constants could not be validated.' );
		}
		if ( null === $record ) {
			return null;
		}

		$record = $this->validate_credential(
			$provider,
			self::CONSTANT_PROFILE,
			$record,
			$record['secret'] ?? null
		);

		return array(
			'id'            => self::CONSTANT_PROFILE,
			'provider'      => $provider,
			'label'         => $record['label'],
			'kind'          => $record['kind'],
			'configuration' => $record['configuration'],
			'secret'        => $record['secret'],
			'source'        => 'constant',
			'immutable'     => true,
		);
	}

	/** @param array<string, mixed> $record */
	private function runtime_constant_credential( string $provider, #[\SensitiveParameter] array $record ): array {
		return array(
			'id'            => self::CONSTANT_PROFILE,
			'provider'      => $provider,
			'label'         => $record['label'],
			'kind'          => $record['kind'],
			'configuration' => $record['configuration'],
			'secret'        => $record['secret'],
			'source'        => 'constant',
			'immutable'     => true,
		);
	}

	/** @return array<string, string>|null */
	private function constant_webhook( string $provider, ProviderWebhookPolicy $policy ): ?array {
		try {
			$record = $policy->webhook_from_constants( $this->declared_constants( $policy->get_constant_names() ) );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider webhook constants could not be validated.' );
		}
		if ( null === $record ) {
			return null;
		}

		return $this->validate_webhook(
			$provider,
			self::CONSTANT_PROFILE,
			$record,
			$record['secret'] ?? null
		);
	}

	/**
	 * @param list<string> $names Provider-declared deployment constants.
	 * @return array<string, mixed>
	 */
	private function declared_constants( array $names ): array {
		$values = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/\ARAN_BOOSTER_[A-Z0-9_]{1,64}\z/D', $name ) ) {
				throw new RuntimeException( 'Provider credential constants are invalid.' );
			}

			$values[ $name ] = $this->raw_constant_value( $name );
		}

		return $values;
	}

	/** @param array<string, mixed> $record */
	private function runtime_credential_record( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
		return array(
			'id'            => $id,
			'provider'      => $provider,
			'label'         => $record['label'],
			'kind'          => $record['kind'],
			'configuration' => $record['configuration'],
			'secret'        => $record['secret'],
			'source'        => 'file',
			'immutable'     => false,
			'self_destruct' => $record['self_destruct'] ?? false,
			'destroy_on'    => $this->credential_destroy_on( $record ),
		);
	}

	/**
	 * @param array<string, mixed> $metadata Credential metadata.
	 * @return array<string, mixed>
	 */
	private function validate_credential(
		string $provider,
		string $id,
		array $metadata,
		#[\SensitiveParameter] mixed $secret,
		bool $submitted = false
	): array {
		$self_destruct       = $metadata['self_destruct'] ?? false;
		$manual_destroy_on   = $metadata['destroy_on'] ?? null;
		$provider_destroy_on = $metadata['provider_destroy_on'] ?? null;
		unset( $metadata['self_destruct'], $metadata['destroy_on'], $metadata['provider_destroy_on'] );
		if ( ! is_bool( $self_destruct ) ) {
			throw new RuntimeException( 'Credential self-destruction setting is invalid.' );
		}
		if ( $self_destruct ) {
			if ( null !== $manual_destroy_on ) {
				$this->require_date( $manual_destroy_on, 'Credential self-destruction date' );
			}
			if ( null !== $provider_destroy_on ) {
				$this->require_date( $provider_destroy_on, 'Credential provider expiry' );
			}
			if ( null === $manual_destroy_on && null === $provider_destroy_on ) {
				throw new RuntimeException( 'Credential self-destruction requires an expiry date.' );
			}
		} else {
			$manual_destroy_on   = null;
			$provider_destroy_on = null;
		}

		$policy = $this->provider_policies->findCredentialPolicy( $provider );
		try {
			$record = null !== $policy
				? $policy->normalize_credential( $metadata, $secret )
				: $metadata + array( 'secret' => $secret );
		} catch ( InvalidCredentialInput $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
			throw new InvalidCredentialInput( $failure->reason, $failure->getMessage() );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider credential material could not be validated.' );
		}
		$this->assert_only_keys( $record, array( 'label', 'kind', 'configuration', 'secret' ), 'Provider credential policy returned unsupported fields.' );

		if ( ! is_array( $record['configuration'] ?? null ) ) {
			throw new RuntimeException( 'Provider credential policy returned an invalid record.' );
		}

		$validated = array(
			'label'         => $this->required_string( $record['label'] ?? null, 'Credential label' ),
			'kind'          => $this->required_string( $record['kind'] ?? null, 'Credential kind' ),
			'configuration' => $record['configuration'],
			'secret'        => $this->required_string( $record['secret'] ?? null, 'Credential secret' ),
		);
		if ( $submitted && $policy instanceof SubmittedCredentialValidator ) {
			try {
				$policy->validate_submitted_credential(
					array(
						'label'         => $validated['label'],
						'kind'          => $validated['kind'],
						'configuration' => $validated['configuration'],
					),
					$validated['secret']
				);
			} catch ( InvalidCredentialInput $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
				throw new InvalidCredentialInput( $failure->reason, $failure->getMessage() );
			} catch ( \Throwable ) {
				throw new RuntimeException( 'Provider credential material could not be validated.' );
			}
		}
		if ( $self_destruct ) {
			$validated['self_destruct'] = true;
			if ( is_string( $manual_destroy_on ) ) {
				$validated['destroy_on'] = $manual_destroy_on;
			}
			if ( is_string( $provider_destroy_on ) ) {
				$validated['provider_destroy_on'] = $provider_destroy_on;
			}
		}

		return $validated;
	}

	/**
	 * @param list<BlueprintCredential> $credentials
	 * @return list<array{provider:string,id:string,record:array<string,mixed>}>
	 */
	private function portable_credential_records(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] array $credentials
	): array {
		$artifact_identity = hash( 'sha256', $blueprint->canonical_json() );
		$records           = array();

		foreach ( $credentials as $credential ) {
			if ( ! $this->blueprint_contains_credential( $blueprint, $credential ) ) {
				throw new RuntimeException( 'The portability credential is not part of this blueprint.' );
			}

			$provider  = $this->provider_value( $credential->provider );
			$record    = $this->validate_credential(
				$provider,
				'portable',
				array(
					'label'         => $credential->label,
					'kind'          => $credential->kind,
					'configuration' => $credential->configuration,
				),
				$credential->secret,
				true
			);
			$records[] = array(
				'provider' => $provider,
				'id'       => $this->portable_credential_id( $artifact_identity, $provider, $record ),
				'record'   => $record,
			);
		}

		return $records;
	}

	private function blueprint_contains_credential(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] BlueprintCredential $needle
	): bool {
		foreach ( $blueprint->credentials as $credential ) {
			if ( $credential->to_array() === $needle->to_array() ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $record */
	private function portable_credential_id(
		string $artifact_identity,
		string $provider,
		#[\SensitiveParameter] array $record
	): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Hash input only; exceptions fail closed before mutation.
			$target_key = hash( 'sha256', json_encode( array( $provider, $record ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} catch ( \JsonException ) {
			throw new RuntimeException( 'The portability credential could not be identified safely.' );
		}

		return 'portable_' . substr( hash( 'sha256', $artifact_identity . "\0" . $target_key ), 0, 55 );
	}

	/**
	 * @param array<string, mixed> $metadata Webhook metadata.
	 * @return array<string, string|int>
	 */
	private function validate_webhook(
		string $provider,
		string $id,
		array $metadata,
		#[\SensitiveParameter] mixed $secret,
		bool $submitted = false
	): array {
		$policy      = $this->provider_policies->findWebhookPolicy( $provider );
		$policy_data = array_intersect_key(
			$metadata,
			array_flip( array( 'label', 'scope', 'target', 'authority_id' ) )
		);
		try {
			$record = null !== $policy
				? $policy->normalize_webhook( $policy_data, $secret )
				: $policy_data + array( 'secret' => $secret );
		} catch ( InvalidWebhookInput $failure ) {
			if ( $submitted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
				throw new InvalidWebhookInput( $failure->reason );
			}
			throw new RuntimeException( 'Provider webhook material could not be validated.' );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider webhook material could not be validated.' );
		}
		$this->assert_only_keys( $record, array( 'label', 'scope', 'target', 'authority_id', 'secret' ), 'Provider webhook policy returned unsupported fields.' );
		$revision = $metadata['revision'] ?? 1;
		$origin   = $metadata['origin'] ?? 'manual';
		if ( ! is_int( $revision ) || $revision < 1 || ! is_string( $origin ) || ! in_array( $origin, array( 'manual', 'assisted' ), true ) ) {
			throw new RuntimeException( 'Webhook profile metadata is invalid.' );
		}

		return array(
			'label'        => $this->required_string( $record['label'] ?? null, 'Webhook secret label' ),
			'scope'        => $this->webhook_scope( $record['scope'] ?? null ),
			'target'       => isset( $record['target'] ) && is_string( $record['target'] ) ? $record['target'] : '',
			'authority_id' => isset( $record['authority_id'] ) && is_string( $record['authority_id'] ) ? $record['authority_id'] : '',
			'revision'     => $revision,
			'origin'       => $origin,
			'secret'       => $this->webhook_secret_value( $record['secret'] ?? null ),
		);
	}

	/** @param array<string, array<string, mixed>> $records */
	private function assert_webhook_collection( array $records, bool $submitted = false ): void {
		if ( count( $records ) > self::MAX_WEBHOOK_PROFILES ) {
			if ( $submitted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
				throw new InvalidWebhookInput( InvalidWebhookInput::CAPACITY );
			}
			throw new RuntimeException( 'A provider cannot store more than 16 webhook secrets.' );
		}

		$targets = array();
		foreach ( $records as $record ) {
			$scope = $this->webhook_scope( $record['scope'] ?? null );
			$key   = match ( $scope ) {
				'owner' => 'owner:' . strtolower( trim( (string) ( $record['target'] ?? '' ), " \t\n\r\0\x0B/" ) ),
				'repository' => 'repository:' . (string) ( $record['authority_id'] ?? '' ),
			};
			if ( isset( $targets[ $key ] ) ) {
				if ( $submitted ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
					throw new InvalidWebhookInput( InvalidWebhookInput::DUPLICATE_TARGET );
				}
				throw new RuntimeException( 'Only one webhook secret may be stored for each owner or repository.' );
			}
			$targets[ $key ] = true;
		}
	}

	private function webhook_scope( mixed $scope ): string {
		$scope = $this->required_string( $scope, 'Webhook secret scope' );
		if ( ! in_array( $scope, array( 'owner', 'repository' ), true ) ) {
			throw new RuntimeException( 'Webhook secret scope must be owner or repository.' );
		}

		return $scope;
	}

	/**
	 * @param callable(array<string, mixed>): array{0: array<string, mixed>, 1: mixed, 2?: bool} $mutation Mutation callback.
	 */
	private function mutate( #[\SensitiveParameter] callable $mutation ): mixed {
		$this->assert_configured_location();

		return $this->with_lock(
			LOCK_EX,
			true,
			function () use ( $mutation ): mixed {
				$key                             = $this->load_key();
				$has_file                        = $this->has_file();
				$document                        = $this->document_for_locked_state( $key, $has_file );
				list($document, $result, $write) = array_pad( $mutation( $document ), 3, true );

				if ( $write ) {
					$document    = $this->validate_canonical_document( $document );
					$key_created = false;
					if ( null === $key ) {
						$key_result  = $this->load_or_create_key();
						$key         = $key_result['key'];
						$key_created = $key_result['created'];
					}

					try {
						$this->write_canonical_file( $document, $key );
					} catch ( \Throwable $failure ) {
						if ( $key_created && ! $has_file && ! $this->has_file() ) {
							$this->delete_exact_key( $key );
						}

						throw $failure;
					}
				}

				return $result;
			}
		);
	}

	/** @param array<string, mixed> $providers */
	private function remove_empty_provider( #[\SensitiveParameter] array &$providers, string $provider ): void {
		if ( isset( $providers[ $provider ] ) && array() === $providers[ $provider ] ) {
			unset( $providers[ $provider ] );
		}
	}

	/** @return array<string, mixed> */
	private function file_document(): array {
		$this->assert_available();
		if ( ! $this->has_managed_material() ) {
			return $this->empty_document();
		}

		return $this->with_lock(
			LOCK_SH,
			false,
			function (): array {
				$key      = $this->load_key();
				$has_file = $this->has_file();

				return $this->document_for_locked_state( $key, $has_file );
			}
		);
	}

	private function has_file(): bool {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return false;
		}

		if ( ! file_exists( $this->path ) && ! is_link( $this->path ) ) {
			return false;
		}
		$stat = lstat( $this->path );
		if ( 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink'] ) {
			throw $this->unavailable( 'Refusing to use an invalid encrypted Booster secrets file.' );
		}

		return true;
	}

	private function has_managed_material( bool $repair_autoload = true ): bool {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			$key = $this->load_key( $repair_autoload );
			if ( null !== $key ) {
				throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
			}

			return false;
		}

		$has_file  = $this->has_file();
		$lock      = $this->lock_path();
		$lock_stat = file_exists( $lock ) || is_link( $lock )
			? lstat( $lock )
			: false;
		if ( false !== $lock_stat
			&& ( 0100000 !== ( $lock_stat['mode'] & 0170000 ) || 1 !== $lock_stat['nlink'] )
		) {
			throw $this->unavailable( 'Refusing to use an invalid encrypted Booster secrets lock.' );
		}
		if ( false !== $lock_stat ) {
			return true;
		}

		if ( $has_file || null !== $this->load_key( $repair_autoload ) ) {
			$lock_stat = file_exists( $lock ) || is_link( $lock )
				? lstat( $lock )
				: false;
			if ( false !== $lock_stat
				&& 0100000 === ( $lock_stat['mode'] & 0170000 )
				&& 1 === $lock_stat['nlink']
			) {
				return true;
			}

			throw $this->unavailable(
				'The encrypted Booster secrets store is missing its lock.',
				'storage_lock_missing'
			);
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function document_for_locked_state( #[\SensitiveParameter] ?string $key, bool $has_file ): array {
		if ( null === $key && ! $has_file ) {
			return $this->empty_document();
		}
		if ( null === $key || ! $has_file ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
			throw $this->incomplete_store( $key, $has_file );
		}

		return $this->read_encrypted_document( $key );
	}

	/** @return array<string, mixed> */
	private function read_encrypted_document( #[\SensitiveParameter] string $key ): array {
		$envelope = $this->read_bounded_file();
		try {
			$plaintext = $this->codec->decrypt( $envelope, $key );
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The encrypted Booster secrets document could not be authenticated.' );
		}

		try {
			$document = json_decode( $plaintext, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw $this->unavailable( 'The encrypted Booster secrets payload is invalid.' );
		}
		if ( ! is_array( $document ) ) {
			throw $this->unavailable( 'The encrypted Booster secrets payload is invalid.' );
		}

		try {
			$document = $this->validate_document( $document );
			if ( ! hash_equals( $this->encode_canonical_document( $document ), $plaintext ) ) {
				throw $this->unavailable( 'The encrypted Booster secrets payload is not canonical.' );
			}
		} catch ( SecretsStorageUnavailable $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The encrypted Booster secrets payload is invalid.' );
		}

		return $document;
	}

	private function read_bounded_file(): string {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle ) {
			throw $this->unavailable( 'The encrypted Booster secrets file is not readable.' );
		}

		try {
			$this->assert_handle_matches_path( $handle, $this->path, 'secrets file' );
			$stat = fstat( $handle );
			if ( false === $stat
				|| 0600 !== ( $stat['mode'] & 0777 )
				|| $stat['size'] < 1
				|| $stat['size'] > EncryptedSecretsEnvelopeCodec::MAX_BYTES
				|| ! $this->owned_by_process( $stat )
			) {
				throw $this->unavailable( 'The encrypted Booster secrets file is not a secure bounded file.' );
			}

			$contents = stream_get_contents( $handle, EncryptedSecretsEnvelopeCodec::MAX_BYTES + 1 );
			if ( false === $contents || strlen( $contents ) !== $stat['size'] ) {
				throw $this->unavailable( 'The encrypted Booster secrets file could not be read safely.' );
			}

			return $contents;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * @param array<string, mixed> $raw Raw sidecar document.
	 * @return array<string, mixed>
	 */
	private function validate_document( #[\SensitiveParameter] array $raw ): array {
		$allowed = array(
			'schema_version',
			self::CREDENTIALS,
			self::WEBHOOKS,
		);

		if ( array() !== array_diff( array_keys( $raw ), $allowed ) ) {
			throw new RuntimeException( 'The Booster secrets file contains unsupported fields.' );
		}

		if ( ! isset( $raw['schema_version'] ) || self::SCHEMA_VERSION !== $raw['schema_version'] ) {
			throw new RuntimeException( 'The Booster secrets file uses an unsupported schema version.' );
		}

		$document = $this->validate_canonical_document(
			array(
				'schema_version'  => self::SCHEMA_VERSION,
				self::CREDENTIALS => $raw[ self::CREDENTIALS ] ?? array(),
				self::WEBHOOKS    => $raw[ self::WEBHOOKS ] ?? array(),
			)
		);

		if ( $document !== $raw ) {
			throw new RuntimeException( 'The Booster secrets file is not in canonical form.' );
		}

		return $document;
	}

	/**
	 * @param array<string, mixed> $document Canonical document.
	 * @return array<string, mixed>
	 */
	private function validate_canonical_document( #[\SensitiveParameter] array $document ): array {
		if ( isset( $document['schema_version'] ) && self::SCHEMA_VERSION !== $document['schema_version'] ) {
			throw new RuntimeException( 'The Booster secrets file uses an unsupported schema version.' );
		}

		$normalised = $this->empty_document();
		foreach ( array( self::CREDENTIALS, self::WEBHOOKS ) as $collection ) {
			$providers = $document[ $collection ] ?? array();
			if ( ! is_array( $providers ) ) {
				throw new RuntimeException( 'Provider secret collections must be records.' );
			}

			foreach ( $providers as $provider => $records ) {
				if ( ! is_string( $provider ) || ! is_array( $records ) ) {
					throw new RuntimeException( 'Provider secret records are malformed.' );
				}

				$provider = $this->provider_value( $provider );
				foreach ( $records as $id => $record ) {
					if ( ! is_string( $id ) || ! is_array( $record ) ) {
						throw new RuntimeException( 'A provider secret record is malformed.' );
					}

					$this->assert_writable_id( $id );
					if ( self::CREDENTIALS === $collection ) {
						$this->assert_only_keys( $record, array( 'label', 'kind', 'configuration', 'secret', 'self_destruct', 'destroy_on', 'provider_destroy_on' ), 'A provider credential contains unsupported fields.' );
						$normalised[ $collection ][ $provider ][ $id ] = $this->validate_stored_credential( $record );
					} else {
						$normalised[ $collection ][ $provider ][ $id ] = $this->validate_stored_webhook( $record );
					}
				}
				ksort( $normalised[ $collection ][ $provider ], SORT_STRING );
				if ( self::WEBHOOKS === $collection ) {
					$this->assert_webhook_collection( $normalised[ $collection ][ $provider ] );
				}
			}
			ksort( $normalised[ $collection ], SORT_STRING );
		}

		return $normalised;
	}

	/** @param array<string, mixed> $record */
	private function validate_stored_credential( #[\SensitiveParameter] array $record ): array {
		$this->assert_only_keys( $record, array( 'label', 'kind', 'configuration', 'secret', 'self_destruct', 'destroy_on', 'provider_destroy_on' ), 'A provider credential contains unsupported fields.' );
		if ( ! is_array( $record['configuration'] ?? null ) ) {
			throw new RuntimeException( 'A provider credential contains invalid configuration.' );
		}

		$self_destruct       = $record['self_destruct'] ?? false;
		$manual_destroy_on   = $record['destroy_on'] ?? null;
		$provider_destroy_on = $record['provider_destroy_on'] ?? null;
		if ( ! is_bool( $self_destruct ) ) {
			throw new RuntimeException( 'Credential self-destruction setting is invalid.' );
		}

		$validated = array(
			'label'         => $this->required_string( $record['label'] ?? null, 'Credential label' ),
			'kind'          => $this->required_string( $record['kind'] ?? null, 'Credential kind' ),
			'configuration' => $record['configuration'],
			'secret'        => $this->required_string( $record['secret'] ?? null, 'Credential secret' ),
		);
		if ( $self_destruct ) {
			if ( null !== $manual_destroy_on ) {
				$this->require_date( $manual_destroy_on, 'Credential self-destruction date' );
			}
			if ( null !== $provider_destroy_on ) {
				$this->require_date( $provider_destroy_on, 'Credential provider expiry' );
			}
			if ( null === $manual_destroy_on && null === $provider_destroy_on ) {
				throw new RuntimeException( 'Credential self-destruction requires an expiry date.' );
			}

			$validated['self_destruct'] = true;
			if ( is_string( $manual_destroy_on ) ) {
				$validated['destroy_on'] = $manual_destroy_on;
			}
			if ( is_string( $provider_destroy_on ) ) {
				$validated['provider_destroy_on'] = $provider_destroy_on;
			}
		}

		return $validated;
	}

	/** @param array<string, mixed> $record */
	private function validate_stored_webhook( #[\SensitiveParameter] array $record ): array {
		$this->assert_only_keys( $record, array( 'label', 'scope', 'target', 'authority_id', 'revision', 'origin', 'secret' ), 'A provider webhook secret contains unsupported fields.' );
		$revision = $record['revision'] ?? 1;
		$origin   = $record['origin'] ?? 'manual';
		if ( ! is_int( $revision ) || $revision < 1 || ! is_string( $origin ) || ! in_array( $origin, array( 'manual', 'assisted' ), true ) ) {
			throw new RuntimeException( 'Webhook profile metadata is invalid.' );
		}

		return array(
			'label'        => $this->required_string( $record['label'] ?? null, 'Webhook secret label' ),
			'scope'        => $this->webhook_scope( $record['scope'] ?? null ),
			'target'       => isset( $record['target'] ) && is_string( $record['target'] ) ? $record['target'] : '',
			'authority_id' => isset( $record['authority_id'] ) && is_string( $record['authority_id'] ) ? $record['authority_id'] : '',
			'revision'     => $revision,
			'origin'       => $origin,
			'secret'       => $this->webhook_secret_value( $record['secret'] ?? null ),
		);
	}

	/** @param array<string, mixed> $record */
	private function revalidate_stored_credential( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
		$validated = $this->validate_credential( $provider, $id, $record, $record['secret'] ?? null );
		if ( $validated !== $record ) {
			throw new RuntimeException( 'Stored provider credential material is no longer canonical under the current policy.' );
		}

		return $validated;
	}

	/** @param array<string, mixed> $document */
	private function assert_recovery_credential_fitness( #[\SensitiveParameter] array $document ): void {
		foreach ( $document[ self::CREDENTIALS ] as $provider => $records ) {
			$policy = $this->provider_policies->findCredentialPolicy( $provider );
			if ( null === $policy ) {
				throw new RuntimeException( 'Stored credential fitness could not be verified for an unavailable provider.' );
			}

			foreach ( $records as $id => $record ) {
				$validated = $this->revalidate_stored_credential( $provider, $id, $record );
				if ( $policy instanceof SubmittedCredentialValidator ) {
					$policy->validate_submitted_credential(
						array(
							'label'         => $validated['label'],
							'kind'          => $validated['kind'],
							'configuration' => $validated['configuration'],
						),
						$validated['secret']
					);
				}
			}
		}
	}

	/** @param array<string, mixed> $record */
	private function revalidate_stored_webhook( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
		$validated = $this->validate_webhook( $provider, $id, $record, $record['secret'] ?? null );
		if ( $validated !== $record ) {
			throw new RuntimeException( 'Stored provider webhook material is no longer canonical under the current policy.' );
		}

		return $validated;
	}

	/** @return array<string, mixed> */
	private function empty_document(): array {
		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			self::CREDENTIALS => array(),
			self::WEBHOOKS    => array(),
		);
	}

	private function writable_id( ?string $id, string $prefix ): string {
		if ( null === $id || '' === trim( $id ) ) {
			$id = $prefix . '_' . $this->opaque_id();
		}

		$id = trim( $id );
		$this->assert_writable_id( $id );

		return $id;
	}

	private function assert_writable_id( mixed $id ): void {
		if ( ! is_string( $id )
			|| self::CONSTANT_PROFILE === $id
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $id )
		) {
			throw new RuntimeException( 'Credential ID is invalid or immutable.' );
		}
	}

	private function opaque_id(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	private function required_string( #[\SensitiveParameter] mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	/** @param array<string, mixed> $record */
	private function credential_destroyed( array $record ): bool {
		$destroy_on = $this->credential_destroy_on( $record );

		return null !== $destroy_on && gmdate( 'Y-m-d' ) > $destroy_on;
	}

	/** @param array<string, mixed> $record */
	private function credential_destroy_on( array $record ): ?string {
		if ( true !== ( $record['self_destruct'] ?? false ) ) {
			return null;
		}

		$dates = array_filter(
			array( $record['destroy_on'] ?? null, $record['provider_destroy_on'] ?? null ),
			static fn ( mixed $value ): bool => is_string( $value )
		);
		if ( array() === $dates ) {
			return null;
		}

		sort( $dates, SORT_STRING );

		return $dates[0];
	}

	private function require_date( mixed $value, string $name ): void {
		if ( ! is_string( $value )
			|| 1 !== preg_match( '/\\A(\\d{4})-(\\d{2})-(\\d{2})\\z/D', $value, $matches )
			|| ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $name . ' must be a valid date.' );
		}
	}

	private function webhook_secret_value( #[\SensitiveParameter] mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) < 32 || strlen( $value ) > 512
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			throw new RuntimeException( 'Webhook secrets must contain 32 to 512 bytes without control characters.' );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $record  Record to validate.
	 * @param list<string>         $allowed Allowed keys.
	 */
	private function assert_only_keys(
		#[\SensitiveParameter] array $record,
		array $allowed,
		string $message
	): void {
		if ( array() !== array_diff( array_keys( $record ), $allowed ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $message );
		}
	}

	private function raw_constant_value( string $name ): mixed {
		if ( is_array( $this->constants ) ) {
			return array_key_exists( $name, $this->constants ) ? $this->constants[ $name ] : null;
		}

		return defined( $name ) ? constant( $name ) : null;
	}

	private function default_path(): ?string {
		if ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' ) ) {
			return null;
		}
		if ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) ) {
			$value = constant( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return null;
			}

			$directory = '/' === $value
				? '/'
				: rtrim( $value, '/' );
			$path      = $directory . ( '/' === $directory ? '' : '/' ) . 'secrets.json';

			return $this->absolute_canonical_configured_path( $path ) ? $path : null;
		}
		return null;
	}

	private function absolute_canonical_configured_path( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_ends_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, "\r" )
			&& ! str_contains( $path, "\n" )
			&& 1 !== preg_match( '#/(?:\.{1,2})(?:/|$)#', $path )
			&& ! str_contains( $path, '//' );
	}

	/**
	 * @template TResult
	 * @param callable(resource): TResult $callback
	 * @return TResult
	 */
	private function with_lock(
		int $operation,
		bool $create,
		#[\SensitiveParameter] callable $callback
	): mixed {
		$this->assert_configured_location();
		$lock_path = $this->lock_path();

		if ( is_link( $lock_path ) ) {
			throw $this->unavailable( 'Refusing to use an invalid encrypted Booster secrets lock.' );
		}

		$lock = fopen( $lock_path, $create ? 'c+b' : 'r+b' );
		if ( false === $lock ) {
			throw $this->unavailable( 'Could not open the encrypted Booster secrets lock.' );
		}

		try {
			$this->assert_handle_matches_path( $lock, $lock_path, 'secrets lock' );
			$lock_stat = fstat( $lock );
			if ( false === $lock_stat ) {
				throw $this->unavailable( 'Could not inspect the encrypted Booster secrets lock.' );
			}
			if ( 0600 !== ( $lock_stat['mode'] & 0777 ) ) {
				if ( ! $create || ! $this->change_permissions( $lock_path, 0600 ) ) {
					throw $this->unavailable( 'Could not secure the encrypted Booster secrets lock.' );
				}
				$lock_stat = fstat( $lock );
			}
			if ( false === $lock_stat || 0600 !== ( $lock_stat['mode'] & 0777 ) || ! $this->owned_by_process( $lock_stat ) ) {
				throw $this->unavailable( 'Could not secure the encrypted Booster secrets lock.' );
			}

			if ( ! flock( $lock, $operation ) ) {
				throw $this->unavailable( 'Could not lock the encrypted Booster secrets store.' );
			}

			$this->assert_handle_matches_path( $lock, $lock_path, 'secrets lock' );

			return $callback( $lock );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** @param resource $handle */
	private function assert_handle_matches_path( mixed $handle, string $path, string $label ): void {
		$path_stat   = lstat( $path );
		$handle_stat = fstat( $handle );

		if ( false === $path_stat
			|| false === $handle_stat
			|| 0100000 !== ( $path_stat['mode'] & 0170000 )
			|| 1 !== $path_stat['nlink']
			|| $path_stat['dev'] !== $handle_stat['dev']
			|| $path_stat['ino'] !== $handle_stat['ino']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem labels are fixed at each call site.
			throw $this->unavailable( sprintf( 'Refusing to use an invalid encrypted Booster %s.', $label ) );
		}
	}

	private function assert_configured_location(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}
		if ( ! str_starts_with( $this->path, DIRECTORY_SEPARATOR )
			|| str_contains( $this->path, "\0" )
			|| str_contains( $this->path, DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR )
		) {
			throw $this->unavailable( 'The encrypted Booster secrets path is invalid.' );
		}

		if ( $this->validate_configured_path && ! $this->configured_location_is_private() ) {
			throw $this->unavailable( 'The configured encrypted Booster secrets path is not a verified private location.' );
		}

		$directory = dirname( $this->path );
		$stat      = lstat( $directory );
		if ( false === $stat
			|| 0040000 !== ( $stat['mode'] & 0170000 )
			|| 0700 !== ( $stat['mode'] & 0777 )
			|| ! $this->owned_by_process( $stat )
			|| ! is_readable( $directory )
			|| ! is_writable( $directory )
		) {
			throw $this->unavailable( 'The encrypted Booster secrets directory is not secure and writable.' );
		}

		if ( is_link( $this->path ) ) {
			throw $this->unavailable( 'Refusing to write an encrypted Booster secrets file through a symbolic link.' );
		}
	}

	private function assert_available(): void {
		if ( ! $this->availability->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Availability exposes one fixed, pathless operator message.
			throw $this->unavailable( $this->availability->message() );
		}
	}

	private function configured_location_is_private(): bool {
		$wordpress_root = defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '';
		$content_dir    = defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) ? WP_CONTENT_DIR : '';
		$plugin_dir     = realpath( dirname( __DIR__, 2 ) );
		$document_root  = $_SERVER['DOCUMENT_ROOT'] ?? null;

		return false !== $plugin_dir
			&& $this->location_resolver->validate_configured(
				(string) $this->path,
				$wordpress_root,
				$content_dir,
				$plugin_dir,
				is_string( $document_root ) && '' !== trim( $document_root ) ? $document_root : null
			);
	}

	private function secure_existing_file(): bool {
		if ( ! is_string( $this->path ) || ! file_exists( $this->path ) ) {
			return false;
		}

		if ( is_link( $this->path ) || ! is_file( $this->path ) ) {
			throw $this->unavailable( 'Refusing to secure an invalid encrypted Booster secrets file.' );
		}

		clearstatcache( true, $this->path );
		if ( 0600 === ( fileperms( $this->path ) & 0777 ) ) {
			return false;
		}

		if ( ! $this->change_permissions( $this->path, 0600 ) ) {
			throw $this->unavailable( 'Could not secure the encrypted Booster secrets file.' );
		}

		clearstatcache( true, $this->path );
		if ( 0600 !== ( fileperms( $this->path ) & 0777 ) ) {
			throw $this->unavailable( 'Could not secure the encrypted Booster secrets file.' );
		}

		return true;
	}

	/** @param array<string, mixed> $document */
	private function write_canonical_file(
		#[\SensitiveParameter] array $document,
		#[\SensitiveParameter] string $key
	): void {
		$this->assert_configured_location();
		$previous_contents = $this->has_file() ? $this->read_bounded_file() : null;

		try {
			$contents = $this->codec->encrypt( $this->encode_canonical_document( $document ), $key );
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster secrets document could not be encrypted.' );
		}

		$replacement_completed = false;
		try {
			$this->replace_ciphertext( $contents );
			$replacement_completed = true;
			clearstatcache( true, $this->path );
			$this->read_encrypted_document( $key );
		} catch ( \Throwable $exception ) {
			if ( $replacement_completed ) {
				try {
					$this->restore_previous_ciphertext( $previous_contents, $key );
				} catch ( \Throwable ) {
					throw $this->unavailable( 'The failed Booster secrets update could not restore the previous encrypted file.' );
				}
			}

			throw $exception;
		}
	}

	private function replace_ciphertext( #[\SensitiveParameter] string $contents ): void {
		$directory = dirname( $this->path );
		$temporary = tempnam( $directory, '.ran-booster-' );
		if ( false === $temporary ) {
			throw $this->unavailable( 'Could not create a temporary encrypted Booster secrets file.' );
		}

		try {
			if ( ! $this->change_permissions( $temporary, 0600 ) || 0600 !== ( fileperms( $temporary ) & 0777 ) ) {
				throw $this->unavailable( 'Could not secure the temporary encrypted Booster secrets file.' );
			}

			$handle = fopen( $temporary, 'wb' );
			if ( false === $handle ) {
				throw $this->unavailable( 'Could not open the temporary encrypted Booster secrets file.' );
			}
			try {
				$this->assert_handle_matches_path( $handle, $temporary, 'temporary secrets file' );
				$stat = fstat( $handle );
				if ( false === $stat || ! $this->owned_by_process( $stat ) ) {
					throw $this->unavailable( 'Could not verify the temporary encrypted Booster secrets file.' );
				}
				$offset = 0;
				$length = strlen( $contents );
				while ( $offset < $length ) {
					$remaining = substr( $contents, $offset );
					$written   = $this->write_handle( $handle, $remaining );
					if ( false === $written || 0 === $written || $written > strlen( $remaining ) ) {
						throw $this->unavailable( 'Could not write the temporary encrypted Booster secrets file.' );
					}
					$offset += $written;
				}
				if ( ! fflush( $handle )
					|| ( function_exists( 'fsync' ) && ! fsync( $handle ) )
				) {
					throw $this->unavailable( 'Could not write the temporary encrypted Booster secrets file.' );
				}
			} finally {
				fclose( $handle );
			}

			if ( ! $this->replace_file( $temporary, $this->path ) ) {
				throw $this->unavailable( 'Could not replace the encrypted Booster secrets file.' );
			}
			$temporary = '';
		} finally {
			if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
				unlink( $temporary );
			}
		}
	}

	private function restore_previous_ciphertext(
		#[\SensitiveParameter] ?string $previous_contents,
		#[\SensitiveParameter] string $key
	): void {
		if ( null !== $previous_contents ) {
			$this->replace_ciphertext( $previous_contents );
			clearstatcache( true, $this->path );
			$this->read_encrypted_document( $key );
			return;
		}

		$stat = lstat( $this->path );
		if ( false === $stat
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| 1 !== $stat['nlink']
			|| ! $this->owned_by_process( $stat )
			|| ! unlink( $this->path )
		) {
			throw $this->unavailable( 'Could not remove the failed encrypted Booster secrets file.' );
		}
		clearstatcache( true, $this->path );
	}

	/** @param array<string, mixed> $document */
	private function encode_canonical_document( #[\SensitiveParameter] array $document ): string {
		try {
			return json_encode(
				$document,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "\n";
		} catch ( JsonException ) {
			throw new RuntimeException( 'The Booster secrets document could not be encoded.' );
		}
	}

	/** @param array<string, int> $stat */
	private function owned_by_process( array $stat ): bool {
		$effective_user_id = $this->effective_user_id();

		return null !== $effective_user_id
			&& isset( $stat['uid'] )
			&& $stat['uid'] === $effective_user_id;
	}

	protected function effective_user_id(): ?int {
		return function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	}

	/**
	 * @param resource $handle
	 */
	protected function write_handle( mixed $handle, #[\SensitiveParameter] string $contents ): int|false {
		return fwrite( $handle, $contents );
	}

	private function lock_path(): string {
		return (string) $this->path . '.lock';
	}

	/**
	 * @return array{dev:int,ino:int,mode:int,uid:int,nlink:int}
	 */
	private function deletable_file_stat( string $path, string $label ): array {
		clearstatcache( true, $path );
		$stat = lstat( $path );
		if ( false === $stat
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| 1 !== $stat['nlink']
			|| 0600 !== ( $stat['mode'] & 0777 )
			|| ! $this->owned_by_process( $stat )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem labels are fixed at each call site.
			throw $this->unavailable( sprintf( 'Refusing to remove an invalid %s.', $label ) );
		}

		return $stat;
	}

	/**
	 * @param array{dev:int,ino:int,mode:int,uid:int,nlink:int} $expected
	 */
	private function delete_exact_file( string $path, array $expected, string $message ): void {
		clearstatcache( true, $path );
		$current = lstat( $path );
		if ( false === $current
			|| $expected['dev'] !== $current['dev']
			|| $expected['ino'] !== $current['ino']
			|| $expected['mode'] !== $current['mode']
			|| $expected['uid'] !== $current['uid']
			|| $expected['nlink'] !== $current['nlink']
			|| ! $this->remove_file( $path )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem failure messages are fixed at each call site.
			throw $this->unavailable( $message );
		}
		clearstatcache( true, $path );
	}

	private function incomplete_store( #[\SensitiveParameter] ?string $key, bool $has_file ): SecretsStorageUnavailable {
		if ( $has_file && null === $key ) {
			return $this->unavailable(
				'The encrypted Booster secrets store is incomplete: secrets.json exists but its database key is missing.',
				'storage_key_missing'
			);
		}
		if ( ! $has_file && null !== $key ) {
			return $this->unavailable(
				'The encrypted Booster secrets store is incomplete: its database key exists but secrets.json is missing.',
				'storage_file_missing'
			);
		}

		return $this->unavailable(
			'The encrypted Booster secrets store is incomplete: only secrets.json.lock remains.',
			'storage_orphan_lock'
		);
	}

	private function unavailable( string $message, string $reason = SecretsStorageUnavailable::REASON_GENERIC ): SecretsStorageUnavailable {
		return new SecretsStorageUnavailable( $message, $reason );
	}

	private function load_key( bool $repair_autoload = true ): ?string {
		try {
			return $this->key_store->load( $repair_autoload );
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster site key is unavailable.' );
		}
	}

	/** @return array{key:string,created:bool} */
	private function load_or_create_key(): array {
		try {
			return $this->key_store->load_or_create();
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster site key could not be initialized.' );
		}
	}

	private function delete_exact_key( #[\SensitiveParameter] string $key ): void {
		try {
			if ( ! $this->key_store->delete_exact( $key ) ) {
				throw $this->unavailable( 'The failed Booster site key could not be removed safely.' );
			}
		} catch ( SecretsStorageUnavailable $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The failed Booster site key could not be removed safely.' );
		}
	}

	private function delete_managed_key( #[\SensitiveParameter] string $key ): void {
		try {
			if ( $this->key_store->delete_exact( $key ) ) {
				return;
			}

			$remaining = $this->key_store->load( false );
			if ( null === $remaining ) {
				return;
			}
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster site key could not be removed safely.' );
		}

		throw $this->unavailable( 'The Booster site key could not be removed safely.' );
	}

	/**
	 * Small filesystem seams keep failure-path tests deterministic without
	 * replacing the native sidecar implementation in production.
	 */
	protected function change_permissions( string $path, int $mode ): bool {
		return chmod( $path, $mode );
	}

	protected function replace_file( string $source, string $destination ): bool {
		return rename( $source, $destination );
	}

	protected function remove_file( string $path ): bool {
		return unlink( $path );
	}
}

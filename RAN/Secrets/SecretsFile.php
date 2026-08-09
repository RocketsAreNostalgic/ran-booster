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
	private ProviderSecretPolicyCatalog $providerPolicies;
	private SiteKeyStore $keyStore;
	private EncryptedSecretsEnvelopeCodec $codec;
	private PrivateLocationCandidateResolver $locationResolver;
	private SecretsRuntimeAvailability $availability;
	private bool $validateConfiguredPath;

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $temporaryCredentials = array();

	/**
	 * @param string|null                     $path             Absolute encrypted sidecar path. Null reads the encrypted-path constant.
	 * @param array<string, mixed>|null        $constants        Test-only constant values. Null reads PHP constants.
	 * @param ProviderSecretPolicyCatalog|null $providerPolicies Registered provider-owned secret policies.
	 */
	public function __construct(
		?string $path = null,
		?array $constants = null,
		?ProviderSecretPolicyCatalog $providerPolicies = null,
		?SiteKeyStore $keyStore = null,
		?EncryptedSecretsEnvelopeCodec $codec = null,
		?PrivateLocationCandidateResolver $locationResolver = null,
		?SecretsRuntimeAvailability $availability = null
	) {
		$this->validateConfiguredPath = null === $path;
		$this->path                   = null === $path ? $this->defaultPath() : $path;
		$this->constants              = $constants;
		$this->providerPolicies       = $providerPolicies ?? new ProviderSecretPolicyCatalog();
		$this->keyStore               = $keyStore ?? new SiteKeyStore();
		$this->codec                  = $codec ?? new EncryptedSecretsEnvelopeCodec();
		$this->locationResolver       = $locationResolver ?? new PrivateLocationCandidateResolver();
		$this->availability           = $availability ?? new SecretsRuntimeAvailability();
	}

	/**
	 * Issue a read-only credential view restricted to one registered provider.
	 */
	public function credentialsFor( ProviderCode|string $provider ): ProviderCredentialStore {
		$provider = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );

		return new BoundProviderCredentialStore( $this, $provider );
	}

	/**
	 * Validate the current sidecar schema and repair its file permissions.
	 *
	 * @return bool True when insecure permissions were repaired.
	 */
	public function verifyAndSecure(): bool {
		if ( ! $this->availability->isAvailable() || ! $this->hasManagedMaterial() ) {
			return false;
		}

		return $this->withLock(
			LOCK_EX,
			false,
			function (): bool {
				$key     = $this->loadKey();
				$hasFile = $this->hasFile();
				if ( null === $key && ! $hasFile ) {
					return false;
				}
				if ( null === $key || ! $hasFile ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incompleteStore( $key, $hasFile );
				}

				$permissionsChanged = $this->secureExistingFile();
				$this->readEncryptedDocument( $key );

				return $permissionsChanged;
			}
		);
	}

	/**
	 * Prove that managed credentials can be read or initialized without mutating
	 * the key, ciphertext or final lock.
	 */
	public function assertManagedStorageReady(): void {
		$this->assertAvailable();
		$this->assertConfiguredLocation();
		$this->fileDocument();
	}

	/**
	 * Authenticate and validate provider credential fitness at a discovered path.
	 *
	 * This does not authorize the filesystem location. Recovery callers must
	 * independently verify the candidate path and its metadata first.
	 * Authentication failures throw; provider-fitness failures return false.
	 */
	public function recoveryCredentialsFitAt( string $path ): bool {
		$candidate = new self(
			$path,
			$this->constants,
			$this->providerPolicies,
			$this->keyStore,
			$this->codec,
			$this->locationResolver,
			$this->availability
		);
		$document  = $candidate->fileDocument();
		try {
			$candidate->assertRecoveryCredentialFitness( $document );

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
	public function canResetOrphanedKeyAt( string $expectedPath ): bool {
		if ( ! $this->canRecoverFromMissingCiphertextAt( $expectedPath ) ) {
			return false;
		}

		return null !== $this->loadKey( false );
	}

	/**
	 * Verify that missing ciphertext is paired with no lock or a secure managed lock.
	 */
	public function canRecoverFromMissingCiphertextAt( string $expectedPath ): bool {
		$this->assertAvailable();
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			return false;
		}

		$this->assertConfiguredLocation();
		if ( $this->hasFile() ) {
			return false;
		}

		$lock = $this->lockPath();
		if ( ! file_exists( $lock ) && ! is_link( $lock ) ) {
			return true;
		}

		return $this->withLock(
			LOCK_SH,
			false,
			fn (): bool => ! $this->hasFile()
		);
	}

	/**
	 * Remove only the exact orphaned database key after a locked state recheck.
	 *
	 * The secure lock remains so the next normal credential write can initialize
	 * a fresh key and authenticated sidecar through the existing first-write path.
	 */
	public function resetOrphanedKeyAt( string $expectedPath ): void {
		$this->assertAvailable();
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			throw $this->unavailable( 'The encrypted Booster secrets path changed before reset.' );
		}

		$lock       = $this->lockPath();
		$createLock = ! file_exists( $lock ) && ! is_link( $lock );
		$this->withLock(
			LOCK_EX,
			$createLock,
			function (): void {
				$key = $this->loadKey( false );
				if ( null === $key || $this->hasFile() ) {
					throw $this->unavailable( 'The encrypted Booster secrets state changed before reset.' );
				}

				$this->deleteExactKey( $key );
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
	public function canResetOrphanedCiphertextAt( string $expectedPath ): bool {
		$this->assertAvailable();
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			return false;
		}

		$this->assertConfiguredLocation();
		if ( null !== $this->loadKey( false ) || ! $this->hasFile() ) {
			return false;
		}

		return $this->withLock(
			LOCK_SH,
			false,
			function (): bool {
				if ( null !== $this->loadKey( false ) || ! $this->hasFile() ) {
					return false;
				}

				$this->deletableFileStat( (string) $this->path, 'encrypted Booster secrets file' );

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
	public function resetOrphanedCiphertextAt( string $expectedPath ): void {
		$this->assertAvailable();
		if ( ! is_string( $this->path ) || ! hash_equals( $this->path, $expectedPath ) ) {
			throw $this->unavailable( 'The encrypted Booster secrets path changed before reset.' );
		}

		$this->withLock(
			LOCK_EX,
			false,
			function (): void {
				if ( null !== $this->loadKey( false ) || ! $this->hasFile() ) {
					throw $this->unavailable( 'The encrypted Booster secrets state changed before reset.' );
				}

				$ciphertext = $this->deletableFileStat(
					(string) $this->path,
					'encrypted Booster secrets file'
				);
				$this->deleteExactFile(
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
	public function hasHealthyManagedStorage(): bool {
		$this->assertAvailable();
		$this->assertConfiguredLocation();

		if ( ! $this->hasManagedMaterial( false ) ) {
			return false;
		}

		return $this->withLock(
			LOCK_SH,
			false,
			function (): bool {
				$key     = $this->loadKey( false );
				$hasFile = $this->hasFile();
				if ( null === $key && ! $hasFile ) {
					return false;
				}
				if ( null === $key || ! $hasFile ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incompleteStore( $key, $hasFile );
				}

				$this->readEncryptedDocument( $key );

				return true;
			}
		);
	}

	/**
	 * Return display-safe credential profiles for one provider.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function credentialProfiles( ProviderCode|string $provider ): array {
		if ( ! $this->availability->isAvailable() ) {
			return array();
		}

		$providerCode = $this->providerValue( $provider );
		$profiles     = array();
		$records      = array();
		$constant     = $this->constantCredential(
			$providerCode,
			$this->providerPolicies->credentialPolicy( $provider )
		);
		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = $constant;
		}

		$document = $this->fileDocument();
		foreach ( $document[ self::CREDENTIALS ][ $providerCode ] ?? array() as $id => $record ) {
			$runtime = $this->runtimeCredentialRecord( $providerCode, $id, $record );
			if ( ! $this->credentialDestroyed( $runtime ) ) {
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
	public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
		if ( ! $this->availability->isAvailable() && self::CONSTANT_PROFILE !== $id ) {
			$this->assertAvailable();
		}

		$providerCode = $this->providerValue( $provider );
		if ( null !== $id && '' !== trim( $id ) ) {
			$id = trim( $id );
			if ( self::CONSTANT_PROFILE === $id ) {
				$constant = $this->constantCredential(
					$providerCode,
					$this->providerPolicies->credentialPolicy( $provider )
				);

				return null === $constant
					? null
					: $this->runtimeConstantCredential( $providerCode, $constant );
			}
			if ( isset( $this->temporaryCredentials[ $providerCode ][ $id ] ) ) {
				return $this->temporaryCredentials[ $providerCode ][ $id ];
			}

			$document = $this->fileDocument();
			$record   = $document[ self::CREDENTIALS ][ $providerCode ][ $id ] ?? null;
			if ( ! is_array( $record ) ) {
				return null;
			}

			$runtime = $this->runtimeCredentialRecord( $providerCode, $id, $record );
			if ( $this->credentialDestroyed( $runtime ) ) {
				return null;
			}

			$runtime = $this->runtimeCredentialRecord(
				$providerCode,
				$id,
				$this->revalidateStoredCredential( $providerCode, $id, $record )
			);

			return $runtime;
		}

		$constant = $this->constantCredential(
			$providerCode,
			$this->providerPolicies->credentialPolicy( $provider )
		);
		if ( null !== $constant ) {
			return $constant;
		}

		$candidates = $this->temporaryCredentials[ $providerCode ] ?? array();
		$document   = $this->fileDocument();
		foreach ( $document[ self::CREDENTIALS ][ $providerCode ] ?? array() as $storedId => $record ) {
			$runtime = $this->runtimeCredentialRecord( $providerCode, $storedId, $record );
			if ( ! $this->credentialDestroyed( $runtime ) ) {
				$candidates[ $storedId ] = $runtime;
			}
		}
		if ( 1 !== count( $candidates ) ) {
			return null;
		}

		$selectedId = (string) array_key_first( $candidates );
		$selected   = $candidates[ $selectedId ];
		if ( 'file' !== ( $selected['source'] ?? null ) ) {
			return $selected;
		}

		return $this->runtimeCredentialRecord(
			$providerCode,
			$selectedId,
			$this->revalidateStoredCredential(
				$providerCode,
				$selectedId,
				$document[ self::CREDENTIALS ][ $providerCode ][ $selectedId ]
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
	public function withTemporaryCredential(
		ProviderCode|string $provider,
		array $metadata,
		#[\SensitiveParameter] string $secret,
		#[\SensitiveParameter] callable $operation
	): mixed {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$id           = 'tmp_' . bin2hex( random_bytes( 16 ) );
		$record       = $this->validateCredential( $providerCode, $id, $metadata, $secret, true );

		$this->temporaryCredentials[ $providerCode ][ $id ] = array(
			'id'            => $id,
			'provider'      => $providerCode,
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
			unset( $this->temporaryCredentials[ $providerCode ][ $id ] );
			if ( array() === $this->temporaryCredentials[ $providerCode ] ) {
				unset( $this->temporaryCredentials[ $providerCode ] );
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
	public function importCredentialsIfAbsent(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] BlueprintCredential ...$credentials
	): array {
		if ( array() === $credentials ) {
			return array();
		}
		$this->assertAvailable();

		$records = $this->portableCredentialRecords( $blueprint, $credentials );

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
	public function saveCredential(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		#[\SensitiveParameter] ?string $secret,
		bool $submitted = false
	): string {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$id           = $this->writableId( $id, 'cred' );
		$document     = $this->fileDocument();
		$existing     = $document[ self::CREDENTIALS ][ $providerCode ][ $id ] ?? null;
		$retainSecret = null === $secret || '' === $secret;
		if ( $retainSecret ) {
			if ( is_array( $existing ) ) {
				$secret = $existing['secret'];
				if ( ! array_key_exists( 'provider_destroy_on', $metadata ) && isset( $existing['provider_destroy_on'] ) ) {
					$metadata['provider_destroy_on'] = $existing['provider_destroy_on'];
				}
			}
		}
		$record = $this->validateCredential( $providerCode, $id, $metadata, $secret, $submitted && ! $retainSecret );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id, $record, $existing ): array {
				if ( ( $document[ self::CREDENTIALS ][ $providerCode ][ $id ] ?? null ) !== $existing ) {
					throw new RuntimeException( 'Credential material changed while it was being validated.' );
				}

				$document[ self::CREDENTIALS ][ $providerCode ][ $id ] = $record;

				return array( $document, $id );
			}
		);
	}

	public function deleteCredential( ProviderCode|string $provider, string $id ): bool {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$this->assertWritableId( $id );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id ): array {
				if ( ! isset( $document[ self::CREDENTIALS ][ $providerCode ][ $id ] ) ) {
					return array( $document, false, false );
				}

				unset( $document[ self::CREDENTIALS ][ $providerCode ][ $id ] );
				$this->removeEmptyProvider( $document[ self::CREDENTIALS ], $providerCode );

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
	public function recordCredentialProviderExpiry( ProviderCode|string $provider, string $id, string $expiresOn ): void {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$this->assertWritableId( $id );
		$this->requireDate( $expiresOn, 'Credential provider expiry' );

		$this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id, $expiresOn ): array {
				$record = $document[ self::CREDENTIALS ][ $providerCode ][ $id ] ?? null;
				if ( ! is_array( $record ) || empty( $record['self_destruct'] ) ) {
					return array( $document, null, false );
				}
				$record['provider_destroy_on']                         = $expiresOn;
				$document[ self::CREDENTIALS ][ $providerCode ][ $id ] = $record;

				return array( $document, null );
			}
		);
	}

	/**
	 * Remove credentials whose encrypted self-destruct deadline has passed.
	 *
	 * @return array<string, list<string>> Provider-scoped removed IDs.
	 */
	public function purgeExpiredCredentials(): array {
		$this->assertAvailable();

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ): array {
				$removed = array();
				foreach ( $document[ self::CREDENTIALS ] as $provider => &$records ) {
					foreach ( $records as $id => $record ) {
						if ( $this->credentialDestroyed( $record ) ) {
							unset( $records[ $id ] );
							$removed[ $provider ][] = $id;
						}
					}
					$this->removeEmptyProvider( $document[ self::CREDENTIALS ], $provider );
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
	public function webhookProfiles( ProviderCode|string $provider ): array {
		if ( ! $this->availability->isAvailable() ) {
			return array();
		}

		$profiles     = array();
		$providerCode = $this->providerValue( $provider );
		$records      = array();
		$constant     = $this->constantWebhook(
			$providerCode,
			$this->providerPolicies->webhookPolicy( $provider )
		);
		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = $constant + array(
				'id'        => self::CONSTANT_PROFILE,
				'provider'  => $providerCode,
				'source'    => 'constant',
				'immutable' => true,
			);
		}

		$document = $this->fileDocument();
		foreach ( $document[ self::WEBHOOKS ][ $providerCode ] ?? array() as $id => $record ) {
			$records[ $id ] = $record + array(
				'id'        => $id,
				'provider'  => $providerCode,
				'source'    => 'file',
				'immutable' => false,
			);
		}

		foreach ( $records as $id => $record ) {
			$profiles[ $id ] = array(
				'id'           => $id,
				'provider'     => $providerCode,
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
	public function webhookMaterials( ProviderCode|string $provider ): array {
		$this->assertAvailable();
		$policy       = $this->providerPolicies->webhookPolicy( $provider );
		$providerCode = $this->providerValue( $provider );
		$records      = array();
		$constant     = $this->constantWebhook( $providerCode, $policy );

		if ( null !== $constant ) {
			$records[ self::CONSTANT_PROFILE ] = array(
				'id'           => self::CONSTANT_PROFILE,
				'provider'     => $providerCode,
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

		$document = $this->fileDocument();
		$stored   = $document[ self::WEBHOOKS ][ $providerCode ] ?? array();

		foreach ( $stored as $id => $record ) {
			$record         = $this->revalidateStoredWebhook( $providerCode, $id, $record );
			$records[ $id ] = array(
				'id'           => $id,
				'provider'     => $providerCode,
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
	public function saveWebhook(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		#[\SensitiveParameter] ?string $secret
	): string {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$id           = $this->writableId( $id, 'wh' );
		$document     = $this->fileDocument();
		$existing     = $document[ self::WEBHOOKS ][ $providerCode ][ $id ] ?? null;
		if ( ( null === $secret || '' === $secret ) && is_array( $existing ) ) {
			$secret = $existing['secret'];
		}
		$record = $this->validateWebhook( $providerCode, $id, $metadata, $secret, true );
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
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id, $record, $existing ): array {
				if ( ( $document[ self::WEBHOOKS ][ $providerCode ][ $id ] ?? null ) !== $existing ) {
					throw new RuntimeException( 'Webhook material changed while it was being validated.' );
				}

				$records        = $document[ self::WEBHOOKS ][ $providerCode ] ?? array();
				$records[ $id ] = $record;
				$this->assertWebhookCollection( $records, true );
				$document[ self::WEBHOOKS ][ $providerCode ] = $records;

				return array( $document, $id );
			}
		);
	}

	public function deleteWebhook( ProviderCode|string $provider, string $id ): bool {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$this->assertWritableId( $id );

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id ): array {
				if ( ! isset( $document[ self::WEBHOOKS ][ $providerCode ][ $id ] ) ) {
					return array( $document, false, false );
				}

				unset( $document[ self::WEBHOOKS ][ $providerCode ][ $id ] );
				$this->removeEmptyProvider( $document[ self::WEBHOOKS ], $providerCode );

				return array( $document, true );
			}
		);
	}

	/**
	 * Delete a webhook only when the stored material still has the expected revision.
	 *
	 * @internal Core operation recovery only.
	 */
	public function deleteWebhookIfRevision( ProviderCode|string $provider, string $id, int $expectedRevision ): bool {
		$this->assertAvailable();
		$providerCode = $this->providerValue( $provider );
		$this->assertWritableId( $id );
		if ( $expectedRevision < 1 ) {
			throw new RuntimeException( 'Webhook secret revision must be positive.' );
		}

		return $this->mutate(
			function ( #[\SensitiveParameter] array $document ) use ( $providerCode, $id, $expectedRevision ): array {
				$record = $document[ self::WEBHOOKS ][ $providerCode ][ $id ] ?? null;
				if ( ! is_array( $record ) || $expectedRevision !== (int) ( $record['revision'] ?? 0 ) ) {
					return array( $document, false, false );
				}

				unset( $document[ self::WEBHOOKS ][ $providerCode ][ $id ] );
				$this->removeEmptyProvider( $document[ self::WEBHOOKS ], $providerCode );

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
	public function assertManagedStorageDeletable(): void {
		$key = $this->loadKey( false );

		if ( ! is_string( $this->path ) || '' === $this->path ) {
			if ( null === $key ) {
				return;
			}

			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$hasFile  = $this->hasFile();
		$lockPath = $this->lockPath();
		$hasLock  = file_exists( $lockPath ) || is_link( $lockPath );
		if ( ! $hasFile && null === $key && ! $hasLock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assertConfiguredLocation();
			}

			return;
		}

		$this->assertAvailable();
		$this->assertConfiguredLocation();
		if ( ! $hasLock ) {
			throw $this->unavailable(
				'The encrypted Booster secrets store is incomplete because its lock is missing.',
				'storage_lock_missing'
			);
		}

		$this->withLock(
			LOCK_SH,
			false,
			function ( mixed $lock ): void {
				$key     = $this->loadKey( false );
				$hasFile = $this->hasFile();
				if ( $hasFile !== ( null !== $key ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
					throw $this->incompleteStore( $key, $hasFile );
				}
				if ( $hasFile ) {
					$this->deletableFileStat(
						(string) $this->path,
						'encrypted Booster secrets file'
					);
					$this->readEncryptedDocument( (string) $key );
				}

				$this->assertHandleMatchesPath( $lock, $this->lockPath(), 'secrets lock' );
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
	public function deleteManagedStorage(): void {
		$this->assertManagedStorageDeletable();

		$key = $this->loadKey( false );

		if ( ! is_string( $this->path ) || '' === $this->path ) {
			if ( null === $key ) {
				return;
			}

			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$hasFile  = $this->hasFile();
		$lockPath = $this->lockPath();
		$hasLock  = file_exists( $lockPath ) || is_link( $lockPath );
		if ( ! $hasFile && null === $key && ! $hasLock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assertConfiguredLocation();
			}

			return;
		}
		if ( $hasFile ) {
			$this->assertAvailable();
		}

		$this->assertConfiguredLocation();
		$this->withLock(
			LOCK_EX,
			! $hasLock,
			function ( mixed $lock ): void {
				$key     = $this->loadKey( false );
				$hasFile = $this->hasFile();

				if ( $hasFile ) {
					if ( null === $key ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
						throw $this->incompleteStore( $key, $hasFile );
					}

					$authenticated = $this->deletableFileStat(
						(string) $this->path,
						'encrypted Booster secrets file'
					);
					$this->readEncryptedDocument( $key );
					$this->deleteExactFile(
						(string) $this->path,
						$authenticated,
						'Could not remove the encrypted Booster secrets file safely.'
					);
				}

				if ( null !== $key ) {
					$this->deleteManagedKey( $key );
				}

				$lockPath = $this->lockPath();
				$this->assertHandleMatchesPath( $lock, $lockPath, 'secrets lock' );
				if ( ! $this->removeFile( $lockPath ) ) {
					throw $this->unavailable( 'Could not remove the encrypted Booster secrets lock safely.' );
				}
				clearstatcache( true, $lockPath );
			}
		);
	}

	private function providerValue( ProviderCode|string $provider ): string {
		try {
			return $provider instanceof ProviderCode ? $provider->value : ProviderCode::parse( $provider )->value;
		} catch ( InvalidProviderCode ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}
	}

	/** @return array<string, mixed>|null */
	private function constantCredential( string $provider, ProviderCredentialPolicy $policy ): ?array {
		try {
			$record = $policy->credentialFromConstants( $this->declaredConstants( $policy->getConstantNames() ) );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider credential constants could not be validated.' );
		}
		if ( null === $record ) {
			return null;
		}

		$record = $this->validateCredential(
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
	private function runtimeConstantCredential( string $provider, #[\SensitiveParameter] array $record ): array {
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
	private function constantWebhook( string $provider, ProviderWebhookPolicy $policy ): ?array {
		try {
			$record = $policy->webhookFromConstants( $this->declaredConstants( $policy->getConstantNames() ) );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider webhook constants could not be validated.' );
		}
		if ( null === $record ) {
			return null;
		}

		return $this->validateWebhook(
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
	private function declaredConstants( array $names ): array {
		$values = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/\ARAN_BOOSTER_[A-Z0-9_]{1,64}\z/D', $name ) ) {
				throw new RuntimeException( 'Provider credential constants are invalid.' );
			}

			$values[ $name ] = $this->rawConstantValue( $name );
		}

		return $values;
	}

	/** @param array<string, mixed> $record */
	private function runtimeCredentialRecord( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
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
			'destroy_on'    => $this->credentialDestroyOn( $record ),
		);
	}

	/**
	 * @param array<string, mixed> $metadata Credential metadata.
	 * @return array<string, mixed>
	 */
	private function validateCredential(
		string $provider,
		string $id,
		array $metadata,
		#[\SensitiveParameter] mixed $secret,
		bool $submitted = false
	): array {
		$selfDestruct      = $metadata['self_destruct'] ?? false;
		$manualDestroyOn   = $metadata['destroy_on'] ?? null;
		$providerDestroyOn = $metadata['provider_destroy_on'] ?? null;
		unset( $metadata['self_destruct'], $metadata['destroy_on'], $metadata['provider_destroy_on'] );
		if ( ! is_bool( $selfDestruct ) ) {
			throw new RuntimeException( 'Credential self-destruction setting is invalid.' );
		}
		if ( $selfDestruct ) {
			if ( null !== $manualDestroyOn ) {
				$this->requireDate( $manualDestroyOn, 'Credential self-destruction date' );
			}
			if ( null !== $providerDestroyOn ) {
				$this->requireDate( $providerDestroyOn, 'Credential provider expiry' );
			}
			if ( null === $manualDestroyOn && null === $providerDestroyOn ) {
				throw new RuntimeException( 'Credential self-destruction requires an expiry date.' );
			}
		} else {
			$manualDestroyOn   = null;
			$providerDestroyOn = null;
		}

		$policy = $this->providerPolicies->findCredentialPolicy( $provider );
		try {
			$record = null !== $policy
				? $policy->normalizeCredential( $metadata, $secret )
				: $metadata + array( 'secret' => $secret );
		} catch ( InvalidCredentialInput $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
			throw new InvalidCredentialInput( $failure->reason );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider credential material could not be validated.' );
		}
		$this->assertOnlyKeys( $record, array( 'label', 'kind', 'configuration', 'secret' ), 'Provider credential policy returned unsupported fields.' );

		if ( ! is_array( $record['configuration'] ?? null ) ) {
			throw new RuntimeException( 'Provider credential policy returned an invalid record.' );
		}

		$validated = array(
			'label'         => $this->requiredString( $record['label'] ?? null, 'Credential label' ),
			'kind'          => $this->requiredString( $record['kind'] ?? null, 'Credential kind' ),
			'configuration' => $record['configuration'],
			'secret'        => $this->requiredString( $record['secret'] ?? null, 'Credential secret' ),
		);
		if ( $submitted && $policy instanceof SubmittedCredentialValidator ) {
			try {
				$policy->validateSubmittedCredential(
					array(
						'label'         => $validated['label'],
						'kind'          => $validated['kind'],
						'configuration' => $validated['configuration'],
					),
					$validated['secret']
				);
			} catch ( InvalidCredentialInput $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
				throw new InvalidCredentialInput( $failure->reason );
			} catch ( \Throwable ) {
				throw new RuntimeException( 'Provider credential material could not be validated.' );
			}
		}
		if ( $selfDestruct ) {
			$validated['self_destruct'] = true;
			if ( is_string( $manualDestroyOn ) ) {
				$validated['destroy_on'] = $manualDestroyOn;
			}
			if ( is_string( $providerDestroyOn ) ) {
				$validated['provider_destroy_on'] = $providerDestroyOn;
			}
		}

		return $validated;
	}

	/**
	 * @param list<BlueprintCredential> $credentials
	 * @return list<array{provider:string,id:string,record:array<string,mixed>}>
	 */
	private function portableCredentialRecords(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] array $credentials
	): array {
		$artifactIdentity = hash( 'sha256', $blueprint->canonicalJson() );
		$records          = array();

		foreach ( $credentials as $credential ) {
			if ( ! $this->blueprintContainsCredential( $blueprint, $credential ) ) {
				throw new RuntimeException( 'The portability credential is not part of this blueprint.' );
			}

			$provider  = $this->providerValue( $credential->provider );
			$record    = $this->validateCredential(
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
				'id'       => $this->portableCredentialId( $artifactIdentity, $provider, $record ),
				'record'   => $record,
			);
		}

		return $records;
	}

	private function blueprintContainsCredential(
		#[\SensitiveParameter] PackageBlueprint $blueprint,
		#[\SensitiveParameter] BlueprintCredential $needle
	): bool {
		foreach ( $blueprint->credentials as $credential ) {
			if ( $credential->toArray() === $needle->toArray() ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $record */
	private function portableCredentialId(
		string $artifactIdentity,
		string $provider,
		#[\SensitiveParameter] array $record
	): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Hash input only; exceptions fail closed before mutation.
			$targetKey = hash( 'sha256', json_encode( array( $provider, $record ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} catch ( \JsonException ) {
			throw new RuntimeException( 'The portability credential could not be identified safely.' );
		}

		return 'portable_' . substr( hash( 'sha256', $artifactIdentity . "\0" . $targetKey ), 0, 55 );
	}

	/**
	 * @param array<string, mixed> $metadata Webhook metadata.
	 * @return array<string, string|int>
	 */
	private function validateWebhook(
		string $provider,
		string $id,
		array $metadata,
		#[\SensitiveParameter] mixed $secret,
		bool $submitted = false
	): array {
		$policy     = $this->providerPolicies->findWebhookPolicy( $provider );
		$policyData = array_intersect_key(
			$metadata,
			array_flip( array( 'label', 'scope', 'target', 'authority_id' ) )
		);
		try {
			$record = null !== $policy
				? $policy->normalizeWebhook( $policyData, $secret )
				: $policyData + array( 'secret' => $secret );
		} catch ( InvalidWebhookInput $failure ) {
			if ( $submitted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rebuild the closed failure so provider arguments never cross this boundary.
				throw new InvalidWebhookInput( $failure->reason );
			}
			throw new RuntimeException( 'Provider webhook material could not be validated.' );
		} catch ( \Throwable ) {
			throw new RuntimeException( 'Provider webhook material could not be validated.' );
		}
		$this->assertOnlyKeys( $record, array( 'label', 'scope', 'target', 'authority_id', 'secret' ), 'Provider webhook policy returned unsupported fields.' );
		$revision = $metadata['revision'] ?? 1;
		$origin   = $metadata['origin'] ?? 'manual';
		if ( ! is_int( $revision ) || $revision < 1 || ! is_string( $origin ) || ! in_array( $origin, array( 'manual', 'assisted' ), true ) ) {
			throw new RuntimeException( 'Webhook profile metadata is invalid.' );
		}

		return array(
			'label'        => $this->requiredString( $record['label'] ?? null, 'Webhook secret label' ),
			'scope'        => $this->webhookScope( $record['scope'] ?? null ),
			'target'       => isset( $record['target'] ) && is_string( $record['target'] ) ? $record['target'] : '',
			'authority_id' => isset( $record['authority_id'] ) && is_string( $record['authority_id'] ) ? $record['authority_id'] : '',
			'revision'     => $revision,
			'origin'       => $origin,
			'secret'       => $this->webhookSecretValue( $record['secret'] ?? null ),
		);
	}

	/** @param array<string, array<string, mixed>> $records */
	private function assertWebhookCollection( array $records, bool $submitted = false ): void {
		if ( count( $records ) > self::MAX_WEBHOOK_PROFILES ) {
			if ( $submitted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
				throw new InvalidWebhookInput( InvalidWebhookInput::CAPACITY );
			}
			throw new RuntimeException( 'A provider cannot store more than 16 webhook secrets.' );
		}

		$targets = array();
		foreach ( $records as $record ) {
			$scope = $this->webhookScope( $record['scope'] ?? null );
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

	private function webhookScope( mixed $scope ): string {
		$scope = $this->requiredString( $scope, 'Webhook secret scope' );
		if ( ! in_array( $scope, array( 'owner', 'repository' ), true ) ) {
			throw new RuntimeException( 'Webhook secret scope must be owner or repository.' );
		}

		return $scope;
	}

	/**
	 * @param callable(array<string, mixed>): array{0: array<string, mixed>, 1: mixed, 2?: bool} $mutation Mutation callback.
	 */
	private function mutate( #[\SensitiveParameter] callable $mutation ): mixed {
		$this->assertConfiguredLocation();

		return $this->withLock(
			LOCK_EX,
			true,
			function () use ( $mutation ): mixed {
				$key                             = $this->loadKey();
				$hasFile                         = $this->hasFile();
				$document                        = $this->documentForLockedState( $key, $hasFile );
				list($document, $result, $write) = array_pad( $mutation( $document ), 3, true );

				if ( $write ) {
					$document   = $this->validateCanonicalDocument( $document );
					$keyCreated = false;
					if ( null === $key ) {
						$keyResult  = $this->loadOrCreateKey();
						$key        = $keyResult['key'];
						$keyCreated = $keyResult['created'];
					}

					try {
						$this->writeCanonicalFile( $document, $key );
					} catch ( \Throwable $failure ) {
						if ( $keyCreated && ! $hasFile && ! $this->hasFile() ) {
							$this->deleteExactKey( $key );
						}

						throw $failure;
					}
				}

				return $result;
			}
		);
	}

	/** @param array<string, mixed> $providers */
	private function removeEmptyProvider( #[\SensitiveParameter] array &$providers, string $provider ): void {
		if ( isset( $providers[ $provider ] ) && array() === $providers[ $provider ] ) {
			unset( $providers[ $provider ] );
		}
	}

	/** @return array<string, mixed> */
	private function fileDocument(): array {
		$this->assertAvailable();
		if ( ! $this->hasManagedMaterial() ) {
			return $this->emptyDocument();
		}

		return $this->withLock(
			LOCK_SH,
			false,
			function (): array {
				$key     = $this->loadKey();
				$hasFile = $this->hasFile();

				return $this->documentForLockedState( $key, $hasFile );
			}
		);
	}

	private function hasFile(): bool {
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

	private function hasManagedMaterial( bool $repairAutoload = true ): bool {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			$key = $this->loadKey( $repairAutoload );
			if ( null !== $key ) {
				throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
			}

			return false;
		}

		$hasFile  = $this->hasFile();
		$lock     = $this->lockPath();
		$lockStat = file_exists( $lock ) || is_link( $lock )
			? lstat( $lock )
			: false;
		if ( false !== $lockStat
			&& ( 0100000 !== ( $lockStat['mode'] & 0170000 ) || 1 !== $lockStat['nlink'] )
		) {
			throw $this->unavailable( 'Refusing to use an invalid encrypted Booster secrets lock.' );
		}
		if ( false !== $lockStat ) {
			return true;
		}

		if ( $hasFile || null !== $this->loadKey( $repairAutoload ) ) {
			$lockStat = file_exists( $lock ) || is_link( $lock )
				? lstat( $lock )
				: false;
			if ( false !== $lockStat
				&& 0100000 === ( $lockStat['mode'] & 0170000 )
				&& 1 === $lockStat['nlink']
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
	private function documentForLockedState( #[\SensitiveParameter] ?string $key, bool $hasFile ): array {
		if ( null === $key && ! $hasFile ) {
			return $this->emptyDocument();
		}
		if ( null === $key || ! $hasFile ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Component presence selects one fixed pathless failure.
			throw $this->incompleteStore( $key, $hasFile );
		}

		return $this->readEncryptedDocument( $key );
	}

	/** @return array<string, mixed> */
	private function readEncryptedDocument( #[\SensitiveParameter] string $key ): array {
		$envelope = $this->readBoundedFile();
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
			$document = $this->validateDocument( $document );
			if ( ! hash_equals( $this->encodeCanonicalDocument( $document ), $plaintext ) ) {
				throw $this->unavailable( 'The encrypted Booster secrets payload is not canonical.' );
			}
		} catch ( SecretsStorageUnavailable $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The encrypted Booster secrets payload is invalid.' );
		}

		return $document;
	}

	private function readBoundedFile(): string {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}

		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle ) {
			throw $this->unavailable( 'The encrypted Booster secrets file is not readable.' );
		}

		try {
			$this->assertHandleMatchesPath( $handle, $this->path, 'secrets file' );
			$stat = fstat( $handle );
			if ( false === $stat
				|| 0600 !== ( $stat['mode'] & 0777 )
				|| $stat['size'] < 1
				|| $stat['size'] > EncryptedSecretsEnvelopeCodec::MAX_BYTES
				|| ! $this->ownedByProcess( $stat )
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
	private function validateDocument( #[\SensitiveParameter] array $raw ): array {
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

		$document = $this->validateCanonicalDocument(
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
	private function validateCanonicalDocument( #[\SensitiveParameter] array $document ): array {
		if ( isset( $document['schema_version'] ) && self::SCHEMA_VERSION !== $document['schema_version'] ) {
			throw new RuntimeException( 'The Booster secrets file uses an unsupported schema version.' );
		}

		$normalised = $this->emptyDocument();
		foreach ( array( self::CREDENTIALS, self::WEBHOOKS ) as $collection ) {
			$providers = $document[ $collection ] ?? array();
			if ( ! is_array( $providers ) ) {
				throw new RuntimeException( 'Provider secret collections must be records.' );
			}

			foreach ( $providers as $provider => $records ) {
				if ( ! is_string( $provider ) || ! is_array( $records ) ) {
					throw new RuntimeException( 'Provider secret records are malformed.' );
				}

				$provider = $this->providerValue( $provider );
				foreach ( $records as $id => $record ) {
					if ( ! is_string( $id ) || ! is_array( $record ) ) {
						throw new RuntimeException( 'A provider secret record is malformed.' );
					}

					$this->assertWritableId( $id );
					if ( self::CREDENTIALS === $collection ) {
						$this->assertOnlyKeys( $record, array( 'label', 'kind', 'configuration', 'secret', 'self_destruct', 'destroy_on', 'provider_destroy_on' ), 'A provider credential contains unsupported fields.' );
						$normalised[ $collection ][ $provider ][ $id ] = $this->validateStoredCredential( $record );
					} else {
						$normalised[ $collection ][ $provider ][ $id ] = $this->validateStoredWebhook( $record );
					}
				}
				ksort( $normalised[ $collection ][ $provider ], SORT_STRING );
				if ( self::WEBHOOKS === $collection ) {
					$this->assertWebhookCollection( $normalised[ $collection ][ $provider ] );
				}
			}
			ksort( $normalised[ $collection ], SORT_STRING );
		}

		return $normalised;
	}

	/** @param array<string, mixed> $record */
	private function validateStoredCredential( #[\SensitiveParameter] array $record ): array {
		$this->assertOnlyKeys( $record, array( 'label', 'kind', 'configuration', 'secret', 'self_destruct', 'destroy_on', 'provider_destroy_on' ), 'A provider credential contains unsupported fields.' );
		if ( ! is_array( $record['configuration'] ?? null ) ) {
			throw new RuntimeException( 'A provider credential contains invalid configuration.' );
		}

		$selfDestruct      = $record['self_destruct'] ?? false;
		$manualDestroyOn   = $record['destroy_on'] ?? null;
		$providerDestroyOn = $record['provider_destroy_on'] ?? null;
		if ( ! is_bool( $selfDestruct ) ) {
			throw new RuntimeException( 'Credential self-destruction setting is invalid.' );
		}

		$validated = array(
			'label'         => $this->requiredString( $record['label'] ?? null, 'Credential label' ),
			'kind'          => $this->requiredString( $record['kind'] ?? null, 'Credential kind' ),
			'configuration' => $record['configuration'],
			'secret'        => $this->requiredString( $record['secret'] ?? null, 'Credential secret' ),
		);
		if ( $selfDestruct ) {
			if ( null !== $manualDestroyOn ) {
				$this->requireDate( $manualDestroyOn, 'Credential self-destruction date' );
			}
			if ( null !== $providerDestroyOn ) {
				$this->requireDate( $providerDestroyOn, 'Credential provider expiry' );
			}
			if ( null === $manualDestroyOn && null === $providerDestroyOn ) {
				throw new RuntimeException( 'Credential self-destruction requires an expiry date.' );
			}

			$validated['self_destruct'] = true;
			if ( is_string( $manualDestroyOn ) ) {
				$validated['destroy_on'] = $manualDestroyOn;
			}
			if ( is_string( $providerDestroyOn ) ) {
				$validated['provider_destroy_on'] = $providerDestroyOn;
			}
		}

		return $validated;
	}

	/** @param array<string, mixed> $record */
	private function validateStoredWebhook( #[\SensitiveParameter] array $record ): array {
		$this->assertOnlyKeys( $record, array( 'label', 'scope', 'target', 'authority_id', 'revision', 'origin', 'secret' ), 'A provider webhook secret contains unsupported fields.' );
		$revision = $record['revision'] ?? 1;
		$origin   = $record['origin'] ?? 'manual';
		if ( ! is_int( $revision ) || $revision < 1 || ! is_string( $origin ) || ! in_array( $origin, array( 'manual', 'assisted' ), true ) ) {
			throw new RuntimeException( 'Webhook profile metadata is invalid.' );
		}

		return array(
			'label'        => $this->requiredString( $record['label'] ?? null, 'Webhook secret label' ),
			'scope'        => $this->webhookScope( $record['scope'] ?? null ),
			'target'       => isset( $record['target'] ) && is_string( $record['target'] ) ? $record['target'] : '',
			'authority_id' => isset( $record['authority_id'] ) && is_string( $record['authority_id'] ) ? $record['authority_id'] : '',
			'revision'     => $revision,
			'origin'       => $origin,
			'secret'       => $this->webhookSecretValue( $record['secret'] ?? null ),
		);
	}

	/** @param array<string, mixed> $record */
	private function revalidateStoredCredential( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
		$validated = $this->validateCredential( $provider, $id, $record, $record['secret'] ?? null );
		if ( $validated !== $record ) {
			throw new RuntimeException( 'Stored provider credential material is no longer canonical under the current policy.' );
		}

		return $validated;
	}

	/** @param array<string, mixed> $document */
	private function assertRecoveryCredentialFitness( #[\SensitiveParameter] array $document ): void {
		foreach ( $document[ self::CREDENTIALS ] as $provider => $records ) {
			$policy = $this->providerPolicies->findCredentialPolicy( $provider );
			if ( null === $policy ) {
				throw new RuntimeException( 'Stored credential fitness could not be verified for an unavailable provider.' );
			}

			foreach ( $records as $id => $record ) {
				$validated = $this->revalidateStoredCredential( $provider, $id, $record );
				if ( $policy instanceof SubmittedCredentialValidator ) {
					$policy->validateSubmittedCredential(
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
	private function revalidateStoredWebhook( string $provider, string $id, #[\SensitiveParameter] array $record ): array {
		$validated = $this->validateWebhook( $provider, $id, $record, $record['secret'] ?? null );
		if ( $validated !== $record ) {
			throw new RuntimeException( 'Stored provider webhook material is no longer canonical under the current policy.' );
		}

		return $validated;
	}

	/** @return array<string, mixed> */
	private function emptyDocument(): array {
		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			self::CREDENTIALS => array(),
			self::WEBHOOKS    => array(),
		);
	}

	private function writableId( ?string $id, string $prefix ): string {
		if ( null === $id || '' === trim( $id ) ) {
			$id = $prefix . '_' . $this->opaqueId();
		}

		$id = trim( $id );
		$this->assertWritableId( $id );

		return $id;
	}

	private function assertWritableId( mixed $id ): void {
		if ( ! is_string( $id )
			|| self::CONSTANT_PROFILE === $id
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $id )
		) {
			throw new RuntimeException( 'Credential ID is invalid or immutable.' );
		}
	}

	private function opaqueId(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	private function requiredString( #[\SensitiveParameter] mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	/** @param array<string, mixed> $record */
	private function credentialDestroyed( array $record ): bool {
		$destroyOn = $this->credentialDestroyOn( $record );

		return null !== $destroyOn && gmdate( 'Y-m-d' ) > $destroyOn;
	}

	/** @param array<string, mixed> $record */
	private function credentialDestroyOn( array $record ): ?string {
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

	private function requireDate( mixed $value, string $name ): void {
		if ( ! is_string( $value )
			|| 1 !== preg_match( '/\\A(\\d{4})-(\\d{2})-(\\d{2})\\z/D', $value, $matches )
			|| ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $name . ' must be a valid date.' );
		}
	}

	private function webhookSecretValue( #[\SensitiveParameter] mixed $value ): string {
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
	private function assertOnlyKeys(
		#[\SensitiveParameter] array $record,
		array $allowed,
		string $message
	): void {
		if ( array() !== array_diff( array_keys( $record ), $allowed ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage exceptions are caught and escaped at the admin boundary.
			throw new RuntimeException( $message );
		}
	}

	private function rawConstantValue( string $name ): mixed {
		if ( is_array( $this->constants ) ) {
			return array_key_exists( $name, $this->constants ) ? $this->constants[ $name ] : null;
		}

		return defined( $name ) ? constant( $name ) : null;
	}

	private function defaultPath(): ?string {
		if ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) ) {
			$value = constant( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return null;
			}

			$directory = '/' === $value
				? '/'
				: rtrim( $value, '/' );
			$path      = $directory . ( '/' === $directory ? '' : '/' ) . 'secrets.json';

			return $this->absoluteCanonicalConfiguredPath( $path ) ? $path : null;
		}
		if ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' )
			&& is_string( RAN_BOOSTER_ENCRYPTED_SECRETS_FILE )
			&& $this->absoluteCanonicalConfiguredPath( RAN_BOOSTER_ENCRYPTED_SECRETS_FILE ) ) {
			return RAN_BOOSTER_ENCRYPTED_SECRETS_FILE;
		}

		return null;
	}

	private function absoluteCanonicalConfiguredPath( string $path ): bool {
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
	private function withLock(
		int $operation,
		bool $create,
		#[\SensitiveParameter] callable $callback
	): mixed {
		$this->assertConfiguredLocation();
		$lockPath = $this->lockPath();

		if ( is_link( $lockPath ) ) {
			throw $this->unavailable( 'Refusing to use an invalid encrypted Booster secrets lock.' );
		}

		$lock = fopen( $lockPath, $create ? 'c+b' : 'r+b' );
		if ( false === $lock ) {
			throw $this->unavailable( 'Could not open the encrypted Booster secrets lock.' );
		}

		try {
			$this->assertHandleMatchesPath( $lock, $lockPath, 'secrets lock' );
			$lockStat = fstat( $lock );
			if ( false === $lockStat ) {
				throw $this->unavailable( 'Could not inspect the encrypted Booster secrets lock.' );
			}
			if ( 0600 !== ( $lockStat['mode'] & 0777 ) ) {
				if ( ! $create || ! $this->changePermissions( $lockPath, 0600 ) ) {
					throw $this->unavailable( 'Could not secure the encrypted Booster secrets lock.' );
				}
				$lockStat = fstat( $lock );
			}
			if ( false === $lockStat || 0600 !== ( $lockStat['mode'] & 0777 ) || ! $this->ownedByProcess( $lockStat ) ) {
				throw $this->unavailable( 'Could not secure the encrypted Booster secrets lock.' );
			}

			if ( ! flock( $lock, $operation ) ) {
				throw $this->unavailable( 'Could not lock the encrypted Booster secrets store.' );
			}

			$this->assertHandleMatchesPath( $lock, $lockPath, 'secrets lock' );

			return $callback( $lock );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** @param resource $handle */
	private function assertHandleMatchesPath( mixed $handle, string $path, string $label ): void {
		$pathStat   = lstat( $path );
		$handleStat = fstat( $handle );

		if ( false === $pathStat
			|| false === $handleStat
			|| 0100000 !== ( $pathStat['mode'] & 0170000 )
			|| 1 !== $pathStat['nlink']
			|| $pathStat['dev'] !== $handleStat['dev']
			|| $pathStat['ino'] !== $handleStat['ino']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem labels are fixed at each call site.
			throw $this->unavailable( sprintf( 'Refusing to use an invalid encrypted Booster %s.', $label ) );
		}
	}

	private function assertConfiguredLocation(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			throw $this->unavailable( 'The encrypted Booster secrets path is not configured.' );
		}
		if ( ! str_starts_with( $this->path, DIRECTORY_SEPARATOR )
			|| str_contains( $this->path, "\0" )
			|| str_contains( $this->path, DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR )
		) {
			throw $this->unavailable( 'The encrypted Booster secrets path is invalid.' );
		}

		if ( $this->validateConfiguredPath && ! $this->configuredLocationIsPrivate() ) {
			throw $this->unavailable( 'The configured encrypted Booster secrets path is not a verified private location.' );
		}

		$directory = dirname( $this->path );
		$stat      = lstat( $directory );
		if ( false === $stat
			|| 0040000 !== ( $stat['mode'] & 0170000 )
			|| 0700 !== ( $stat['mode'] & 0777 )
			|| ! $this->ownedByProcess( $stat )
			|| ! is_readable( $directory )
			|| ! is_writable( $directory )
		) {
			throw $this->unavailable( 'The encrypted Booster secrets directory is not secure and writable.' );
		}

		if ( is_link( $this->path ) ) {
			throw $this->unavailable( 'Refusing to write an encrypted Booster secrets file through a symbolic link.' );
		}
	}

	private function assertAvailable(): void {
		if ( ! $this->availability->isAvailable() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Availability exposes one fixed, pathless operator message.
			throw $this->unavailable( $this->availability->message() );
		}
	}

	private function configuredLocationIsPrivate(): bool {
		$wordpressRoot = defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '';
		$contentDir    = defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) ? WP_CONTENT_DIR : '';
		$pluginDir     = realpath( dirname( __DIR__, 2 ) );
		$documentRoot  = $_SERVER['DOCUMENT_ROOT'] ?? null;

		return false !== $pluginDir
			&& $this->locationResolver->validateConfigured(
				(string) $this->path,
				$wordpressRoot,
				$contentDir,
				$pluginDir,
				is_string( $documentRoot ) && '' !== trim( $documentRoot ) ? $documentRoot : null
			);
	}

	private function secureExistingFile(): bool {
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

		if ( ! $this->changePermissions( $this->path, 0600 ) ) {
			throw $this->unavailable( 'Could not secure the encrypted Booster secrets file.' );
		}

		clearstatcache( true, $this->path );
		if ( 0600 !== ( fileperms( $this->path ) & 0777 ) ) {
			throw $this->unavailable( 'Could not secure the encrypted Booster secrets file.' );
		}

		return true;
	}

	/** @param array<string, mixed> $document */
	private function writeCanonicalFile(
		#[\SensitiveParameter] array $document,
		#[\SensitiveParameter] string $key
	): void {
		$this->assertConfiguredLocation();
		$previousContents = $this->hasFile() ? $this->readBoundedFile() : null;

		try {
			$contents = $this->codec->encrypt( $this->encodeCanonicalDocument( $document ), $key );
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster secrets document could not be encrypted.' );
		}

		$replacementCompleted = false;
		try {
			$this->replaceCiphertext( $contents );
			$replacementCompleted = true;
			clearstatcache( true, $this->path );
			$this->readEncryptedDocument( $key );
		} catch ( \Throwable $exception ) {
			if ( $replacementCompleted ) {
				try {
					$this->restorePreviousCiphertext( $previousContents, $key );
				} catch ( \Throwable ) {
					throw $this->unavailable( 'The failed Booster secrets update could not restore the previous encrypted file.' );
				}
			}

			throw $exception;
		}
	}

	private function replaceCiphertext( #[\SensitiveParameter] string $contents ): void {
		$directory = dirname( $this->path );
		$temporary = tempnam( $directory, '.ran-booster-' );
		if ( false === $temporary ) {
			throw $this->unavailable( 'Could not create a temporary encrypted Booster secrets file.' );
		}

		try {
			if ( ! $this->changePermissions( $temporary, 0600 ) || 0600 !== ( fileperms( $temporary ) & 0777 ) ) {
				throw $this->unavailable( 'Could not secure the temporary encrypted Booster secrets file.' );
			}

			$handle = fopen( $temporary, 'wb' );
			if ( false === $handle ) {
				throw $this->unavailable( 'Could not open the temporary encrypted Booster secrets file.' );
			}
			try {
				$this->assertHandleMatchesPath( $handle, $temporary, 'temporary secrets file' );
				$stat = fstat( $handle );
				if ( false === $stat || ! $this->ownedByProcess( $stat ) ) {
					throw $this->unavailable( 'Could not verify the temporary encrypted Booster secrets file.' );
				}
				$offset = 0;
				$length = strlen( $contents );
				while ( $offset < $length ) {
					$remaining = substr( $contents, $offset );
					$written   = $this->writeHandle( $handle, $remaining );
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

			if ( ! $this->replaceFile( $temporary, $this->path ) ) {
				throw $this->unavailable( 'Could not replace the encrypted Booster secrets file.' );
			}
			$temporary = '';
		} finally {
			if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
				unlink( $temporary );
			}
		}
	}

	private function restorePreviousCiphertext(
		#[\SensitiveParameter] ?string $previousContents,
		#[\SensitiveParameter] string $key
	): void {
		if ( null !== $previousContents ) {
			$this->replaceCiphertext( $previousContents );
			clearstatcache( true, $this->path );
			$this->readEncryptedDocument( $key );
			return;
		}

		$stat = lstat( $this->path );
		if ( false === $stat
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| 1 !== $stat['nlink']
			|| ! $this->ownedByProcess( $stat )
			|| ! unlink( $this->path )
		) {
			throw $this->unavailable( 'Could not remove the failed encrypted Booster secrets file.' );
		}
		clearstatcache( true, $this->path );
	}

	/** @param array<string, mixed> $document */
	private function encodeCanonicalDocument( #[\SensitiveParameter] array $document ): string {
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
	private function ownedByProcess( array $stat ): bool {
		$effectiveUserId = $this->effectiveUserId();

		return null !== $effectiveUserId
			&& isset( $stat['uid'] )
			&& $stat['uid'] === $effectiveUserId;
	}

	protected function effectiveUserId(): ?int {
		return function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	}

	/**
	 * @param resource $handle
	 */
	protected function writeHandle( mixed $handle, #[\SensitiveParameter] string $contents ): int|false {
		return fwrite( $handle, $contents );
	}

	private function lockPath(): string {
		return (string) $this->path . '.lock';
	}

	/**
	 * @return array{dev:int,ino:int,mode:int,uid:int,nlink:int}
	 */
	private function deletableFileStat( string $path, string $label ): array {
		clearstatcache( true, $path );
		$stat = lstat( $path );
		if ( false === $stat
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| 1 !== $stat['nlink']
			|| 0600 !== ( $stat['mode'] & 0777 )
			|| ! $this->ownedByProcess( $stat )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem labels are fixed at each call site.
			throw $this->unavailable( sprintf( 'Refusing to remove an invalid %s.', $label ) );
		}

		return $stat;
	}

	/**
	 * @param array{dev:int,ino:int,mode:int,uid:int,nlink:int} $expected
	 */
	private function deleteExactFile( string $path, array $expected, string $message ): void {
		clearstatcache( true, $path );
		$current = lstat( $path );
		if ( false === $current
			|| $expected['dev'] !== $current['dev']
			|| $expected['ino'] !== $current['ino']
			|| $expected['mode'] !== $current['mode']
			|| $expected['uid'] !== $current['uid']
			|| $expected['nlink'] !== $current['nlink']
			|| ! $this->removeFile( $path )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal filesystem failure messages are fixed at each call site.
			throw $this->unavailable( $message );
		}
		clearstatcache( true, $path );
	}

	private function incompleteStore( #[\SensitiveParameter] ?string $key, bool $hasFile ): SecretsStorageUnavailable {
		if ( $hasFile && null === $key ) {
			return $this->unavailable(
				'The encrypted Booster secrets store is incomplete: secrets.json exists but its database key is missing.',
				'storage_key_missing'
			);
		}
		if ( ! $hasFile && null !== $key ) {
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

	private function loadKey( bool $repairAutoload = true ): ?string {
		try {
			return $this->keyStore->load( $repairAutoload );
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster site key is unavailable.' );
		}
	}

	/** @return array{key:string,created:bool} */
	private function loadOrCreateKey(): array {
		try {
			return $this->keyStore->loadOrCreate();
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The Booster site key could not be initialized.' );
		}
	}

	private function deleteExactKey( #[\SensitiveParameter] string $key ): void {
		try {
			if ( ! $this->keyStore->deleteExact( $key ) ) {
				throw $this->unavailable( 'The failed Booster site key could not be removed safely.' );
			}
		} catch ( SecretsStorageUnavailable $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw $this->unavailable( 'The failed Booster site key could not be removed safely.' );
		}
	}

	private function deleteManagedKey( #[\SensitiveParameter] string $key ): void {
		try {
			if ( $this->keyStore->deleteExact( $key ) ) {
				return;
			}

			$remaining = $this->keyStore->load( false );
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
	protected function changePermissions( string $path, int $mode ): bool {
		return chmod( $path, $mode );
	}

	protected function replaceFile( string $source, string $destination ): bool {
		return rename( $source, $destination );
	}

	protected function removeFile( string $path ): bool {
		return unlink( $path );
	}
}

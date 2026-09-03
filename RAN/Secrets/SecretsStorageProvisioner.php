<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Native metadata reads verify local inode, permission and device boundaries.
// phpcs:disable WordPress.WP.AlternativeFunctions

use Throwable;

/**
 * Composes private-location discovery, destructive POST-only probing and the
 * constrained wp-config.php writer.
 *
 * status() is metadata-only. provision() is the sole mutation entrypoint and
 * reports bounded codes without logging paths or filesystem exceptions.
 */
class SecretsStorageProvisioner {

	private const DIRECTORY_CONSTANT_NAME = 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR';
	private const FILE_CONSTANT_NAME      = 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE';
	private const RECOVERY_MAX_ENTRIES    = 64;
	public const RESET_CONFIRMATION       = 'RESET STORAGE';

	public function __construct(
		private readonly PrivateLocationCandidateResolver $resolver = new PrivateLocationCandidateResolver(),
		private readonly PosixFilesystemProbe $probe = new PosixFilesystemProbe(),
		private readonly WpConfigSecretsPathWriter $writer = new WpConfigSecretsPathWriter(),
		private readonly ?SecretsFile $secrets = null
	) {
	}

	public function status(): SecretsStorageProvisioningResult {
		$environment = $this->runtimeFailure();
		if ( null !== $environment ) {
			return $environment;
		}

		$configured = $this->configuredPath();
		if ( false === $configured ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'configured_path_invalid',
				__( 'The encrypted secrets path constant is not a valid absolute path.', 'ran-booster' )
			);
		}
		if ( is_string( $configured ) ) {
			$pathIsSafe = $this->validateConfiguredCandidate( $configured );
			$source     = $this->configuredPathSource( $configured, $pathIsSafe );
			if ( ! $pathIsSafe ) {
				return SecretsStorageProvisioningResult::storageNeedsAttention(
					$configured,
					$source,
					'configured_path_unsafe',
					__( 'The resolved configured storage directory is not a verified private location. It may be inside the public web root, use a symbolic link or cross another unsafe path boundary. Choose a real local directory outside the public web root.', 'ran-booster' )
				);
			}

			$pathFailure = $this->inspectReadyPath( $configured );
			if ( null !== $pathFailure ) {
				return SecretsStorageProvisioningResult::storageNeedsAttention(
					$configured,
					$source,
					$pathFailure['code'],
					$pathFailure['message']
				);
			}

			try {
				return $this->managedStorageHealthy()
					? SecretsStorageProvisioningResult::storageHealthy( $configured, $source )
					: SecretsStorageProvisioningResult::pathConfigured( $configured, $source );
			} catch ( SecretsStorageUnavailable $failure ) {
				$diagnostic = $this->managedStorageDiagnostic( $failure );

				return SecretsStorageProvisioningResult::storageNeedsAttention(
					$configured,
					$source,
					$diagnostic['code'],
					$diagnostic['message']
				);
			} catch ( Throwable ) {
				return SecretsStorageProvisioningResult::storageNeedsAttention( $configured, $source );
			}
		}

		if ( ! $this->supportedAutomaticPlatform() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'local_posix_unavailable',
				__( 'Automatic secure storage setup requires a direct local POSIX filesystem.', 'ran-booster' )
			);
		}

		$discarded = array();
		try {
			$candidate = $this->resolveCandidate( $discarded );
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				__( 'Booster could not determine a safe private storage location.', 'ran-booster' ),
				null,
				$discarded
			);
		}
		if ( null === $candidate ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				__( 'Booster could not determine a safe private storage location.', 'ran-booster' ),
				null,
				$discarded
			);
		}

		if ( null === $this->loadedWpConfigPath() ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_unavailable',
				__( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ),
				$candidate
			);
		}

		return SecretsStorageProvisioningResult::setupAvailable( $candidate );
	}

	public function provision(): SecretsStorageProvisioningResult {
		$status = $this->status();
		if ( ! $status->canProvisionAutomatically() ) {
			return $status;
		}

		$candidate = $status->candidatePath();
		$config    = $this->loadedWpConfigPath();
		if ( null === $candidate || null === $config ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_unavailable',
				__( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ),
				$candidate
			);
		}

		try {
			$passed = $this->probeCandidate( $candidate );
		} catch ( Throwable ) {
			$passed = false;
		}
		if ( ! $passed ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'filesystem_probe_failed',
				__( 'The private storage filesystem did not pass Booster\'s safety checks.', 'ran-booster' ),
				$candidate
			);
		}
		if ( ! $this->validateConfiguredCandidate( $candidate ) ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'candidate_path_unsafe',
				__( 'The private storage location changed or did not pass Booster\'s final path-safety check.', 'ran-booster' ),
				$candidate
			);
		}

		if ( ! $this->sameFilesystemDevice( dirname( $candidate ), $config ) ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'filesystem_device_mismatch',
				__( 'The private storage location and WordPress configuration are not on one verified local filesystem.', 'ran-booster' ),
				$candidate
			);
		}

		try {
			$result = $this->writeConfiguration( $config, $candidate );
		} catch ( WpConfigPathWriteException $exception ) {
			return SecretsStorageProvisioningResult::manualRequired(
				$this->stableCode( $exception->reason(), 'wp_config_write_failed' ),
				$exception->getMessage(),
				$candidate
			);
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_write_failed',
				__( 'The WordPress configuration could not be updated safely.', 'ran-booster' ),
				$candidate
			);
		}

		if ( ! $result->requiresNextRequestVerification() ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_verification_unavailable',
				__( 'Booster could not require a fresh WordPress configuration check.', 'ran-booster' ),
				$candidate
			);
		}

		return SecretsStorageProvisioningResult::pendingVerification( $candidate );
	}

	/**
	 * Find authenticated sibling storage without mutating or weakening policy.
	 *
	 * @return array{
	 *     state: 'available'|'blocked'|'ambiguous'|'reset_available',
	 *     message: string,
	 *     candidate_path: string|null,
	 *     token: string|null,
	 *     confirmation: string|null
	 * }|null
	 */
	public function recoveryState( SecretsStorageProvisioningResult $status ): ?array {
		$current           = $status->candidatePath();
		$missingCiphertext = null !== $current && $this->currentCiphertextIsAbsent( $current );
		$missingKey        = 'storage_key_missing' === $status->code();
		if ( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION !== $status->status()
			|| null === $current
			|| ( ! $missingCiphertext && ! $missingKey )
		) {
			return null;
		}

		$scanComplete = true;
		$candidates   = $this->recoveryCandidates( $current, $scanComplete );
		if ( ! $scanComplete ) {
			return array(
				'state'          => 'blocked',
				'message'        => __(
					'Booster found plausible prior storage material that it could not inspect completely. Restore or review that material manually before resetting credential storage.',
					'ran-booster'
				),
				'candidate_path' => null,
				'token'          => null,
				'confirmation'   => null,
			);
		}
		if ( array() === $candidates ) {
			try {
				if ( $missingKey && $this->orphanedCiphertextResetAvailable( $current ) ) {
					return array(
						'state'          => 'reset_available',
						'message'        => __(
							'Booster found encrypted credential storage without its matching database key. Restore the matching database key if possible, or explicitly discard the unauthenticated ciphertext and start with empty credential storage.',
							'ran-booster'
						),
						'candidate_path' => null,
						'token'          => null,
						'confirmation'   => self::RESET_CONFIRMATION,
					);
				}
				if ( $missingCiphertext && $this->orphanedKeyResetAvailable( $current ) ) {
					return array(
						'state'          => 'reset_available',
						'message'        => __(
							'Booster found a database encryption key without its matching encrypted file. Restore the matching file if possible, or explicitly reset this empty credential store.',
							'ran-booster'
						),
						'candidate_path' => null,
						'token'          => null,
						'confirmation'   => self::RESET_CONFIRMATION,
					);
				}
			} catch ( Throwable ) {
				return null;
			}

			return null;
		}
		if ( $missingKey ) {
			return array(
				'state'          => 'blocked',
				'message'        => __(
					'Booster found prior storage material, but no database key is available to authenticate it. Restore the matching database key before adopting or resetting storage.',
					'ran-booster'
				),
				'candidate_path' => null,
				'token'          => null,
				'confirmation'   => null,
			);
		}
		if ( 1 !== count( $candidates ) ) {
			return array(
				'state'          => 'ambiguous',
				'message'        => __(
					'Booster found more than one authenticated prior storage set. Choose and configure the correct private location manually.',
					'ran-booster'
				),
				'candidate_path' => null,
				'token'          => null,
				'confirmation'   => null,
			);
		}

		$candidate  = $candidates[0];
		$config     = $this->loadedWpConfigPath();
		$owned      = false;
		$sameDevice = false;
		try {
			$owned      = null !== $config && $this->writer->assertOwnedDefinitionRemovable( $config, $current );
			$sameDevice = null !== $config && $this->sameFilesystemDevice( dirname( $candidate['candidate_path'] ), $config );
		} catch ( Throwable ) {
			$owned = false;
		}

		if ( ! $candidate['safe'] || ! $candidate['fit'] || ! $owned || ! $sameDevice ) {
			$message = match ( true ) {
				! $candidate['safe'] => __( 'Booster authenticated prior credential storage, but its location does not pass the current private-path policy. Move the matching storage set to a verified private location before using it.', 'ran-booster' ),
				! $candidate['fit'] => __( 'Booster authenticated prior credential storage, but one or more credentials do not pass their current provider policy. Review or restore the matching storage set before using it.', 'ran-booster' ),
				! $owned => __( 'Booster authenticated prior credential storage, but the active wp-config.php definition is operator-managed. Configure the verified private path manually.', 'ran-booster' ),
				default => __( 'Booster authenticated prior credential storage, but it crosses an unsupported filesystem boundary. Configure the verified private path manually.', 'ran-booster' ),
			};

			return array(
				'state'          => 'blocked',
				'message'        => $message,
				'candidate_path' => null,
				'token'          => null,
				'confirmation'   => null,
			);
		}

		return array(
			'state'          => 'available',
			'message'        => __( 'Booster found one prior storage set that authenticates with this site\'s database key and whose credentials pass their current provider policies.', 'ran-booster' ),
			'candidate_path' => $candidate['candidate_path'],
			'token'          => $candidate['token'],
			'confirmation'   => null,
		);
	}

	public function resetOrphanedStorage( string $confirmation ): SecretsStorageProvisioningResult {
		$status  = $this->status();
		$current = $status->candidatePath();
		$source  = $status->pathSource();
		if ( null === $current
			|| null === $source
			|| ! hash_equals( self::RESET_CONFIRMATION, $confirmation )
		) {
			return $this->resetFailure( $status, 'storage_reset_request_invalid', __( 'The empty-storage reset request is invalid. Review the current storage state and try again.', 'ran-booster' ) );
		}

		$offer = $this->recoveryState( $status );
		if ( null === $offer || 'reset_available' !== $offer['state'] ) {
			return $this->resetFailure( $status, 'storage_reset_state_changed', __( 'The credential storage state changed and was not reset. Review it again before continuing.', 'ran-booster' ) );
		}

		try {
			if ( 'storage_key_missing' === $status->code() ) {
				$this->resetOrphanedCiphertext( $current );
			} else {
				$this->resetOrphanedKey( $current );
			}
		} catch ( Throwable ) {
			return $this->resetFailure( $status, 'storage_reset_failed', __( 'The incomplete credential storage could not be reset safely. No storage reset was confirmed.', 'ran-booster' ) );
		}

		return SecretsStorageProvisioningResult::storageReset( $current, $source );
	}

	public function adoptRecovery( string $token ): SecretsStorageProvisioningResult {
		$status  = $this->status();
		$current = $status->candidatePath();
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token ) || null === $current ) {
			return $this->recoveryFailure( $current, 'recovery_request_invalid', __( 'The storage recovery request is invalid. Review the current storage state and try again.', 'ran-booster' ) );
		}

		$offer = $this->recoveryState( $status );
		if ( null === $offer
			|| 'available' !== $offer['state']
			|| ! is_string( $offer['candidate_path'] )
			|| ! is_string( $offer['token'] )
			|| ! hash_equals( $offer['token'], $token )
		) {
			return $this->recoveryFailure( $current, 'recovery_candidate_changed', __( 'The recoverable storage candidate is no longer uniquely safe and authenticated. Review the current storage state again.', 'ran-booster' ) );
		}

		$config = $this->loadedWpConfigPath();
		if ( null === $config ) {
			return $this->recoveryFailure( $current, 'wp_config_unavailable', __( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ) );
		}

		try {
			$result = $this->retargetConfiguration( $config, $current, $offer['candidate_path'] );
		} catch ( WpConfigPathWriteException $exception ) {
			return $this->recoveryFailure(
				$current,
				$this->stableCode( $exception->reason(), 'recovery_write_failed' ),
				$exception->getMessage()
			);
		} catch ( Throwable ) {
			return $this->recoveryFailure( $current, 'recovery_write_failed', __( 'The recoverable storage path could not be adopted safely.', 'ran-booster' ) );
		}

		return false !== $result && $result->requiresNextRequestVerification()
			? SecretsStorageProvisioningResult::pendingVerification( $offer['candidate_path'] )
			: $this->recoveryFailure( $current, 'wp_config_verification_unavailable', __( 'Booster could not require a fresh WordPress configuration check.', 'ran-booster' ) );
	}

	/** @param list<array{directory:string,code:string,reason:string,component:string|null}>|null $discarded */
	protected function resolveCandidate( ?array &$discarded = null ): ?string {
		return $this->resolver->resolve(
			$this->wordpressRoot(),
			$this->contentDirectory(),
			$this->pluginDirectory(),
			$this->documentRoot(),
			$discarded
		);
	}

	protected function validateConfiguredCandidate( string $candidate ): bool {
		return $this->resolver->validateConfigured(
			$candidate,
			$this->wordpressRoot(),
			$this->contentDirectory(),
			$this->pluginDirectory(),
			$this->documentRoot()
		);
	}

	protected function probeCandidate( string $candidate ): bool {
		return $this->probe->probe( $candidate );
	}

	protected function writeConfiguration( string $config, string $candidate ): WpConfigPathWriteResult {
		return $this->writer->write( $config, $candidate );
	}

	protected function retargetConfiguration(
		string $config,
		string $current,
		string $replacement
	): WpConfigPathWriteResult|false {
		return $this->writer->retargetOwnedDefinition( $config, $current, $replacement );
	}

	protected function recoveryCredentialsFit( string $candidate ): bool {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		return $this->secrets->recoveryCredentialsFitAt( $candidate );
	}

	protected function orphanedKeyResetAvailable( string $current ): bool {
		return null !== $this->secrets && $this->secrets->canResetOrphanedKeyAt( $current );
	}

	protected function resetOrphanedKey( string $current ): void {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		$this->secrets->resetOrphanedKeyAt( $current );
	}

	protected function orphanedCiphertextResetAvailable( string $current ): bool {
		return null !== $this->secrets && $this->secrets->canResetOrphanedCiphertextAt( $current );
	}

	protected function resetOrphanedCiphertext( string $current ): void {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		$this->secrets->resetOrphanedCiphertextAt( $current );
	}

	protected function wordpressRoot(): string {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '';
	}

	protected function contentDirectory(): string {
		return defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) ? WP_CONTENT_DIR : '';
	}

	protected function pluginDirectory(): string {
		$path = realpath( dirname( __DIR__, 2 ) );

		return false === $path ? dirname( __DIR__, 2 ) : $path;
	}

	protected function documentRoot(): ?string {
		$root = $_SERVER['DOCUMENT_ROOT'] ?? null;

		return is_string( $root ) && '' !== trim( $root ) ? $root : null;
	}

	/** @return list<string> */
	protected function includedFiles(): array {
		return get_included_files();
	}

	/** @return string|false|null False means defined with an invalid value. */
	protected function configuredPath(): string|false|null {
		if ( defined( self::DIRECTORY_CONSTANT_NAME ) ) {
			$value = constant( self::DIRECTORY_CONSTANT_NAME );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return false;
			}

			$directory = '/' === $value ? '/' : rtrim( $value, '/' );
			$path      = $directory . ( '/' === $directory ? '' : '/' ) . 'secrets.json';

			return $this->absoluteCanonicalPath( $path ) ? $path : false;
		}
		if ( ! defined( self::FILE_CONSTANT_NAME ) ) {
			return null;
		}

		$value = constant( self::FILE_CONSTANT_NAME );

		return is_string( $value ) && $this->absoluteCanonicalPath( $value ) ? $value : false;
	}

	protected function isMultisiteInstallation(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	protected function sodiumAvailable(): bool {
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
	}

	protected function supportedLocalPlatform(): bool {
		if ( ! $this->supportedPosixPlatform() ) {
			return false;
		}
		if ( defined( 'FS_METHOD' ) && 'direct' !== constant( 'FS_METHOD' ) ) {
			return false;
		}

		$root = $this->wordpressRoot();

		return '' !== $root && stream_is_local( $root );
	}

	protected function managedStorageHealthy(): bool {
		return null !== $this->secrets && $this->secrets->hasHealthyManagedStorage();
	}

	private function supportedPosixPlatform(): bool {
		if ( 'Windows' === PHP_OS_FAMILY
			|| '\\' === DIRECTORY_SEPARATOR
			|| ! function_exists( 'flock' )
			|| ! function_exists( 'posix_geteuid' )
		) {
			return false;
		}

		return true;
	}

	private function supportedAutomaticPlatform(): bool {
		return $this->supportedLocalPlatform();
	}

	private function runtimeFailure(): ?SecretsStorageProvisioningResult {
		if ( ! $this->sodiumAvailable() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'sodium_unavailable',
				'The Sodium extension is required for encrypted secrets storage.'
			);
		}
		if ( $this->isMultisiteInstallation() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'multisite_unsupported',
				'Encrypted file-backed secrets storage is not available on multisite in this Beta release.'
			);
		}
		if ( ! $this->supportedPosixPlatform() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'local_posix_unavailable',
				'Encrypted secrets storage requires a local POSIX environment.'
			);
		}

		return null;
	}

	private function configuredPathSource( string $configured, bool $pathIsSafe ): string {
		try {
			$config = $this->loadedWpConfigPath();
			if ( null !== $config && $this->writer->hasOwnedDefinition( $config, $configured ) ) {
				return SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC;
			}
			if ( $pathIsSafe && $configured === $this->resolveCandidate() ) {
				return SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC;
			}
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
		}

		return SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
	}

	/** @return list<array{candidate_path:string,token:string,safe:bool,fit:bool}> */
	private function recoveryCandidates( string $current, ?bool &$complete = null ): array {
		$complete = true;
		$base     = $this->automaticStorageBase( $current );
		if ( null === $base ) {
			return array();
		}
		if ( ! is_dir( $base ) || is_link( $base ) || ! stream_is_local( $base ) ) {
			$complete = false;

			return array();
		}

		$entries = scandir( $base );
		if ( false === $entries || count( $entries ) > self::RECOVERY_MAX_ENTRIES + 2 ) {
			$complete = false;

			return array();
		}

		$candidates = array();
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry )
				|| 1 !== preg_match( '/\A[a-f0-9]{16}\z/D', $entry )
				|| dirname( $current ) === $base . '/' . $entry
			) {
				continue;
			}

			$directory = $base . '/' . $entry;
			if ( is_link( $directory ) || ! is_dir( $directory ) ) {
				$complete = false;

				continue;
			}
			$candidate = $directory . '/secrets.json';
			$lock      = $candidate . '.lock';
			if ( ! file_exists( $candidate )
				&& ! is_link( $candidate )
				&& ! file_exists( $lock )
				&& ! is_link( $lock )
			) {
				continue;
			}
			try {
				if ( null !== $this->inspectReadyPath( $candidate ) ) {
					$complete = false;

					continue;
				}
				$fit      = $this->recoveryCredentialsFit( $candidate );
				$revision = $this->recoveryRevision( $candidate );
				if ( null === $revision ) {
					$complete = false;

					continue;
				}
				$candidates[] = array(
					'candidate_path' => $candidate,
					'token'          => $revision,
					'safe'           => $this->validateConfiguredCandidate( $candidate ),
					'fit'            => $fit,
				);
			} catch ( Throwable ) {
				$complete = false;

				continue;
			}
		}

		return $candidates;
	}

	private function automaticStorageBase( string $current ): ?string {
		$directory = dirname( $current );
		$base      = dirname( $directory );

		return 'secrets.json' === basename( $current )
			&& 1 === preg_match( '/\A[a-f0-9]{16}\z/D', basename( $directory ) )
			&& '.ran-booster' === basename( $base )
			? $base
			: null;
	}

	protected function currentCiphertextIsAbsent( string $current ): bool {
		if ( file_exists( $current ) || is_link( $current ) ) {
			return false;
		}
		if ( null === $this->secrets ) {
			return ! file_exists( $current . '.lock' ) && ! is_link( $current . '.lock' );
		}

		try {
			return $this->secrets->canRecoverFromMissingCiphertextAt( $current );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function recoveryRevision( string $candidate ): ?string {
		$parts = array( $candidate );
		foreach ( array( dirname( $candidate ), $candidate, $candidate . '.lock' ) as $path ) {
			$stat = lstat( $path );
			if ( false === $stat ) {
				return null;
			}
			foreach ( array( 'dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime' ) as $key ) {
				$parts[] = (string) $stat[ $key ];
			}
		}

		return hash( 'sha256', implode( "\0", $parts ) );
	}

	private function recoveryFailure( ?string $current, string $code, string $message ): SecretsStorageProvisioningResult {
		return null === $current
			? SecretsStorageProvisioningResult::manualRequired( $code, $message )
			: SecretsStorageProvisioningResult::storageNeedsAttention(
				$current,
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL,
				$code,
				$message
			);
	}

	private function resetFailure( SecretsStorageProvisioningResult $status, string $code, string $message ): SecretsStorageProvisioningResult {
		$current = $status->candidatePath();
		$source  = $status->pathSource();

		return null === $current || null === $source
			? SecretsStorageProvisioningResult::manualRequired( $code, $message )
			: SecretsStorageProvisioningResult::storageNeedsAttention( $current, $source, $code, $message );
	}

	/** @return array{code: string, message: string}|null */
	private function inspectReadyPath( string $candidate ): ?array {
		$directory = dirname( $candidate );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return $this->pathFailure(
				'storage_directory_unavailable',
				__( 'PHP cannot see the configured storage directory. It may be absent, or PHP may lack execute/traverse permission on a parent directory. Keep the storage directory itself owner-only with mode 0700.', 'ran-booster' )
			);
		}
		if ( is_link( $directory ) || ! is_dir( $directory ) || ! stream_is_local( $directory ) ) {
			return $this->pathFailure(
				'storage_directory_invalid',
				__( 'The configured secrets directory must be a real directory on a supported local filesystem, not a symbolic link.', 'ran-booster' )
			);
		}

		$stat = lstat( $directory );
		if ( false === $stat || 0040000 !== ( $stat['mode'] & 0170000 ) ) {
			return $this->pathFailure(
				'storage_directory_inspection_failed',
				__( 'Booster could not verify the configured secrets directory.', 'ran-booster' )
			);
		}
		$issues = $this->accessIssues( $directory, $stat, 0700, 'directory' );
		if ( array() !== $issues ) {
			return $this->pathFailure(
				'storage_directory_unusable',
				implode( ' ', $issues )
			);
		}

		if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
			return null;
		}
		if ( is_link( $candidate ) || ! is_file( $candidate ) ) {
			return $this->pathFailure(
				'storage_file_invalid',
				__( 'The configured secrets file must be a regular file, not a symbolic link.', 'ran-booster' )
			);
		}
		$file = lstat( $candidate );
		if ( false === $file || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return $this->pathFailure(
				'storage_file_inspection_failed',
				__( 'Booster could not verify the configured secrets file.', 'ran-booster' )
			);
		}
		$issues = $this->accessIssues( $candidate, $file, 0600, 'file' );
		if ( 1 !== $file['nlink'] ) {
			$issues[] = __( 'The configured secrets file has additional hard links.', 'ran-booster' );
		}
		if ( array() !== $issues ) {
			return $this->pathFailure(
				'storage_file_unusable',
				implode( ' ', $issues )
			);
		}

		$lock = $candidate . '.lock';
		if ( ! file_exists( $lock ) && ! is_link( $lock ) ) {
			return $this->pathFailure(
				'storage_lock_missing',
				__( 'The secrets file exists, but its matching lock file is missing. Restore the matching storage set from one backup.', 'ran-booster' )
			);
		}
		if ( is_link( $lock ) || ! is_file( $lock ) ) {
			return $this->pathFailure(
				'storage_lock_invalid',
				__( 'The configured secrets lock must be a regular file, not a symbolic link.', 'ran-booster' )
			);
		}
		$lockStat = lstat( $lock );
		if ( false === $lockStat || 0100000 !== ( $lockStat['mode'] & 0170000 ) ) {
			return $this->pathFailure(
				'storage_lock_inspection_failed',
				__( 'Booster could not verify the configured secrets lock file.', 'ran-booster' )
			);
		}
		$issues = $this->accessIssues( $lock, $lockStat, 0600, 'lock file' );
		if ( 1 !== $lockStat['nlink'] ) {
			$issues[] = __( 'The configured secrets lock file has additional hard links.', 'ran-booster' );
		}
		if ( array() !== $issues ) {
			return $this->pathFailure(
				'storage_lock_unusable',
				implode( ' ', $issues )
			);
		}

		return null;
	}

	/**
	 * @param array{mode: int, uid: int} $stat
	 * @return list<string>
	 */
	private function accessIssues( string $path, array $stat, int $requiredMode, string $label ): array {
		$displayLabel = match ( $label ) {
			'directory' => _x( 'directory', 'Configured secrets storage item', 'ran-booster' ),
			'file' => _x( 'file', 'Configured secrets storage item', 'ran-booster' ),
			'lock file' => _x( 'lock file', 'Configured secrets storage item', 'ran-booster' ),
			default => $label,
		};
		$issues = array();
		$mode   = $stat['mode'] & 0777;
		if ( $requiredMode !== $mode ) {
			$issues[] = sprintf(
				/* translators: 1: configured secrets storage item, 2: current octal mode, 3: required octal mode. */
				__( 'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.', 'ran-booster' ),
				$displayLabel,
				$mode,
				$requiredMode
			);
		}
		if ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== $stat['uid'] ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not owned by the PHP process user.', 'ran-booster' ),
				$displayLabel
			);
		}
		if ( ! is_readable( $path ) ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not readable by PHP.', 'ran-booster' ),
				$displayLabel
			);
		}
		if ( ! is_writable( $path ) ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not writable by PHP.', 'ran-booster' ),
				$displayLabel
			);
		}

		return $issues;
	}

	/** @return array{code: string, message: string} */
	private function pathFailure( string $code, string $message ): array {
		return compact( 'code', 'message' );
	}

	/** @return array{code: string, message: string} */
	private function managedStorageDiagnostic( SecretsStorageUnavailable $failure ): array {
		if ( SecretsStorageUnavailable::REASON_GENERIC !== $failure->reason() ) {
			return match ( $failure->reason() ) {
				'storage_key_missing' => $this->pathFailure(
					'storage_key_missing',
					__( 'secrets.json and secrets.json.lock exist, but the matching database encryption key is missing. Restore the file and database key from the same backup; Booster will not delete unauthenticated ciphertext.', 'ran-booster' )
				),
				'storage_file_missing' => $this->pathFailure(
					'storage_file_missing',
					__( 'The database encryption key exists, but secrets.json is missing. Restore the matching encrypted file from the same backup before using or uninstalling Booster.', 'ran-booster' )
				),
				'storage_orphan_lock' => $this->pathFailure(
					'storage_orphan_lock',
					__( 'Only secrets.json.lock remains; no secrets file or database encryption key was found.', 'ran-booster' )
				),
				'storage_lock_missing' => $this->pathFailure(
					'storage_lock_missing',
					__( 'Managed secrets material exists, but secrets.json.lock is missing. Restore the matching storage set from one backup.', 'ran-booster' )
				),
				default => $this->pathFailure(
					$failure->reason(),
					__( 'Booster could not safely use the encrypted secrets store.', 'ran-booster' )
				),
			};
		}

		return match ( $failure->getMessage() ) {
			'The encrypted Booster secrets store is incomplete.',
			'The encrypted Booster secrets store is incomplete because its lock is missing.',
			'The encrypted Booster secrets store is missing its lock.' => $this->pathFailure(
				'storage_incomplete',
				__( 'The secrets file, lock file and database key are incomplete. Restore the matching set from one backup or reset empty storage.', 'ran-booster' )
			),
			'The encrypted Booster secrets document could not be authenticated.' => $this->pathFailure(
				'storage_authentication_failed',
				__( 'The secrets file could not be authenticated with this site\'s database key. Restore both from the same backup.', 'ran-booster' )
			),
			'The encrypted Booster secrets payload is invalid.',
			'The encrypted Booster secrets payload is not canonical.' => $this->pathFailure(
				'storage_document_invalid',
				__( 'The secrets file authenticated but its encrypted document is invalid.', 'ran-booster' )
			),
			'The Booster site key is unavailable.' => $this->pathFailure(
				'storage_key_unavailable',
				__( 'Booster could not read the database-held encryption key. Restore the database and encrypted files from the same backup.', 'ran-booster' )
			),
			'The encrypted Booster secrets file is not readable.',
			'The encrypted Booster secrets file is not a secure bounded file.',
			'The encrypted Booster secrets file could not be read safely.' => $this->pathFailure(
				'storage_file_unusable',
				__( 'The secrets file could not be read safely. Verify its ownership, mode 0600 and that it is a non-empty Booster-managed file.', 'ran-booster' )
			),
			'Refusing to use an invalid encrypted Booster secrets lock.',
			'Could not open the encrypted Booster secrets lock.',
			'Could not inspect the encrypted Booster secrets lock.',
			'Could not secure the encrypted Booster secrets lock.',
			'Could not lock the encrypted Booster secrets store.' => $this->pathFailure(
				'storage_lock_unusable',
				__( 'The secrets lock file could not be used safely. Verify its ownership and mode 0600.', 'ran-booster' )
			),
			default => $this->pathFailure(
				'storage_unavailable',
				__( 'Booster could not classify the storage failure. Verify PHP owns the directories, secrets.json and secrets.json.lock; directories require mode 0700 and both files require mode 0600.', 'ran-booster' )
			),
		};
	}

	private function loadedWpConfigPath(): ?string {
		$root = $this->canonicalDirectory( $this->wordpressRoot() );
		if ( null === $root ) {
			return null;
		}

		$supported = array();
		$inRoot    = $this->canonicalRegularFile( $root . '/wp-config.php' );
		if ( null !== $inRoot ) {
			$supported[] = $inRoot;
		}

		$parent = dirname( $root );
		if ( ! is_file( $parent . '/wp-settings.php' ) ) {
			$aboveRoot = $this->canonicalRegularFile( $parent . '/wp-config.php' );
			if ( null !== $aboveRoot ) {
				$supported[] = $aboveRoot;
			}
		}
		$supported = array_values( array_unique( $supported ) );

		$loaded = array();
		foreach ( $this->includedFiles() as $included ) {
			if ( ! is_string( $included ) || 'wp-config.php' !== basename( $included ) ) {
				continue;
			}
			$canonical = $this->canonicalRegularFile( $included );
			if ( null === $canonical ) {
				return null;
			}
			$loaded[] = $canonical;
		}
		$loaded = array_values( array_unique( $loaded ) );

		return 1 === count( $loaded ) && in_array( $loaded[0], $supported, true )
			? $loaded[0]
			: null;
	}

	private function canonicalDirectory( string $path ): ?string {
		if ( '' === trim( $path ) || ! stream_is_local( $path ) ) {
			return null;
		}
		$real = realpath( $path );

		return false !== $real && is_dir( $real ) ? rtrim( $real, '/' ) : null;
	}

	private function canonicalRegularFile( string $path ): ?string {
		if ( is_link( $path ) || ! is_file( $path ) || ! stream_is_local( $path ) ) {
			return null;
		}
		$real = realpath( $path );
		if ( false === $real || $this->normalizePath( $path ) !== $this->normalizePath( $real ) ) {
			return null;
		}

		return $real;
	}

	private function sameFilesystemDevice( string $candidateDirectory, string $config ): bool {
		if ( ! is_dir( $candidateDirectory ) || ! is_file( $config ) ) {
			return false;
		}
		$directoryStat = stat( $candidateDirectory );
		$configStat    = stat( $config );

		return false !== $directoryStat
			&& false !== $configStat
			&& isset( $directoryStat['dev'], $configStat['dev'] )
			&& $directoryStat['dev'] === $configStat['dev'];
	}

	private function absoluteCanonicalPath( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_ends_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, "\r" )
			&& ! str_contains( $path, "\n" )
			&& 1 !== preg_match( '#/(?:\.{1,2})(?:/|$)#', $path )
			&& ! str_contains( $path, '//' );
	}

	private function normalizePath( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	private function stableCode( mixed $code, string $fallback ): string {
		return is_string( $code ) && preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) === 1
			? $code
			: $fallback;
	}
}

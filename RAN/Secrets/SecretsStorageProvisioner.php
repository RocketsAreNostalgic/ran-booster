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

	private const DIRECTORY_CONSTANT_NAME        = 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR';
	private const UNSUPPORTED_FILE_CONSTANT_NAME = 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE';
	private const RECOVERY_MAX_ENTRIES           = 64;
	public const RESET_CONFIRMATION              = 'RESET STORAGE';

	public function __construct(
		private readonly PrivateLocationCandidateResolver $resolver = new PrivateLocationCandidateResolver(),
		private readonly PosixFilesystemProbe $probe = new PosixFilesystemProbe(),
		private readonly WpConfigSecretsPathWriter $writer = new WpConfigSecretsPathWriter(),
		private readonly ?SecretsFile $secrets = null
	) {
	}

	public function status(): SecretsStorageProvisioningResult {
		$environment = $this->runtime_failure();
		if ( null !== $environment ) {
			return $environment;
		}

		$configured = $this->configured_path();
		if ( false === $configured ) {
			return SecretsStorageProvisioningResult::manual_required(
				'configured_path_invalid',
				__( 'The encrypted secrets path constant is not a valid absolute path.', 'ran-booster' )
			);
		}
		if ( is_string( $configured ) ) {
			$path_is_safe = $this->validate_configured_candidate( $configured );
			$source       = $this->configured_path_source( $configured, $path_is_safe );
			if ( ! $path_is_safe ) {
				return SecretsStorageProvisioningResult::storage_needs_attention(
					$configured,
					$source,
					'configured_path_unsafe',
					__( 'The resolved configured storage directory is not a verified private location. It may be inside the public web root, use a symbolic link or cross another unsafe path boundary. Choose a real local directory outside the public web root.', 'ran-booster' )
				);
			}

			$path_failure = $this->inspect_ready_path( $configured );
			if ( null !== $path_failure ) {
				return SecretsStorageProvisioningResult::storage_needs_attention(
					$configured,
					$source,
					$path_failure['code'],
					$path_failure['message']
				);
			}

			try {
				return $this->managed_storage_healthy()
					? SecretsStorageProvisioningResult::storage_healthy( $configured, $source )
					: SecretsStorageProvisioningResult::path_configured( $configured, $source );
			} catch ( SecretsStorageUnavailable $failure ) {
				$diagnostic = $this->managed_storage_diagnostic( $failure );

				return SecretsStorageProvisioningResult::storage_needs_attention(
					$configured,
					$source,
					$diagnostic['code'],
					$diagnostic['message']
				);
			} catch ( Throwable ) {
				return SecretsStorageProvisioningResult::storage_needs_attention( $configured, $source );
			}
		}

		if ( ! $this->supported_automatic_platform() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'local_posix_unavailable',
				__( 'Automatic secure storage setup requires a direct local POSIX filesystem.', 'ran-booster' )
			);
		}

		$discarded = array();
		try {
			$candidate = $this->resolve_candidate( $discarded );
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::manual_required(
				'location_unavailable',
				__( 'Booster could not determine a safe private storage location.', 'ran-booster' ),
				null,
				$discarded
			);
		}
		if ( null === $candidate ) {
			return SecretsStorageProvisioningResult::manual_required(
				'location_unavailable',
				__( 'Booster could not determine a safe private storage location.', 'ran-booster' ),
				null,
				$discarded
			);
		}

		if ( null === $this->loaded_wp_config_path() ) {
			return SecretsStorageProvisioningResult::manual_required(
				'wp_config_unavailable',
				__( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ),
				$candidate
			);
		}

		return SecretsStorageProvisioningResult::setup_available( $candidate );
	}

	public function provision(): SecretsStorageProvisioningResult {
		$status = $this->status();
		if ( ! $status->can_provision_automatically() ) {
			return $status;
		}

		$candidate = $status->candidate_path();
		$config    = $this->loaded_wp_config_path();
		if ( null === $candidate || null === $config ) {
			return SecretsStorageProvisioningResult::manual_required(
				'wp_config_unavailable',
				__( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ),
				$candidate
			);
		}

		try {
			$passed = $this->probe_candidate( $candidate );
		} catch ( Throwable ) {
			$passed = false;
		}
		if ( ! $passed ) {
			return SecretsStorageProvisioningResult::manual_required(
				'filesystem_probe_failed',
				__( 'The private storage filesystem did not pass Booster\'s safety checks.', 'ran-booster' ),
				$candidate
			);
		}
		if ( ! $this->validate_configured_candidate( $candidate ) ) {
			return SecretsStorageProvisioningResult::manual_required(
				'candidate_path_unsafe',
				__( 'The private storage location changed or did not pass Booster\'s final path-safety check.', 'ran-booster' ),
				$candidate
			);
		}

		if ( ! $this->same_filesystem_device( dirname( $candidate ), $config ) ) {
			return SecretsStorageProvisioningResult::manual_required(
				'filesystem_device_mismatch',
				__( 'The private storage location and WordPress configuration are not on one verified local filesystem.', 'ran-booster' ),
				$candidate
			);
		}

		try {
			$result = $this->write_configuration( $config, $candidate );
		} catch ( WpConfigPathWriteException $exception ) {
			return SecretsStorageProvisioningResult::manual_required(
				$this->stable_code( $exception->reason(), 'wp_config_write_failed' ),
				$this->wp_config_write_failure_message( $exception->reason() ),
				$candidate
			);
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::manual_required(
				'wp_config_write_failed',
				__( 'The WordPress configuration could not be updated safely.', 'ran-booster' ),
				$candidate
			);
		}

		if ( ! $result->requires_next_request_verification() ) {
			return SecretsStorageProvisioningResult::manual_required(
				'wp_config_verification_unavailable',
				__( 'Booster could not require a fresh WordPress configuration check.', 'ran-booster' ),
				$candidate
			);
		}

		return SecretsStorageProvisioningResult::pending_verification( $candidate );
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
	public function recovery_state( SecretsStorageProvisioningResult $status ): ?array {
		$current            = $status->candidate_path();
		$missing_ciphertext = null !== $current && $this->current_ciphertext_is_absent( $current );
		$missing_key        = 'storage_key_missing' === $status->code();
		if ( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION !== $status->status()
			|| null === $current
			|| ( ! $missing_ciphertext && ! $missing_key )
		) {
			return null;
		}

		$scan_complete = true;
		$candidates    = $this->recovery_candidates( $current, $scan_complete );
		if ( ! $scan_complete ) {
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
				if ( $missing_key && $this->orphaned_ciphertext_reset_available( $current ) ) {
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
				if ( $missing_ciphertext && $this->orphaned_key_reset_available( $current ) ) {
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
		if ( $missing_key ) {
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

		$candidate   = $candidates[0];
		$config      = $this->loaded_wp_config_path();
		$owned       = false;
		$same_device = false;
		try {
			$owned       = null !== $config && $this->writer->assert_owned_definition_removable( $config, $current );
			$same_device = null !== $config && $this->same_filesystem_device( dirname( $candidate['candidate_path'] ), $config );
		} catch ( Throwable ) {
			$owned = false;
		}

		if ( ! $candidate['safe'] || ! $candidate['fit'] || ! $owned || ! $same_device ) {
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

	public function reset_orphaned_storage( string $confirmation ): SecretsStorageProvisioningResult {
		$status  = $this->status();
		$current = $status->candidate_path();
		$source  = $status->path_source();
		if ( null === $current
			|| null === $source
			|| ! hash_equals( self::RESET_CONFIRMATION, $confirmation )
		) {
			return $this->reset_failure( $status, 'storage_reset_request_invalid', __( 'The empty-storage reset request is invalid. Review the current storage state and try again.', 'ran-booster' ) );
		}

		$offer = $this->recovery_state( $status );
		if ( null === $offer || 'reset_available' !== $offer['state'] ) {
			return $this->reset_failure( $status, 'storage_reset_state_changed', __( 'The credential storage state changed and was not reset. Review it again before continuing.', 'ran-booster' ) );
		}

		try {
			if ( 'storage_key_missing' === $status->code() ) {
				$this->reset_orphaned_ciphertext( $current );
			} else {
				$this->reset_orphaned_key( $current );
			}
		} catch ( Throwable ) {
			return $this->reset_failure( $status, 'storage_reset_failed', __( 'The incomplete credential storage could not be reset safely. No storage reset was confirmed.', 'ran-booster' ) );
		}

		return SecretsStorageProvisioningResult::storage_reset( $current, $source );
	}

	public function adopt_recovery( string $token ): SecretsStorageProvisioningResult {
		$status  = $this->status();
		$current = $status->candidate_path();
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token ) || null === $current ) {
			return $this->recovery_failure( $current, 'recovery_request_invalid', __( 'The storage recovery request is invalid. Review the current storage state and try again.', 'ran-booster' ) );
		}

		$offer = $this->recovery_state( $status );
		if ( null === $offer
			|| 'available' !== $offer['state']
			|| ! is_string( $offer['candidate_path'] )
			|| ! is_string( $offer['token'] )
			|| ! hash_equals( $offer['token'], $token )
		) {
			return $this->recovery_failure( $current, 'recovery_candidate_changed', __( 'The recoverable storage candidate is no longer uniquely safe and authenticated. Review the current storage state again.', 'ran-booster' ) );
		}

		$config = $this->loaded_wp_config_path();
		if ( null === $config ) {
			return $this->recovery_failure( $current, 'wp_config_unavailable', __( 'Booster could not safely identify the wp-config.php loaded by WordPress.', 'ran-booster' ) );
		}

		try {
			$result = $this->retarget_configuration( $config, $current, $offer['candidate_path'] );
		} catch ( WpConfigPathWriteException $exception ) {
			return $this->recovery_failure(
				$current,
				$this->stable_code( $exception->reason(), 'recovery_write_failed' ),
				$this->wp_config_write_failure_message( $exception->reason() )
			);
		} catch ( Throwable ) {
			return $this->recovery_failure( $current, 'recovery_write_failed', __( 'The recoverable storage path could not be adopted safely.', 'ran-booster' ) );
		}

		return false !== $result && $result->requires_next_request_verification()
			? SecretsStorageProvisioningResult::pending_verification( $offer['candidate_path'] )
			: $this->recovery_failure( $current, 'wp_config_verification_unavailable', __( 'Booster could not require a fresh WordPress configuration check.', 'ran-booster' ) );
	}

	/**
	 * @param list<array{directory:string,code:string,reason:string,component:string|null}>|null $discarded
	 * @param-out list<array{directory:string,code:string,reason:string,component:string|null}> $discarded
	 */
	protected function resolve_candidate( ?array &$discarded = null ): ?string {
		return $this->resolver->resolve(
			$this->wordpress_root(),
			$this->content_directory(),
			$this->plugin_directory(),
			$this->document_root(),
			$discarded
		);
	}

	protected function validate_configured_candidate( string $candidate ): bool {
		return $this->resolver->validate_configured(
			$candidate,
			$this->wordpress_root(),
			$this->content_directory(),
			$this->plugin_directory(),
			$this->document_root()
		);
	}

	protected function probe_candidate( string $candidate ): bool {
		return $this->probe->probe( $candidate );
	}

	protected function write_configuration( string $config, string $candidate ): WpConfigPathWriteResult {
		return $this->writer->write( $config, $candidate );
	}

	protected function retarget_configuration(
		string $config,
		string $current,
		string $replacement
	): WpConfigPathWriteResult|false {
		return $this->writer->retarget_owned_definition( $config, $current, $replacement );
	}

	protected function recovery_credentials_fit( string $candidate ): bool {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		return $this->secrets->recovery_credentials_fit_at( $candidate );
	}

	protected function orphaned_key_reset_available( string $current ): bool {
		return null !== $this->secrets && $this->secrets->can_reset_orphaned_key_at( $current );
	}

	protected function reset_orphaned_key( string $current ): void {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		$this->secrets->reset_orphaned_key_at( $current );
	}

	protected function orphaned_ciphertext_reset_available( string $current ): bool {
		return null !== $this->secrets && $this->secrets->can_reset_orphaned_ciphertext_at( $current );
	}

	protected function reset_orphaned_ciphertext( string $current ): void {
		if ( null === $this->secrets ) {
			throw new SecretsStorageUnavailable( 'Encrypted storage is unavailable.' );
		}

		$this->secrets->reset_orphaned_ciphertext_at( $current );
	}

	protected function wordpress_root(): string {
		// Validate actual configured values even when WordPress analysis stubs declare strings.
		return defined( 'ABSPATH' ) && is_string( constant( 'ABSPATH' ) ) ? ABSPATH : '';
	}

	protected function content_directory(): string {
		// Validate actual configured values even when WordPress analysis stubs declare strings.
		return defined( 'WP_CONTENT_DIR' ) && is_string( constant( 'WP_CONTENT_DIR' ) ) ? WP_CONTENT_DIR : '';
	}

	protected function plugin_directory(): string {
		$path = realpath( dirname( __DIR__, 2 ) );

		return false === $path ? dirname( __DIR__, 2 ) : $path;
	}

	protected function document_root(): ?string {
		$root = $_SERVER['DOCUMENT_ROOT'] ?? null;

		return is_string( $root ) && '' !== trim( $root ) ? $root : null;
	}

	/** @return list<string> */
	protected function included_files(): array {
		return get_included_files();
	}

	/** @return string|false|null False means defined with an invalid value. */
	protected function configured_path(): string|false|null {
		if ( defined( self::UNSUPPORTED_FILE_CONSTANT_NAME ) ) {
			return false;
		}
		if ( defined( self::DIRECTORY_CONSTANT_NAME ) ) {
			$value = constant( self::DIRECTORY_CONSTANT_NAME );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return false;
			}

			$directory = '/' === $value ? '/' : rtrim( $value, '/' );
			$path      = $directory . ( '/' === $directory ? '' : '/' ) . 'secrets.json';

			return $this->absolute_canonical_path( $path ) ? $path : false;
		}
		return null;
	}

	protected function is_multisite_installation(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	protected function sodium_available(): bool {
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
	}

	protected function supported_local_platform(): bool {
		if ( ! $this->supported_posix_platform() ) {
			return false;
		}
		if ( defined( 'FS_METHOD' ) && 'direct' !== constant( 'FS_METHOD' ) ) {
			return false;
		}

		$root = $this->wordpress_root();

		return '' !== $root && stream_is_local( $root );
	}

	protected function managed_storage_healthy(): bool {
		return null !== $this->secrets && $this->secrets->has_healthy_managed_storage();
	}

	private function supported_posix_platform(): bool {
		if ( 'Windows' === PHP_OS_FAMILY
			|| '\\' === DIRECTORY_SEPARATOR
			|| ! function_exists( 'flock' )
			|| ! function_exists( 'posix_geteuid' )
		) {
			return false;
		}

		return true;
	}

	private function supported_automatic_platform(): bool {
		return $this->supported_local_platform();
	}

	private function runtime_failure(): ?SecretsStorageProvisioningResult {
		if ( ! $this->sodium_available() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'sodium_unavailable',
				'The Sodium extension is required for encrypted secrets storage.'
			);
		}
		if ( $this->is_multisite_installation() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'multisite_unsupported',
				'Encrypted file-backed secrets storage is not available on multisite in this Beta release.'
			);
		}
		if ( ! $this->supported_posix_platform() ) {
			return SecretsStorageProvisioningResult::unsupported(
				'local_posix_unavailable',
				'Encrypted secrets storage requires a local POSIX environment.'
			);
		}

		return null;
	}

	private function configured_path_source( string $configured, bool $path_is_safe ): string {
		try {
			$config = $this->loaded_wp_config_path();
			if ( null !== $config && $this->writer->has_owned_definition( $config, $configured ) ) {
				return SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC;
			}
			if ( $path_is_safe && $configured === $this->resolve_candidate() ) {
				return SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC;
			}
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
		}

		return SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
	}

	/**
	 * @param-out bool $complete
	 * @return list<array{candidate_path:string,token:string,safe:bool,fit:bool}>
	 */
	private function recovery_candidates( string $current, ?bool &$complete = null ): array {
		$complete = true;
		$base     = $this->automatic_storage_base( $current );
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
			if ( 1 !== preg_match( '/\A[a-f0-9]{16}\z/D', $entry )
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
				if ( null !== $this->inspect_ready_path( $candidate ) ) {
					$complete = false;

					continue;
				}
				$fit      = $this->recovery_credentials_fit( $candidate );
				$revision = $this->recovery_revision( $candidate );
				if ( null === $revision ) {
					$complete = false;

					continue;
				}
				$candidates[] = array(
					'candidate_path' => $candidate,
					'token'          => $revision,
					'safe'           => $this->validate_configured_candidate( $candidate ),
					'fit'            => $fit,
				);
			} catch ( Throwable ) {
				$complete = false;

				continue;
			}
		}

		return $candidates;
	}

	private function automatic_storage_base( string $current ): ?string {
		$directory = dirname( $current );
		$base      = dirname( $directory );

		return 'secrets.json' === basename( $current )
			&& 1 === preg_match( '/\A[a-f0-9]{16}\z/D', basename( $directory ) )
			&& '.ran-booster' === basename( $base )
			? $base
			: null;
	}

	protected function current_ciphertext_is_absent( string $current ): bool {
		if ( file_exists( $current ) || is_link( $current ) ) {
			return false;
		}
		if ( null === $this->secrets ) {
			return ! file_exists( $current . '.lock' ) && ! is_link( $current . '.lock' );
		}

		try {
			return $this->secrets->can_recover_from_missing_ciphertext_at( $current );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function recovery_revision( string $candidate ): ?string {
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

	private function recovery_failure( ?string $current, string $code, string $message ): SecretsStorageProvisioningResult {
		return null === $current
			? SecretsStorageProvisioningResult::manual_required( $code, $message )
			: SecretsStorageProvisioningResult::storage_needs_attention(
				$current,
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL,
				$code,
				$message
			);
	}

	private function reset_failure( SecretsStorageProvisioningResult $status, string $code, string $message ): SecretsStorageProvisioningResult {
		$current = $status->candidate_path();
		$source  = $status->path_source();

		return null === $current || null === $source
			? SecretsStorageProvisioningResult::manual_required( $code, $message )
			: SecretsStorageProvisioningResult::storage_needs_attention( $current, $source, $code, $message );
	}

	/** @return array{code: string, message: string}|null */
	private function inspect_ready_path( string $candidate ): ?array {
		$directory = dirname( $candidate );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return $this->path_failure(
				'storage_directory_unavailable',
				__( 'PHP cannot see the configured storage directory. It may be absent, or PHP may lack execute/traverse permission on a parent directory. Keep the storage directory itself owner-only with mode 0700.', 'ran-booster' )
			);
		}
		if ( is_link( $directory ) || ! is_dir( $directory ) || ! stream_is_local( $directory ) ) {
			return $this->path_failure(
				'storage_directory_invalid',
				__( 'The configured secrets directory must be a real directory on a supported local filesystem, not a symbolic link.', 'ran-booster' )
			);
		}

		$stat = lstat( $directory );
		if ( false === $stat || 0040000 !== ( $stat['mode'] & 0170000 ) ) {
			return $this->path_failure(
				'storage_directory_inspection_failed',
				__( 'Booster could not verify the configured secrets directory.', 'ran-booster' )
			);
		}
		$issues = $this->access_issues( $directory, $stat, 0700, 'directory' );
		if ( array() !== $issues ) {
			return $this->path_failure(
				'storage_directory_unusable',
				implode( ' ', $issues )
			);
		}

		if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
			return null;
		}
		if ( is_link( $candidate ) || ! is_file( $candidate ) ) {
			return $this->path_failure(
				'storage_file_invalid',
				__( 'The configured secrets file must be a regular file, not a symbolic link.', 'ran-booster' )
			);
		}
		$file = lstat( $candidate );
		if ( false === $file || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return $this->path_failure(
				'storage_file_inspection_failed',
				__( 'Booster could not verify the configured secrets file.', 'ran-booster' )
			);
		}
		$issues = $this->access_issues( $candidate, $file, 0600, 'file' );
		if ( 1 !== $file['nlink'] ) {
			$issues[] = __( 'The configured secrets file has additional hard links.', 'ran-booster' );
		}
		if ( array() !== $issues ) {
			return $this->path_failure(
				'storage_file_unusable',
				implode( ' ', $issues )
			);
		}

		$lock = $candidate . '.lock';
		if ( ! file_exists( $lock ) && ! is_link( $lock ) ) {
			return $this->path_failure(
				'storage_lock_missing',
				__( 'The secrets file exists, but its matching lock file is missing. Restore the matching storage set from one backup.', 'ran-booster' )
			);
		}
		if ( is_link( $lock ) || ! is_file( $lock ) ) {
			return $this->path_failure(
				'storage_lock_invalid',
				__( 'The configured secrets lock must be a regular file, not a symbolic link.', 'ran-booster' )
			);
		}
		$lock_stat = lstat( $lock );
		if ( false === $lock_stat || 0100000 !== ( $lock_stat['mode'] & 0170000 ) ) {
			return $this->path_failure(
				'storage_lock_inspection_failed',
				__( 'Booster could not verify the configured secrets lock file.', 'ran-booster' )
			);
		}
		$issues = $this->access_issues( $lock, $lock_stat, 0600, 'lock file' );
		if ( 1 !== $lock_stat['nlink'] ) {
			$issues[] = __( 'The configured secrets lock file has additional hard links.', 'ran-booster' );
		}
		if ( array() !== $issues ) {
			return $this->path_failure(
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
	private function access_issues( string $path, array $stat, int $required_mode, string $label ): array {
		$display_label = match ( $label ) {
			'directory' => _x( 'directory', 'Configured secrets storage item', 'ran-booster' ),
			'file' => _x( 'file', 'Configured secrets storage item', 'ran-booster' ),
			'lock file' => _x( 'lock file', 'Configured secrets storage item', 'ran-booster' ),
			default => $label,
		};
		$issues = array();
		$mode   = $stat['mode'] & 0777;
		if ( $required_mode !== $mode ) {
			$issues[] = sprintf(
				/* translators: 1: configured secrets storage item, 2: current octal mode, 3: required octal mode. */
				__( 'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.', 'ran-booster' ),
				$display_label,
				$mode,
				$required_mode
			);
		}
		if ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== $stat['uid'] ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not owned by the PHP process user.', 'ran-booster' ),
				$display_label
			);
		}
		if ( ! is_readable( $path ) ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not readable by PHP.', 'ran-booster' ),
				$display_label
			);
		}
		if ( ! is_writable( $path ) ) {
			$issues[] = sprintf(
				/* translators: %s: configured secrets storage item. */
				__( 'The configured secrets %s is not writable by PHP.', 'ran-booster' ),
				$display_label
			);
		}

		return $issues;
	}

	/** @return array{code: string, message: string} */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() reads code and message to preserve the bounded storage diagnostic shape.
	private function path_failure( string $code, string $message ): array {
		return compact( 'code', 'message' );
	}

	/** @return array{code: string, message: string} */
	private function managed_storage_diagnostic( SecretsStorageUnavailable $failure ): array {
		if ( SecretsStorageUnavailable::REASON_GENERIC !== $failure->reason() ) {
			return match ( $failure->reason() ) {
				'storage_key_missing' => $this->path_failure(
					'storage_key_missing',
					__( 'secrets.json and secrets.json.lock exist, but the matching database encryption key is missing. Restore the file and database key from the same backup; Booster will not delete unauthenticated ciphertext.', 'ran-booster' )
				),
				'storage_file_missing' => $this->path_failure(
					'storage_file_missing',
					__( 'The database encryption key exists, but secrets.json is missing. Restore the matching encrypted file from the same backup before using or uninstalling Booster.', 'ran-booster' )
				),
				'storage_orphan_lock' => $this->path_failure(
					'storage_orphan_lock',
					__( 'Only secrets.json.lock remains; no secrets file or database encryption key was found.', 'ran-booster' )
				),
				'storage_lock_missing' => $this->path_failure(
					'storage_lock_missing',
					__( 'Managed secrets material exists, but secrets.json.lock is missing. Restore the matching storage set from one backup.', 'ran-booster' )
				),
				default => $this->path_failure(
					$failure->reason(),
					__( 'Booster could not safely use the encrypted secrets store.', 'ran-booster' )
				),
			};
		}

		return match ( $failure->getMessage() ) {
			'The encrypted Booster secrets store is incomplete.',
			'The encrypted Booster secrets store is incomplete because its lock is missing.',
			'The encrypted Booster secrets store is missing its lock.' => $this->path_failure(
				'storage_incomplete',
				__( 'The secrets file, lock file and database key are incomplete. Restore the matching set from one backup or reset empty storage.', 'ran-booster' )
			),
			'The encrypted Booster secrets document could not be authenticated.' => $this->path_failure(
				'storage_authentication_failed',
				__( 'The secrets file could not be authenticated with this site\'s database key. Restore both from the same backup.', 'ran-booster' )
			),
			'The encrypted Booster secrets payload is invalid.',
			'The encrypted Booster secrets payload is not canonical.' => $this->path_failure(
				'storage_document_invalid',
				__( 'The secrets file authenticated but its encrypted document is invalid.', 'ran-booster' )
			),
			'The Booster site key is unavailable.' => $this->path_failure(
				'storage_key_unavailable',
				__( 'Booster could not read the database-held encryption key. Restore the database and encrypted files from the same backup.', 'ran-booster' )
			),
			'The encrypted Booster secrets file is not readable.',
			'The encrypted Booster secrets file is not a secure bounded file.',
			'The encrypted Booster secrets file could not be read safely.' => $this->path_failure(
				'storage_file_unusable',
				__( 'The secrets file could not be read safely. Verify its ownership, mode 0600 and that it is a non-empty Booster-managed file.', 'ran-booster' )
			),
			'Refusing to use an invalid encrypted Booster secrets lock.',
			'Could not open the encrypted Booster secrets lock.',
			'Could not inspect the encrypted Booster secrets lock.',
			'Could not secure the encrypted Booster secrets lock.',
			'Could not lock the encrypted Booster secrets store.' => $this->path_failure(
				'storage_lock_unusable',
				__( 'The secrets lock file could not be used safely. Verify its ownership and mode 0600.', 'ran-booster' )
			),
			default => $this->path_failure(
				'storage_unavailable',
				__( 'Booster could not classify the storage failure. Verify PHP owns the directories, secrets.json and secrets.json.lock; directories require mode 0700 and both files require mode 0600.', 'ran-booster' )
			),
		};
	}

	private function loaded_wp_config_path(): ?string {
		$root = $this->canonical_directory( $this->wordpress_root() );
		if ( null === $root ) {
			return null;
		}

		$supported = array();
		$in_root   = $this->canonical_regular_file( $root . '/wp-config.php' );
		if ( null !== $in_root ) {
			$supported[] = $in_root;
		}

		$parent = dirname( $root );
		if ( ! is_file( $parent . '/wp-settings.php' ) ) {
			$above_root = $this->canonical_regular_file( $parent . '/wp-config.php' );
			if ( null !== $above_root ) {
				$supported[] = $above_root;
			}
		}
		$supported = array_values( array_unique( $supported ) );

		$loaded = array();
		foreach ( $this->included_files() as $included ) {
			if ( ! is_string( $included ) || 'wp-config.php' !== basename( $included ) ) {
				continue;
			}
			$canonical = $this->canonical_regular_file( $included );
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

	private function canonical_directory( string $path ): ?string {
		if ( '' === trim( $path ) || ! stream_is_local( $path ) ) {
			return null;
		}
		$real = realpath( $path );

		return false !== $real && is_dir( $real ) ? rtrim( $real, '/' ) : null;
	}

	private function canonical_regular_file( string $path ): ?string {
		if ( is_link( $path ) || ! is_file( $path ) || ! stream_is_local( $path ) ) {
			return null;
		}
		$real = realpath( $path );
		if ( false === $real || $this->normalize_path( $path ) !== $this->normalize_path( $real ) ) {
			return null;
		}

		return $real;
	}

	private function same_filesystem_device( string $candidate_directory, string $config ): bool {
		if ( ! is_dir( $candidate_directory ) || ! is_file( $config ) ) {
			return false;
		}
		$directory_stat = stat( $candidate_directory );
		$config_stat    = stat( $config );

		return false !== $directory_stat
			&& false !== $config_stat
			&& isset( $directory_stat['dev'], $config_stat['dev'] )
			&& $directory_stat['dev'] === $config_stat['dev'];
	}

	private function absolute_canonical_path( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_ends_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, "\r" )
			&& ! str_contains( $path, "\n" )
			&& 1 !== preg_match( '#/(?:\.{1,2})(?:/|$)#', $path )
			&& ! str_contains( $path, '//' );
	}

	private function normalize_path( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	private function stable_code( mixed $code, string $fallback ): string {
		return is_string( $code ) && preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) === 1
			? $code
			: $fallback;
	}

	private function wp_config_write_failure_message( string $reason ): string {
		return match ( $reason ) {
			'sidecar_path_unchanged' => __( 'The encrypted secrets path is already configured.', 'ran-booster' ),
			'config_directory_invalid' => __( 'The WordPress configuration directory is not safe and writable.', 'ran-booster' ),
			'sidecar_path_invalid' => __( 'The encrypted secrets path is not safe for automatic configuration.', 'ran-booster' ),
			'config_path_invalid' => __( 'The supplied WordPress configuration path is not an absolute safe POSIX file path.', 'ran-booster' ),
			'config_changed' => __( 'The WordPress configuration changed before it could be edited.', 'ran-booster' ),
			'lock_permissions_failed' => __( 'Could not secure the WordPress configuration edit lock.', 'ran-booster' ),
			'lock_failed' => __( 'Could not lock the WordPress configuration for editing.', 'ran-booster' ),
			'config_file_invalid' => __( 'The WordPress configuration does not pass Booster\'s writable private regular-file checks.', 'ran-booster' ),
			'config_permissions_unsafe' => __( 'The WordPress configuration is group- or world-writable.', 'ran-booster' ),
			'config_size_unsupported' => __( 'The WordPress configuration has an unsupported size.', 'ran-booster' ),
			'config_owner_invalid' => __( 'The WordPress configuration is not owned by the current process owner.', 'ran-booster' ),
			'config_read_failed' => __( 'Could not read the complete WordPress configuration.', 'ran-booster' ),
			'marker_invalid' => __( 'The WordPress configuration must contain one standard stop-editing marker.', 'ran-booster' ),
			'constant_exists' => __( 'The encrypted secrets path constant is already defined.', 'ran-booster' ),
			'owned_definition_ambiguous' => __( 'The automatic encrypted secrets definition is ambiguous.', 'ran-booster' ),
			'candidate_parse_failed' => __( 'The edited WordPress configuration did not pass the expected definition check.', 'ran-booster' ),
			'temporary_permissions_failed' => __( 'Could not secure the temporary WordPress configuration.', 'ran-booster' ),
			'temporary_ownership_failed' => __( 'Could not preserve WordPress configuration ownership.', 'ran-booster' ),
			'temporary_flush_failed' => __( 'Could not flush the edited WordPress configuration.', 'ran-booster' ),
			'temporary_sync_failed' => __( 'Could not synchronize the edited WordPress configuration.', 'ran-booster' ),
			'temporary_readback_failed' => __( 'The edited WordPress configuration failed its read-back check.', 'ran-booster' ),
			'temporary_metadata_invalid' => __( 'The edited WordPress configuration metadata could not be verified.', 'ran-booster' ),
			'replace_failed' => __( 'Could not atomically replace the WordPress configuration.', 'ran-booster' ),
			'replacement_readback_failed' => __( 'The installed WordPress configuration failed verification.', 'ran-booster' ),
			'filesystem_failure' => __( 'The WordPress configuration could not be updated safely.', 'ran-booster' ),
			'config_parse_failed' => __( 'The WordPress configuration does not parse as supported PHP.', 'ran-booster' ),
			'line_endings_unsupported' => __( 'The WordPress configuration uses unsupported line endings.', 'ran-booster' ),
			'lock_invalid' => __( 'The WordPress configuration edit lock is not safe.', 'ran-booster' ),
			'temporary_write_failed' => __( 'Could not write the complete edited WordPress configuration.', 'ran-booster' ),
			'temporary_file_invalid' => __( 'The temporary WordPress configuration is not safe.', 'ran-booster' ),
			'lock_open_failed' => __( 'Could not open the WordPress configuration edit lock.', 'ran-booster' ),
			'temporary_create_failed' => __( 'Could not create a private temporary WordPress configuration.', 'ran-booster' ),
			default => __( 'The WordPress configuration could not be updated safely.', 'ran-booster' ),
		};
	}
}

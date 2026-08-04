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
				'The encrypted secrets path constant is not a valid absolute path.'
			);
		}
		if ( is_string( $configured ) ) {
			if ( ! $this->validateConfiguredCandidate( $configured ) ) {
				return SecretsStorageProvisioningResult::manualRequired(
					'configured_path_unsafe',
					'The configured encrypted secrets path is not a verified private location.'
				);
			}

			$source      = $this->configuredPathSource( $configured );
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
				'Automatic secure storage setup requires a direct local POSIX filesystem.'
			);
		}

		try {
			$candidate = $this->resolveCandidate();
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				'Booster could not determine a safe private storage location.'
			);
		}
		if ( null === $candidate ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				'Booster could not determine a safe private storage location.'
			);
		}

		if ( null === $this->loadedWpConfigPath() ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_unavailable',
				'Booster could not safely identify the wp-config.php loaded by WordPress.',
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
				'Booster could not safely identify the wp-config.php loaded by WordPress.',
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
				'The private storage filesystem did not pass Booster\'s safety checks.',
				$candidate
			);
		}

		if ( ! $this->sameFilesystemDevice( dirname( $candidate ), $config ) ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'filesystem_device_mismatch',
				'The private storage location and WordPress configuration are not on one verified local filesystem.',
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
				'The WordPress configuration could not be updated safely.',
				$candidate
			);
		}

		if ( ! $result->requiresNextRequestVerification() ) {
			return SecretsStorageProvisioningResult::manualRequired(
				'wp_config_verification_unavailable',
				'Booster could not require a fresh WordPress configuration check.',
				$candidate
			);
		}

		return SecretsStorageProvisioningResult::pendingVerification( $candidate );
	}

	protected function resolveCandidate(): ?string {
		return $this->resolver->resolve(
			$this->wordpressRoot(),
			$this->contentDirectory(),
			$this->pluginDirectory(),
			$this->documentRoot()
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
		if ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD ) {
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
				'Encrypted file-backed secrets storage is not available on multisite in this Alpha release.'
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

	private function configuredPathSource( string $configured ): string {
		try {
			return $configured === $this->resolveCandidate()
				? SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
				: SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
		} catch ( Throwable ) {
			return SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL;
		}
	}

	/** @return array{code: string, message: string}|null */
	private function inspectReadyPath( string $candidate ): ?array {
		$directory = dirname( $candidate );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return $this->pathFailure(
				'storage_directory_missing',
				'The configured secrets directory does not exist or is not visible to the PHP process.'
			);
		}
		if ( is_link( $directory ) || ! is_dir( $directory ) || ! stream_is_local( $directory ) ) {
			return $this->pathFailure(
				'storage_directory_invalid',
				'The configured secrets directory must be a real directory on a supported local filesystem, not a symbolic link.'
			);
		}

		$stat = lstat( $directory );
		if ( false === $stat || 0040000 !== ( $stat['mode'] & 0170000 ) ) {
			return $this->pathFailure(
				'storage_directory_inspection_failed',
				'Booster could not verify the configured secrets directory.'
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
				'The configured secrets file must be a regular file, not a symbolic link.'
			);
		}
		$file = lstat( $candidate );
		if ( false === $file || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return $this->pathFailure(
				'storage_file_inspection_failed',
				'Booster could not verify the configured secrets file.'
			);
		}
		$issues = $this->accessIssues( $candidate, $file, 0600, 'file' );
		if ( 1 !== $file['nlink'] ) {
			$issues[] = 'The configured secrets file has additional hard links.';
		}
		if ( array() !== $issues ) {
			return $this->pathFailure(
				'storage_file_unusable',
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
		$issues = array();
		$mode   = $stat['mode'] & 0777;
		if ( $requiredMode !== $mode ) {
			$issues[] = sprintf( 'The configured secrets %s uses mode %04o; mode %04o is required.', $label, $mode, $requiredMode );
		}
		if ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== $stat['uid'] ) {
			$issues[] = sprintf( 'The configured secrets %s is not owned by the PHP process user.', $label );
		}
		if ( ! is_readable( $path ) ) {
			$issues[] = sprintf( 'The configured secrets %s is not readable by PHP.', $label );
		}
		if ( ! is_writable( $path ) ) {
			$issues[] = sprintf( 'The configured secrets %s is not writable by PHP.', $label );
		}

		return $issues;
	}

	/** @return array{code: string, message: string} */
	private function pathFailure( string $code, string $message ): array {
		return compact( 'code', 'message' );
	}

	/** @return array{code: string, message: string} */
	private function managedStorageDiagnostic( SecretsStorageUnavailable $failure ): array {
		return match ( $failure->getMessage() ) {
			'The encrypted Booster secrets store is incomplete.',
			'The encrypted Booster secrets store is incomplete because its lock is missing.',
			'The encrypted Booster secrets store is missing its lock.' => $this->pathFailure(
				'storage_incomplete',
				'The secrets file, lock file and database key are incomplete. Restore the matching set from one backup or reset empty storage.'
			),
			'The encrypted Booster secrets document could not be authenticated.' => $this->pathFailure(
				'storage_authentication_failed',
				'The secrets file could not be authenticated with this site\'s database key. Restore both from the same backup.'
			),
			'The encrypted Booster secrets payload is invalid.',
			'The encrypted Booster secrets payload is not canonical.' => $this->pathFailure(
				'storage_document_invalid',
				'The secrets file authenticated but its encrypted document is invalid.'
			),
			default => $this->pathFailure(
				'storage_unavailable',
				'Booster could not safely read the configured secrets storage.'
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

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

	private const CONSTANT_NAME = 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE';

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

			$source = $this->configuredPathSource( $configured );
			if ( ! $this->readyPath( $configured ) ) {
				return SecretsStorageProvisioningResult::storageNeedsAttention( $configured, $source );
			}

			try {
				return $this->managedStorageHealthy()
					? SecretsStorageProvisioningResult::storageHealthy( $configured, $source )
					: SecretsStorageProvisioningResult::pathConfigured( $configured, $source );
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
		if ( ! defined( self::CONSTANT_NAME ) ) {
			return null;
		}

		$value = constant( self::CONSTANT_NAME );

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

	private function readyPath( string $candidate ): bool {
		$directory = dirname( $candidate );
		if ( ! is_dir( $directory )
			|| is_link( $directory )
			|| ! is_writable( $directory )
			|| ! stream_is_local( $directory )
		) {
			return false;
		}

		$stat = lstat( $directory );

		if ( false === $stat
			|| 0040000 !== ( $stat['mode'] & 0170000 )
			|| 0700 !== ( $stat['mode'] & 0777 )
			|| ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== $stat['uid'] )
		) {
			return false;
		}

		if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
			return true;
		}
		if ( is_link( $candidate ) || ! is_file( $candidate ) || ! is_readable( $candidate ) || ! is_writable( $candidate ) ) {
			return false;
		}
		$file = lstat( $candidate );

		return false !== $file
			&& 0100000 === ( $file['mode'] & 0170000 )
			&& 1 === $file['nlink']
			&& 0600 === ( $file['mode'] & 0777 )
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $file['uid'] );
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

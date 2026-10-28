<?php

declare(strict_types=1);

namespace RAN\Admin;

use Closure;
use RAN\Deployment\PreparedArtifact;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Installs only the exact release selected by Core's fixed catalogue. */
final class ExtensionInstaller {
	private const MAX_ARCHIVE_BYTES = 16777216;

	/** @var Closure(): list<array<string, mixed>> */
	private Closure $catalogue;

	/** @param callable(): list<array<string, mixed>> $catalogue */
	public function __construct(
		private WordPressUpdaterLock $lock,
		private CorePackageExecutor $executor,
		callable $catalogue
	) {
		$this->catalogue = Closure::fromCallable( $catalogue );
	}

	/** Return a closed result vocabulary; never expose paths or remote errors. */
	public function install( string $id ): string {
		try {
			$extension = $this->find( $id );
		} catch ( Throwable ) {
			return 'failed';
		}
		if ( null === $extension ) {
			return 'failed';
		}
		if ( ! $extension['compatible'] ) {
			return 'incompatible';
		}
		if ( 'Not installed' !== $extension['state'] ) {
			return 'already_installed';
		}

		$directory          = null;
		$path               = null;
		$artifact           = null;
		$created            = null;
		$directory_identity = null;
		$attempted          = false;
		$result             = 'failed';
		try {
			$directory          = $this->temporary_directory();
			$directory_identity = lstat( $directory );
			if ( false === $directory_identity ) {
				throw new RuntimeException( 'Temporary directory identity unavailable.' );
			}
			$path = $directory . '/release.zip';
			$this->download( $extension, $path, $created );
			$identity = PreparedArtifact::regular_file_identity( $path );
			if ( null === $identity || 0600 !== $identity['permissions'] || 1 !== $identity['links'] ) {
				throw new RuntimeException( 'Invalid temporary artifact.' );
			}
			$artifact = new PreparedArtifact(
				$path,
				$extension['id'],
				$extension['release_version'],
				$extension['asset_sha256'],
				$identity['device'],
				$identity['inode'],
				$identity['size'],
				$identity['permissions'],
				$identity['links'],
				$directory
			);
			$artifact->assert_unchanged();
			$files  = $this->archive_files( $path, $extension );
			$result = $this->lock->run(
				function () use ( $id, $extension, $artifact, $files, &$attempted ): string {
					$current = $this->find( $id );
					if ( null === $current || ! $current['compatible'] ) {
							return 'incompatible';
					}
					if ( 'Not installed' !== $current['state'] ) {
						return 'already_installed';
					}
					foreach ( array( 'id', 'plugin', 'release_version', 'asset_url', 'asset_sha256' ) as $key ) {
						if ( $current[ $key ] !== $extension[ $key ] ) {
							return 'failed';
						}
					}
					$slug        = dirname( $extension['plugin'] );
					$destination = WP_PLUGIN_DIR . '/' . $slug;
					clearstatcache( true, $destination );
					if ( file_exists( $destination ) || is_link( $destination ) ) {
						return 'already_installed';
					}
					$artifact->assert_unchanged();
					$attempted = true;
					$execution = $this->executor->install_plugin( $artifact, $slug, null );
					if ( ! $execution->is_successful() || ! $this->installed_matches( $destination, $files ) ) {
						return 'uncertain';
					}
					wp_clean_plugins_cache( true );
					$installed = get_plugins();
					if ( ! isset( $installed[ $extension['plugin'] ] ) || ( $installed[ $extension['plugin'] ]['Version'] ?? null ) !== $extension['release_version'] || is_plugin_active( $extension['plugin'] ) ) {
						return 'uncertain';
					}
					return 'installed';
				}
			);
		} catch ( Throwable ) {
			$result = $attempted ? 'uncertain' : 'failed';
		} finally {
			try {
				if ( null !== $artifact ) {
					$artifact->cleanup();
				} elseif ( null !== $directory ) {
					$current_directory = lstat( $directory );
					if ( false === $current_directory || false === $directory_identity || null === $directory_identity || is_link( $directory ) || $current_directory['dev'] !== $directory_identity['dev'] || $current_directory['ino'] !== $directory_identity['ino'] ) {
						throw new RuntimeException( 'Temporary directory identity changed.' );
					}
					if ( null !== $path && ( file_exists( $path ) || is_link( $path ) ) ) {
						$current_file = PreparedArtifact::regular_file_identity( $path );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the identity-checked file from a private Core-owned directory.
						if ( null === $created || null === $current_file || $current_file['device'] !== $created['device'] || $current_file['inode'] !== $created['inode'] || 1 !== $current_file['links'] || ! unlink( $path ) ) {
							throw new RuntimeException( 'Temporary artifact could not be removed.' );
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the identity-checked empty private directory.
					if ( ! rmdir( $directory ) ) {
						throw new RuntimeException( 'Temporary directory could not be removed.' );
					}
				}
			} catch ( Throwable ) {
				$result = 'uncertain';
			}
		}
		return $result;
	}

	/** @return array{id: string, plugin: string, release_version: string, asset_url: string, asset_sha256: string, compatible: bool, state: string}|null */
	private function find( string $id ): ?array {
		foreach ( ( $this->catalogue )() as $extension ) {
			if ( ( $extension['id'] ?? null ) !== $id ) {
				continue;
			}
			foreach ( array( 'id', 'plugin', 'release_version', 'asset_url', 'asset_sha256', 'state' ) as $key ) {
				if ( ! is_string( $extension[ $key ] ?? null ) ) {
					return null;
				}
			}
			if ( ! is_bool( $extension['compatible'] ?? null ) || 1 !== preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\.php\z/', $extension['plugin'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $extension['asset_sha256'] ) ) {
				return null;
			}
			return array_intersect_key( $extension, array_flip( array( 'id', 'plugin', 'release_version', 'asset_url', 'asset_sha256', 'compatible', 'state' ) ) );
		}
		return null;
	}

	private function temporary_directory(): string {
		$directory = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . '/ran-extension-' . bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A private random local directory owns downloaded bytes.
		if ( ! mkdir( $directory, 0700 ) ) {
			throw new RuntimeException( 'Temporary directory unavailable.' );
		}
		if ( is_link( $directory ) || 0700 !== ( fileperms( $directory ) & 0777 ) ) {
			if ( ! is_link( $directory ) && is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the newly created empty private directory.
				rmdir( $directory );
			}
			throw new RuntimeException( 'Temporary directory permissions unavailable.' );
		}
		return $directory;
	}

	/**
	 * @param array{id: string, plugin: string, release_version: string, asset_url: string, asset_sha256: string, compatible: bool, state: string} $extension
	 * @param array{device: int, inode: int, size: int, permissions: int, links: int}|null $created
	 * @param-out array{device: int, inode: int, size: int, permissions: int, links: int} $created
	 */
	private function download( array $extension, string $path, ?array &$created ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive local creation establishes archive custody.
		$file = fopen( $path, 'xb' );
		if ( false === $file ) {
			throw new RuntimeException( 'Temporary file unavailable.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created local file.
		fclose( $file );
		$initial = PreparedArtifact::regular_file_identity( $path );
		if ( null === $initial ) {
			throw new RuntimeException( 'Temporary file identity unavailable.' );
		}
		$created = $initial;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the exact private temporary file before download.
		if ( ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Temporary file permissions unavailable.' );
		}
		$response = wp_safe_remote_get(
			$extension['asset_url'],
			array(
				'timeout'             => 60,
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $path,
				'limit_response_size' => self::MAX_ARCHIVE_BYTES + 1,
			)
		);
		clearstatcache( true, $path );
		$size         = filesize( $path );
		$digest       = hash_file( 'sha256', $path );
		$current_file = PreparedArtifact::regular_file_identity( $path );
		if ( null === $current_file || $created['device'] !== $current_file['device'] || $created['inode'] !== $current_file['inode'] || 1 !== $current_file['links'] || 0600 !== $current_file['permissions'] || is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || false === $size || $size <= 0 || $size > self::MAX_ARCHIVE_BYTES || ! is_string( $digest ) || ! hash_equals( $extension['asset_sha256'], $digest ) ) {
			throw new RuntimeException( 'Selected release could not be verified.' );
		}
	}

	/** @param array{id: string, plugin: string, release_version: string, asset_url: string, asset_sha256: string, compatible: bool, state: string} $extension
	 * @return array<string, string> Relative names and sha256 digests.
	 */
	private function archive_files( string $path, array $extension ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'Invalid selected release archive.' );
		}
		$prefix              = dirname( $extension['plugin'] ) . '/';
		$files               = array();
		$plugin_header_valid = false;
		try {
			$total_size = 0;
			$entries    = array();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			if ( $zip->numFiles > 1000 ) {
				throw new RuntimeException( 'Too many release archive entries.' );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$name = $zip->getNameIndex( $i );
				if ( ! is_string( $name ) || ! str_starts_with( $name, $prefix ) || str_contains( $name, '\\' ) || str_contains( $name, '//' ) || preg_match( '~(?:^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $name ) ) {
					throw new RuntimeException( 'Invalid release archive path.' );
				}
				$normalized = rtrim( $name, '/' );
				if ( isset( $entries[ $normalized ] ) ) {
					throw new RuntimeException( 'Duplicate release archive entry.' );
				}
				$entries[ $normalized ] = true;
				$opsys                  = 0;
				$attributes             = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) && 3 === $opsys ) {
					$type = ( $attributes >> 16 ) & 0170000;
					if ( 0 !== $type && ( str_ends_with( $name, '/' ) ? 0040000 !== $type : 0100000 !== $type ) ) {
						throw new RuntimeException( 'Release archive has a special entry.' );
					}
				}
				if ( str_ends_with( $name, '/' ) ) {
					continue;
				}
				$relative = substr( $name, strlen( $prefix ) );
				$stat     = $zip->statIndex( $i );
				if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || ! is_int( $stat['size'] ) || $stat['size'] < 0 ) {
					throw new RuntimeException( 'Invalid release archive size.' );
				}
				$total_size += $stat['size'];
				if ( $total_size > self::MAX_ARCHIVE_BYTES ) {
					throw new RuntimeException( 'Release archive is too large.' );
				}
				$content = $zip->getFromIndex( $i );
				if ( '' === $relative || isset( $files[ $relative ] ) || ! is_string( $content ) ) {
					throw new RuntimeException( 'Invalid release archive entry.' );
				}
				if ( $name === $extension['plugin'] ) {
					$header              = substr( $content, 0, 8192 );
					$plugin_header_valid = 1 === preg_match( '/^[ \t\/*#@]*Plugin Name:[ \t]*\S.+$/mi', $header )
						&& 1 === preg_match( '/^[ \t\/*#@]*Version:[ \t]*' . preg_quote( $extension['release_version'], '/' ) . '[ \t]*$/mi', $header );
				}
				$files[ $relative ] = hash( 'sha256', $content );
			}
		} finally {
			$zip->close();
		}
		if ( ! $plugin_header_valid || ! isset( $files[ basename( $extension['plugin'] ) ] ) ) {
			throw new RuntimeException( 'Missing selected extension entry.' );
		}
		return $files;
	}

	/** @param array<string, string> $files */
	private function installed_matches( string $destination, array $files ): bool {
		if ( is_link( $destination ) || ! is_dir( $destination ) ) {
			return false;
		}
		$root = realpath( $destination );
		if ( false === $root ) {
			return false;
		}
		$observed = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $destination, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() || ( ! $entry->isDir() && ! $entry->isFile() ) ) {
				return false;
			}
			if ( $entry->isFile() ) {
				$observed[ substr( $entry->getPathname(), strlen( $destination ) + 1 ) ] = true;
			}
		}
		if ( count( $observed ) !== count( $files ) || array_diff_key( $observed, $files ) ) {
			return false;
		}
		foreach ( $files as $relative => $digest ) {
			$path = $destination . '/' . $relative;
			$real = realpath( $path );
			if ( false === $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) || is_link( $path ) || ! is_file( $path ) || ! hash_equals( $digest, (string) hash_file( 'sha256', $path ) ) ) {
				return false;
			}
		}
		return true;
	}
}

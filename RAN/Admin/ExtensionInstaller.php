<?php

declare(strict_types=1);

namespace RAN\Admin;

use Closure;
use RAN\Deployment\PackageMutationGuard;
use RAN\Deployment\PreparedArtifact;
use RAN\Logging\BoosterLogger;
use RAN\PackageArtifactLimit;
use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\UpdaterSupport\V1\PluginFileIdentifier;
use RAN\UpdaterSupport\V1\TemporaryArchive;
use RAN\UpdaterSupport\V1\WordPressHeaders;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Installs only the exact release selected by Core's fixed catalogue. */
final class ExtensionInstaller {
	/** @var Closure(): list<array<string, mixed>> */
	private Closure $catalogue;

	/** @param callable(): list<array<string, mixed>> $catalogue */
	public function __construct(
		private WordPressUpdaterLock $lock,
		private WordPressCorePackageExecutor $executor,
		callable $catalogue
	) {
		$this->catalogue = Closure::fromCallable( $catalogue );
	}

	/** Return a closed result vocabulary; never expose paths or remote errors. */
	public function install( string $id ): string {
		$extension = null;
		$attempted = false;
		$result    = 'failed';
		try {
			$extension = $this->find( $id );
			if ( null === $extension ) {
				throw new RuntimeException( 'Selected extension unavailable.' );
			}
			if ( ! $extension['compatible'] ) {
				return 'incompatible';
			}
			if ( 'Not installed' !== $extension['state'] ) {
				return 'already_installed';
			}
			PackageMutationGuard::assert_filesystem_mutation_allowed();
			$maximum_bytes = PackageArtifactLimit::resolve();
			$result        = TemporaryArchive::with_archive(
				static fn (): string => wp_tempnam( 'ran-extension-' ),
				function ( string $path, int $limit ) use ( $extension ): void {
					$this->download( $extension, $path, $limit );
				},
				function ( string $path, string $digest ) use ( $id, $extension, $maximum_bytes, &$attempted ): string {
					$identity = PreparedArtifact::regular_file_identity( $path );
					if ( null === $identity || 0600 !== $identity['permissions'] || 1 !== $identity['links'] ) {
						throw new RuntimeException( 'Invalid temporary artifact.' );
					}
					$artifact = new PreparedArtifact(
						$path,
						$extension['id'],
						$extension['release_version'],
						$digest,
						$identity['device'],
						$identity['inode'],
						$identity['size'],
						$identity['permissions'],
						$identity['links']
					);
					$artifact->assert_unchanged();
					$files = $this->archive_files( $path, $extension, $maximum_bytes );
					return $this->lock->run(
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
							PackageMutationGuard::assert_filesystem_mutation_allowed();
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
				},
				$maximum_bytes,
				$extension['asset_sha256']
			);
		} catch ( Throwable $failure ) {
			$result = $attempted || 'The temporary archive could not be removed.' === $failure->getMessage() ? 'uncertain' : 'failed';
		}
		if ( in_array( $result, array( 'failed', 'uncertain' ), true ) ) {
			BoosterLogger::log(
				'Extension install failed.',
				array_filter(
					array(
						'operation'    => 'extension_install',
						'outcome_code' => $result,
						'package_slug' => $extension['id'] ?? null,
					)
				)
			);
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
			if ( ! is_bool( $extension['compatible'] ?? null ) || ! PluginFileIdentifier::is_valid( $extension['plugin'] ) || ! PackageDirectory::is_valid_name( dirname( $extension['plugin'] ) ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $extension['asset_sha256'] ) ) {
				return null;
			}
			return array_intersect_key( $extension, array_flip( array( 'id', 'plugin', 'release_version', 'asset_url', 'asset_sha256', 'compatible', 'state' ) ) );
		}
		return null;
	}

	/** @param array{id: string, plugin: string, release_version: string, asset_url: string, asset_sha256: string, compatible: bool, state: string} $extension */
	private function download( array $extension, string $path, int $maximum_bytes ): void {
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
				'limit_response_size' => $maximum_bytes + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'Selected release could not be verified.' );
		}
	}

	/** @param array{id: string, plugin: string, release_version: string, asset_url: string, asset_sha256: string, compatible: bool, state: string} $extension
	 * @return array<string, string> Relative names and sha256 digests.
	 */
	private function archive_files( string $path, array $extension, int $maximum_bytes ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'Invalid selected release archive.' );
		}
		$prefix              = dirname( $extension['plugin'] ) . '/';
		$files               = array();
		$plugin_header_valid = false;
		try {
			$total_size = 0;
			$entries    = array();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			if ( null !== ArchiveSafety::entry_count_failure( $zip->numFiles ) ) {
				throw new RuntimeException( 'Too many release archive entries.' );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$name  = $zip->getNameIndex( $i );
				$entry = is_string( $name ) ? ArchiveSafety::normalize_path( $name ) : null;
				if ( null === $entry || ! str_starts_with( $name, $prefix ) ) {
					throw new RuntimeException( 'Invalid release archive path.' );
				}
				$entries[]  = $entry;
				$opsys      = 0;
				$attributes = 0;
				if ( ! $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) || null !== ArchiveSafety::entry_type_reason( $opsys, $attributes, $entry['directory'] ) ) {
					throw new RuntimeException( 'Release archive has a special entry.' );
				}
				$stat   = $zip->statIndex( $i );
				$policy = is_array( $stat ) ? ArchiveSafety::entry_policy_failure( $stat, $entry['directory'], $maximum_bytes - $total_size ) : 'entry_policy_metadata_invalid';
				if ( ! is_array( $stat ) || ! is_int( $stat['size'] ?? null ) || null !== $policy ) {
					throw new RuntimeException( 'archive_expanded_limit' === $policy ? 'Release archive is too large.' : 'Invalid release archive size.' );
				}
				if ( $entry['directory'] ) {
					continue;
				}
				$total_size += $stat['size'];
				$relative    = substr( $entry['path'], strlen( $prefix ) );
				$stream      = $zip->getStream( $name );
				if ( ! is_resource( $stream ) ) {
					throw new RuntimeException( 'Invalid release archive entry.' );
				}
				$hash = hash_init( 'sha256' );
				try {
					if ( hash_update_stream( $hash, $stream, $stat['size'] + 1 ) !== $stat['size'] ) {
						throw new RuntimeException( 'Invalid release archive entry size.' );
					}
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream opened for this ZIP member.
					fclose( $stream );
				}
				if ( $entry['path'] === $extension['plugin'] ) {
					$header              = $zip->getFromIndex( $i, WordPressHeaders::MAX_BYTES );
					$headers             = is_string( $header ) ? WordPressHeaders::parse(
						$header,
						array(
							'Name'    => 'Plugin Name',
							'Version' => 'Version',
						)
					) : array();
					$plugin_header_valid = 1 === count( $headers['Name'] ?? array() ) && '' !== $headers['Name'][0] && array( $extension['release_version'] ) === ( $headers['Version'] ?? null );
				}
				$files[ $relative ] = hash_final( $hash );
			}
			if ( null !== ArchiveSafety::collision_failure( $entries ) ) {
				throw new RuntimeException( 'Duplicate release archive entry.' );
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
				$observed[ $this->installed_relative_path( $destination, $entry->getPathname(), DIRECTORY_SEPARATOR ) ] = true;
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

	private function installed_relative_path( string $destination, string $pathname, string $separator ): string {
		return str_replace( $separator, '/', substr( $pathname, strlen( $destination ) + 1 ) );
	}
}

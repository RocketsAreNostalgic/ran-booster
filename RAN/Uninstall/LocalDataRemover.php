<?php

declare(strict_types=1);

namespace RAN\Uninstall;

// Exact inode and empty-directory checks require native local filesystem operations.
// phpcs:disable WordPress.WP.AlternativeFunctions

use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\PrivateLocationCandidateResolver;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Storage\Database;
use RuntimeException;

/**
 * Removes only verified Core-owned local state during WordPress uninstall.
 */
class LocalDataRemover {

	private const OPTION_NAMES = array(
		Database::VERSION_OPTION,
		CredentialExpiryObservationStore::OPTION_NAME,
		PublicRepositoryLookupProfileStore::OPTION_NAME,
	);

	private const USER_META_KEYS = array(
		DevelopmentSafetyNoticeController::USER_META_KEY,
		CredentialExpiryNotice::USER_META_KEY,
		DeploymentAdminPresenter::USER_META_KEY,
	);

	private object $database;
	private ?string $verifiedTablePrefix = null;

	public function __construct(
		private readonly SecretsFile $secrets,
		private readonly TemporaryDebugCapture $debugCapture,
		private readonly WpConfigSecretsPathWriter $configWriter,
		private readonly PrivateLocationCandidateResolver $locationResolver = new PrivateLocationCandidateResolver(),
		?object $database = null
	) {
		global $wpdb;

		$this->database = $database ?? $wpdb;
	}

	public function remove(): void {
		$this->assertExactConvertedSiteScope();

		$sidecarPath = $this->secrets->path();
		$configPath  = null === $sidecarPath
			? $this->loadedWpConfigPathForRetry()
			: $this->loadedWpConfigPath();

		$this->assertCleanupCapabilities();
		if ( null !== $configPath ) {
			if ( null !== $sidecarPath ) {
				$ownedDefinition = $this->configWriter->assertOwnedDefinitionRemovable( $configPath, $sidecarPath );
				if ( function_exists( 'is_multisite' ) && is_multisite() && ! $ownedDefinition ) {
					throw new RuntimeException( 'Booster could not verify the converted installation configuration ownership.' );
				}
			}
			$this->assertWpConfigLockRemovable( $configPath );
		}
		$this->secrets->assertManagedStorageDeletable();
		$this->debugCapture->assertManagedStorageDeletable();
		$this->assertAutomaticDirectoriesRemovable( $sidecarPath );

		$this->debugCapture->deleteManagedStorage();
		$this->secrets->deleteManagedStorage();
		$this->clearScheduledWork();
		$this->clearUpdaterState();
		$this->clearUserMetadata();
		$this->dropTables();
		$this->deleteOptions();

		if ( null !== $configPath ) {
			if ( null !== $sidecarPath ) {
				$this->configWriter->removeOwnedDefinition( $configPath, $sidecarPath );
			}
			$this->removeWpConfigLock( $configPath );
		}

		$this->removeEmptyAutomaticDirectories( $sidecarPath );
	}

	protected function clearScheduledWork(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			throw new RuntimeException( 'Booster scheduled work could not be removed.' );
		}
		if ( false === wp_clear_scheduled_hook( WordPressWorkerWakeup::HOOK, array() ) ) {
			throw new RuntimeException( 'Booster scheduled work could not be removed.' );
		}
	}

	protected function clearUpdaterState(): void {
		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			throw new RuntimeException( 'Booster updater state could not be removed.' );
		}

		$missing = new \stdClass();
		$option  = $this->packageUpdaterAuthorityOption();
		delete_option( $option );
		if ( $missing !== get_option( $option, $missing ) ) {
			throw new RuntimeException( 'Booster updater state could not be removed.' );
		}
	}

	private function packageUpdaterAuthorityOption(): string {
		$target = implode( "\0", array( 'plugin', 'ran-booster', 'ran-booster.php' ) );
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}

	protected function clearUserMetadata(): void {
		if ( ! isset( $this->database->usermeta )
			|| ! is_string( $this->database->usermeta )
			|| ! method_exists( $this->database, 'prepare' )
			|| ! method_exists( $this->database, 'query' )
		) {
			throw new RuntimeException( 'Booster user notices could not be removed.' );
		}

		foreach ( self::USER_META_KEYS as $metaKey ) {
			$query = $this->database->prepare(
				'DELETE FROM %i WHERE meta_key = %s',
				$this->database->usermeta,
				$metaKey
			);
			if ( false === $this->database->query( $query ) ) {
				throw new RuntimeException( 'Booster user notices could not be removed.' );
			}
		}
	}

	protected function dropTables(): void {
		if ( null === $this->verifiedTablePrefix
			|| ! method_exists( $this->database, 'prepare' )
			|| ! method_exists( $this->database, 'query' )
		) {
			throw new RuntimeException( 'Booster tables could not be removed.' );
		}

		foreach (
			array(
				$this->verifiedTablePrefix . 'ran_booster_packages',
				$this->verifiedTablePrefix . 'ran_booster_deployment_attempts',
				$this->verifiedTablePrefix . 'ran_booster_rejected_admission_audit',
				$this->verifiedTablePrefix . 'ran_booster_native_update_activity',
			) as $table
		) {
			$query = $this->database->prepare( 'DROP TABLE IF EXISTS %i', $table );
			if ( false === $this->database->query( $query ) ) {
				throw new RuntimeException( 'Booster tables could not be removed.' );
			}
		}
	}

	protected function deleteOptions(): void {
		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			throw new RuntimeException( 'Booster options could not be removed.' );
		}

		$missing = new \stdClass();
		$options = self::OPTION_NAMES;
		foreach ( array_values( array_unique( $options ) ) as $option ) {
			delete_option( $option );
			if ( $missing !== get_option( $option, $missing ) ) {
				throw new RuntimeException( 'Booster options could not be removed.' );
			}
		}
	}

	private function assertExactConvertedSiteScope(): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'get_current_blog_id' )
			|| ! function_exists( 'get_main_site_id' )
			|| get_current_blog_id() !== get_main_site_id()
			|| ! isset( $this->database->prefix, $this->database->base_prefix )
			|| ! isset( $this->database->options )
			|| ! is_string( $this->database->prefix )
			|| ! is_string( $this->database->base_prefix )
			|| ! is_string( $this->database->options )
			|| '' === $this->database->prefix
			|| ! hash_equals( $this->database->base_prefix, $this->database->prefix )
			|| ! hash_equals( $this->database->base_prefix . 'options', $this->database->options )
		) {
			throw new RuntimeException( 'Booster could not verify the converted installation cleanup scope.' );
		}
	}

	private function assertCleanupCapabilities(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' )
			|| ! function_exists( 'delete_option' )
			|| ! function_exists( 'get_option' )
			|| ! isset( $this->database->prefix, $this->database->usermeta )
			|| ! is_string( $this->database->prefix )
			|| '' === $this->database->prefix
			|| ! is_string( $this->database->usermeta )
			|| '' === $this->database->usermeta
			|| ! method_exists( $this->database, 'prepare' )
			|| ! method_exists( $this->database, 'query' )
		) {
			throw new RuntimeException( 'Booster could not verify the local cleanup capabilities.' );
		}

		$this->verifiedTablePrefix = $this->database->prefix;
	}

	protected function loadedWpConfigPath(): string {
		$root = $this->canonicalDirectory( defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '' );
		if ( null === $root ) {
			throw new RuntimeException( 'The loaded WordPress configuration could not be verified.' );
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

		$loaded = array();
		foreach ( get_included_files() as $included ) {
			if ( ! is_string( $included ) || 'wp-config.php' !== basename( $included ) ) {
				continue;
			}
			$canonical = $this->canonicalRegularFile( $included );
			if ( null === $canonical ) {
				throw new RuntimeException( 'The loaded WordPress configuration could not be verified.' );
			}
			$loaded[] = $canonical;
		}

		$supported = array_values( array_unique( $supported ) );
		$loaded    = array_values( array_unique( $loaded ) );
		if ( array() === $loaded
			&& 1 === count( $supported )
			&& defined( 'WP_CLI' )
			&& true === WP_CLI
		) {
			return $supported[0];
		}
		if ( 1 !== count( $loaded ) || ! in_array( $loaded[0], $supported, true ) ) {
			throw new RuntimeException( 'The loaded WordPress configuration could not be verified.' );
		}

		return $loaded[0];
	}

	private function loadedWpConfigPathForRetry(): ?string {
		try {
			return $this->loadedWpConfigPath();
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function removeEmptyAutomaticDirectories( ?string $sidecarPath ): void {
		$automaticPath = $this->automaticSidecarPath();
		if ( null === $automaticPath
			|| ( null !== $sidecarPath && $sidecarPath !== $automaticPath )
		) {
			return;
		}

		$siteDirectory = dirname( $automaticPath );
		$baseDirectory = dirname( $siteDirectory );
		$this->removeDirectoryIfEmpty( $siteDirectory );
		$this->removeDirectoryIfEmpty( $baseDirectory );
	}

	private function removeWpConfigLock( string $configPath ): void {
		$lockPath = $configPath . '.ran-booster.lock';
		if ( ! file_exists( $lockPath ) && ! is_link( $lockPath ) ) {
			return;
		}

		$this->assertWpConfigLockRemovable( $configPath );
		if ( ! unlink( $lockPath ) ) {
			throw new RuntimeException( 'The Booster WordPress configuration lock could not be removed safely.' );
		}
	}

	private function assertWpConfigLockRemovable( string $configPath ): void {
		$lockPath = $configPath . '.ran-booster.lock';
		if ( ! file_exists( $lockPath ) && ! is_link( $lockPath ) ) {
			return;
		}

		$stat = lstat( $lockPath );
		if ( is_link( $lockPath )
			|| false === $stat
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| 1 !== $stat['nlink']
			|| 0600 !== ( $stat['mode'] & 0777 )
			|| 0 !== $stat['size']
			|| ! function_exists( 'posix_geteuid' )
			|| posix_geteuid() !== $stat['uid']
		) {
			throw new RuntimeException( 'The Booster WordPress configuration lock could not be removed safely.' );
		}
	}

	protected function automaticSidecarPath(): ?string {
		try {
			return $this->locationResolver->resolve(
				defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '',
				defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) ? WP_CONTENT_DIR : '',
				dirname( __DIR__, 2 ),
				isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
					? $_SERVER['DOCUMENT_ROOT']
					: null
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function removeDirectoryIfEmpty( string $directory ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}

		$contents = scandir( $directory );
		if ( array( '.', '..' ) !== $contents ) {
			return;
		}

		$stat = lstat( $directory );
		if ( false === $stat
			|| 0040000 !== ( $stat['mode'] & 0170000 )
			|| 0700 !== ( $stat['mode'] & 0777 )
			|| ! function_exists( 'posix_geteuid' )
			|| posix_geteuid() !== $stat['uid']
			|| ! rmdir( $directory )
		) {
			throw new RuntimeException( 'An empty Booster storage directory could not be removed safely.' );
		}
	}

	private function assertAutomaticDirectoriesRemovable( ?string $sidecarPath ): void {
		$automaticPath = $this->automaticSidecarPath();
		if ( null === $automaticPath
			|| ( null !== $sidecarPath && $sidecarPath !== $automaticPath )
		) {
			return;
		}

		$this->assertDirectoryRemovalSafe( dirname( $automaticPath ) );
		$this->assertDirectoryRemovalSafe( dirname( dirname( $automaticPath ) ) );
	}

	private function assertDirectoryRemovalSafe( string $directory ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}

		if ( array( '.', '..' ) !== scandir( $directory ) ) {
			return;
		}

		$stat = lstat( $directory );
		if ( false === $stat
			|| 0040000 !== ( $stat['mode'] & 0170000 )
			|| 0700 !== ( $stat['mode'] & 0777 )
			|| ! function_exists( 'posix_geteuid' )
			|| posix_geteuid() !== $stat['uid']
		) {
			throw new RuntimeException( 'An empty Booster storage directory could not be removed safely.' );
		}
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

	private function normalizePath( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}

<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use InvalidArgumentException;
use RAN\Deployment\PreparedArtifact;
use RAN\PackageSubdirectory;
use RAN\Runtime\RuntimeSupport;
use Throwable;
use WP_Error;

/**
 * Give one immutable package transaction to WordPress core.
 *
 * Provider work, persistence, locking, cleanup and postcondition policy belong
 * to the deployment runner. This class owns only the scoped WordPress call.
 */
class CorePackageExecutor {

	/** @var Closure(string, string, string, object|null): mixed|null */
	private ?Closure $coreOperation;

	/**
	 * The optional operation seam exists only for focused adapter tests.
	 *
	 * @param callable(string, string, string, object|null): mixed|null $coreOperation
	 */
	public function __construct( ?callable $coreOperation = null ) {
		$this->coreOperation = null === $coreOperation ? null : Closure::fromCallable( $coreOperation );
	}

	public function installPlugin(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->executeInstall( 'plugin', $artifact, $packageSlug, $subdirectory );
	}

	public function installTheme(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->executeInstall( 'theme', $artifact, $packageSlug, $subdirectory );
	}

	public function updatePlugin(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory,
		string $pluginFile
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->executeUpdate( 'plugin', $artifact, $packageSlug, $subdirectory, $pluginFile );
	}

	public function updateTheme(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory,
		string $stylesheet
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->executeUpdate( 'theme', $artifact, $packageSlug, $subdirectory, $stylesheet );
	}

	private function executeInstall(
		string $type,
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory
	): CorePackageExecutionResult {
		$inputs = $this->validateInputs( $artifact, $packageSlug, $subdirectory );
		if ( $inputs instanceof CorePackageExecutionResult ) {
			return $inputs;
		}
		if ( 'theme' === $type && ! $this->themeParentIsAvailable( $artifact, $inputs['slug'], $inputs['subdirectory'] ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}

		$preDownload  = $this->preDownloadFilter( $type, 'install', $artifact, null );
		$sourceFilter = $this->sourceSelectionFilter( $inputs['slug'], $inputs['subdirectory'], $type, 'install', null );
		$completions  = array();
		$complete     = $this->completionCollector( $completions );

		add_filter( 'upgrader_pre_download', $preDownload, 10, 4 );
		add_filter( 'upgrader_source_selection', $sourceFilter, 10, 4 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );

		try {
			$result = $this->runCoreOperation( 'install', $type, $inputs['path'], null );

			return $this->mapResult( $result, $type, 'install', null, $completions );
		} catch ( Throwable ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
		} finally {
			remove_filter( 'upgrader_pre_download', $preDownload, 10 );
			remove_filter( 'upgrader_source_selection', $sourceFilter, 10 );
			remove_action( 'upgrader_process_complete', $complete, 100 );
		}
	}

	private function executeUpdate(
		string $type,
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory,
		string $installedIdentifier
	): CorePackageExecutionResult {
		$inputs = $this->validateInputs( $artifact, $packageSlug, $subdirectory, $installedIdentifier );
		if ( $inputs instanceof CorePackageExecutionResult ) {
			return $inputs;
		}
		if ( ( 'plugin' === $type && dirname( $inputs['identifier'] ) !== $inputs['slug'] )
			|| ( 'theme' === $type && $inputs['identifier'] !== $inputs['slug'] )
		) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}

		$offer           = $this->updateOffer( $type, $artifact, $inputs['slug'], $inputs['identifier'] );
		$transientHook   = 'pre_site_transient_update_' . ( 'plugin' === $type ? 'plugins' : 'themes' );
		$transientFilter = $this->transientFilter( $type, $offer, $inputs['identifier'] );
		$preDownload     = $this->preDownloadFilter( $type, 'update', $artifact, $inputs['identifier'] );
		$sourceFilter    = $this->sourceSelectionFilter( $inputs['slug'], $inputs['subdirectory'], $type, 'update', $inputs['identifier'] );
		$vcsFilter       = $this->vcsFilter( $type, $inputs['identifier'] );
		$coreAutoUpdate  = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		$cronFilter      = static fn (): bool => true;
		$completions     = array();
		$complete        = $this->completionCollector( $completions );
		if ( false !== $coreAutoUpdate && ! remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $coreAutoUpdate ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
		}

		add_filter( $transientHook, $transientFilter, 10, 1 );
		add_filter( 'upgrader_pre_download', $preDownload, 10, 4 );
		add_filter( 'upgrader_source_selection', $sourceFilter, 10, 4 );
		add_filter( 'automatic_updates_is_vcs_checkout', $vcsFilter, 10, 2 );
		add_filter( 'wp_doing_cron', $cronFilter, PHP_INT_MAX, 1 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );

		try {
			$result = $this->runCoreOperation( 'update', $type, $inputs['path'], $offer );

			return $this->mapResult( $result, $type, 'update', $inputs['identifier'], $completions );
		} catch ( Throwable ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
		} finally {
			remove_filter( $transientHook, $transientFilter, 10 );
			remove_filter( 'upgrader_pre_download', $preDownload, 10 );
			remove_filter( 'upgrader_source_selection', $sourceFilter, 10 );
			remove_filter( 'automatic_updates_is_vcs_checkout', $vcsFilter, 10 );
			remove_filter( 'wp_doing_cron', $cronFilter, PHP_INT_MAX );
			remove_action( 'upgrader_process_complete', $complete, 100 );
			if ( false !== $coreAutoUpdate ) {
				add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $coreAutoUpdate );
			}
		}
	}

	/**
	 * @return array{path: string, slug: string, subdirectory: string|null, identifier: string}|CorePackageExecutionResult
	 */
	private function validateInputs(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory,
		string $installedIdentifier = ''
	): array|CorePackageExecutionResult {
		try {
			$artifact->assertUnchanged();
			$slug         = PackageSubdirectory::normalizeSlug( $packageSlug );
			$subdirectory = PackageSubdirectory::normalize( $subdirectory );
			$identifier   = '' === $installedIdentifier ? '' : $this->normalizeInstalledIdentifier( $installedIdentifier );
		} catch ( Throwable ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}

		return array(
			'path'         => $artifact->getPath(),
			'slug'         => $slug,
			'subdirectory' => $subdirectory,
			'identifier'   => $identifier,
		);
	}

	private function normalizeInstalledIdentifier( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( '' === $identifier
			|| str_starts_with( $identifier, '/' )
			|| str_contains( $identifier, '\\' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $identifier ) === 1
			|| preg_match( '#(^|/)\.\.?(/|$)#', $identifier ) === 1
		) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}

		return $identifier;
	}

	private function updateOffer( string $type, PreparedArtifact $artifact, string $slug, string $identifier ): object {
		$offer          = array(
			'id'           => 'ran-booster/' . $slug,
			'slug'         => $slug,
			'new_version'  => $artifact->getExpectedVersion(),
			'package'      => $artifact->getPath(),
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);
		$offer[ $type ] = $identifier;

		return (object) $offer;
	}

	private function transientFilter( string $type, object $offer, string $identifier ): Closure {
		return static function ( mixed $transient ) use ( $type, $offer, $identifier ): object {
			if ( ! is_object( $transient ) ) {
				$transient = new \stdClass();
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $identifier ] = 'plugin' === $type ? $offer : (array) $offer;

			return $transient;
		};
	}

	private function preDownloadFilter( string $type, string $action, PreparedArtifact $artifact, ?string $identifier ): Closure {
		return static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( $type, $action, $artifact, $identifier ): mixed {
			if ( false !== $reply ) {
				return $reply;
			}
			$archivePath         = $artifact->getPath();
			$operationIdentifier = null === $identifier || $identifier === ( $extra[ $type ] ?? null );
			if ( is_string( $package )
				&& hash_equals( $archivePath, $package )
				&& $type === ( $extra['type'] ?? null )
				&& $action === ( $extra['action'] ?? null )
				&& $operationIdentifier
			) {
				$artifact->assertUnchanged();

				return $archivePath;
			}

			return $reply;
		};
	}

	private function vcsFilter( string $type, string $identifier ): Closure {
		$allowedContext = WP_PLUGIN_DIR;
		if ( 'theme' === $type ) {
			$themeRoot = realpath( get_theme_root( $identifier ) );
			if ( false === $themeRoot || ! is_dir( $themeRoot ) ) {
				return static fn ( bool $checkout, string $context ): bool => $checkout;
			}
			$allowedContext = $themeRoot;
		}

		return static function ( bool $checkout, string $context ) use ( $type, $allowedContext ): bool {
			if ( 'plugin' === $type ) {
				return WP_PLUGIN_DIR === $context ? false : $checkout;
			}

			$canonicalContext = realpath( $context );

			return false !== $canonicalContext && hash_equals( $allowedContext, $canonicalContext ) ? false : $checkout;
		};
	}

	private function sourceSelectionFilter(
		string $slug,
		?string $subdirectory,
		string $type,
		string $action,
		?string $identifier
	): Closure {
		return static function ( mixed $source, mixed $remoteSource, mixed $upgrader, array $extra ) use ( $slug, $subdirectory, $type, $action, $identifier ): mixed {
			if ( $type !== ( $extra['type'] ?? null )
				|| $action !== ( $extra['action'] ?? null )
				|| ( null !== $identifier && $identifier !== ( $extra[ $type ] ?? null ) )
			) {
				return $source;
			}
			if ( ! is_string( $source ) || ! is_string( $remoteSource ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}
			$sourceRoot = realpath( $source );
			$remoteRoot = realpath( $remoteSource );
			if ( false === $sourceRoot || false === $remoteRoot || ! is_dir( $sourceRoot ) || ! is_dir( $remoteRoot ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}
			if ( ! self::isCanonicalChild( $sourceRoot, $remoteRoot ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			$selectedSource = $sourceRoot;
			if ( null !== $subdirectory ) {
				$selectedSource = realpath( $sourceRoot . DIRECTORY_SEPARATOR . $subdirectory );
				if ( false === $selectedSource || ! is_dir( $selectedSource ) || ! self::isCanonicalChild( $selectedSource, $sourceRoot ) ) {
					return new WP_Error( 'ran_booster_invalid_package_source' );
				}
			}

			$destination = $remoteRoot . DIRECTORY_SEPARATOR . $slug;
			if ( hash_equals( $selectedSource, $destination ) ) {
				return trailingslashit( $selectedSource );
			}
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			global $wp_filesystem;
			if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $selectedSource, $destination, false ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			return trailingslashit( $destination );
		};
	}

	private function themeParentIsAvailable( PreparedArtifact $artifact, string $slug, ?string $subdirectory ): bool {
		$parent = $this->themeParentFromArchive( $artifact, $subdirectory );
		if ( false === $parent ) {
			return false;
		}
		if ( null === $parent ) {
			return true;
		}
		if ( $slug === $parent || ! function_exists( 'wp_get_theme' ) ) {
			return false;
		}

		try {
			return wp_get_theme( $parent )->exists();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Read only the selected theme's Template header from the immutable ZIP.
	 *
	 * @return string|null|false Parent stylesheet, no parent, or invalid archive.
	 */
	private function themeParentFromArchive( PreparedArtifact $artifact, ?string $subdirectory ): string|null|false {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $artifact->getPath(), \ZipArchive::RDONLY ) ) {
			return false;
		}

		$subdirectorySegments = null === $subdirectory ? array() : explode( '/', $subdirectory );
		$candidates           = array();
		try {
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index );
				if ( ! is_string( $name ) || str_contains( $name, '\\' ) || str_starts_with( $name, '/' ) ) {
					continue;
				}
				$segments = explode( '/', trim( $name, '/' ) );
				if ( count( $segments ) !== count( $subdirectorySegments ) + 2
					|| 'style.css' !== end( $segments )
					|| $subdirectorySegments !== array_slice( $segments, 1, -1 )
				) {
					continue;
				}
				$candidates[] = $index;
			}
			if ( 1 !== count( $candidates ) ) {
				return false;
			}
			$header = $zip->getFromIndex( $candidates[0], 8192 );
		} finally {
			$zip->close();
		}
		if ( ! is_string( $header ) ) {
			return false;
		}
		if ( preg_match( '/^[ \t\/*#@]*Template:[ \t]*(.+)$/mi', $header, $match ) !== 1 ) {
			return null;
		}

		try {
			return PackageSubdirectory::normalizeSlug( trim( $match[1] ) );
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function isCanonicalChild( string $path, string $parent ): bool {
		return $path !== $parent && str_starts_with( $path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR );
	}

	/** @param list<array<string, mixed>> $completions */
	private function completionCollector( array &$completions ): Closure {
		return static function ( object $upgrader, array $extra ) use ( &$completions ): void {
			$completions[] = $extra;
		};
	}

	/** @param list<array<string, mixed>> $completions */
	private function mapResult(
		mixed $result,
		string $type,
		string $action,
		?string $identifier,
		array $completions
	): CorePackageExecutionResult {
		$successfulInstallation = $this->isCanonicalInstallationResult( $result );
		$requiresCompletion     = true === $result || $successfulInstallation || $this->isRestoredPluginFailure( $type, $result );
		if ( array() !== $completions || $requiresCompletion ) {
			if ( 1 !== count( $completions ) || ! $this->completionMatches( $completions[0], $type, $action, $identifier ) ) {
				return CorePackageExecutionResult::failed( CorePackageExecutionFailure::OPERATION_MISMATCH );
			}
		}

		if ( true === $result || $successfulInstallation ) {
			return CorePackageExecutionResult::succeeded();
		}
		if ( false === $result ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED );
		}
		if ( $this->isRestoredPluginFailure( $type, $result ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_RESTORED );
		}
		if ( $result instanceof WP_Error ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		}

		return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
	}

	/**
	 * WordPress may return WP_Upgrader::install_package()'s documented result
	 * array after a successful automatic plugin or theme update.
	 */
	private function isCanonicalInstallationResult( mixed $result ): bool {
		if ( ! is_array( $result )
			|| array_keys( $result ) !== array( 'source', 'source_files', 'destination', 'destination_name', 'local_destination', 'remote_destination', 'clear_destination' )
			|| ! is_string( $result['source'] )
			|| ! is_array( $result['source_files'] )
			|| ! array_is_list( $result['source_files'] )
			|| ! is_string( $result['destination'] )
			|| ! is_string( $result['destination_name'] )
			|| ! is_string( $result['local_destination'] )
			|| ! is_string( $result['remote_destination'] )
			|| ! is_bool( $result['clear_destination'] ) ) {
			return false;
		}

		foreach ( $result['source_files'] as $sourceFile ) {
			if ( ! is_string( $sourceFile ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string, mixed> $completion */
	private function completionMatches( array $completion, string $type, string $action, ?string $identifier ): bool {
		if ( $type !== ( $completion['type'] ?? null ) || $action !== ( $completion['action'] ?? null ) ) {
			return false;
		}
		if ( null === $identifier ) {
			return true;
		}

		return $identifier === ( $completion[ $type ] ?? null );
	}

	private function isRestoredPluginFailure( string $type, mixed $result ): bool {
		return 'plugin' === $type
			&& $result instanceof WP_Error
			&& 'plugin_update_fatal_error_rollback_successful' === $result->get_error_code();
	}

	private function runCoreOperation( string $action, string $type, string $archivePath, ?object $offer ): mixed {
		if ( null !== $this->coreOperation ) {
			return ( $this->coreOperation )( $action, $type, $archivePath, $offer );
		}

		$this->loadWordPressUpgraders();
		if ( 'install' === $action ) {
			$skin = new \Automatic_Upgrader_Skin();

			return 'plugin' === $type
				? ( new \Plugin_Upgrader( $skin ) )->install( $archivePath )
				: ( new \Theme_Upgrader( $skin ) )->install( $archivePath );
		}

		return ( new \WP_Automatic_Updater() )->update( $type, $offer );
	}

	private function loadWordPressUpgraders(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new \RuntimeException( 'WordPress is unavailable.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	}
}

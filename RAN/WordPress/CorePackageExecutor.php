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
 * Install one immutable prospective package through WordPress core.
 *
 * Provider work, persistence, locking, cleanup and postcondition policy belong
 * to the deployment runner. This class owns only the scoped WordPress call.
 */
class CorePackageExecutor {

	/** @var Closure(string, string, string, object|null): mixed|null */
	private ?Closure $core_operation;

	/**
	 * The optional operation seam exists only for focused adapter tests.
	 *
	 * @param callable(string, string, string, object|null): mixed|null $core_operation
	 */
	public function __construct( ?callable $core_operation = null ) {
		$this->core_operation = null;
		if ( null !== $core_operation ) {
			$this->core_operation = Closure::fromCallable( $core_operation );
		}
	}

	public function install_plugin(
		PreparedArtifact $artifact,
		string $package_slug,
		?string $subdirectory
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->execute_install( 'plugin', $artifact, $package_slug, $subdirectory );
	}

	public function install_theme(
		PreparedArtifact $artifact,
		string $package_slug,
		?string $subdirectory
	): CorePackageExecutionResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED );
		}

		return $this->execute_install( 'theme', $artifact, $package_slug, $subdirectory );
	}

	private function execute_install(
		string $type,
		PreparedArtifact $artifact,
		string $package_slug,
		?string $subdirectory
	): CorePackageExecutionResult {
		$inputs = $this->validate_inputs( $artifact, $package_slug, $subdirectory );
		if ( $inputs instanceof CorePackageExecutionResult ) {
			return $inputs;
		}
		if ( 'theme' === $type && ! $this->theme_parent_is_available( $artifact, $inputs['slug'], $inputs['subdirectory'] ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}

		$pre_download  = $this->pre_download_filter( $type, $artifact );
		$source_filter = $this->source_selection_filter( $inputs['slug'], $inputs['subdirectory'], $type );
		$completions   = array();
		$complete      = $this->completion_collector( $completions );

		add_filter( 'upgrader_pre_download', $pre_download, 10, 4 );
		add_filter( 'upgrader_source_selection', $source_filter, 10, 4 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );

		try {
			$result = $this->run_core_operation( $type, $inputs['path'] );

			return $this->map_result( $result, $type, $completions );
		} catch ( Throwable ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
		} finally {
			remove_filter( 'upgrader_pre_download', $pre_download, 10 );
			remove_filter( 'upgrader_source_selection', $source_filter, 10 );
			remove_action( 'upgrader_process_complete', $complete, 100 );
		}
	}

	/**
	 * @return array{path: string, slug: string, subdirectory: string|null}|CorePackageExecutionResult
	 */
	private function validate_inputs(
		PreparedArtifact $artifact,
		string $package_slug,
		?string $subdirectory
	): array|CorePackageExecutionResult {
		try {
			$artifact->assert_unchanged();
			$slug         = PackageSubdirectory::normalize_slug( $package_slug );
			$subdirectory = PackageSubdirectory::normalize( $subdirectory );
		} catch ( Throwable ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}

		return array(
			'path'         => $artifact->get_path(),
			'slug'         => $slug,
			'subdirectory' => $subdirectory,
		);
	}

	private function pre_download_filter( string $type, PreparedArtifact $artifact ): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress upgrader_pre_download supplies the upgrader in slot three before the required hook metadata.
		return static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( $type, $artifact ): mixed {
			if ( false !== $reply ) {
				return $reply;
			}
			$archive_path = $artifact->get_path();
			if ( is_string( $package )
				&& hash_equals( $archive_path, $package )
				&& ( $extra['type'] ?? null ) === $type
				&& ( $extra['action'] ?? null ) === 'install'
			) {
				$artifact->assert_unchanged();

				return $archive_path;
			}

			return $reply;
		};
	}

	private function source_selection_filter(
		string $slug,
		?string $subdirectory,
		string $type
	): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress upgrader_source_selection supplies the upgrader in slot three before the required hook metadata.
		return static function ( mixed $source, mixed $remote_source, mixed $upgrader, array $extra ) use ( $slug, $subdirectory, $type ): mixed {
			if ( ( $extra['type'] ?? null ) !== $type
				|| ( $extra['action'] ?? null ) !== 'install'
			) {
				return $source;
			}
			if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}
			$source_root = realpath( $source );
			$remote_root = realpath( $remote_source );
			if ( false === $source_root || false === $remote_root || ! is_dir( $source_root ) || ! is_dir( $remote_root ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}
			if ( ! self::is_canonical_child( $source_root, $remote_root ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			$selected_source = $source_root;
			if ( null !== $subdirectory ) {
				$selected_source = realpath( $source_root . DIRECTORY_SEPARATOR . $subdirectory );
				if ( false === $selected_source || ! is_dir( $selected_source ) || ! self::is_canonical_child( $selected_source, $source_root ) ) {
					return new WP_Error( 'ran_booster_invalid_package_source' );
				}
			}

			$destination = $remote_root . DIRECTORY_SEPARATOR . $slug;
			if ( hash_equals( $selected_source, $destination ) ) {
				return trailingslashit( $selected_source );
			}
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			global $wp_filesystem;
			$move = array( $wp_filesystem, 'move' );
			if ( ! is_object( $wp_filesystem ) || ! is_callable( $move ) || ! $move( $selected_source, $destination, false ) ) {
				return new WP_Error( 'ran_booster_invalid_package_source' );
			}

			return trailingslashit( $destination );
		};
	}

	private function theme_parent_is_available( PreparedArtifact $artifact, string $slug, ?string $subdirectory ): bool {
		$parent_path = $this->theme_parent_from_archive( $artifact, $subdirectory );
		if ( false === $parent_path ) {
			return false;
		}
		if ( null === $parent_path ) {
			return true;
		}
		if ( $slug === $parent_path || ! function_exists( 'wp_get_theme' ) ) {
			return false;
		}

		try {
			return wp_get_theme( $parent_path )->exists();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Read only the selected theme's Template header from the immutable ZIP.
	 *
	 * @return string|null|false Parent stylesheet, no parent, or invalid archive.
	 */
	private function theme_parent_from_archive( PreparedArtifact $artifact, ?string $subdirectory ): string|null|false {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $artifact->get_path(), \ZipArchive::RDONLY ) ) {
			return false;
		}

		$subdirectory_segments = null === $subdirectory ? array() : explode( '/', $subdirectory );
		$candidates            = array();
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive retains its native public property contract.
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index );
				if ( ! is_string( $name ) || str_contains( $name, '\\' ) || str_starts_with( $name, '/' ) ) {
					continue;
				}
				$segments = explode( '/', trim( $name, '/' ) );
				if ( count( $segments ) !== count( $subdirectory_segments ) + 2
					|| 'style.css' !== end( $segments )
					|| array_slice( $segments, 1, -1 ) !== $subdirectory_segments
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
			return PackageSubdirectory::normalize_slug( trim( $match[1] ) );
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function is_canonical_child( string $path, string $parent_path ): bool {
		return $path !== $parent_path && str_starts_with( $path . DIRECTORY_SEPARATOR, $parent_path . DIRECTORY_SEPARATOR );
	}

	/** @param list<array<string, mixed>> $completions */
	private function completion_collector( array &$completions ): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress upgrader_process_complete supplies the upgrader before the collected completion metadata.
		return static function ( object $upgrader, array $extra ) use ( &$completions ): void {
			$completions[] = $extra;
		};
	}

	/** @param list<array<string, mixed>> $completions */
	private function map_result(
		mixed $result,
		string $type,
		array $completions
	): CorePackageExecutionResult {
		$successful_installation = $this->is_canonical_installation_result( $result );
		$requires_completion     = true === $result || $successful_installation || $this->is_restored_plugin_failure( $type, $result );
		if ( array() !== $completions || $requires_completion ) {
			if ( 1 !== count( $completions ) || ! $this->completion_matches( $completions[0], $type ) ) {
				return CorePackageExecutionResult::failed( CorePackageExecutionFailure::OPERATION_MISMATCH );
			}
		}

		if ( true === $result || $successful_installation ) {
			return CorePackageExecutionResult::succeeded();
		}
		if ( false === $result ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED );
		}
		if ( $this->is_restored_plugin_failure( $type, $result ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_RESTORED );
		}
		if ( $result instanceof WP_Error ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		}

		return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
	}

	/**
	 * WordPress may return WP_Upgrader::install_package()'s documented result
	 * array after a successful plugin or theme installation.
	 */
	private function is_canonical_installation_result( mixed $result ): bool {
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

		foreach ( $result['source_files'] as $source_file ) {
			if ( ! is_string( $source_file ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string, mixed> $completion */
	private function completion_matches( array $completion, string $type ): bool {
		return ( $completion['type'] ?? null ) === $type && ( $completion['action'] ?? null ) === 'install';
	}

	private function is_restored_plugin_failure( string $type, mixed $result ): bool {
		return 'plugin' === $type
			&& $result instanceof WP_Error
			&& 'plugin_update_fatal_error_rollback_successful' === $result->get_error_code();
	}

	private function run_core_operation( string $type, string $archive_path ): mixed {
		if ( null !== $this->core_operation ) {
			return ( $this->core_operation )( 'install', $type, $archive_path, null );
		}

		$this->load_wordpress_upgraders();
		$skin = new \Automatic_Upgrader_Skin();

		return 'plugin' === $type
			? ( new \Plugin_Upgrader( $skin ) )->install( $archive_path )
			: ( new \Theme_Upgrader( $skin ) )->install( $archive_path );
	}

	private function load_wordpress_upgraders(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new \RuntimeException( 'WordPress is unavailable.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
}

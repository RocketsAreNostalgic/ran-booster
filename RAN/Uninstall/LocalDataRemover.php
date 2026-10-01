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
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\PrivateLocationCandidateResolver;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Storage\Database;
use RuntimeException;

/**
 * Removes verified local state owned by Core and bundled components during WordPress uninstall.
 */
class LocalDataRemover {

	private const OPTION_NAMES = array(
		Database::VERSION_OPTION,
		CredentialExpiryObservationStore::OPTION_NAME,
		PublicRepositoryLookupProfileStore::OPTION_NAME,
		RepositoryBranchCheckEvidenceStore::OPTION_NAME,
		WordPressInstallationStore::OPTION_NAME,
	);

	private const USER_META_KEYS = array(
		DevelopmentSafetyNoticeController::USER_META_KEY,
		CredentialExpiryNotice::USER_META_KEY,
		DeploymentAdminPresenter::USER_META_KEY,
	);

	private object $database;
	private ?string $verified_table_prefix = null;

	public function __construct(
		private readonly SecretsFile $secrets,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly TemporaryDebugCapture $debugCapture,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly WpConfigSecretsPathWriter $configWriter,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly PrivateLocationCandidateResolver $locationResolver = new PrivateLocationCandidateResolver(),
		?object $database = null
	) {
		global $wpdb;

		$this->database = $database ?? $wpdb;
	}

	public function remove(): void {
		$this->assert_exact_converted_site_scope();

		$sidecar_path = $this->secrets->path();
		$config_path  = null === $sidecar_path
			? $this->loaded_wp_config_path_for_retry()
			: $this->loaded_wp_config_path();

		$this->assert_cleanup_capabilities();
		if ( null !== $config_path ) {
			if ( null !== $sidecar_path ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$owned_definition = $this->configWriter->assert_owned_definition_removable( $config_path, $sidecar_path );
				if ( function_exists( 'is_multisite' ) && is_multisite() && ! $owned_definition ) {
					throw new RuntimeException( 'Booster could not verify the converted installation configuration ownership.' );
				}
			}
			$this->assert_wp_config_lock_removable( $config_path );
		}
		$this->secrets->assert_managed_storage_deletable();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		$this->debugCapture->assert_managed_storage_deletable();
		$this->assert_automatic_directories_removable( $sidecar_path );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		$this->debugCapture->delete_managed_storage();
		$this->secrets->delete_managed_storage();
		$this->clear_scheduled_work();
		$this->clear_updater_state();
		if ( ! ( new WorkflowAssistanceState() )->removeDurableState() ) {
			throw new RuntimeException( 'Bundled GitHub provider state could not be removed.' );
		}
		$this->clear_user_metadata();
		$this->drop_tables();
		$this->delete_options();

		if ( null !== $config_path ) {
			if ( null !== $sidecar_path ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$this->configWriter->remove_owned_definition( $config_path, $sidecar_path );
			}
			$this->remove_wp_config_lock( $config_path );
		}

		$this->remove_empty_automatic_directories( $sidecar_path );
	}

	protected function clear_scheduled_work(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			throw new RuntimeException( 'Booster scheduled work could not be removed.' );
		}
		if ( false === wp_clear_scheduled_hook( WordPressWorkerWakeup::HOOK, array() ) ) {
			throw new RuntimeException( 'Booster scheduled work could not be removed.' );
		}
	}

	protected function clear_updater_state(): void {
		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			throw new RuntimeException( 'Booster updater state could not be removed.' );
		}

		$missing = new \stdClass();
		$option  = $this->package_updater_authority_option();
		delete_option( $option );
		if ( get_option( $option, $missing ) !== $missing ) {
			throw new RuntimeException( 'Booster updater state could not be removed.' );
		}
	}

	private function package_updater_authority_option(): string {
		$target = implode( "\0", array( 'plugin', 'ran-booster', 'ran-booster.php' ) );
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}

	protected function clear_user_metadata(): void {
		if ( ! isset( $this->database->usermeta )
			|| ! is_string( $this->database->usermeta )
			|| ! method_exists( $this->database, 'prepare' )
			|| ! method_exists( $this->database, 'query' )
		) {
			throw new RuntimeException( 'Booster user notices could not be removed.' );
		}

		foreach ( self::USER_META_KEYS as $meta_key ) {
			$query = $this->database->prepare(
				'DELETE FROM %i WHERE meta_key = %s',
				$this->database->usermeta,
				$meta_key
			);
			if ( false === $this->database->query( $query ) ) {
				throw new RuntimeException( 'Booster user notices could not be removed.' );
			}
		}
	}

	protected function drop_tables(): void {
		if ( null === $this->verified_table_prefix
			|| ! method_exists( $this->database, 'prepare' )
			|| ! method_exists( $this->database, 'query' )
		) {
			throw new RuntimeException( 'Booster tables could not be removed.' );
		}

		foreach (
			array(
				$this->verified_table_prefix . 'ran_booster_packages',
				$this->verified_table_prefix . 'ran_booster_deployment_attempts',
			) as $table
		) {
			$query = $this->database->prepare( 'DROP TABLE IF EXISTS %i', $table );
			if ( false === $this->database->query( $query ) ) {
				throw new RuntimeException( 'Booster tables could not be removed.' );
			}
		}
	}

	protected function delete_options(): void {
		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			throw new RuntimeException( 'Booster options could not be removed.' );
		}

		$missing = new \stdClass();
		foreach ( array_values( array_unique( self::OPTION_NAMES ) ) as $option ) {
			delete_option( $option );
			if ( get_option( $option, $missing ) !== $missing ) {
				throw new RuntimeException( 'Booster options could not be removed.' );
			}
		}
	}

	private function assert_exact_converted_site_scope(): void {
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

	private function assert_cleanup_capabilities(): void {
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

		$this->verified_table_prefix = $this->database->prefix;
	}

	protected function loaded_wp_config_path(): string {
		$root = $this->canonical_directory( defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '' );
		if ( null === $root ) {
			throw new RuntimeException( 'The loaded WordPress configuration could not be verified.' );
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

		$loaded = array();
		foreach ( get_included_files() as $included ) {
			if ( ! is_string( $included ) || 'wp-config.php' !== basename( $included ) ) {
				continue;
			}
			$canonical = $this->canonical_regular_file( $included );
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

	private function loaded_wp_config_path_for_retry(): ?string {
		try {
			return $this->loaded_wp_config_path();
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function remove_empty_automatic_directories( ?string $sidecar_path ): void {
		$automatic_path = $this->automatic_sidecar_path();
		if ( null === $automatic_path
			|| ( null !== $sidecar_path && $sidecar_path !== $automatic_path )
		) {
			return;
		}

		$site_directory = dirname( $automatic_path );
		$base_directory = dirname( $site_directory );
		$this->remove_directory_if_empty( $site_directory );
		$this->remove_directory_if_empty( $base_directory );
	}

	private function remove_wp_config_lock( string $config_path ): void {
		$lock_path = $config_path . '.ran-booster.lock';
		if ( ! file_exists( $lock_path ) && ! is_link( $lock_path ) ) {
			return;
		}

		$this->assert_wp_config_lock_removable( $config_path );
		if ( ! unlink( $lock_path ) ) {
			throw new RuntimeException( 'The Booster WordPress configuration lock could not be removed safely.' );
		}
	}

	private function assert_wp_config_lock_removable( string $config_path ): void {
		$lock_path = $config_path . '.ran-booster.lock';
		if ( ! file_exists( $lock_path ) && ! is_link( $lock_path ) ) {
			return;
		}

		$stat = lstat( $lock_path );
		if ( is_link( $lock_path )
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

	protected function automatic_sidecar_path(): ?string {
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
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

	private function remove_directory_if_empty( string $directory ): void {
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

	private function assert_automatic_directories_removable( ?string $sidecar_path ): void {
		$automatic_path = $this->automatic_sidecar_path();
		if ( null === $automatic_path
			|| ( null !== $sidecar_path && $sidecar_path !== $automatic_path )
		) {
			return;
		}

		$this->assert_directory_removal_safe( dirname( $automatic_path ) );
		$this->assert_directory_removal_safe( dirname( dirname( $automatic_path ) ) );
	}

	private function assert_directory_removal_safe( string $directory ): void {
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

	private function normalize_path( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}

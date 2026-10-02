<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use Throwable;

/**
 * Registers release-owned packages with the shared native updater.
 *
 * One malformed target is isolated from every other target.
 */
final class ManagedReleaseTargetRegistrar {

	/** @var array<string, RepositoryReleaseNativeTarget> */
	private array $targets = array();

	/** @var array<string, array<string, int|string>> */
	private array $registered_authorities = array();

	/** @var array<string, string> */
	private array $failures = array();

	private bool $registered                            = false;
	private ?string $core_self_update_plugin_identifier = null;
	private RepositorySourceGuard $source_guard;

	/** @var array<string, array{authority: array<string, int|string>, automatic: bool, lock: ?string, restore: bool}> */
	private array $native_updates = array();

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ManagedReleaseStore $store,
		private WordPressUpdaterLock $updater_lock,
		private ProviderRegistry $providers,
		?RepositorySourceGuard $source_guard = null,
		private readonly ?string $bulk_forbidden_plugin_identifier = null
	) {
		$this->source_guard = $source_guard ?? new RepositorySourceGuard();
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return;
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'upgrader_pre_download', array( $this, 'authorize_native_download' ), PHP_INT_MIN, 4 );
			add_filter( 'upgrader_pre_install', array( $this, 'fence_native_mutation' ), 1, 2 );
			add_filter( 'site_transient_update_plugins', array( $this, 'suppress_unauthorized_plugin_offers' ), PHP_INT_MAX );
			add_filter( 'site_transient_update_themes', array( $this, 'suppress_unauthorized_theme_offers' ), PHP_INT_MAX );
			add_action( 'upgrader_process_complete', array( $this, 'complete_native_mutation' ), PHP_INT_MAX, 2 );
		}

		try {
			$plugins = $this->plugins->all_deployment_plugins( PackageSource::RELEASE_ASSET );
		} catch ( Throwable $exception ) {
			$this->failures[ self::key( 'plugin', '*' ) ] = 'repository_read_failed';
			BoosterLogger::log_exception(
				'managed release repository read unavailable',
				$exception,
				array(
					'source' => 'plugin',
					'step'   => 'managed_release_target_registration',
				)
			);
			$plugins = array();
		}
		try {
			$themes = $this->themes->all_deployment_themes( PackageSource::RELEASE_ASSET );
		} catch ( Throwable $exception ) {
			$this->failures[ self::key( 'theme', '*' ) ] = 'repository_read_failed';
			BoosterLogger::log_exception(
				'managed release repository read unavailable',
				$exception,
				array(
					'source' => 'theme',
					'step'   => 'managed_release_target_registration',
				)
			);
			$themes = array();
		}
		$conflicts = $this->release_repository_conflicts( $plugins, $themes );
		$this->register_packages( 'plugin', $plugins, $conflicts );
		$this->register_packages( 'theme', $themes, $conflicts );
	}

	public function reserve_core_self_update_target( string $installed_identifier ): void {
		$identity = self::plugin_target_identity( $installed_identifier );
		if ( '' !== $identity ) {
			$this->core_self_update_plugin_identifier = $identity;
		}
	}

	public function has_reserved_core_self_update_target( string $package_type, string $installed_identifier ): bool {
		return 'plugin' === $package_type
			&& null !== $this->core_self_update_plugin_identifier
			&& hash_equals( $this->core_self_update_plugin_identifier, self::plugin_target_identity( $installed_identifier ) );
	}

	public function suppress_unauthorized_plugin_offers( mixed $transient ): mixed {
		return $this->suppress_unauthorized_offers( 'plugin', $transient );
	}

	public function suppress_unauthorized_theme_offers( mixed $transient ): mixed {
		return $this->suppress_unauthorized_offers( 'theme', $transient );
	}

	/**
	 * Snapshot live Core authority before the updater performs remote work.
	 *
	 * @param array<string, mixed> $hook_extra
	 */
	public function authorize_native_download(
		mixed $reply,
		string $package,
		object $upgrader,
		array $hook_extra
	): mixed {
		unset( $package );
		$bulk               = true === ( $upgrader->bulk ?? false );
		$single_target_bulk = $bulk
			&& 1 === ( $upgrader->update_count ?? null )
			&& 1 === ( $upgrader->update_current ?? null );
		$target             = $this->native_target( $hook_extra, $bulk );
		if ( $bulk && null !== $target && 'plugin' === $target['type'] && $this->bulk_forbidden_plugin_identifier === $target['identifier'] ) {
			return $this->native_update_error( 'unsupported_context' );
		}
		if ( null === $target ) {
			return $reply;
		}
		$key = self::key( $target['type'], $target['identifier'] );
		if ( $reply instanceof \WP_Error ) {
			unset( $this->native_updates[ $key ] );

			return $reply;
		}
		try {
			$snapshot = $this->native_authority_snapshot( $target['type'], $target['identifier'] );
		} catch ( PluginNotFound | ThemeNotFound ) {
			return isset( $this->registered_authorities[ $key ] ) || isset( $this->targets[ $key ] )
				? $this->native_update_error( 'authority_changed' )
				: $reply;
		} catch ( Throwable ) {
			return $this->native_update_error( 'authority_changed' );
		}
		if ( ! $snapshot['release'] ) {
			return $this->has_native_target_state( $key )
				? $this->native_update_error( 'authority_changed' )
				: $reply;
		}
		$authority = $snapshot['authority'];
		if ( null === $authority
			|| ! $this->native_target_is_active( $target['type'], $target['identifier'] )
			|| ( $this->registered_authorities[ $key ] ?? null ) !== $authority ) {
			return $this->native_update_error( 'authority_changed' );
		}
		try {
			PackageMutationGuard::assert_package_mutation_allowed();
		} catch ( Throwable ) {
			return $this->native_update_error( 'authority_changed' );
		}
		if ( $bulk && ! $single_target_bulk ) {
			return $this->native_update_error( 'unsupported_context' );
		}
		$automatic = isset( $upgrader->skin )
			&& is_object( $upgrader->skin )
			&& 'Automatic_Upgrader_Skin' === get_class( $upgrader->skin );
		if ( $automatic && ( ! function_exists( 'doing_action' ) || ! doing_action( 'wp_maybe_auto_update' ) ) ) {
			return $this->native_update_error( 'unsupported_context' );
		}
		$outer_lock = null;
		if ( $automatic ) {
			try {
				$outer_lock = $this->updater_lock->current_token();
			} catch ( Throwable ) {
				$outer_lock = null;
			}
			if ( null === $outer_lock ) {
				return $this->native_update_error( 'unsupported_context' );
			}
		}
		$this->native_updates[ $key ] = array(
			'authority' => $authority,
			'automatic' => $automatic,
			'lock'      => $outer_lock,
			'restore'   => ! empty( $hook_extra['temp_backup'] ),
		);

		return $reply;
	}

	/**
	 * Fence the exact single target before WordPress mutates its installation.
	 *
	 * @param array<string, mixed> $hook_extra
	 */
	public function fence_native_mutation( mixed $reply, array $hook_extra ): mixed {
		$target = $this->native_target( $hook_extra, true );
		if ( null === $target ) {
			return $reply;
		}
		$key        = self::key( $target['type'], $target['identifier'] );
		$pending    = $this->native_updates[ $key ] ?? null;
		$lock       = $this->updater_lock;
		$lock_token = null;
		if ( $reply instanceof \WP_Error ) {
			unset( $this->native_updates[ $key ] );

			return $reply;
		}
		if ( null === $pending ) {
			try {
				$snapshot = $this->native_authority_snapshot( $target['type'], $target['identifier'] );
			} catch ( PluginNotFound | ThemeNotFound ) {
				return isset( $this->registered_authorities[ $key ] ) || isset( $this->targets[ $key ] )
					? $this->native_update_error( 'authority_changed' )
					: $reply;
			} catch ( Throwable ) {
				return $this->native_update_error( 'authority_changed' );
			}
			if ( ! $snapshot['release'] && ! $this->has_native_target_state( $key ) ) {
				return $reply;
			}

			return $this->native_update_error( 'authority_changed' );
		}
		try {
			PackageMutationGuard::assert_package_mutation_allowed();
			if ( $pending['automatic'] ) {
				if ( ! function_exists( 'doing_action' )
					|| ! doing_action( 'wp_maybe_auto_update' )
					|| null === $pending['lock']
					|| ! hash_equals( $pending['lock'], (string) $lock->current_token() ) ) {
					throw new \RuntimeException( 'The WordPress automatic updater lock is unavailable.' );
				}
			} else {
				$lock_token = $lock->acquire();
			}
			$snapshot = $this->native_authority_snapshot( $target['type'], $target['identifier'] );
			$current  = $snapshot['authority'];
			if ( ! $snapshot['release']
				|| null === $current
				|| ! $this->native_target_is_active( $target['type'], $target['identifier'] )
				|| $pending['authority'] !== $current ) {
				throw new \RuntimeException( 'The managed release authority changed.' );
			}
			if ( null !== $lock_token ) {
				$this->native_updates[ $key ]['lock'] = $lock_token;
			}

			return $reply;
		} catch ( Throwable ) {
			if ( null !== $lock_token ) {
				try {
					$lock->release( $lock_token );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A hard-stop-safe stale lock is preferable to hiding the authority failure.
				} catch ( Throwable ) {
					// The exact lock token remains available for stale-lock recovery.
				}
			}
			unset( $this->native_updates[ $key ] );

			return $this->native_update_error( 'authority_changed' );
		}
	}

	/** @param array<string, mixed> $hook_extra */
	public function complete_native_mutation( object $upgrader, array $hook_extra ): void {
		$target = $this->native_target( $hook_extra, ( true === ( $hook_extra['bulk'] ?? false ) ) );
		if ( null === $target ) {
			return;
		}
		$key     = self::key( $target['type'], $target['identifier'] );
		$pending = $this->native_updates[ $key ] ?? null;
		unset( $this->native_updates[ $key ] );
		if ( null === $pending
			|| $pending['automatic']
			|| null === $pending['lock'] ) {
			return;
		}

		$release = function () use ( $pending ): void {
			try {
				if ( ! $this->updater_lock->release( $pending['lock'] ) ) {
					throw new \RuntimeException( 'The native update lock was replaced.' );
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::log_exception(
					'native update lock release failed',
					$failure,
					array( 'step' => 'native_update_lock_release' )
				);
			}
		};
		$failed  = ( $upgrader->skin->result ?? null ) instanceof \WP_Error
			|| ( $upgrader->result ?? null ) instanceof \WP_Error;
		if ( $failed && $pending['restore'] ) {
			add_action( 'shutdown', $release, PHP_INT_MAX, 0 );

			return;
		}
		$release();
	}

	public function target( string $type, string $identifier ): ?RepositoryReleaseNativeTarget {
		return $this->targets[ self::key( $type, $identifier ) ] ?? null;
	}

	public function status( string $type, string $identifier ): ?RepositoryReleaseNativeTargetStatus {
		$target = $this->target( $type, $identifier );
		if ( null === $target ) {
			return null;
		}
		try {
			return $target->status();
		} catch ( Throwable ) {
			return null;
		}
	}

	public function failure_code( string $type, string $identifier ): string {
		return $this->failures[ self::key( $type, $identifier ) ]
			?? $this->failures[ self::key( $type, '*' ) ]
			?? '';
	}

	/**
	 * @param array<string, Package> $packages
	 * @param array<string, string> $conflicts
	 */
	private function register_packages( string $type, array $packages, array $conflicts = array() ): void {
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || PackageSource::RELEASE_ASSET !== $package->get_source() ) {
				continue;
			}
			$identifier = (string) $package->get_identifier();
			$key        = self::key( $type, $identifier );
			if ( isset( $conflicts[ $key ] ) ) {
				$this->failures[ $key ] = $conflicts[ $key ];

				continue;
			}
			if ( null !== $package->get_subdirectory() ) {
				$this->failures[ $key ] = 'subdirectory_not_supported';

				continue;
			}
			try {
				$this->targets[ $key ] = $this->register_package( $type, $package );
			} catch ( Throwable ) {
				unset( $this->targets[ $key ], $this->registered_authorities[ $key ] );
				$this->failures[ $key ] = 'target_registration_failed';
			}
		}
	}

	/**
	 * Quarantine pre-existing release-source collisions before registering a
	 * provider target. Persistence remains the authority for stale rows and
	 * concurrent writes; this bootstrap pass prevents ambiguous native offers.
	 *
	 * @param array<string, Package> $plugins
	 * @param array<string, Package> $themes
	 * @return array<string, string>
	 */
	private function release_repository_conflicts( array $plugins, array $themes ): array {
		$packages  = array();
		$conflicts = array();
		foreach (
			array(
				'plugin' => $plugins,
				'theme'  => $themes,
			) as $type => $group
		) {
			foreach ( $group as $package ) {
				if ( ! $package instanceof Package || PackageSource::RELEASE_ASSET !== $package->get_source() ) {
					continue;
				}
				$identifier       = (string) $package->get_identifier();
				$key              = self::key( $type, $identifier );
				$packages[ $key ] = $package;
			}
		}
		foreach ( $packages as $key => $package ) {
			$type       = str_starts_with( $key, "plugin\0" ) ? 'plugin' : 'theme';
			$identifier = (string) $package->get_identifier();
			$provider   = (string) $package->get_provider_code();
			$repository = (string) $package->get_provider_repository_id();
			try {
				$assessment = $this->source_guard->assess(
					$provider,
					$repository,
					'plugin' === $type ? 1 : 2,
					$identifier,
					PackageSource::RELEASE_ASSET
				);
			} catch ( Throwable $exception ) {
				$conflicts[ $key ] = 'repository_source_unavailable';
				BoosterLogger::log_exception(
					'managed release repository source unavailable',
					$exception,
					array(
						'source' => $type,
						'step'   => 'managed_release_repository_exclusivity',
					)
				);

				continue;
			}
			if ( ! $assessment['allowed'] ) {
				$conflicts[ $key ] = $assessment['code'];
			}
		}

		return $conflicts;
	}

	private function register_package( string $type, Package $package ): RepositoryReleaseNativeTarget {
		$identifier    = (string) $package->get_identifier();
		$configuration = $this->store->configuration( $type, $identifier );
		$provider_code = $package->get_provider_code();
		if ( null === $configuration
			|| null === $provider_code
			|| ! $this->providers->is_sealed()
			|| ! $this->configuration_matches_package( $type, $identifier, $configuration ) ) {
			throw new \RuntimeException( 'The managed release target is ineligible.' );
		}
		$native_targets = $this->providers->require_capability( $provider_code, RepositoryReleaseNativeTargets::class );
		$metadata_file  = $this->metadata_path( $type, $configuration, $identifier );
		$target         = $native_targets->create_native_target(
			$type,
			$package->get_repository()->reference,
			$metadata_file,
			$configuration->package_root(),
			$identifier,
			$configuration->channel(),
			$package->get_deployment_policy()->value
		);
		if ( ! $target->register() ) {
			throw new \RuntimeException( 'The managed release target could not be registered.' );
		}
		$this->registered_authorities[ self::key( $type, $identifier ) ] = $this->authority(
			$package,
			$configuration
		);

		return $target;
	}

	/**
	 * @return array{type: 'plugin'|'theme', identifier: string}|null
	 */
	private function native_target( array $hook_extra, bool $bulk = false ): ?array {
		$action = $hook_extra['action'] ?? null;
		$type   = $hook_extra['type'] ?? null;
		if ( ( null !== $action && 'update' !== $action )
			|| ( null !== $type && ! in_array( $type, array( 'plugin', 'theme' ), true ) )
			|| ( ! $bulk && ( 'update' !== $action || null === $type ) ) ) {
			return null;
		}
		$plugin = $hook_extra['plugin'] ?? null;
		$theme  = $hook_extra['theme'] ?? null;
		if ( $bulk && 'plugin' === $type && null === $plugin ) {
			$plugins = $hook_extra['plugins'] ?? null;
			$plugin  = is_array( $plugins ) && 1 === count( $plugins )
				? reset( $plugins )
				: null;
		}
		if ( $bulk && 'theme' === $type && null === $theme ) {
			$themes = $hook_extra['themes'] ?? null;
			$theme  = is_array( $themes ) && 1 === count( $themes )
				? reset( $themes )
				: null;
		}
		if ( ( null === $type || 'plugin' === $type )
			&& is_string( $plugin )
			&& '' !== $plugin
			&& null === $theme ) {
			return array(
				'type'       => 'plugin',
				'identifier' => $plugin,
			);
		}
		if ( ( null === $type || 'theme' === $type )
			&& is_string( $theme )
			&& '' !== $theme
			&& null === $plugin ) {
			return array(
				'type'       => 'theme',
				'identifier' => $theme,
			);
		}

		return null;
	}

	/** @return array{release: bool, authority: array<string, int|string>|null} */
	private function native_authority_snapshot( string $type, string $identifier ): array {
		$package = 'plugin' === $type
			? $this->plugins->booster_plugin_from_file( $identifier )
			: $this->themes->booster_theme_from_stylesheet( $identifier );
		if ( PackageSource::RELEASE_ASSET !== $package->get_source() ) {
			return array(
				'release'   => false,
				'authority' => null,
			);
		}
		if ( null !== $package->get_subdirectory() ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}
		$configuration = $this->store->configuration( $type, $identifier );
		$repository_id = $package->get_provider_repository_id();
		$provider_code = $package->get_provider_code();
		if ( null === $provider_code
			|| ! is_string( $repository_id )
			|| '' === $repository_id
			|| null === $configuration
			|| ! $this->configuration_matches_package( $type, $identifier, $configuration ) ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}
		$assessment = $this->source_guard->assess(
			$provider_code,
			$repository_id,
			'plugin' === $type ? 1 : 2,
			$identifier,
			PackageSource::RELEASE_ASSET
		);
		if ( ! $assessment['allowed'] ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}
		try {
			$this->providers->require_capability( $provider_code, RepositoryReleaseNativeTargets::class );
		} catch ( Throwable ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}

		return array(
			'release'   => true,
			'authority' => $this->authority( $package, $configuration ),
		);
	}

	private function has_native_target_state( string $key ): bool {
		return isset( $this->registered_authorities[ $key ] )
			|| isset( $this->targets[ $key ] )
			|| isset( $this->failures[ $key ] );
	}

	private function native_target_is_active( string $type, string $identifier ): bool {
		return true === $this->status( $type, $identifier )?->active;
	}

	private function suppress_unauthorized_offers( string $type, mixed $transient ): mixed {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $transient;
		}
		try {
			$packages = 'plugin' === $type
				? $this->plugins->all_deployment_plugins()
				: $this->themes->all_deployment_themes();
		} catch ( Throwable ) {
			$prefix = $type . "\0";
			$keys   = array_unique(
				array_merge(
					array_keys( $this->registered_authorities ),
					array_keys( $this->targets ),
					array_keys( $this->failures )
				)
			);
			foreach ( $keys as $key ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$identifier = substr( $key, strlen( $prefix ) );
					if ( '*' !== $identifier ) {
						unset( $transient->response[ $identifier ] );
					}
				}
			}

			return $transient;
		}
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package ) {
				continue;
			}
			$identifier = (string) $package->get_identifier();
			if ( PackageSource::RELEASE_ASSET !== $package->get_source() ) {
				unset( $transient->response[ $identifier ] );

				continue;
			}
			$key = self::key( $type, $identifier );
			try {
				$snapshot = $this->native_authority_snapshot( $type, $identifier );
				$current  = $snapshot['authority'];
			} catch ( Throwable ) {
				$current = null;
			}
			if ( ! ( $snapshot['release'] ?? false )
				|| null === $current
				|| ( $this->registered_authorities[ $key ] ?? null ) !== $current
				|| ! $this->native_target_is_active( $type, $identifier ) ) {
				unset( $transient->response[ $identifier ] );
			}
		}

		return $transient;
	}

	/** @return array<string, int|string> */
	private function authority( Package $package, ManagedReleaseConfiguration $configuration ): array {
		return array(
			'provider'               => (string) $package->get_provider_code(),
			'source_revision'        => $package->get_source_revision(),
			'provider_repository_id' => (string) $package->get_provider_repository_id(),
			'repository'             => (string) $package->get_repository(),
			'credential_id'          => $package->get_credential_id(),
			'private'                => $package->get_private() ? 1 : 0,
			'configuration'          => $configuration->to_json(),
			'deployment_policy'      => $package->get_deployment_policy()->value,
		);
	}

	private function native_update_error( string $reason ): \WP_Error {
		return new \WP_Error(
			'ran_booster_native_update_' . $reason,
			'The managed package update is no longer authorized.'
		);
	}

	private function configuration_matches_package(
		string $type,
		string $identifier,
		ManagedReleaseConfiguration $configuration
	): bool {
		if ( 'theme' === $type ) {
			return 'style.css' === strtolower( $configuration->metadata_file() );
		}

		return basename( $identifier ) === $configuration->metadata_file();
	}

	private function metadata_path(
		string $type,
		ManagedReleaseConfiguration $configuration,
		?string $installed_identity = null
	): string {
		$root = 'plugin' === $type
			? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' )
			: ( function_exists( 'get_theme_root' ) ? get_theme_root() : '' );
		if ( '' === $root || null === $installed_identity ) {
			throw new \RuntimeException( 'The managed release metadata root is unavailable.' );
		}

		return 'plugin' === $type
			? rtrim( $root, '/\\' ) . '/' . $installed_identity
			: rtrim( $root, '/\\' ) . '/' . $installed_identity . '/' . $configuration->metadata_file();
	}

	private static function plugin_target_identity( string $identifier ): string {
		return ltrim( strtolower( str_replace( '\\', '/', $identifier ) ), '/' );
	}

	private static function key( string $type, string $identifier ): string {
		return $type . "\0" . $identifier;
	}
}

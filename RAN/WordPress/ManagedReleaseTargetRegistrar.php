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
	private array $registeredAuthorities = array();

	/** @var array<string, string> */
	private array $failures = array();

	private bool $registered = false;

	/** @var array<string, array{authority: array<string, int|string>, automatic: bool, lock: ?string, restore: bool}> */
	private array $nativeUpdates = array();

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ManagedReleaseStore $store,
		private WordPressUpdaterLock $updaterLock,
		private ProviderRegistry $providers
	) {
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return;
		}

		try {
			$this->registerPackages( 'plugin', $this->plugins->allDeploymentPlugins( PackageSource::RELEASE_ASSET ) );
		} catch ( Throwable $exception ) {
			$this->failures[ self::key( 'plugin', '*' ) ] = 'repository_read_failed';
			BoosterLogger::logException(
				'managed release repository read unavailable',
				$exception,
				array(
					'source' => 'plugin',
					'step'   => 'managed_release_target_registration',
				)
			);
		}
		try {
			$this->registerPackages( 'theme', $this->themes->allDeploymentThemes( PackageSource::RELEASE_ASSET ) );
		} catch ( Throwable $exception ) {
			$this->failures[ self::key( 'theme', '*' ) ] = 'repository_read_failed';
			BoosterLogger::logException(
				'managed release repository read unavailable',
				$exception,
				array(
					'source' => 'theme',
					'step'   => 'managed_release_target_registration',
				)
			);
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'upgrader_pre_download', array( $this, 'authorizeNativeDownload' ), PHP_INT_MIN, 4 );
			add_filter( 'upgrader_pre_install', array( $this, 'fenceNativeMutation' ), 1, 2 );
			add_filter( 'site_transient_update_plugins', array( $this, 'suppressUnauthorizedPluginOffers' ), PHP_INT_MAX );
			add_filter( 'site_transient_update_themes', array( $this, 'suppressUnauthorizedThemeOffers' ), PHP_INT_MAX );
			add_action( 'upgrader_process_complete', array( $this, 'completeNativeMutation' ), PHP_INT_MAX, 2 );
		}
	}

	public function suppressUnauthorizedPluginOffers( mixed $transient ): mixed {
		return $this->suppressUnauthorizedOffers( 'plugin', $transient );
	}

	public function suppressUnauthorizedThemeOffers( mixed $transient ): mixed {
		return $this->suppressUnauthorizedOffers( 'theme', $transient );
	}

	/**
	 * Snapshot live Core authority before the updater performs remote work.
	 *
	 * @param array<string, mixed> $hookExtra
	 */
	public function authorizeNativeDownload(
		mixed $reply,
		string $package,
		object $upgrader,
		array $hookExtra
	): mixed {
		unset( $package );
		$bulk             = true === ( $upgrader->bulk ?? false );
		$singleTargetBulk = $bulk
			&& 1 === ( $upgrader->update_count ?? null )
			&& 1 === ( $upgrader->update_current ?? null );
		$target           = $this->nativeTarget( $hookExtra, $bulk );
		if ( null === $target ) {
			return $reply;
		}
		$key = self::key( $target['type'], $target['identifier'] );
		if ( $reply instanceof \WP_Error ) {
			unset( $this->nativeUpdates[ $key ] );

			return $reply;
		}
		try {
			$snapshot = $this->nativeAuthoritySnapshot( $target['type'], $target['identifier'] );
		} catch ( PluginNotFound | ThemeNotFound ) {
			return isset( $this->registeredAuthorities[ $key ] ) || isset( $this->targets[ $key ] )
				? $this->nativeUpdateError( 'authority_changed' )
				: $reply;
		} catch ( Throwable ) {
			return $this->nativeUpdateError( 'authority_changed' );
		}
		if ( ! $snapshot['release'] ) {
			return $this->hasNativeTargetState( $key )
				? $this->nativeUpdateError( 'authority_changed' )
				: $reply;
		}
		$authority = $snapshot['authority'];
		if ( null === $authority
			|| ! $this->nativeTargetIsActive( $target['type'], $target['identifier'] )
			|| ( $this->registeredAuthorities[ $key ] ?? null ) !== $authority ) {
			return $this->nativeUpdateError( 'authority_changed' );
		}
		try {
			PackageMutationGuard::assertPackageMutationAllowed();
		} catch ( Throwable ) {
			return $this->nativeUpdateError( 'authority_changed' );
		}
		if ( $bulk && ! $singleTargetBulk ) {
			return $this->nativeUpdateError( 'unsupported_context' );
		}
		$automatic = isset( $upgrader->skin )
			&& is_object( $upgrader->skin )
			&& 'Automatic_Upgrader_Skin' === get_class( $upgrader->skin );
		if ( $automatic && ( ! function_exists( 'doing_action' ) || ! doing_action( 'wp_maybe_auto_update' ) ) ) {
			return $this->nativeUpdateError( 'unsupported_context' );
		}
		$outerLock = null;
		if ( $automatic ) {
			try {
				$outerLock = $this->updaterLock->currentToken();
			} catch ( Throwable ) {
				$outerLock = null;
			}
			if ( null === $outerLock ) {
				return $this->nativeUpdateError( 'unsupported_context' );
			}
		}
		$this->nativeUpdates[ $key ] = array(
			'authority' => $authority,
			'automatic' => $automatic,
			'lock'      => $outerLock,
			'restore'   => ! empty( $hookExtra['temp_backup'] ),
		);

		return $reply;
	}

	/**
	 * Fence the exact single target before WordPress mutates its installation.
	 *
	 * @param array<string, mixed> $hookExtra
	 */
	public function fenceNativeMutation( mixed $reply, array $hookExtra ): mixed {
		$target = $this->nativeTarget( $hookExtra, true );
		if ( null === $target ) {
			return $reply;
		}
		$key       = self::key( $target['type'], $target['identifier'] );
		$pending   = $this->nativeUpdates[ $key ] ?? null;
		$lock      = $this->updaterLock;
		$lockToken = null;
		if ( $reply instanceof \WP_Error ) {
			unset( $this->nativeUpdates[ $key ] );

			return $reply;
		}
		if ( null === $pending ) {
			try {
				$snapshot = $this->nativeAuthoritySnapshot( $target['type'], $target['identifier'] );
			} catch ( PluginNotFound | ThemeNotFound ) {
				return isset( $this->registeredAuthorities[ $key ] ) || isset( $this->targets[ $key ] )
					? $this->nativeUpdateError( 'authority_changed' )
					: $reply;
			} catch ( Throwable ) {
				return $this->nativeUpdateError( 'authority_changed' );
			}
			if ( ! $snapshot['release'] && ! $this->hasNativeTargetState( $key ) ) {
				return $reply;
			}

			return $this->nativeUpdateError( 'authority_changed' );
		}
		try {
			PackageMutationGuard::assertPackageMutationAllowed();
			if ( $pending['automatic'] ) {
				if ( ! function_exists( 'doing_action' )
					|| ! doing_action( 'wp_maybe_auto_update' )
					|| null === $pending['lock']
					|| ! hash_equals( $pending['lock'], (string) $lock->currentToken() ) ) {
					throw new \RuntimeException( 'The WordPress automatic updater lock is unavailable.' );
				}
			} else {
				$lockToken = $lock->acquire();
			}
			$snapshot = $this->nativeAuthoritySnapshot( $target['type'], $target['identifier'] );
			$current  = $snapshot['authority'];
			if ( ! $snapshot['release']
				|| null === $current
				|| ! $this->nativeTargetIsActive( $target['type'], $target['identifier'] )
				|| $pending['authority'] !== $current ) {
				throw new \RuntimeException( 'The managed release authority changed.' );
			}
			if ( null !== $lockToken ) {
				$this->nativeUpdates[ $key ]['lock'] = $lockToken;
			}

			return $reply;
		} catch ( Throwable ) {
			if ( null !== $lockToken ) {
				try {
					$lock->release( $lockToken );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A hard-stop-safe stale lock is preferable to hiding the authority failure.
				} catch ( Throwable ) {
					// The exact lock token remains available for stale-lock recovery.
				}
			}
			unset( $this->nativeUpdates[ $key ] );

			return $this->nativeUpdateError( 'authority_changed' );
		}
	}

	/** @param array<string, mixed> $hookExtra */
	public function completeNativeMutation( object $upgrader, array $hookExtra ): void {
		$target = $this->nativeTarget( $hookExtra, true === ( $hookExtra['bulk'] ?? false ) );
		if ( null === $target ) {
			return;
		}
		$key     = self::key( $target['type'], $target['identifier'] );
		$pending = $this->nativeUpdates[ $key ] ?? null;
		unset( $this->nativeUpdates[ $key ] );
		if ( null === $pending
			|| $pending['automatic']
			|| null === $pending['lock'] ) {
			return;
		}

		$release = function () use ( $pending ): void {
			try {
				if ( ! $this->updaterLock->release( $pending['lock'] ) ) {
					throw new \RuntimeException( 'The native update lock was replaced.' );
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::logException(
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

	public function failureCode( string $type, string $identifier ): string {
		return $this->failures[ self::key( $type, $identifier ) ]
			?? $this->failures[ self::key( $type, '*' ) ]
			?? '';
	}

	/** @param array<string, Package> $packages */
	private function registerPackages( string $type, array $packages ): void {
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || PackageSource::RELEASE_ASSET !== $package->getSource() ) {
				continue;
			}
			$identifier = (string) $package->getIdentifier();
			$key        = self::key( $type, $identifier );
			if ( null !== $package->getSubdirectory() ) {
				$this->failures[ $key ] = 'subdirectory_not_supported';

				continue;
			}
			try {
				$this->targets[ $key ] = $this->registerPackage( $type, $package );
			} catch ( Throwable ) {
				unset( $this->targets[ $key ], $this->registeredAuthorities[ $key ] );
				$this->failures[ $key ] = 'target_registration_failed';
			}
		}
	}

	private function registerPackage( string $type, Package $package ): RepositoryReleaseNativeTarget {
		$identifier    = (string) $package->getIdentifier();
		$configuration = $this->store->configuration( $type, $identifier );
		$providerCode  = $package->getProviderCode();
		if ( null === $configuration
			|| null === $providerCode
			|| ! $this->providers->isSealed()
			|| ! $this->configurationMatchesPackage( $type, $identifier, $configuration ) ) {
			throw new \RuntimeException( 'The managed release target is ineligible.' );
		}
		$nativeTargets = $this->providers->requireCapability( $providerCode, RepositoryReleaseNativeTargets::class );
		$metadataFile  = $this->metadataPath( $type, $configuration, $identifier );
		$target        = $nativeTargets->createNativeTarget(
			$type,
			$package->getRepository()->reference,
			$metadataFile,
			$configuration->packageRoot(),
			$identifier,
			$configuration->channel(),
			$package->getDeploymentPolicy()->value
		);
		if ( ! $target->register() ) {
			throw new \RuntimeException( 'The managed release target could not be registered.' );
		}
		$this->registeredAuthorities[ self::key( $type, $identifier ) ] = $this->authority(
			$package,
			$configuration
		);

		return $target;
	}

	/**
	 * @return array{type: 'plugin'|'theme', identifier: string}|null
	 */
	private function nativeTarget( array $hookExtra, bool $bulk = false ): ?array {
		$action = $hookExtra['action'] ?? null;
		$type   = $hookExtra['type'] ?? null;
		if ( ( null !== $action && 'update' !== $action )
			|| ( null !== $type && ! in_array( $type, array( 'plugin', 'theme' ), true ) )
			|| ( ! $bulk && ( 'update' !== $action || null === $type ) ) ) {
			return null;
		}
		$plugin = $hookExtra['plugin'] ?? null;
		$theme  = $hookExtra['theme'] ?? null;
		if ( $bulk && 'plugin' === $type && null === $plugin ) {
			$plugins = $hookExtra['plugins'] ?? null;
			$plugin  = is_array( $plugins ) && 1 === count( $plugins )
				? reset( $plugins )
				: null;
		}
		if ( $bulk && 'theme' === $type && null === $theme ) {
			$themes = $hookExtra['themes'] ?? null;
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
	private function nativeAuthoritySnapshot( string $type, string $identifier ): array {
		$package = 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
		if ( PackageSource::RELEASE_ASSET !== $package->getSource() ) {
			return array(
				'release'   => false,
				'authority' => null,
			);
		}
		if ( null !== $package->getSubdirectory() ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}
		$configuration = $this->store->configuration( $type, $identifier );
		$repositoryId  = $package->getProviderRepositoryId();
		$providerCode  = $package->getProviderCode();
		if ( null === $providerCode
			|| ! is_string( $repositoryId )
			|| '' === $repositoryId
			|| null === $configuration
			|| ! $this->configurationMatchesPackage( $type, $identifier, $configuration ) ) {
			return array(
				'release'   => true,
				'authority' => null,
			);
		}
		try {
			$this->providers->requireCapability( $providerCode, RepositoryReleaseNativeTargets::class );
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

	private function hasNativeTargetState( string $key ): bool {
		return isset( $this->registeredAuthorities[ $key ] )
			|| isset( $this->targets[ $key ] )
			|| isset( $this->failures[ $key ] );
	}

	private function nativeTargetIsActive( string $type, string $identifier ): bool {
		return true === $this->status( $type, $identifier )?->active;
	}

	private function suppressUnauthorizedOffers( string $type, mixed $transient ): mixed {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $transient;
		}
		try {
			$packages = 'plugin' === $type
				? $this->plugins->allDeploymentPlugins()
				: $this->themes->allDeploymentThemes();
		} catch ( Throwable ) {
			$prefix = $type . "\0";
			$keys   = array_unique(
				array_merge(
					array_keys( $this->registeredAuthorities ),
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
			$identifier = (string) $package->getIdentifier();
			if ( PackageSource::RELEASE_ASSET !== $package->getSource() ) {
				unset( $transient->response[ $identifier ] );

				continue;
			}
			$key = self::key( $type, $identifier );
			try {
				$snapshot = $this->nativeAuthoritySnapshot( $type, $identifier );
				$current  = $snapshot['authority'];
			} catch ( Throwable ) {
				$current = null;
			}
			if ( ! ( $snapshot['release'] ?? false )
				|| null === $current
				|| ( $this->registeredAuthorities[ $key ] ?? null ) !== $current
				|| ! $this->nativeTargetIsActive( $type, $identifier ) ) {
				unset( $transient->response[ $identifier ] );
			}
		}

		return $transient;
	}

	/** @return array<string, int|string> */
	private function authority( Package $package, ManagedReleaseConfiguration $configuration ): array {
		return array(
			'provider'               => (string) $package->getProviderCode(),
			'source_revision'        => $package->getSourceRevision(),
			'provider_repository_id' => (string) $package->getProviderRepositoryId(),
			'repository'             => (string) $package->getRepository(),
			'credential_id'          => $package->getCredentialId(),
			'private'                => $package->getPrivate() ? 1 : 0,
			'configuration'          => $configuration->toJson(),
			'deployment_policy'      => $package->getDeploymentPolicy()->value,
		);
	}

	private function nativeUpdateError( string $reason ): \WP_Error {
		return new \WP_Error(
			'ran_booster_native_update_' . $reason,
			'The managed package update is no longer authorized.'
		);
	}

	private function configurationMatchesPackage(
		string $type,
		string $identifier,
		ManagedReleaseConfiguration $configuration
	): bool {
		if ( 'theme' === $type ) {
			return 'style.css' === strtolower( $configuration->metadataFile() );
		}

		return basename( $identifier ) === $configuration->metadataFile();
	}

	private function metadataPath(
		string $type,
		ManagedReleaseConfiguration $configuration,
		?string $installedIdentity = null
	): string {
		$root = 'plugin' === $type
			? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' )
			: ( function_exists( 'get_theme_root' ) ? get_theme_root() : '' );
		if ( '' === $root || null === $installedIdentity ) {
			throw new \RuntimeException( 'The managed release metadata root is unavailable.' );
		}

		return 'plugin' === $type
			? rtrim( $root, '/\\' ) . '/' . $installedIdentity
			: rtrim( $root, '/\\' ) . '/' . $installedIdentity . '/' . $configuration->metadataFile();
	}

	private static function key( string $type, string $identifier ): string {
		return $type . "\0" . $identifier;
	}
}

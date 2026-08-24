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

	/** @var array<string, array{authority: array<string, int|string>, automatic: bool, lock: ?string, restore: bool, phase?: string}> */
	private array $nativeUpdates = array();

	/** @var array{upgrader: object, type: 'plugin'|'theme', count: int, current: int, lock: ?string, restore: bool, poison: bool, targets: array<string, true>}|null */
	private ?array $manualBulkRun = null;

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
			add_filter( 'upgrader_install_package_result', array( $this, 'captureNativeInstallResult' ), PHP_INT_MAX, 2 );
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
		$bulk   = true === ( $upgrader->bulk ?? false );
		$target = $this->nativeTarget( $hookExtra, $bulk );
		if ( null === $target ) {
			return $reply;
		}
		$key = self::key( $target['type'], $target['identifier'] );
		if ( $reply instanceof \WP_Error ) {
			unset( $this->nativeUpdates[ $key ] );

			return $reply;
		}
		try {
			$authority = $this->nativeAuthority( $target['type'], $target['identifier'] );
		} catch ( PluginNotFound | ThemeNotFound ) {
			return isset( $this->registeredAuthorities[ $key ] ) || isset( $this->targets[ $key ] )
				? $this->nativeUpdateError( 'authority_changed' )
				: $reply;
		} catch ( Throwable ) {
			return $this->nativeUpdateError( 'authority_changed' );
		}
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
		$automatic = isset( $upgrader->skin )
			&& is_object( $upgrader->skin )
			&& 'Automatic_Upgrader_Skin' === get_class( $upgrader->skin );
		if ( $automatic && ( ! function_exists( 'doing_action' ) || ! doing_action( 'wp_maybe_auto_update' ) ) ) {
			return $this->nativeUpdateError( 'unsupported_context' );
		}
		if ( null !== $this->manualBulkRun && ( ! $bulk || $this->manualBulkRun['upgrader'] !== $upgrader ) ) {
			$this->manualBulkRun['poison'] = true;

			return $this->nativeUpdateError( 'authority_changed' );
		}
		if ( $bulk ) {
			if ( $automatic || ! $this->recordManualBulkTarget( $upgrader, $target ) ) {
				return $this->nativeUpdateError( $automatic ? 'unsupported_context' : 'authority_changed' );
			}
			$this->nativeUpdates[ $key ] = array(
				'authority' => $authority,
				'automatic' => false,
				'lock'      => null,
				'restore'   => false,
				'phase'     => 'authorized',
			);

			return $reply;
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
		$bulkRun   = null !== $this->manualBulkRun && isset( $this->manualBulkRun['targets'][ $key ] );
		if ( null !== $this->manualBulkRun ) {
			if ( ! $bulkRun || null === $pending ) {
				if ( isset( $this->registeredAuthorities[ $key ] ) || isset( $this->targets[ $key ] ) ) {
					$this->manualBulkRun['poison'] = true;

					return $this->nativeUpdateError( 'authority_changed' );
				}

				return $reply;
			}
			if ( $reply instanceof \WP_Error || 'authorized' !== ( $pending['phase'] ?? null ) || $this->manualBulkRun['poison'] ) {
				$this->manualBulkRun['poison'] = true;

				return $reply instanceof \WP_Error ? $reply : $this->nativeUpdateError( 'authority_changed' );
			}
		}
		if ( $reply instanceof \WP_Error ) {
			unset( $this->nativeUpdates[ $key ] );

			return $reply;
		}
		if ( null === $pending ) {
			try {
				$this->nativeAuthority( $target['type'], $target['identifier'] );
			} catch ( PluginNotFound | ThemeNotFound ) {
				return isset( $this->registeredAuthorities[ $key ] ) || isset( $this->targets[ $key ] )
					? $this->nativeUpdateError( 'authority_changed' )
					: $reply;
			} catch ( Throwable ) {
				return $this->nativeUpdateError( 'authority_changed' );
			}

			return $this->nativeUpdateError( 'authority_changed' );
		}
		try {
			PackageMutationGuard::assertPackageMutationAllowed();
			if ( $bulkRun ) {
				if ( null === $this->manualBulkRun['lock'] ) {
					$this->manualBulkRun['lock'] = $lock->acquire();
					add_action(
						'shutdown',
						function (): void {
							$this->releaseManualBulkRun( true );
						},
						PHP_INT_MAX,
						0
					);
				}
			} elseif ( $pending['automatic'] ) {
				if ( ! function_exists( 'doing_action' )
					|| ! doing_action( 'wp_maybe_auto_update' )
					|| null === $pending['lock']
					|| ! hash_equals( $pending['lock'], (string) $lock->currentToken() ) ) {
					throw new \RuntimeException( 'The WordPress automatic updater lock is unavailable.' );
				}
			} else {
				$lockToken = $lock->acquire();
			}
			$current = $this->nativeAuthority( $target['type'], $target['identifier'] );
			if ( null === $current
				|| ! $this->nativeTargetIsActive( $target['type'], $target['identifier'] )
				|| $pending['authority'] !== $current ) {
				throw new \RuntimeException( 'The managed release authority changed.' );
			}
			if ( $bulkRun ) {
				$this->nativeUpdates[ $key ]['phase'] = 'fenced';
			} elseif ( null !== $lockToken ) {
				$this->nativeUpdates[ $key ]['lock'] = $lockToken;
			}

			return $reply;
		} catch ( Throwable ) {
			if ( $bulkRun ) {
				$this->manualBulkRun['poison'] = true;

				return $this->nativeUpdateError( 'authority_changed' );
			}
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
	public function captureNativeInstallResult( mixed $result, array $hookExtra ): mixed {
		if ( null === $this->manualBulkRun ) {
			return $result;
		}
		$target = $this->nativeTarget( $hookExtra, true );
		$key    = null === $target ? null : self::key( $target['type'], $target['identifier'] );
		if ( null === $key || ! isset( $this->manualBulkRun['targets'][ $key ] )
			|| 'fenced' !== ( $this->nativeUpdates[ $key ]['phase'] ?? null ) ) {
			return $result;
		}
		$this->nativeUpdates[ $key ]['phase'] = 'terminal';
		$this->manualBulkRun['restore']       = $this->manualBulkRun['restore'] || ( $result instanceof \WP_Error && ! empty( $hookExtra['temp_backup'] ) );

		return $result;
	}

	/** @param array<string, mixed> $hookExtra */
	public function completeNativeMutation( object $upgrader, array $hookExtra ): void {
		if ( null !== $this->manualBulkRun ) {
			$this->completeManualBulkRun( $upgrader, $hookExtra );

			return;
		}
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

		$failed = ( $upgrader->skin->result ?? null ) instanceof \WP_Error
			|| ( $upgrader->result ?? null ) instanceof \WP_Error;
		if ( $failed && $pending['restore'] ) {
			add_action(
				'shutdown',
				function () use ( $pending ): void {
					$this->releaseNativeLock( $pending['lock'] );
				},
				PHP_INT_MAX,
				0
			);

			return;
		}
		$this->releaseNativeLock( $pending['lock'] );
	}

	/** @param array{type: 'plugin'|'theme', identifier: string} $target */
	private function recordManualBulkTarget( object $upgrader, array $target ): bool {
		$count   = $upgrader->update_count ?? null;
		$current = $upgrader->update_current ?? null;
		if ( ! is_int( $count ) || ! is_int( $current ) || $count < 1 || $current < 1 || $current > $count ) {
			if ( null !== $this->manualBulkRun ) {
				$this->manualBulkRun['poison'] = true;
			}

			return false;
		}
		if ( null === $this->manualBulkRun ) {
			$this->manualBulkRun = array(
				'upgrader' => $upgrader,
				'type'     => $target['type'],
				'count'    => $count,
				'current'  => $current,
				'lock'     => null,
				'restore'  => false,
				'poison'   => false,
				'targets'  => array(),
			);
		} elseif ( $this->manualBulkRun['poison']
			|| $this->manualBulkRun['upgrader'] !== $upgrader
			|| $this->manualBulkRun['type'] !== $target['type']
			|| $this->manualBulkRun['count'] !== $count
			|| $current <= $this->manualBulkRun['current'] ) {
			$this->manualBulkRun['poison'] = true;

			return false;
		} else {
			$this->manualBulkRun['current'] = $current;
		}
		$key = self::key( $target['type'], $target['identifier'] );
		if ( isset( $this->nativeUpdates[ $key ] ) ) {
			$this->manualBulkRun['poison'] = true;

			return false;
		}
		$this->manualBulkRun['targets'][ $key ] = true;

		return true;
	}

	/** @param array<string, mixed> $hookExtra */
	private function completeManualBulkRun( object $upgrader, array $hookExtra ): void {
		$run = $this->manualBulkRun;
		if ( $run['upgrader'] !== $upgrader || 'update' !== ( $hookExtra['action'] ?? null )
			|| true !== ( $hookExtra['bulk'] ?? false ) || $run['type'] !== ( $hookExtra['type'] ?? null ) ) {
			return;
		}
		$names = 'plugin' === $run['type'] ? ( $hookExtra['plugins'] ?? null ) : ( $hookExtra['themes'] ?? null );
		if ( ! is_array( $names ) || $run['count'] !== count( $names ) || count( $names ) !== count( array_unique( $names, SORT_REGULAR ) )
			|| count( array_filter( $names, static fn ( mixed $name ): bool => ! is_string( $name ) || '' === $name ) ) > 0 ) {
			return;
		}
		foreach ( $run['targets'] as $key => $_present ) {
			$pending = $this->nativeUpdates[ $key ] ?? null;
			if ( 'terminal' !== ( $pending['phase'] ?? null ) || ! in_array( substr( $key, strlen( $run['type'] ) + 1 ), $names, true ) ) {
				return;
			}
		}
		if ( $run['poison'] || $run['restore'] ) {
			return;
		}
		$this->releaseManualBulkRun( false );
	}

	private function releaseManualBulkRun( bool $shutdown ): void {
		if ( null === $this->manualBulkRun || null === $this->manualBulkRun['lock'] ) {
			return;
		}
		$result = $this->releaseNativeLock( $this->manualBulkRun['lock'] );
		if ( true === $result || false === $result || $shutdown ) {
			foreach ( $this->manualBulkRun['targets'] as $key => $_present ) {
				unset( $this->nativeUpdates[ $key ] );
			}
			$this->manualBulkRun = null;
		}
	}

	private function releaseNativeLock( string $token ): ?bool {
		try {
			if ( $this->updaterLock->release( $token ) ) {
				return true;
			}
			BoosterLogger::logException(
				'native update lock release failed',
				new \RuntimeException( 'The native update lock was replaced.' ),
				array( 'step' => 'native_update_lock_release' )
			);

			return false;
		} catch ( Throwable $failure ) {
			BoosterLogger::logException( 'native update lock release failed', $failure, array( 'step' => 'native_update_lock_release' ) );

			return null;
		}
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

	/** @return array<string, int|string>|null */
	private function nativeAuthority( string $type, string $identifier ): ?array {
		$package       = 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
		$configuration = $this->store->configuration( $type, $identifier );
		$repositoryId  = $package->getProviderRepositoryId();
		$providerCode  = $package->getProviderCode();
		if ( PackageSource::RELEASE_ASSET !== $package->getSource()
			|| null === $providerCode
			|| ! is_string( $repositoryId )
			|| '' === $repositoryId
			|| null === $configuration
			|| ! $this->configurationMatchesPackage( $type, $identifier, $configuration ) ) {
			return null;
		}
		try {
			$this->providers->requireCapability( $providerCode, RepositoryReleaseNativeTargets::class );
		} catch ( Throwable ) {
			return null;
		}

		return $this->authority( $package, $configuration );
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
			foreach ( array_keys( $this->registeredAuthorities ) as $key ) {
				if ( str_starts_with( $key, $prefix ) ) {
					unset( $transient->response[ substr( $key, strlen( $prefix ) ) ] );
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
				$current = $this->nativeAuthority( $type, $identifier );
			} catch ( Throwable ) {
				$current = null;
			}
			if ( null === $current
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

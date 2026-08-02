<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageSource;
use RAN\Runtime\RuntimeSupport;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

/**
 * Registers release-owned packages with the shared native updater.
 *
 * One malformed target is isolated from every other target.
 */
final class ManagedReleaseTargetRegistrar {

	/** @var array<string, object> */
	private array $facades = array();

	/** @var array<string, array<string, int|string>> */
	private array $registeredAuthorities = array();

	/** @var array<string, string> */
	private array $failures = array();

	private bool $registered = false;

	/** @var callable|null */
	private mixed $factory;

	/** @var array<string, array{authority: array<string, int|string>, automatic: bool, lock: ?string, restore: bool}> */
	private array $nativeUpdates = array();

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private SecretsFile $secrets,
		private ManagedReleaseStore $store,
		private WordPressUpdaterLock $updaterLock,
		?callable $factory = null
	) {
		$this->factory = $factory;
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
			add_filter( 'upgrader_pre_download', array( $this, 'authorizeNativeDownload' ), PHP_INT_MAX - 1, 4 );
			add_filter( 'upgrader_pre_install', array( $this, 'fenceNativeMutation' ), 1, 2 );
			add_action( 'upgrader_process_complete', array( $this, 'completeNativeMutation' ), PHP_INT_MAX, 2 );
		}
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
		if ( null === $target || null === $this->facade( $target['type'], $target['identifier'] ) ) {
			return $reply;
		}
		try {
			PackageMutationGuard::assertPackageMutationAllowed();
		} catch ( Throwable ) {
			return $this->nativeUpdateError( 'authority_changed' );
		}
		if ( $bulk && ! $singleTargetBulk ) {
			return $this->nativeUpdateError( 'unsupported_context' );
		}
		$key = self::key( $target['type'], $target['identifier'] );
		if ( $reply instanceof \WP_Error ) {
			unset( $this->nativeUpdates[ $key ] );

			return $reply;
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
		try {
			$authority = $this->nativeAuthority( $target['type'], $target['identifier'] );
		} catch ( Throwable ) {
			$authority = null;
		}
		if ( null === $authority
			|| ( $this->registeredAuthorities[ $key ] ?? null ) !== $authority ) {
			return $this->nativeUpdateError( 'authority_changed' );
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
			return null === $this->facade( $target['type'], $target['identifier'] )
				? $reply
				: $this->nativeUpdateError( 'authority_changed' );
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
			$current = $this->nativeAuthority( $target['type'], $target['identifier'] );
			if ( null === $current || $pending['authority'] !== $current ) {
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

	public function facade( string $type, string $identifier ): ?object {
		return $this->facades[ self::key( $type, $identifier ) ] ?? null;
	}

	/** @return array<string, mixed> */
	public function diagnostics( string $type, string $identifier ): array {
		$facade = $this->facade( $type, $identifier );
		if ( null === $facade || ! is_callable( array( $facade, 'diagnostics' ) ) ) {
			return array();
		}

		try {
			$value = $facade->diagnostics();

			return is_array( $value ) ? $value : array();
		} catch ( Throwable ) {
			return array();
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
				$this->facades[ $key ] = $this->registerPackage( $type, $package );
			} catch ( Throwable ) {
				unset( $this->facades[ $key ], $this->registeredAuthorities[ $key ] );
				$this->failures[ $key ] = 'target_registration_failed';
			}
		}
	}

	private function registerPackage( string $type, Package $package ): object {
		$identifier    = (string) $package->getIdentifier();
		$configuration = $this->store->configuration( $type, $identifier );
		if ( null === $configuration
			|| 'gh' !== $package->getProviderCode()
			|| 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}\z/D', (string) $package->getRepository() )
			|| ! $this->configurationMatchesPackage( $type, $identifier, $configuration ) ) {
			throw new \RuntimeException( 'The managed release target is ineligible.' );
		}

		$credentialId = '' === $package->getCredentialId() ? null : $package->getCredentialId();
		$accessToken  = $package->getPrivate() || null !== $credentialId
			? function () use ( $credentialId ): ?string {
				$credential = $this->secrets->credentialMaterial( 'gh', $credentialId );
				$secret     = is_array( $credential ) ? ( $credential['secret'] ?? null ) : null;

				return is_string( $secret ) && '' !== $secret ? $secret : null;
			}
			: null;
		$metadataFile = $this->metadataPath( $type, $configuration, $identifier );

		$facade = GitHubReleaseUpdaterBootstrap::registerManaged(
			$type,
			$metadataFile,
			(string) $package->getRepository(),
			(string) $package->getProviderRepositoryId(),
			$configuration->packageRoot(),
			$identifier,
			$accessToken,
			$configuration->channel(),
			$package->getDeploymentPolicy()->value,
			$this->factory
		);
		$this->registeredAuthorities[ self::key( $type, $identifier ) ] = $this->authority(
			$package,
			$configuration
		);

		return $facade;
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
		if ( PackageSource::RELEASE_ASSET !== $package->getSource()
			|| 'gh' !== $package->getProviderCode()
			|| ! is_string( $repositoryId )
			|| '' === $repositoryId
			|| null === $configuration
			|| ! $this->configurationMatchesPackage( $type, $identifier, $configuration ) ) {
			return null;
		}

		return $this->authority( $package, $configuration );
	}

	/** @return array<string, int|string> */
	private function authority( Package $package, ManagedReleaseConfiguration $configuration ): array {
		return array(
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

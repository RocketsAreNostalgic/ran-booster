<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;
use Throwable;

/**
 * Administrator facade over Core-owned source state and native update runtime.
 */
final class NativeReleaseTrackingFacade implements ReleaseTrackingFacade {
	private const SOURCE_CHANGED_MESSAGE = 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.';
	private const SUBDIRECTORY_MESSAGE   = 'Published releases require this plugin or theme to be at the repository root. Return to Branch to keep using its configured repository subdirectory.';

	/** @var \Closure(string): bool */
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	/** @var \Closure(string): void */
	private \Closure $refreshNative;

	/** @var \Closure(string, string, string): bool */
	private \Closure $metadataEligible;
	private bool $metadataEligibilityOverridden;

	/** @var \Closure(string): void */
	private \Closure $invalidateNative;
	private WordPressUpdaterLock $updaterLock;

	/**
	 * @param callable(string): bool|null         $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 * @param callable(string): void|null         $refreshNative
	 * @param callable(): bool|null $metadataEligible
	 * @param callable(string): void|null         $invalidateNative
	 */
	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ManagedReleaseStore $store,
		private ManagedReleaseTargetRegistrar $registrar,
		WordPressUpdaterLock $updaterLock,
		private ProviderRegistry $providers,
		?callable $canManage = null,
		?callable $verifyNonce = null,
		?callable $refreshNative = null,
		?callable $metadataEligible = null,
		?callable $invalidateNative = null
	) {
		$this->updaterLock                   = $updaterLock;
		$this->canManage                     = null === $canManage
			? static fn ( string $type ): bool => current_user_can( 'manage_options' )
				&& current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' )
			: \Closure::fromCallable( $canManage );
		$this->verifyNonce                   = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
		$this->refreshNative                 = null === $refreshNative
			? static function ( string $type ): void {
				if ( 'plugin' === $type ) {
					wp_update_plugins();
				} else {
					wp_update_themes();
				}
			}
			: \Closure::fromCallable( $refreshNative );
		$this->metadataEligible              = null === $metadataEligible
			? static fn (): bool => true
			: \Closure::fromCallable( $metadataEligible );
		$this->metadataEligibilityOverridden = null !== $metadataEligible;
		$this->invalidateNative              = null === $invalidateNative
			? static function ( string $type ): void {
				delete_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes' );
			}
			: \Closure::fromCallable( $invalidateNative );
	}

	public function nonceAction(
		string $operation,
		string $type,
		string $identifier,
		int $sourceRevision,
		string $channel = ''
	): string {
		$preflight = 'preflight' === $operation;
		if ( ! in_array( $operation, array( 'preflight', 'enable', 'refresh', 'change_channel', 'return_to_branch' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $identifier
			|| $sourceRevision < 1
			|| ( $preflight && ! $this->validChannel( $channel ) )
			|| ( ! $preflight && '' !== $channel ) ) {
			throw new InvalidArgumentException( 'The release tracking nonce scope is invalid.' );
		}

		$action = 'ran-booster-release-tracking-' . $operation . '-' . $type . '-' . $identifier . '-' . $sourceRevision;

		return $preflight ? $action . '-' . $channel : $action;
	}

	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		RuntimeSupport::assertManagedOperationsAllowed();

		return $this->projectStatus( $type, $identifier );
	}

	private function projectStatus(
		string $type,
		string $identifier
	): ReleaseTrackingStatus {
		$package       = $this->package( $type, $identifier );
		$eligibility   = $this->eligibility( $type, $identifier, $package );
		$incompatible  = ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $eligibility->code();
		$configuration = null;
		$failureCode   = $incompatible ? ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED : '';
		if ( PackageSource::RELEASE_ASSET === $package->getSource() ) {
			try {
				$configuration = $this->store->configuration( $type, $identifier );
				if ( null === $configuration ) {
					$failureCode = $incompatible ? $failureCode : 'release_configuration_invalid';
				}
			} catch ( Throwable ) {
				$failureCode = $incompatible ? $failureCode : 'release_configuration_invalid';
			}
		}
		$packageRoot   = $configuration?->packageRoot() ?? $eligibility->packageRoot();
		$preflight     = null;
		$targetStatus  = $incompatible || null === $configuration
			? null
			: $this->registrar->status( $type, $identifier );
		$latestVersion = $targetStatus?->offeredVersion ?? '';
		if ( ! $incompatible && PackageSource::RELEASE_ASSET === $package->getSource() && null !== $configuration ) {
			$preflight = $this->projectCandidateValidation(
				$packageRoot,
				$package,
				$targetStatus
			);
			if ( '' === $latestVersion && null !== $preflight ) {
				$latestVersion = $preflight->latestVersion();
			}
		}
		if ( null !== $configuration && '' === $failureCode ) {
			$failureCode = $this->registrar->failureCode( $type, $identifier );
		}
		if ( null !== $preflight && ! $preflight->ready() ) {
			$failureCode = $preflight->code();
		}
		$nativeRelationship  = $targetStatus?->versionRelationship ?? '';
		$versionRelationship = $preflight?->versionRelationship()
			?? ( '' !== $nativeRelationship ? $nativeRelationship : 'invalid' );
		if ( '' === $failureCode && null !== $targetStatus ) {
			$failureCode = $targetStatus->failureCode;
		}

		return new ReleaseTrackingStatus(
			$type,
			$identifier,
			$package->getSource()->value,
			$package->getSourceRevision(),
			(string) $package->getProviderRepositoryId(),
			$package->getDeploymentPolicy()->value,
			$eligibility,
			$preflight,
			$packageRoot,
			$package->getVersion(),
			$latestVersion,
			'newer' === $versionRelationship
				&& '' !== $latestVersion
				&& ( null === $preflight || $preflight->ready() ),
			$this->diagnosticTime( $targetStatus?->lastCheck ),
			$this->diagnosticTime( $targetStatus?->nextCheck ),
			$failureCode,
			$configuration?->channel() ?? 'stable'
		);
	}

	public function statuses( string $type, array $identifiers ): array {
		RuntimeSupport::assertManagedOperationsAllowed();
		if ( count( $identifiers ) > 100 ) {
			throw new InvalidArgumentException( 'Too many release tracking statuses were requested.' );
		}
		$statuses = array();
		foreach ( $identifiers as $identifier ) {
			if ( ! is_string( $identifier ) || isset( $statuses[ $identifier ] ) ) {
				throw new InvalidArgumentException( 'The release tracking status selection is invalid.' );
			}
			$statuses[ $identifier ] = $this->projectStatus( $type, $identifier );
		}

		return $statuses;
	}

	public function preflight(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight {
		if ( ! RuntimeSupport::current()->allowsManagedOperations()
			|| ! $this->validChannel( $channel )
			|| ! $this->authorized( 'preflight', $type, $identifier, $expectedSourceRevision, $nonce, $channel ) ) {
			return null;
		}

		try {
			$package = $this->package( $type, $identifier );

			return $this->branchPreflight(
				$type,
				$identifier,
				$expectedSourceRevision,
				$channel,
				$package,
				$this->eligibility( $type, $identifier, $package )
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	public function enable(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'enable', $type, $identifier, $expectedSourceRevision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be enabled.' );
		}
		try {
			$package     = $this->package( $type, $identifier );
			$eligibility = $this->eligibility( $type, $identifier, $package );
			if ( ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $eligibility->code() ) {
				return $this->subdirectoryNotSupported();
			}
			if ( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER === $eligibility->code() ) {
				return ReleaseTrackingResult::failed(
					'target_already_uses_ran_updater',
					'This package already uses the RAN GitHub release updater. Use either its own updater or Booster release tracking, not both.'
				);
			}
			$preflight = $this->branchPreflight(
				$type,
				$identifier,
				$expectedSourceRevision,
				$channel,
				$package,
				$eligibility
			);
			if ( null === $preflight ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			if ( ! $preflight->ready() ) {
				return ReleaseTrackingResult::failed(
					$preflight->code(),
					'Published release assets could not be validated.'
				);
			}
			$packageRoot   = $eligibility->packageRoot();
			$headerFile    = $this->headerFile( $type, $identifier );
			$configuration = $this->configurationFor(
				$packageRoot,
				$headerFile,
				$channel
			);
			$incompatible  = false;
			$changed       = $this->mutateWithUpdaterLock(
				function () use ( $type, $identifier, $expectedSourceRevision, $configuration, &$incompatible ): bool {
					if ( ! $this->releaseSourceSupported( $this->package( $type, $identifier ) ) ) {
						$incompatible = true;

						return false;
					}
					$changed = $this->store->transition(
						$type,
						$identifier,
						PackageSource::BRANCH,
						$expectedSourceRevision,
						PackageSource::RELEASE_ASSET,
						$configuration,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
					);
					if ( $changed ) {
						( $this->invalidateNative )( $type );
					}

					return $changed;
				}
			);
			if ( null === $changed ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
			}
			if ( $incompatible ) {
				return $this->subdirectoryNotSupported();
			}

			return $changed
				? ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled' )
				: ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
		}
	}

	public function changeChannel(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'change_channel', $type, $identifier, $expectedSourceRevision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The release track could not be changed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( ! $this->releaseSourceSupported( $package ) ) {
				return $this->subdirectoryNotSupported();
			}
			$configuration = $this->store->configuration( $type, $identifier );
			if ( PackageSource::RELEASE_ASSET !== $package->getSource()
				|| $expectedSourceRevision !== $package->getSourceRevision()
				|| null === $configuration ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			if ( $channel === $configuration->channel() ) {
				$track = 'stable' === $channel ? 'Stable' : 'Preview';
				return ReleaseTrackingResult::succeeded( 'release_channel_current', $track . ' is already the active release track. No settings were changed.' );
			}
			$incompatible = false;
			$changed      = $this->mutateWithUpdaterLock(
				function () use ( $type, $identifier, $expectedSourceRevision, $channel, &$incompatible ): bool {
					if ( ! $this->releaseSourceSupported( $this->package( $type, $identifier ) ) ) {
						$incompatible = true;

						return false;
					}
					$changed = $this->store->changeChannel(
						$type,
						$identifier,
						$expectedSourceRevision,
						$channel,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
					);
					if ( $changed ) {
						( $this->invalidateNative )( $type );
					}

					return $changed;
				}
			);
			if ( null === $changed ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'The release track could not be changed.' );
			}
			if ( $incompatible ) {
				return $this->subdirectoryNotSupported();
			}

			return $changed
				? ReleaseTrackingResult::succeeded(
					'release_channel_changed',
					'Release track changed. The installed package was not changed.'
				)
				: ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'release_unavailable', 'The release track could not be changed.' );
		}
	}

	public function refresh(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->authorized( 'refresh', $type, $identifier, $expectedSourceRevision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be refreshed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( ! $this->releaseSourceSupported( $package ) ) {
				return $this->subdirectoryNotSupported();
			}
			try {
				$configuration = $this->store->configuration( $type, $identifier );
			} catch ( InvalidArgumentException ) {
				return ReleaseTrackingResult::failed( 'release_configuration_invalid', 'Saved release tracking settings are invalid.' );
			}
			if ( PackageSource::RELEASE_ASSET !== $package->getSource()
				|| $expectedSourceRevision !== $package->getSourceRevision()
				|| null === $configuration
				|| null === $this->registrar->target( $type, $identifier ) ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$target = $this->registrar->target( $type, $identifier );
			if ( null === $target || ! $target->refresh() ) {
				return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
			}
			( $this->refreshNative )( $type );

			return ReleaseTrackingResult::succeeded( 'release_refreshed', 'Published release information was refreshed.' );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
		}
	}

	public function returnToBranch(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->authorized( 'return_to_branch', $type, $identifier, $expectedSourceRevision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The package source could not be changed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( PackageSource::RELEASE_ASSET !== $package->getSource()
				|| $expectedSourceRevision !== $package->getSourceRevision() ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$changed = $this->mutateWithUpdaterLock(
				function () use ( $type, $identifier, $expectedSourceRevision ): bool {
					return $this->store->transition(
						$type,
						$identifier,
						PackageSource::RELEASE_ASSET,
						$expectedSourceRevision,
						PackageSource::BRANCH,
						null,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
					);
				}
			);
			if ( null === $changed ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'The package source could not be changed.' );
			}

			if ( ! $changed ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$target = $this->registrar->target( $type, $identifier );
			if ( null !== $target ) {
				try {
					$target->refresh();
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The committed source transition is authoritative; cache cleanup is best effort.
				} catch ( Throwable ) {
				}
			}
			try {
				( $this->invalidateNative )( $type );
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The committed source transition is authoritative; cache cleanup is best effort.
			} catch ( Throwable ) {
			}

			return ReleaseTrackingResult::succeeded( 'branch_restored', 'Branch-based package management is restored.' );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'release_unavailable', 'The package source could not be changed.' );
		}
	}

	/**
	 * @param callable(): bool $mutation
	 */
	private function mutateWithUpdaterLock( callable $mutation ): ?bool {
		try {
			return $this->updaterLock->run( $mutation );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function package( string $type, string $identifier ): Package {
		if ( 'plugin' === $type ) {
			return $this->plugins->boosterPluginFromFile( $identifier );
		}
		if ( 'theme' === $type ) {
			return $this->themes->boosterThemeFromStylesheet( $identifier );
		}

		throw new InvalidArgumentException( 'The release tracking package type is invalid.' );
	}

	private function eligibility( string $type, string $identifier, Package $package ): ReleaseTrackingEligibility {
		if ( ! $this->releaseSourceSupported( $package ) ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED );
		}
		$providerCode = $package->getProviderCode();
		if ( null === $providerCode ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER );
		}
		try {
			$metadata      = $this->providers->requireCapability( $providerCode, RepositoryReleaseMetadata::class );
			$nativeTargets = $this->providers->requireCapability( $providerCode, RepositoryReleaseNativeTargets::class );
			$expected      = $metadata->expectedUpdateUri( $package->getRepository()->reference );
		} catch ( UnsupportedProviderCapability | UnknownProvider ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER );
		} catch ( Throwable ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_REPOSITORY );
		}
		$repository = (string) $package->getRepository();
		if ( '' === $expected ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_REPOSITORY );
		}
		if ( 'theme' === $type ) {
			if ( $identifier !== (string) $package->getSlug() ) {
				return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY );
			}
			$packageRoot = $identifier;
		} else {
			$parts = explode( '/', $identifier );
			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY );
			}
			$packageRoot = $parts[0];
		}

		if ( $this->metadataEligibilityOverridden && ( $this->metadataEligible )() ) {
			return $this->eligibleOrSelfManagedTarget( $type, $identifier, $package, $expected, $packageRoot, $nativeTargets );
		}
		$updateUri = $this->updateUri( $type, $identifier );
		if ( '' === $updateUri ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::MISSING_UPDATE_URI, $expected, $packageRoot );
		}
		if ( ! hash_equals( $expected, $updateUri ) || ! ( $this->metadataEligible )() ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::MISMATCHED_UPDATE_URI, $expected, $packageRoot );
		}

		return $this->eligibleOrSelfManagedTarget( $type, $identifier, $package, $expected, $packageRoot, $nativeTargets );
	}

	private function eligibleOrSelfManagedTarget(
		string $type,
		string $identifier,
		Package $package,
		string $expectedUpdateUri,
		string $packageRoot,
		RepositoryReleaseNativeTargets $nativeTargets
	): ReleaseTrackingEligibility {
		if ( PackageSource::BRANCH === $package->getSource()
			&& $this->hasRegisteredTarget( $nativeTargets, $type, $this->targetIdentity( $type, $identifier ) ) ) {
			return new ReleaseTrackingEligibility(
				ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER,
				$expectedUpdateUri,
				$packageRoot
			);
		}

		return new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::ELIGIBLE,
			$expectedUpdateUri,
			$packageRoot
		);
	}

	private function hasRegisteredTarget( RepositoryReleaseNativeTargets $nativeTargets, string $type, string $identity ): bool {
		try {
			return $nativeTargets->hasRegisteredNativeTarget( $type, $identity );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function releaseSourceSupported( Package $package ): bool {
		return null === $package->getSubdirectory();
	}

	private function subdirectoryNotSupported(): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed(
			ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED,
			self::SUBDIRECTORY_MESSAGE
		);
	}

	private function targetIdentity( string $type, string $identifier ): string {
		$identity = strtolower( str_replace( '\\', '/', $identifier ) );

		return 'plugin' === $type ? ltrim( $identity, '/' ) : $identity;
	}

	private function updateUri( string $type, string $identifier ): string {
		if ( ! function_exists( 'get_file_data' ) ) {
			return '';
		}
		$root = 'plugin' === $type
			? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' )
			: ( function_exists( 'get_theme_root' ) ? get_theme_root() : '' );
		$file = 'plugin' === $type
			? rtrim( $root, '/\\' ) . '/' . $identifier
			: rtrim( $root, '/\\' ) . '/' . $identifier . '/style.css';
		if ( '' === $root || ! is_file( $file ) ) {
			return '';
		}
		$data      = get_file_data( $file, array( 'UpdateURI' => 'Update URI' ) );
		$updateUri = is_string( $data['UpdateURI'] ?? null )
			? rtrim( $data['UpdateURI'], '/' )
			: '';

		return $updateUri;
	}

	private function headerFile( string $type, string $identifier ): string {
		return 'theme' === $type ? 'style.css' : basename( $identifier );
	}

	private function branchPreflight(
		string $type,
		string $identifier,
		int $expectedSourceRevision,
		string $channel,
		Package $package,
		ReleaseTrackingEligibility $eligibility
	): ?ReleaseTrackingPreflight {
		if ( PackageSource::BRANCH !== $package->getSource()
			|| $expectedSourceRevision !== $package->getSourceRevision()
			|| ! $eligibility->eligible() ) {
			return null;
		}

		return $this->providerPreflight(
			$type,
			$package,
			$eligibility->packageRoot(),
			$this->headerFile( $type, $identifier ),
			$channel
		);
	}

	private function providerPreflight(
		string $type,
		Package $package,
		string $packageRoot,
		string $headerFile,
		string $channel
	): ReleaseTrackingPreflight {
		$providerCode = $package->getProviderCode();
		if ( null === $providerCode ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $packageRoot, reasonCode: 'provider_unavailable' );
		}

		try {
			$this->providers->requireCapability( $providerCode, RepositoryReleaseNativeTargets::class );
			$listing    = $this->providers->requireCapability( $providerCode, RepositoryReleaseCandidateListing::class );
			$inspector  = $this->providers->requireCapability( $providerCode, RepositoryReleaseInspector::class );
			$metadata   = $this->providers->requireCapability( $providerCode, RepositoryReleaseMetadata::class );
			$repository = $package->getRepository()->reference;
			$candidates = $listing->listReleaseCandidates( $type, $repository, $channel )->candidates;
			if ( array() === $candidates ) {
				return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $packageRoot, reasonCode: 'no_releases' );
			}

			$inspected = 0;
			foreach ( $candidates as $candidate ) {
				if ( 'stable' === $channel && $candidate->prerelease ) {
					return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $packageRoot, reasonCode: 'invalid_release' );
				}
				++$inspected;

				try {
					$inspection = $inspector->inspectRelease(
						$type,
						$repository,
						$candidate->providerReleaseId,
						$candidate->tag,
						$channel
					);
				} catch ( RepositoryReleaseInspectionRejected $failure ) {
					if ( RepositoryReleaseInspectionRejected::INCOMPATIBLE === $failure->reason ) {
						if ( 2 === $inspected ) {
							return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $packageRoot, reasonCode: 'release_incompatible' );
						}
						continue;
					}

					return new ReleaseTrackingPreflight(
						RepositoryReleaseInspectionRejected::NO_RELEASES === $failure->reason
							? ReleaseTrackingPreflight::RELEASE_UNAVAILABLE
							: ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
						$packageRoot,
						reasonCode: RepositoryReleaseInspectionRejected::NO_RELEASES === $failure->reason
							? 'no_releases'
							: 'invalid_release'
					);
				}

				if ( ! hash_equals( $candidate->providerReleaseId, $inspection->providerReleaseId )
					|| ! hash_equals( $candidate->tag, $inspection->tag )
					|| ! hash_equals( $candidate->version, $inspection->version )
					|| ! hash_equals( $packageRoot, $inspection->packageRoot )
					|| ! hash_equals( $headerFile, $inspection->mainFile ) ) {
					return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $packageRoot, reasonCode: 'release_identity_mismatch' );
				}

				$comparison   = version_compare( $inspection->version, $package->getVersion() );
				$relationship = match ( true ) {
					$comparison > 0 => 'newer',
					$comparison < 0 => 'older',
					default => 'same',
				};
				$releaseUrl = $metadata->releaseDetailsUrl( $repository, $inspection->tag );
				if ( '' === $releaseUrl ) {
					throw new InvalidArgumentException( 'The release details URL is unavailable.' );
				}

				return new ReleaseTrackingPreflight(
					ReleaseTrackingPreflight::READY,
					$packageRoot,
					$inspection->version,
					$releaseUrl,
					$inspection->tag,
					$inspection->version,
					$relationship
				);
			}

			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $packageRoot, reasonCode: 'release_incompatible' );
		} catch ( Throwable ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $packageRoot, reasonCode: 'provider_unavailable' );
		}
	}

	private function configurationFor(
		string $packageRoot,
		string $headerFile,
		string $channel
	): ManagedReleaseConfiguration {
		return new ManagedReleaseConfiguration( $packageRoot, $headerFile, $channel );
	}

	private function validChannel( string $channel ): bool {
		return in_array( $channel, array( 'stable', 'prerelease' ), true );
	}

	private function authorized(
		string $operation,
		string $type,
		string $identifier,
		int $sourceRevision,
		string $nonce,
		string $channel = ''
	): bool {
		try {
			return ( $this->canManage )( $type )
				&& ( $this->verifyNonce )(
					$nonce,
					$this->nonceAction( $operation, $type, $identifier, $sourceRevision, $channel )
				);
		} catch ( Throwable ) {
			return false;
		}
	}

	private function diagnosticTime( mixed $value ): string {
		return is_int( $value ) && $value > 0 ? gmdate( DATE_ATOM, $value ) : '';
	}

	private function projectCandidateValidation(
		string $packageRoot,
		Package $package,
		?RepositoryReleaseNativeTargetStatus $targetStatus
	): ?ReleaseTrackingPreflight {
		if ( null === $targetStatus || '' === $targetStatus->candidateCode ) {
			return null;
		}

		$code = match ( $targetStatus->candidateCode ) {
			'release_identity_verified' => ReleaseTrackingPreflight::READY,
			'release_version_mismatch' => ReleaseTrackingPreflight::RELEASE_VERSION_MISMATCH,
			'package_header_missing' => ReleaseTrackingPreflight::RELEASE_HEADER_MISSING,
			'package_header_invalid' => ReleaseTrackingPreflight::RELEASE_HEADER_INVALID,
			'package_archive_unreadable' => ReleaseTrackingPreflight::RELEASE_ARCHIVE_UNREADABLE,
			'github_updater_release_incompatible' => ReleaseTrackingPreflight::RELEASE_UNAVAILABLE,
			default => ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
		};
		$reasonCode = match ( $targetStatus->candidateCode ) {
			'release_identity_verified' => '',
			'release_version_mismatch',
			'package_header_missing',
			'package_header_invalid',
			'package_archive_unreadable',
			'package_zip_extension_unavailable',
			'package_archive_size_invalid',
			'package_archive_too_large',
			'package_archive_path_unsafe',
			'package_archive_path_duplicate',
			'package_archive_root_invalid',
			'package_archive_entry_duplicate',
			'package_archive_entry_limit',
			'release_version_invalid',
			'package_update_uri_missing',
			'package_update_uri_invalid',
			'package_compatibility_missing',
			'package_compatibility_invalid',
			'package_header_ambiguous' => $targetStatus->candidateCode,
			'github_updater_release_incompatible' => 'release_incompatible',
			default => 'invalid_release',
		};

		try {
			return new ReleaseTrackingPreflight(
				$code,
				$packageRoot,
				$targetStatus->candidateReleaseVersion,
				$this->releaseUrl( $package, $targetStatus->candidateReleaseTag ),
				$targetStatus->candidateReleaseTag,
				$targetStatus->candidatePackageHeaderVersion,
				'' !== $targetStatus->versionRelationship ? $targetStatus->versionRelationship : 'invalid',
				$reasonCode
			);
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	private function releaseUrl( Package $package, string $tag ): string {
		$providerCode = $package->getProviderCode();
		if ( null === $providerCode ) {
			return '';
		}
		try {
			$metadata = $this->providers->requireCapability( $providerCode, RepositoryReleaseMetadata::class );

			return $metadata->releaseDetailsUrl( $package->getRepository()->reference, $tag );
		} catch ( Throwable ) {
			return '';
		}
	}
}

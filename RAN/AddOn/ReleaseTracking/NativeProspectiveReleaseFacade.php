<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleasePreflight;
use RAN\WordPress\WordPressUpdaterLock;
use Throwable;

/**
 * Core-owned prospective release validation, installation and adoption.
 */
final class NativeProspectiveReleaseFacade implements ProspectiveReleaseFacade {

	/** @var \Closure(string): bool */
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	/** @var \Closure(): int */
	private \Closure $currentUserId;

	/**
	 * @param callable(string): bool|null         $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 * @param callable(): int|null                $currentUserId
	 */
	public function __construct(
		private PackageRepositoryRequestResolver $repositories,
		private ManagedReleasePreflight $preflight,
		private CorePackageExecutor $executor,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private WordPressUpdaterLock $updaterLock,
		private ProviderRegistry $providers,
		?callable $canManage = null,
		?callable $verifyNonce = null,
		?callable $currentUserId = null
	) {
		$this->canManage     = null === $canManage
			? static fn ( string $type ): bool => current_user_can( 'manage_options' )
				&& current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' )
			: \Closure::fromCallable( $canManage );
		$this->verifyNonce   = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
		$this->currentUserId = null === $currentUserId
			? static fn (): int => get_current_user_id()
			: \Closure::fromCallable( $currentUserId );
	}

	public function nonceAction( string $operation, string $type ): string {
		if ( ! in_array( $operation, array( 'list_candidates', 'discover', 'inspect', 'install' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'The prospective release nonce scope is invalid.' );
		}

		return 'ran-booster-prospective-release-' . $operation . '-' . $type;
	}

	public function supportedProviderCodes( string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		try {
			$this->providers->requireCapability( 'gh', RepositoryReleaseCandidateListing::class );
			$this->providers->requireCapability( 'gh', RepositoryReleaseInspector::class );
			$this->providers->requireCapability( 'gh', RepositoryReleaseAcquirer::class );
			$this->providers->requireCapability( 'gh', RepositoryReleaseMetadata::class );
			$this->providers->requireCapability( 'gh', RepositoryReleaseNativeTargets::class );
		} catch ( Throwable ) {
			return array();
		}

		return array( 'gh' );
	}

	public function listCandidates(
		string $type,
		array $repositoryRequest,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'list_candidates', $type, $nonce ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		$listing = $this->releaseCandidateListing( $repositoryRequest );
		if ( null === $listing ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			$repository = $this->resolveRepository( $repositoryRequest );
			$result     = $listing->listReleaseCandidates(
				$type,
				$this->repositoryReference( $repository ),
				$channel
			);
			if ( array() === $result->candidates ) {
				return ProspectiveReleaseResult::failure( 'no_releases' );
			}

			$candidates = array();
			foreach ( $result->candidates as $candidate ) {
				if ( 'stable' === $channel && $candidate->prerelease ) {
					throw new InvalidArgumentException( 'The release candidate conflicts with the requested channel.' );
				}
				$releaseId = filter_var(
					$candidate->providerReleaseId,
					FILTER_VALIDATE_INT,
					array( 'options' => array( 'min_range' => 1 ) )
				);
				if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $candidate->providerReleaseId )
					|| false === $releaseId ) {
					throw new InvalidArgumentException( 'The release candidate identity is incompatible.' );
				}
				$candidates[] = array(
					'release_id'           => $releaseId,
					'tag'                  => $candidate->tag,
					'version'              => $candidate->version,
					'prerelease'           => $candidate->prerelease,
					'published_at'         => $candidate->publishedAt,
					'expected_asset_names' => $candidate->expectedAssetNames,
				);
			}

			return ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => $candidates,
					'channel'    => $channel,
				)
			);
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function discover(
		string $type,
		array $repositoryRequest,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'discover', $type, $nonce ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		if ( ! $this->supportsCompleteProspectiveReleaseProvider( $type, $repositoryRequest ) ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			$repository = $this->resolveRepository( $repositoryRequest );
			$result     = $this->preflight->discoverProspective(
				$type,
				$repository,
				$channel
			);

			return $this->preflightResult( $result, 'release_available' );
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function inspect(
		string $type,
		array $repositoryRequest,
		int $releaseId,
		string $tag,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'inspect', $type, $nonce )
			|| ! $this->validExactRelease( $releaseId, $tag ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		$capabilities = $this->releaseInspectionCapabilities( $repositoryRequest );
		if ( null === $capabilities ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			$repository = $this->repositoryReference( $this->resolveRepository( $repositoryRequest ) );
			$inspection = $capabilities['inspector']->inspectRelease(
				$type,
				$repository,
				(string) $releaseId,
				$tag,
				$channel
			);
			if ( (string) $releaseId !== $inspection->providerReleaseId
				|| ! hash_equals( $tag, $inspection->tag ) ) {
				throw RepositoryReleaseInspectionRejected::invalidRelease();
			}

			return ProspectiveReleaseResult::success(
				'release_ready',
				array(
					'release_id'   => $releaseId,
					'tag'          => $inspection->tag,
					'version'      => $inspection->version,
					'commit'       => $inspection->providerCommitId,
					'details_url'  => $capabilities['metadata']->releaseDetailsUrl( $repository, $inspection->tag ),
					'package_root' => $inspection->packageRoot,
					'main_file'    => $inspection->mainFile,
					'fingerprint'  => $inspection->fingerprint,
					'channel'      => $channel,
				)
			);
		} catch ( RepositoryReleaseInspectionRejected $rejection ) {
			return ProspectiveReleaseResult::failure(
				match ( $rejection->reason ) {
					RepositoryReleaseInspectionRejected::NO_RELEASES => 'no_releases',
					RepositoryReleaseInspectionRejected::INVALID_RELEASE => 'release_invalid',
					default => 'unable_to_check',
				}
			);
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function install(
		string $type,
		array $repositoryRequest,
		int $releaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->validChannel( $channel )
			|| ! $this->authorized( 'install', $type, $nonce )
			|| ! $this->validExactRelease( $releaseId, $tag )
			|| ! $this->validFingerprint( $expectedFingerprint ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		$acquirer = $this->releaseAcquirer( $repositoryRequest );
		if ( null === $acquirer ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			PackageMutationGuard::assertFilesystemMutationAllowed();
			$repository          = $this->resolveRepository( $repositoryRequest );
			$repositoryReference = $this->repositoryReference( $repository );
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'install_failed' );
		}

		try {
			$release = $acquirer->acquireRelease(
				$type,
				$repositoryReference,
				(string) $releaseId,
				$tag,
				$expectedFingerprint,
				$channel
			);
		} catch ( RepositoryReleaseAcquisitionRejected $rejection ) {
			return ProspectiveReleaseResult::failure(
				RepositoryReleaseAcquisitionRejected::CLEANUP_FAILED === $rejection->reason
					? 'installation_cleanup_failed'
					: 'release_invalid'
			);
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}

		return $this->installExact( $type, $repository, $release, $channel );
	}

	/** @param array<string, mixed> $request
	 *  @return array<string, mixed>
	 */
	private function resolveRepository( array $request ): array {
		$request['deployment_policy'] = DeploymentPolicy::MANUAL->value;
		$request['subdirectory']      = '';

		return $this->repositories->resolve( $request );
	}

	/**
	 * @param array<string, mixed> $repository
	 */
	private function installExact(
		string $type,
		array $repository,
		RepositoryReleaseArtifact $release,
		string $channel
	): ProspectiveReleaseResult {
		$artifact           = null;
		$lockToken          = null;
		$outcome            = ProspectiveReleaseResult::failure( 'install_failed' );
		$finalizationFailed = false;

		try {
			$identifier    = $release->identifier( $type );
			$repository    = $this->managedRepository( $repository );
			$configuration = new ManagedReleaseConfiguration(
				$release->packageRoot(),
				$release->mainFile(),
				$channel
			);
			$userId        = ( $this->currentUserId )();
			$lockToken     = $this->updaterLock->acquire();
			$wasActive     = $this->isActive( $type, $identifier );
			if ( $this->isInstalled( $type, $identifier )
				|| $this->hasManagementRecord( $type, $identifier )
				|| $wasActive ) {
				$outcome = ProspectiveReleaseResult::failure( 'package_already_exists' );
			} else {
				PackageMutationGuard::assertFilesystemMutationAllowed();
				$artifact            = $release->handoffToCore();
				$result              = 'plugin' === $type
					? $this->executor->installPlugin( $artifact, $release->packageRoot(), null )
					: $this->executor->installTheme( $artifact, $release->packageRoot(), null );
				$exists              = $this->installedStateOrNull( $type, $identifier );
				$package             = true === $exists ? $this->installedPackageOrNull( $type, $identifier ) : null;
				$isActive            = $this->activeStateOrNull( $type, $identifier );
				$activationUnchanged = null !== $isActive && $wasActive === $isActive;
				if ( null === $exists || ( true === $exists && null === $package ) ) {
					$outcome = ProspectiveReleaseResult::failure(
						'management_state_uncertain',
						array( 'identifier' => $identifier )
					);
				} elseif ( null !== $package ) {
					$actualVersion = $package->getVersion();
					if ( ! $result->isSuccessful()
						|| ! hash_equals( $release->version(), $actualVersion )
						|| ! $activationUnchanged ) {
						$outcome = $this->installedButUnmanaged( $identifier, $actualVersion );
					} else {
						$package->setRepository( $repository );
						$package->setSubdirectory( null );
						$package->setDeploymentPolicy( DeploymentPolicy::MANUAL );
						$package->setSource( PackageSource::RELEASE_ASSET, 1 );
						$adoption = $this->adoptRelease(
							$type,
							$package,
							$configuration,
							$userId
						);
						$outcome  = $adoption
							? ProspectiveReleaseResult::success(
								'installed',
								array(
									'identifier' => $identifier,
									'version'    => $release->version(),
								)
							)
							: $this->installedButUnmanaged( $identifier, $actualVersion );
					}
				} elseif ( ! $activationUnchanged ) {
					$outcome = ProspectiveReleaseResult::failure(
						'management_state_uncertain',
						array( 'identifier' => $identifier )
					);
				} elseif ( ! $result->isSuccessful() ) {
					$outcome = ProspectiveReleaseResult::failure(
						$result->getFailure()?->value ?? 'wordpress_failed'
					);
				} else {
					$outcome = ProspectiveReleaseResult::failure(
						'management_state_uncertain',
						array(
							'identifier' => $identifier,
							'version'    => $release->version(),
						)
					);
				}
			}
		} catch ( Throwable ) {
			$outcome = ProspectiveReleaseResult::failure( 'install_failed' );
		} finally {
			try {
				if ( null !== $artifact ) {
					$artifact->cleanup();
				} elseif ( ! $release->discard() ) {
					$finalizationFailed = true;
					BoosterLogger::log(
						'prospective release artifact cleanup failed',
						array( 'step' => 'prospective_release_cleanup' )
					);
				}
			} catch ( Throwable $failure ) {
				$finalizationFailed = true;
				BoosterLogger::logException(
					'prospective release artifact cleanup failed',
					$failure,
					array( 'step' => 'prospective_release_cleanup' )
				);
			}
			if ( null !== $lockToken ) {
				try {
					if ( ! $this->updaterLock->release( $lockToken ) ) {
						$finalizationFailed = true;
						BoosterLogger::log(
							'prospective release updater lock release failed',
							array( 'step' => 'prospective_release_lock_release' )
						);
					}
				} catch ( Throwable $failure ) {
					$finalizationFailed = true;
					BoosterLogger::logException(
						'prospective release updater lock release failed',
						$failure,
						array( 'step' => 'prospective_release_lock_release' )
					);
				}
			}
		}

		if ( $finalizationFailed ) {
			return ProspectiveReleaseResult::failure(
				'installation_cleanup_failed',
				$outcome->data()
			);
		}

		return $outcome;
	}

	/** @param array<string, mixed> $repository */
	private function managedRepository( array $repository ): ManagedRepository {
		return new ManagedRepository(
			(string) $repository['provider'],
			(string) $repository['repository'],
			(string) $repository['provider_repository_id'],
			(string) $repository['repository_default_branch'],
			'1' === (string) $repository['private'],
			'' === (string) $repository['credential_id'] ? null : (string) $repository['credential_id']
		);
	}

	private function installedPackageOrNull( string $type, string $identifier ): ?Package {
		try {
			return $this->installedPackage( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function installedStateOrNull( string $type, string $identifier ): ?bool {
		try {
			return $this->isInstalled( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function isActive( string $type, string $identifier ): bool {
		if ( 'plugin' === $type ) {
			return in_array( $identifier, (array) get_option( 'active_plugins', array() ), true );
		}

		return in_array(
			$identifier,
			array( (string) get_option( 'stylesheet', '' ), (string) get_option( 'template', '' ) ),
			true
		);
	}

	private function activeStateOrNull( string $type, string $identifier ): ?bool {
		try {
			return $this->isActive( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function installedButUnmanaged( string $identifier, string $version ): ProspectiveReleaseResult {
		return ProspectiveReleaseResult::failure(
			'installed_but_unmanaged',
			array(
				'identifier' => $identifier,
				'version'    => $version,
			)
		);
	}

	private function installedPackage( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->installedPluginFromFile( $identifier )
			: $this->themes->installedThemeFromStylesheet( $identifier );
	}

	private function isInstalled( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->isInstalled( $identifier )
			: $this->themes->isInstalled( $identifier );
	}

	private function hasManagementRecord( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->hasManagementRecord( $identifier )
			: $this->themes->hasManagementRecord( $identifier );
	}

	private function adoptRelease(
		string $type,
		Package $package,
		ManagedReleaseConfiguration $configuration,
		int $userId
	): bool {
		if ( 'plugin' === $type && $package instanceof Plugin ) {
			return $this->plugins->adoptRelease( $package, $configuration, $userId )->isSuccessful();
		}
		if ( 'theme' === $type && $package instanceof Theme ) {
			return $this->themes->adoptRelease( $package, $configuration, $userId )->isSuccessful();
		}

		return false;
	}

	private function authorized( string $operation, string $type, string $nonce ): bool {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! ( $this->canManage )( $type ) ) {
			return false;
		}

		return '' !== $nonce
			&& ( $this->verifyNonce )( $nonce, $this->nonceAction( $operation, $type ) );
	}

	/** @param array<string, mixed> $repositoryRequest */
	private function supportsCompleteProspectiveReleaseProvider( string $type, array $repositoryRequest ): bool {
		$provider = $repositoryRequest['provider'] ?? null;

		return is_string( $provider )
			&& in_array( $provider, $this->supportedProviderCodes( $type ), true );
	}

	/** @param array<string, mixed> $repositoryRequest */
	private function releaseCandidateListing( array $repositoryRequest ): ?RepositoryReleaseCandidateListing {
		$provider = $repositoryRequest['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return null;
		}

		try {
			$capability = $this->providers->requireCapability( $provider, RepositoryReleaseCandidateListing::class );
		} catch ( Throwable ) {
			return null;
		}

		return $capability instanceof RepositoryReleaseCandidateListing ? $capability : null;
	}

	/** @param array<string, mixed> $repositoryRequest */
	private function releaseAcquirer( array $repositoryRequest ): ?RepositoryReleaseAcquirer {
		$provider = $repositoryRequest['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return null;
		}

		try {
			$capability = $this->providers->requireCapability( $provider, RepositoryReleaseAcquirer::class );
		} catch ( Throwable ) {
			return null;
		}

		return $capability instanceof RepositoryReleaseAcquirer ? $capability : null;
	}

	/**
	 * @param array<string, mixed> $repositoryRequest
	 * @return array{inspector: RepositoryReleaseInspector, metadata: RepositoryReleaseMetadata}|null
	 */
	private function releaseInspectionCapabilities( array $repositoryRequest ): ?array {
		$provider = $repositoryRequest['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return null;
		}

		try {
			$inspector = $this->providers->requireCapability( $provider, RepositoryReleaseInspector::class );
			$metadata  = $this->providers->requireCapability( $provider, RepositoryReleaseMetadata::class );
		} catch ( Throwable ) {
			return null;
		}

		return $inspector instanceof RepositoryReleaseInspector
			&& $metadata instanceof RepositoryReleaseMetadata
			? array(
				'inspector' => $inspector,
				'metadata'  => $metadata,
			)
			: null;
	}

	/** @param array<string, mixed> $repository */
	private function repositoryReference( array $repository ): RepositoryReference {
		$repositoryId = $repository['provider_repository_id'] ?? null;
		$credentialId = $repository['credential_id'] ?? null;

		return new RepositoryReference(
			(string) ( $repository['repository'] ?? '' ),
			is_string( $repositoryId ) && '' !== $repositoryId ? $repositoryId : null,
			'1' === ( $repository['private'] ?? null ),
			is_string( $credentialId ) && '' !== $credentialId ? $credentialId : null
		);
	}

	private function validExactRelease( int $releaseId, string $tag ): bool {
		return $releaseId > 0
			&& 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag );
	}

	private function validFingerprint( string $fingerprint ): bool {
		return 1 === preg_match( '/\Av1:[a-f0-9]{64}\z/D', $fingerprint );
	}

	private function validChannel( string $channel ): bool {
		return in_array( $channel, array( 'stable', 'prerelease' ), true );
	}

	private function preflightResult(
		array|\WP_Error $result,
		string $successCode
	): ProspectiveReleaseResult {
		if ( ! $result instanceof \WP_Error ) {
			return ProspectiveReleaseResult::success( $successCode, $result );
		}

		$code = $result->get_error_code();
		if ( 'github_updater_no_eligible_release' === $code ) {
			return ProspectiveReleaseResult::failure( 'no_releases' );
		}
		if ( in_array(
			$code,
			array(
				'github_updater_prerelease_not_allowed',
				'github_updater_invalid_preflight_target',
				'github_updater_invalid_package_identity_target',
				'github_updater_invalid_release_fingerprint',
				'github_updater_artifact_continuity_failed',
			),
			true
		) ) {
			return ProspectiveReleaseResult::failure( 'release_invalid' );
		}

		return ProspectiveReleaseResult::failure( 'unable_to_check' );
	}
}

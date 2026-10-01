<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\ReleaseArtifactCleanupFailure;
use RAN\Deployment\ReleaseArtifactCustodian;
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
use RAN\Internal\ReleaseManagement\ProspectiveReleaseCandidateReader;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\WordPressUpdaterLock;
use Throwable;

/**
 * Core-owned prospective release validation, installation and adoption.
 */
final class NativeProspectiveReleaseFacade implements ProspectiveReleaseFacade {
	private readonly ProspectiveReleaseCandidateReader $candidate_reader;
	private RepositorySourceGuard $source_guard;

	/** @var \Closure(string): bool */
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/** @var \Closure(): int */
	private \Closure $current_user_id;

	/**
	 * @param callable(string): bool|null         $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 * @param callable(): int|null                $currentUserId
	 */
	public function __construct(
		private PackageRepositoryRequestResolver $repositories,
		private CorePackageExecutor $executor,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private WordPressUpdaterLock $updaterLock,
		private ProviderRegistry $providers,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $canManage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $verifyNonce = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $currentUserId = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?RepositorySourceGuard $sourceGuard = null
	) {
		$this->candidate_reader = new ProspectiveReleaseCandidateReader( $repositories, $providers );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->source_guard = $sourceGuard ?? new RepositorySourceGuard();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->can_manage = null === $canManage
			? static fn ( string $type ): bool => current_user_can( 'manage_options' )
				&& current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $canManage );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->verify_nonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $verifyNonce );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->current_user_id = null === $currentUserId
			? static fn (): int => get_current_user_id()
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $currentUserId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function nonceAction( string $operation, string $type ): string {
		if ( ! in_array( $operation, array( 'list_candidates', 'inspect', 'install' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'The prospective release nonce scope is invalid.' );
		}

		return 'ran-booster-prospective-release-' . $operation . '-' . $type;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function supportedProviderCodes( string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		$supported = array();
		foreach ( $this->providers->orderedMetadata() as $metadata ) {
			$provider = $metadata->code->value;
			if ( ! $this->candidate_reader->supportsProviderCode( $provider ) ) {
				continue;
			}

			$supported[] = $provider;
		}

		return $supported;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function listCandidates(
		string $type,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $repositoryRequest,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}
		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'list_candidates', $type, $nonce ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			return $this->candidate_reader->read( $type, $repositoryRequest, $channel );
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function inspect(
		string $type,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $repositoryRequest,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $releaseId,
		string $tag,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'inspect', $type, $nonce )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| ! $this->valid_exact_release( $releaseId, $tag ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$capabilities = $this->release_inspection_capabilities( $repositoryRequest );
		if ( null === $capabilities ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$repository = $this->repository_reference( $this->resolve_repository( $repositoryRequest ) );
			$inspection = $capabilities['inspector']->inspectRelease(
				$type,
				$repository,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$releaseId,
				$tag,
				$channel
			);
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract. Retain the promoted constructor or external DTO property contract.
			if ( $releaseId !== $inspection->providerReleaseId
				|| ! hash_equals( $tag, $inspection->tag ) ) {
				throw RepositoryReleaseInspectionRejected::invalidRelease();
			}

			return ProspectiveReleaseResult::success(
				'release_ready',
				array(
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					'release_id'   => $releaseId,
					'tag'          => $inspection->tag,
					'version'      => $inspection->version,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					'commit'       => $inspection->providerCommitId,
					'details_url'  => $capabilities['metadata']->releaseDetailsUrl( $repository, $inspection->tag ),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					'package_root' => $inspection->packageRoot,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					'main_file'    => $inspection->mainFile,
					'fingerprint'  => $inspection->fingerprint,
					'channel'      => $channel,
				)
			);
		} catch ( RepositoryReleaseInspectionRejected $rejection ) {
			return ProspectiveReleaseResult::failure(
				match ( $rejection->reason ) {
					RepositoryReleaseInspectionRejected::NO_RELEASES => 'no_releases',
					RepositoryReleaseInspectionRejected::INVALID_RELEASE,
					RepositoryReleaseInspectionRejected::INCOMPATIBLE => 'release_invalid',
					default => 'unable_to_check',
				}
			);
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function install(
		string $type,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $repositoryRequest,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $releaseId,
		string $tag,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		string $expectedFingerprint,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'install', $type, $nonce )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| ! $this->valid_exact_release( $releaseId, $tag )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| ! $this->valid_fingerprint( $expectedFingerprint ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$acquirer = $this->release_acquirer( $repositoryRequest );
		if ( null === $acquirer ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			PackageMutationGuard::assert_filesystem_mutation_allowed();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$repository   = $this->resolve_repository( $repositoryRequest );
			$availability = $this->prospective_release_source_available( $type, $repository );
			if ( true !== $availability ) {
				return ProspectiveReleaseResult::failure(
					false === $availability
						? 'release_repository_conflict'
						: 'release_unavailable'
				);
			}
			$repository_reference = $this->repository_reference( $repository );
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'install_failed' );
		}

		try {
			$release = $acquirer->acquireRelease(
				$type,
				$repository_reference,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$releaseId,
				$tag,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
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

		return $this->install_exact( $type, $repository, $release, $channel );
	}

	/** @param array<string, mixed> $request
	 *  @return array<string, mixed>
	 */
	private function resolve_repository( array $request ): array {
		$request['deployment_policy'] = DeploymentPolicy::MANUAL->value;
		$request['subdirectory']      = '';

		return $this->repositories->resolve( $request );
	}

	/**
	 * @param array<string, mixed> $repository
	 */
	private function install_exact(
		string $type,
		array $repository,
		RepositoryReleaseArtifact $release,
		string $channel
	): ProspectiveReleaseResult {
		$artifact            = null;
		$lock_token          = null;
		$outcome             = ProspectiveReleaseResult::failure( 'install_failed' );
		$finalization_failed = false;

		try {
			$identifier = $release->identifier( $type );
			$repository = $this->managed_repository( $repository );
			do {
				$availability = $this->release_source_available( $type, $identifier, $repository );
				if ( true !== $availability ) {
					$outcome = ProspectiveReleaseResult::failure( false === $availability ? 'release_repository_conflict' : 'release_unavailable' );
					break;
				}
				$configuration = new ManagedReleaseConfiguration(
					$release->packageRoot(),
					$release->mainFile(),
					$channel
				);
				$user_id       = ( $this->current_user_id )();
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$lock_token   = $this->updaterLock->acquire();
				$availability = $this->release_source_available( $type, $identifier, $repository );
				if ( true !== $availability ) {
					$outcome = ProspectiveReleaseResult::failure( false === $availability ? 'release_repository_conflict' : 'release_unavailable' );
					break;
				}
				$was_active = $this->is_active( $type, $identifier );
				if ( $this->is_installed( $type, $identifier )
					|| $this->has_management_record( $type, $identifier )
					|| $was_active ) {
					$outcome = ProspectiveReleaseResult::failure( 'package_already_exists' );
				} else {
					PackageMutationGuard::assert_filesystem_mutation_allowed();
					$artifact             = ReleaseArtifactCustodian::claim( $release->handoffToCore() );
					$result               = 'plugin' === $type
						? $this->executor->installPlugin( $artifact, $release->packageRoot(), null )
						: $this->executor->installTheme( $artifact, $release->packageRoot(), null );
					$exists               = $this->installed_state_or_null( $type, $identifier );
					$package              = true === $exists ? $this->installed_package_or_null( $type, $identifier ) : null;
					$is_active            = $this->active_state_or_null( $type, $identifier );
					$activation_unchanged = null !== $is_active && $was_active === $is_active;
					if ( null === $exists || ( true === $exists && null === $package ) ) {
						$outcome = ProspectiveReleaseResult::failure(
							'management_state_uncertain',
							array( 'identifier' => $identifier )
						);
					} elseif ( null !== $package ) {
						$actual_version = $package->get_version();
						if ( ! $result->is_successful()
							|| ! hash_equals( $release->version(), $actual_version )
							|| ! $activation_unchanged ) {
							$outcome = $this->installed_but_unmanaged( $identifier, $actual_version );
						} else {
							$package->set_repository( $repository );
							$package->set_subdirectory( null );
							$package->set_deployment_policy( DeploymentPolicy::MANUAL );
							$package->set_source( PackageSource::RELEASE_ASSET, 1 );
							$adoption = $this->adopt_release(
								$type,
								$package,
								$configuration,
								$user_id
							);
							$outcome  = $adoption
								? ProspectiveReleaseResult::success(
									'installed',
									array(
										'identifier' => $identifier,
										'version'    => $release->version(),
									)
								)
								: $this->installed_but_unmanaged( $identifier, $actual_version );
						}
					} elseif ( ! $activation_unchanged ) {
						$outcome = ProspectiveReleaseResult::failure(
							'management_state_uncertain',
							array( 'identifier' => $identifier )
						);
					} elseif ( ! $result->is_successful() ) {
						$outcome = ProspectiveReleaseResult::failure(
							$result->get_failure()?->value ?? 'wordpress_failed'
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
			} while ( false );
		} catch ( ReleaseArtifactCleanupFailure $failure ) {
			$finalization_failed = true;
			$outcome             = ProspectiveReleaseResult::failure( 'install_failed' );
			BoosterLogger::logException(
				'prospective release Core artifact cleanup failed',
				$failure,
				array( 'step' => 'prospective_release_cleanup' )
			);
		} catch ( Throwable ) {
			$outcome = ProspectiveReleaseResult::failure( 'install_failed' );
		} finally {
			try {
				if ( null !== $artifact ) {
					$artifact->cleanup();
				} elseif ( ! $release->discard() ) {
					$finalization_failed = true;
					BoosterLogger::log(
						'prospective release artifact cleanup failed',
						array( 'step' => 'prospective_release_cleanup' )
					);
				}
			} catch ( Throwable $failure ) {
				$finalization_failed = true;
				BoosterLogger::logException(
					'prospective release artifact cleanup failed',
					$failure,
					array( 'step' => 'prospective_release_cleanup' )
				);
			}
			if ( null !== $lock_token ) {
				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					if ( ! $this->updaterLock->release( $lock_token ) ) {
						$finalization_failed = true;
						BoosterLogger::log(
							'prospective release updater lock release failed',
							array( 'step' => 'prospective_release_lock_release' )
						);
					}
				} catch ( Throwable $failure ) {
					$finalization_failed = true;
					BoosterLogger::logException(
						'prospective release updater lock release failed',
						$failure,
						array( 'step' => 'prospective_release_lock_release' )
					);
				}
			}
		}

		if ( $finalization_failed ) {
			return ProspectiveReleaseResult::failure(
				'installation_cleanup_failed',
				$outcome->data()
			);
		}

		return $outcome;
	}

	/** @param array<string, mixed> $repository */
	private function managed_repository( array $repository ): ManagedRepository {
		return new ManagedRepository(
			(string) $repository['provider'],
			(string) $repository['repository'],
			(string) $repository['provider_repository_id'],
			(string) $repository['repository_default_branch'],
			'1' === (string) $repository['private'],
			'' === (string) $repository['credential_id'] ? null : (string) $repository['credential_id']
		);
	}

	private function installed_package_or_null( string $type, string $identifier ): ?Package {
		try {
			return $this->installed_package( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function installed_state_or_null( string $type, string $identifier ): ?bool {
		try {
			return $this->is_installed( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function is_active( string $type, string $identifier ): bool {
		if ( 'plugin' === $type ) {
			return in_array( $identifier, (array) get_option( 'active_plugins', array() ), true );
		}

		return in_array(
			$identifier,
			array( (string) get_option( 'stylesheet', '' ), (string) get_option( 'template', '' ) ),
			true
		);
	}

	private function active_state_or_null( string $type, string $identifier ): ?bool {
		try {
			return $this->is_active( $type, $identifier );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function installed_but_unmanaged( string $identifier, string $version ): ProspectiveReleaseResult {
		return ProspectiveReleaseResult::failure(
			'installed_but_unmanaged',
			array(
				'identifier' => $identifier,
				'version'    => $version,
			)
		);
	}

	private function installed_package( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->installed_plugin_from_file( $identifier )
			: $this->themes->installed_theme_from_stylesheet( $identifier );
	}

	private function is_installed( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->is_installed( $identifier )
			: $this->themes->is_installed( $identifier );
	}

	private function has_management_record( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->has_management_record( $identifier )
			: $this->themes->has_management_record( $identifier );
	}

	/** @param array<string, mixed> $repository */
	private function prospective_release_source_available( string $type, array $repository ): ?bool {
		try {
			$assessment = $this->source_guard->assess(
				(string) ( $repository['provider'] ?? '' ),
				(string) ( $repository['provider_repository_id'] ?? '' ),
				'plugin' === $type ? 1 : 2,
				'__ran_booster_prospective_release__',
				PackageSource::RELEASE_ASSET
			);

			// A retry's package identifier is not trusted until the archive is
			// verified. Permit that read for a sole same-type Release record;
			// installExact must match its identifier before any filesystem work.
			if ( $assessment['allowed'] || (
				1 === $assessment['relationship_count']
				&& 1 === $assessment['release_count']
				&& ( 'plugin' === $type ? 1 : 2 ) === $assessment['owner_type']
			) ) {
				return true;
			}

			return 'repository_source_unavailable' === $assessment['code'] ? null : false;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function release_source_available( string $type, string $identifier, ManagedRepository $repository ): ?bool {
		try {
			$assessment = $this->source_guard->assess(
				$repository->provider->value,
				$repository->reference->providerRepositoryId,
				'plugin' === $type ? 1 : 2,
				$identifier,
				PackageSource::RELEASE_ASSET
			);

			if ( $assessment['allowed'] ) {
				return true;
			}

			return 'repository_source_unavailable' === $assessment['code'] ? null : false;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function adopt_release(
		string $type,
		Package $package,
		ManagedReleaseConfiguration $configuration,
		int $user_id
	): bool {
		if ( 'plugin' === $type && $package instanceof Plugin ) {
			return $this->plugins->adopt_release( $package, $configuration, $user_id )->is_successful();
		}
		if ( 'theme' === $type && $package instanceof Theme ) {
			return $this->themes->adopt_release( $package, $configuration, $user_id )->is_successful();
		}

		return false;
	}

	private function authorized( string $operation, string $type, string $nonce ): bool {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! ( $this->can_manage )( $type ) ) {
			return false;
		}

		return '' !== $nonce
			&& ( $this->verify_nonce )( $nonce, $this->nonceAction( $operation, $type ) );
	}

	/** @param array<string, mixed> $repository_request */
	private function release_candidate_listing( array $repository_request ): ?RepositoryReleaseCandidateListing {
		$provider = $repository_request['provider'] ?? null;
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

	/** @param array<string, mixed> $repository_request */
	private function release_acquirer( array $repository_request ): ?RepositoryReleaseAcquirer {
		$provider = $repository_request['provider'] ?? null;
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
	 * @param array<string, mixed> $repository_request
	 * @return array{inspector: RepositoryReleaseInspector, metadata: RepositoryReleaseMetadata}|null
	 */
	private function release_inspection_capabilities( array $repository_request ): ?array {
		$provider = $repository_request['provider'] ?? null;
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
	private function repository_reference( array $repository ): RepositoryReference {
		$repository_id = $repository['provider_repository_id'] ?? null;
		$credential_id = $repository['credential_id'] ?? null;

		return new RepositoryReference(
			(string) ( $repository['repository'] ?? '' ),
			is_string( $repository_id ) && '' !== $repository_id ? $repository_id : null,
			'1' === ( $repository['private'] ?? null ),
			is_string( $credential_id ) && '' !== $credential_id ? $credential_id : null
		);
	}

	private function valid_exact_release( string $release_id, string $tag ): bool {
		return 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,191}\z/D', $release_id )
			&& 1 === preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag );
	}

	private function valid_fingerprint( string $fingerprint ): bool {
		return 1 === preg_match( '/\Av2:[a-f0-9]{64}\z/D', $fingerprint );
	}

	private function valid_channel( string $channel ): bool {
		return in_array( $channel, array( 'stable', 'prerelease' ), true );
	}
}

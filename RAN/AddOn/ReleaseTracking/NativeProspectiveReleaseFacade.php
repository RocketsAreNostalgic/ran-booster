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

		private WordPressUpdaterLock $updater_lock,
		private ProviderRegistry $providers,

		?callable $can_manage = null,

		?callable $verify_nonce = null,

		?callable $current_user_id = null,

		?RepositorySourceGuard $source_guard = null
	) {
		$this->candidate_reader = new ProspectiveReleaseCandidateReader( $repositories, $providers );

		$this->source_guard = $source_guard ?? new RepositorySourceGuard();

		$this->can_manage = null === $can_manage
			? static fn ( string $type ): bool => current_user_can( 'manage_options' )
				&& current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' )

			: \Closure::fromCallable( $can_manage );

		$this->verify_nonce = null === $verify_nonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )

			: \Closure::fromCallable( $verify_nonce );

		$this->current_user_id = null === $current_user_id
			? static fn (): int => get_current_user_id()

			: \Closure::fromCallable( $current_user_id );
	}


	public function nonce_action( string $operation, string $type ): string {
		if ( ! in_array( $operation, array( 'list_candidates', 'inspect', 'install' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'The prospective release nonce scope is invalid.' );
		}

		return 'ran-booster-prospective-release-' . $operation . '-' . $type;
	}


	public function supported_provider_codes( string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		$supported = array();
		foreach ( $this->providers->ordered_metadata() as $metadata ) {
			$provider = $metadata->code->value;
			if ( ! $this->candidate_reader->supports_provider_code( $provider ) ) {
				continue;
			}

			$supported[] = $provider;
		}

		return $supported;
	}


	public function list_candidates(
		string $type,

		array $repository_request,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}
		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'list_candidates', $type, $nonce ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		try {

			return $this->candidate_reader->read( $type, $repository_request, $channel );
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function inspect(
		string $type,

		array $repository_request,

		string $release_id,
		string $tag,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'inspect', $type, $nonce )

			|| ! $this->valid_exact_release( $release_id, $tag ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}

		$capabilities = $this->release_inspection_capabilities( $repository_request );
		if ( null === $capabilities ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {

			$repository = $this->repository_reference( $this->resolve_repository( $repository_request ) );
			$inspection = $capabilities['inspector']->inspect_release(
				$type,
				$repository,

				$release_id,
				$tag,
				$channel
			);

			if ( $release_id !== $inspection->provider_release_id
				|| ! hash_equals( $tag, $inspection->tag ) ) {
				throw RepositoryReleaseInspectionRejected::invalid_release();
			}

			return ProspectiveReleaseResult::success(
				'release_ready',
				array(

					'release_id'   => $release_id,
					'tag'          => $inspection->tag,
					'version'      => $inspection->version,

					'commit'       => $inspection->provider_commit_id,
					'details_url'  => $capabilities['metadata']->release_details_url( $repository, $inspection->tag ),

					'package_root' => $inspection->package_root,

					'main_file'    => $inspection->main_file,
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

		array $repository_request,

		string $release_id,
		string $tag,

		string $expected_fingerprint,
		string $channel,
		string $nonce
	): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}

		if ( ! $this->valid_channel( $channel )
			|| ! $this->authorized( 'install', $type, $nonce )

			|| ! $this->valid_exact_release( $release_id, $tag )

			|| ! $this->valid_fingerprint( $expected_fingerprint ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}

		$acquirer = $this->release_acquirer( $repository_request );
		if ( null === $acquirer ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}

		try {
			PackageMutationGuard::assert_filesystem_mutation_allowed();

			$repository   = $this->resolve_repository( $repository_request );
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
			$release = $acquirer->acquire_release(
				$type,
				$repository_reference,

				$release_id,
				$tag,

				$expected_fingerprint,
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
					$release->package_root(),
					$release->main_file(),
					$channel
				);
				$user_id       = ( $this->current_user_id )();

				$lock_token   = $this->updater_lock->acquire();
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
					$artifact             = ReleaseArtifactCustodian::claim( $release->handoff_to_core() );
					$result               = 'plugin' === $type
						? $this->executor->install_plugin( $artifact, $release->package_root(), null )
						: $this->executor->install_theme( $artifact, $release->package_root(), null );
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
			BoosterLogger::log_exception(
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
				BoosterLogger::log_exception(
					'prospective release artifact cleanup failed',
					$failure,
					array( 'step' => 'prospective_release_cleanup' )
				);
			}
			if ( null !== $lock_token ) {
				try {

					if ( ! $this->updater_lock->release( $lock_token ) ) {
						$finalization_failed = true;
						BoosterLogger::log(
							'prospective release updater lock release failed',
							array( 'step' => 'prospective_release_lock_release' )
						);
					}
				} catch ( Throwable $failure ) {
					$finalization_failed = true;
					BoosterLogger::log_exception(
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
				$repository->reference->provider_repository_id,
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
			&& ( $this->verify_nonce )( $nonce, $this->nonce_action( $operation, $type ) );
	}

	/** @param array<string, mixed> $repository_request */
	private function release_candidate_listing( array $repository_request ): ?RepositoryReleaseCandidateListing {
		$provider = $repository_request['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return null;
		}

		try {
			$capability = $this->providers->require_capability( $provider, RepositoryReleaseCandidateListing::class );
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
			$capability = $this->providers->require_capability( $provider, RepositoryReleaseAcquirer::class );
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
			$inspector = $this->providers->require_capability( $provider, RepositoryReleaseInspector::class );
			$metadata  = $this->providers->require_capability( $provider, RepositoryReleaseMetadata::class );
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

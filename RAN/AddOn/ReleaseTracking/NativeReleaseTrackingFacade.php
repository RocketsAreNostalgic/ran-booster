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
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseSubdirectoryNotSupported;
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
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/** @var \Closure(string): void */
	private \Closure $refresh_native;

	/** @var \Closure(string, string, string): bool */
	private \Closure $metadata_eligible;
	private bool $metadata_eligibility_overridden;

	/** @var \Closure(string): void */
	private \Closure $invalidate_native;

	/** @var \Closure(string): ?string */
	private \Closure $public_lookup_profile;
	private WordPressUpdaterLock $updater_lock;
	private RepositorySourceGuard $source_guard;

	/**
	 * @param callable(string): bool|null         $canManage
	 * @param callable(string, string): bool|null $verifyNonce
	 * @param callable(string): void|null         $refreshNative
	 * @param callable(): bool|null $metadataEligible
	 * @param callable(string): void|null         $invalidateNative
	 * @param callable(string): ?string|null      $publicLookupProfile
	 */
	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ManagedReleaseStore $store,
		private ManagedReleaseTargetRegistrar $registrar,

		WordPressUpdaterLock $updater_lock,
		private ProviderRegistry $providers,

		?callable $can_manage = null,

		?callable $verify_nonce = null,

		?callable $refresh_native = null,

		?callable $metadata_eligible = null,

		?callable $invalidate_native = null,

		?callable $public_lookup_profile = null,

		?RepositorySourceGuard $source_guard = null
	) {

		$this->updater_lock = $updater_lock;

		$this->source_guard = $source_guard ?? new RepositorySourceGuard();

		$this->can_manage = null === $can_manage
			? static fn ( string $type ): bool => current_user_can( 'manage_options' )
				&& current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' )

			: \Closure::fromCallable( $can_manage );

		$this->verify_nonce = null === $verify_nonce
			? static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action )

			: \Closure::fromCallable( $verify_nonce );

		$this->refresh_native = null === $refresh_native
			? static function ( string $type ): void {
				if ( 'plugin' === $type ) {
					wp_update_plugins();
				} else {
					wp_update_themes();
				}
			}

			: \Closure::fromCallable( $refresh_native );

		$this->metadata_eligible = null === $metadata_eligible
			? static fn (): bool => true

			: \Closure::fromCallable( $metadata_eligible );

		$this->metadata_eligibility_overridden = null !== $metadata_eligible;

		$this->invalidate_native = null === $invalidate_native
			? static function ( string $type ): void {
				delete_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes' );
			}

			: \Closure::fromCallable( $invalidate_native );

		$this->public_lookup_profile = null === $public_lookup_profile
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The public lookup resolver callback receives a provider code; the default resolver deliberately returns no profile.
			? static fn ( string $provider ): ?string => null

			: \Closure::fromCallable( $public_lookup_profile );
	}


	public function nonce_action(
		string $operation,
		string $type,
		string $identifier,

		int $source_revision,
		string $channel = ''
	): string {
		$preflight = in_array( $operation, array( 'preflight', 'assessment_preflight', 'list_candidates', 'inspect_candidate' ), true );
		if ( ! in_array( $operation, array( 'preflight', 'assessment_preflight', 'list_candidates', 'inspect_candidate', 'enable', 'refresh', 'change_channel', 'return_to_branch' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $identifier

			|| $source_revision < 1
			|| ( $preflight && ! $this->valid_channel( $channel ) )
			|| ( ! $preflight && '' !== $channel ) ) {
			throw new InvalidArgumentException( 'The release tracking nonce scope is invalid.' );
		}


		$action = 'ran-booster-release-tracking-' . $operation . '-' . $type . '-' . $identifier . '-' . $source_revision;

		return $preflight ? $action . '-' . $channel : $action;
	}

	public function status( string $type, string $identifier ): ReleaseTrackingStatus {
		RuntimeSupport::assert_managed_operations_allowed();

		return $this->project_status( $type, $identifier );
	}

	private function project_status(
		string $type,
		string $identifier
	): ReleaseTrackingStatus {
		$package       = $this->package( $type, $identifier );
		$eligibility   = $this->eligibility( $type, $identifier, $package );
		$incompatible  = ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $eligibility->code();
		$configuration = null;
		$failure_code  = $incompatible ? ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED : '';
		if ( ! $incompatible && PackageSource::BRANCH === $package->get_source() ) {
			$availability = $this->release_source_available( $type, $package );
			if ( false === $availability ) {
				$failure_code = 'release_repository_conflict';
			} elseif ( null === $availability ) {
				$failure_code = 'release_unavailable';
			}
		}
		if ( PackageSource::RELEASE_ASSET === $package->get_source() ) {
			try {
				$configuration = $this->store->configuration( $type, $identifier );
				if ( null === $configuration ) {
					$failure_code = $incompatible ? $failure_code : 'release_configuration_invalid';
				}
			} catch ( Throwable ) {
				$failure_code = $incompatible ? $failure_code : 'release_configuration_invalid';
			}
		}
		$package_root  = $configuration?->package_root() ?? $eligibility->package_root();
		$preflight     = null;
		$target_status = $incompatible || null === $configuration
			? null
			: $this->registrar->status( $type, $identifier );

		$latest_version = $target_status?->offered_version ?? '';
		if ( ! $incompatible && PackageSource::RELEASE_ASSET === $package->get_source() && null !== $configuration ) {
			$preflight = $this->project_candidate_validation(
				$package_root,
				$package,
				$target_status
			);
			if ( '' === $latest_version && null !== $preflight ) {
				$latest_version = $preflight->latest_version();
			}
		}
		if ( null !== $configuration && '' === $failure_code ) {
			$failure_code = $this->registrar->failure_code( $type, $identifier );
		}
		if ( null !== $preflight && ! $preflight->ready() ) {
			$failure_code = $preflight->code();
		}

		$native_relationship  = $target_status?->version_relationship ?? '';
		$version_relationship = $preflight?->version_relationship()
			?? ( '' !== $native_relationship ? $native_relationship : 'invalid' );
		if ( '' === $failure_code && null !== $target_status ) {

			$failure_code = $target_status->failure_code;
		}

		return new ReleaseTrackingStatus(
			$type,
			$identifier,
			$package->get_source()->value,
			$package->get_source_revision(),
			(string) $package->get_provider_repository_id(),
			$package->get_deployment_policy()->value,
			$eligibility,
			$preflight,
			$package_root,
			$package->get_version(),
			$latest_version,
			'newer' === $version_relationship
				&& '' !== $latest_version
				&& ( null === $preflight || $preflight->ready() ),

			$this->diagnostic_time( $target_status?->last_check ),

			$this->diagnostic_time( $target_status?->next_check ),
			$failure_code,
			$configuration?->channel() ?? 'stable',

			$target_status?->candidate_provider_release_id ?? ''
		);
	}

	public function statuses( string $type, array $identifiers ): array {
		RuntimeSupport::assert_managed_operations_allowed();
		if ( count( $identifiers ) > 100 ) {
			throw new InvalidArgumentException( 'Too many release tracking statuses were requested.' );
		}
		$statuses = array();
		foreach ( $identifiers as $identifier ) {
			if ( ! is_string( $identifier ) || isset( $statuses[ $identifier ] ) ) {
				throw new InvalidArgumentException( 'The release tracking status selection is invalid.' );
			}
			$statuses[ $identifier ] = $this->project_status( $type, $identifier );
		}

		return $statuses;
	}

	public function preflight(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight {
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'preflight', $type, $identifier, $expected_source_revision, $nonce, $channel ) ) {
			return null;
		}

		try {
			$package = $this->package( $type, $identifier );

			return $this->branch_preflight(
				$type,
				$identifier,

				$expected_source_revision,
				$channel,
				$package,
				$this->eligibility( $type, $identifier, $package )
			);
		} catch ( Throwable ) {
			return null;
		}
	}


	public function assessment_preflight(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight {
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'assessment_preflight', $type, $identifier, $expected_source_revision, $nonce, $channel ) ) {
			return null;
		}

		try {
			$package = $this->package( $type, $identifier );
			if ( true !== $this->release_source_available( $type, $package ) ) {
				return null;
			}
			$eligibility = $this->eligibility( $type, $identifier, $package );
			if ( ! in_array( $package->get_source(), array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET ), true )

				|| $expected_source_revision !== $package->get_source_revision()
				|| ! $eligibility->eligible() ) {
				return null;
			}

			return $this->provider_preflight(
				$type,
				$package,
				$eligibility->package_root(),
				$this->header_file( $type, $identifier ),
				$channel
			);
		} catch ( Throwable ) {
			return null;
		}
	}


	public function list_candidates(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?\RAN\RepositoryProvider\RepositoryReleaseCandidateList {
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'list_candidates', $type, $identifier, $expected_source_revision, $nonce, $channel ) ) {
			return null;
		}

		try {

			$package = $this->managed_package_for_browser( $type, $identifier, $expected_source_revision );
			if ( null === $package ) {
				return null;
			}
			$listing = $this->providers->require_capability( (string) $package->get_provider_code(), RepositoryReleaseCandidateListing::class );

			foreach ( $this->release_browser_repositories( $package ) as $repository ) {
				try {
					return $listing->list_release_candidates( $type, $repository, $channel );
				} catch ( RepositoryReleaseReadUnavailable ) {
					continue;
				}
			}

			return null;
		} catch ( Throwable ) {
			return null;
		}
	}


	public function inspect_candidate(
		string $type,
		string $identifier,

		int $expected_source_revision,

		string $release_id,
		string $tag,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight {
		if ( ! RuntimeSupport::current()->allows_managed_operations()

			|| '' === $release_id
			|| '' === $tag
			|| ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'inspect_candidate', $type, $identifier, $expected_source_revision, $nonce, $channel ) ) {
			return null;
		}

		try {

			$package = $this->managed_package_for_browser( $type, $identifier, $expected_source_revision );
			if ( null === $package ) {
				return null;
			}
			$configuration = $this->store->configuration( $type, $identifier );
			if ( null === $configuration || $channel !== $configuration->channel() ) {
				return null;
			}
			$provider   = (string) $package->get_provider_code();
			$inspector  = $this->providers->require_capability( $provider, RepositoryReleaseInspector::class );
			$metadata   = $this->providers->require_capability( $provider, RepositoryReleaseMetadata::class );
			$inspection = null;
			$repository = null;
			foreach ( $this->release_browser_repositories( $package ) as $repository ) {
				try {

					$inspection = $inspector->inspect_release( $type, $repository, $release_id, $tag, $channel );
					break;
				} catch ( RepositoryReleaseReadUnavailable ) {
					continue;
				}
			}
			if ( null === $inspection || null === $repository ) {
				return null;
			}

			if ( ! hash_equals( $release_id, $inspection->provider_release_id )
				|| ! hash_equals( $tag, $inspection->tag )

				|| ! hash_equals( $configuration->package_root(), $inspection->package_root )

				|| ! hash_equals( $configuration->metadata_file(), $inspection->main_file ) ) {
				return null;
			}
			$url = $metadata->release_details_url( $repository, $inspection->tag );
			if ( '' === $url ) {
				return null;
			}
			$comparison = version_compare( $inspection->version, $package->get_version() );

			return new ReleaseTrackingPreflight(
				ReleaseTrackingPreflight::READY,

				$inspection->package_root,
				$inspection->version,
				$url,
				$inspection->tag,
				$inspection->version,
				$comparison > 0 ? 'newer' : ( $comparison < 0 ? 'older' : 'same' )
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	private function managed_package_for_browser( string $type, string $identifier, int $revision ): ?Package {
		$package = $this->package( $type, $identifier );
		if ( PackageSource::RELEASE_ASSET !== $package->get_source()
			|| $revision !== $package->get_source_revision()
			|| ! $this->release_source_supported( $package ) ) {
			return null;
		}

		return $package;
	}

	/** @return list<RepositoryReference> */
	private function release_browser_repositories( Package $package ): array {
		$repository = $package->get_repository()->reference;
		if ( $repository->private ) {

			return null === $repository->credential_id ? array() : array( $repository );
		}
		$profile_id = ( $this->public_lookup_profile )( (string) $package->get_provider_code() );

		if ( null !== $repository->credential_id ) {

			return $profile_id === $repository->credential_id || null === $profile_id
				? array( $repository )
				: array( $repository, $this->repository_with_credential( $repository, $profile_id ) );
		}
		if ( null !== $profile_id ) {
			return array( $this->repository_with_credential( $repository, $profile_id ) );
		}
		return array( $repository );
	}

	private function repository_with_credential( RepositoryReference $repository, string $credential_id ): RepositoryReference {
		return $repository->with_credential( $credential_id );
	}

	public function enable(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'enable', $type, $identifier, $expected_source_revision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be enabled.' );
		}
		try {
			$package      = $this->package( $type, $identifier );
			$availability = $this->release_source_available( $type, $package );
			if ( false === $availability ) {
				return ReleaseTrackingResult::failed( 'release_repository_conflict', 'Published releases require exclusive use of this provider repository.' );
			}
			if ( null === $availability ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
			}
			$eligibility = $this->eligibility( $type, $identifier, $package );
			if ( ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $eligibility->code() ) {
				return $this->subdirectory_not_supported();
			}
			if ( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER === $eligibility->code() ) {
				return ReleaseTrackingResult::failed(
					'target_already_uses_ran_updater',
					'This package already uses the RAN GitHub release updater. Use either its own updater or Booster release tracking, not both.'
				);
			}
			$preflight = $this->branch_preflight(
				$type,
				$identifier,

				$expected_source_revision,
				$channel,
				$package,
				$eligibility,
				false
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
			$package_root  = $eligibility->package_root();
			$header_file   = $this->header_file( $type, $identifier );
			$configuration = $this->configuration_for(
				$package_root,
				$header_file,
				$channel
			);
			$incompatible  = false;
			$conflict      = false;
			$unavailable   = false;
			$changed       = $this->mutate_with_updater_lock(

				function () use ( $type, $identifier, $expected_source_revision, $configuration, &$incompatible, &$conflict, &$unavailable ): bool {
					$package = $this->package( $type, $identifier );
					if ( ! $this->release_source_supported( $package ) ) {
						$incompatible = true;

						return false;
					}
					$availability = $this->release_source_available( $type, $package );
					if ( false === $availability ) {
						$conflict = true;

						return false;
					}
					if ( null === $availability ) {
						$unavailable = true;

						return false;
					}
					$changed = $this->store->transition(
						$type,
						$identifier,
						PackageSource::BRANCH,

						$expected_source_revision,
						PackageSource::RELEASE_ASSET,
						$configuration,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
					);
					if ( $changed ) {
						( $this->invalidate_native )( $type );
					}

					return $changed;
				}
			);
			if ( $changed instanceof ManagedReleaseSubdirectoryNotSupported ) {
				return $this->subdirectory_not_supported();
			}
			if ( null === $changed ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
			}
			if ( $incompatible ) {
				return $this->subdirectory_not_supported();
			}
			if ( $unavailable ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
			}
			if ( $conflict ) {
				return ReleaseTrackingResult::failed( 'release_repository_conflict', 'Published releases require exclusive use of this provider repository.' );
			}

			if ( ! $changed ) {
				$availability = $this->release_source_available( $type, $this->package( $type, $identifier ) );
				if ( false === $availability ) {
					return ReleaseTrackingResult::failed( 'release_repository_conflict', 'Published releases require exclusive use of this provider repository.' );
				}
				if ( null === $availability ) {
					return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
				}
			}

			return $changed
				? ReleaseTrackingResult::succeeded( 'release_enabled', 'Release tracking enabled' )
				: ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
		}
	}


	public function change_channel(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $channel,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		if ( ! $this->valid_channel( $channel )

			|| ! $this->authorized( 'change_channel', $type, $identifier, $expected_source_revision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The release track could not be changed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( ! $this->release_source_supported( $package ) ) {
				return $this->subdirectory_not_supported();
			}
			$configuration = $this->store->configuration( $type, $identifier );
			if ( PackageSource::RELEASE_ASSET !== $package->get_source()

				|| $expected_source_revision !== $package->get_source_revision()
				|| null === $configuration ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			if ( $channel === $configuration->channel() ) {
				$track = 'stable' === $channel ? 'Stable' : 'Preview';
				return ReleaseTrackingResult::succeeded( 'release_channel_current', $track . ' is already the active release track. No settings were changed.' );
			}
			$incompatible = false;
			$changed      = $this->mutate_with_updater_lock(

				function () use ( $type, $identifier, $expected_source_revision, $channel, &$incompatible ): bool {
					if ( ! $this->release_source_supported( $this->package( $type, $identifier ) ) ) {
						$incompatible = true;

						return false;
					}
					$changed = $this->store->change_channel(
						$type,
						$identifier,

						$expected_source_revision,
						$channel,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
					);
					if ( $changed ) {
						( $this->invalidate_native )( $type );
					}

					return $changed;
				}
			);
			if ( $changed instanceof ManagedReleaseSubdirectoryNotSupported ) {
				return $this->subdirectory_not_supported();
			}
			if ( null === $changed ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'The release track could not be changed.' );
			}
			if ( $incompatible ) {
				return $this->subdirectory_not_supported();
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

		int $expected_source_revision,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}


		if ( ! $this->authorized( 'refresh', $type, $identifier, $expected_source_revision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be refreshed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( ! $this->release_source_supported( $package ) ) {
				return $this->subdirectory_not_supported();
			}
			try {
				$configuration = $this->store->configuration( $type, $identifier );
			} catch ( InvalidArgumentException ) {
				return ReleaseTrackingResult::failed( 'release_configuration_invalid', 'Saved release tracking settings are invalid.' );
			}
			if ( PackageSource::RELEASE_ASSET !== $package->get_source()

				|| $expected_source_revision !== $package->get_source_revision()
				|| null === $configuration
				|| null === $this->registrar->target( $type, $identifier ) ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$target = $this->registrar->target( $type, $identifier );
			if ( null === $target || ! $target->refresh() ) {
				return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
			}
			( $this->refresh_native )( $type );

			return ReleaseTrackingResult::succeeded( 'release_refreshed', 'Published release information was refreshed.' );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
		}
	}


	public function return_to_branch(
		string $type,
		string $identifier,

		int $expected_source_revision,
		string $nonce
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}


		if ( ! $this->authorized( 'return_to_branch', $type, $identifier, $expected_source_revision, $nonce ) ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The package source could not be changed.' );
		}
		try {
			$package = $this->package( $type, $identifier );
			if ( PackageSource::RELEASE_ASSET !== $package->get_source()

				|| $expected_source_revision !== $package->get_source_revision() ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$changed = $this->mutate_with_updater_lock(

				function () use ( $type, $identifier, $expected_source_revision ): bool {
					return $this->store->transition(
						$type,
						$identifier,
						PackageSource::RELEASE_ASSET,

						$expected_source_revision,
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
				( $this->invalidate_native )( $type );
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
	 * @return bool|ManagedReleaseSubdirectoryNotSupported|null
	 */
	private function mutate_with_updater_lock( callable $mutation ): bool|ManagedReleaseSubdirectoryNotSupported|null {
		try {
			return $this->updater_lock->run( $mutation );
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			return $failure;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function package( string $type, string $identifier ): Package {
		if ( 'plugin' === $type ) {
			return $this->plugins->booster_plugin_from_file( $identifier );
		}
		if ( 'theme' === $type ) {
			return $this->themes->booster_theme_from_stylesheet( $identifier );
		}

		throw new InvalidArgumentException( 'The release tracking package type is invalid.' );
	}

	private function eligibility( string $type, string $identifier, Package $package ): ReleaseTrackingEligibility {
		if ( ! $this->release_source_supported( $package ) ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED );
		}
		$provider_code = $package->get_provider_code();
		if ( null === $provider_code ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER );
		}
		try {
			$metadata       = $this->providers->require_capability( $provider_code, RepositoryReleaseMetadata::class );
			$native_targets = $this->providers->require_capability( $provider_code, RepositoryReleaseNativeTargets::class );
			$expected       = $metadata->expected_update_uri( $package->get_repository()->reference );
		} catch ( UnsupportedProviderCapability | UnknownProvider ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER );
		} catch ( Throwable ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_REPOSITORY );
		}
		$repository = (string) $package->get_repository();
		if ( '' === $expected ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_REPOSITORY );
		}
		if ( 'theme' === $type ) {
			if ( $identifier !== (string) $package->get_slug() ) {
				return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY );
			}
			$package_root = $identifier;
		} else {
			$parts = explode( '/', $identifier );
			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY );
			}
			$package_root = $parts[0];
		}

		if ( $this->metadata_eligibility_overridden && ( $this->metadata_eligible )() ) {
			return $this->eligible_or_self_managed_target( $type, $identifier, $package, $expected, $package_root, $native_targets );
		}
		$update_uri = $this->update_uri( $type, $identifier );
		if ( '' === $update_uri ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::MISSING_UPDATE_URI, $expected, $package_root );
		}
		if ( ! hash_equals( $expected, $update_uri ) || ! ( $this->metadata_eligible )() ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::MISMATCHED_UPDATE_URI, $expected, $package_root );
		}

		return $this->eligible_or_self_managed_target( $type, $identifier, $package, $expected, $package_root, $native_targets );
	}

	private function eligible_or_self_managed_target(
		string $type,
		string $identifier,
		Package $package,
		string $expected_update_uri,
		string $package_root,
		RepositoryReleaseNativeTargets $native_targets
	): ReleaseTrackingEligibility {
		$target_identity = $this->target_identity( $type, $identifier );
		if ( PackageSource::BRANCH === $package->get_source()
			&& ( $this->registrar->has_reserved_core_self_update_target( $type, $target_identity )
				|| $this->has_registered_target( $native_targets, $type, $target_identity ) ) ) {
			return new ReleaseTrackingEligibility(
				ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER,
				$expected_update_uri,
				$package_root
			);
		}

		return new ReleaseTrackingEligibility(
			ReleaseTrackingEligibility::ELIGIBLE,
			$expected_update_uri,
			$package_root
		);
	}

	private function has_registered_target( RepositoryReleaseNativeTargets $native_targets, string $type, string $identity ): bool {
		try {
			return $native_targets->has_registered_native_target( $type, $identity );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function release_source_supported( Package $package ): bool {
		return null === $package->get_subdirectory();
	}

	private function release_source_available( string $type, Package $package ): ?bool {
		try {
			$assessment = $this->source_guard->assess(
				(string) $package->get_provider_code(),
				(string) $package->get_provider_repository_id(),
				'plugin' === $type ? 1 : 2,
				(string) $package->get_identifier(),
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

	private function subdirectory_not_supported(): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed(
			ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED,
			self::SUBDIRECTORY_MESSAGE
		);
	}

	private function target_identity( string $type, string $identifier ): string {
		$identity = strtolower( str_replace( '\\', '/', $identifier ) );

		return 'plugin' === $type ? ltrim( $identity, '/' ) : $identity;
	}

	private function update_uri( string $type, string $identifier ): string {
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
		$data       = get_file_data( $file, array( 'UpdateURI' => 'Update URI' ) );
		$update_uri = is_string( $data['UpdateURI'] ?? null )
			? rtrim( $data['UpdateURI'], '/' )
			: '';

		return $update_uri;
	}

	private function header_file( string $type, string $identifier ): string {
		return 'theme' === $type ? 'style.css' : basename( $identifier );
	}

	private function branch_preflight(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		Package $package,
		ReleaseTrackingEligibility $eligibility,
		bool $allow_public_lookup_profile = true
	): ?ReleaseTrackingPreflight {
		if ( PackageSource::BRANCH !== $package->get_source()
			|| $expected_source_revision !== $package->get_source_revision()
			|| ! $eligibility->eligible() ) {
			return null;
		}

		return $this->provider_preflight(
			$type,
			$package,
			$eligibility->package_root(),
			$this->header_file( $type, $identifier ),
			$channel,
			$allow_public_lookup_profile
		);
	}

	private function provider_preflight(
		string $type,
		Package $package,
		string $package_root,
		string $header_file,
		string $channel,
		bool $allow_public_lookup_profile = true
	): ReleaseTrackingPreflight {
		$provider_code = $package->get_provider_code();
		if ( null === $provider_code ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $package_root, reason_code: 'provider_unavailable' );
		}

		try {
			$this->providers->require_capability( $provider_code, RepositoryReleaseNativeTargets::class );
			$listing      = $this->providers->require_capability( $provider_code, RepositoryReleaseCandidateListing::class );
			$inspector    = $this->providers->require_capability( $provider_code, RepositoryReleaseInspector::class );
			$metadata     = $this->providers->require_capability( $provider_code, RepositoryReleaseMetadata::class );
			$repositories = $allow_public_lookup_profile
				? $this->release_browser_repositories( $package )
				: array( $package->get_repository()->reference );
			foreach ( $repositories as $repository ) {
				try {
					return $this->repository_preflight( $type, $repository, $package_root, $header_file, $channel, $package, $listing, $inspector, $metadata );
				} catch ( RepositoryReleaseReadUnavailable ) {
					continue;
				}
			}

			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $package_root, reason_code: 'provider_unavailable' );
		} catch ( Throwable ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $package_root, reason_code: 'provider_unavailable' );
		}
	}

	private function repository_preflight(
		string $type,
		RepositoryReference $repository,
		string $package_root,
		string $header_file,
		string $channel,
		Package $package,
		RepositoryReleaseCandidateListing $listing,
		RepositoryReleaseInspector $inspector,
		RepositoryReleaseMetadata $metadata
	): ReleaseTrackingPreflight {
		$candidates = $listing->list_release_candidates( $type, $repository, $channel )->candidates;
		if ( array() === $candidates ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $package_root, reason_code: 'no_releases' );
		}

		$inspected = 0;
		foreach ( $candidates as $candidate ) {
			if ( 'stable' === $channel && $candidate->prerelease ) {
				return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $package_root, reason_code: 'invalid_release' );
			}
			++$inspected;

			try {
				$inspection = $inspector->inspect_release(
					$type,
					$repository,

					$candidate->provider_release_id,
					$candidate->tag,
					$channel
				);
			} catch ( RepositoryReleaseInspectionRejected $failure ) {
				if ( RepositoryReleaseInspectionRejected::INCOMPATIBLE === $failure->reason ) {
					if ( 2 === $inspected ) {
						return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $package_root, reason_code: 'release_incompatible' );
					}
					continue;
				}

				return new ReleaseTrackingPreflight(
					RepositoryReleaseInspectionRejected::NO_RELEASES === $failure->reason
						? ReleaseTrackingPreflight::RELEASE_UNAVAILABLE
						: ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
					$package_root,
					reason_code: RepositoryReleaseInspectionRejected::NO_RELEASES === $failure->reason
						? 'no_releases'
						: 'invalid_release'
				);
			}


			if ( ! hash_equals( $candidate->provider_release_id, $inspection->provider_release_id )
				|| ! hash_equals( $candidate->tag, $inspection->tag )
				|| ! hash_equals( $candidate->version, $inspection->version )

				|| ! hash_equals( $package_root, $inspection->package_root )

				|| ! hash_equals( $header_file, $inspection->main_file ) ) {
				return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $package_root, reason_code: 'release_identity_mismatch' );
			}

			$comparison   = version_compare( $inspection->version, $package->get_version() );
			$relationship = match ( true ) {
				$comparison > 0 => 'newer',
				$comparison < 0 => 'older',
				default => 'same',
			};
			$release_url = $metadata->release_details_url( $repository, $inspection->tag );
			if ( '' === $release_url ) {
				throw new InvalidArgumentException( 'The release details URL is unavailable.' );
			}

			return new ReleaseTrackingPreflight(
				ReleaseTrackingPreflight::READY,
				$package_root,
				$inspection->version,
				$release_url,
				$inspection->tag,
				$inspection->version,
				$relationship
			);
		}

		return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $package_root, reason_code: 'release_incompatible' );
	}

	private function configuration_for(
		string $package_root,
		string $header_file,
		string $channel
	): ManagedReleaseConfiguration {
		return new ManagedReleaseConfiguration( $package_root, $header_file, $channel );
	}

	private function valid_channel( string $channel ): bool {
		return in_array( $channel, array( 'stable', 'prerelease' ), true );
	}

	private function authorized(
		string $operation,
		string $type,
		string $identifier,
		int $source_revision,
		string $nonce,
		string $channel = ''
	): bool {
		try {
			return ( $this->can_manage )( $type )
				&& ( $this->verify_nonce )(
					$nonce,
					$this->nonce_action( $operation, $type, $identifier, $source_revision, $channel )
				);
		} catch ( Throwable ) {
			return false;
		}
	}

	private function diagnostic_time( mixed $value ): string {
		return is_int( $value ) && $value > 0 ? gmdate( DATE_ATOM, $value ) : '';
	}

	private function project_candidate_validation(
		string $package_root,
		Package $package,
		?RepositoryReleaseNativeTargetStatus $target_status
	): ?ReleaseTrackingPreflight {

		if ( null === $target_status || '' === $target_status->candidate_code ) {
			return null;
		}


		$code = match ( $target_status->candidate_code ) {
			'release_identity_verified' => ReleaseTrackingPreflight::READY,
			'release_version_mismatch' => ReleaseTrackingPreflight::RELEASE_VERSION_MISMATCH,
			'package_header_missing' => ReleaseTrackingPreflight::RELEASE_HEADER_MISSING,
			'package_header_invalid' => ReleaseTrackingPreflight::RELEASE_HEADER_INVALID,
			'package_archive_unreadable' => ReleaseTrackingPreflight::RELEASE_ARCHIVE_UNREADABLE,
			'github_updater_release_incompatible' => ReleaseTrackingPreflight::RELEASE_UNAVAILABLE,
			default => ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
		};

		$reason_code = match ( $target_status->candidate_code ) {
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

			'package_header_ambiguous' => $target_status->candidate_code,
			'github_updater_release_incompatible' => 'release_incompatible',
			default => 'invalid_release',
		};

		try {
			return new ReleaseTrackingPreflight(
				$code,
				$package_root,

				$target_status->candidate_release_version,

				$this->release_url( $package, $target_status->candidate_release_tag ),

				$target_status->candidate_release_tag,

				$target_status->candidate_package_header_version,

				'' !== $target_status->version_relationship ? $target_status->version_relationship : 'invalid',
				$reason_code
			);
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	private function release_url( Package $package, string $tag ): string {
		$provider_code = $package->get_provider_code();
		if ( null === $provider_code ) {
			return '';
		}
		try {
			$metadata = $this->providers->require_capability( $provider_code, RepositoryReleaseMetadata::class );

			return $metadata->release_details_url( $package->get_repository()->reference, $tag );
		} catch ( Throwable ) {
			return '';
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use RAN\UpdaterSupport\V1\PackageDirectory;
use InvalidArgumentException;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\PackageMutationGuard;
use RAN\Deployment\PackageSourceGate;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
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
	/**
	 * Provider-supplied rejection reasons Core has specific copy for. A reason without copy would show the
	 * generic text, which is less helpful than the listing-derived reason, so it is not accepted yet.
	 */
	private const EXPLAINED_PROVIDER_REASONS = array( 'package_asset_missing', 'package_asset_ambiguous', 'release_incompatible' );

	private const SOURCE_CHANGED_MESSAGE = 'Package settings changed after this browser page was opened. Refresh this browser page, review the current settings, then try again.';
	private const SUBDIRECTORY_MESSAGE   = 'Published releases require this plugin or theme to be at the repository root. Return to Branch to keep using its configured repository subdirectory.';

	/** @var \Closure(string): bool */
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/** @var \Closure(string): void */
	private \Closure $refresh_native;

	/** @var \Closure(): bool */
	private \Closure $metadata_eligible;
	private bool $metadata_eligibility_overridden;

	/** @var \Closure(string): void */
	private \Closure $invalidate_native;

	/** @var \Closure(string): ?string */
	private \Closure $public_lookup_profile;
	private WordPressUpdaterLock $updater_lock;
	private RepositorySourceGuard $source_guard;
	private ?string $anonymous_rate_limit_provider = null;

	/** @var \Closure(string, string): mixed */
	private \Closure $check_without_offer;

	/** The default read-only check is throttled; an injected one is not. */
	private bool $check_cooldown;

	/** @var \Closure(string, string, string, string, bool, string): string */
	private \Closure $switch_install;

	/**
	 * @param callable(string): bool|null         $can_manage
	 * @param callable(string, string): bool|null $verify_nonce
	 * @param callable(string): void|null         $refresh_native
	 * @param callable(): bool|null $metadata_eligible
	 * @param callable(string): void|null         $invalidate_native
	 * @param callable(string): ?string|null      $public_lookup_profile
	 * @param callable(string, string): mixed|null $check_without_offer
	 * @param callable(string, string, string, string, bool, string): string|null $switch_install Installs one exact release.
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
		?RepositorySourceGuard $source_guard = null,
		?callable $check_without_offer = null,
		?callable $switch_install = null,
		private ?GitHubProviderSelection $github_selection = null
	) {
		$this->switch_install = null === $switch_install ? \Closure::fromCallable( array( self::class, 'install_release' ) ) : \Closure::fromCallable( $switch_install );

		$this->updater_lock = $updater_lock;

		// One completed read-only check per package a minute: each call does full discovery and keeps no cache.
		$this->check_cooldown      = null === $check_without_offer;
		$this->check_without_offer = null === $check_without_offer
			? static function ( string $type, string $identifier ): mixed {
				if ( false !== get_transient( self::check_cooldown_key( $type, $identifier ) ) ) {
					return array(
						'status'  => 'skipped',
						'reason'  => 'cooldown_active',
						'offered' => false,
					);
				}
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The shared release updater owns this documented read-only check filter.
				return apply_filters( 'ran_wp_release_updater_check_releases_v1', null, $type, $identifier );
			}

			: \Closure::fromCallable( $check_without_offer );

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
		return $this->nonce_scope( $operation, $type, $identifier, $source_revision, $channel, $this->package( $type, $identifier ) );
	}

	private function nonce_scope( string $operation, string $type, string $identifier, int $source_revision, string $channel, Package $package ): string {
		$token     = \RAN\AdoptionIdentity::of( $package ) ?? throw new InvalidArgumentException( 'The adoption identity is unavailable.' );
		$preflight = in_array( $operation, array( 'preflight', 'assessment_preflight', 'list_candidates', 'inspect_candidate' ), true );
		if ( ! in_array( $operation, array( 'preflight', 'assessment_preflight', 'list_candidates', 'inspect_candidate', 'enable', 'refresh', 'change_channel', 'return_to_branch' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $identifier

			|| $source_revision < 1
			|| ( $preflight && ! $this->valid_channel( $channel ) )
			|| ( ! $preflight && '' !== $channel ) ) {
			throw new InvalidArgumentException( 'The release tracking nonce scope is invalid.' );
		}

		$action = 'ran-booster-release-tracking-' . $operation . '-' . $type . '-' . $identifier . '-' . $source_revision . '-' . $token;

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
		$incompatible  = in_array( $eligibility->code(), array( ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED, ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY ), true );
		$configuration = null;
		$failure_code  = $incompatible ? $eligibility->code() : '';
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

		$latest_version = $target_status->offered_version ?? '';
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

		$native_relationship  = $target_status->version_relationship ?? '';
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
			$target_status->candidate_provider_release_id ?? ''
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

	/**
	 * Check an in-memory adoption target using the same native release gates as
	 * the management UI. This is deliberately concrete: Transporter must not
	 * widen the add-on interface with a package-mutation capability.
	 */
	public function release_adoption_ready(
		string $type,
		Package $package,
		ManagedReleaseConfiguration $configuration
	): bool {
		try {
			$identifier  = (string) $package->get_identifier();
			$eligibility = $this->eligibility( $type, $identifier, $package );
			if ( ! $eligibility->eligible()
				|| true !== $this->release_source_available( $type, $package ) ) {
				return false;
			}
			// Do not use the ordinary helper here: an unavailable native-target
			// probe must never be treated as an available target during adoption.
			$targets = $this->providers->require_capability(
				(string) $package->get_provider_code(),
				RepositoryReleaseNativeTargets::class
			);
			if ( $this->registrar->has_reserved_core_self_update_target( $type, $this->target_identity( $type, $identifier ) )
				|| $targets->has_registered_native_target( $type, $this->target_identity( $type, $identifier ) ) ) {
				return false;
			}
			$preflight = $this->provider_preflight(
				$type,
				$package,
				$configuration->package_root(),
				$configuration->metadata_file(),
				$configuration->channel(),
				false
			);

			return $preflight->ready();
		} catch ( Throwable ) {
			return false;
		}
	}


	public function preflight(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce
	): ?ReleaseTrackingPreflight {
		$package = $this->authorized( 'preflight', $type, $identifier, $expected_source_revision, $nonce, $channel );
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| null === $package ) {
			return null;
		}

		try {

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
		$package = $this->authorized( 'assessment_preflight', $type, $identifier, $expected_source_revision, $nonce, $channel );
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| null === $package ) {
			return null;
		}

		try {
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
		$this->anonymous_rate_limit_provider = null;
		$package                             = $this->authorized( 'list_candidates', $type, $identifier, $expected_source_revision, $nonce, $channel );
		if ( ! RuntimeSupport::current()->allows_managed_operations()
			|| ! $this->valid_channel( $channel )

			|| null === $package ) {
			return null;
		}

		try {

			$package = $this->managed_package_for_browser( $package, $expected_source_revision );
			if ( null === $package ) {
				return null;
			}
			$listing = $this->providers->require_capability( (string) $package->get_provider_code(), RepositoryReleaseCandidateListing::class );

			foreach ( $this->release_browser_repositories( $package ) as $repository ) {
				try {
					return $listing->list_release_candidates( $type, $repository, $channel );
				} catch ( RepositoryReleaseReadUnavailable $unavailable ) {
					BoosterLogger::log_provider_failure(
						'release read failed',
						$unavailable,
						array(
							'operation' => 'list_release_candidates',
							'provider'  => (string) $package->get_provider_code(),
						)
					);
					if ( ! $repository->private
						&& null === $repository->credential_id
						&& in_array( $unavailable->failure?->reason, array( 'rate_limited', 'rate_limited_secondary' ), true ) ) {
						$this->anonymous_rate_limit_provider = (string) $package->get_provider_code();
					}
					continue;
				}
			}

			return null;
		} catch ( Throwable ) {
			return null;
		}
	}


	/** The provider whose anonymous public lookup was rate-limited in the last candidate listing, if any. */
	public function anonymous_rate_limit_provider(): ?string {
		return $this->anonymous_rate_limit_provider;
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
		$package = $this->authorized( 'inspect_candidate', $type, $identifier, $expected_source_revision, $nonce, $channel );
		if ( ! RuntimeSupport::current()->allows_managed_operations()

			|| '' === $release_id
			|| '' === $tag
			|| ! $this->valid_channel( $channel )

			|| null === $package ) {
			return null;
		}

		try {

			$package = $this->managed_package_for_browser( $package, $expected_source_revision );
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
				} catch ( RepositoryReleaseReadUnavailable $unavailable ) {
					BoosterLogger::log_provider_failure(
						'release read failed',
						$unavailable,
						array(
							'operation' => 'inspect_release',
							'provider'  => (string) $package->get_provider_code(),
						)
					);
					continue;
				} catch ( RepositoryReleaseInspectionRejected $rejection ) {
					return RepositoryReleaseInspectionRejected::INCOMPATIBLE === $rejection->reason && 'digest_missing' === $rejection->detail
						? new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $configuration->package_root(), reason_code: 'digest_missing' ) : null;
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
			return new ReleaseTrackingPreflight(
				ReleaseTrackingPreflight::READY,
				$inspection->package_root,
				$inspection->version,
				$url,
				$inspection->tag,
				$inspection->version
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	private function managed_package_for_browser( Package $package, int $revision ): ?Package {
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

	/** @param array{identity:string,tag:string,version:string}|null $confirmed_release Core confirmation, bound by the admin nonce. */
	public function enable(
		string $type,
		string $identifier,
		int $expected_source_revision,
		string $channel,
		string $nonce,
		string $confirmed_older_release_identity = '',
		string $confirmed_older_release_tag = '',
		?array $confirmed_release = null
	): ReleaseTrackingResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ReleaseTrackingResult::failed(
				UnsupportedRuntimeException::ERROR_CODE,
				'Release tracking is unavailable on WordPress Multisite.'
			);
		}

		$package = $this->authorized( 'enable', $type, $identifier, $expected_source_revision, $nonce );
		if ( ! $this->valid_channel( $channel )

			|| null === $package ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be enabled.' );
		}
		if ( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, $channel, fresh: true ) ) {
			return self::source_paused();
		}
		if ( PackageMutationGuard::file_mods_disabled() ) {
			$this->schedule_native_recheck( $type );

			return ReleaseTrackingResult::failed( 'policy_forced_off', 'Updates are turned off by this site configuration.' );
		}
		if ( DeploymentPolicy::DISABLED === $package->get_deployment_policy() ) {
			$this->schedule_native_recheck( $type );

			return ReleaseTrackingResult::failed( 'policy_disabled', 'Updates are disabled for this package.' );
		}
		if ( $this->github_hold_blocks_package( $package ) ) {
			$this->schedule_native_recheck( $type );

			return ReleaseTrackingResult::failed( 'native_hold', 'GitHub installs are currently on hold.' );
		}
		try {
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
			if ( PackageSource::BRANCH !== $package->get_source() || ! $eligibility->eligible() ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$package_root  = $eligibility->package_root();
			$header_file   = $this->header_file( $type, $identifier );
			$configuration = $this->configuration_for(
				$package_root,
				$header_file,
				$channel
			);
			$live          = $this->package( $type, $identifier );
			if ( null === \RAN\AdoptionIdentity::of( $live ) || \RAN\AdoptionIdentity::of( $live ) !== \RAN\AdoptionIdentity::of( $package ) || $expected_source_revision !== $live->get_source_revision() ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			if ( ! $this->registrar->admit_switch( $type, $live, $configuration, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) ) {
				return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
			}

			$installed = 'unsupported';
			try {
				// Existing facade callers can still ask Core to select a release. A Core
				// form already supplies the release the operator chose.
				if ( null === $confirmed_release ) {
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
							'Published release assets could not be validated.',
							$preflight->reason_code(),
							$preflight->detail_text()
						);
					}
					$confirmed_release = array(
						'identity' => $preflight->release_identity(),
						'tag'      => $preflight->release_tag(),
						'version'  => $preflight->latest_version(),
					);
				}
				$allow_older = '' !== $confirmed_older_release_identity || '' !== $confirmed_older_release_tag;
				if ( $allow_older && ( ! hash_equals( $confirmed_older_release_identity, $confirmed_release['identity'] )
					|| ! hash_equals( $confirmed_older_release_tag, $confirmed_release['tag'] ) ) ) {
					return ReleaseTrackingResult::failed( 'release_older', 'The eligible release changed after older-release confirmation.' );
				}
				try {
					$installed = ( $this->switch_install )( $type, $identifier, $confirmed_release['identity'], $confirmed_release['tag'], $allow_older, $confirmed_release['version'] );
				} catch ( Throwable ) {
					// Pending native completion owns any recovery after an interrupted upgrade.
					$installed = 'uncertain';
				}
			} finally {
				if ( 'installed' !== $installed ) {
					if ( 'uncertain' !== $installed ) {
						$this->registrar->withdraw_switch( $type, $identifier );
					}
					$this->schedule_native_recheck( $type );
				}
			}
			$code = match ( $installed ) {
				'installed' => 'release_enabled',
				'failed' => 'wordpress_failed',
				'uncertain' => 'management_state_uncertain',
				'unsupported', 'runtime_busy', 'filesystem_unavailable', 'discovery_not_admitted', 'offer_token_unavailable', 'runtime_liveness_lost' => 'service_unavailable',
				'release_older' => 'release_older',
				'candidate_descriptor_mismatch' => 'release_selection_changed',
				'policy_disabled' => 'policy_disabled',
				'policy_forced_off' => 'policy_forced_off',
				default => 'release_unavailable',
			};
			BoosterLogger::log(
				'release switch',
				array(
					'event'        => 'release_switch',
					'outcome_code' => $code,
					'reason'       => $installed,
				)
			);

			return 'release_enabled' === $code
				? ReleaseTrackingResult::succeeded( $code, 'Release tracking enabled' )
				: ReleaseTrackingResult::failed( $code, 'Release tracking could not be enabled.' );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.' );
		}
	}

	private function schedule_native_recheck( string $type ): void {
		add_action( 'shutdown', fn () => ( $this->invalidate_native )( $type ), PHP_INT_MAX, 0 );
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

		$package = $this->authorized( 'change_channel', $type, $identifier, $expected_source_revision, $nonce );
		if ( ! $this->valid_channel( $channel )

			|| null === $package ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The release track could not be changed.' );
		}
		if ( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, $channel, fresh: true ) ) {
			return self::source_paused();
		}
		try {
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
				$type,
				$identifier,
				function () use ( $type, $identifier, $expected_source_revision, $channel, $package, &$incompatible ): bool {
					$token   = \RAN\AdoptionIdentity::of( $package );
					$current = $this->package( $type, $identifier );
					if ( null === $token || \RAN\AdoptionIdentity::of( $current ) !== $token ) {
						return false;
					}
					if ( ! $this->release_source_supported( $current ) ) {
						$incompatible = true;

						return false;
					}
					$changed = $this->store->change_channel(
						$type,
						$identifier,
						$expected_source_revision,
						$channel,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
						$token
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

		$package = $this->authorized( 'refresh', $type, $identifier, $expected_source_revision, $nonce );
		if ( null === $package ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'Release tracking could not be refreshed.' );
		}
		try {
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
				|| null === $configuration ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$target = $this->registrar->target( $type, $identifier );
			if ( null === $target ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$this->registrar->forget_check_outcome( $type, $identifier );
			$this->registrar->mark_manual_check( $type, $identifier );
			// Answered before the target refresh: that refresh clears WordPress's whole cached update check.
			if ( DeploymentPolicy::DISABLED === $package->get_deployment_policy() ) {
				$answer = $this->answer_without_offer( $type, $identifier );
				if ( null !== $answer ) {
					return $answer;
				}
			}
			if ( ! $target->refresh() ) {
				return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
			}
			( $this->refresh_native )( $type );
			$check = $this->registrar->check_outcome( $type, $identifier );
			if ( null !== $check && 'succeeded' !== $check['status'] ) {
				return ReleaseTrackingResult::failed(
					match ( $check['reason'] ) {
						'policy_disabled' => 'refresh_skipped_disabled',
						'policy_forced_off' => 'refresh_skipped_forced_off',
						default => match ( $check['status'] ) {
							'skipped' => 'refresh_unchecked',
							'rejected' => 'refresh_rejected',
							default => 'refresh_check_failed',
						},
					},
					'Published release information was not checked.',
					check_reason: $check['reason']
				);
			}

			return ReleaseTrackingResult::succeeded( 'release_refreshed', 'Published release information was refreshed.' );
		} catch ( Throwable ) {
			return ReleaseTrackingResult::failed( 'refresh_failed', 'Published release information could not be refreshed.' );
		}
	}


	/**
	 * Updates are off: ask the updater for a result that is never an offer. Null means it did not
	 * answer (an older runtime), which is unsupported and never up to date.
	 */
	private function answer_without_offer( string $type, string $identifier ): ?ReleaseTrackingResult {
		$report = ( $this->check_without_offer )( $type, $identifier );
		$status = is_array( $report ) ? ( $report['status'] ?? null ) : null;
		$reason = is_array( $report ) ? ( $report['reason'] ?? null ) : null;
		if ( ! is_string( $status ) || ! is_string( $reason ) || false !== ( $report['offered'] ?? null )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $reason ) ) {
			return null;
		}
		// A claimed result counts only when the observation the updater emitted says the same.
		$observed = $this->registrar->check_outcome( $type, $identifier );
		$claimed  = 'succeeded' === $status && in_array( $reason, array( 'newer_release_observed', 'no_newer_candidate', 'no_candidates' ), true );
		if ( $claimed && ( null === $observed || $observed['status'] !== $status || $observed['reason'] !== $reason ) ) {
			$status = 'skipped';
			$reason = 'check_not_observed';
		}
		$version = is_string( $report['candidate_version'] ?? null ) ? ReleaseTrackingPreflight::detail( explode( '+', $report['candidate_version'], 2 )[0] ) : '';
		$code    = match ( true ) {
			'cooldown_active' === $reason => 'refresh_recent',
			'policy_forced_off' === $reason => 'refresh_skipped_forced_off',
			'policy_disabled' === $reason => 'refresh_skipped_disabled',
			'succeeded' === $status && 'newer_release_observed' === $reason => 'refresh_newer_while_off',
			'succeeded' === $status && in_array( $reason, array( 'no_newer_candidate', 'no_candidates' ), true ) => 'refresh_current_while_off',
			'rejected' === $status => 'refresh_rejected',
			'failed' === $status => 'refresh_check_failed',
			default => 'refresh_unchecked',
		};
		// Only a completed check starts the cooldown; any other answer can be retried at once.
		if ( $this->check_cooldown && in_array( $code, array( 'refresh_newer_while_off', 'refresh_current_while_off', 'refresh_rejected' ), true ) ) {
			set_transient( self::check_cooldown_key( $type, $identifier ), 1, MINUTE_IN_SECONDS );
		}

		return ReleaseTrackingResult::failed(
			$code,
			'Published release information was not offered.',
			detail: 'refresh_newer_while_off' === $code ? $version : '',
			check_reason: $reason
		);
	}

	private static function check_cooldown_key( string $type, string $identifier ): string {
		return 'ran_booster_check_off_' . md5( $type . "\0" . $identifier );
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

		$package = $this->authorized( 'return_to_branch', $type, $identifier, $expected_source_revision, $nonce );
		if ( null === $package ) {
			return ReleaseTrackingResult::failed( 'forbidden', 'The package source could not be changed.' );
		}
		if ( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: true ) ) {
			return self::source_paused();
		}
		try {
			if ( PackageSource::RELEASE_ASSET !== $package->get_source()

				|| $expected_source_revision !== $package->get_source_revision() ) {
				return ReleaseTrackingResult::failed( 'source_changed', self::SOURCE_CHANGED_MESSAGE );
			}
			$changed = $this->mutate_with_updater_lock(
				$type,
				$identifier,
				function () use ( $type, $identifier, $expected_source_revision, $package ): bool {
					$token = \RAN\AdoptionIdentity::of( $package );
					if ( null === $token || \RAN\AdoptionIdentity::of( $this->package( $type, $identifier ) ) !== $token ) {
						return false;
					}
					return $this->store->transition(
						$type,
						$identifier,
						PackageSource::RELEASE_ASSET,
						$expected_source_revision,
						PackageSource::BRANCH,
						null,
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
						$token
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
	private function mutate_with_updater_lock( string $type, string $identifier, callable $mutation ): bool|ManagedReleaseSubdirectoryNotSupported|null {
		if ( null === $this->github_selection ) {
			try {
				return $this->updater_lock->run( $mutation );
			} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
				return $failure;
			} catch ( Throwable ) {
				return null;
			}
		}

		try {
			$token = $this->updater_lock->try_acquire_without_reclaim();
		} catch ( Throwable ) {
			return null;
		}
		if ( null === $token ) {
			return null;
		}

		try {
			if ( $this->updater_lock->current_token() !== $token
				|| $this->github_selection->native_mutation_blocks_target( $type, $identifier )
				|| $this->updater_lock->current_token() !== $token ) {
				return null;
			}

			return $mutation();
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			return $failure;
		} catch ( Throwable ) {
			return null;
		} finally {
			try {
				if ( ! $this->updater_lock->release( $token ) ) {
					BoosterLogger::log( 'Managed release mutation lock could not be released.' );
				}
			} catch ( Throwable $failure ) {
				BoosterLogger::log_exception( 'Managed release mutation lock release failed.', $failure );
			}
		}
	}

	/** Run the published exact-install operation with the selected listing entry. */
	private static function install_release( string $type, string $identifier, string $release_identity, string $tag, bool $allow_older, string $version ): string {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The shared release updater owns this documented exact-install filter.
		$report = apply_filters( 'ran_wp_release_updater_install_exact_release_v1', null, $type, $identifier, $release_identity, $tag, $version, $allow_older );
		if ( ! is_array( $report ) || ! is_string( $report['code'] ?? null ) ) {
			return 'unsupported';
		}
		if ( 'installed' === ( $report['status'] ?? null ) && 'update_completed' === $report['code'] ) {
			$finalization = $report['finalization'] ?? null;

			return false === ( $report['finalization_pending'] ?? null )
				&& is_array( $finalization )
				&& 'completed' === ( $finalization['commit'] ?? null )
				&& 'completed' === ( $finalization['host_completion'] ?? null ) ? 'installed' : 'uncertain';
		}
		if ( true === ( $report['finalization_pending'] ?? null )
			|| in_array( $report['code'], array( 'host_completion_failed', 'install_commit_failed', 'lease_release_failed', 'archive_cleanup_failed', 'unexpected_failure', 'unverified_install_result' ), true ) ) {
			return 'uncertain';
		}
		if ( 'failed' === ( $report['status'] ?? null ) ) {
			return 'failed';
		}

		return 'refused' === ( $report['status'] ?? null ) && ! in_array( $report['code'], array( 'installed', 'failed', 'uncertain' ), true ) ? $report['code'] : 'unsupported';
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
		$parts = explode( '/', $identifier );
		if ( $identifier !== (string) $package->get_identifier()
			|| ( 'plugin' === $type && ( 2 !== count( $parts ) || strlen( $identifier ) > 255 || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $parts[1] ) ) )
			|| ( 'theme' === $type && 1 !== count( $parts ) )
			|| ! PackageDirectory::is_valid_name( $parts[0] ) ) {
			return new ReleaseTrackingEligibility( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY );
		}
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

	private static function source_paused(): ReleaseTrackingResult {
		BoosterLogger::log(
			'release operation refused',
			array(
				'event'        => 'release_operation_refused',
				'outcome_code' => DeploymentOutcome::CODE_SOURCE_PAUSED,
			)
		);

		return ReleaseTrackingResult::failed( DeploymentOutcome::CODE_SOURCE_PAUSED, 'Updates are paused by a site setting.' );
	}

	private function subdirectory_not_supported(): ReleaseTrackingResult {
		return ReleaseTrackingResult::failed(
			ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED,
			self::SUBDIRECTORY_MESSAGE
		);
	}

	private function target_identity( string $type, string $identifier ): string {
		// A full identifier keeps its separator handling, then folds whole: the documented collision_key() exception.
		$identity = PackageDirectory::collision_key( str_replace( '\\', '/', $identifier ) );

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

	private function github_hold_blocks_package( Package $package ): bool {
		if ( null === $this->github_selection ) {
			return false;
		}
		$status = $this->github_selection->status();
		if ( 'recovery_required' !== ( $status['state'] ?? null ) ) {
			return false;
		}
		$expectation = $this->github_selection->expectation();
		$provider    = is_array( $expectation ) ? ( $expectation['provider_code'] ?? null ) : null;
		if ( ! is_string( $provider ) || '' === $provider ) {
			return true;
		}

		return $package->get_provider_code() === $provider;
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
				} catch ( RepositoryReleaseReadUnavailable $unavailable ) {
					BoosterLogger::log_provider_failure(
						'release read failed',
						$unavailable,
						array(
							'operation' => 'release_preflight',
							'provider'  => (string) $package->get_provider_code(),
						)
					);
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
		$newest    = null;
		$detail    = null;
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
					// Look past the newest release once, then stop. The newest release is the one named,
					// even when the older one failed differently: it is the release the maintainer last published.
					if ( null === $newest ) {
						$newest = $candidate;
						// The provider's failure reason wins over its legacy detail.
						$detail = null !== $failure->failure && in_array( $failure->failure->reason, ReleaseTrackingPreflight::reason_codes(), true )
							? $failure->failure->reason
							: $failure->detail;
					}
					if ( 2 === $inspected ) {
						return $this->rejected_release_preflight( $package_root, $newest, $detail );
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
				$found = $inspection->package_root . '/' . $inspection->main_file;

				return new ReleaseTrackingPreflight(
					ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
					$package_root,
					reason_code: 'release_identity_mismatch',
					// Name the ZIP's layout only when that is what differs, not a listing-versus-inspection mismatch.
					detail: hash_equals( $package_root, $inspection->package_root ) && hash_equals( $header_file, $inspection->main_file ) ? '' : ReleaseTrackingPreflight::detail( $found )
				);
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
				$relationship,
				reason_code: null === $newest ? '' : (
					in_array( $detail, ReleaseTrackingPreflight::reason_codes(), true )
						? $detail : $this->rejected_release_preflight( $package_root, $newest, $detail )->reason_code()
				),
				detail: $newest->version ?? '',
				release_identity: $inspection->provider_release_id
			);
		}

		return $this->rejected_release_preflight( $package_root, $newest, $detail );
	}

	/**
	 * The provider's own reason for the newest rejection wins when Core knows it. Otherwise the listing's
	 * ZIP count chooses the wording, after inspection has rejected the release. One ZIP means the cause is
	 * inside the archive, which only the provider can name.
	 */
	private function rejected_release_preflight( string $package_root, ?RepositoryReleaseCandidate $newest, ?string $provider_reason ): ReleaseTrackingPreflight {
		$zips = null === $newest ? 1 : count( $newest->expected_asset_names );

		return new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::RELEASE_UNAVAILABLE,
			$package_root,
			reason_code: match ( true ) {
				'digest_missing' === $provider_reason => 'digest_missing',
				null !== $provider_reason && in_array( $provider_reason, self::EXPLAINED_PROVIDER_REASONS, true ) => $provider_reason,
				0 === $zips => 'package_asset_missing',
				$zips > 1 => 'package_asset_ambiguous',
				default => 'release_incompatible',
			},
			detail: null === $newest ? '' : ReleaseTrackingPreflight::detail( $newest->tag )
		);
	}

	private function configuration_for(
		string $package_root,
		string $header_file,
		string $channel
	): ManagedReleaseConfiguration {
		return new ManagedReleaseConfiguration( $package_root, $header_file, $channel );
	}

	/** @phpstan-assert-if-true 'stable'|'prerelease' $channel */
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
	): ?Package {
		try {
			if ( ! RuntimeSupport::current()->allows_managed_operations() || ! ( $this->can_manage )( $type ) ) {
				return null;
			}
			$package = $this->package( $type, $identifier );
			return ( $this->verify_nonce )(
				$nonce,
				$this->nonce_scope( $operation, $type, $identifier, $source_revision, $channel, $package )
			) ? $package : null;
		} catch ( Throwable ) {
			return null;
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

		// The provider reports a candidate that is not newer than the installed version with the generic incompatible code. That is a completed check with nothing to offer, not a rejected release.
		if ( 'github_updater_release_incompatible' === $target_status->candidate_code
			&& in_array( $target_status->version_relationship, array( 'same', 'older' ), true ) ) {
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

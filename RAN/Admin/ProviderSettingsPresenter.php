<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryPathInspector;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use Throwable;

/**
 * Builds a display-only provider settings payload.
 *
 * Secret-bearing records are deliberately never requested from the sidecar.
 */
final readonly class ProviderSettingsPresenter {
	private const MAX_REPOSITORY_PACKAGE_SUMMARIES = 20;

	private PublicRepositoryLookupProfileStore $public_lookup_profiles;
	private CredentialExpiryObservationStore $expiry_observations;
	private CredentialExpiryReminder $expiry_reminders;
	private RepositoryBranchCheckEvidenceStore $branch_check_evidence;
	private WordPressUpdaterLock $branch_check_lock;

	public function __construct(
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private CredentialUsageReader $credential_usage,
		?PublicRepositoryLookupProfileStore $public_lookup_profiles = null,
		?CredentialExpiryObservationStore $expiry_observations = null,
		?CredentialExpiryReminder $expiry_reminders = null,
		private ?PluginRepository $plugins = null,
		private ?ThemeRepository $themes = null,
		private ?WebhookAssistanceReadinessEvaluator $webhook_assistance = null,
		?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null,
		?WordPressUpdaterLock $branch_check_lock = null
	) {

		$this->public_lookup_profiles = $public_lookup_profiles ?? new PublicRepositoryLookupProfileStore();

		$this->expiry_observations = $expiry_observations ?? new CredentialExpiryObservationStore();

		$this->expiry_reminders = $expiry_reminders ?? new CredentialExpiryReminder(
			$this->providers,
			$this->secrets,
			$this->expiry_observations
		);

		$this->branch_check_evidence = $branch_check_evidence ?? new RepositoryBranchCheckEvidenceStore();

		$this->branch_check_lock = $branch_check_lock ?? new WordPressUpdaterLock();
	}

	/**
	 * @return array<string, mixed>
	 */

	public function build( ?string $selected_provider = null ): array {
		$available = $this->admin_providers();
		if ( array() === $available ) {
			throw new RuntimeException( 'No repository provider settings are available.' );
		}

		$selected_provider = is_string( $selected_provider ) ? trim( $selected_provider ) : '';

		if ( ! isset( $available[ $selected_provider ] ) ) {

			$selected_provider = array_key_first( $available );
		}

		$selected = $available[ $selected_provider ];
		$metadata = $selected->get_metadata();
		$admin    = $metadata->admin;

		if ( null === $admin ) {
			throw new RuntimeException( 'Repository provider settings metadata is unavailable.' );
		}

		$managed_repositories = $this->managed_repositories( $selected_provider, $selected, true );

		$provider_repositories = $this->managed_repositories( $selected_provider, $selected, false );

		$webhook_readiness   = $this->webhook_assistance_readiness( $selected_provider, $selected );
		$storage_unavailable = false;
		try {

			$credentials = $this->credential_profiles( $selected_provider, $admin, true );

			$webhooks              = $this->webhook_profiles( $selected_provider, $admin, $managed_repositories );
			$provider_repositories = $this->with_retained_webhook_evidence(
				$provider_repositories,
				$managed_repositories,
				$selected_provider
			);

			$public_lookup_profile = $this->public_lookup_profile( $selected, $selected_provider, $credentials );
			if ( null !== $public_lookup_profile ) {
				$configured_id = $public_lookup_profile['configured_id'];
				$credentials   = array_map(
					static fn ( array $profile ): array => $profile + array(
						'public_lookup_default' => $configured_id === $profile['id'],
					),
					$credentials
				);
			}
		} catch ( SecretsStorageUnavailable ) {
			$credentials           = array();
			$webhooks              = array();
			$public_lookup_profile = null;
			$storage_unavailable   = true;
		}

		return array(

			'selected_provider'            => $selected_provider,

			'providers'                    => $this->tabs( $available, $selected_provider ),
			'provider'                     => $this->provider( $selected, $metadata, $admin ),
			'credential_profiles'          => $credentials,
			'webhook_profiles'             => $webhooks,
			'managed_webhook_repositories' => $managed_repositories,
			'provider_repositories'        => $provider_repositories,
			'public_lookup_profile'        => $public_lookup_profile,
			'secrets_storage_unavailable'  => $storage_unavailable,
			'webhook_assistance_readiness' => $webhook_readiness,
		);
	}

	/** @return array<string, mixed>|null */
	private function webhook_assistance_readiness( string $provider_code, RepositoryProvider $provider ): ?array {

		if ( null === $this->webhook_assistance || ! $provider instanceof WebhookNormalizer ) {
			return null;
		}

		try {
			$endpoint = rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $provider_code ) );

			return $this->webhook_assistance->evaluate( $provider_code, $endpoint )->to_array();
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Build provider and credential choices for package forms and the picker.
	 *
	 * @return array{default_provider: string, providers: list<array<string, mixed>>}
	 */

	public function build_package_form( ?string $default_provider = null ): array {
		$providers = $this->package_providers();

		$package_provider_codes = array_column(
			array_filter( $providers, static fn ( array $provider ): bool => true === $provider['deploy'] ),
			'code'
		);
		if ( array() === $package_provider_codes ) {
			throw new RuntimeException( 'No repository provider can install packages.' );
		}

		if ( ! is_string( $default_provider ) || ! in_array( $default_provider, $package_provider_codes, true ) ) {

			$default_provider = $package_provider_codes[0];
		}

		return array(

			'default_provider' => $default_provider,
			'providers'        => $providers,
		);
	}

	/**
	 * Build package controls without replacing an existing package's provider.
	 *
	 * An unavailable provider is represented as inert display data so the saved
	 * identity remains visible and can become operational again if that provider
	 * is registered later.
	 *
	 * @return array{default_provider: string, providers: list<array<string, mixed>>}
	 */

	public function build_existing_package_form( string $stored_provider ): array {
		$providers = $this->package_providers();
		$codes     = array_column( $providers, 'code' );

		if ( ! in_array( $stored_provider, $codes, true ) ) {
			$providers[] = array(

				'code'                                   => $stored_provider,

				'label'                                  => $stored_provider,
				'owner_label'                            => '',
				'repository_url_base'                    => '',
				'credentials_url'                        => '',
				'available'                              => false,
				'browse'                                 => false,
				'credentialed_public_browse'             => false,
				'provider_default_public_lookup_profile' => false,
				'deploy'                                 => false,
				'webhooks'                               => false,
				'default_credential_id'                  => '',
				'credential_profiles'                    => array(),
				'public_lookup'                          => null,
			);
		}

		return array(

			'default_provider' => $stored_provider,
			'providers'        => $providers,
		);
	}

	/**
	 * Build display-only branch readiness for one managed package.
	 *
	 * This proves only Booster's local receiver, saved repository identity and
	 * local secret coverage. A remote provider webhook is deliberately not
	 * inferred from this projection.
	 *
	 * @return array{
	 *     provider_code: string,
	 *     retained: bool,
	 *     site: array{status: string, reason_codes: list<string>, callback_url: string},
	 *     repository: array<string, mixed>|null,
	 *     webhook_settings_url: string
	 * }|null
	 */
	public function build_package_branch_readiness( Package $package ): ?array {

		if ( ! in_array( $package->get_source(), array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET ), true ) || null === $this->webhook_assistance ) {
			return null;
		}

		$provider_code = (string) ( $package->get_provider_code() ?? '' );
		if ( '' === $provider_code ) {
			return null;
		}

		try {
			$provider = $this->providers->get( $provider_code );
			if ( ! $provider instanceof WebhookNormalizer ) {
				return null;
			}
			$readiness = $this->webhook_assistance_readiness( $provider_code, $provider );
			if ( null === $readiness ) {
				return null;
			}
			if ( PackageSource::RELEASE_ASSET === $package->get_source() ) {
				$retention = $this->build_package_webhook_retention( $package );
				if ( null === $retention ) {
					return null;
				}

				return array(
					'provider_code'        => $provider_code,
					'retained'             => true,
					'site'                 => $readiness['site'],
					'repository'           => array(
						'repository_id'         => $retention['repository_id'],
						'repository'            => $retention['repository'],
						'reason_codes'          => array(),
						'local_secret_coverage' => $retention['local_secret_coverage'],
					),
					'webhook_settings_url' => $retention['provider_webhooks_url'],
				);
			}

			$repository_id = $package->get_provider_repository_id();
			$repository    = strtolower( trim( (string) $package->get_repository(), '/' ) );
			$match         = null;
			foreach ( $readiness['repositories'] as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$candidate_id = $candidate['repository_id'] ?? null;
				if ( is_string( $repository_id ) && '' !== $repository_id && $repository_id === $candidate_id ) {
					$match = $candidate;
					break;
				}
				if ( strtolower( trim( (string) ( $candidate['repository'] ?? '' ), '/' ) ) === $repository ) {
					$match = $candidate;
				}
			}

			return array(
				'provider_code'        => $provider_code,
				'retained'             => false,
				'site'                 => $readiness['site'],
				'repository'           => $match,
				'webhook_settings_url' => (string) ( $this->repository_webhook_settings_url(
					$provider,
					(string) $package->get_repository()
				) ?? '' ),
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return 'verified'|'subdirectory_unavailable'|'subdirectory_unverified'|'unable_to_check'|'provider_unavailable' */
	public function check_package_repository_branch( string $type, Package $package ): string {
		if ( PackageSource::BRANCH !== $package->get_source() ) {
			return 'unable_to_check';
		}
		try {
			return $this->branch_check_lock->run(
				fn (): string => $this->check_package_repository_branch_while_locked( $type, $package ),
				'Another package operation is in progress.',
				'The package operation lock could not be released.'
			);
		} catch ( Throwable ) {
			return 'unable_to_check';
		}
	}

	/** @return 'verified'|'unable_to_check'|'provider_unavailable' */
	private function check_package_repository_branch_while_locked( string $type, Package $package ): string {
		$profile_id          = $this->effective_branch_check_profile( $package );
		$profile_fingerprint = $this->branch_check_evidence->profile_fingerprint_for( $package, $profile_id );

		try {
			$provider = $this->providers->get( (string) $package->get_provider_code() );
		} catch ( UnknownProvider ) {
			return $this->record_package_repository_branch_check( $type, $package, $profile_id, 'provider_unavailable', $profile_fingerprint );
		} catch ( Throwable ) {
			return $this->record_package_repository_branch_check( $type, $package, $profile_id, 'unable_to_check', $profile_fingerprint );
		}

		$archive = null;
		$result  = 'unable_to_check';
		try {
			$repository = $package->get_repository()->reference;
			if ( ! $repository->private ) {
				$credential_id = $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->get_public_repository_browse_metadata()->supports_provider_default_profile
						? $profile_id
						: null;

				$repository = new RepositoryReference(
					$repository->locator,
					$repository->provider_repository_id,
					false,
					$credential_id
				);
			}

			$archive      = $provider->prepare_archive( new ArchiveRequest( $repository, (string) $package->get_branch() ) );
			$resolved_ref = $archive->get_resolved_ref();
			if ( '' !== trim( $resolved_ref )
				&& strlen( $resolved_ref ) <= 191
				&& ! preg_match( '/[\x00-\x1F\x7F]/', $resolved_ref )
			) {
				$subdirectory = $package->get_subdirectory();
				if ( is_string( $subdirectory ) && '' !== $subdirectory
					&& $provider instanceof RepositoryPathInspector
				) {
					try {
						$result = $provider->repository_path_exists( $repository, $resolved_ref, $subdirectory )
							? 'verified'
							: 'subdirectory_unavailable';
					} catch ( Throwable ) {
						$result = 'subdirectory_unverified';
					}
				} elseif ( is_string( $subdirectory ) && '' !== $subdirectory ) {
					$result = 'subdirectory_unverified';
				} else {
					$result = 'verified';
				}
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Provider failures intentionally map to a closed result.
		} catch ( Throwable ) {
			// The provider failure is intentionally not exposed to administrators.
		} finally {
			if ( null !== $archive ) {
				try {
					$archive->cleanup();
				} catch ( Throwable ) {
					$result = 'unable_to_check';
				}
			}
		}

		return $this->record_package_repository_branch_check( $type, $package, $profile_id, $result, $profile_fingerprint );
	}

	/** @param 'verified'|'unable_to_check'|'provider_unavailable' $outcome */
	private function record_package_repository_branch_check(
		string $type,
		Package $package,
		?string $profile_id,
		string $outcome,
		string $profile_fingerprint
	): string {
		try {
			$this->branch_check_evidence->record( $type, $package, $profile_id, $outcome, $profile_fingerprint );
		} catch ( Throwable ) {
			return 'unable_to_check';
		}

		return $outcome;
	}

	/**
	 * Returns the current non-secret access state for a one-time branch-check result.
	 *
	 * The dashboard may cache that result briefly, but credential replacement and
	 * default public-profile changes must require a fresh remote check.
	 */
	public function package_repository_branch_check_access_fingerprint( Package $package ): string {
		return $this->branch_check_evidence->profile_fingerprint_for( $package, $this->effective_branch_check_profile( $package ) );
	}

	/** @return array{outcome: 'verified', checked_at: string}|null */
	public function package_repository_branch_evidence( string $type, Package $package ): ?array {
		return $this->branch_check_evidence->find( $type, $package, $this->effective_branch_check_profile( $package ) );
	}

	private function effective_branch_check_profile( Package $package ): ?string {
		if ( $package->is_private() ) {
			return $package->get_credential_id();
		}
		return $this->public_lookup_profiles->get( (string) $package->get_provider_code() );
	}

	/**
	 * Build display-only evidence for webhook setup retained by a release package.
	 *
	 * Local signing coverage never claims that a matching remote hook exists.
	 *
	 * @return array{
	 *   available: bool,
	 *   provider_code: string,
	 *   repository_id: string,
	 *   repository: string,
	 *   local_secret_coverage: string,
	 *   branch_package_references: list<string>,
	 *   provider_webhooks_url: string
	 * }|null
	 */
	public function build_package_webhook_retention( Package $package ): ?array {
		if ( PackageSource::RELEASE_ASSET !== $package->get_source() ) {
			return null;
		}

		$provider_code = (string) ( $package->get_provider_code() ?? '' );
		$repository_id = (string) ( $package->get_provider_repository_id() ?? '' );
		$repository    = trim( (string) $package->get_repository() );
		if ( '' === $provider_code || '' === $repository_id || '' === $repository ) {
			return null;
		}

		$provider_webhooks_url = '';
		try {
			$provider = $this->providers->get( $provider_code );
			if ( $provider instanceof RepositoryWebhookSettingsLink ) {
				$provider_webhooks_url = (string) ( $this->repository_webhook_settings_url( $provider, $repository ) ?? '' );
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Retained local evidence remains useful while a provider is unavailable.
		} catch ( Throwable ) {
			// The retained local evidence remains useful while a provider is unavailable.
		}

		try {
			$profiles  = $this->secrets->webhook_profiles( $provider_code );
			$coverage  = $this->retained_secret_coverage( $repository, $repository_id, $profiles );
			$available = true;
		} catch ( Throwable ) {
			$coverage  = 'unknown';
			$available = false;
		}

		$branch_consumers = $this->branch_consumers( $provider_code, $repository_id, $repository );

		return array(
			'available'                 => $available,
			'provider_code'             => $provider_code,
			'repository_id'             => $repository_id,
			'repository'                => $repository,
			'local_secret_coverage'     => $coverage,
			'branch_evidence_available' => $branch_consumers['available'],
			'branch_package_references' => $branch_consumers['references'],
			'provider_webhooks_url'     => $provider_webhooks_url,
		);
	}

	/**
	 * Build the provider capability summary used by managed-package lists.
	 *
	 * @return list<array{code: string, label: string, available: bool, deploy: bool, default_credential_id: string, credential_kind_labels: array<string,string>, credentials: list<array{id: string, label: string, source: string}>}>
	 */
	public function build_package_list(): array {
		return array_map(
			static fn ( array $provider ): array => array(
				'code'                   => $provider['code'],
				'label'                  => $provider['label'],
				'available'              => $provider['available'],
				'deploy'                 => $provider['deploy'],
				'default_credential_id'  => $provider['default_credential_id'],
				'credential_kind_labels' => $provider['credential_kind_labels'],
				'credentials'            => array_map(
					static fn ( array $credential ): array => array(
						'id'     => $credential['id'],
						'label'  => $credential['label'],
						'source' => $credential['source'],
					),
					$provider['credential_profiles']
				),
			),
			$this->package_providers()
		);
	}

	/**
	 * Build the display-only credential candidates for Transporter export.
	 *
	 * @param array<string, array<string, list<array{index:int,name:string,type:string}>>> $associations
	 * @return list<array{code:string,label:string,credentials:list<array<string,mixed>>}>
	 */
	public function build_portability_credentials( array $associations ): array {
		$groups = array();
		foreach ( $this->providers->administration_metadata() as $metadata ) {
			$code       = $metadata->code->value;
			$candidates = $associations[ $code ] ?? array();
			$admin      = $metadata->admin;
			if ( null === $admin ) {
				continue;
			}

			$profiles    = $this->secrets->credential_profiles( $code );
			$profile_ids = array_values( array_unique( array_merge( array_keys( $candidates ), array_keys( $profiles ) ) ) );
			if ( array() === $profile_ids ) {
				continue;
			}
			$credentials = array();
			foreach ( $profile_ids as $id ) {
				$profile       = $profiles[ $id ] ?? null;
				$packages      = $candidates[ $id ] ?? array();
				$kind          = is_array( $profile ) ? $admin->get_credential_kind( (string) ( $profile['kind'] ?? '' ) ) : null;
				$source        = is_array( $profile ) && is_string( $profile['source'] ?? null ) ? $profile['source'] : '';
				$self_destruct = is_array( $profile ) && ! empty( $profile['self_destruct'] );
				$reason        = ! is_array( $profile ) ? 'missing' : ( 'file' !== $source ? 'configuration' : ( $self_destruct ? 'self_destruct' : ( array() === $packages ? 'unassociated' : '' ) ) );
				$credentials[] = array(
					'id'         => is_array( $profile ) ? $id : '',
					'label'      => is_array( $profile ) && is_string( $profile['label'] ?? null ) ? $profile['label'] : '',
					'kind_label' => null !== $kind ? $kind->label : ( is_array( $profile ) ? (string) ( $profile['kind'] ?? '' ) : '' ),
					'available'  => '' === $reason && ! empty( $profile['configured'] ),
					'reason'     => $reason,
					'destroy_on' => is_array( $profile ) && is_string( $profile['destroy_on'] ?? null ) ? $profile['destroy_on'] : null,
					'packages'   => $packages,
				);
			}
			$groups[] = array(
				'code'        => $code,
				'label'       => $metadata->label,
				'credentials' => $credentials,
			);
		}

		return $groups;
	}

	/**
	 * Registered providers remain available even when they omit an optional
	 * package capability or package-admin metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function package_providers(): array {
		$providers = array();

		foreach ( $this->providers->administration_metadata() as $metadata ) {
			$code        = $metadata->code->value;
			$provider    = $this->providers->get( $code );
			$admin       = $metadata->admin;
			$credentials = array();

			if ( null !== $admin ) {
				try {
					$profiles = $this->credential_profiles( $code, $admin );
				} catch ( SecretsStorageUnavailable ) {
					$profiles = array();
				}
				foreach ( $profiles as $profile ) {
					$kind    = $admin->get_credential_kind( $profile['kind'] );
					$details = array();

					if ( null !== $kind ) {
						foreach ( $kind->fields as $field ) {
							$value = $profile['configuration'][ $field->key ] ?? '';
							if ( '' !== $value ) {
								$details[] = $field->label . ': ' . $value;
							}
						}
					}

					$credentials[] = array(
						'id'         => $profile['id'],
						'label'      => $profile['label'],
						'kind'       => $profile['kind'],
						'kind_label' => null !== $kind ? $kind->label : $profile['kind'],
						'detail'     => implode( ' · ', $details ),
						'source'     => $profile['source'],
						'configured' => $profile['configured'],
					);
				}
			}

			$providers[] = array(
				'code'                                   => $code,
				'label'                                  => $metadata->label,

				'owner_label'                            => $metadata->owner_label,

				'repository_url_base'                    => $metadata->repository_url_base,

				'credentials_url'                        => null !== $admin && array() !== $admin->credential_kinds
					? 'admin.php?page=ran-booster&tab=' . rawurlencode( $code ) . '&view=credentials'
					: '',
				'available'                              => true,
				'browse'                                 => $provider instanceof RepositoryBrowser,
				'credentialed_public_browse'             => $provider instanceof CredentialedPublicRepositoryBrowser,
				'provider_default_public_lookup_profile' => $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->get_public_repository_browse_metadata()->supports_provider_default_profile,
				'deploy'                                 => true,
				'webhooks'                               => $provider instanceof WebhookNormalizer,
				'default_credential_id'                  => $this->default_credential_id( $credentials ),

				'credential_kind_labels'                 => null === $admin ? array() : array_column( array_map( $this->credential_kind( ... ), $admin->credential_kinds ), 'label', 'code' ),
				'credential_profiles'                    => $credentials,
				'public_lookup'                          => $this->package_public_lookup( $provider, $code, $credentials ),
			);
		}

		return $providers;
	}

	/**
	 * @param list<array<string, mixed>> $credentials Display-safe credential profiles.
	 */
	private function default_credential_id( array $credentials ): string {
		foreach ( $credentials as $credential ) {
			if ( 'constant' === $credential['source'] ) {
				return (string) $credential['id'];
			}
		}

		return 1 === count( $credentials ) ? (string) $credentials[0]['id'] : '';
	}

	/**
	 * @param list<array<string, mixed>> $credentials Display-safe credential profiles.
	 * @return array{supports_default: bool, configured_id: string, configured_label: string, stale: bool}|null
	 */
	private function package_public_lookup( RepositoryProvider $provider, string $provider_code, array $credentials ): ?array {
		if ( ! $provider instanceof CredentialedPublicRepositoryBrowser ) {
			return null;
		}

		$supports_default = $provider->get_public_repository_browse_metadata()->supports_provider_default_profile;
		$configured_id    = $supports_default ? $this->public_lookup_profiles->get( $provider_code ) ?? '' : '';
		$configured_label = '';
		$eligible_ids     = array();

		foreach ( $credentials as $credential ) {
			if ( false === ( $credential['configured'] ?? false ) ) {
				continue;
			}

			$eligible_ids[] = $credential['id'];
			if ( $configured_id === $credential['id'] ) {
				$configured_label = (string) $credential['label'];
			}
		}

		return array(
			'supports_default' => $supports_default,
			'configured_id'    => $configured_id,
			'configured_label' => $configured_label,
			'stale'            => '' !== $configured_id && ! in_array( $configured_id, $eligible_ids, true ),
		);
	}

	/**
	 * @param list<array<string, mixed>> $credentials Display-safe credential profiles.
	 * @return array{configured_id: string, stale: bool}|null
	 */
	private function public_lookup_profile( RepositoryProvider $provider, string $provider_code, array $credentials ): ?array {
		if ( ! $provider instanceof CredentialedPublicRepositoryBrowser
			|| ! $provider->get_public_repository_browse_metadata()->supports_provider_default_profile ) {
			return null;
		}

		$configured_id = $this->public_lookup_profiles->get( $provider_code ) ?? '';
		$eligible_ids  = array_column(
			array_filter(
				$credentials,
				static fn ( array $profile ): bool => true === $profile['configured']
			),
			'id'
		);

		return array(
			'configured_id' => $configured_id,
			'stale'         => '' !== $configured_id && ! in_array( $configured_id, $eligible_ids, true ),
		);
	}

	/**
	 * @return array<string, RepositoryProvider>
	 */
	private function admin_providers(): array {
		$providers = array();
		foreach ( $this->providers->administration_metadata() as $metadata ) {
			$providers[ $metadata->code->value ] = $this->providers->get( $metadata->code );
		}

		return $providers;
	}

	/**
	 * @param array<string, RepositoryProvider> $providers Providers with admin metadata.
	 * @return list<array{code: string, label: string, active: bool}>
	 */
	private function tabs( array $providers, string $selected_provider ): array {
		$tabs = array();

		foreach ( $providers as $code => $provider ) {
			$tabs[] = array(
				'code'   => $code,
				'label'  => $provider->get_metadata()->label,
				'active' => $code === $selected_provider,
			);
		}

		return $tabs;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function provider( RepositoryProvider $provider, ProviderMetadata $metadata, ProviderAdminMetadata $admin ): array {
		$setup = $admin->setup;

		return array(
			'code'             => $metadata->code->value,
			'label'            => $metadata->label,

			'owner_label'      => $metadata->owner_label,

			'credential_kinds' => array_map( $this->credential_kind( ... ), $admin->credential_kinds ),

			'webhook_scopes'   => array_map( $this->webhook_scope( ... ), $admin->webhook_scopes ),
			'webhook_setup'    => null === $setup ? null : array(

				'location'                   => $setup->webhook_location,

				'event'                      => $setup->webhook_event,

				'documentation_url'          => $setup->webhook_documentation_url,

				'delivery_documentation_url' => $setup->delivery_documentation_url,
			),
			'capabilities'     => array(
				'browse'                                 => $provider instanceof RepositoryBrowser,
				'credentialed_public_browse'             => $provider instanceof CredentialedPublicRepositoryBrowser,
				'provider_default_public_lookup_profile' => $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->get_public_repository_browse_metadata()->supports_provider_default_profile,
				'archive'                                => true,
				'webhooks'                               => $provider instanceof WebhookNormalizer,
				'package'                                => true,
				'credentials'                            => $provider instanceof CredentialValidator,
			),
		);
	}

	/**
	 * List repository targets already managed by Booster without mutating package rows.
	 *
	 * @return array{available: bool, owners: list<string>, repositories: list<array<string, mixed>>}
	 */
	private function managed_repositories( string $provider, RepositoryProvider $repository_provider, bool $branch_only ): array {
		if ( null === $this->plugins || null === $this->themes ) {
				return array(
					'available'    => false,
					'owners'       => array(),
					'repositories' => array(),
				);
		}

		try {
			$packages = array_merge(
				array_map(
					static fn ( Package $package ): array => array(
						'package' => $package,
						'type'    => 'plugin',
					),
					array_values( $this->plugins->all_deployment_plugins() )
				),
				array_map(
					static fn ( Package $package ): array => array(
						'package' => $package,
						'type'    => 'theme',
					),
					array_values( $this->themes->all_deployment_themes() )
				)
			);
		} catch ( Throwable ) {
				return array(
					'available'    => false,
					'owners'       => array(),
					'repositories' => array(),
				);
		}

		$owners                    = array();
		$repositories              = array();
		$ids_by_locator            = array();
		$locators_by_repository_id = array();
		foreach ( $packages as $entry ) {
			$package = $entry['package'];
			$source  = $package->get_source();
			if ( $package->get_provider_code() !== $provider
				|| ( $branch_only && PackageSource::BRANCH !== $source ) ) {
				continue;
			}

			$target        = trim( (string) $package->get_repository() );
			$repository_id = trim( (string) ( $package->get_provider_repository_id() ?? '' ) );
			if ( '' === $target ) {
				continue;
			}
			$locator_key = $target;
			if ( '' !== $repository_id ) {
				$ids_by_locator[ $locator_key ][ $repository_id ]            = true;
				$locators_by_repository_id[ $repository_id ][ $locator_key ] = true;
			}
		}

		foreach ( $packages as $entry ) {
			$package = $entry['package'];
			$source  = $package->get_source();
			if ( $package->get_provider_code() !== $provider
				|| ( $branch_only && PackageSource::BRANCH !== $source ) ) {
				continue;
			}

			$target        = trim( (string) $package->get_repository() );
			$repository_id = trim( (string) ( $package->get_provider_repository_id() ?? '' ) );
			if ( '' === $target ) {
				continue;
			}
			$locator_key       = $target;
			$identity_conflict = '' !== $repository_id && (
				1 < count( $ids_by_locator[ $locator_key ] ?? array() )
				|| 1 < count( $locators_by_repository_id[ $repository_id ] ?? array() )
			);

			// Provider IDs, not mutable locators or package source, are the live-row
			// authority. An absent ID stays visible for review but is never operable.
			$key   = '' === $repository_id
				? 'historical:' . hash( 'sha256', $target . '|' . $source->value . '|' . (string) $package->get_identifier() )
				: $repository_id;
			$parts = explode( '/', trim( $target, '/' ), 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$owners[ strtolower( $parts[0] ) ] = $parts[0];
			}
			if ( ! isset( $repositories[ $key ] ) ) {
				$repositories[ $key ] = array(
					'target'                        => $target,
					'repository_id'                 => $repository_id,
					'sources'                       => array( $source->value => true ),
					'historical'                    => '' === $repository_id || $identity_conflict,
					'identity_conflict'             => $identity_conflict,
					'package_count'                 => 0,
					'automatic_count'               => 0,
					'has_automatic_branch_consumer' => false,
					'package_references'            => array(),
					'branch_package_references'     => array(),
					'package_summaries'             => array(),
					'deployment_policies'           => array(
						'automatic' => 0,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'repository_url'                => $identity_conflict ? null : $this->repository_url( $repository_provider, $target ),
					'webhook_settings_url'          => PackageSource::BRANCH === $source && ! $identity_conflict
						? $this->repository_webhook_settings_url( $repository_provider, $target )
						: null,
				);
			}
			if ( $identity_conflict ) {
				$repositories[ $key ]['historical']           = true;
				$repositories[ $key ]['identity_conflict']    = true;
				$repositories[ $key ]['repository_url']       = null;
				$repositories[ $key ]['webhook_settings_url'] = null;
			} elseif ( PackageSource::BRANCH === $source && null === $repositories[ $key ]['webhook_settings_url'] ) {
				$repositories[ $key ]['webhook_settings_url'] = $this->repository_webhook_settings_url( $repository_provider, $target );
			}

			$repositories[ $key ]['sources'][ $source->value ] = true;
			++$repositories[ $key ]['package_count'];
			$repositories[ $key ]['package_references'][] = (string) $package->get_identifier();
			if ( PackageSource::BRANCH === $source ) {
				$repositories[ $key ]['branch_package_references'][] = (string) $package->get_identifier();
				if ( 'automatic' === $package->get_deployment_policy()->value ) {
					$repositories[ $key ]['has_automatic_branch_consumer'] = true;
				}
			}
			if ( self::MAX_REPOSITORY_PACKAGE_SUMMARIES > count( $repositories[ $key ]['package_summaries'] ) ) {
				$repositories[ $key ]['package_summaries'][] = $this->package_summary( $package, $source, $entry['type'] );
			}
			$policy = $package->get_deployment_policy()->value;
			++$repositories[ $key ]['deployment_policies'][ $policy ];
			if ( 'automatic' === $policy ) {
				++$repositories[ $key ]['automatic_count'];
			}
		}

		foreach ( $repositories as &$repository ) {
			sort( $repository['package_references'], SORT_STRING );
			sort( $repository['branch_package_references'], SORT_STRING );
			usort( $repository['package_summaries'], static fn ( array $left, array $right ): int => strcmp( $left['identifier'], $right['identifier'] ) );
			$repository['package_summaries_omitted'] = max( 0, $repository['package_count'] - count( $repository['package_summaries'] ) );
			$source_keys                             = array_keys( $repository['sources'] );
			sort( $source_keys, SORT_STRING );
			$repository['source'] = 2 === count( $source_keys ) ? 'mixed' : ( $source_keys[0] ?? PackageSource::BRANCH->value );
			unset( $repository['sources'] );
		}
		unset( $repository );

		ksort( $repositories, SORT_NATURAL | SORT_FLAG_CASE );
		ksort( $owners, SORT_NATURAL | SORT_FLAG_CASE );

		return array(
			'available'    => true,
			'owners'       => array_values( $owners ),
			'repositories' => array_values( $repositories ),
		);
	}

	/** @return array{type:string,identifier:string,display_name:string,settings_url:string,source:string,source_revision:int,branch:string,subdirectory:string,deployment_policy:string} */
	private function package_summary( Package $package, PackageSource $source, string $type ): array {
		$identifier = (string) $package->get_identifier();
		$page       = 'theme' === $type ? 'ran-booster-themes' : 'ran-booster-plugins';

		return array(
			'type'              => $type,
			'identifier'        => $identifier,
			'display_name'      => $package->get_display_name(),
			'settings_url'      => ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
				. '?page=' . $page . '&package=' . rawurlencode( $identifier ),
			'source'            => $source->value,
			'source_revision'   => $package->get_source_revision(),
			'branch'            => (string) $package->get_branch(),
			'subdirectory'      => (string) $package->get_subdirectory(),
			'deployment_policy' => $package->get_deployment_policy()->value,
		);
	}

	/**
	 * @param array<string, mixed> $provider_repositories
	 * @param array<string, mixed> $branch_repositories
	 * @return array<string, mixed>
	 */
	private function with_retained_webhook_evidence(
		array $provider_repositories,
		array $branch_repositories,
		string $provider_code
	): array {
		try {
			$profiles           = $this->secrets->webhook_profiles( $provider_code );
			$evidence_available = true;
		} catch ( Throwable ) {
			$profiles           = array();
			$evidence_available = false;
		}

		$branches = is_array( $branch_repositories['repositories'] ?? null )
			? $branch_repositories['repositories']
			: array();
		if ( ! is_array( $provider_repositories['repositories'] ?? null ) ) {
			return $provider_repositories;
		}
		foreach ( $provider_repositories['repositories'] as &$repository ) {
			if ( ! is_array( $repository ) || PackageSource::RELEASE_ASSET->value !== ( $repository['source'] ?? null ) ) {
				continue;
			}

			$locator       = (string) ( $repository['target'] ?? '' );
			$repository_id = (string) ( $repository['repository_id'] ?? '' );
			$references    = array();
			foreach ( $branches as $branch ) {
				if ( ! is_array( $branch ) ) {
					continue;
				}
				$branch_id      = (string) ( $branch['repository_id'] ?? '' );
				$branch_locator = (string) ( $branch['target'] ?? '' );
				if ( ( '' !== $repository_id && '' !== $branch_id && hash_equals( $repository_id, $branch_id ) )
					|| 0 === strcasecmp( trim( $locator, '/' ), trim( $branch_locator, '/' ) )
				) {
					$references = array_merge(
						$references,
						array_values( array_filter( $branch['package_references'] ?? array(), 'is_string' ) )
					);
				}
			}
			$references = array_values( array_unique( $references ) );
			sort( $references, SORT_STRING );

			$repository['retained_webhook'] = array(
				'evidence_available'        => $evidence_available,
				'local_secret_coverage'     => $evidence_available
					? $this->retained_secret_coverage( $locator, $repository_id, $profiles )
					: 'unknown',
				'branch_evidence_available' => ! empty( $branch_repositories['available'] ),
				'branch_package_references' => $references,
			);
		}
		unset( $repository );

		return $provider_repositories;
	}

	/** @return array{available: bool, references: list<string>} */
	private function branch_consumers( string $provider_code, string $repository_id, string $repository ): array {
		if ( null === $this->plugins || null === $this->themes ) {
			return array(
				'available'  => false,
				'references' => array(),
			);
		}

		try {
			$packages = array_merge(
				$this->plugins->all_deployment_plugins(),
				$this->themes->all_deployment_themes()
			);
		} catch ( Throwable ) {
			return array(
				'available'  => false,
				'references' => array(),
			);
		}

		$references = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->get_source()
				|| $provider_code !== $package->get_provider_code() ) {
				continue;
			}
			$package_id = (string) ( $package->get_provider_repository_id() ?? '' );
			if ( ( '' !== $package_id && hash_equals( $repository_id, $package_id ) )
				|| 0 === strcasecmp( trim( $repository, '/' ), trim( (string) $package->get_repository(), '/' ) )
			) {
				$references[] = (string) $package->get_identifier();
			}
		}

		$references = array_values( array_unique( $references ) );
		sort( $references, SORT_STRING );

		return array(
			'available'  => true,
			'references' => $references,
		);
	}

	/** @param array<string, array<string, mixed>> $profiles */
	private function retained_secret_coverage( string $repository, string $repository_id, array $profiles ): string {
		$owner  = strtolower( explode( '/', trim( $repository, '/' ), 2 )[0] );
		$shared = false;
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || false === ( $profile['configured'] ?? true ) ) {
				continue;
			}
			$scope = strtolower( trim( (string) ( $profile['scope'] ?? '' ) ) );
			if ( 'repository' === $scope
				&& is_string( $profile['authority_id'] ?? null )
				&& hash_equals( $repository_id, $profile['authority_id'] )
			) {
				return 'repository';
			}
			$target = strtolower( trim( (string) ( $profile['target'] ?? '' ), " \t\n\r\0\x0B/" ) );
			if ( 'owner' === $scope && '' !== $owner && $owner === $target ) {
				$shared = true;
			}
		}

		return $shared ? 'shared' : 'none';
	}

	private function repository_url( RepositoryProvider $provider, string $locator ): ?string {
		$parts = explode( '/', trim( $locator, '/' ), 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		return rtrim( $provider->get_metadata()->repository_url_base, '/' )
			. '/'
			. rawurlencode( $parts[0] )
			. '/'
			. rawurlencode( $parts[1] );
	}

	private function repository_webhook_settings_url( RepositoryProvider $provider, string $locator ): ?string {
		if ( ! $provider instanceof RepositoryWebhookSettingsLink ) {
			return null;
		}

		try {
			$url = trim( $provider->repository_webhook_settings_url( $locator ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Display payload remains usable without WordPress runtime.
			$parts = parse_url( $url );

			if (
				'' === $url
				|| strlen( $url ) > 2048
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $url )
				|| false === filter_var( $url, FILTER_VALIDATE_URL )
				|| false === $parts
				|| 'https' !== strtolower( $parts['scheme'] ?? '' )
				|| '' === ( $parts['host'] ?? '' )
				|| array_intersect_key( $parts, array_flip( array( 'user', 'pass', 'query', 'fragment' ) ) )
			) {
				return null;
			}

			return $url;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function credential_kind( CredentialKindMetadata $kind ): array {
		return array(
			'code'               => $kind->code,
			'label'              => $kind->label,

			'short_label'        => $kind->short_label,

			'secret_label'       => $kind->secret_label,

			'secret_placeholder' => $kind->secret_placeholder,
			'fields'             => array_map( $this->credential_field( ... ), $kind->fields ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function credential_field( CredentialFieldMetadata $field ): array {
		return array(
			'key'         => $field->key,
			'label'       => $field->label,
			'type'        => $field->type,
			'required'    => $field->required,
			'placeholder' => $field->placeholder,
			'description' => $field->description,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function webhook_scope( WebhookScopeMetadata $scope ): array {
		return array(
			'code'               => $scope->code,
			'label'              => $scope->label,

			'requires_target'    => $scope->requires_target,

			'target_label'       => $scope->target_label,

			'target_placeholder' => $scope->target_placeholder,
			'description'        => $scope->description,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function credential_profiles( string $provider, ProviderAdminMetadata $admin, bool $include_usage = false ): array {

		if ( array() === $admin->credential_kinds ) {
			return array();
		}

		$profiles = array();

		foreach ( $this->secrets->credential_profiles( $provider ) as $profile ) {
			$kind          = $admin->get_credential_kind( (string) ( $profile['kind'] ?? '' ) );
			$configuration = array();

			if ( null !== $kind ) {
				foreach ( $kind->fields as $field ) {
					$value                        = $profile['configuration'][ $field->key ] ?? '';
					$configuration[ $field->key ] = is_string( $value ) ? $value : '';
				}
			}

			$id    = is_string( $profile['id'] ?? null ) ? $profile['id'] : '';
			$usage = array(
				'available' => false,
				'total'     => null,
				'packages'  => array(),
			);
			if ( $include_usage ) {
				try {

					$usage = array( 'available' => true ) + $this->credential_usage->read( $provider, $id );
				} catch ( RuntimeException ) {
					$usage['available'] = false;
				}
			}
			$immutable = ! empty( $profile['immutable'] );
			$source    = is_string( $profile['source'] ?? null ) ? $profile['source'] : 'file';
			try {
				$expiry_observation = $this->expiry_observations->get( $provider, $id );
			} catch ( RuntimeException ) {
				$expiry_observation = array();
			}
			$profiles[] = array(
				'id'            => $id,
				'provider'      => $provider,
				'label'         => is_string( $profile['label'] ?? null ) ? $profile['label'] : '',
				'kind'          => is_string( $profile['kind'] ?? null ) ? $profile['kind'] : '',
				'configuration' => $configuration,
				'source'        => $source,
				'immutable'     => $immutable,
				'configured'    => ! empty( $profile['configured'] ),
				'editable'      => ! $immutable && 'constant' !== $source,
				'self_destruct' => ! empty( $profile['self_destruct'] ),
				'destroy_on'    => is_string( $profile['destroy_on'] ?? null ) ? $profile['destroy_on'] : null,
				'expiry'        => $expiry_observation,
				'expiry_status' => $this->expiry_reminders->status( $provider, $profile ),
				'usage'         => array(
					'available' => $usage['available'],
					'total'     => $usage['total'],
					'packages'  => array_map( $this->package_usage_link( ... ), $usage['packages'] ),
				),
			);
		}

		return $profiles;
	}

	/**
	 * @param array{type: string, identifier: string, installed: bool} $package Managed package identity.
	 * @return array{type: string, identifier: string, installed: bool, edit_url: ?string}
	 */
	private function package_usage_link( array $package ): array {
		$page = 'plugin' === $package['type'] ? 'ran-booster-plugins' : 'ran-booster-themes';

		return $package + array(
			'edit_url' => $package['installed']
				? 'admin.php?page=' . rawurlencode( $page ) . '&package=' . rawurlencode( $package['identifier'] )
				: null,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function webhook_profiles(
		string $provider,
		ProviderAdminMetadata $admin,
		array $managed_repositories
	): array {

		if ( array() === $admin->webhook_scopes ) {
			return array();
		}

		$profiles = array();

		foreach ( $this->secrets->webhook_profiles( $provider ) as $profile ) {
			$scope = is_string( $profile['scope'] ?? null ) ? $profile['scope'] : '';
			if ( null === $admin->get_webhook_scope( $scope ) ) {
				continue;
			}

			$immutable  = ! empty( $profile['immutable'] );
			$source     = is_string( $profile['source'] ?? null ) ? $profile['source'] : 'file';
			$profiles[] = array(
				'id'         => is_string( $profile['id'] ?? null ) ? $profile['id'] : '',
				'provider'   => $provider,
				'label'      => is_string( $profile['label'] ?? null ) ? $profile['label'] : '',
				'scope'      => $scope,
				'target'     => is_string( $profile['target'] ?? null ) ? $profile['target'] : '',
				'revision'   => is_int( $profile['revision'] ?? null ) ? $profile['revision'] : 1,
				'origin'     => is_string( $profile['origin'] ?? null ) ? $profile['origin'] : 'manual',
				'source'     => $source,
				'immutable'  => $immutable,
				'configured' => ! empty( $profile['configured'] ),
				'editable'   => ! $immutable && 'constant' !== $source,
				'usage'      => $this->webhook_profile_usage( $profile, $managed_repositories ),
			);
		}

		return $profiles;
	}

	/**
	 * Describe packages whose local signature authority may be affected by one
	 * profile. This remains local configuration evidence; it does not claim that
	 * a matching remote webhook exists.
	 *
	 * @param array<string, mixed> $profile
	 * @param array{
	 *   available: bool,
	 *   repositories: list<array{
	 *     target: string,
	 *     repository_id: string,
	 *     package_count: int,
	 *     package_references: list<string>
	 *   }>
	 * } $managed_repositories
	 * @return array{
	 *   available: bool,
	 *   total: int|null,
	 *   repositories: list<array{
	 *     target: string,
	 *     package_count: int,
	 *     package_references: list<string>
	 *   }>
	 * }
	 */
	private function webhook_profile_usage( array $profile, array $managed_repositories ): array {
		if ( empty( $managed_repositories['available'] ) ) {
			return array(
				'available'    => false,
				'total'        => null,
				'repositories' => array(),
			);
		}

		$scope        = is_string( $profile['scope'] ?? null ) ? strtolower( trim( $profile['scope'] ) ) : '';
		$target       = is_string( $profile['target'] ?? null ) ? strtolower( trim( $profile['target'], '/' ) ) : '';
		$authority_id = is_string( $profile['authority_id'] ?? null ) ? trim( $profile['authority_id'] ) : '';
		$matches      = array();
		$total        = 0;

		foreach ( $managed_repositories['repositories'] as $repository ) {
			$repository_target = strtolower( trim( (string) ( $repository['target'] ?? '' ), '/' ) );
			$repository_id     = is_string( $repository['repository_id'] ?? null )
				? trim( $repository['repository_id'] )
				: '';
			$owner             = strtolower( explode( '/', $repository_target, 2 )[0] ?? '' );
			$matches_profile   = match ( $scope ) {
				'repository' => ( '' !== $authority_id && hash_equals( $authority_id, $repository_id ) )
					|| ( '' === $authority_id && '' !== $target && hash_equals( $target, $repository_target ) ),
				'owner'      => '' !== $target && hash_equals( $target, $owner ),
				default      => false,
			};

			if ( ! $matches_profile ) {
				continue;
			}

			$package_count = max( 0, (int) ( $repository['package_count'] ?? 0 ) );
			$total        += $package_count;
			$matches[]     = array(
				'target'             => (string) ( $repository['target'] ?? '' ),
				'package_count'      => $package_count,
				'package_references' => is_array( $repository['package_references'] ?? null )
					? array_values( array_filter( $repository['package_references'], 'is_string' ) )
					: array(),
			);
		}

		return array(
			'available'    => true,
			'total'        => $total,
			'repositories' => $matches,
		);
	}

	/**
	 * Project credential and webhook-profile lists for the provider page.
	 *
	 * @param array<string, mixed> $data Provider settings and normalized list state.
	 * @return array<string, mixed>
	 */
	public function build_profile_list_projection( array $data ): array {
		$provider       = is_array( $data['provider'] ?? null ) ? $data['provider'] : array();
		$credentials    = is_array( $data['credential_profiles'] ?? null ) ? $data['credential_profiles'] : array();
		$webhooks       = is_array( $data['webhook_profiles'] ?? null ) ? $data['webhook_profiles'] : array();
		$state          = $data['provider_list_state'];
		$provider_code  = is_string( $provider['code'] ?? null ) ? $provider['code'] : '';
		$provider_label = is_string( $provider['label'] ?? null ) ? $provider['label'] : '';
		$owner_label    = is_string( $provider['owner_label'] ?? null ) && '' !== trim( $provider['owner_label'] )
			? $provider['owner_label']
			: __( 'Owner', 'ran-booster' );
		$base_url       = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) );
		$provider_url   = static fn ( array $args = array() ): string => add_query_arg( $args, $base_url );
		$kind_labels    = array_column( is_array( $provider['credential_kinds'] ?? null ) ? $provider['credential_kinds'] : array(), 'label', 'code' );
		$scope_labels   = array_column( is_array( $provider['webhook_scopes'] ?? null ) ? $provider['webhook_scopes'] : array(), 'label', 'code' );
		$field_labels   = array();
		foreach ( is_array( $provider['credential_kinds'] ?? null ) ? $provider['credential_kinds'] : array() as $kind ) {
			foreach ( is_array( $kind['fields'] ?? null ) ? $kind['fields'] : array() as $field ) {
				if ( is_string( $field['key'] ?? null ) && is_string( $field['label'] ?? null ) ) {
					$field_labels[ $field['key'] ] = $field['label'];
				}
			}
		}
		$credential_projection = $this->credential_rows( $credentials, $kind_labels, $field_labels, $owner_label );
		$webhook_rows          = $this->webhook_rows( $webhooks, $scope_labels );
		$credential_list       = $this->filter_and_page( $credential_projection['rows'], 'credentials', $state );
		$webhook_list          = $this->filter_and_page( $webhook_rows, 'secrets', $state );
		$urls                  = $this->list_urls( $provider_url, $provider_code, $state, $credential_list, $webhook_list );
		$storage_unavailable   = ! empty( $data['secrets_storage_unavailable'] );
		$has_credentials       = ! $storage_unavailable && ! empty( $provider['credential_kinds'] );
		$provider_has_webhooks = ! empty( $provider['capabilities']['webhooks'] ) && ! empty( $provider['webhook_scopes'] );
		$summaries             = $this->profile_summaries(
			$storage_unavailable,
			$this->needs_attention( $credential_projection['rows'] ),
			$this->needs_attention( $webhook_rows ),
			count( $credential_projection['rows'] ),
			count( $webhook_rows ),
			(int) ( $data['automaticPackageCount'] ?? 0 )
		);
		$mutation_fields       = array( '_wpnonce' => wp_create_nonce( 'ran-booster-save-secrets' ) );
		if ( is_string( $_SERVER['REQUEST_URI'] ?? null ) ) {
			$mutation_fields['_wp_http_referer'] = wp_unslash( $_SERVER['REQUEST_URI'] );
		}
		$interaction_values = wp_json_encode(
			array(
				'ran_booster_interaction[operation]' => 'core:delete-webhook-profile',
				'ran_booster_interaction[target]'    => ProviderProfileAdminController::TARGET_KEY,
			)
		);

		return array(
			'provider_view'                     => in_array( $data['provider_view'] ?? null, array( 'credentials', 'secrets' ), true ) ? $data['provider_view'] : 'overview',
			'provider_list_state'               => $state,
			'public_lookup_profile'             => is_array( $data['public_lookup_profile'] ?? null ) ? $data['public_lookup_profile'] : null,
			'webhook_setup'                     => is_array( $provider['webhook_setup'] ?? null ) ? $provider['webhook_setup'] : null,
			'storage_unavailable'               => $storage_unavailable,
			'has_credential_settings'           => $has_credentials,
			'provider_has_webhook_settings'     => $provider_has_webhooks,
			'has_webhook_settings'              => ! $storage_unavailable && $provider_has_webhooks,
			'providerTrustDescription'          => sprintf( /* translators: 1: repository provider name, 2: repository provider code. */ __( 'The active %1$s provider can read every credential saved under provider code %2$s. Install and activate only providers you trust; Booster does not authenticate a third-party publisher.', 'ran-booster' ), $provider_label, $provider_code ),
			'package_type_labels'               => array(
				'plugin' => __( 'Plugin', 'ran-booster' ),
				'theme'  => __( 'Theme', 'ran-booster' ),
			),
			'overview_url'                      => $provider_url(),
			'credentials_url'                   => $provider_url( array( 'view' => 'credentials' ) ),
			'secrets_url'                       => $provider_url( array( 'view' => 'secrets' ) ),
			'provider_list_action_url'          => admin_url( 'admin.php' ),
			'provider_mutation_fields'          => $mutation_fields,
			'delete_webhook_interaction_values' => is_string( $interaction_values ) ? $interaction_values : '{}',
			'credential_row_count'              => count( $credential_projection['rows'] ),
			'credential_scopes'                 => $credential_projection['scopes'],
			'webhook_row_count'                 => count( $webhook_rows ),
			'ready_webhook_profile_count'       => count( array_filter( $webhook_rows, static fn ( array $row ): bool => 'ready' === ( $row['status_key'] ?? null ) ) ),
			'credential_summary'                => $summaries['credential'],
			'webhook_summary'                   => $summaries['webhook'],
			'credential_list'                   => $credential_list,
			'webhook_list'                      => $webhook_list,
			'credential_sort_urls'              => $urls['credentials']['sort'],
			'webhook_sort_urls'                 => $urls['secrets']['sort'],
			'credential_pagination'             => $urls['credentials']['pagination'],
			'webhook_pagination'                => $urls['secrets']['pagination'],
		) + $this->profile_copy( $provider_label );
	}



	/**
	 * @param list<array<string,mixed>> $profiles
	 * @param array<string,string> $kind_labels
	 * @param array<string,string> $field_labels
	 * @return array{rows:list<array<string,mixed>>,scopes:array<string,string>}
	 */
	private function credential_rows( array $profiles, array $kind_labels, array $field_labels, string $owner_label ): array {
		$rows   = array();
		$scopes = array();
		foreach ( $profiles as $profile_index => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$configuration = is_array( $profile['configuration'] ?? null ) ? $profile['configuration'] : array();
			$summary       = array();
			$scope_value   = '';
			foreach ( $configuration as $key => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$summary[] = ( $field_labels[ $key ] ?? ucfirst( (string) $key ) ) . ': ' . $value;
				if ( '' === $scope_value && in_array( $key, array( 'owner', 'workspace' ), true ) ) {
					$scope_value = $value;
				}
			}
			$scope_key            = '' === $scope_value ? 'account' : sanitize_key( $scope_value );
			$scope_label          = '' === $scope_value ? __( 'Account', 'ran-booster' ) : $owner_label . ' · ' . $scope_value;
			$scopes[ $scope_key ] = $scope_label;
			$usage                = is_array( $profile['usage'] ?? null ) ? $profile['usage'] : array(
				'available' => false,
				'total'     => null,
				'packages'  => array(),
			);
			$usage_total          = ! empty( $usage['available'] ) ? (int) ( $usage['total'] ?? 0 ) : -1;
			$usage_label          = ! empty( $usage['available'] )
				? sprintf( /* translators: %d: number of packages using the credential. */ _nx( '%d package', '%d packages', $usage_total, 'Packages using a credential', 'ran-booster' ), $usage_total )
				: /* translators: Label shown when package usage cannot be determined. */ __( 'Usage unavailable', 'ran-booster' );
			$health_label         = ! empty( $profile['configured'] )
				? (string) ( $profile['expiry_status']['badge_label'] ?? __( 'Stored · Validity checked on use', 'ran-booster' ) )
				: __( 'Not configured', 'ran-booster' );
			$status_key           = ! empty( $profile['configured'] )
				&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'error' )
				&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'warning' ) ? 'ready' : 'attention';
			$kind                 = is_string( $profile['kind'] ?? null ) ? $profile['kind'] : '';
			$label                = is_string( $profile['label'] ?? null ) ? $profile['label'] : '';
			$rows[]               = $profile + array(
				'profile_index'       => $profile_index,
				'configuration_json'  => (string) wp_json_encode( $configuration ),
				'provider_expires_on' => is_string( $profile['expiry']['provider_expires_at'] ?? null ) ? substr( $profile['expiry']['provider_expires_at'], 0, 10 ) : '',
				'usage_listed'        => count( $usage['packages'] ),
				'kind_label'          => $kind_labels[ $kind ] ?? $kind,
				'configuration_label' => implode( ' · ', $summary ),
				'scope_key'           => $scope_key,
				'scope_label'         => $scope_label,
				'usage_total'         => $usage_total,
				'usage_label'         => $usage_label,
				'health_label'        => $health_label,
				'status_key'          => $status_key,
				'search_value'        => strtolower( implode( ' ', array_merge( array( $label, $kind_labels[ $kind ] ?? $kind, $scope_label, $health_label ), array_values( array_filter( $configuration, 'is_string' ) ) ) ) ),
			);
		}

		return array(
			'rows'   => $rows,
			'scopes' => $scopes,
		);
	}

	/** @param list<array<string,mixed>> $profiles @param array<string,string> $scope_labels @return list<array<string,mixed>> */
	private function webhook_rows( array $profiles, array $scope_labels ): array {
		$rows = array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$usage               = is_array( $profile['usage'] ?? null ) ? $profile['usage'] : array(
				'available'    => false,
				'total'        => null,
				'repositories' => array(),
			);
			$usage_total         = ! empty( $usage['available'] ) ? (int) ( $usage['total'] ?? 0 ) : -1;
			$usage_label         = ! empty( $usage['available'] ) ? sprintf( /* translators: %d: number of packages using the credential. */ _nx( '%d package', '%d packages', $usage_total, 'Packages using a credential', 'ran-booster' ), $usage_total ) : /* translators: Label shown when package usage cannot be determined. */ __( 'Usage unavailable', 'ran-booster' );
			$scope               = is_string( $profile['scope'] ?? null ) ? $profile['scope'] : '';
			$label               = is_string( $profile['label'] ?? null ) ? $profile['label'] : '';
			$target              = is_string( $profile['target'] ?? null ) ? $profile['target'] : '';
			$health              = ! empty( $profile['configured'] ) ? __( 'Saved · Remote delivery not verified by Core', 'ran-booster' ) : __( 'Local secret not configured', 'ran-booster' );
			$delete_confirmation = ! empty( $usage['available'] ) && 0 < $usage_total
				? sprintf( /* translators: %d is the number of managed packages that use the local secret. */ _n( 'Remove this local secret? %d managed package may be affected. Remote provider webhooks will not be removed.', 'Remove this local secret? %d managed packages may be affected. Remote provider webhooks will not be removed.', $usage_total, 'ran-booster' ), $usage_total )
				: __( 'Remove this local secret? Remote provider webhooks will not be removed.', 'ran-booster' );
			$rows[]              = $profile + array(
				'scope_label'         => $scope_labels[ $scope ] ?? ucfirst( $scope ),
				'usage_total'         => $usage_total,
				'usage_label'         => $usage_label,
				'health_label'        => $health,
				'delete_confirmation' => $delete_confirmation,
				'status_key'          => ! empty( $profile['configured'] ) && ! empty( $usage['available'] ) ? 'ready' : 'attention',
				'search_value'        => strtolower( implode( ' ', array( $label, $scope, $target, $usage_label, $health ) ) ),
			);
		}

		return $rows;
	}

	/** @param list<array<string,mixed>> $rows @param array<string,mixed> $state @return array{rows:list<array<string,mixed>>,total:int,pages:int,current:int} */
	private function filter_and_page( array $rows, string $view, array $state ): array {
		$search   = strtolower( trim( (string) $state['search'] ) );
		$rows     = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $state, $search, $view ): bool {
					if ( '' !== $search && ! str_contains( (string) ( $row['search_value'] ?? '' ), $search ) ) {
						return false;
					}
					if ( 'credentials' === $view ) {
						return ( '' === $state['kind'] || ( $row['kind'] ?? null ) === $state['kind'] )
						&& ( '' === $state['scope'] || ( $row['scope_key'] ?? null ) === $state['scope'] );
					}

					return ( '' === $state['scope'] || ( $row['scope'] ?? null ) === $state['scope'] )
					&& ( '' === $state['status'] || ( $row['status_key'] ?? null ) === $state['status'] );
				}
			)
		);
		$sort_key = match ( $state['orderby'] ) {
			'kind' => 'kind_label', 'scope' => 'scope_label', 'usage' => 'usage_total', 'health' => 'health_label', default => 'label' };
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $state, $sort_key ): int {
				$left_value  = $left[ $sort_key ] ?? '';
				$right_value = $right[ $sort_key ] ?? '';
				$comparison  = is_int( $left_value ) && is_int( $right_value ) ? $left_value <=> $right_value : strnatcasecmp( (string) $left_value, (string) $right_value );

				return 'desc' === $state['order'] ? -$comparison : $comparison;
			}
		);
		$total   = count( $rows );
		$pages   = max( 1, (int) ceil( $total / (int) $state['per_page'] ) );
		$current = min( (int) $state['paged'], $pages );

		return array(
			'rows'    => array_slice( $rows, ( $current - 1 ) * (int) $state['per_page'], (int) $state['per_page'] ),
			'total'   => $total,
			'pages'   => $pages,
			'current' => $current,
		);
	}

	/** @param list<array<string,mixed>> $rows */
	private function needs_attention( array $rows ): bool {
		return array() !== array_filter( $rows, static fn ( array $row ): bool => 'attention' === ( $row['status_key'] ?? null ) );
	}

	/** @return array{credential:array{tone:string,heading:string,description:string},webhook:array{tone:string,heading:string,description:string}} */
	private function profile_summaries( bool $storage_unavailable, bool $credential_attention, bool $webhook_attention, int $credential_count, int $webhook_count, int $automatic_count ): array {
		$credential_problem = $storage_unavailable || $credential_attention;
		$webhook_problem    = $storage_unavailable || $webhook_attention || ( 0 === $webhook_count && 0 < $automatic_count );

		return array(
			'credential' => array(
				'tone'        => $credential_problem ? 'attention' : ( 0 < $credential_count ? 'ready' : 'pending' ),
				'heading'     => $credential_problem ? __( 'Repository access needs attention', 'ran-booster' ) : ( 0 === $credential_count ? __( 'No credential saved', 'ran-booster' ) : sprintf( /* translators: %d is the number of saved credentials. */ _n( 'Ready · %d credential', 'Ready · %d credentials', $credential_count, 'ran-booster' ), $credential_count ) ),
				'description' => $storage_unavailable ? __( 'Restore encrypted credential storage before reviewing or changing saved repository access.', 'ran-booster' ) : ( $credential_attention ? __( 'Review saved credentials that are incomplete, expired, or approaching expiry.', 'ran-booster' ) : ( 0 === $credential_count ? __( 'Public repositories remain available through anonymous lookup. Add a credential only for private access or steadier API limits.', 'ran-booster' ) : __( 'Private repository access is available. Open credential management to validate, replace, or review usage.', 'ran-booster' ) ) ),
			),
			'webhook'    => array(
				'tone'        => $webhook_problem ? 'attention' : ( 0 < $webhook_count ? 'ready' : 'pending' ),
				'heading'     => $webhook_problem ? __( 'Webhook signing · Needs attention', 'ran-booster' ) : ( 0 === $webhook_count ? __( 'Webhook signing · No secret saved', 'ran-booster' ) : __( 'Webhook signing · Ready locally', 'ran-booster' ) ),
				'description' => $storage_unavailable ? __( 'Restore encrypted credential storage before Push-to-Deploy can verify signed deliveries.', 'ran-booster' ) : ( $webhook_attention ? __( 'Review saved signing material whose configuration or managed-package usage could not be confirmed.', 'ran-booster' ) : ( 0 === $webhook_count ? ( 0 < $automatic_count ? __( 'Automatic branch deployments require local signing material before provider webhooks can be used safely.', 'ran-booster' ) : __( 'Add local signing material before configuring a provider webhook.', 'ran-booster' ) ) : sprintf( /* translators: %d is the number of local secrets that can verify signed deliveries. */ _n( '%d local secret can verify signed deliveries. This does not prove a matching remote webhook exists.', '%d local secrets can verify signed deliveries. This does not prove matching remote webhooks exist.', $webhook_count, 'ran-booster' ), $webhook_count ) ) ),
			),
		);
	}




	/**
	 * @param callable(array<string,mixed>):string $provider_url
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $credentials
	 * @param array<string,mixed> $secrets
	 * @return array<string,array<string,mixed>>
	 */
	private function list_urls( callable $provider_url, string $provider_code, array $state, array $credentials, array $secrets ): array {
		$result = array();
		foreach ( array(
			'credentials' => $credentials,
			'secrets'     => $secrets,
		) as $view => $list ) {
			$sort = array();
			foreach ( array( 'name', 'kind', 'scope', 'usage', 'health' ) as $orderby ) {
				$order            = $state['orderby'] === $orderby && 'asc' === $state['order'] ? 'desc' : 'asc';
				$sort[ $orderby ] = $provider_url(
					array_filter(
						array(
							'view'     => $view,
							's'        => $state['search'],
							'kind'     => $state['kind'],
							'scope'    => $state['scope'],
							'status'   => $state['status'],
							'orderby'  => $orderby,
							'order'    => $order,
							'per_page' => $state['per_page'],
						),
						static fn ( mixed $value ): bool => '' !== $value
					)
				);
			}
			$page_url        = static fn ( int $page ): string => $provider_url(
				array_filter(
					array(
						'view'     => $view,
						's'        => $state['search'],
						'kind'     => $state['kind'],
						'scope'    => $state['scope'],
						'status'   => $state['status'],
						'orderby'  => $state['orderby'],
						'order'    => $state['order'],
						'per_page' => $state['per_page'],
						'paged'    => max( 1, $page ),
					),
					static fn ( mixed $value ): bool => '' !== $value
				)
			);
			$result[ $view ] = array(
				'sort'       => $sort,
				'pagination' => array(
					'item_count_label' => sprintf( /* translators: %d: item count. */ _n( '%d item', '%d items', $list['total'], 'ran-booster' ), $list['total'] ),
					'page_label'       => sprintf( /* translators: 1: current page number, 2: total page count. */ __( 'Page %1$d of %2$d', 'ran-booster' ), $list['current'], $list['pages'] ),
					'current'          => $list['current'],
					'pages'            => $list['pages'],
					'per_page'         => $state['per_page'],
					'action_url'       => admin_url( 'admin.php' ),
					'hidden_fields'    => array_filter(
						array(
							'page'    => 'ran-booster',
							'tab'     => $provider_code,
							'view'    => $view,
							's'       => $state['search'],
							'kind'    => 'credentials' === $view ? $state['kind'] : '',
							'scope'   => $state['scope'],
							'status'  => 'secrets' === $view ? $state['status'] : '',
							'orderby' => $state['orderby'],
							'order'   => $state['order'],
						),
						static fn ( mixed $value ): bool => '' !== $value
					),
					'previous_url'     => $page_url( $list['current'] - 1 ),
					'next_url'         => $page_url( $list['current'] + 1 ),
				),
			);
		}

		return $result;
	}

	/** @return array<string,string> */
	private function profile_copy( string $label ): array {
		return array(
			'provider_back_label'               => sprintf( /* translators: %s is the repository provider name. */ __( 'Back to %s overview', 'ran-booster' ), $label ),
			'credential_management_description' => sprintf( /* translators: %s is the repository provider name. */ __( 'Manage saved credentials used for %s repository access.', 'ran-booster' ), $label ),
			'secret_management_description'     => sprintf( /* translators: %s is the repository provider name. */ __( 'Manage local signing material used to verify %s webhook deliveries.', 'ran-booster' ), $label ),
		);
	}
}

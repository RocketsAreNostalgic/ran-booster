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
use RuntimeException;
use Throwable;

/**
 * Builds a display-only provider settings payload.
 *
 * Secret-bearing records are deliberately never requested from the sidecar.
 */
final readonly class ProviderSettingsPresenter {

	private PublicRepositoryLookupProfileStore $publicLookupProfiles;
	private CredentialExpiryObservationStore $expiryObservations;
	private CredentialExpiryReminder $expiryReminders;
	private RepositoryBranchCheckEvidenceStore $branchCheckEvidence;

	public function __construct(
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private CredentialUsageReader $credentialUsage,
		?PublicRepositoryLookupProfileStore $publicLookupProfiles = null,
		?CredentialExpiryObservationStore $expiryObservations = null,
		?CredentialExpiryReminder $expiryReminders = null,
		private ?PluginRepository $plugins = null,
		private ?ThemeRepository $themes = null,
		private ?WebhookAssistanceReadinessEvaluator $webhookAssistance = null,
		?RepositoryBranchCheckEvidenceStore $branchCheckEvidence = null
	) {
		$this->publicLookupProfiles = $publicLookupProfiles ?? new PublicRepositoryLookupProfileStore();
		$this->expiryObservations   = $expiryObservations ?? new CredentialExpiryObservationStore();
		$this->expiryReminders      = $expiryReminders ?? new CredentialExpiryReminder(
			$this->providers,
			$this->secrets,
			$this->expiryObservations
		);
		$this->branchCheckEvidence  = $branchCheckEvidence ?? new RepositoryBranchCheckEvidenceStore();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build( ?string $selectedProvider = null ): array {
		$available = $this->adminProviders();
		if ( array() === $available ) {
			throw new RuntimeException( 'No repository provider settings are available.' );
		}

		$selectedProvider = is_string( $selectedProvider ) ? trim( $selectedProvider ) : '';
		if ( ! isset( $available[ $selectedProvider ] ) ) {
			$selectedProvider = array_key_first( $available );
		}

		$selected = $available[ $selectedProvider ];
		$metadata = $selected->getMetadata();
		$admin    = $metadata->admin;

		if ( null === $admin ) {
			throw new RuntimeException( 'Repository provider settings metadata is unavailable.' );
		}

		$managedRepositories  = $this->managedRepositories( $selectedProvider, $selected, true );
		$providerRepositories = $this->managedRepositories( $selectedProvider, $selected, false );
		$webhookReadiness     = $this->webhookAssistanceReadiness( $selectedProvider, $selected );
		$storageUnavailable   = false;
		try {
			$credentials          = $this->credentialProfiles( $selectedProvider, $admin, true );
			$webhooks             = $this->webhookProfiles( $selectedProvider, $admin, $managedRepositories );
			$providerRepositories = $this->withRetainedWebhookEvidence(
				$providerRepositories,
				$managedRepositories,
				$selectedProvider
			);
			$publicLookupProfile  = $this->publicLookupProfile( $selected, $selectedProvider, $credentials );
			if ( null !== $publicLookupProfile ) {
				$configuredId = $publicLookupProfile['configured_id'];
				$credentials  = array_map(
					static fn ( array $profile ): array => $profile + array(
						'public_lookup_default' => $configuredId === $profile['id'],
					),
					$credentials
				);
			}
		} catch ( SecretsStorageUnavailable ) {
			$credentials         = array();
			$webhooks            = array();
			$publicLookupProfile = null;
			$storageUnavailable  = true;
		}

		return array(
			'selected_provider'            => $selectedProvider,
			'providers'                    => $this->tabs( $available, $selectedProvider ),
			'provider'                     => $this->provider( $selected, $metadata, $admin ),
			'credential_profiles'          => $credentials,
			'webhook_profiles'             => $webhooks,
			'managed_webhook_repositories' => $managedRepositories,
			'provider_repositories'        => $providerRepositories,
			'public_lookup_profile'        => $publicLookupProfile,
			'secrets_storage_unavailable'  => $storageUnavailable,
			'webhook_assistance_readiness' => $webhookReadiness,
		);
	}

	/** @return array<string, mixed>|null */
	private function webhookAssistanceReadiness( string $providerCode, RepositoryProvider $provider ): ?array {
		if ( null === $this->webhookAssistance || ! $provider instanceof WebhookNormalizer ) {
			return null;
		}

		try {
			$endpoint = rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $providerCode ) );

			return $this->webhookAssistance->evaluate( $providerCode, $endpoint )->toArray();
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Build provider and credential choices for package forms and the picker.
	 *
	 * @return array{default_provider: string, providers: list<array<string, mixed>>}
	 */
	public function buildPackageForm( ?string $defaultProvider = null ): array {
		$providers = $this->packageProviders();

		$packageProviderCodes = array_column(
			array_filter( $providers, static fn ( array $provider ): bool => true === $provider['deploy'] ),
			'code'
		);
		if ( array() === $packageProviderCodes ) {
			throw new RuntimeException( 'No repository provider can install packages.' );
		}
		if ( ! is_string( $defaultProvider ) || ! in_array( $defaultProvider, $packageProviderCodes, true ) ) {
			$defaultProvider = $packageProviderCodes[0];
		}

		return array(
			'default_provider' => $defaultProvider,
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
	public function buildExistingPackageForm( string $storedProvider ): array {
		$providers = $this->packageProviders();
		$codes     = array_column( $providers, 'code' );

		if ( ! in_array( $storedProvider, $codes, true ) ) {
			$providers[] = array(
				'code'                                   => $storedProvider,
				'label'                                  => $storedProvider,
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
			'default_provider' => $storedProvider,
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
	 *     site: array{status: string, reason_codes: list<string>, callback_url: string},
	 *     repository: array<string, mixed>|null,
	 *     webhook_settings_url: string
	 * }|null
	 */
	public function buildPackageBranchReadiness( Package $package ): ?array {
		if ( PackageSource::BRANCH !== $package->getSource() || null === $this->webhookAssistance ) {
			return null;
		}

		$providerCode = (string) ( $package->getProviderCode() ?? '' );
		if ( '' === $providerCode ) {
			return null;
		}

		try {
			$provider = $this->providers->get( $providerCode );
			if ( ! $provider instanceof WebhookNormalizer ) {
				return null;
			}
			$readiness = $this->webhookAssistanceReadiness( $providerCode, $provider );
			if ( null === $readiness ) {
				return null;
			}

			$repositoryId = $package->getProviderRepositoryId();
			$repository   = strtolower( trim( (string) $package->getRepository(), '/' ) );
			$match        = null;
			foreach ( $readiness['repositories'] as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$candidateId = $candidate['repository_id'] ?? null;
				if ( is_string( $repositoryId ) && '' !== $repositoryId && $repositoryId === $candidateId ) {
					$match = $candidate;
					break;
				}
				if ( $repository === strtolower( trim( (string) ( $candidate['repository'] ?? '' ), '/' ) ) ) {
					$match = $candidate;
				}
			}

			return array(
				'provider_code'        => $providerCode,
				'site'                 => $readiness['site'],
				'repository'           => $match,
				'webhook_settings_url' => (string) ( $this->repositoryWebhookSettingsUrl(
					$provider,
					(string) $package->getRepository()
				) ?? '' ),
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return 'verified'|'unable_to_check'|'provider_unavailable' */
	public function checkPackageRepositoryBranch( string $type, Package $package ): string {
		if ( PackageSource::BRANCH !== $package->getSource() ) {
			return 'unable_to_check';
		}
		$profileId          = $this->effectiveBranchCheckProfile( $package );
		$profileFingerprint = $this->branchCheckEvidence->profileFingerprintFor( $package, $profileId );

		try {
			$provider = $this->providers->get( (string) $package->getProviderCode() );
		} catch ( UnknownProvider ) {
			return $this->recordPackageRepositoryBranchCheck( $type, $package, $profileId, 'provider_unavailable', $profileFingerprint );
		} catch ( Throwable ) {
			return $this->recordPackageRepositoryBranchCheck( $type, $package, $profileId, 'unable_to_check', $profileFingerprint );
		}

		$archive = null;
		$result  = 'unable_to_check';
		try {
			$repository = $package->getRepository()->reference;
			if ( ! $repository->private ) {
				$credentialId = $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile
						? $profileId
						: null;

				$repository = new RepositoryReference(
					$repository->locator,
					$repository->providerRepositoryId,
					false,
					$credentialId
				);
			}

			$archive     = $provider->prepareArchive( new ArchiveRequest( $repository, (string) $package->getBranch() ) );
			$resolvedRef = $archive->getResolvedRef();
			if ( '' !== trim( $resolvedRef )
				&& strlen( $resolvedRef ) <= 191
				&& ! preg_match( '/[\x00-\x1F\x7F]/', $resolvedRef )
			) {
				$result = 'verified';
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

		return $this->recordPackageRepositoryBranchCheck( $type, $package, $profileId, $result, $profileFingerprint );
	}

	/** @param 'verified'|'unable_to_check'|'provider_unavailable' $outcome */
	private function recordPackageRepositoryBranchCheck(
		string $type,
		Package $package,
		?string $profileId,
		string $outcome,
		string $profileFingerprint
	): string {
		try {
			$this->branchCheckEvidence->record( $type, $package, $profileId, $outcome, $profileFingerprint );
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
	public function packageRepositoryBranchCheckAccessFingerprint( Package $package ): string {
		return $this->branchCheckEvidence->profileFingerprintFor( $package, $this->effectiveBranchCheckProfile( $package ) );
	}

	/** @return array{outcome: 'verified', checked_at: string}|null */
	public function packageRepositoryBranchEvidence( string $type, Package $package ): ?array {
		return $this->branchCheckEvidence->find( $type, $package, $this->effectiveBranchCheckProfile( $package ) );
	}

	private function effectiveBranchCheckProfile( Package $package ): ?string {
		if ( $package->isPrivate() ) {
			return $package->getCredentialId();
		}
		return $this->publicLookupProfiles->get( (string) $package->getProviderCode() );
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
	public function buildPackageWebhookRetention( Package $package ): ?array {
		if ( PackageSource::RELEASE_ASSET !== $package->getSource() ) {
			return null;
		}

		$providerCode = (string) ( $package->getProviderCode() ?? '' );
		$repositoryId = (string) ( $package->getProviderRepositoryId() ?? '' );
		$repository   = trim( (string) $package->getRepository() );
		if ( '' === $providerCode || '' === $repositoryId || '' === $repository ) {
			return null;
		}

		$providerWebhooksUrl = '';
		try {
			$provider = $this->providers->get( $providerCode );
			if ( $provider instanceof RepositoryWebhookSettingsLink ) {
				$providerWebhooksUrl = (string) ( $this->repositoryWebhookSettingsUrl( $provider, $repository ) ?? '' );
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Retained local evidence remains useful while a provider is unavailable.
		} catch ( Throwable ) {
			// The retained local evidence remains useful while a provider is unavailable.
		}

		try {
			$profiles  = $this->secrets->webhookProfiles( $providerCode );
			$coverage  = $this->retainedSecretCoverage( $repository, $repositoryId, $profiles );
			$available = true;
		} catch ( Throwable ) {
			$coverage  = 'unknown';
			$available = false;
		}

		$branchConsumers = $this->branchConsumers( $providerCode, $repositoryId, $repository );

		return array(
			'available'                 => $available,
			'provider_code'             => $providerCode,
			'repository_id'             => $repositoryId,
			'repository'                => $repository,
			'local_secret_coverage'     => $coverage,
			'branch_evidence_available' => $branchConsumers['available'],
			'branch_package_references' => $branchConsumers['references'],
			'provider_webhooks_url'     => $providerWebhooksUrl,
		);
	}

	/**
	 * Build the provider capability summary used by managed-package lists.
	 *
	 * @return list<array{code: string, label: string, available: bool, deploy: bool, default_credential_id: string, credential_kind_labels: array<string,string>, credentials: list<array{id: string, label: string, source: string}>}>
	 */
	public function buildPackageList(): array {
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
			$this->packageProviders()
		);
	}

	/**
	 * Build the display-only credential candidates for Transporter export.
	 *
	 * @param array<string, array<string, list<array{index:int,name:string,type:string}>>> $associations
	 * @return list<array{code:string,label:string,credentials:list<array<string,mixed>>}>
	 */
	public function buildPortabilityCredentials( array $associations ): array {
		$groups = array();
		foreach ( $this->providers->administrationMetadata() as $metadata ) {
			$code       = $metadata->code->value;
			$candidates = $associations[ $code ] ?? array();
			$admin      = $metadata->admin;
			if ( null === $admin ) {
				continue;
			}

			$profiles   = $this->secrets->credentialProfiles( $code );
			$profileIds = array_values( array_unique( array_merge( array_keys( $candidates ), array_keys( $profiles ) ) ) );
			if ( array() === $profileIds ) {
				continue;
			}
			$credentials = array();
			foreach ( $profileIds as $id ) {
				$profile       = $profiles[ $id ] ?? null;
				$packages      = $candidates[ $id ] ?? array();
				$kind          = is_array( $profile ) ? $admin->getCredentialKind( (string) ( $profile['kind'] ?? '' ) ) : null;
				$source        = is_array( $profile ) && is_string( $profile['source'] ?? null ) ? $profile['source'] : '';
				$selfDestruct  = is_array( $profile ) && ! empty( $profile['self_destruct'] );
				$reason        = ! is_array( $profile ) ? 'missing' : ( 'file' !== $source ? 'configuration' : ( $selfDestruct ? 'self_destruct' : ( array() === $packages ? 'unassociated' : '' ) ) );
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
	private function packageProviders(): array {
		$providers = array();

		foreach ( $this->providers->administrationMetadata() as $metadata ) {
			$code        = $metadata->code->value;
			$provider    = $this->providers->get( $code );
			$admin       = $metadata->admin;
			$credentials = array();

			if ( null !== $admin ) {
				try {
					$profiles = $this->credentialProfiles( $code, $admin );
				} catch ( SecretsStorageUnavailable ) {
					$profiles = array();
				}
				foreach ( $profiles as $profile ) {
					$kind    = $admin->getCredentialKind( $profile['kind'] );
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
				'owner_label'                            => $metadata->ownerLabel,
				'repository_url_base'                    => $metadata->repositoryUrlBase,
				'credentials_url'                        => null !== $admin && array() !== $admin->credentialKinds
					? 'admin.php?page=ran-booster&tab=' . rawurlencode( $code ) . '&view=credentials'
					: '',
				'available'                              => true,
				'browse'                                 => $provider instanceof RepositoryBrowser,
				'credentialed_public_browse'             => $provider instanceof CredentialedPublicRepositoryBrowser,
				'provider_default_public_lookup_profile' => $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile,
				'deploy'                                 => true,
				'webhooks'                               => $provider instanceof WebhookNormalizer,
				'default_credential_id'                  => $this->defaultCredentialId( $credentials ),
				'credential_kind_labels'                 => null === $admin ? array() : array_column( array_map( $this->credentialKind( ... ), $admin->credentialKinds ), 'label', 'code' ),
				'credential_profiles'                    => $credentials,
				'public_lookup'                          => $this->packagePublicLookup( $provider, $code, $credentials ),
			);
		}

		return $providers;
	}

	/**
	 * @param list<array<string, mixed>> $credentials Display-safe credential profiles.
	 */
	private function defaultCredentialId( array $credentials ): string {
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
	private function packagePublicLookup( RepositoryProvider $provider, string $providerCode, array $credentials ): ?array {
		if ( ! $provider instanceof CredentialedPublicRepositoryBrowser ) {
			return null;
		}

		$supportsDefault = $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile;
		$configuredId    = $supportsDefault ? $this->publicLookupProfiles->get( $providerCode ) ?? '' : '';
		$configuredLabel = '';
		$eligibleIds     = array();

		foreach ( $credentials as $credential ) {
			if ( false === ( $credential['configured'] ?? false ) ) {
				continue;
			}

			$eligibleIds[] = $credential['id'];
			if ( $configuredId === $credential['id'] ) {
				$configuredLabel = (string) $credential['label'];
			}
		}

		return array(
			'supports_default' => $supportsDefault,
			'configured_id'    => $configuredId,
			'configured_label' => $configuredLabel,
			'stale'            => '' !== $configuredId && ! in_array( $configuredId, $eligibleIds, true ),
		);
	}

	/**
	 * @param list<array<string, mixed>> $credentials Display-safe credential profiles.
	 * @return array{configured_id: string, stale: bool}|null
	 */
	private function publicLookupProfile( RepositoryProvider $provider, string $providerCode, array $credentials ): ?array {
		if ( ! $provider instanceof CredentialedPublicRepositoryBrowser
			|| ! $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile ) {
			return null;
		}

		$configuredId = $this->publicLookupProfiles->get( $providerCode ) ?? '';
		$eligibleIds  = array_column(
			array_filter(
				$credentials,
				static fn ( array $profile ): bool => true === $profile['configured']
			),
			'id'
		);

		return array(
			'configured_id' => $configuredId,
			'stale'         => '' !== $configuredId && ! in_array( $configuredId, $eligibleIds, true ),
		);
	}

	/**
	 * @return array<string, RepositoryProvider>
	 */
	private function adminProviders(): array {
		$providers = array();
		foreach ( $this->providers->administrationMetadata() as $metadata ) {
			$providers[ $metadata->code->value ] = $this->providers->get( $metadata->code );
		}

		return $providers;
	}

	/**
	 * @param array<string, RepositoryProvider> $providers Providers with admin metadata.
	 * @return list<array{code: string, label: string, active: bool}>
	 */
	private function tabs( array $providers, string $selectedProvider ): array {
		$tabs = array();

		foreach ( $providers as $code => $provider ) {
			$tabs[] = array(
				'code'   => $code,
				'label'  => $provider->getMetadata()->label,
				'active' => $code === $selectedProvider,
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
			'owner_label'      => $metadata->ownerLabel,
			'credential_kinds' => array_map( $this->credentialKind( ... ), $admin->credentialKinds ),
			'webhook_scopes'   => array_map( $this->webhookScope( ... ), $admin->webhookScopes ),
			'webhook_setup'    => null === $setup ? null : array(
				'location'                   => $setup->webhookLocation,
				'event'                      => $setup->webhookEvent,
				'documentation_url'          => $setup->webhookDocumentationUrl,
				'delivery_documentation_url' => $setup->deliveryDocumentationUrl,
			),
			'capabilities'     => array(
				'browse'                                 => $provider instanceof RepositoryBrowser,
				'credentialed_public_browse'             => $provider instanceof CredentialedPublicRepositoryBrowser,
				'provider_default_public_lookup_profile' => $provider instanceof CredentialedPublicRepositoryBrowser
					&& $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile,
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
	 * @return array{available: bool, owners: list<string>, repositories: list<array{target: string, repository_id: string, source: string, package_count: int, automatic_count: int, package_references: list<string>, deployment_policies: array{automatic: int, manual: int, disabled: int}, repository_url: string|null, webhook_settings_url: string|null}>}
	 */
	private function managedRepositories( string $provider, RepositoryProvider $repositoryProvider, bool $branchOnly ): array {
		if ( null === $this->plugins || null === $this->themes ) {
				return array(
					'available'    => false,
					'owners'       => array(),
					'repositories' => array(),
				);
		}

		try {
			$packages = array_merge(
				$this->plugins->allDeploymentPlugins(),
				$this->themes->allDeploymentThemes()
			);
		} catch ( Throwable ) {
				return array(
					'available'    => false,
					'owners'       => array(),
					'repositories' => array(),
				);
		}

		$owners       = array();
		$repositories = array();
		foreach ( $packages as $package ) {
			$source = $package->getSource();
			if ( $package->getProviderCode() !== $provider
				|| ( $branchOnly && PackageSource::BRANCH !== $source ) ) {
				continue;
			}

			$target = trim( (string) $package->getRepository() );
			if ( '' === $target ) {
				continue;
			}

			$key   = strtolower( $target ) . ( $branchOnly ? '' : '|' . $source->value );
			$parts = explode( '/', trim( $target, '/' ), 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$owners[ strtolower( $parts[0] ) ] = $parts[0];
			}
			if ( ! isset( $repositories[ $key ] ) ) {
				$repositories[ $key ] = array(
					'target'               => $target,
					'repository_id'        => (string) ( $package->getProviderRepositoryId() ?? '' ),
					'source'               => $source->value,
					'package_count'        => 0,
					'automatic_count'      => 0,
					'package_references'   => array(),
					'deployment_policies'  => array(
						'automatic' => 0,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'repository_url'       => $this->repositoryUrl( $repositoryProvider, $target ),
					'webhook_settings_url' => PackageSource::BRANCH === $source
						? $this->repositoryWebhookSettingsUrl( $repositoryProvider, $target )
						: null,
				);
			} elseif ( $repositories[ $key ]['repository_id'] !== (string) ( $package->getProviderRepositoryId() ?? '' ) ) {
				$repositories[ $key ]['repository_id'] = '';
			}

			++$repositories[ $key ]['package_count'];
			$repositories[ $key ]['package_references'][] = (string) $package->getIdentifier();
			$policy                                       = $package->getDeploymentPolicy()->value;
			++$repositories[ $key ]['deployment_policies'][ $policy ];
			if ( 'automatic' === $policy ) {
				++$repositories[ $key ]['automatic_count'];
			}
		}

		foreach ( $repositories as &$repository ) {
			sort( $repository['package_references'], SORT_STRING );
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

	/**
	 * @param array<string, mixed> $providerRepositories
	 * @param array<string, mixed> $branchRepositories
	 * @return array<string, mixed>
	 */
	private function withRetainedWebhookEvidence(
		array $providerRepositories,
		array $branchRepositories,
		string $providerCode
	): array {
		try {
			$profiles          = $this->secrets->webhookProfiles( $providerCode );
			$evidenceAvailable = true;
		} catch ( Throwable ) {
			$profiles          = array();
			$evidenceAvailable = false;
		}

		$branches = is_array( $branchRepositories['repositories'] ?? null )
			? $branchRepositories['repositories']
			: array();
		if ( ! is_array( $providerRepositories['repositories'] ?? null ) ) {
			return $providerRepositories;
		}
		foreach ( $providerRepositories['repositories'] as &$repository ) {
			if ( ! is_array( $repository ) || PackageSource::RELEASE_ASSET->value !== ( $repository['source'] ?? null ) ) {
				continue;
			}

			$locator      = (string) ( $repository['target'] ?? '' );
			$repositoryId = (string) ( $repository['repository_id'] ?? '' );
			$references   = array();
			foreach ( $branches as $branch ) {
				if ( ! is_array( $branch ) ) {
					continue;
				}
				$branchId      = (string) ( $branch['repository_id'] ?? '' );
				$branchLocator = (string) ( $branch['target'] ?? '' );
				if ( ( '' !== $repositoryId && '' !== $branchId && hash_equals( $repositoryId, $branchId ) )
					|| 0 === strcasecmp( trim( $locator, '/' ), trim( $branchLocator, '/' ) )
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
				'evidence_available'        => $evidenceAvailable,
				'local_secret_coverage'     => $evidenceAvailable
					? $this->retainedSecretCoverage( $locator, $repositoryId, $profiles )
					: 'unknown',
				'branch_evidence_available' => ! empty( $branchRepositories['available'] ),
				'branch_package_references' => $references,
			);
		}
		unset( $repository );

		return $providerRepositories;
	}

	/** @return array{available: bool, references: list<string>} */
	private function branchConsumers( string $providerCode, string $repositoryId, string $repository ): array {
		if ( null === $this->plugins || null === $this->themes ) {
			return array(
				'available'  => false,
				'references' => array(),
			);
		}

		try {
			$packages = array_merge(
				$this->plugins->allDeploymentPlugins(),
				$this->themes->allDeploymentThemes()
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
				|| PackageSource::BRANCH !== $package->getSource()
				|| $providerCode !== $package->getProviderCode() ) {
				continue;
			}
			$packageId = (string) ( $package->getProviderRepositoryId() ?? '' );
			if ( ( '' !== $packageId && hash_equals( $repositoryId, $packageId ) )
				|| 0 === strcasecmp( trim( $repository, '/' ), trim( (string) $package->getRepository(), '/' ) )
			) {
				$references[] = (string) $package->getIdentifier();
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
	private function retainedSecretCoverage( string $repository, string $repositoryId, array $profiles ): string {
		$owner  = strtolower( explode( '/', trim( $repository, '/' ), 2 )[0] );
		$shared = false;
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || false === ( $profile['configured'] ?? true ) ) {
				continue;
			}
			$scope = strtolower( trim( (string) ( $profile['scope'] ?? '' ) ) );
			if ( 'repository' === $scope
				&& is_string( $profile['authority_id'] ?? null )
				&& hash_equals( $repositoryId, $profile['authority_id'] )
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

	private function repositoryUrl( RepositoryProvider $provider, string $locator ): ?string {
		$parts = explode( '/', trim( $locator, '/' ), 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		return rtrim( $provider->getMetadata()->repositoryUrlBase, '/' )
			. '/'
			. rawurlencode( $parts[0] )
			. '/'
			. rawurlencode( $parts[1] );
	}

	private function repositoryWebhookSettingsUrl( RepositoryProvider $provider, string $locator ): ?string {
		if ( ! $provider instanceof RepositoryWebhookSettingsLink ) {
			return null;
		}

		try {
			$url = trim( $provider->repositoryWebhookSettingsUrl( $locator ) );
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
	private function credentialKind( CredentialKindMetadata $kind ): array {
		return array(
			'code'               => $kind->code,
			'label'              => $kind->label,
			'short_label'        => $kind->shortLabel,
			'secret_label'       => $kind->secretLabel,
			'secret_placeholder' => $kind->secretPlaceholder,
			'fields'             => array_map( $this->credentialField( ... ), $kind->fields ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function credentialField( CredentialFieldMetadata $field ): array {
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
	private function webhookScope( WebhookScopeMetadata $scope ): array {
		return array(
			'code'               => $scope->code,
			'label'              => $scope->label,
			'requires_target'    => $scope->requiresTarget,
			'target_label'       => $scope->targetLabel,
			'target_placeholder' => $scope->targetPlaceholder,
			'description'        => $scope->description,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function credentialProfiles( string $provider, ProviderAdminMetadata $admin, bool $includeUsage = false ): array {
		if ( array() === $admin->credentialKinds ) {
			return array();
		}

		$profiles = array();

		foreach ( $this->secrets->credentialProfiles( $provider ) as $profile ) {
			$kind          = $admin->getCredentialKind( (string) ( $profile['kind'] ?? '' ) );
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
			if ( $includeUsage ) {
				try {
					$usage = array( 'available' => true ) + $this->credentialUsage->read( $provider, $id );
				} catch ( RuntimeException ) {
					$usage['available'] = false;
				}
			}
			$immutable = ! empty( $profile['immutable'] );
			$source    = is_string( $profile['source'] ?? null ) ? $profile['source'] : 'file';
			try {
				$expiryObservation = $this->expiryObservations->get( $provider, $id );
			} catch ( RuntimeException ) {
				$expiryObservation = array();
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
				'expiry'        => $expiryObservation,
				'expiry_status' => $this->expiryReminders->status( $provider, $profile ),
				'usage'         => array(
					'available' => $usage['available'],
					'total'     => $usage['total'],
					'packages'  => array_map( $this->packageUsageLink( ... ), $usage['packages'] ),
				),
			);
		}

		return $profiles;
	}

	/**
	 * @param array{type: string, identifier: string, installed: bool} $package Managed package identity.
	 * @return array{type: string, identifier: string, installed: bool, edit_url: ?string}
	 */
	private function packageUsageLink( array $package ): array {
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
	private function webhookProfiles(
		string $provider,
		ProviderAdminMetadata $admin,
		array $managedRepositories
	): array {
		if ( array() === $admin->webhookScopes ) {
			return array();
		}

		$profiles = array();

		foreach ( $this->secrets->webhookProfiles( $provider ) as $profile ) {
			$scope = is_string( $profile['scope'] ?? null ) ? $profile['scope'] : '';
			if ( null === $admin->getWebhookScope( $scope ) ) {
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
				'usage'      => $this->webhookProfileUsage( $profile, $managedRepositories ),
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
	 * } $managedRepositories
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
	private function webhookProfileUsage( array $profile, array $managedRepositories ): array {
		if ( empty( $managedRepositories['available'] ) ) {
			return array(
				'available'    => false,
				'total'        => null,
				'repositories' => array(),
			);
		}

		$scope       = is_string( $profile['scope'] ?? null ) ? strtolower( trim( $profile['scope'] ) ) : '';
		$target      = is_string( $profile['target'] ?? null ) ? strtolower( trim( $profile['target'], '/' ) ) : '';
		$authorityId = is_string( $profile['authority_id'] ?? null ) ? trim( $profile['authority_id'] ) : '';
		$matches     = array();
		$total       = 0;

		foreach ( $managedRepositories['repositories'] as $repository ) {
			$repositoryTarget = strtolower( trim( (string) ( $repository['target'] ?? '' ), '/' ) );
			$repositoryId     = is_string( $repository['repository_id'] ?? null )
				? trim( $repository['repository_id'] )
				: '';
			$owner            = strtolower( explode( '/', $repositoryTarget, 2 )[0] ?? '' );
			$matchesProfile   = match ( $scope ) {
				'repository' => ( '' !== $authorityId && hash_equals( $authorityId, $repositoryId ) )
					|| ( '' === $authorityId && '' !== $target && hash_equals( $target, $repositoryTarget ) ),
				'owner'      => '' !== $target && hash_equals( $target, $owner ),
				default      => false,
			};

			if ( ! $matchesProfile ) {
				continue;
			}

			$packageCount = max( 0, (int) ( $repository['package_count'] ?? 0 ) );
			$total       += $packageCount;
			$matches[]    = array(
				'target'             => (string) ( $repository['target'] ?? '' ),
				'package_count'      => $packageCount,
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
	// Translation placeholders in this internal read model retain the provider,
	// package-count and page-count meanings documented by their returned keys.
	// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment

	/**
	 * Project credential and webhook-profile lists for the provider page.
	 *
	 * @param array<string, mixed> $data Provider settings and normalized list state.
	 * @return array<string, mixed>
	 */
	public function buildProfileListProjection( array $data ): array {
		$provider      = is_array( $data['provider'] ?? null ) ? $data['provider'] : array();
		$credentials   = is_array( $data['credential_profiles'] ?? null ) ? $data['credential_profiles'] : array();
		$webhooks      = is_array( $data['webhook_profiles'] ?? null ) ? $data['webhook_profiles'] : array();
		$state         = $data['providerListState'];
		$providerCode  = is_string( $provider['code'] ?? null ) ? $provider['code'] : '';
		$providerLabel = is_string( $provider['label'] ?? null ) ? $provider['label'] : '';
		$ownerLabel    = is_string( $provider['owner_label'] ?? null ) && '' !== trim( $provider['owner_label'] )
			? $provider['owner_label']
			: __( 'Owner', 'ran-booster' );
		$baseUrl       = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) );
		$providerUrl   = static fn ( array $args = array() ): string => add_query_arg( $args, $baseUrl );
		$kindLabels    = array_column( is_array( $provider['credential_kinds'] ?? null ) ? $provider['credential_kinds'] : array(), 'label', 'code' );
		$scopeLabels   = array_column( is_array( $provider['webhook_scopes'] ?? null ) ? $provider['webhook_scopes'] : array(), 'label', 'code' );
		$fieldLabels   = array();
		foreach ( is_array( $provider['credential_kinds'] ?? null ) ? $provider['credential_kinds'] : array() as $kind ) {
			foreach ( is_array( $kind['fields'] ?? null ) ? $kind['fields'] : array() as $field ) {
				if ( is_string( $field['key'] ?? null ) && is_string( $field['label'] ?? null ) ) {
					$fieldLabels[ $field['key'] ] = $field['label'];
				}
			}
		}
		$credentialProjection = $this->credentialRows( $credentials, $kindLabels, $fieldLabels, $ownerLabel );
		$webhookRows          = $this->webhookRows( $webhooks, $scopeLabels );
		$credentialList       = $this->filterAndPage( $credentialProjection['rows'], 'credentials', $state );
		$webhookList          = $this->filterAndPage( $webhookRows, 'secrets', $state );
		$urls                 = $this->listUrls( $providerUrl, $providerCode, $state, $credentialList, $webhookList );
		$storageUnavailable   = ! empty( $data['secrets_storage_unavailable'] );
		$hasCredentials       = ! $storageUnavailable && ! empty( $provider['credential_kinds'] );
		$providerHasWebhooks  = ! empty( $provider['capabilities']['webhooks'] ) && ! empty( $provider['webhook_scopes'] );
		$summaries            = $this->profileSummaries(
			$storageUnavailable,
			$this->needsAttention( $credentialProjection['rows'] ),
			$this->needsAttention( $webhookRows ),
			count( $credentialProjection['rows'] ),
			count( $webhookRows ),
			(int) ( $data['automaticPackageCount'] ?? 0 )
		);
		$mutationFields       = array( '_wpnonce' => wp_create_nonce( 'ran-booster-save-secrets' ) );
		if ( is_string( $_SERVER['REQUEST_URI'] ?? null ) ) {
			$mutationFields['_wp_http_referer'] = wp_unslash( $_SERVER['REQUEST_URI'] );
		}
		$interactionValues = wp_json_encode(
			array(
				'ran_booster_interaction[operation]' => 'core:delete-webhook-profile',
				'ran_booster_interaction[target]'    => ProviderProfileAdminController::TARGET_KEY,
			)
		);

		return array(
			'providerView'                   => in_array( $data['providerView'] ?? null, array( 'credentials', 'secrets' ), true ) ? $data['providerView'] : 'overview',
			'providerListState'              => $state,
			'publicLookupProfile'            => is_array( $data['public_lookup_profile'] ?? null ) ? $data['public_lookup_profile'] : null,
			'webhookSetup'                   => is_array( $provider['webhook_setup'] ?? null ) ? $provider['webhook_setup'] : null,
			'storageUnavailable'             => $storageUnavailable,
			'hasCredentialSettings'          => $hasCredentials,
			'providerHasWebhookSettings'     => $providerHasWebhooks,
			'hasWebhookSettings'             => ! $storageUnavailable && $providerHasWebhooks,
			'providerTrustDescription'       => sprintf( __( 'The active %1$s provider can read every credential saved under provider code %2$s. Install and activate only providers you trust; Booster does not authenticate a third-party publisher.', 'ran-booster' ), $providerLabel, $providerCode ),
			'packageTypeLabels'              => array(
				'plugin' => __( 'Plugin', 'ran-booster' ),
				'theme'  => __( 'Theme', 'ran-booster' ),
			),
			'overviewUrl'                    => $providerUrl(),
			'credentialsUrl'                 => $providerUrl( array( 'view' => 'credentials' ) ),
			'secretsUrl'                     => $providerUrl( array( 'view' => 'secrets' ) ),
			'providerListActionUrl'          => admin_url( 'admin.php' ),
			'providerMutationFields'         => $mutationFields,
			'deleteWebhookInteractionValues' => is_string( $interactionValues ) ? $interactionValues : '{}',
			'credentialRowCount'             => count( $credentialProjection['rows'] ),
			'credentialScopes'               => $credentialProjection['scopes'],
			'webhookRowCount'                => count( $webhookRows ),
			'credentialSummary'              => $summaries['credential'],
			'webhookSummary'                 => $summaries['webhook'],
			'credentialList'                 => $credentialList,
			'webhookList'                    => $webhookList,
			'credentialSortUrls'             => $urls['credentials']['sort'],
			'webhookSortUrls'                => $urls['secrets']['sort'],
			'credentialPagination'           => $urls['credentials']['pagination'],
			'webhookPagination'              => $urls['secrets']['pagination'],
		) + $this->profileCopy( $providerLabel );
	}



	/**
	 * @param list<array<string,mixed>> $profiles
	 * @param array<string,string> $kindLabels
	 * @param array<string,string> $fieldLabels
	 * @return array{rows:list<array<string,mixed>>,scopes:array<string,string>}
	 */
	private function credentialRows( array $profiles, array $kindLabels, array $fieldLabels, string $ownerLabel ): array {
		$rows   = array();
		$scopes = array();
		foreach ( $profiles as $profileIndex => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$configuration = is_array( $profile['configuration'] ?? null ) ? $profile['configuration'] : array();
			$summary       = array();
			$scopeValue    = '';
			foreach ( $configuration as $key => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$summary[] = ( $fieldLabels[ $key ] ?? ucfirst( (string) $key ) ) . ': ' . $value;
				if ( '' === $scopeValue && in_array( $key, array( 'owner', 'workspace' ), true ) ) {
					$scopeValue = $value;
				}
			}
			$scopeKey            = '' === $scopeValue ? 'account' : sanitize_key( $scopeValue );
			$scopeLabel          = '' === $scopeValue ? __( 'Account', 'ran-booster' ) : $ownerLabel . ' · ' . $scopeValue;
			$scopes[ $scopeKey ] = $scopeLabel;
			$usage               = is_array( $profile['usage'] ?? null ) ? $profile['usage'] : array(
				'available' => false,
				'total'     => null,
				'packages'  => array(),
			);
			$usageTotal          = ! empty( $usage['available'] ) ? (int) ( $usage['total'] ?? 0 ) : -1;
			$usageLabel          = ! empty( $usage['available'] )
				? sprintf( _n( '%d package', '%d packages', $usageTotal, 'ran-booster' ), $usageTotal )
				: __( 'Usage unavailable', 'ran-booster' );
			$healthLabel         = ! empty( $profile['configured'] )
				? (string) ( $profile['expiry_status']['badge_label'] ?? __( 'Stored · Validity checked on use', 'ran-booster' ) )
				: __( 'Not configured', 'ran-booster' );
			$statusKey           = ! empty( $profile['configured'] )
				&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'error' )
				&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'warning' ) ? 'ready' : 'attention';
			$kind                = is_string( $profile['kind'] ?? null ) ? $profile['kind'] : '';
			$label               = is_string( $profile['label'] ?? null ) ? $profile['label'] : '';
			$rows[]              = $profile + array(
				'profile_index'       => $profileIndex,
				'configuration_json'  => (string) wp_json_encode( $configuration ),
				'provider_expires_on' => is_string( $profile['expiry']['provider_expires_at'] ?? null ) ? substr( $profile['expiry']['provider_expires_at'], 0, 10 ) : '',
				'usage_listed'        => count( $usage['packages'] ),
				'kind_label'          => $kindLabels[ $kind ] ?? $kind,
				'configuration_label' => implode( ' · ', $summary ),
				'scope_key'           => $scopeKey,
				'scope_label'         => $scopeLabel,
				'usage_total'         => $usageTotal,
				'usage_label'         => $usageLabel,
				'health_label'        => $healthLabel,
				'status_key'          => $statusKey,
				'search_value'        => strtolower( implode( ' ', array_merge( array( $label, $kindLabels[ $kind ] ?? $kind, $scopeLabel, $healthLabel ), array_values( array_filter( $configuration, 'is_string' ) ) ) ) ),
			);
		}

		return array(
			'rows'   => $rows,
			'scopes' => $scopes,
		);
	}

	/** @param list<array<string,mixed>> $profiles @param array<string,string> $scopeLabels @return list<array<string,mixed>> */
	private function webhookRows( array $profiles, array $scopeLabels ): array {
		$rows = array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$usage              = is_array( $profile['usage'] ?? null ) ? $profile['usage'] : array(
				'available'    => false,
				'total'        => null,
				'repositories' => array(),
			);
			$usageTotal         = ! empty( $usage['available'] ) ? (int) ( $usage['total'] ?? 0 ) : -1;
			$usageLabel         = ! empty( $usage['available'] ) ? sprintf( _n( '%d package', '%d packages', $usageTotal, 'ran-booster' ), $usageTotal ) : __( 'Usage unavailable', 'ran-booster' );
			$scope              = is_string( $profile['scope'] ?? null ) ? $profile['scope'] : '';
			$label              = is_string( $profile['label'] ?? null ) ? $profile['label'] : '';
			$target             = is_string( $profile['target'] ?? null ) ? $profile['target'] : '';
			$health             = ! empty( $profile['configured'] ) ? __( 'Saved · Remote delivery not verified by Core', 'ran-booster' ) : __( 'Local secret not configured', 'ran-booster' );
			$deleteConfirmation = ! empty( $usage['available'] ) && 0 < $usageTotal
				? sprintf( _n( 'Remove this local secret? %d managed package may be affected. Remote provider webhooks will not be removed.', 'Remove this local secret? %d managed packages may be affected. Remote provider webhooks will not be removed.', $usageTotal, 'ran-booster' ), $usageTotal )
				: __( 'Remove this local secret? Remote provider webhooks will not be removed.', 'ran-booster' );
			$rows[]             = $profile + array(
				'scope_label'         => $scopeLabels[ $scope ] ?? ucfirst( $scope ),
				'usage_total'         => $usageTotal,
				'usage_label'         => $usageLabel,
				'health_label'        => $health,
				'delete_confirmation' => $deleteConfirmation,
				'status_key'          => ! empty( $profile['configured'] ) && ! empty( $usage['available'] ) ? 'ready' : 'attention',
				'search_value'        => strtolower( implode( ' ', array( $label, $scope, $target, $usageLabel, $health ) ) ),
			);
		}

		return $rows;
	}

	/** @param list<array<string,mixed>> $rows @param array<string,mixed> $state @return array{rows:list<array<string,mixed>>,total:int,pages:int,current:int} */
	private function filterAndPage( array $rows, string $view, array $state ): array {
		$search  = strtolower( trim( (string) $state['search'] ) );
		$rows    = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $state, $search, $view ): bool {
					if ( '' !== $search && ! str_contains( (string) ( $row['search_value'] ?? '' ), $search ) ) {
						return false;
					}
					if ( 'credentials' === $view ) {
						return ( '' === $state['kind'] || $state['kind'] === ( $row['kind'] ?? null ) )
						&& ( '' === $state['scope'] || $state['scope'] === ( $row['scope_key'] ?? null ) );
					}

					return ( '' === $state['scope'] || $state['scope'] === ( $row['scope'] ?? null ) )
					&& ( '' === $state['status'] || $state['status'] === ( $row['status_key'] ?? null ) );
				}
			)
		);
		$sortKey = match ( $state['orderby'] ) {
			'kind' => 'kind_label', 'scope' => 'scope_label', 'usage' => 'usage_total', 'health' => 'health_label', default => 'label' };
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $state, $sortKey ): int {
				$leftValue  = $left[ $sortKey ] ?? '';
				$rightValue = $right[ $sortKey ] ?? '';
				$comparison = is_int( $leftValue ) && is_int( $rightValue ) ? $leftValue <=> $rightValue : strnatcasecmp( (string) $leftValue, (string) $rightValue );

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
	private function needsAttention( array $rows ): bool {
		return array() !== array_filter( $rows, static fn ( array $row ): bool => 'attention' === ( $row['status_key'] ?? null ) );
	}

	/** @return array{credential:array{tone:string,heading:string,description:string},webhook:array{tone:string,heading:string,description:string}} */
	private function profileSummaries( bool $storageUnavailable, bool $credentialAttention, bool $webhookAttention, int $credentialCount, int $webhookCount, int $automaticCount ): array {
		$credentialProblem = $storageUnavailable || $credentialAttention;
		$webhookProblem    = $storageUnavailable || $webhookAttention || ( 0 === $webhookCount && 0 < $automaticCount );

		return array(
			'credential' => array(
				'tone'        => $credentialProblem ? 'attention' : ( 0 < $credentialCount ? 'ready' : 'pending' ),
				'heading'     => $credentialProblem ? __( 'Repository access needs attention', 'ran-booster' ) : ( 0 === $credentialCount ? __( 'No credential saved', 'ran-booster' ) : sprintf( _n( 'Ready · %d credential', 'Ready · %d credentials', $credentialCount, 'ran-booster' ), $credentialCount ) ),
				'description' => $storageUnavailable ? __( 'Restore encrypted credential storage before reviewing or changing saved repository access.', 'ran-booster' ) : ( $credentialAttention ? __( 'Review saved credentials that are incomplete, expired, or approaching expiry.', 'ran-booster' ) : ( 0 === $credentialCount ? __( 'Public repositories remain available through anonymous lookup. Add a credential only for private access or steadier API limits.', 'ran-booster' ) : __( 'Private repository access is available. Open credential management to validate, replace, or review usage.', 'ran-booster' ) ) ),
			),
			'webhook'    => array(
				'tone'        => $webhookProblem ? 'attention' : ( 0 < $webhookCount ? 'ready' : 'pending' ),
				'heading'     => $webhookProblem ? __( 'Webhook signing · Needs attention', 'ran-booster' ) : ( 0 === $webhookCount ? __( 'Webhook signing · No secret saved', 'ran-booster' ) : __( 'Webhook signing · Ready locally', 'ran-booster' ) ),
				'description' => $storageUnavailable ? __( 'Restore encrypted credential storage before Push-to-Deploy can verify signed deliveries.', 'ran-booster' ) : ( $webhookAttention ? __( 'Review saved signing material whose configuration or managed-package usage could not be confirmed.', 'ran-booster' ) : ( 0 === $webhookCount ? ( 0 < $automaticCount ? __( 'Automatic branch deployments require local signing material before provider webhooks can be used safely.', 'ran-booster' ) : __( 'Add local signing material before configuring a provider webhook.', 'ran-booster' ) ) : sprintf( _n( '%d local secret can verify signed deliveries. This does not prove a matching remote webhook exists.', '%d local secrets can verify signed deliveries. This does not prove matching remote webhooks exist.', $webhookCount, 'ran-booster' ), $webhookCount ) ) ),
			),
		);
	}




	/**
	 * @param callable(array<string,mixed>):string $providerUrl
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $credentials
	 * @param array<string,mixed> $secrets
	 * @return array<string,array<string,mixed>>
	 */
	private function listUrls( callable $providerUrl, string $providerCode, array $state, array $credentials, array $secrets ): array {
		$result = array();
		foreach ( array(
			'credentials' => $credentials,
			'secrets'     => $secrets,
		) as $view => $list ) {
			$sort = array();
			foreach ( array( 'name', 'kind', 'scope', 'usage', 'health' ) as $orderby ) {
				$order            = $state['orderby'] === $orderby && 'asc' === $state['order'] ? 'desc' : 'asc';
				$sort[ $orderby ] = $providerUrl(
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
			$pageUrl         = static fn ( int $page ): string => $providerUrl(
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
					'item_count_label' => sprintf( _n( '%d item', '%d items', $list['total'], 'ran-booster' ), $list['total'] ),
					'page_label'       => sprintf( __( 'Page %1$d of %2$d', 'ran-booster' ), $list['current'], $list['pages'] ),
					'current'          => $list['current'],
					'pages'            => $list['pages'],
					'per_page'         => $state['per_page'],
					'action_url'       => admin_url( 'admin.php' ),
					'hidden_fields'    => array_filter(
						array(
							'page'    => 'ran-booster',
							'tab'     => $providerCode,
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
					'previous_url'     => $pageUrl( $list['current'] - 1 ),
					'next_url'         => $pageUrl( $list['current'] + 1 ),
				),
			);
		}

		return $result;
	}

	/** @return array<string,string> */
	private function profileCopy( string $label ): array {
		return array(
			'providerBackLabel'               => sprintf( __( 'Back to %s overview', 'ran-booster' ), $label ),
			'credentialManagementDescription' => sprintf( __( 'Manage saved credentials used for %s repository access.', 'ran-booster' ), $label ),
			'secretManagementDescription'     => sprintf( __( 'Manage local signing material used to verify %s webhook deliveries.', 'ran-booster' ), $label ),
		);
	}

	// phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment
}

<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use Closure;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryPathInspector;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer as WebhookNormalizerContract;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryReleaseWorkflow;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RuntimeException;

final readonly class GitHubProvider implements RepositoryProvider, RepositoryPathInspector, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizerContract, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink, RepositoryWebhookFitness, RepositoryWebhookManagement, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseNativeTargets, RepositoryReleaseWorkflowManagement {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;

	private const RELEASE_PREFLIGHT_CLASS       = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseCandidatePreflight';
	private const RELEASE_PREFLIGHT_API_VERSION = 4;
	private const RELEASE_FINGERPRINT_CLASS     = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseFingerprint';

	private ProviderMetadata $metadata;

	private ProviderCredentialStore $credentials;

	private RepositoryBrowser $browser;
	private RepositoryWebhookClient $webhookClient;
	private WebhookNormalizer $webhooks;
	private Diagnostics $diagnostics;
	private CredentialPolicy $credentialPolicy;
	private GitHubRepositoryReleaseWorkflow $releaseWorkflow;

	public static function create( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence ): RepositoryProvider {
		return new self(
			$credentials,
			new RepositoryBrowser( $credentials ),
			new WebhookNormalizer( $credentials, $deliveryEvidence ),
			new RepositoryWebhookClient()
		);
	}

	public static function legacyAssistedHooksAddOnIsActive(): bool {
		$retirementBridge = defined( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION' )
			&& 1 === constant( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION' );

		return class_exists( 'RAN\AssistedHooks\Plugin', false ) && ! $retirementBridge;
	}

	public static function registerLegacyAssistedHooksAddOnNotice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html__( 'Bundled GitHub webhook management is inactive because a pre-retirement RAN Booster Assisted Hooks release is active. Deactivate that add-on to use the bundled feature.', 'ran-booster' )
				);
			}
		);
	}

	private function __construct( ProviderCredentialStore $credentials, RepositoryBrowser $browser, WebhookNormalizer $webhooks, RepositoryWebhookClient $webhookClient ) {
		$this->credentials      = $credentials;
		$this->browser          = $browser;
		$this->webhooks         = $webhooks;
		$this->webhookClient    = $webhookClient;
		$this->diagnostics      = new Diagnostics( $browser );
		$this->credentialPolicy = new CredentialPolicy();
		$workflowRecords        = new SetupRecordStore();
		$this->releaseWorkflow  = new GitHubRepositoryReleaseWorkflow(
			$credentials,
			new WorkflowApplicationCoordinator(
				new GitHubRepositoryClient(),
				new TemplatePackRepositoryClient(),
				new SourceReadyAssessor(),
				$workflowRecords
			),
			$workflowRecords
		);
		$this->metadata         = new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner',
			new ProviderAdminMetadata(
				array(
					new CredentialKindMetadata(
						'classic',
						'Classic personal access token',
						'Personal access token',
						'ghp_...',
						array(),
						'Classic PAT'
					),
					new CredentialKindMetadata(
						'fine-grained',
						'Fine-grained personal access token',
						'Personal access token',
						'github_pat_...',
						array(
							new CredentialFieldMetadata(
								'owner',
								'Resource owner',
								'text',
								true,
								'organization-or-user',
								'Enter the GitHub username or organization selected as the token resource owner, not an email address.'
							),
						),
						'Fine-grained PAT'
					),
				),
				array(
					new WebhookScopeMetadata(
						'owner',
						'GitHub owner',
						true,
						'Owner',
						'organization-or-user',
						'Use this secret for repositories belonging to one organization or user.',
						true
					),
					new WebhookScopeMetadata(
						'repository',
						'GitHub repository',
						true,
						'Repository',
						'organization-or-user/repository',
						'Use this secret only for one repository.'
					),
				),
				new ProviderSetupMetadata(
					'Public repositories need no token. For private repository browsing and archive reads, prefer a fine-grained personal access token: choose the resource owner, select only the repositories this site needs, and set Repository permissions → Contents to Read-only (Metadata: Read-only is automatic). A fine-grained token is limited to one user or organisation, so select the project repositories once and use its saved Booster profile for the packages that need it. Booster does not change that GitHub repository selection. Repository webhook management is different: classic tokens need admin:repo_hook, while fine-grained tokens need Repository permissions → Webhooks: Read and write. Release-workflow automation writes repository files and needs Contents: Read and write, Workflows: Read and write, and Pull requests: Read and write. Keep those elevated capabilities on a separate saved credential from ordinary read access where possible. A classic personal access token with repo scope is inherently broad and cannot be limited to selected repositories or read-only access. For organisation repositories, the token owner also needs repository access and any required SSO authorisation or organisation approval.',
					array(
						array(
							'label' => 'Create and manage GitHub personal access tokens',
							'url'   => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
						),
						array(
							'label' => 'Required fine-grained token permissions',
							'url'   => 'https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens',
						),
						array(
							'label' => 'Organisation token policies and approval',
							'url'   => 'https://docs.github.com/en/organizations/managing-programmatic-access-to-your-organization/setting-a-personal-access-token-policy-for-your-organization',
						),
					),
					'Repository Settings → Webhooks → Add webhook',
					'Just the push event',
					'https://docs.github.com/en/webhooks/using-webhooks/creating-webhooks#creating-a-repository-webhook',
					'https://docs.github.com/en/webhooks/testing-and-troubleshooting-webhooks/viewing-webhook-deliveries'
				),
				new ProviderNavigationPlacement(
					ProviderNavigationPlacement::GIT_HOST,
					100
				),
				'owner/repository'
			)
		);
	}

	public function getMetadata(): ProviderMetadata {
		return $this->metadata;
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function workflowStatus( ReleaseTrackingStatus $status ): RepositoryReleaseWorkflowStatus {
		return $this->releaseWorkflow->status( $status );
	}

	public function workflowPreview( ReleaseTrackingStatus $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		return $this->releaseWorkflow->preview( $status, $key );
	}

	public function workflowInspect( ReleaseTrackingStatus $status, string $channel, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		return $this->releaseWorkflow->inspect( $status, $channel, $preflight, $credentialId );
	}

	public function workflowSetup( ReleaseTrackingStatus $status, string $key, string $confirmation, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		return $this->releaseWorkflow->setup( $status, $key, $confirmation, $preflight, $credentialId );
	}

	public function workflowOutcome( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		return $this->releaseWorkflow->outcome( $status, $credentialId );
	}

	public function workflowInspectUpdate( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		return $this->releaseWorkflow->inspectUpdate( $status, $credentialId );
	}

	public function workflowSetupUpdate( ReleaseTrackingStatus $status, string $key, string $confirmation, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		return $this->releaseWorkflow->setupUpdate( $status, $key, $confirmation, $credentialId );
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return $this->credentialPolicy;
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return $this->webhooks->getWebhookPolicy();
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		return $this->webhooks->diagnoseWebhookReadiness();
	}

	public function validateCredential( string $credentialId ): CredentialValidationResult {
		return $this->browser->validateCredential( $credentialId );
	}

	public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		return $this->browser->browse( $request );
	}

	public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->browser->repository( $request->locator, $request->credentialId );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		$repository = $request->repository;

		$ref            = $request->ref;
		$expectedBranch = $request->expectedBranch;
		$repositoryId   = $repository->providerRepositoryId;

		if ( null === $repositoryId ) {
			throw new RuntimeException( 'The managed GitHub repository does not have a stable provider identity.', 409 );
		}

		if ( null !== $expectedBranch ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
				throw new RuntimeException( 'The GitHub deployment event does not contain a valid commit.', 400 );
			}
			$ref  = strtolower( $ref );
			$head = $this->browser->branchHead(
				$repository->locator,
				$expectedBranch,
				$repositoryId,
				$repository->credentialId,
				$repository->private
			);

			if ( ! hash_equals( $ref, $head ) ) {
				throw new StaleDeployment( 'The GitHub deployment event is stale because the configured branch has moved.', 409 );
			}
		} else {
			$ref = $this->browser->immutableRef(
				$repository->locator,
				$ref,
				$repositoryId,
				$repository->credentialId,
				$repository->private
			);
		}

		$url = 'https://api.github.com/repos/'
			. $this->encodeRepositoryName( $repository->locator )
			. '/zipball/'
			. rawurlencode( $ref );

		$headVerifier = null;
		if ( null !== $expectedBranch ) {
			$headVerifier = function () use ( $repository, $expectedBranch, $ref ): void {
				$head = $this->browser->currentBranchHead(
					$repository->locator,
					$expectedBranch,
					$repository->credentialId,
					$repository->private
				);

				if ( ! hash_equals( $ref, $head ) ) {
					throw new StaleDeployment( 'The GitHub deployment event is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new AuthenticatedPreparedArchive(
			$url,
			$ref,
			$this->archiveAuthorizer( $repository ),
			$headVerifier
		);
	}

	public function repositoryPathExists( RepositoryReference $repository, string $ref, string $path ): bool {
		return $this->browser->pathExists(
			$repository->locator,
			$ref,
			$path,
			$repository->credentialId,
			$repository->private
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return $this->webhooks->normalizeWebhook( $request );
	}

	public function repositoryWebhookSettingsUrl( string $locator ): string {
		return 'https://github.com/' . $this->encodeRepositoryName( $locator ) . '/settings/hooks';
	}

	public function expectedUpdateUri( RepositoryReference $repository ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}\z/D', $repository->locator ) ) {
			return '';
		}

		return 'https://github.com/' . $this->encodeRepositoryName( $repository->locator );
	}

	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
		$updateUri = $this->expectedUpdateUri( $repository );
		if ( '' === $updateUri || '' === $tag || strlen( $tag ) > 100 ) {
			return '';
		}

		return $updateUri . '/releases/tag/' . rawurlencode( $tag );
	}

	public function hasRegisteredNativeTarget( string $packageType, string $installedIdentifier ): bool {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| '' === $installedIdentifier ) {
			return false;
		}
		$signal = 'ran_wp_github_release_updater_v1_has_registered_target';
		if ( ! function_exists( $signal ) ) {
			return false;
		}

		$identity = strtolower( str_replace( '\\', '/', $installedIdentifier ) );

		return true === $signal( $packageType, 'plugin' === $packageType ? ltrim( $identity, '/' ) : $identity );
	}

	public function createNativeTarget(
		string $packageType,
		RepositoryReference $repository,
		string $metadataFile,
		string $packageRoot,
		string $installedIdentifier,
		string $channel,
		string $deploymentPolicy
	): RepositoryReleaseNativeTarget {
		$repositoryId = $repository->providerRepositoryId;
		if ( '' === $this->expectedUpdateUri( $repository ) || null === $repositoryId ) {
			throw new RuntimeException( 'The GitHub release native target repository is invalid.' );
		}

		return new GitHubReleaseNativeTarget(
			$packageType,
			$metadataFile,
			$repository->locator,
			$repositoryId,
			$packageRoot,
			$installedIdentifier,
			$this->releaseAccessToken( $repository ),
			$channel,
			$deploymentPolicy
		);
	}

	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		$preflightClass = self::RELEASE_PREFLIGHT_CLASS;
		$repositoryId   = $repository->providerRepositoryId;
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| null === $repositoryId
			|| 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $repositoryId )
			|| ! class_exists( $preflightClass )
			|| ! defined( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| self::RELEASE_PREFLIGHT_API_VERSION !== constant( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| ! method_exists( $preflightClass, 'fromProspectiveTarget' ) ) {
			throw new RuntimeException( 'GitHub release candidate listing is unavailable.', 503 );
		}

		$preflight = $preflightClass::fromProspectiveTarget(
			array(
				'repository'           => $repository->locator,
				'providerRepositoryId' => $repositoryId,
				'channel'              => $channel,
				'accessToken'          => $this->releaseAccessToken( $repository ),
				'packageType'          => $packageType,
			)
		);
		if ( $preflight instanceof \WP_Error && $this->isReleaseReadUnavailable( $preflight ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release candidate access is unavailable.', 503 );
		}
		if ( $preflight instanceof \WP_Error || ! is_callable( array( $preflight, 'listCandidates' ) ) ) {
			throw new RuntimeException( 'GitHub release candidate listing is unavailable.', 503 );
		}
		$releases = $preflight->listCandidates();
		if ( $releases instanceof \WP_Error ) {
			if ( 'github_updater_no_eligible_release' === $releases->get_error_code() ) {
				return new RepositoryReleaseCandidateList( array() );
			}
			if ( $this->isReleaseReadUnavailable( $releases ) ) {
				throw new RepositoryReleaseReadUnavailable( 'GitHub release candidate access is unavailable.', 502 );
			}

			throw new RuntimeException( 'GitHub could not list release candidates.', 502 );
		}
		if ( ! is_array( $releases ) || ! array_is_list( $releases ) || count( $releases ) > 8 ) {
			throw new RuntimeException( 'GitHub returned invalid release candidates.', 502 );
		}

		$candidates = array();
		foreach ( $releases as $release ) {
			if ( ! is_object( $release )
				|| ! is_callable( array( $release, 'releaseId' ) )
				|| ! is_callable( array( $release, 'tag' ) )
				|| ! is_callable( array( $release, 'version' ) )
				|| ! is_callable( array( $release, 'isPrerelease' ) )
				|| ! is_callable( array( $release, 'publishedAt' ) )
				|| ! is_callable( array( $release, 'expectedAssetNames' ) ) ) {
				throw new RuntimeException( 'GitHub returned invalid release candidates.', 502 );
			}
			$releaseId          = $release->releaseId();
			$tag                = $release->tag();
			$version            = $release->version();
			$prerelease         = $release->isPrerelease();
			$publishedAt        = $release->publishedAt();
			$expectedAssetNames = $release->expectedAssetNames();
			if ( ! is_int( $releaseId )
				|| ! is_string( $tag )
				|| ! is_string( $version )
				|| ! is_bool( $prerelease )
				|| ! is_string( $publishedAt )
				|| ! is_array( $expectedAssetNames ) ) {
				throw new RuntimeException( 'GitHub returned invalid release candidates.', 502 );
			}
			$candidates[] = new RepositoryReleaseCandidate(
				(string) $releaseId,
				$tag,
				$version,
				$prerelease,
				$publishedAt,
				$expectedAssetNames
			);
		}

		return new RepositoryReleaseCandidateList( $candidates );
	}

	public function inspectRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		$releaseId = filter_var(
			$providerReleaseId,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $providerReleaseId )
			|| false === $releaseId
			|| 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag ) ) {
			throw RepositoryReleaseInspectionRejected::invalidRelease();
		}

		$preflightClass = self::RELEASE_PREFLIGHT_CLASS;
		$repositoryId   = $repository->providerRepositoryId;
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| null === $repositoryId
			|| 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $repositoryId )
			|| ! class_exists( $preflightClass )
			|| ! defined( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| self::RELEASE_PREFLIGHT_API_VERSION !== constant( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| ! method_exists( $preflightClass, 'fromProspectiveTarget' ) ) {
			throw new RuntimeException( 'GitHub release inspection is unavailable.', 503 );
		}

		$preflight = $preflightClass::fromProspectiveTarget(
			array(
				'repository'           => $repository->locator,
				'providerRepositoryId' => $repositoryId,
				'channel'              => $channel,
				'accessToken'          => $this->releaseAccessToken( $repository ),
				'packageType'          => $packageType,
			)
		);
		if ( $preflight instanceof \WP_Error && $this->isReleaseReadUnavailable( $preflight ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release inspection access is unavailable.', 503 );
		}
		if ( $preflight instanceof \WP_Error || ! is_callable( array( $preflight, 'inspectExact' ) ) ) {
			throw new RuntimeException( 'GitHub release inspection is unavailable.', 503 );
		}

		$inspection = $preflight->inspectExact( $releaseId, $tag );
		if ( $inspection instanceof \WP_Error ) {
			$this->rejectReleaseInspection( $inspection );
		}

		try {
			if ( ! is_object( $inspection )
				|| ! is_callable( array( $inspection, 'releaseId' ) )
				|| ! is_callable( array( $inspection, 'tag' ) )
				|| ! is_callable( array( $inspection, 'version' ) )
				|| ! is_callable( array( $inspection, 'commit' ) )
				|| ! is_callable( array( $inspection, 'packageType' ) )
				|| ! is_callable( array( $inspection, 'packageRoot' ) )
				|| ! is_callable( array( $inspection, 'mainFile' ) )
				|| ! is_callable( array( $inspection, 'fingerprint' ) ) ) {
				throw new RuntimeException();
			}
			$fingerprint = $inspection->fingerprint();
			if ( ! is_object( $fingerprint ) || ! is_callable( array( $fingerprint, 'value' ) ) ) {
				throw new RuntimeException();
			}
			$inspectedReleaseId = $inspection->releaseId();
			$inspectedTag       = $inspection->tag();
			$version            = $inspection->version();
			$commit             = $inspection->commit();
			$inspectedType      = $inspection->packageType();
			$packageRoot        = $inspection->packageRoot();
			$mainFile           = $inspection->mainFile();
			$fingerprintValue   = $fingerprint->value();
			if ( ! is_int( $inspectedReleaseId )
				|| $releaseId !== $inspectedReleaseId
				|| ! is_string( $inspectedTag )
				|| ! hash_equals( $tag, $inspectedTag )
				|| ! is_string( $version )
				|| ! is_string( $commit )
				|| 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $commit )
				|| ! is_string( $inspectedType )
				|| ! hash_equals( $packageType, $inspectedType )
				|| ! is_string( $packageRoot )
				|| ! is_string( $mainFile )
				|| ! is_string( $fingerprintValue )
				|| 1 !== preg_match( '/\Av1:[0-9a-f]{64}\z/D', $fingerprintValue ) ) {
				throw new RuntimeException();
			}

			return new RepositoryReleaseInspection(
				(string) $inspectedReleaseId,
				$inspectedTag,
				$version,
				$commit,
				$packageRoot,
				$mainFile,
				$fingerprintValue
			);
		} catch ( \Throwable ) {
			throw new RuntimeException( 'GitHub returned invalid release inspection evidence.', 502 );
		}
	}

	public function acquireRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		$releaseId = filter_var(
			$providerReleaseId,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $providerReleaseId )
			|| false === $releaseId
			|| 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag )
			|| 1 !== preg_match( '/\Av1:[a-f0-9]{64}\z/D', $expectedFingerprint ) ) {
			throw RepositoryReleaseAcquisitionRejected::invalidRelease();
		}

		$preflightClass   = self::RELEASE_PREFLIGHT_CLASS;
		$fingerprintClass = self::RELEASE_FINGERPRINT_CLASS;
		$repositoryId     = $repository->providerRepositoryId;
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| null === $repositoryId
			|| 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $repositoryId )
			|| ! class_exists( $preflightClass )
			|| ! defined( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| self::RELEASE_PREFLIGHT_API_VERSION !== constant( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| ! method_exists( $preflightClass, 'fromProspectiveTarget' )
			|| ! class_exists( $fingerprintClass )
			|| ! method_exists( $fingerprintClass, 'fromString' ) ) {
			throw new RuntimeException( 'GitHub release acquisition is unavailable.', 503 );
		}

		$preflight = $preflightClass::fromProspectiveTarget(
			array(
				'repository'           => $repository->locator,
				'providerRepositoryId' => $repositoryId,
				'channel'              => $channel,
				'accessToken'          => $this->releaseAccessToken( $repository ),
				'packageType'          => $packageType,
			)
		);
		if ( $preflight instanceof \WP_Error || ! is_callable( array( $preflight, 'acquireExact' ) ) ) {
			throw new RuntimeException( 'GitHub release acquisition is unavailable.', 503 );
		}

		$fingerprint = $fingerprintClass::fromString( $expectedFingerprint );
		if ( $fingerprint instanceof \WP_Error ) {
			throw RepositoryReleaseAcquisitionRejected::invalidRelease();
		}
		$validated = $preflight->acquireExact( $releaseId, $tag, $fingerprint );
		if ( $validated instanceof \WP_Error ) {
			$this->rejectReleaseAcquisition( $validated );
		}

		try {
			if ( ! is_object( $validated )
				|| ! is_callable( array( $validated, 'inspection' ) )
				|| ! is_callable( array( $validated, 'discard' ) )
				|| ! is_callable( array( $validated, 'handoffToCore' ) ) ) {
				throw new RuntimeException();
			}
			$inspection = $validated->inspection();
			if ( ! is_object( $inspection )
				|| ! is_callable( array( $inspection, 'releaseId' ) )
				|| ! is_callable( array( $inspection, 'tag' ) )
				|| ! is_callable( array( $inspection, 'version' ) )
				|| ! is_callable( array( $inspection, 'commit' ) )
				|| ! is_callable( array( $inspection, 'packageType' ) )
				|| ! is_callable( array( $inspection, 'packageRoot' ) )
				|| ! is_callable( array( $inspection, 'mainFile' ) )
				|| ! is_callable( array( $inspection, 'fingerprint' ) ) ) {
				throw new RuntimeException();
			}
			$inspectedFingerprint = $inspection->fingerprint();
			if ( ! is_object( $inspectedFingerprint ) || ! is_callable( array( $inspectedFingerprint, 'value' ) ) ) {
				throw new RuntimeException();
			}

			$inspectedReleaseId = $inspection->releaseId();
			$inspectedTag       = $inspection->tag();
			$version            = $inspection->version();
			$commit             = $inspection->commit();
			$inspectedType      = $inspection->packageType();
			$packageRoot        = $inspection->packageRoot();
			$mainFile           = $inspection->mainFile();
			$fingerprintValue   = $inspectedFingerprint->value();
			if ( ! is_int( $inspectedReleaseId )
				|| $releaseId !== $inspectedReleaseId
				|| ! is_string( $inspectedTag )
				|| ! hash_equals( $tag, $inspectedTag )
				|| ! is_string( $version )
				|| ! is_string( $commit )
				|| 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $commit )
				|| ! is_string( $inspectedType )
				|| ! hash_equals( $packageType, $inspectedType )
				|| ! is_string( $packageRoot )
				|| ! is_string( $mainFile )
				|| ! is_string( $fingerprintValue )
				|| ! hash_equals( $expectedFingerprint, $fingerprintValue ) ) {
				throw new RuntimeException();
			}

			return new GitHubReleaseArtifact(
				$validated,
				$version,
				$commit,
				$packageRoot,
				$mainFile
			);
		} catch ( \Throwable ) {
			$cleanupFailed = ! is_object( $validated ) || ! is_callable( array( $validated, 'discard' ) );
			if ( is_object( $validated ) && is_callable( array( $validated, 'discard' ) ) ) {
				try {
					$cleanupFailed = true !== $validated->discard();
				} catch ( \Throwable ) {
					$cleanupFailed = true;
				}
			}
			if ( $cleanupFailed ) {
				throw RepositoryReleaseAcquisitionRejected::cleanupFailed();
			}
			throw new RuntimeException( 'GitHub returned invalid release acquisition evidence.', 502 );
		}
	}

	public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId ): RepositoryWebhookFitnessResult {
		return $this->webhookClient->assessSetup( $repositoryId, $repository, $this->credential( $credentialProfileId ) );
	}

	public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		$this->assertHookId( $hookId );

		return $this->webhookClient->assessCheck( $repositoryId, $repository, $this->credential( $credentialProfileId ) );
	}

	public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		$this->assertHookId( $hookId );

		return $this->webhookClient->assessReconfigure( $repositoryId, $repository, $this->credential( $credentialProfileId ) );
	}

	public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		$this->assertHookId( $hookId );

		return $this->webhookClient->assessRemove( $repositoryId, $repository, $this->credential( $credentialProfileId ) );
	}

	public function assessTest( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		$this->assertHookId( $hookId );

		return $this->webhookClient->assessTest( $repositoryId, $repository, $this->credential( $credentialProfileId ) );
	}

	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret ): RepositoryWebhookOperationResult {
		$this->assertRepositoryId( $repositoryId );

		return $this->webhookClient->setup( $repository, $callbackUrl, $this->credential( $credentialProfileId ), $signingSecret );
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		$this->assertRepositoryId( $repositoryId );

		return $this->webhookClient->check( $repository, $hookId, $callbackUrl, $this->credential( $credentialProfileId ) );
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, #[\SensitiveParameter] string $signingSecret ): RepositoryWebhookOperationResult {
		$this->assertRepositoryId( $repositoryId );

		return $this->webhookClient->reconfigure( $repository, $hookId, $callbackUrl, $this->credential( $credentialProfileId ), $signingSecret );
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		$this->assertRepositoryId( $repositoryId );

		return $this->webhookClient->remove( $repository, $hookId, $callbackUrl, $this->credential( $credentialProfileId ) );
	}

	public function test( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		$this->assertRepositoryId( $repositoryId );
		$this->assertHookId( $hookId );

		return $this->webhookClient->test( $repository, $hookId, $callbackUrl, $this->credential( $credentialProfileId ) );
	}

	private function credential( ?string $credentialProfileId ): string {
		$credentialProfileId = null === $credentialProfileId ? null : trim( $credentialProfileId );
		if ( null === $credentialProfileId || '' === $credentialProfileId ) {
			throw new RuntimeException( 'Choose a saved GitHub credential.', 400 );
		}

		$material = $this->credentials->credentialMaterial( $credentialProfileId );
		$secret   = is_array( $material ) && is_string( $material['secret'] ?? null ) ? trim( $material['secret'] ) : '';
		if ( '' === $secret ) {
			throw new RuntimeException( 'The selected GitHub credential is unavailable.', 400 );
		}

		return $secret;
	}

	private function assertRepositoryId( string $repositoryId ): void {
		if ( '' === trim( $repositoryId ) || strlen( $repositoryId ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repositoryId ) ) {
			throw new RuntimeException( 'The GitHub repository identity is invalid.', 400 );
		}
	}

	private function assertHookId( string $hookId ): void {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $hookId ) ) {
			throw new RuntimeException( 'The GitHub hook identity is invalid.', 400 );
		}
	}

	private function encodeRepositoryName( string $fullName ): string {
		$parts = explode( '/', $fullName );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			throw new RuntimeException( 'GitHub repository names must use the owner/repository form.' );
		}

		return rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}

	private function archiveAuthorizer( RepositoryReference $repository ): ?Closure {
		if ( ! $repository->private ) {
			return null;
		}

		$credentialId = $repository->credentialId;
		$credential   = $this->credentials->credentialMaterial( $credentialId );
		$token        = is_array( $credential ) ? $credential['secret'] : '';

		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			if ( null !== $credentialId ) {
				throw new RuntimeException( 'The selected GitHub credential is not configured.' );
			}

			throw new RuntimeException( 'RAN_BOOSTER_GITHUB_TOKEN or the Booster secrets file is not configured.' );
		}

		$authorization = 'Bearer ' . trim( $token );

		return static function ( array $arguments ) use ( $authorization ): array {
			$arguments['headers']['Authorization'] = $authorization;

			return $arguments;
		};
	}

	private function releaseAccessToken( RepositoryReference $repository ): ?Closure {
		if ( ! $repository->private && null === $repository->credentialId ) {
			return null;
		}

		return function () use ( $repository ): string {
			$credential = $this->credentials->credentialMaterial( $repository->credentialId );
			$token      = is_array( $credential ) ? $credential['secret'] ?? null : null;
			if ( ! is_string( $token ) || '' === trim( $token ) ) {
				throw new RuntimeException( 'The selected GitHub credential is unavailable.', 400 );
			}

			return trim( $token );
		};
	}

	private function rejectReleaseInspection( \WP_Error $failure ): never {
		$code = $failure->get_error_code();
		if ( 'github_updater_no_eligible_release' === $code ) {
			throw RepositoryReleaseInspectionRejected::noReleases();
		}

		if ( 'github_updater_release_incompatible' === $code ) {
			throw RepositoryReleaseInspectionRejected::incompatible();
		}

		if ( $this->isInvalidReleaseFailureCode( $code ) ) {
			throw RepositoryReleaseInspectionRejected::invalidRelease();
		}
		if ( $this->isReleaseReadUnavailable( $failure ) ) {
			throw new RepositoryReleaseReadUnavailable( 'GitHub release inspection access is unavailable.', 502 );
		}

		throw new RuntimeException( 'GitHub could not inspect the selected release.', 502 );
	}

	private function isReleaseReadUnavailable( \WP_Error $failure ): bool {
		$code = $failure->get_error_code();
		if ( in_array(
			$code,
			array(
				'github_updater_github_authentication_failed',
				'github_updater_github_forbidden',
				'github_updater_credentials_unavailable',
				'github_updater_invalid_access_token',
				'github_updater_rate_limited',
				'github_updater_http_transport_failed',
			),
			true
		) ) {
			return true;
		}

		$data = $failure->get_error_data();

		return 'github_updater_github_http_error' === $code
			&& is_array( $data )
			&& 404 === (int) ( $data['status'] ?? 0 );
	}

	private function rejectReleaseAcquisition( \WP_Error $failure ): never {
		$code = $failure->get_error_code();
		if ( 'github_updater_invalid_release_fingerprint' === $code
			|| $this->isInvalidReleaseFailureCode( $code ) ) {
			throw RepositoryReleaseAcquisitionRejected::invalidRelease();
		}

		throw new RuntimeException( 'GitHub could not acquire the selected release.', 502 );
	}

	private function isInvalidReleaseFailureCode( string $code ): bool {
		return in_array(
			$code,
			array(
				'github_updater_ambiguous_release_asset',
				'github_updater_artifact_continuity_failed',
				'github_updater_downloaded_digest_mismatch',
				'github_updater_invalid_release',
				'github_updater_invalid_release_asset',
				'github_updater_invalid_release_id',
				'github_updater_invalid_release_tag',
				'github_updater_invalid_release_url',
				'github_updater_invalid_tag_commit',
				'github_updater_missing_asset_digest',
				'github_updater_prerelease_not_allowed',
				'github_updater_release_asset_too_large',
				'github_updater_release_assurance_rejected',
				'github_updater_release_changed',
				'github_updater_release_incompatible',
				'github_updater_release_is_draft',
				'github_updater_release_version_mismatch',
				'github_updater_repository_identity_changed',
				'package_archive_entry_duplicate',
				'package_archive_entry_limit',
				'package_archive_path_duplicate',
				'package_archive_path_unsafe',
				'package_archive_root_invalid',
				'package_archive_size_invalid',
				'package_archive_too_large',
				'package_archive_unreadable',
				'package_compatibility_invalid',
				'package_compatibility_missing',
				'package_header_ambiguous',
				'package_header_invalid',
				'package_header_missing',
				'package_update_uri_invalid',
				'package_update_uri_missing',
				'release_version_invalid',
				'release_version_mismatch',
			),
			true
		);
	}
}

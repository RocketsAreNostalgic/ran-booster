<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\ProviderWebhookAssistanceMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\Secrets\SecretsFile;
use RuntimeException;

final readonly class GitHubProvider implements RepositoryProvider, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizer, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink {

	private ProviderMetadata $metadata;

	private SecretsFile $secrets;

	private GitHubRepositoryBrowser $browser;
	private GitHubWebhookNormalizer $webhooks;
	private GitHubDiagnostics $diagnostics;
	private GitHubCredentialPolicy $credentialPolicy;

	public function __construct( SecretsFile $secrets, GitHubRepositoryBrowser $browser, GitHubWebhookNormalizer $webhooks ) {
		$this->secrets          = $secrets;
		$this->browser          = $browser;
		$this->webhooks         = $webhooks;
		$this->diagnostics      = new GitHubDiagnostics( $browser );
		$this->credentialPolicy = new GitHubCredentialPolicy();
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
						'ghp_...'
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
								'The GitHub organization or user selected as the token resource owner.'
							),
						)
					),
				),
				array(
					new WebhookScopeMetadata(
						'owner',
						'GitHub owner',
						true,
						'Owner',
						'organization-or-user',
						'Use this secret for repositories belonging to one organization or user.'
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
					'Public repositories need no token. For private repositories, prefer a fine-grained personal access token: choose the resource owner, select only the repositories this site needs, and set Repository permissions → Contents to Read-only (Metadata: Read-only is automatic). A fine-grained token is limited to one user or organisation: select the project repositories once, then use its saved Booster profile for the packages that need it. Booster does not change that GitHub repository selection. A classic personal access token needs the repo scope and no other scope; it is inherently broad and cannot be limited to selected repositories or read-only access. Booster does not need admin:repo_hook or any other webhook permission. For organisation repositories, the token owner also needs repository access and any required SSO authorisation or organisation approval.',
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
					ProviderNavigationPlacement::GITHUB_SLOT
				),
				'owner/repository',
				new ProviderWebhookAssistanceMetadata(
					'Assisted Hooks',
					'Assisted Hooks add-on not active.',
					'Webhooks remain available through GitHub. Activating the compatible add-on adds repository-level provisioning here.',
					'Assisted Hooks is active.',
					'Repository status and assisted configuration actions are available below.'
				)
			)
		);
	}

	public function getMetadata(): ProviderMetadata {
		return $this->metadata;
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
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

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return $this->webhooks->normalizeWebhook( $request );
	}

	public function repositoryWebhookSettingsUrl( string $locator ): string {
		return 'https://github.com/' . $this->encodeRepositoryName( $locator ) . '/settings/hooks';
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
		$credential   = $this->secrets->credentialMaterial( ProviderCode::parse( 'gh' ), $credentialId );
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
}

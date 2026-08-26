<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\PreparedArchive as PreparedArchiveContract;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RuntimeException;

final readonly class Provider implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialValidator, WebhookNormalizer, RepositoryWebhookFitness, RepositoryWebhookManagement {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;

	private ProviderCode $code;
	private Client $client;
	private CredentialPolicy $credentialPolicy;
	private WebhookPolicy $webhookPolicy;
	private Diagnostics $diagnostics;

	public function __construct(
		private ProviderCredentialStore $credentials,
		private AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
	) {
		$this->code             = ProviderCode::parse( 'fixture-provider' );
		$this->client           = new Client();
		$this->credentialPolicy = new CredentialPolicy();
		$this->webhookPolicy    = new WebhookPolicy();
		$this->diagnostics      = new Diagnostics( $this->client, $credentials );
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			$this->code,
			'Fixture provider',
			'https://fixtures.example.test/',
			'Fixture namespace',
			new ProviderAdminMetadata(
				array(
					new CredentialKindMetadata(
						'api-key',
						'Fixture API key',
						'API key',
						'',
						array( new CredentialFieldMetadata( 'tenant', 'Tenant', 'text', true ) )
					),
				),
				array()
			)
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return $this->credentialPolicy;
	}

	public function validateCredential( string $credentialId ): CredentialValidationResult {
		return $this->client->validateCredential( $this->credentials->credentialMaterial( $credentialId ) )
			? CredentialValidationResult::valid()
			: CredentialValidationResult::invalid();
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return $this->webhookPolicy;
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::NOT_CONFIGURED,
			'fixture-provider.webhook.not_configured',
			'Fixture webhook readiness is not configured.',
			'Configure a fixture webhook before sending a fixture delivery.'
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		if ( ! $request->getProvider()->equals( $this->code ) ) {
			throw new WebhookRejected( 400, 'Webhook provider does not match fixture provider.' );
		}

		$request->requireVerification();

		return 'ping' === $request->getHeader( 'x-fixture-event' )
			? WebhookEnvelope::probe()
			: WebhookEnvelope::ignored();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$credentialId = $request->credentialId;
		if ( null !== $credentialId && ! $this->validateCredential( $credentialId )->isValid() ) {
			throw new RuntimeException( 'The fixture credential is unavailable.' );
		}

		$repository = $this->client->repository( $request->locator );

		return new RepositoryDescriptor(
			$this->code,
			$repository['locator'],
			$repository['package_slug'],
			$repository['provider_repository_id'],
			null !== $credentialId,
			$repository['default_branch'],
			$credentialId
		);
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchiveContract {
		$repository     = $request->repository;
		$locator        = $repository->locator;
		$expectedBranch = $request->expectedBranch;
		$resolvedRef    = null === $expectedBranch
			? $this->client->resolveRef( $locator, $request->ref )
			: strtolower( $request->ref );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $resolvedRef ) ) {
			throw new RuntimeException( 'The fixture deployment does not contain a valid commit.', 400 );
		}

		if ( null !== $expectedBranch ) {
			$head = $this->client->branchHead( $locator, $expectedBranch );
			if ( ! hash_equals( $resolvedRef, $head ) ) {
				throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
			}
		}

		$encodedLocator = implode( '/', array_map( 'rawurlencode', explode( '/', $locator ) ) );
		$headVerifier   = null;
		if ( null !== $expectedBranch ) {
			$headVerifier = function () use ( $locator, $expectedBranch, $resolvedRef ): void {
				if ( ! hash_equals( $resolvedRef, $this->client->branchHead( $locator, $expectedBranch ) ) ) {
					throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new PreparedArchive(
			'https://fixtures.example.test/' . $encodedLocator . '/' . $resolvedRef . '.zip',
			$resolvedRef,
			$headVerifier
		);
	}

	public function getClient(): Client {
		return $this->client;
	}

	public function latestDeliveryWasObserved(): bool {
		return null !== $this->deliveryEvidence->latestAuthenticatedDelivery();
	}

	public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessTest( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->assessRemove( $repositoryId, $repository, $credentialProfileId, $hookId );
	}

	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		$this->credential( $credentialProfileId );

		return $this->operation( 'configured_pending_delivery', 'configured_pending_delivery' );
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		$this->credential( $credentialProfileId );

		return $this->operation( 'fixture_configuration_confirmed', 'unknown' );
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		$this->credential( $credentialProfileId );

		return $this->operation( 'configured_pending_delivery', 'configured_pending_delivery' );
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		$this->credential( $credentialProfileId );

		return $this->operation( 'fixture_absence_confirmed', 'absent' );
	}

	public function test( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		return $this->check( $repositoryId, $repository, $hookId, $callbackUrl, $credentialProfileId );
	}

	private function fitness( ?string $credentialId ): RepositoryWebhookFitnessResult {
		$this->credential( $credentialId );

		return new RepositoryWebhookFitnessResult( 'supported', 'suitable', 'appropriate', 'observed', 'fixture.permission.webhook_exact', gmdate( 'Y-m-d\TH:i:s\Z' ), 'No fixture remediation is required.' );
	}

	private function credential( ?string $credentialId ): void {
		if ( null === $credentialId || ! $this->validateCredential( $credentialId )->isValid() ) {
			throw new RuntimeException( 'The fixture operation credential is unavailable.' );
		}
	}

	private function operation( string $code, string $delivery ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			'succeeded',
			$code,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			'fixture:hook-1',
			array(
				'endpoint'     => 'matched',
				'events'       => 'matched',
				'content_type' => 'matched',
				'active'       => 'matched',
			),
			$delivery,
			'No fixture remediation is required.'
		);
	}
}

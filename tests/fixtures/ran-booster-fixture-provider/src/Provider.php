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
	private CredentialPolicy $credential_policy;
	private WebhookPolicy $webhook_policy;
	private Diagnostics $diagnostics;

	public function __construct(
		private ProviderCredentialStore $credentials,
		private AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence
	) {
		$this->code             = ProviderCode::parse( 'fixture-provider' );
		$this->client           = new Client();
		$this->credential_policy = new CredentialPolicy();
		$this->webhook_policy    = new WebhookPolicy();
		$this->diagnostics      = new Diagnostics( $this->client, $credentials );
	}

	public function get_metadata(): ProviderMetadata {
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

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->credential_policy;
	}

	public function validate_credential( string $credential_id ): CredentialValidationResult {
		return $this->client->validate_credential( $this->credentials->credential_material( $credential_id ) )
			? CredentialValidationResult::valid()
			: CredentialValidationResult::invalid();
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return $this->webhook_policy;
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::NOT_CONFIGURED,
			'fixture-provider.webhook.not_configured',
			'Fixture webhook readiness is not configured.',
			'Configure a fixture webhook before sending a fixture delivery.'
		);
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		if ( ! $request->get_provider()->equals( $this->code ) ) {
			throw new WebhookRejected( 400, 'Webhook provider does not match fixture provider.' );
		}

		$request->require_verification();

		return 'ping' === $request->get_header( 'x-fixture-event' )
			? WebhookEnvelope::probe()
			: WebhookEnvelope::ignored();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$credential_id = $request->credential_id;
		if ( null !== $credential_id && ! $this->validate_credential( $credential_id )->is_valid() ) {
			throw new RuntimeException( 'The fixture credential is unavailable.' );
		}

		$repository = $this->client->repository( $request->locator );

		return new RepositoryDescriptor(
			$this->code,
			$repository['locator'],
			$repository['package_slug'],
			$repository['provider_repository_id'],
			null !== $credential_id,
			$repository['default_branch'],
			$credential_id
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchiveContract {
		$repository     = $request->repository;
		$locator        = $repository->locator;
		$expected_branch = $request->expected_branch;
		$resolved_ref    = null === $expected_branch
			? $this->client->resolve_ref( $locator, $request->ref )
			: strtolower( $request->ref );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $resolved_ref ) ) {
			throw new RuntimeException( 'The fixture deployment does not contain a valid commit.', 400 );
		}

		if ( null !== $expected_branch ) {
			$head = $this->client->branch_head( $locator, $expected_branch );
			if ( ! hash_equals( $resolved_ref, $head ) ) {
				throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
			}
		}

		$encoded_locator = implode( '/', array_map( 'rawurlencode', explode( '/', $locator ) ) );
		$head_verifier   = null;
		if ( null !== $expected_branch ) {
			$head_verifier = function () use ( $locator, $expected_branch, $resolved_ref ): void {
				if ( ! hash_equals( $resolved_ref, $this->client->branch_head( $locator, $expected_branch ) ) ) {
					throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new PreparedArchive(
			'https://fixtures.example.test/' . $encoded_locator . '/' . $resolved_ref . '.zip',
			$resolved_ref,
			$head_verifier
		);
	}

	public function get_client(): Client {
		return $this->client;
	}

	public function latest_delivery_was_observed(): bool {
		return null !== $this->delivery_evidence->latest_authenticated_delivery();
	}

	public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->assess_remove( $repository_id, $repository, $credential_profile_id, $hook_id );
	}

	public function setup( string $repository_id, string $repository, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		$this->credential( $credential_profile_id );

		return $this->operation( 'configured_pending_delivery', 'configured_pending_delivery' );
	}

	public function check( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		$this->credential( $credential_profile_id );

		return $this->operation( 'fixture_configuration_confirmed', 'unknown' );
	}

	public function reconfigure( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		$this->credential( $credential_profile_id );

		return $this->operation( 'configured_pending_delivery', 'configured_pending_delivery' );
	}

	public function remove( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		$this->credential( $credential_profile_id );

		return $this->operation( 'fixture_absence_confirmed', 'absent' );
	}

	public function test( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		return $this->check( $repository_id, $repository, $hook_id, $callback_url, $credential_profile_id );
	}

	private function fitness( ?string $credential_id ): RepositoryWebhookFitnessResult {
		$this->credential( $credential_id );

		return new RepositoryWebhookFitnessResult( 'supported', 'suitable', 'appropriate', 'observed', 'fixture.permission.webhook_exact', gmdate( 'Y-m-d\TH:i:s\Z' ), 'No fixture remediation is required.' );
	}

	private function credential( ?string $credential_id ): void {
		if ( null === $credential_id || ! $this->validate_credential( $credential_id )->is_valid() ) {
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

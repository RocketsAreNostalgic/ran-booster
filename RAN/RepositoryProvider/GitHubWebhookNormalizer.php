<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use JsonException;
use RAN\Logging\BoosterLogger;
use RAN\Secrets\SecretsFile;

final readonly class GitHubWebhookNormalizer implements WebhookNormalizer {
	private const MAX_EVENT_BYTES    = 64;
	private const MAX_DELIVERY_BYTES = 191;

	private GitHubWebhookPolicy $policy;

	public function __construct(
		private SecretsFile $secrets,
		private AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
	) {
		$this->policy = new GitHubWebhookPolicy();
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return $this->policy;
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		try {
			if ( array() === $this->secrets->webhookProfiles( ProviderCode::parse( 'gh' ) ) ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::NOT_CONFIGURED,
					'gh.webhook.not_configured',
					'Site-wide Push-to-Deploy check: no GitHub webhook secret is configured for this site.',
					'This check is not scoped to the repository selected above. Save a GitHub webhook secret before sending a provider delivery.'
				);
			}
		} catch ( \Throwable ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'gh.webhook.configuration_unavailable',
				'Site-wide Push-to-Deploy check: GitHub webhook configuration could not be read.',
				'Check the credential sidecar and save the GitHub webhook configuration again.'
			);
		}

		try {
			$evidence = $this->deliveryEvidence->latestAuthenticatedDelivery( ProviderCode::parse( 'gh' ) );
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException(
				'GitHub webhook delivery evidence check failed',
				$exception,
				array( 'step' => 'gh_webhook_delivery_evidence' )
			);

			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'gh.webhook.delivery_evidence_unavailable',
				'Site-wide Push-to-Deploy check: Booster could not read retained GitHub webhook delivery evidence.',
				'Check the Booster database schema and connection, then run diagnostics again.'
			);
		}

		if ( null === $evidence ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'gh.webhook.delivery_not_observed',
				'Site-wide Push-to-Deploy check: Booster has no retained authenticated GitHub push delivery from any repository. This result is not scoped to the repository selected above.',
				'If Push-to-Deploy should be active, confirm a managed repository has a GitHub webhook using a Booster secret, send a real push, then run diagnostics again. GitHub ping tests are not retained.'
			);
		}

		if ( ! $evidence->matchedManagedPackage ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'gh.webhook.delivery_authenticated_unmatched',
				sprintf(
					'Site-wide Push-to-Deploy check: Booster authenticated a GitHub push delivery at %s site time, but it matched no managed package. This result is not scoped to the repository selected above.',
					$evidence->receivedAt
				),
				'Check repository identity, configured branch, and package deployment policy across managed GitHub repositories, then send a fresh push.'
			);
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'gh.webhook.delivery_authenticated',
			sprintf(
				'Site-wide Push-to-Deploy check: Booster authenticated a GitHub push delivery at %s site time and matched at least one managed package. This result is not scoped to the repository selected above.',
				$evidence->receivedAt
			),
			'Review Deployment activity for the package outcome. If the webhook secret or provider hook changed after this time, send a fresh push.'
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		if ( ! $request->getProvider()->equals( ProviderCode::parse( 'gh' ) ) ) {
			throw new WebhookRejected( 400, 'Webhook provider does not match GitHub.' );
		}

		$body         = $request->getBody();
		$event        = $this->event( $request );
		$delivery     = $this->delivery( $request );
		$verification = $request->requireVerification();

		if ( 'ping' === $event ) {
			return WebhookEnvelope::probe();
		}

		if ( 'push' !== $event ) {
			return WebhookEnvelope::ignored();
		}

		$payload = $this->decodePushPayload( $body );
		$push    = $this->validatePushPayload( $payload );

		if ( ! $this->policy->authorizeWebhook( $verification, $push['repository_id'], $push['repository'] ) ) {
			throw new WebhookRejected( 401, 'Webhook authentication failed.' );
		}

		if ( $push['deleted'] || ! str_starts_with( $push['ref'], 'refs/heads/' ) ) {
			return WebhookEnvelope::ignored();
		}

		$branch = substr( $push['ref'], strlen( 'refs/heads/' ) );
		if ( '' === $branch ) {
			throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
		}

		if ( ! $this->isDeployableCommit( $push['commit'] ) ) {
			return WebhookEnvelope::ignored();
		}

		return WebhookEnvelope::events(
			new PushEvent(
				ProviderCode::parse( 'gh' ),
				$push['repository'],
				$push['repository_id'],
				$branch,
				$push['commit'],
				$delivery
			)
		);
	}

	private function event( WebhookRequest $request ): string {
		$event = $this->boundedHeader(
			$request->getRawHeaderValues( 'x-github-event' ),
			self::MAX_EVENT_BYTES
		);

		if ( null === $event ) {
			throw new WebhookRejected( 400, 'GitHub event name is required.' );
		}

		return $event;
	}

	private function delivery( WebhookRequest $request ): string {
		$delivery = $this->boundedHeader(
			$request->getRawHeaderValues( 'x-github-delivery' ),
			self::MAX_DELIVERY_BYTES
		);

		if ( null === $delivery ) {
			throw new WebhookRejected( 400, 'GitHub delivery identifier is required.' );
		}

		return $delivery;
	}

	/**
	 * @param list<string> $values Untouched header values.
	 */
	private function boundedHeader( array $values, int $maxBytes ): ?string {
		if ( 1 !== count( $values )
			|| 1 !== preg_match( '/\A[\x21-\x7E]{1,' . $maxBytes . '}\z/D', $values[0] )
		) {
			return null;
		}

		return $values[0];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodePushPayload( string $body ): array {
		try {
			$payload = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
		}

		if ( ! is_array( $payload ) ) {
			throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload Decoded GitHub push payload.
	 * @return array{repository: string, repository_id: string, ref: string, commit: string, deleted: bool}
	 */
	private function validatePushPayload( array $payload ): array {
		if ( ! isset( $payload['repository'] ) || ! is_array( $payload['repository'] )
			|| ! array_key_exists( 'id', $payload['repository'] )
			|| ! isset( $payload['repository']['full_name'], $payload['ref'], $payload['after'] )
			|| ! is_string( $payload['repository']['full_name'] )
			|| ! is_string( $payload['ref'] )
			|| ! is_string( $payload['after'] )
			|| ( isset( $payload['deleted'] ) && ! is_bool( $payload['deleted'] ) )
		) {
			throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
		}

		$repository   = trim( $payload['repository']['full_name'], " \t\n\r\0\x0B/" );
		$repositoryId = $this->normalizeRepositoryId( $payload['repository']['id'] );
		$parts        = explode( '/', $repository );

		if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) || '' === trim( $parts[1] )
			|| '' === trim( $payload['ref'] ) || '' === trim( $payload['after'] )
		) {
			throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
		}

		return array(
			'repository'    => $repository,
			'repository_id' => $repositoryId,
			'ref'           => $payload['ref'],
			'commit'        => $payload['after'],
			'deleted'       => $payload['deleted'] ?? false,
		);
	}

	private function normalizeRepositoryId( mixed $repositoryId ): string {
		if ( is_int( $repositoryId ) && $repositoryId >= 0 ) {
			return (string) $repositoryId;
		}

		if ( is_string( $repositoryId ) && '' !== trim( $repositoryId ) ) {
			return $repositoryId;
		}

		throw new WebhookRejected( 400, 'GitHub push payload is invalid.' );
	}

	private function isDeployableCommit( string $commit ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{40,64}\z/i', $commit )
			&& 1 !== preg_match( '/\A0{40,64}\z/', $commit );
	}
}

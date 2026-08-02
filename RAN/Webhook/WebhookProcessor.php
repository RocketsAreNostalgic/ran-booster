<?php

declare(strict_types=1);

namespace RAN\Webhook;

use InvalidArgumentException;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Runtime\RuntimeSupport;
use Throwable;

final readonly class WebhookProcessor {

	public function __construct(
		private ProviderRegistry $providers,
		private DeploymentCoordinator $coordinator,
		private SignedWebhookVerifier $verifier
	) {
	}

	/**
	 * @param array<string, string|list<string>> $headers Native WordPress request headers.
	 */
	public function handle( string $provider, string $body, array $headers ): WebhookResponse {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return $this->response( 503, 'Webhook processing is unavailable on WordPress Multisite.' );
		}

		try {
			$providerCode = ProviderCode::parse( $provider );
			$normalizer   = $this->providers->requireCapability( $providerCode, WebhookNormalizer::class );
			$policy       = $normalizer->getWebhookPolicy();
			$request      = new WebhookRequest( $providerCode, $body, $headers, $policy->getRetainedHeaders() );
			$verification = $this->verifier->verify( $request, $policy );
			$envelope     = $normalizer->normalizeWebhook( $request->withVerification( $verification ) );
			if ( count( $envelope->getEvents() ) > 32 ) {
				throw new WebhookRejected( 400, 'Webhook event fan-out is too large.' );
			}

			if ( $envelope->isProbe() ) {
				return $this->response( 200, 'Webhook verified.' );
			}

			if ( $envelope->isIgnored() ) {
				return $this->response( 202, 'Webhook event ignored.' );
			}

			foreach ( $envelope->getEvents() as $event ) {
				if ( ! $policy->authorizeWebhook( $verification, $event->providerRepositoryId, $event->repository ) ) {
					throw new WebhookRejected( 401, 'Webhook authentication failed.' );
				}
			}
			$result = $this->coordinator->acceptWebhook(
				$envelope->getEvents(),
				hash( 'sha256', $request->getBody() )
			);

			if ( 'conflict' === $result['status'] ) {
				return $this->response( 409, 'Webhook delivery conflict.', $result );
			}

			return $this->response( 202, 'Webhook accepted.', $result );
		} catch ( WebhookRejected $exception ) {
			$status = $this->rejectionStatus( $exception->getStatusCode() );

			return $this->response(
				$status,
				$this->rejectionMessage( $status )
			);
		} catch ( InvalidProviderCode | UnknownProvider ) {
			return $this->response( 404, 'Webhook provider not found.' );
		} catch ( UnsupportedProviderCapability ) {
			return $this->response( 501, 'Webhook provider is not available.' );
		} catch ( DeploymentStorageFailure $failure ) {
			if ( $failure->isDeliveryConflict() ) {
				return $this->response( 409, 'Webhook delivery conflict.' );
			}

			return $failure->isDatabaseUnsupported() || $failure->isCapacityExhausted()
				? $this->response( 503, 'Webhook processing is temporarily unavailable.' )
				: $this->response( 500, 'Webhook processing failed.' );
		} catch ( InvalidArgumentException ) {
			return $this->response( 400, 'Invalid webhook request.' );
		} catch ( Throwable ) {
			return $this->response( 500, 'Webhook processing failed.' );
		}
	}

	private function rejectionStatus( int $status ): int {
		if ( in_array( $status, array( 401, 403, 503 ), true ) ) {
			return 401;
		}

		return in_array( $status, array( 400, 401, 413 ), true ) ? $status : 500;
	}

	private function rejectionMessage( int $status ): string {
		return match ( $status ) {
			400 => 'Invalid webhook request.',
			401 => 'Webhook authentication failed.',
			413 => 'Webhook request is too large.',
			default => 'Webhook request rejected.',
		};
	}

	/**
	 * @param array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}|null $result
	 */
	private function response( int $status, string $message, ?array $result = null ): WebhookResponse {
		$data = array( 'message' => $message );

		if ( null !== $result ) {
			$data['status']           = $result['status'];
			$data['correlation_id']   = $result['correlation_id'];
			$data['accepted_targets'] = $result['accepted_targets'];
			$data['runner_status']    = $result['runner_status'];
		}

		return new WebhookResponse( $status, $data );
	}
}

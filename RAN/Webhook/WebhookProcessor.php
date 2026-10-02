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

	/** @param callable(): array{body: string, headers: array<string, string|list<string>>} $request */
	public function handle( string $provider, callable $request ): WebhookResponse {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return $this->response( 503, 'Webhook processing is unavailable on WordPress Multisite.' );
		}

		try {
			$provider_code = ProviderCode::parse( $provider );
			$normalizer    = $this->providers->require_capability( $provider_code, WebhookNormalizer::class );
			$policy        = $normalizer->get_webhook_policy();
			$input         = $request();
			$webhook       = new WebhookRequest( $provider_code, $input['body'], $input['headers'], $policy->get_retained_headers() );
			$verification  = $this->verifier->verify( $webhook, $policy );
			$envelope      = $normalizer->normalize_webhook( $webhook->with_verification( $verification ) );
			if ( count( $envelope->get_events() ) > 32 ) {
				throw new WebhookRejected( 400, 'Webhook event fan-out is too large.' );
			}

			if ( $envelope->is_probe() ) {
				return $this->response( 200, 'Webhook verified.' );
			}

			if ( $envelope->is_ignored() ) {
				return $this->response( 202, 'Webhook event ignored.' );
			}

			foreach ( $envelope->get_events() as $event ) {

				if ( ! $policy->authorize_webhook( $verification, $event->provider_repository_id, $event->repository ) ) {
					throw new WebhookRejected( 401, 'Webhook authentication failed.' );
				}
			}
			$result = $this->coordinator->accept_webhook(
				$envelope->get_events(),
				hash( 'sha256', $webhook->get_body() )
			);

			if ( 'conflict' === $result['status'] ) {
				return $this->response( 409, 'Webhook delivery conflict.', $result );
			}

			return $this->response( 202, 'Webhook accepted.', $result );
		} catch ( WebhookRejected $exception ) {
			$status = $this->rejection_status( $exception->get_status_code() );

			return $this->response(
				$status,
				$this->rejection_message( $status )
			);
		} catch ( InvalidProviderCode | UnknownProvider ) {
			return $this->response( 404, 'Webhook provider not found.' );
		} catch ( UnsupportedProviderCapability ) {
			return $this->response( 404, 'Webhook provider not found.' );
		} catch ( DeploymentStorageFailure $failure ) {
			if ( $failure->is_delivery_conflict() ) {
				return $this->response( 409, 'Webhook delivery conflict.' );
			}

			return $failure->is_database_unsupported() || $failure->is_capacity_exhausted()
				? $this->response( 503, 'Webhook processing is temporarily unavailable.' )
				: $this->response( 500, 'Webhook processing failed.' );
		} catch ( InvalidArgumentException ) {
			return $this->response( 400, 'Invalid webhook request.' );
		} catch ( Throwable ) {
			return $this->response( 500, 'Webhook processing failed.' );
		}
	}

	private function rejection_status( int $status ): int {
		if ( in_array( $status, array( 401, 403, 503 ), true ) ) {
			return 401;
		}

		return in_array( $status, array( 400, 401, 413 ), true ) ? $status : 500;
	}

	private function rejection_message( int $status ): string {
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

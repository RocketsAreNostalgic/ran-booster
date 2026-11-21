<?php

declare(strict_types=1);

namespace RAN\Webhook;

use InvalidArgumentException;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use RAN\Deployment\CredentialShapedRef;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RuntimeException;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\SignedWebhookVerification;
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
		private SignedWebhookVerifier $verifier,
		private ?GitHubProviderSelection $selection = null,
		private ?InstallationStore $installations = null
	) {
	}

	/** @param callable(): array{body: string, headers: array<string, string|list<string>>} $request */
	public function handle( string $provider, callable $request ): WebhookResponse {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return $this->response( 503, 'Webhook processing is unavailable on WordPress Multisite.' );
		}

		$guard = function () use ( $provider ): void {
			if ( ! $this->available( $provider ) ) {
				throw new RuntimeException( 'Webhook provider unavailable.' );
			}
		};
		try {
			$provider_code = ProviderCode::parse( $provider );
			$guard();
			$normalizer = $this->providers->require_capability( $provider_code, WebhookNormalizer::class );
			$policy     = $normalizer->get_webhook_policy();
			$guard();
			$input = $request();
			$guard();
			$webhook      = new WebhookRequest( $provider_code, $input['body'], $input['headers'], $policy->get_retained_headers() );
			$verification = $this->verifier->verify( $webhook, $policy, $guard );
			$envelope     = $normalizer->normalize_webhook( $webhook->with_verification( $verification ) );
			$guard();
			if ( count( $envelope->get_events() ) > 32 ) {
				throw new WebhookRejected( 400, 'Webhook event fan-out is too large.', 'event_fanout_exceeded' );
			}

			if ( $envelope->is_probe() ) {
				BoosterLogger::capture_webhook( 'probe_verified' );
				$this->mark_delivery_verified( $verification, $envelope->get_repository_id() );

				return $this->response( 200, 'Webhook verified.' );
			}

			if ( $envelope->is_ignored() ) {
				BoosterLogger::capture_webhook( $envelope->get_reason() ?? 'ignored' );

				return $this->response( 202, 'Webhook event ignored.' );
			}

			foreach ( $envelope->get_events() as $event ) {

				$authorized = $policy->authorize_webhook( $verification, $event->provider_repository_id, $event->repository );
				$guard();
				if ( ! $authorized ) {
					throw new WebhookRejected( 401, 'Webhook authentication failed.', 'scope_mismatch' );
				}
			}
			foreach ( $envelope->get_events() as $event ) {
				$this->mark_delivery_verified( $verification, $event->provider_repository_id );
			}
			$result = $this->coordinator->accept_webhook(
				$envelope->get_events(),
				hash( 'sha256', $webhook->get_body() )
			);

			return $this->response( 202, 'Webhook accepted.', $result );
		} catch ( WebhookRejected $exception ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			$reason = $exception->get_reason() ?? 'rejected';
			// An unreadable secrets store is Booster's fault, so the provider may retry; every other refusal stays 401.
			$status = 'secret_store_unavailable' === $reason ? 503 : $this->rejection_status( $exception->get_status_code() );
			BoosterLogger::capture_webhook( $reason );
			if ( in_array( $reason, DeploymentCoordinator::REJECTED_DELIVERY_REASONS, true ) ) {
				$this->coordinator->record_rejected_delivery( $provider, $reason );
			}

			return $this->response(
				$status,
				$this->rejection_message( $status )
			);
		} catch ( InvalidProviderCode | UnknownProvider | UnsupportedProviderCapability ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			BoosterLogger::capture_webhook( 'provider_not_found' );
			return $this->response( 404, 'Webhook provider not found.' );
		} catch ( DeploymentStorageFailure $failure ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			if ( $failure->is_delivery_conflict() ) {
				BoosterLogger::capture_webhook( 'delivery_conflict' );
				return $this->response( 409, 'Webhook delivery conflict.' );
			}

			BoosterLogger::capture_webhook( $failure->is_database_unsupported() ? 'database_unsupported' : ( $failure->is_capacity_exhausted() ? 'capacity_exhausted' : 'storage_failure' ) );
			return $failure->is_database_unsupported() || $failure->is_capacity_exhausted()
				? $this->response( 503, 'Webhook processing is temporarily unavailable.' )
				: $this->response( 500, 'Webhook processing failed.' );
		} catch ( CredentialShapedRef ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			BoosterLogger::capture_webhook( 'configured_ref_refused' );
			return $this->response( 400, 'Invalid webhook request.' );
		} catch ( InvalidArgumentException ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			BoosterLogger::capture_webhook( 'invalid_request' );
			return $this->response( 400, 'Invalid webhook request.' );
		} catch ( Throwable ) {
			if ( ! $this->available( $provider ) ) {
				BoosterLogger::capture_webhook( 'selection_unavailable' );
				return $this->response( 503, 'Webhook processing is temporarily unavailable.' );
			}
			BoosterLogger::capture_webhook( 'processing_failure' );
			return $this->response( 500, 'Webhook processing failed.' );
		}
	}

	/**
	 * A signed delivery proves a Booster-recorded webhook works only when that
	 * record's own signing profile verified it for the record's repository.
	 * It never creates a record, never overrides another status and writes
	 * only the needs_verification to configured transition.
	 */
	private function mark_delivery_verified( SignedWebhookVerification $verification, ?string $repository_id ): void {
		if ( null === $this->installations || null === $repository_id ) {
			return;
		}
		try {
			$record = $this->installations->find( $verification->get_provider()->value, $repository_id );
			if ( null === $record || 'needs_verification' !== $record->status() || $record->requires_hook_identification()
				|| ! in_array( $record->webhook_profile_id(), array_column( $verification->get_profiles(), 'id' ), true ) ) {
				return;
			}
			$write = $this->installations->save_if_current( $record->with_check( 'configured', gmdate( 'Y-m-d\TH:i:s\Z' ) ), $record );
		} catch ( Throwable ) {
			// Recording is best effort; the delivery response never depends on it.
			$write = InstallationStore::WRITE_FAILED;
		}
		if ( InstallationStore::WRITE_UNCHANGED === $write ) {
			return;
		}
		BoosterLogger::log(
			InstallationStore::WRITE_APPLIED === $write ? 'Webhook delivery verified the recorded webhook.' : 'Webhook delivery verification could not be recorded.',
			array( 'provider' => $verification->get_provider()->value )
		);
	}

	private function available( string $provider ): bool {
		try {
			return 'gh' !== $provider || ( $this->selection?->webhook_access_allowed() ?? false );
		} catch ( Throwable ) {
			return false;
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
			503 => 'Webhook processing is temporarily unavailable.',
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

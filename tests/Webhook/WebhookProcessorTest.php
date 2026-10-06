<?php

declare(strict_types=1);

namespace RAN\Tests\Webhook;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused webhook fakes stay beside their tests.

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Webhook\WebhookProcessor;
use RAN\Webhook\SignedWebhookVerifier;
use RuntimeException;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Throwable;

final class WebhookProcessorTest extends TestCase {
	public const WEBHOOK_SECRET = 'processor-test-webhook-secret-0001';

	public function test_unknown_and_unsupported_providers_fail_closed(): void {
		$request_calls = 0;
		$request       = static function () use ( &$request_calls ): array {
			++$request_calls;

			return array(
				'body'    => '{}',
				'headers' => array(),
			);
		};
		$processor     = $this->processor( new ProviderRegistry(), new WebhookProcessorCoordinator() );

		self::assertSame( 404, $processor->handle( 'bb', $request )->get_status() );

		$metadata_only = new class() implements RepositoryProvider {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}
		};
		$processor     = $this->processor(
			new ProviderRegistry( array( $metadata_only ) ),
			new WebhookProcessorCoordinator()
		);

		self::assertSame( 404, $processor->handle( 'gh', $request )->get_status() );
		self::assertSame( 0, $request_calls );
	}

	public function test_provider_exceptions_never_reach_the_response(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new \RuntimeException( 'secret-canary body-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( 'body-canary', $this->signed_headers( 'body-canary' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 500, $response->get_status() );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'body-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	public function test_provider_rejection_messages_are_mapped_at_the_trusted_edge(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new WebhookRejected( 403, 'secret-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 'Webhook authentication failed.', $response->get_data()['message'] );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	public function test_probe_and_ignored_envelopes_do_not_invoke_the_intake(): void {
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );

		$probe = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) ),
			$coordinator
		);
		self::assertSame( 200, $probe->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) )->get_status() );

		$ignored = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			$coordinator
		);
		self::assertSame( 202, $ignored->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) )->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_accepted_admission_returns_only_the_safe_receipt(): void {
		$correlation_id = str_repeat( 'a', 32 );
		$body           = '{"secret-canary":"raw-body-canary"}';
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', $correlation_id, 1, 'scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( $body, $this->signed_headers( $body ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame(
			array(
				'message'          => 'Webhook accepted.',
				'status'           => 'accepted',
				'correlation_id'   => $correlation_id,
				'accepted_targets' => 1,
				'runner_status'    => 'scheduled',
			),
			$response->get_data()
		);
		$rendered = implode( ' ', array_map( 'strval', $response->get_data() ) );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
		self::assertStringNotContainsString( self::WEBHOOK_SECRET, $rendered );
	}

	public function test_exact_duplicate_admission_returns202_without_replaying_deployment(): void {
		$correlation_id = str_repeat( 'b', 32 );
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'duplicate', $correlation_id, 1, 'already_scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'duplicate', $response->get_data()['status'] );
		self::assertSame( $correlation_id, $response->get_data()['correlation_id'] );
		self::assertSame( 'already_scheduled', $response->get_data()['runner_status'] );
	}

	public function test_conflicting_delivery_returns409(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::delivery_conflict() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'Webhook delivery conflict.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_unsupported_database_returns_retry_safe_unavailable_response(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::unsupported_database() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_exhausted_attempt_capacity_returns_retry_safe_unavailable_response(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::capacity_exhausted() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_processor_reauthorizes_every_normalized_event_before_intake(): void {
		$event     = new PushEvent( ProviderCode::parse( 'gh' ), 'other/repository', 'other-id', 'main', str_repeat( 'a', 40 ), 'delivery-one' );
		$spy       = new WebhookProcessorCoordinatorSpy();
		$processor = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) ) ),
			new WebhookProcessorCoordinator( null, $spy ),
			array(
				'allowed-profile' => array(
					'scope'        => 'repository',
					'target'       => 'owner/repository',
					'authority_id' => 'allowed-id',
					'secret'       => self::WEBHOOK_SECRET,
				),
			)
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_unavailable_runner_does_not_reject_an_already_accepted_delivery(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', str_repeat( 'd', 32 ), 1, 'unavailable' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'accepted', $response->get_data()['status'] );
		self::assertSame( 'unavailable', $response->get_data()['runner_status'] );
	}

	public function test_no_targets_are_accepted_without_a_worker_wakeup(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', str_repeat( 'e', 32 ), 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 0, $response->get_data()['accepted_targets'] );
		self::assertSame( 'not_required', $response->get_data()['runner_status'] );
	}

	public function test_zero_target_replay_returns202_without_a_worker_wakeup(): void {
		$correlation_id = str_repeat( 'f', 32 );
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'duplicate', $correlation_id, 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'duplicate', $response->get_data()['status'] );
		self::assertSame( $correlation_id, $response->get_data()['correlation_id'] );
		self::assertSame( 0, $response->get_data()['accepted_targets'] );
		self::assertSame( 'not_required', $response->get_data()['runner_status'] );
	}

	public function test_database_throwable_maps_to_generic500_without_leaking_details(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, new RuntimeException( 'db-canary secret-canary raw-body-canary' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );
		$rendered = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 500, $response->get_status() );
		self::assertSame( 'Webhook processing failed.', $response->get_data()['message'] );
		self::assertStringNotContainsString( 'db-canary', $rendered );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
	}

	public function test_ambiguous_retained_headers_are_rejected_safely(): void {
		$processor = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle(
			'gh',
			$this->request( '{}', array( 'X-GitHub-Event' => array( 'push', 'ping' ) ) )
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_false_signature_stops_before_normalization_and_intake(): void {
		$normalizer_calls = 0;
		$spy              = new WebhookProcessorCoordinatorSpy();
		$materials        = array();
		foreach ( range( 1, 16 ) as $index ) {
			$materials[ 'profile-' . $index ] = array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => sprintf( 'processor-test-webhook-secret-%04d', $index ),
			);
		}
		$processor = $this->processor(
			new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static function () use ( &$normalizer_calls ): WebhookEnvelope {
							++$normalizer_calls;

							return WebhookEnvelope::ignored();
						}
					),
				)
			),
			new WebhookProcessorCoordinator( null, $spy ),
			$materials
		);

		$response = $processor->handle(
			'gh',
			$this->request( '{}', array( 'X-Hub-Signature-256' => 'sha256=' . str_repeat( '0', 64 ) ) )
		);

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 0, $normalizer_calls );
		self::assertSame( 0, $spy->calls );
	}

	public function test_oversized_git_hub_request_does_not_load_secrets_or_invoke_intake(): void {
		$secrets     = new class() extends SecretsFile {
			public int $calls = 0;

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_materials( ProviderCode|string $provider ): array {
				++$this->calls;

				return array();
			}
		};
		$normalizer  = new GitHubWebhookNormalizer( $secrets->credentials_for( 'gh' ), new EmptyAuthenticatedWebhookDeliveryEvidenceReader() );
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$processor   = $this->processor(
			new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static fn ( WebhookRequest $request ): WebhookEnvelope => $normalizer->normalize_webhook( $request )
					),
				)
			),
			$coordinator
		);

		$response = $processor->handle( 'gh', $this->request( str_repeat( 'x', 262145 ), array() ) );

		self::assertSame( 413, $response->get_status() );
		self::assertSame( 'Webhook request is too large.', $response->get_data()['message'] );
		self::assertSame( 0, $secrets->calls );
		self::assertSame( 0, $spy->calls );
	}

	public function test_normalized_event_fan_out_is_bounded_before_intake(): void {
		$events = array();
		foreach ( range( 1, 33 ) as $index ) {
			$events[] = new PushEvent(
				ProviderCode::parse( 'gh' ),
				'owner/example',
				'4001',
				'branch-' . $index,
				str_repeat( 'a', 40 ),
				'delivery-one'
			);
		}
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$processor   = $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( ...$events ) ) )
			),
			$coordinator
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_intake_receives_only_events_and_body_digest(): void {
		$body        = '{"body-canary":"must-not-cross-intake"}';
		$secret      = self::WEBHOOK_SECRET;
		$event       = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'4001',
			'main',
			str_repeat( 'a', 40 ),
			'delivery-one'
		);
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$materials   = array(
			'zeta-profile'  => array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => $secret,
			),
			'alpha-profile' => array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => $secret,
			),
		);
		$processor   = $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) )
			),
			$coordinator,
			$materials
		);

		$response = $processor->handle( 'gh', $this->request( $body, $this->signed_headers( $body ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 1, $spy->calls );
		self::assertSame( hash( 'sha256', $body ), $spy->authenticated_body_digest );
		self::assertSame( array( $event ), $spy->events );
		$captured = implode(
			'|',
			array_merge( $event->to_array(), array( $spy->authenticated_body_digest ) )
		);
		self::assertStringNotContainsString( $body, $captured );
		self::assertStringNotContainsString( $secret, $captured );
		self::assertStringNotContainsString( 'X-Hub-Signature-256', $captured );
	}

	/**
	 * @param array<string, array{scope: string, target: string, authority_id: string, secret: string}>|null $materials
	 */
	private function processor(
		ProviderRegistry $registry,
		DeploymentCoordinator $coordinator,
		?array $materials = null
	): WebhookProcessor {
		$secrets = new class($materials) extends SecretsFile {
			/** @var array<string, array{scope: string, target: string, authority_id: string, secret: string}> */
			private array $materials;

			public function __construct( ?array $materials = null ) {
				parent::__construct( '/unused/processor-secrets.php', array() );
				$this->materials = $materials ?? array(
					'test-profile' => array(
						'scope'        => 'owner',
						'target'       => 'owner',
						'authority_id' => '',
						'secret'       => WebhookProcessorTest::WEBHOOK_SECRET,
					),
				);
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_materials( ProviderCode|string $provider ): array {
				return $this->materials;
			}
		};

		return new WebhookProcessor( $registry, $coordinator, new SignedWebhookVerifier( $secrets ) );
	}

	private function event_processor( WebhookProcessorCoordinator $coordinator ): WebhookProcessor {
		$event = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'4001',
			'main',
			str_repeat( 'a', 40 ),
			'delivery-one'
		);

		return $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) )
			),
			$coordinator
		);
	}

	/**
	 * @param array<string, string|list<string>> $headers
	 * @return callable(): array{body: string, headers: array<string, string|list<string>>}
	 */
	private function request( string $body, array $headers ): callable {
		return static fn (): array => array(
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * @return array{
	 *     status: 'accepted'|'duplicate'|'conflict',
	 *     correlation_id: string,
	 *     accepted_targets: int,
	 *     runner_status: 'scheduled'|'already_scheduled'|'unavailable'|'not_required'
	 * }
	 */
	public static function admission_result(
		string $status,
		string $correlation_id,
		int $accepted_targets,
		string $runner_status
	): array {
		return array(
			'status'           => $status,
			'correlation_id'   => $correlation_id,
			'accepted_targets' => $accepted_targets,
			'runner_status'    => $runner_status,
		);
	}

	/** @return array<string, string> */
	private function signed_headers( string $body ): array {
		return array(
			'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, self::WEBHOOK_SECRET ),
		);
	}
}

final readonly class WebhookProcessorProvider implements RepositoryProvider, WebhookNormalizer {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private \Closure $normalizer;

	public function __construct( callable $normalizer ) {
		$this->normalizer = \Closure::fromCallable( $normalizer );
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return ( $this->normalizer )( $request );
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return new GitHubWebhookPolicy();
	}

	public function diagnose_webhook_readiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
			\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}
}

final class WebhookProcessorCoordinator extends DeploymentCoordinator {

	/**
	 * @param array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}|null $result
	 */
	public function __construct(
		private ?array $result = null,
		private ?WebhookProcessorCoordinatorSpy $spy = null,
		private ?Throwable $failure = null
	) {
	}

	public function accept_webhook(
		array $events,
		string $authenticated_body_digest
	): array {
		if ( null !== $this->spy ) {
			++$this->spy->calls;
			$this->spy->events                    = $events;
			$this->spy->authenticated_body_digest = $authenticated_body_digest;
		}
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result ?? WebhookProcessorTest::admission_result( 'accepted', str_repeat( 'a', 32 ), 1, 'scheduled' );
	}
}

final class WebhookProcessorCoordinatorSpy {

	public int $calls = 0;
	/** @var list<PushEvent> */
	public array $events                     = array();
	public string $authenticated_body_digest = '';
}

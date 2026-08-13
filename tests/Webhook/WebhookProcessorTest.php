<?php

declare(strict_types=1);

namespace Tests\Webhook;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused webhook fakes stay beside their tests.

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Booster\GitHub\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\Booster\GitHub\WebhookPolicy as GitHubWebhookPolicy;
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
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Throwable;

final class WebhookProcessorTest extends TestCase {
	public const WEBHOOK_SECRET = 'processor-test-webhook-secret-0001';

	public function testUnknownAndUnsupportedProvidersFailClosed(): void {
		$requestCalls = 0;
		$request      = static function () use ( &$requestCalls ): array {
			++$requestCalls;

			return array(
				'body'    => '{}',
				'headers' => array(),
			);
		};
		$processor    = $this->processor( new ProviderRegistry(), new WebhookProcessorCoordinator() );

		self::assertSame( 404, $processor->handle( 'bb', $request )->getStatus() );

		$metadataOnly = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}
		};
		$processor    = $this->processor(
			new ProviderRegistry( array( $metadataOnly ) ),
			new WebhookProcessorCoordinator()
		);

		self::assertSame( 404, $processor->handle( 'gh', $request )->getStatus() );
		self::assertSame( 0, $requestCalls );
	}

	public function testProviderExceptionsNeverReachTheResponse(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new \RuntimeException( 'secret-canary body-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( 'body-canary', $this->signedHeaders( 'body-canary' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->getData() ) );

		self::assertSame( 500, $response->getStatus() );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'body-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	public function testProviderRejectionMessagesAreMappedAtTheTrustedEdge(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new WebhookRejected( 403, 'secret-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->getData() ) );

		self::assertSame( 401, $response->getStatus() );
		self::assertSame( 'Webhook authentication failed.', $response->getData()['message'] );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	public function testProbeAndIgnoredEnvelopesDoNotInvokeTheIntake(): void {
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );

		$probe = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) ),
			$coordinator
		);
		self::assertSame( 200, $probe->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) )->getStatus() );

		$ignored = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			$coordinator
		);
		self::assertSame( 202, $ignored->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) )->getStatus() );
		self::assertSame( 0, $spy->calls );
	}

	public function testAcceptedAdmissionReturnsOnlyTheSafeReceipt(): void {
		$correlationId = str_repeat( 'a', 32 );
		$body          = '{"secret-canary":"raw-body-canary"}';
		$processor     = $this->eventProcessor(
			new WebhookProcessorCoordinator( self::admissionResult( 'accepted', $correlationId, 1, 'scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( $body, $this->signedHeaders( $body ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame(
			array(
				'message'          => 'Webhook accepted.',
				'status'           => 'accepted',
				'correlation_id'   => $correlationId,
				'accepted_targets' => 1,
				'runner_status'    => 'scheduled',
			),
			$response->getData()
		);
		$rendered = implode( ' ', array_map( 'strval', $response->getData() ) );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
		self::assertStringNotContainsString( self::WEBHOOK_SECRET, $rendered );
	}

	public function testExactDuplicateAdmissionReturns202WithoutReplayingDeployment(): void {
		$correlationId = str_repeat( 'b', 32 );
		$processor     = $this->eventProcessor(
			new WebhookProcessorCoordinator( self::admissionResult( 'duplicate', $correlationId, 1, 'already_scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame( 'duplicate', $response->getData()['status'] );
		self::assertSame( $correlationId, $response->getData()['correlation_id'] );
		self::assertSame( 'already_scheduled', $response->getData()['runner_status'] );
	}

	public function testConflictingDeliveryReturns409(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::deliveryConflict() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 409, $response->getStatus() );
		self::assertSame( 'Webhook delivery conflict.', $response->getData()['message'] );
		self::assertArrayNotHasKey( 'status', $response->getData() );
	}

	public function testUnsupportedDatabaseReturnsRetrySafeUnavailableResponse(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::unsupportedDatabase() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 503, $response->getStatus() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->getData()['message'] );
		self::assertArrayNotHasKey( 'status', $response->getData() );
	}

	public function testExhaustedAttemptCapacityReturnsRetrySafeUnavailableResponse(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::capacityExhausted() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 503, $response->getStatus() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->getData()['message'] );
		self::assertArrayNotHasKey( 'status', $response->getData() );
	}

	public function testProcessorReauthorizesEveryNormalizedEventBeforeIntake(): void {
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

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 401, $response->getStatus() );
		self::assertSame( 0, $spy->calls );
	}

	public function testUnavailableRunnerDoesNotRejectAnAlreadyAcceptedDelivery(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( self::admissionResult( 'accepted', str_repeat( 'd', 32 ), 1, 'unavailable' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame( 'accepted', $response->getData()['status'] );
		self::assertSame( 'unavailable', $response->getData()['runner_status'] );
	}

	public function testNoTargetsAreAcceptedWithoutAWorkerWakeup(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( self::admissionResult( 'accepted', str_repeat( 'e', 32 ), 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame( 0, $response->getData()['accepted_targets'] );
		self::assertSame( 'not_required', $response->getData()['runner_status'] );
	}

	public function testZeroTargetReplayReturns202WithoutAWorkerWakeup(): void {
		$correlationId = str_repeat( 'f', 32 );
		$processor     = $this->eventProcessor(
			new WebhookProcessorCoordinator( self::admissionResult( 'duplicate', $correlationId, 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame( 'duplicate', $response->getData()['status'] );
		self::assertSame( $correlationId, $response->getData()['correlation_id'] );
		self::assertSame( 0, $response->getData()['accepted_targets'] );
		self::assertSame( 'not_required', $response->getData()['runner_status'] );
	}

	public function testDatabaseThrowableMapsToGeneric500WithoutLeakingDetails(): void {
		$processor = $this->eventProcessor(
			new WebhookProcessorCoordinator( null, null, new RuntimeException( 'db-canary secret-canary raw-body-canary' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );
		$rendered = implode( ' ', array_map( 'strval', $response->getData() ) );

		self::assertSame( 500, $response->getStatus() );
		self::assertSame( 'Webhook processing failed.', $response->getData()['message'] );
		self::assertStringNotContainsString( 'db-canary', $rendered );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
	}

	public function testAmbiguousRetainedHeadersAreRejectedSafely(): void {
		$processor = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle(
			'gh',
			$this->request( '{}', array( 'X-GitHub-Event' => array( 'push', 'ping' ) ) )
		);

		self::assertSame( 400, $response->getStatus() );
	}

	public function testFalseSignatureStopsBeforeNormalizationAndIntake(): void {
		$normalizerCalls = 0;
		$spy             = new WebhookProcessorCoordinatorSpy();
		$materials       = array();
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
						static function () use ( &$normalizerCalls ): WebhookEnvelope {
							++$normalizerCalls;

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

		self::assertSame( 401, $response->getStatus() );
		self::assertSame( 0, $normalizerCalls );
		self::assertSame( 0, $spy->calls );
	}

	public function testOversizedGitHubRequestDoesNotLoadSecretsOrInvokeIntake(): void {
		$secrets     = new class() extends SecretsFile {
			public int $calls = 0;

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function webhookMaterials( ProviderCode|string $provider ): array {
				++$this->calls;

				return array();
			}
		};
		$normalizer  = new GitHubWebhookNormalizer( $secrets->credentialsFor( 'gh' ), new EmptyAuthenticatedWebhookDeliveryEvidenceReader() );
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$processor   = $this->processor(
			new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static fn ( WebhookRequest $request ): WebhookEnvelope => $normalizer->normalizeWebhook( $request )
					),
				)
			),
			$coordinator
		);

		$response = $processor->handle( 'gh', $this->request( str_repeat( 'x', 262145 ), array() ) );

		self::assertSame( 413, $response->getStatus() );
		self::assertSame( 'Webhook request is too large.', $response->getData()['message'] );
		self::assertSame( 0, $secrets->calls );
		self::assertSame( 0, $spy->calls );
	}

	public function testNormalizedEventFanOutIsBoundedBeforeIntake(): void {
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

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signedHeaders( '{}' ) ) );

		self::assertSame( 400, $response->getStatus() );
		self::assertSame( 0, $spy->calls );
	}

	public function testIntakeReceivesOnlyEventsAndBodyDigest(): void {
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

		$response = $processor->handle( 'gh', $this->request( $body, $this->signedHeaders( $body ) ) );

		self::assertSame( 202, $response->getStatus() );
		self::assertSame( 1, $spy->calls );
		self::assertSame( hash( 'sha256', $body ), $spy->authenticatedBodyDigest );
		self::assertSame( array( $event ), $spy->events );
		$captured = implode(
			'|',
			array_merge( $event->toArray(), array( $spy->authenticatedBodyDigest ) )
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

			public function webhookMaterials( ProviderCode|string $provider ): array {
				return $this->materials;
			}
		};

		return new WebhookProcessor( $registry, $coordinator, new SignedWebhookVerifier( $secrets ) );
	}

	private function eventProcessor( WebhookProcessorCoordinator $coordinator ): WebhookProcessor {
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
	public static function admissionResult(
		string $status,
		string $correlationId,
		int $acceptedTargets,
		string $runnerStatus
	): array {
		return array(
			'status'           => $status,
			'correlation_id'   => $correlationId,
			'accepted_targets' => $acceptedTargets,
			'runner_status'    => $runnerStatus,
		);
	}

	/** @return array<string, string> */
	private function signedHeaders( string $body ): array {
		return array(
			'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, self::WEBHOOK_SECRET ),
		);
	}
}

final readonly class WebhookProcessorProvider implements RepositoryProvider, WebhookNormalizer {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private \Closure $normalizer;

	public function __construct( callable $normalizer ) {
		$this->normalizer = \Closure::fromCallable( $normalizer );
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return ( $this->normalizer )( $request );
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new GitHubWebhookPolicy();
	}

	public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
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

	public function acceptWebhook(
		array $events,
		string $authenticatedBodyDigest
	): array {
		if ( null !== $this->spy ) {
			++$this->spy->calls;
			$this->spy->events                  = $events;
			$this->spy->authenticatedBodyDigest = $authenticatedBodyDigest;
		}
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result ?? WebhookProcessorTest::admissionResult( 'accepted', str_repeat( 'a', 32 ), 1, 'scheduled' );
	}
}

final class WebhookProcessorCoordinatorSpy {

	public int $calls = 0;
	/** @var list<PushEvent> */
	public array $events                   = array();
	public string $authenticatedBodyDigest = '';
}

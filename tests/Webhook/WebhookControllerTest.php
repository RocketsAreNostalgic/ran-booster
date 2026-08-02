<?php

declare(strict_types=1);

namespace Tests\Webhook;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused REST fakes stay beside their tests.

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentCoordinator;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Webhook\WebhookController;
use RAN\Webhook\WebhookProcessor;
use RAN\Webhook\SignedWebhookVerifier;
use RAN\Secrets\SecretsFile;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;

require_once __DIR__ . '/WebhookControllerTestEnvironment.php';

final class WebhookControllerTest extends TestCase {
	public const WEBHOOK_SECRET = 'controller-test-webhook-secret-001';
	private const NO_STORE      = 'no-store, no-cache, must-revalidate, max-age=0';

	protected function setUp(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function testUrlProviderCannotBeOverriddenByMergedRequestParameters(): void {
		$processor  = $this->processor( new WebhookControllerProvider(), new WebhookControllerCoordinator() );
		$controller = new WebhookController( $processor );
		$request    = new \WP_REST_Request(
			array( 'provider' => 'gh' ),
			array( 'provider' => 'bb' ),
			'{}',
			array( 'x-fixture-signature' => 'sha256=' . hash_hmac( 'sha256', '{}', self::WEBHOOK_SECRET ) )
		);

		$response = $controller->receive( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'Webhook verified.', $response->get_data()['message'] );
		self::assertSame( self::NO_STORE, $response->get_headers()['Cache-Control'] );
	}

	public function testOriginalMethodAndOverridesStopBeforeProviderOrBodyWork(): void {
		$controller = new WebhookController( $this->processor( new WebhookControllerProvider(), new WebhookControllerCoordinator() ) );
		$requests   = array(
			new \WP_REST_Request( array( 'provider' => 'gh' ), array(), '{}', array(), 'GET' ),
			new \WP_REST_Request( array( 'provider' => 'gh' ), array(), '{}', array( 'x_http_method_override' => 'DELETE' ) ),
			new \WP_REST_Request( array( 'provider' => 'gh' ), array(), '{}', array(), 'POST', array( '_method' => 'POST' ) ),
		);

		foreach ( $requests as $request ) {
			$response = $controller->receive( $request );

			self::assertSame( 400, $response->get_status() );
			self::assertSame( 0, $request->bodyCalls );
			self::assertSame( 0, $request->headerCalls );
			self::assertSame( self::NO_STORE, $response->get_headers()['Cache-Control'] );
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$request                   = new \WP_REST_Request( array( 'provider' => 'gh' ), array(), '{}', array(), 'POST' );
		$response                  = $controller->receive( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 0, $request->bodyCalls );
	}

	public function testOnlyTheExactQueryStyleRouteMetadataIsAccepted(): void {
		$controller = new WebhookController( $this->processor( new WebhookControllerProvider(), new WebhookControllerCoordinator() ) );
		$route      = '/ran-booster/v1/webhooks/gh';
		$accepted   = new \WP_REST_Request(
			array( 'provider' => 'gh' ),
			array(),
			'{}',
			array( 'x-fixture-signature' => 'sha256=' . hash_hmac( 'sha256', '{}', self::WEBHOOK_SECRET ) ),
			'POST',
			array( 'rest_route' => $route ),
			$route
		);
		$rejected   = new \WP_REST_Request(
			array( 'provider' => 'gh' ),
			array(),
			'{}',
			array(),
			'POST',
			array(
				'rest_route' => $route,
				'_envelope'  => '1',
			),
			$route
		);

		self::assertSame( 200, $controller->receive( $accepted )->get_status() );
		self::assertSame( 400, $controller->receive( $rejected )->get_status() );
		self::assertSame( 0, $rejected->bodyCalls );
	}

	public function testUnknownProviderStopsBeforeBodyAndHeadersAreRead(): void {
		$controller = new WebhookController( $this->processor( new WebhookControllerProvider(), new WebhookControllerCoordinator() ) );
		$request    = new \WP_REST_Request( array( 'provider' => 'bb' ), array(), 'body-canary', array( 'secret-canary' => 'value' ) );
		$response   = $controller->receive( $request );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 0, $request->bodyCalls );
		self::assertSame( 0, $request->headerCalls );
		self::assertSame( self::NO_STORE, $response->get_headers()['Cache-Control'] );
	}

	public function testControllerReturnsTheAsyncAdmissionReceipt(): void {
		$event       = new \RAN\RepositoryProvider\PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'4001',
			'main',
			str_repeat( 'a', 40 ),
			'delivery-one'
		);
		$correlation = str_repeat( 'b', 32 );
		$processor   = $this->processor(
			new WebhookControllerProvider( WebhookEnvelope::events( $event ) ),
			new WebhookControllerCoordinator(
				array(
					'status'           => 'accepted',
					'correlation_id'   => $correlation,
					'accepted_targets' => 1,
					'runner_status'    => 'unavailable',
				)
			)
		);
		$controller  = new WebhookController( $processor );
		$request     = new \WP_REST_Request(
			array( 'provider' => 'gh' ),
			array(),
			'{}',
			array( 'x-fixture-signature' => 'sha256=' . hash_hmac( 'sha256', '{}', self::WEBHOOK_SECRET ) )
		);

		$response = $controller->receive( $request );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'accepted', $response->get_data()['status'] );
		self::assertSame( $correlation, $response->get_data()['correlation_id'] );
		self::assertSame( 1, $response->get_data()['accepted_targets'] );
		self::assertSame( 'unavailable', $response->get_data()['runner_status'] );
		self::assertSame( self::NO_STORE, $response->get_headers()['Cache-Control'] );
	}

	private function processor( WebhookControllerProvider $provider, WebhookControllerCoordinator $coordinator ): WebhookProcessor {
		return new WebhookProcessor(
			new ProviderRegistry( array( $provider ) ),
			$coordinator,
			new SignedWebhookVerifier(
				new class() extends SecretsFile {
					public function __construct() {
						parent::__construct( '/unused/controller-secrets.php', array() );
					}

					public function webhookMaterials( ProviderCode|string $provider ): array {
						return array(
							'test-profile' => array(
								'scope'        => 'owner',
								'target'       => 'owner',
								'authority_id' => '',
								'secret'       => WebhookControllerTest::WEBHOOK_SECRET,
							),
						);
					}
				}
			)
		);
	}
}

final readonly class WebhookControllerProvider implements RepositoryProvider, WebhookNormalizer {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private WebhookEnvelope $envelope;

	public function __construct( ?WebhookEnvelope $envelope = null ) {
		$this->envelope = $envelope ?? WebhookEnvelope::probe();
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return $this->envelope;
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) );
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

final class WebhookControllerCoordinator extends DeploymentCoordinator {

	/** @param array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}|null $result */
	public function __construct( private ?array $result = null ) {
	}

	public function acceptWebhook(
		array $events,
		string $authenticatedBodyDigest
	): array {
		return $this->result ?? array(
			'status'           => 'accepted',
			'correlation_id'   => str_repeat( 'a', 32 ),
			'accepted_targets' => 1,
			'runner_status'    => 'scheduled',
		);
	}
}

<?php

declare(strict_types=1);

namespace Tests\Deployment;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused boundary spies stay beside their integration test.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only cleanup removes the exact temporary capture paths.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Internal\CoreContainer;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Webhook\SignedWebhookVerifier;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WebhookV1ExecutionBoundaryTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;
	/** @var list<string> */
	private array $operations;

	protected function setUp(): void {
		require_once __DIR__ . '/WebhookV1BoundaryWordPressFunctions.php';

		$GLOBALS['ran_booster_webhook_v1_operations'] = array();
		$this->operations                             = &$GLOBALS['ran_booster_webhook_v1_operations'];
		$this->directory                              = sys_get_temp_dir() . '/ran-booster-webhook-v1-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->capture = new TemporaryDebugCapture(
			$this->directory . '/secrets.php',
			static fn(): int => strtotime( '2026-08-03T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configureCapture( $this->capture );
	}

	protected function tearDown(): void {
		BoosterLogger::configureCapture( null );
		unset( $GLOBALS['ran_booster_webhook_v1_operations'] );

		foreach (
			array(
				$this->directory . '/ran-booster-debug.php',
				$this->directory . '/ran-booster-debug.php.lock',
			) as $path
		) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
	}

	public function testRouteRegistrationResolvesTheRealGraphWithoutCrossingOperationBoundaries(): void {
		$runtime          = $this->runtime();
		$this->operations = array();

		$runtime->registerWebhookRoutes();

		self::assertCount( 1, $GLOBALS['ran_booster_webhook_v1_routes'] );
		$route = $GLOBALS['ran_booster_webhook_v1_routes'][0];
		self::assertSame( 'ran-booster/v1', $route['namespace'] );
		self::assertSame( '/webhooks/(?P<provider>[a-z0-9-]+)', $route['route'] );
		self::assertSame( 'POST', $route['arguments']['methods'] );
		self::assertInstanceOf( \RAN\Webhook\WebhookController::class, $route['arguments']['callback'][0] );
		$this->assertNoOperations();
	}

	public function testUnrelatedRestDispatchNeverEntersTheWebhookProcessor(): void {
		$this->runtime()->registerWebhookRoutes();
		$unrelatedCalls = 0;
		register_rest_route(
			'fixture/v1',
			'/health',
			array(
				'callback' => static function () use ( &$unrelatedCalls ): void {
					++$unrelatedCalls;
				},
			)
		);
		$this->operations = array();

		$dispatched = ran_booster_test_dispatch_rest_route( '/fixture/v1/health', new \stdClass() );

		self::assertTrue( $dispatched );
		self::assertSame( 1, $unrelatedCalls );
		$this->assertNoOperations();
	}

	private function runtime(): Booster {
		$provider  = new WebhookV1BoundaryProvider( $this->operations );
		$registry  = new ProviderRegistry( array( $provider ) );
		$container = new CoreContainer();
		$container->bind( Database::class, new WebhookV1BoundaryDatabase( $this->operations ) );
		$container->bind( ProviderRegistry::class, $registry );
		$container->bind( DeploymentCoordinator::class, new WebhookV1BoundaryCoordinator( $this->operations ) );
		$container->bind(
			SignedWebhookVerifier::class,
			new SignedWebhookVerifier( new WebhookV1BoundarySecretsFile( $this->operations ) )
		);

		return new Booster( $container );
	}

	private function assertNoOperations(): void {
		self::assertSame( array(), $this->operations );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );
	}
}

final class WebhookV1BoundaryDatabase extends Database {
	/** @param list<string> $operations */
	public function __construct( private array &$operations ) {
	}

	public function maybeUpgrade(): void {
		$this->operations[] = 'database';
	}

	public function requireReady(): void {
		$this->operations[] = 'database';
	}
}

final class WebhookV1BoundarySecretsFile extends SecretsFile {
	/** @param list<string> $operations */
	public function __construct( private array &$operations ) {
		parent::__construct( '/unused/webhook-v1-boundary-secrets.php', array() );
	}

	public function webhookMaterials( ProviderCode|string $provider ): array {
		unset( $provider );
		$this->operations[] = 'sidecar';

		return array();
	}
}

final class WebhookV1BoundaryProvider implements RepositoryProvider, WebhookNormalizer {
	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @param list<string> $operations */
	public function __construct( private array &$operations ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		$this->operations[] = 'provider';

		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );
		$this->operations[] = 'provider';

		return WebhookEnvelope::ignored();
	}

	public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		$this->operations[] = 'remote';

		return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
			\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}
}

final class WebhookV1BoundaryCoordinator extends DeploymentCoordinator {
	/** @param list<string> $operations */
	public function __construct( private array &$operations ) {
	}

	public function acceptWebhook( array $events, string $authenticatedBodyDigest ): array {
		unset( $events, $authenticatedBodyDigest );
		$this->operations[] = 'storage';

		return array(
			'status'           => 'accepted',
			'correlation_id'   => str_repeat( 'a', 32 ),
			'accepted_targets' => 1,
			'runner_status'    => 'scheduled',
		);
	}
}

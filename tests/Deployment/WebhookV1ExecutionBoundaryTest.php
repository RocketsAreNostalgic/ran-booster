<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

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
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WebhookV1ExecutionBoundaryTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;
	/** @var list<string> */
	private array $operations;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/WebhookV1BoundaryWordPressFunctions.php';

		$GLOBALS['ran_booster_webhook_v1_operations'] = array();
		$this->operations                             = &$GLOBALS['ran_booster_webhook_v1_operations'];
		$this->directory                              = sys_get_temp_dir() . '/ran-booster-webhook-v1-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->capture = new TemporaryDebugCapture(
			$this->directory . '/secrets.php',
			static fn(): int => strtotime( '2026-08-03T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configure_capture( $this->capture );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		BoosterLogger::configure_capture( null );
		unset( $GLOBALS['ran_booster_webhook_v1_operations'] );

		foreach (
			array(
				$this->directory . '/ran-booster-debug.php',
				$this->directory . '/ran-booster-debug.php.lock',
			) as $path
		) {
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			rmdir( $this->directory );
		}
	}

	public function test_route_registration_resolves_the_real_graph_without_crossing_operation_boundaries(): void {
		$runtime          = $this->runtime();
		$this->operations = array();

		$runtime->register_webhook_routes();

		self::assertCount( 1, $GLOBALS['ran_booster_webhook_v1_routes'] );
		$route = $GLOBALS['ran_booster_webhook_v1_routes'][0];
		self::assertSame( 'ran-booster/v1', $route['namespace'] );
		self::assertSame( '/webhooks/(?P<provider>[a-z0-9-]+)', $route['route'] );
		self::assertSame( 'POST', $route['arguments']['methods'] );
		self::assertInstanceOf( \RAN\Webhook\WebhookController::class, $route['arguments']['callback'][0] );
		$this->assert_no_operations();
	}

	public function test_unrelated_rest_dispatch_never_enters_the_webhook_processor(): void {
		$this->runtime()->register_webhook_routes();
		$unrelated_calls = 0;
		register_rest_route(
			'fixture/v1',
			'/health',
			array(
				'callback' => static function () use ( &$unrelated_calls ): void {
					++$unrelated_calls;
				},
			)
		);
		$this->operations = array();

		$dispatched = ran_booster_test_dispatch_rest_route( '/fixture/v1/health', new \stdClass() );

		self::assertTrue( $dispatched );
		self::assertSame( 1, $unrelated_calls );
		$this->assert_no_operations();
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

	private function assert_no_operations(): void {
		self::assertSame( array(), $this->operations );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class WebhookV1BoundaryDatabase extends Database {
	/** @param list<string> $operations */
	// @phpstan-ignore property.onlyWritten (The caller reads this by-reference event buffer to verify exact execution ordering.)
	public function __construct( private array &$operations ) {
	}

	public function maybe_upgrade(): void {
		$this->operations[] = 'database';
	}

	public function require_ready(): void {
		$this->operations[] = 'database';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class WebhookV1BoundarySecretsFile extends SecretsFile {
	/** @param list<string> $operations */
	// @phpstan-ignore property.onlyWritten (The caller reads this by-reference event buffer to verify exact execution ordering.)
	public function __construct( private array &$operations ) {
		parent::__construct( '/unused/webhook-v1-boundary-secrets.php', array() );
	}

	public function webhook_materials( ProviderCode|string $provider ): array {
		unset( $provider );
		$this->operations[] = 'sidecar';

		return array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class WebhookV1BoundaryProvider implements RepositoryProvider, WebhookNormalizer {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @param list<string> $operations */
	// @phpstan-ignore property.onlyWritten (The caller reads this by-reference event buffer to verify exact execution ordering.)
	public function __construct( private array &$operations ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		$this->operations[] = 'provider';

		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );
		$this->operations[] = 'provider';

		return WebhookEnvelope::ignored();
	}

	public function diagnose_webhook_readiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		$this->operations[] = 'remote';

		return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
			\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class WebhookV1BoundaryCoordinator extends DeploymentCoordinator {
	/** @param list<string> $operations */
	// @phpstan-ignore property.onlyWritten (The caller reads this by-reference event buffer to verify exact execution ordering.)
	public function __construct( private array &$operations ) {
	}

	public function accept_webhook( array $events, string $authenticated_body_digest ): array {
		unset( $events, $authenticated_body_digest );
		$this->operations[] = 'storage';

		return array(
			'status'           => 'accepted',
			'correlation_id'   => str_repeat( 'a', 32 ),
			'accepted_targets' => 1,
			'runner_status'    => 'scheduled',
		);
	}
}

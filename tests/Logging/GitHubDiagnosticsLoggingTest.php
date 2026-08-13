<?php

declare(strict_types=1);

namespace Tests\Logging;

// Direct local filesystem operations inspect the bounded temporary capture under test.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused escaping-provider fixture belongs beside the logging boundary tests.

require_once __DIR__ . '/LoggingWordPressFunctions.php';

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\Diagnostics as GitHubDiagnostics;
use RAN\Booster\GitHub\RepositoryBrowser;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Troubleshooting\TroubleshootingService;
use ReflectionClass;
use Throwable;

final class GitHubDiagnosticsLoggingTest extends TestCase {

	private const SECRET_CANARY = 'github_pat_diagnostic_canary_secret';

	private string $captureDirectory;
	private TemporaryDebugCapture $capture;

	protected function setUp(): void {
		$this->captureDirectory = sys_get_temp_dir() . '/ran-booster-github-diagnostics-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->captureDirectory, 0700 ) );
		$this->capture = new TemporaryDebugCapture(
			$this->captureDirectory . '/secrets.php',
			static fn(): int => strtotime( '2026-08-13T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configureCapture( $this->capture );
	}

	protected function tearDown(): void {
		BoosterLogger::configureCapture( null );
		foreach ( array( 'ran-booster-debug.php', 'ran-booster-debug.php.lock' ) as $name ) {
			$path = $this->captureDirectory . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->captureDirectory ) ) {
			rmdir( $this->captureDirectory );
		}
	}

	/** @return iterable<string, array{bool,string,int}> */
	public static function unexpectedFailures(): iterable {
		yield 'credential' => array( true, 'credential-secret-canary', 73 );
		yield 'repository' => array( false, 'owner/repository-secret-canary', 74 );
	}

	#[DataProvider( 'unexpectedFailures' )]
	public function testUnexpectedFailureIsLoggedWithoutProviderInputOrExceptionMessage(
		bool $credential,
		string $providerInput,
		int $code
	): void {
		$browser = new class() extends RepositoryBrowser {
			public ?Throwable $credentialException = null;
			public ?Throwable $repositoryException = null;

			public function __construct() {
			}

			public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {
				unset( $credentialId, $timeout );
				throw $this->credentialException ?? new LogicException( 'Unexpected credential fixture state.' );
			}

			public function repository(
				string $fullName,
				?string $credentialId = null,
				float|int $timeout = 15,
				?int $responseSize = null,
				bool $authenticateDefault = false
			): RepositoryDescriptor {
				unset( $fullName, $credentialId, $timeout, $responseSize, $authenticateDefault );
				throw $this->repositoryException ?? new LogicException( 'Unexpected repository fixture state.' );
			}
		};
		$failure = new LogicException( self::SECRET_CANARY, $code );
		if ( $credential ) {
			$browser->credentialException = $failure;
			$results                      = ( new GitHubDiagnostics( $browser ) )->diagnose( new ProviderDiagnosticRequest( $providerInput ) );
			$result                       = $results[0];
		} else {
			$browser->repositoryException = $failure;
			$results                      = ( new GitHubDiagnostics( $browser ) )->diagnose( new ProviderDiagnosticRequest( null, $providerInput ) );
			$result                       = $results[1];
		}
		self::assertSame( $failure, $result->failure );
		$service = ( new ReflectionClass( TroubleshootingService::class ) )->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod( TroubleshootingService::class, 'recordProviderFailure' );
		$method->invoke( $service, $result, ProviderCode::parse( 'gh' ), 'provider_diagnostics' );

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertSame(
			'[ran-booster] provider diagnostic operation failed {"provider":"gh","step":"provider_diagnostics","exception_class":"LogicException","exception_code":"' . $code . '"}',
			$line
		);
		self::assertStringNotContainsString( self::SECRET_CANARY, $line );
		self::assertStringNotContainsString( $providerInput, $line );
	}

	/** @return iterable<string, array{bool,string}> */
	public static function escapingFailures(): iterable {
		yield 'diagnostics' => array( false, 'provider_diagnostics' );
		yield 'webhook readiness' => array( true, 'provider_webhook_readiness' );
	}

	#[DataProvider( 'escapingFailures' )]
	public function testEscapingProviderFailureIsLoggedOnlyAtTheCoreBoundary( bool $readiness, string $step ): void {
		$failure  = new LogicException( self::SECRET_CANARY, 75 );
		$provider = new EscapingDiagnosticProvider( $failure, $readiness );
		$local    = new class() extends \RAN\Troubleshooting\LocalTroubleshootingService {
			public function __construct() {
			}

			public function diagnose(): array {
				$results = array();
				for ( $index = 1; $index <= 5; ++$index ) {
					$results[] = new \RAN\RepositoryProvider\ProviderDiagnosticResult(
						\RAN\RepositoryProvider\ProviderDiagnosticResult::PASSED,
						'local.fixture.' . $index,
						'Local fixture passed.',
						'No action is required.'
					);
				}

				return array(
					'results' => $results,
					'partial' => false,
				);
			}
		};
		$payload  = ( new TroubleshootingService( $local, new ProviderRegistry( array( $provider ) ) ) )->diagnose( 'gh', null, null );

		self::assertSame( 'provider_unavailable', $payload['partial_reason'] );
		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertSame(
			'[ran-booster] provider diagnostic operation failed {"provider":"gh","step":"' . $step . '","exception_class":"LogicException","exception_code":"75"}',
			$line
		);
		self::assertStringNotContainsString( self::SECRET_CANARY, $line );
	}
}

final class EscapingDiagnosticProvider implements RepositoryProvider, WebhookNormalizer {
	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public function __construct( private Throwable $failure, private bool $failReadiness ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Fixture', 'https://example.test/', 'Owner' );
	}

	public function getProviderDiagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
		return new class( $this->failure, $this->failReadiness ) implements \RAN\RepositoryProvider\ProviderDiagnostics {
			public function __construct( private Throwable $failure, private bool $failReadiness ) {
			}

			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				if ( ! $this->failReadiness ) {
					throw $this->failure;
				}

				return array();
			}
		};
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new class() implements ProviderWebhookPolicy {
			public function getProvider(): ProviderCode {
				return ProviderCode::parse( 'gh' );
			}
			public function getRetainedHeaders(): array {
				return array();
			}
			public function getSignatureHeader(): string {
				return 'x-fixture-signature';
			}
			public function normalizeWebhook( array $metadata, mixed $secret ): array {
				unset( $metadata, $secret );
				return array();
			}
			public function getConstantNames(): array {
				return array();
			}
			public function webhookFromConstants( array $constants ): ?array {
				unset( $constants );
				return null;
			}
			public function authorizeWebhook( \RAN\RepositoryProvider\SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
				unset( $verification, $repositoryAuthorityId, $repository );
				return false;
			}
			public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
				return $target === $repositoryLocator;
			}
		};
	}

	public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		throw $this->failure;
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );
		return WebhookEnvelope::ignored();
	}
}

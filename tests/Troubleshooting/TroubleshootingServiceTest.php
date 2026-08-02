<?php

declare(strict_types=1);

namespace Tests\Troubleshooting;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private focused fixtures keep orchestration behavior visible beside its tests.

use Closure;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RAN\Troubleshooting\TroubleshootingService;

final class TroubleshootingServiceTest extends TestCase {

	public function testFormPayloadUsesSafeCredentialLabelsWithoutRunningLocalOrProviderChecks(): void {
		$local    = new TroubleshootingLocalFixture( $this->localResults() );
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$secrets  = new TroubleshootingSecretsFixture(
			array(
				'gh' => array(
					'id'            => 'site-private',
					'label'         => 'Site private access',
					'configuration' => array( 'account' => 'configuration-canary' ),
					'secret'        => 'secret-canary',
				),
			)
		);
		$payload  = ( new TroubleshootingService( $local, new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ), null, $secrets ) )->formPayload();

		self::assertFalse( $payload['ran'] );
		self::assertSame( array( 'gh' => 'GitHub fixture' ), $payload['providers'] );
		self::assertSame(
			array(
				'gh' => array(
					array(
						'id'    => 'site-private',
						'label' => 'Site private access',
					),
				),
			),
			$payload['credentials']
		);
		self::assertArrayNotHasKey( 'configuration', $payload['credentials']['gh'][0] );
		self::assertArrayNotHasKey( 'secret', $payload['credentials']['gh'][0] );
		self::assertSame( 0, $local->runs );
		self::assertSame( 0, $provider->runs );
	}

	public function testRunsOnlyTheSelectedProviderAndPreservesDeterministicOrder(): void {
		$selected = new TroubleshootingProviderFixture(
			'gh',
			fn(): array => array( $this->diagnosticResult( 'gh.connectivity' ), $this->diagnosticResult( 'gh.credential' ) )
		);
		$idle     = new TroubleshootingProviderFixture( 'bb', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->localResults() ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $selected, $idle ) )
		);

		$payload = $service->diagnose( 'gh', 'private-profile', 'owner/repository' );

		self::assertFalse( $payload['partial'] );
		self::assertSame( 1, $selected->runs );
		self::assertSame( 0, $idle->runs );
		self::assertSame(
			array( 'local.one', 'local.two', 'local.three', 'local.four', 'local.five', 'gh.connectivity', 'gh.credential' ),
			array_column( $payload['results'], 'code' )
		);
		self::assertStringNotContainsString( 'private-profile', $payload['report'] );
		self::assertStringNotContainsString( 'owner/repository', $payload['report'] );
	}

	public function testInvalidProviderStillReturnsAllLocalRowsAndSafePartialState(): void {
		$payload = $this->service()->diagnose( 'unknown-provider', null, null );

		self::assertTrue( $payload['partial'] );
		self::assertSame( 'provider_unavailable', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( '', $payload['selected_provider'] );
	}

	public function testMalformedBoundedInputReturnsLocalRowsAndDoesNotRunProvider(): void {
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->localResults() ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) )
		);

		$payload = $service->diagnose( 'gh', str_repeat( 'a', 129 ), null );

		self::assertSame( 'provider_results_invalid', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function testLocalPartialStopsBeforeProviderWork(): void {
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( array( $this->diagnosticResult( 'local.multisite' ) ), true ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) )
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'local_incomplete', $payload['partial_reason'] );
		self::assertCount( 1, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function testDeadlineStartsBeforeLocalChecksAndReturnsExplicitPartialResults(): void {
		$now      = 0.0;
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$local    = new TroubleshootingLocalFixture(
			$this->localResults(),
			false,
			static function () use ( &$now ): void {
				$now = 11.0;
			}
		);
		$service  = new TroubleshootingService(
			$local,
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function testCaughtSixthClaimIsRecordedAsRemoteBudgetExhaustion(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					try {
						$request->claimRemoteCall();
					} catch ( \Throwable ) {
						// Provider deliberately translates its failed sixth claim into a result.
						self::assertSame( 5, $request->getRemoteCalls() );
					}
				}

				return array( $this->diagnosticResult( 'gh.connectivity' ) );
			}
		);
		$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertSame( 'remote_calls_exhausted', $payload['partial_reason'] );
		self::assertCount( 6, $payload['results'] );
	}

	public function testRecordedBudgetFailureOutranksALaterProviderException(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			static function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					try {
						$request->claimRemoteCall();
					} catch ( \Throwable ) {
						throw new \RuntimeException( 'provider exception canary' );
					}
				}

				return array();
			}
		);
		$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertSame( 'remote_calls_exhausted', $payload['partial_reason'] );
		self::assertStringNotContainsString( 'canary', $payload['report'] );
	}

	public function testProviderOutputIsClampedWithoutDisplacingLocalRows(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			fn(): array => array(
				$this->diagnosticResult( 'gh.one' ),
				$this->diagnosticResult( 'gh.two' ),
				$this->diagnosticResult( 'gh.three' ),
				$this->diagnosticResult( 'gh.four' ),
			)
		);
		$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertSame( 'result_limit_exhausted', $payload['partial_reason'] );
		self::assertCount( 8, $payload['results'] );
		self::assertSame( $this->localCodes(), array_slice( array_column( $payload['results'], 'code' ), 0, 5 ) );
	}

	public function testWrongPrefixAndDuplicateProviderResultsAreSafelyRejected(): void {
		foreach (
			array(
				array( $this->diagnosticResult( 'bb.wrong' ) ),
				array( $this->diagnosticResult( 'gh.same' ), $this->diagnosticResult( 'gh.same' ) ),
				array( 'not-a-result' ),
			) as $providerResults
		) {
			$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => $providerResults );
			$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

			self::assertSame( 'provider_results_invalid', $payload['partial_reason'] );
			self::assertSame( $this->localCodes(), array_slice( array_column( $payload['results'], 'code' ), 0, 5 ) );
		}
	}

	public function testOptionalWebhookReadinessUsesOnlyTheFinalAvailableSlotAndSharesDeadline(): void {
		$now      = 0.0;
		$provider = new TroubleshootingWebhookProviderFixture(
			'gh',
			fn(): array => array( $this->diagnosticResult( 'gh.connectivity' ), $this->diagnosticResult( 'gh.credential' ) ),
			function () use ( &$now ): ProviderDiagnosticResult {
				$now = 11.0;

				return $this->diagnosticResult( 'gh.webhook' );
			}
		);
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->localResults() ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertCount( 8, $payload['results'] );
		self::assertSame( 'gh.webhook', $payload['results'][7]['code'] );
		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertSame( 1, $provider->readinessRuns );
	}

	public function testProviderRowsReturnedAfterDeadlineAreDiscardedButLocalRowsRemain(): void {
		$now      = 0.0;
		$provider = new TroubleshootingProviderFixture(
			'gh',
			function () use ( &$now ): array {
				$now = 11.0;

				return array( $this->diagnosticResult( 'gh.late' ) );
			}
		);
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->localResults() ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertSame( $this->localCodes(), array_column( $payload['results'], 'code' ) );
	}

	public function testWebhookReadinessIsNotCalledWhenProviderRowsFillTheResultLimit(): void {
		$provider = new TroubleshootingWebhookProviderFixture(
			'gh',
			fn(): array => array(
				$this->diagnosticResult( 'gh.one' ),
				$this->diagnosticResult( 'gh.two' ),
				$this->diagnosticResult( 'gh.three' ),
			),
			fn(): ProviderDiagnosticResult => $this->diagnosticResult( 'gh.webhook' )
		);

		$payload = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertFalse( $payload['partial'] );
		self::assertCount( 8, $payload['results'] );
		self::assertSame( 0, $provider->readinessRuns );
	}

	/** @return list<ProviderDiagnosticResult> */
	private function localResults(): array {
		return array_map( fn( string $code ): ProviderDiagnosticResult => $this->diagnosticResult( $code ), $this->localCodes() );
	}

	/** @return list<string> */
	private function localCodes(): array {
		return array( 'local.one', 'local.two', 'local.three', 'local.four', 'local.five' );
	}

	private function diagnosticResult( string $code ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult( ProviderDiagnosticResult::PASSED, $code, 'The check completed safely.', 'No action is required.' );
	}

	private function service( ?TroubleshootingProviderFixture $provider = null ): TroubleshootingService {
		return new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->localResults() ),
			new ProviderRegistry( new \Tests\Support\NullLoggingFacade(), array( $provider ?? new TroubleshootingProviderFixture( 'gh', static fn(): array => array() ) ) )
		);
	}
}

class TroubleshootingLocalFixture extends LocalTroubleshootingService {
	public int $runs = 0;

	/** @param list<ProviderDiagnosticResult> $results */
	public function __construct(
		private array $results,
		private bool $partial = false,
		private ?Closure $onRun = null
	) {
		parent::__construct( new SecretsFile( '/unused/ran-booster-troubleshooting.php', array() ) );
	}

	public function diagnose(): array {
		++$this->runs;
		if ( null !== $this->onRun ) {
			( $this->onRun )();
		}

		return array(
			'results' => $this->results,
			'partial' => $this->partial,
		);
	}
}

final class TroubleshootingSecretsFixture extends SecretsFile {
	/** @param array<string, array<string, mixed>> $profiles */
	public function __construct( private array $profiles ) {
		parent::__construct( '/unused/ran-booster-troubleshooting-secrets.php', array() );
	}

	public function credentialProfiles( ProviderCode|string $provider ): array {
		$providerCode = $provider instanceof ProviderCode ? $provider->value : $provider;

		return isset( $this->profiles[ $providerCode ] )
			? array( $this->profiles[ $providerCode ]['id'] => $this->profiles[ $providerCode ] )
			: array();
	}
}

class TroubleshootingProviderFixture implements RepositoryProvider {
	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public int $runs = 0;

	public function __construct( protected string $code, private Closure $diagnose ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'GitHub fixture', 'https://example.test/', 'Owner' );
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class( $this ) implements ProviderDiagnostics {
			public function __construct( private TroubleshootingProviderFixture $provider ) {
			}

			public function diagnose( ProviderDiagnosticRequest $request ): array {
				++$this->provider->runs;

				return $this->provider->runDiagnostics( $request );
			}
		};
	}

	public function runDiagnostics( ProviderDiagnosticRequest $request ): array {
		return ( $this->diagnose )( $request );
	}
}

final class TroubleshootingWebhookProviderFixture extends TroubleshootingProviderFixture implements WebhookNormalizer {
	public int $readinessRuns = 0;

	public function __construct( string $code, Closure $diagnose, private Closure $readiness ) {
		parent::__construct( $code, $diagnose );
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new class( ProviderCode::parse( $this->code ) ) implements ProviderWebhookPolicy {
			public function __construct( private ProviderCode $provider ) {
			}

			public function getProvider(): ProviderCode {
				return $this->provider;
			}

			public function getRetainedHeaders(): array {
				return array();
			}

			public function getSignatureHeader(): string {
				return 'x-fixture-signature';
			}

			public function normalizeWebhook( array $metadata, mixed $secret ): array {
				return array(
					'label'        => 'Fixture',
					'scope'        => 'global',
					'target'       => '',
					'authority_id' => '',
					'secret'       => str_repeat( 'f', 32 ),
				);
			}

			public function getConstantNames(): array {
				return array();
			}

			public function webhookFromConstants( array $constants ): ?array {
				return null;
			}

			public function authorizeWebhook( \RAN\RepositoryProvider\SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
				return true;
			}

			public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
				return $target === $repositoryLocator;
			}
		};
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		++$this->readinessRuns;

		return ( $this->readiness )();
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}

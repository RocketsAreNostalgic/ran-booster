<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

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

	public function test_form_payload_uses_safe_credential_labels_without_running_local_or_provider_checks(): void {
		$local    = new TroubleshootingLocalFixture( $this->local_results() );
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
		$payload  = ( new TroubleshootingService( $local, new ProviderRegistry( array( $provider ) ), null, $secrets ) )->form_payload();

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

	public function test_runs_only_the_selected_provider_and_preserves_deterministic_order(): void {
		$selected = new TroubleshootingProviderFixture(
			'gh',
			fn(): array => array( $this->diagnostic_result( 'gh.connectivity' ), $this->diagnostic_result( 'gh.credential' ) )
		);
		$idle     = new TroubleshootingProviderFixture( 'bb', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->local_results() ),
			new ProviderRegistry( array( $selected, $idle ) )
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

	public function test_invalid_provider_still_returns_all_local_rows_and_safe_partial_state(): void {
		$payload = $this->service()->diagnose( 'unknown-provider', null, null );

		self::assertTrue( $payload['partial'] );
		self::assertSame( 'provider_unavailable', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( '', $payload['selected_provider'] );
	}

	public function test_malformed_bounded_input_returns_local_rows_and_does_not_run_provider(): void {
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->local_results() ),
			new ProviderRegistry( array( $provider ) )
		);

		$payload = $service->diagnose( 'gh', str_repeat( 'a', 129 ), null );

		self::assertSame( 'provider_results_invalid', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function test_local_partial_stops_before_provider_work(): void {
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( array( $this->diagnostic_result( 'local.multisite' ) ), true ),
			new ProviderRegistry( array( $provider ) )
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'local_incomplete', $payload['partial_reason'] );
		self::assertCount( 1, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function test_deadline_starts_before_local_checks_and_returns_explicit_partial_results(): void {
		$now      = 0.0;
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$local    = new TroubleshootingLocalFixture(
			$this->local_results(),
			false,
			static function () use ( &$now ): void {
				$now = 11.0;
			}
		);
		$service  = new TroubleshootingService(
			$local,
			new ProviderRegistry( array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame( 0, $provider->runs );
	}

	public function test_caught_sixth_claim_is_recorded_as_remote_budget_exhaustion(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					try {
						$request->claim_remote_call();
					} catch ( \Throwable ) {
						// Provider deliberately translates its failed sixth claim into a result.
						self::assertSame( 5, $request->get_remote_calls() );
					}
				}

				return array( $this->diagnostic_result( 'gh.connectivity' ) );
			}
		);
		$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertSame( 'remote_calls_exhausted', $payload['partial_reason'] );
		self::assertCount( 6, $payload['results'] );
	}

	public function test_recorded_budget_failure_outranks_a_later_provider_exception(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			static function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					try {
						$request->claim_remote_call();
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

	public function test_provider_output_is_clamped_without_displacing_local_rows(): void {
		$provider = new TroubleshootingProviderFixture(
			'gh',
			fn(): array => array(
				$this->diagnostic_result( 'gh.one' ),
				$this->diagnostic_result( 'gh.two' ),
				$this->diagnostic_result( 'gh.three' ),
				$this->diagnostic_result( 'gh.four' ),
			)
		);
		$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertSame( 'result_limit_exhausted', $payload['partial_reason'] );
		self::assertCount( 8, $payload['results'] );
		self::assertSame( $this->local_codes(), array_slice( array_column( $payload['results'], 'code' ), 0, 5 ) );
	}

	public function test_wrong_prefix_and_duplicate_provider_results_are_safely_rejected(): void {
		foreach (
			array(
				array( $this->diagnostic_result( 'bb.wrong' ) ),
				array( $this->diagnostic_result( 'gh.same' ), $this->diagnostic_result( 'gh.same' ) ),
				array( 'not-a-result' ),
			) as $provider_results
		) {
			$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => $provider_results );
			$payload  = $this->service( $provider )->diagnose( 'gh', null, null );

			self::assertSame( 'provider_results_invalid', $payload['partial_reason'] );
			self::assertSame( $this->local_codes(), array_slice( array_column( $payload['results'], 'code' ), 0, 5 ) );
		}
	}

	public function test_optional_webhook_readiness_uses_only_the_final_available_slot_and_shares_deadline(): void {
		$now      = 0.0;
		$provider = new TroubleshootingWebhookProviderFixture(
			'gh',
			fn(): array => array( $this->diagnostic_result( 'gh.connectivity' ), $this->diagnostic_result( 'gh.credential' ) ),
			function () use ( &$now ): ProviderDiagnosticResult {
				$now = 11.0;

				return $this->diagnostic_result( 'gh.webhook' );
			}
		);
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->local_results() ),
			new ProviderRegistry( array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertCount( 8, $payload['results'] );
		self::assertSame( 'gh.webhook', $payload['results'][7]['code'] );
		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertSame( 1, $provider->readiness_runs );
	}

	public function test_provider_rows_returned_after_deadline_are_discarded_but_local_rows_remain(): void {
		$now      = 0.0;
		$provider = new TroubleshootingProviderFixture(
			'gh',
			function () use ( &$now ): array {
				$now = 11.0;

				return array( $this->diagnostic_result( 'gh.late' ) );
			}
		);
		$service  = new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->local_results() ),
			new ProviderRegistry( array( $provider ) ),
			static function () use ( &$now ): float {
				return $now;
			}
		);

		$payload = $service->diagnose( 'gh', null, null );

		self::assertSame( 'deadline_exhausted', $payload['partial_reason'] );
		self::assertSame( $this->local_codes(), array_column( $payload['results'], 'code' ) );
	}

	public function test_webhook_readiness_is_not_called_when_provider_rows_fill_the_result_limit(): void {
		$provider = new TroubleshootingWebhookProviderFixture(
			'gh',
			fn(): array => array(
				$this->diagnostic_result( 'gh.one' ),
				$this->diagnostic_result( 'gh.two' ),
				$this->diagnostic_result( 'gh.three' ),
			),
			fn(): ProviderDiagnosticResult => $this->diagnostic_result( 'gh.webhook' )
		);

		$payload = $this->service( $provider )->diagnose( 'gh', null, null );

		self::assertFalse( $payload['partial'] );
		self::assertCount( 8, $payload['results'] );
		self::assertSame( 0, $provider->readiness_runs );
	}

	/** @return list<ProviderDiagnosticResult> */
	private function local_results(): array {
		return array_map( fn( string $code ): ProviderDiagnosticResult => $this->diagnostic_result( $code ), $this->local_codes() );
	}

	/** @return list<string> */
	private function local_codes(): array {
		return array( 'local.one', 'local.two', 'local.three', 'local.four', 'local.five' );
	}

	private function diagnostic_result( string $code ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult( ProviderDiagnosticResult::PASSED, $code, 'The check completed safely.', 'No action is required.' );
	}

	private function service( ?TroubleshootingProviderFixture $provider = null ): TroubleshootingService {
		return new TroubleshootingService(
			new TroubleshootingLocalFixture( $this->local_results() ),
			new ProviderRegistry( array( $provider ?? new TroubleshootingProviderFixture( 'gh', static fn(): array => array() ) ) )
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
class TroubleshootingLocalFixture extends LocalTroubleshootingService {
	public int $runs = 0;

	/** @param list<ProviderDiagnosticResult> $results */
	public function __construct(
		private array $results,
		private bool $partial = false,
		private ?Closure $on_run = null
	) {
		parent::__construct( new SecretsFile( '/unused/ran-booster-troubleshooting.php', array() ) );
	}

	public function diagnose(): array {
		++$this->runs;
		if ( null !== $this->on_run ) {
			( $this->on_run )();
		}

		return array(
			'results' => $this->results,
			'partial' => $this->partial,
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class TroubleshootingSecretsFixture extends SecretsFile {
	/** @param array<string, array<string, mixed>> $profiles */
	public function __construct( private array $profiles ) {
		parent::__construct( '/unused/ran-booster-troubleshooting-secrets.php', array() );
	}

	public function credential_profiles( ProviderCode|string $provider ): array {
		$provider_code = $provider instanceof ProviderCode ? $provider->value : $provider;

		return isset( $this->profiles[ $provider_code ] )
			? array( $this->profiles[ $provider_code ]['id'] => $this->profiles[ $provider_code ] )
			: array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
class TroubleshootingProviderFixture implements RepositoryProvider {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public int $runs = 0;

	public function __construct( protected string $code, private Closure $diagnose ) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'GitHub fixture', 'https://example.test/', 'Owner' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class( $this ) implements ProviderDiagnostics {
			public function __construct( private TroubleshootingProviderFixture $provider ) {
			}

			public function diagnose( ProviderDiagnosticRequest $request ): array {
				++$this->provider->runs;

				return $this->provider->run_diagnostics( $request );
			}
		};
	}

	/** @return list<mixed> Includes invalid provider results to exercise rejection. */
	public function run_diagnostics( ProviderDiagnosticRequest $request ): array {
		return ( $this->diagnose )( $request );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class TroubleshootingWebhookProviderFixture extends TroubleshootingProviderFixture implements WebhookNormalizer {
	public int $readiness_runs = 0;

	public function __construct( string $code, Closure $diagnose, private Closure $readiness ) {
		parent::__construct( $code, $diagnose );
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return new class( ProviderCode::parse( $this->code ) ) implements ProviderWebhookPolicy {
			public function __construct( private ProviderCode $provider ) {
			}

			public function get_provider(): ProviderCode {
				return $this->provider;
			}

			public function get_retained_headers(): array {
				return array();
			}

			public function get_signature_header(): string {
				return 'x-fixture-signature';
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
			public function normalize_webhook( array $metadata, mixed $secret ): array {
				return array(
					'label'        => 'Fixture',
					'scope'        => 'global',
					'target'       => '',
					'authority_id' => '',
					'secret'       => str_repeat( 'f', 32 ),
				);
			}

			public function get_constant_names(): array {
				return array();
			}

			public function webhook_from_constants( array $constants ): ?array {
				return null;
			}

			public function authorize_webhook( \RAN\RepositoryProvider\SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
				return true;
			}

			public function repository_target_matches( string $target, string $repository_locator ): bool {
				return $target === $repository_locator;
			}
		};
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		++$this->readiness_runs;

		return ( $this->readiness )();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}

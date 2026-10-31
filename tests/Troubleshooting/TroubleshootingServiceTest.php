<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use Closure;
use RAN\Activity\ActivityStore;
use RAN\Tests\Activity\ActivityConnection;
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
use RAN\Troubleshooting\InstallationFacts;
use RAN\Troubleshooting\SupportReport;

require_once dirname( __DIR__ ) . '/Activity/ActivityConnection.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityWordPressFunctions.php';
require_once __DIR__ . '/DiagnosticRunMemory.php';
require_once __DIR__ . '/DiagnosticRunFixture.php';

final class TroubleshootingServiceTest extends TestCase {

	public function test_installation_context_is_one_passive_snapshot_shared_with_the_report(): void {
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		$provider                  = new TroubleshootingProviderFixture( 'gh', static fn (): array => array() );
		$service                   = $this->service( $provider );
		$form                      = $service->form_payload();
		$provider->reject_metadata = true;
		$form['core_self_update']  = array(
			'requested_mode' => 'auto',
			'effective_mode' => 'disabled',
			'reason'         => 'source_installation',
			'last_check'     => 0,
			'next_check'     => 1,
			'marker_version' => '1.0.0-beta.1',
		);
		$selection                 = array(
			'state'      => 'available',
			'available'  => true,
			'current'    => array(
				'selected'    => 'bundled',
				'declaration' => array(
					'display_name'           => 'Shipped GitHub module',
					'display_version'        => '1.2.3',
					'implementation_id'      => 'ran/github',
					'provider_api_version'   => 15,
					'compatibility_revision' => '1',
					'facets'                 => array( 'RAN\\RepositoryProvider\\RepositoryBrowser' ),
					'api_credential_formats' => array( 'github-classic-pat-v1' ),
					'webhook_signing_format' => 'github-hmac-sha256-v1',
				),
			),
			'candidates' => array(),
		);
		$payload                   = $service->with_installation_context( $form, $selection );
		self::assertSame( 0, $provider->runs );
		self::assertFalse( $payload['ran'] );
		self::assertSame( $form['core_self_update'], $payload['core_self_update'] );
		self::assertSame( 'Bundled with Booster', $payload['installation_details']['GitHub provider']['Source'] );
		self::assertSame( '15', $payload['installation_details']['GitHub provider']['Provider API'] );
		self::assertSame( '1970-01-01T00:00:00+00:00', $payload['installation_details']['Booster Core updates']['Last check (UTC)'] );
		foreach ( $payload['installation_details'] as $heading => $rows ) {
			self::assertStringContainsString( "\n" . $heading . "\n", $payload['report'] );
			foreach ( $rows as $label => $value ) {
				self::assertStringContainsString( $label . ': ' . $value, $payload['report'] );
			}
		}
		self::assertStringContainsString( 'Local declarations only;', $payload['report'] );
		self::assertStringContainsString( "Diagnostic checks\nNot run", $payload['report'] );
		self::assertSame( $payload, $service->with_installation_context( $payload, $selection ) );
		$form['ran']            = true;
		$form['results']        = array( $this->diagnostic_result( 'local.snapshot' )->to_array() );
		$form['partial']        = true;
		$form['partial_reason'] = 'provider_unavailable';
		$ran                    = $service->with_installation_context( $form, $selection );
		self::assertStringContainsString( 'Partial: yes', $ran['report'] );
		self::assertStringContainsString( '[pass] local.snapshot | The check completed safely.', $ran['report'] );
		self::assertSame( $payload['installation_details'], $ran['installation_details'] );
	}

	public function test_operator_check_context_projects_only_safe_recorded_values(): void {
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		$provider                 = new TroubleshootingProviderFixture( 'gh', static fn (): array => array() );
		$service                  = $this->service( $provider );
		$form                     = $service->form_payload();
		$form['core_self_update'] = array(
			'last_successful_check' => 1699999000,
			'last_check_outcome'    => 'failed',
			'last_check_code'       => 'check_failed',
			'check_cooldown_until'  => 1700000300,
			'next_check'            => 1700001000,
		);
		$rows                     = $service->with_installation_context( $form, null )['installation_details']['Booster Core updates'];
		self::assertSame( gmdate( 'c', 1699999000 ), $rows['Last successful operator check (UTC)'] );
		self::assertSame( 'failed', $rows['Last operator check outcome'] );
		self::assertSame( 'check_failed', $rows['Last operator check code'] );
		self::assertSame( gmdate( 'c', 1700000300 ), $rows['Operator check cooldown until (UTC)'] );
		self::assertSame( gmdate( 'c', 1700001000 ), $rows['Next check (UTC)'] );
		foreach ( array( 'success', 'skipped', 'unknown', 'rejected' ) as $outcome ) {
			$form['core_self_update']['last_check_outcome'] = $outcome;
			self::assertSame( $outcome, $service->with_installation_context( $form, null )['installation_details']['Booster Core updates']['Last operator check outcome'] );
		}
		$form['core_self_update'] = array(
			'last_successful_check' => 0,
			'check_cooldown_until'  => 253402300800,
			'last_check_outcome'    => 'injected',
			'last_check_code'       => '/private/path',
		);
		$payload                  = $service->with_installation_context( $form, null );
		foreach ( array( 'Last successful operator check (UTC)', 'Operator check cooldown until (UTC)', 'Last operator check outcome', 'Last operator check code' ) as $label ) {
			self::assertSame( 'Unavailable', $payload['installation_details']['Booster Core updates'][ $label ] );
		}
		self::assertStringNotContainsString( '/private/path', $payload['report'] );
		self::assertSame( 0, $provider->runs );
	}

	public function test_installation_context_rejects_unsafe_claims_and_never_serializes_raw_state(): void {
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		$service               = $this->service();
		$form                  = $service->form_payload();
		$form['credential_id'] = 'credential-private-canary';
		$form['repository']    = 'private-owner/private-repository';
		$unsafe_values         = array( '/srv/private-path-canary/config.php', 'C:\\private-path-canary\\config.php', 'Authorization: secret-header-canary', 'Cookie: private-cookie-canary', 'Bearer private-token-canary', 'github_pat_privatecanary', '<b>html-canary</b>', "line\ncontrol-canary", str_repeat( 'oversize-canary', 40 ), new \stdClass(), array( 'raw-secret-canary' ) );
		foreach ( $unsafe_values as $unsafe ) {
			$form['core_self_update'] = array(
				'reason'     => $unsafe,
				'last_check' => '123456',
				'next_check' => -1,
				'secret'     => 'core-secret-canary',
			);
			$form['providers']['gh']  = 'Authorization: provider-header-canary';
			$declaration              = array(
				'display_name'           => $unsafe,
				'display_version'        => $unsafe,
				'implementation_id'      => $unsafe,
				'provider_api_version'   => '15',
				'compatibility_revision' => $unsafe,
				'plugin_basename'        => 'private-path-canary/private.php',
				'secret'                 => 'declaration-secret-canary',
				'facets'                 => array( $unsafe ),
				'api_credential_formats' => array( $unsafe ),
			);
			$selection                = array(
				'state'      => $unsafe,
				'available'  => 'true',
				'current'    => array(
					'selected'    => 'external',
					'declaration' => $declaration,
					'approval'    => 'approval-private-canary',
				),
				'candidates' => array( '/srv/candidate-private-canary' => $declaration ),
				'secret'     => 'selection-private-canary',
			);
			$payload                  = $service->with_installation_context( $form, $selection );
			self::assertSame( 'Unavailable', $payload['installation_details']['GitHub provider']['Name'] );
			self::assertSame( 'Unavailable', $payload['installation_details']['GitHub provider']['Provider API'] );
			self::assertSame( 'Unknown', $payload['installation_details']['GitHub provider']['Operational availability'] );
			self::assertSame( 'Unavailable', $payload['installation_details']['Booster Core updates']['Policy reason'] );
			self::assertStringNotContainsString( 'canary', $payload['report'] );
			self::assertStringNotContainsString( 'private-owner/private-repository', $payload['report'] );
			self::assertSame( $payload['report'], $service->with_installation_context( $payload, $selection )['report'] );
		}
	}

	public function test_missing_provider_and_candidate_bounds_remain_explicit_in_context(): void {
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		$service = new TroubleshootingService( new TroubleshootingLocalFixture( array() ), new ProviderRegistry() );
		$payload = $service->with_installation_context( $service->form_payload(), null );
		self::assertSame( 'Unknown', $payload['installation_details']['GitHub provider']['Source'] );
		self::assertSame( 'Unavailable', $payload['installation_details']['Diagnostic Git provider']['Git provider'] );
		$selection = array(
			'state'      => 'missing',
			'available'  => false,
			'current'    => array(
				'selected'    => 'external',
				'declaration' => array( 'display_name' => 'Absent replacement' ),
			),
			'candidates' => array_fill(
				0,
				8,
				array(
					'display_name' => 'Enabled replacement',
					'facets'       => array_fill( 0, 33, 'excess-facet-canary' ),
				)
			),
		);
		$payload   = $service->with_installation_context( $payload, $selection );
		self::assertSame( 'External replacement', $payload['installation_details']['GitHub provider']['Source'] );
		self::assertSame( 'missing', $payload['installation_details']['GitHub provider']['Selection state'] );
		self::assertSame( '8', $payload['installation_details']['GitHub provider']['Enabled candidates'] );
		self::assertArrayHasKey( 'GitHub candidate 4', $payload['installation_details'] );
		self::assertArrayNotHasKey( 'GitHub candidate 5', $payload['installation_details'] );
		self::assertStringNotContainsString( 'excess-facet-canary', $payload['report'] );
		self::assertFalse( $payload['ran'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_activity_captures_actor_before_provider_work(): void {
		require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
		$connection                                       = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options']          = array( ActivityStore::VERSION_OPTION => '1' );
		$GLOBALS['ran_booster_package_operation_user_id'] = 17;
		$provider = new TroubleshootingProviderFixture(
			'gh',
			static function (): array {
				$GLOBALS['ran_booster_package_operation_user_id'] = 29;
				return array();
			}
		);
		$service  = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), activity: new ActivityStore( $connection ) );
		$service->diagnose( 'gh', null, null );
		self::assertSame( 17, $connection->inserted[0]['actor'] );
		self::assertSame( 29, get_current_user_id() );
	}

	public function test_activity_uses_registered_metadata_without_calling_provider_again(): void {
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$connection                              = new ActivityConnection();
		$provider                                = new TroubleshootingProviderFixture( 'gh', static fn (): array => array() );
		$registry                                = new ProviderRegistry( array( $provider ) );
		$provider->reject_metadata               = true;
		$service                                 = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), $registry, activity: new ActivityStore( $connection ) );
		$payload                                 = $service->diagnose( 'gh', null, null );
		self::assertFalse( $payload['partial'] );
		self::assertSame( 1, $provider->runs );
		self::assertSame( 'gh', $connection->inserted[0]['provider'] );
		self::assertSame( 'gh', $connection->inserted[0]['subject_identity'] );
		unset( $GLOBALS['ran_booster_activity_options'] );
	}

	public function test_activity_records_status_counts_without_locator_credentials_or_exception(): void {
		$connection                              = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$provider                                = new TroubleshootingProviderFixture(
			'gh',
			static fn (): array => array(
				new ProviderDiagnosticResult( 'warning', 'gh.warning', 'Configuration needs attention.', 'Review configuration.' ),
				new ProviderDiagnosticResult( 'not_configured', 'gh.missing', 'Not configured.', 'Configure the provider.' ),
				new ProviderDiagnosticResult( 'fail', 'gh.failed', 'Remote check failed.', 'Try again.', new \RuntimeException( 'private-exception-canary' ) ),
			)
		);
		$service                                 = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), activity: new ActivityStore( $connection ) );
		$payload                                 = $service->diagnose( 'gh', 'credential-canary', 'private-owner/private-repository' );
		self::assertCount( 8, $payload['results'] );
		self::assertFalse( $payload['partial'] );
		self::assertSame( 1, $provider->runs );
		self::assertCount( 1, $connection->inserted );
		self::assertSame( 'provider', $connection->inserted[0]['subject_type'] );
		self::assertNull( $connection->inserted[0]['adoption_token'] );
		$writes = array_values( array_filter( $connection->prepared, static fn ( array $query ): bool => str_starts_with( $query['sql'], 'UPDATE ' ) ) );
		self::assertCount( 1, $writes );
		self::assertSame( 'failed', $writes[0]['args'][0] );
		self::assertSame( 'diagnostics.failed', $writes[0]['args'][1] );
		self::assertSame(
			array(
				'diagnostic_failed'         => 1,
				'diagnostic_not_configured' => 1,
				'diagnostic_passed'         => 5,
				'diagnostic_warning'        => 1,
				'partial_reason'            => null,
			),
			json_decode( (string) $writes[0]['args'][4], true, flags: JSON_THROW_ON_ERROR )
		);
		$recorded = json_encode( array( $connection->prepared, $connection->inserted ), JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Inspect every persisted scalar in a WordPress-free privacy regression.
		foreach ( array( 'private-exception-canary', 'credential-canary', 'private-owner/private-repository', 'Remote check failed.' ) as $canary ) {
			self::assertStringNotContainsString( $canary, $recorded );
		}
		unset( $GLOBALS['ran_booster_activity_options'] );
	}

	public function test_partial_and_failed_storage_leave_diagnostic_budget_and_payload_unchanged(): void {
		$connection                              = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$provider                                = new TroubleshootingProviderFixture(
			'gh',
			static function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					$request->claim_remote_call();
				}
				return array();
			}
		);
		$service                                 = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), activity: new ActivityStore( $connection ) );
		$payload                                 = $service->diagnose( 'gh', null, null );
		self::assertSame( 'remote_calls_exhausted', $payload['partial_reason'] );
		$writes = array_values( array_filter( $connection->prepared, static fn ( array $query ): bool => str_starts_with( $query['sql'], 'UPDATE ' ) ) );
		self::assertSame( 'diagnostics.partial', $writes[0]['args'][1] );
		self::assertStringContainsString( 'remote_calls_exhausted', (string) $writes[0]['args'][4] );
		$connection->fail_insert = true;
		self::assertSame( $payload, $service->diagnose( 'gh', null, null ) );
		self::assertSame( 2, $provider->runs );
		unset( $GLOBALS['ran_booster_activity_options'] );
	}

	public function test_warning_and_not_configured_remain_distinct_from_passed(): void {
		foreach ( array(
			'pass'           => array( 'succeeded', 'diagnostics.passed' ),
			'warning'        => array( 'rejected', 'diagnostics.warning' ),
			'not_configured' => array( 'skipped', 'diagnostics.not_configured' ),
		) as $result_status => $expected ) {
			$connection                              = new ActivityConnection();
			$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
			$provider                                = new TroubleshootingProviderFixture( 'gh', static fn (): array => array( new ProviderDiagnosticResult( $result_status, 'gh.check', 'The check completed.', 'Review configuration.' ) ) );
			$service                                 = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), activity: new ActivityStore( $connection ) );
			$service->diagnose( 'gh', null, null );
			$writes = array_values( array_filter( $connection->prepared, static fn ( array $query ): bool => str_starts_with( $query['sql'], 'UPDATE ' ) ) );
			self::assertSame( $expected, array_slice( $writes[0]['args'], 0, 2 ) );
			self::assertSame( '', $writes[0]['args'][2] );
		}
		unset( $GLOBALS['ran_booster_activity_options'] );
	}

	public function test_passive_form_and_unexpected_failure_preserve_execution_boundary(): void {
		$connection                              = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$exception                               = new \RuntimeException( 'unexpected-private-canary' );
		$local                                   = new TroubleshootingLocalFixture(
			$this->local_results(),
			on_run: static function () use ( $exception ): void {
				throw $exception;
			}
		);
		$provider                                = new TroubleshootingProviderFixture( 'gh', static fn (): array => array() );
		$service                                 = new TroubleshootingService( $local, new ProviderRegistry( array( $provider ) ), activity: new ActivityStore( $connection ) );
		self::assertFalse( $service->form_payload()['ran'] );
		self::assertCount( 0, $connection->inserted );
		try {
			$service->diagnose( 'gh', null, null );
			self::fail( 'Existing diagnostic exception must propagate.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( $exception, $caught );
		}
		$writes = array_values( array_filter( $connection->prepared, static fn ( array $query ): bool => str_starts_with( $query['sql'], 'UPDATE ' ) ) );
		self::assertSame( array(), $writes );
		self::assertSame( 'incomplete', $connection->inserted[0]['status'] );
		self::assertSame( 0, $provider->runs );
		unset( $GLOBALS['ran_booster_activity_options'] );
	}

	public function test_missing_provider_with_activity_still_returns_local_diagnostics(): void {
		$connection                              = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$service                                 = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry(), activity: new ActivityStore( $connection ) );
		$payload                                 = $service->diagnose( 'unknown-provider', null, null );
		self::assertSame( 'provider_unavailable', $payload['partial_reason'] );
		self::assertNotEmpty( $payload['results'] );
		self::assertSame( array(), $connection->inserted );
	}

	public function test_form_payload_uses_safe_credential_labels_without_running_local_or_provider_checks(): void {
		$local    = new TroubleshootingLocalFixture( $this->local_results() );
		$provider = new TroubleshootingProviderFixture( 'gh', static fn(): array => array() );
		$secrets  = new TroubleshootingSecretsFixture(
			array(
				'gh' => array(
					'id'            => 'site-private',
					'provider'      => 'gh',
					'kind'          => 'fixture',
					'source'        => 'file',
					'immutable'     => false,
					'configured'    => true,
					'self_destruct' => false,
					'destroy_on'    => null,
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

	public function test_retained_server_results_survive_navigation_without_repeating_diagnostics(): void {
		require_once dirname( __DIR__ ) . '/Admin/AdminViewWordPressFunctions.php';
		$memory     = new DiagnosticRunMemory();
		$runs       = new DiagnosticRunFixture( $memory );
		$runs->time = microtime( true ) + 10.0;
		$provider   = new TroubleshootingProviderFixture(
			'gh',
			static fn (): array => array(
				new ProviderDiagnosticResult( 'fail', 'gh.credential.invalid', 'Private project prose canary.', 'Contact private-person@example.test.' ),
				new ProviderDiagnosticResult( 'warning', 'gh.privatecanary', 'Private repository prose canary.', 'Review private ref canary.' ),
			)
		);
		$second     = new TroubleshootingProviderFixture(
			'bb',
			static function ( ProviderDiagnosticRequest $request ): array {
				for ( $index = 0; $index < 6; ++$index ) {
					$request->claim_remote_call();
				}
				return array();
			}
		);
		$registry   = new ProviderRegistry( array( $provider, $second ) );
		$local      = new TroubleshootingLocalFixture( $this->local_results() );
		$service    = new TroubleshootingService( $local, $registry, retained_runs: $runs );
		$immediate  = $service->diagnose( 'gh', 'credential-canary', 'private-owner/private-repository' );
		self::assertSame( true, $immediate['retention_available'] ?? null );
		self::assertSame( 'Private project prose canary.', $immediate['results'][5]['message'] );
		$partial = $service->diagnose( 'bb', null, null );
		self::assertSame( 'remote_calls_exhausted', $partial['partial_reason'] );
		self::assertSame( true, $partial['retention_available'] ?? null );
		self::assertCount( 2, $runs->available()['runs'] );
		self::assertSame( 'fail', $runs->available()['runs'][0]['results'][5]['status'] );
		self::assertSame( 'provider.check', $runs->available()['runs'][0]['results'][6]['code'] );
		$reloaded       = new DiagnosticRunFixture( $memory );
		$reloaded->time = $runs->time;
		$report         = new SupportReport( new InstallationFacts( $registry ), $reloaded );
		$navigation     = new TroubleshootingService( $local, $registry, retained_runs: $reloaded, support_report: $report );
		$captured       = $navigation->generate_support_report( array() );
		self::assertStringContainsString( 'Earlier explicit checks, not newly executed tests.', $captured['text'] );
		self::assertStringContainsString( 'gh.credential.invalid', $captured['text'] );
		self::assertStringContainsString( 'Partial: remote_calls_exhausted', $captured['text'] );
		self::assertStringContainsString( 'one saved credential selected; identity withheld', $captured['text'] );
		self::assertIsString( $memory->raw );
		foreach ( array( 'canary', 'private-owner/private-repository', 'private-person@example.test', 'gh.privatecanary' ) as $private ) {
			self::assertStringNotContainsString( $private, $memory->raw );
			self::assertStringNotContainsString( $private, $captured['text'] );
		}
		self::assertSame( 1, $provider->runs );
		self::assertSame( 1, $second->runs );
		self::assertSame( 2, $local->runs );
	}

	public function test_retention_failure_preserves_immediate_diagnostics_and_invalid_provider_is_not_retained(): void {
		$memory         = new DiagnosticRunMemory();
		$memory->refuse = true;
		$runs           = new DiagnosticRunFixture( $memory );
		$runs->time     = microtime( true ) + 10.0;
		$provider       = new TroubleshootingProviderFixture( 'gh', fn (): array => array( $this->diagnostic_result( 'gh.check' ) ) );
		$service        = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), retained_runs: $runs );
		$result         = $service->diagnose( 'gh', null, null );
		self::assertSame( false, $result['retention_available'] ?? null );
		self::assertTrue( $result['ran'] );
		self::assertCount( 6, $result['results'] );
		self::assertSame( 'gh.check', $result['results'][5]['code'] );
		self::assertNull( $memory->raw );
		$writes = $memory->writes;
		$result = $service->diagnose( 'unknown-provider', null, null );
		self::assertSame( 'provider_unavailable', $result['partial_reason'] );
		self::assertSame( $writes, $memory->writes );
	}

	public function test_standing_installation_facts_never_enumerate_credentials_or_execute_operations(): void {
		require_once dirname( __DIR__ ) . '/Admin/AdminViewWordPressFunctions.php';
		$provider                  = new TroubleshootingProviderFixture( 'gh', static fn (): array => array() );
		$registry                  = new ProviderRegistry( array( $provider ) );
		$provider->reject_metadata = true;
		$local                     = new TroubleshootingLocalFixture( $this->local_results() );
		$secrets                   = new TroubleshootingSecretsFixture( array() );
		$service                   = new TroubleshootingService( $local, $registry, secrets: $secrets );
		$facts                     = $service->installation_payload( null, array( 'status' => 'not_checked' ) );
		self::assertSame( array(), $facts['credentials'] );
		self::assertSame( array( 'status' => 'not_checked' ), $facts['storage'] );
		self::assertArrayHasKey( 'PHP', $facts['environment'] );
		self::assertFalse( $facts['ran'] );
		self::assertSame( 0, $local->runs );
		self::assertSame( 0, $provider->runs );
		self::assertSame( 0, $secrets->profile_reads );
		self::assertSame( 0, $secrets->maintenance_reads );
	}

	public function test_diagnostics_preserve_immediate_results_if_the_initiating_scope_changes(): void {
		$memory     = new DiagnosticRunMemory();
		$runs       = new DiagnosticRunFixture( $memory );
		$runs->time = microtime( true ) + 10.0;
		$provider   = new TroubleshootingProviderFixture(
			'gh',
			function () use ( $runs ): array {
				$runs->identity = array(
					'site' => 1,
					'user' => 2,
				);
				return array( $this->diagnostic_result( 'gh.check' ) );
			}
		);
		$service    = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), new ProviderRegistry( array( $provider ) ), retained_runs: $runs );
		$result     = $service->diagnose( 'gh', null, null );
		self::assertTrue( $result['ran'] );
		self::assertSame( false, $result['retention_available'] ?? null );
		self::assertCount( 6, $result['results'] );
		self::assertNull( $memory->raw );
	}

	public function test_retained_implementation_attribution_does_not_change_with_replacement_class(): void {
		require_once dirname( __DIR__ ) . '/Admin/AdminViewWordPressFunctions.php';
		$memory     = new DiagnosticRunMemory();
		$runs       = new DiagnosticRunFixture( $memory );
		$runs->time = microtime( true ) + 10.0;
		$registry   = new ProviderRegistry( array( new TroubleshootingProviderFixture( 'gh', static fn (): array => array() ) ) );
		$service    = new TroubleshootingService( new TroubleshootingLocalFixture( $this->local_results() ), $registry, retained_runs: $runs );
		$service->diagnose( 'gh', null, null );
		$attribution = $runs->available()['runs'][0]['implementation'];
		$replacement = new class( 'gh', static fn (): array => array() ) extends TroubleshootingProviderFixture {};
		$current     = new InstallationFacts( new ProviderRegistry( array( $replacement ) ) );
		self::assertNotSame( $attribution, $current->implementation( 'gh' ) );
		$report = ( new SupportReport( $current, $runs ) )->generate( array() );
		self::assertStringContainsString( 'Implementation 1', $report['text'] );
		self::assertStringContainsString( 'Implementation 2', $report['text'] );
		self::assertStringNotContainsString( $attribution, $report['text'] );
		self::assertSame( $attribution, $runs->available()['runs'][0]['implementation'] );
		self::assertSame( 0, $replacement->runs );
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

/**
 * Deliberately includes an unexpected secret to test the independent display projection.
 *
 * @phpstan-type ProfileWithUnexpectedSecret array{id:string,provider:string,label:string,kind:string,configuration:array<array-key,mixed>,source:'file',immutable:false,configured:true,self_destruct:false,destroy_on:null,secret:string}
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class TroubleshootingSecretsFixture extends SecretsFile {
	public int $profile_reads     = 0;
	public int $maintenance_reads = 0;
	/** @param array<string, ProfileWithUnexpectedSecret> $profiles */
	public function __construct( private array $profiles ) {
		parent::__construct( '/unused/ran-booster-troubleshooting-secrets.php', array() );
	}

	public function verify_and_secure(): bool {
		++$this->maintenance_reads;
		throw new \RuntimeException( 'Standing facts must not authenticate or secure storage.' );
	}

	public function recovery_credentials_fit_at( string $path ): bool {
		unset( $path );
		++$this->maintenance_reads;
		throw new \RuntimeException( 'Standing facts must not inspect recovery storage.' );
	}

	public function credential_profiles( ProviderCode|string $provider ): array {
		++$this->profile_reads;
		$provider_code = $provider instanceof ProviderCode ? $provider->value : $provider;

		return isset( $this->profiles[ $provider_code ] )
			? array( $this->profiles[ $provider_code ]['id'] => $this->profiles[ $provider_code ] )
			: array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
class TroubleshootingProviderFixture implements RepositoryProvider {
	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	public int $runs             = 0;
	public bool $reject_metadata = false;

	public function __construct( protected string $code, private Closure $diagnose ) {
	}

	public function get_metadata(): ProviderMetadata {
		if ( $this->reject_metadata ) {
			throw new \RuntimeException( 'Metadata callback must not run after registration.' );
		}
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

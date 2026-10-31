<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\TestCase;
use RAN\Activity\ActivityStore;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Tests\Activity\ActivityConnection;
use RAN\Troubleshooting\InstallationFacts;
use RAN\Troubleshooting\SupportReport;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdateNativeTarget;
use RAN\WordPress\CoreSelfUpdatePolicy;

require_once __DIR__ . '/SupportReportWordPressFunctions.php';
require_once __DIR__ . '/SupportReportProvider.php';
require_once __DIR__ . '/DiagnosticRunMemory.php';
require_once __DIR__ . '/DiagnosticRunFixture.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityConnection.php';
require_once dirname( __DIR__ ) . '/Activity/ActivityWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';

final class SupportReportTest extends TestCase {
	public function test_core_projection_preserves_recorded_operator_history_and_rejects_untrusted_values(): void {
		$method = new \ReflectionMethod( SupportReport::class, 'core_update_lines' );
		$core   = array(
			'reason'                => 'preference_disabled',
			'last_check'            => 1700000000,
			'next_check'            => 1700001000,
			'last_successful_check' => 1699999000,
			'last_check_outcome'    => 'failed',
			'last_check_code'       => 'check_failed',
			'check_cooldown_until'  => 1700000300,
		);
		$lines  = $method->invoke( null, $core );
		self::assertIsArray( $lines );
		self::assertContains( 'Recorded operator results and cooldown are separate from target last_check and next_check; no new check was performed.', $lines );
		self::assertContains( 'reason: preference_disabled', $lines );
		self::assertContains( 'last_successful_check: ' . gmdate( 'c', 1699999000 ), $lines );
		self::assertContains( 'last_check_outcome: failed', $lines );
		self::assertContains( 'last_check_code: check_failed', $lines );
		self::assertContains( 'check_cooldown_until: ' . gmdate( 'c', 1700000300 ), $lines );
		self::assertContains( 'next_check: ' . gmdate( 'c', 1700001000 ), $lines );
		foreach ( array( 'success', 'skipped', 'unknown', 'rejected' ) as $outcome ) {
			$core['last_check_outcome'] = $outcome;
			$lines                      = $method->invoke( null, $core );
			self::assertIsArray( $lines );
			self::assertContains( 'last_check_outcome: ' . $outcome, $lines );
		}
		$core['last_check_outcome']    = 'private_outcome';
		$core['last_check_code']       = '/private/path';
		$core['last_successful_check'] = 0;
		$core['check_cooldown_until']  = 253402300800;
		$lines                         = $method->invoke( null, $core );
		self::assertIsArray( $lines );
		foreach ( array( 'last_check_outcome: Unknown', 'last_check_code: Unknown', 'last_successful_check: Unknown', 'check_cooldown_until: Unknown' ) as $line ) {
			self::assertContains( $line, $lines );
		}
		foreach ( array(
			array(),
			array(
				'last_successful_check' => null,
				'check_cooldown_until'  => null,
				'last_check_outcome'    => null,
				'last_check_code'       => null,
			),
			array(
				'last_successful_check' => '1700000000',
				'check_cooldown_until'  => '1700000300',
				'last_check_outcome'    => null,
				'last_check_code'       => 'private_token_canary',
			),
		) as $input ) {
			$lines = $method->invoke( null, $input );
			self::assertIsArray( $lines );
			foreach ( array( 'last_check_outcome: Unknown', 'last_check_code: Unknown', 'last_successful_check: Unknown', 'check_cooldown_until: Unknown' ) as $line ) {
				self::assertContains( $line, $lines );
			}
		}
	}

	public function test_real_generation_redacts_all_sections_and_uses_consistent_placeholders(): void {
		$this->environment();
		$provider             = new SupportReportProvider();
		$registry             = new ProviderRegistry( array( $provider ) );
		$provider->registered = true;
		$memory               = new DiagnosticRunMemory();
		$runs                 = new DiagnosticRunFixture( $memory );
		self::assertTrue( $runs->save( 'gh', $this->diagnostic_run() ) );
		$connection                              = new ActivityConnection();
		$connection->read_results                = array(
			array(
				(object) array(
					'id'               => '17',
					'correlation'      => str_repeat( 'a', 32 ),
					'started_at'       => '2026-10-31 00:01:00',
					'completed_at'     => '2026-10-31 00:01:10',
					'provider'         => 'privateprovider',
					'subject_identity' => 'private-package-canary',
					'subject_type'     => 'plugin',
					'operation'        => 'diagnostics.run',
					'status'           => 'failed',
					'reason'           => 'diagnostics.partial',
					'message'          => 'private-prose-canary',
					'actor'            => 'private-actor-canary',
				),
			),
		);
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$plugins                                 = new class() extends PluginRepository {
			public function support_snapshot( int $limit = 100 ): array {
				return array(
					'available' => true,
					'truncated' => false,
					'records'   => array_slice(
						array(
							array(
								'package'           => 'private-package-canary',
								'repository'        => 'private-owner/private-repository-canary',
								'branch'            => 'private-ref-canary',
								'provider'          => 'privateprovider',
								'deployment_policy' => 'automatic',
								'source'            => 'release_asset',
							),
						),
						0,
						$limit
					),
				);
			}
		};
		$report                                  = new SupportReport( new InstallationFacts( $registry ), $runs, new ActivityStore( $connection ), $plugins, $this->empty_themes(), clock: static fn(): float => 1100.0 );
		$captured                                = $report->generate(
			array(
				'state'  => 'configured',
				'source' => 'constant',
				'path'   => '/private/path-canary',
				'token'  => 'private-token-canary',
			)
		);
		$text                                    = $captured['text'];
		self::assertSame( 'ran-booster-support-19700101-001820.txt', $captured['filename'] );
		foreach ( array( 'Environment', 'Installation', 'Storage', 'Managed packages', 'Diagnostics', 'Activity', 'Logging' ) as $section ) {
			self::assertStringContainsString( $section . ': ', $text );
		}
		self::assertStringContainsString( 'policy=automatic', $text );
		self::assertStringContainsString( 'source=release_asset', $text );
		self::assertStringContainsString( 'Scope: one saved credential selected; identity withheld; one repository selected; identity withheld', $text );
		self::assertStringContainsString( 'Partial: provider_unavailable', $text );
		self::assertStringContainsString( 'gh.repository.denied', $text );
		self::assertStringContainsString( 'diagnostics.partial', $text );
		self::assertSame( 2, substr_count( $text, 'Package 1' ) );
		self::assertSame( 4, substr_count( $text, 'Provider 1' ) );
		self::assertStringContainsString( 'Included count: plugin; Provider 1; policy=automatic; rows=1', $text );
		self::assertStringNotContainsString( 'private', strtolower( $text ) );
		self::assertStringNotContainsString( 'Private Component Canary', $text );
		self::assertSame( array(), $connection->inserted );
		self::assertSame( 1, $memory->writes );
		self::assertLessThanOrEqual( SupportReport::MAX_BYTES, strlen( $text ) );
	}

	public function test_frozen_capture_survives_diagnostic_expiry_and_inputs_are_not_refreshed(): void {
		$this->environment();
		$memory = new DiagnosticRunMemory();
		$runs   = new DiagnosticRunFixture( $memory );
		self::assertTrue( $runs->save( 'gh', $this->diagnostic_run() ) );
		$report     = new SupportReport( new InstallationFacts( new ProviderRegistry() ), $runs, clock: static fn(): float => 1100.0 );
		$captured   = $report->generate( array() );
		$before     = $captured['text'];
		$runs->time = 4600.0;
		self::assertSame( array(), $runs->available()['runs'] );
		self::assertSame( $before, $captured['text'] );
		self::assertStringContainsString( 'gh.repository.denied', $captured['text'] );
		self::assertStringContainsString( 'Diagnostics [Not run]', $report->generate( array() )['text'] );
		self::assertSame( 1, $memory->writes );
	}

	public function test_collector_failures_are_local_and_exception_details_never_enter_report(): void {
		$this->environment();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fail a local fact read with a private exception canary.
		$GLOBALS['wpdb']                               = new class() {
			public function db_version(): never {
				throw new \RuntimeException( '/private/database-canary; Authorization: private-token-canary' );
			}
		};
		$GLOBALS['ran_booster_support_header_failure'] = true;
		$memory                                        = new DiagnosticRunMemory();
		$memory->broken                                = true;
		$report                                        = new SupportReport( new InstallationFacts( new ProviderRegistry() ), new DiagnosticRunFixture( $memory ) );
		$text = $report->generate( array() )['text'];
		self::assertStringContainsString( 'Environment [Included]', $text );
		self::assertStringContainsString( 'Database: Unknown', $text );
		self::assertStringContainsString( 'Installation [Unavailable]', $text );
		self::assertStringContainsString( 'Storage [Included]', $text );
		self::assertStringContainsString( 'Diagnostics [Unavailable]', $text );
		self::assertStringContainsString( 'Logging [Unavailable]', $text );
		self::assertStringNotContainsString( 'canary', $text );
		self::assertSame( 0, $memory->writes );
		unset( $GLOBALS['ran_booster_support_header_failure'] );
	}

	public function test_budget_is_checked_between_collectors_without_claiming_preemption(): void {
		$this->environment();
		$time = 1000.0;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The bounded read advances a logical clock without sleeping.
		$GLOBALS['wpdb']                             = new class( $time ) {
			public function __construct( public float &$time ) {}
			public function db_version(): string {
				$this->time += 10.0;
				return '8.4.6';
			}
		};
		$GLOBALS['ran_booster_support_header_reads'] = array();
		$text                                        = ( new SupportReport( new InstallationFacts( new ProviderRegistry() ), clock: static fn(): float => $GLOBALS['wpdb']->time ) )->generate( array() )['text'];
		self::assertStringContainsString( 'Environment [Included]', $text );
		foreach ( array( 'Installation', 'Storage', 'Managed packages', 'Diagnostics', 'Activity', 'Logging' ) as $section ) {
			self::assertStringContainsString( $section . ' [Not included]', $text );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_support_header_reads'] );
		self::assertStringContainsString( 'Capture ended UTC: 1970-01-01T00:16:50+00:00', $text );
	}

	public function test_diagnostic_export_does_not_trust_display_prose_or_known_prefixes(): void {
		$rows = array();
		foreach ( array( 'local.runtime.ready', 'gh.repository.denied', 'gh.repository.denied.private-canary', 'privateprovider.private-canary', "gh.repository.denied\0private-canary", "gh.repository.denied\xff" ) as $code ) {
			$rows[] = array(
				'status'      => 'fail',
				'code'        => $code,
				'message'     => 'private message canary',
				'remediation' => '/private/repair-canary',
			);
		}
		$safe = SupportReport::safe_diagnostic_results( $rows );
		self::assertSame( 'local.runtime.ready', $safe[0]['code'] );
		self::assertSame( 'gh.repository.denied', $safe[1]['code'] );
		foreach ( array_slice( $safe, 2 ) as $row ) {
			self::assertSame( 'provider.check', $row['code'] );
		}
		self::assertStringNotContainsString( 'canary', implode( ' ', array_merge( ...array_map( 'array_values', $safe ) ) ) );
		self::assertCount( 8, SupportReport::safe_diagnostic_results( array_fill( 0, 100, $rows[0] ) ) );
	}

	public function test_safe_log_templates_omit_raw_text_and_unreviewed_context(): void {
		$line = '[ran-booster] attempt finished {"attempt_id":17,"correlation_id":"' . str_repeat( 'a', 32 ) . '","outcome_code":"archive_download_failed","package_slug":"private-package-canary","resolved_by":"private-actor-canary","exception_class":"private-exception-canary","resolved_ref":"private-ref-canary"}';
		$safe = SupportReport::safe_log( $line );
		self::assertIsString( $safe );
		self::assertStringContainsString( 'attempt finished; attempt=17; correlation=' . str_repeat( 'a', 32 ), $safe );
		self::assertStringNotContainsString( 'canary', $safe );
		foreach ( array( '[ran-booster] private-prose-canary', '[ran-booster] attempt finished suffix-canary', '[ran-booster] attempt finished {"attempt_id":"private-canary"', "[ran-booster] attempt finished\xff", '[ran-booster] attempt finished {"nested":{"a":{"b":{"c":1}}}}', str_repeat( 'x', 4097 ) ) as $unrecognized ) {
			self::assertNull( SupportReport::safe_log( $unrecognized ) );
		}
	}

	public function test_report_distinguishes_attention_review_and_missing_selected_provider(): void {
		$this->environment();
		$connection                              = new ActivityConnection();
		$connection->read_results                = array(
			array(
				(object) array(
					'id'     => '1',
					'status' => 'incomplete',
					'stage'  => 'deployment.needs_attention',
				),
				(object) array(
					'id'     => '2',
					'status' => 'incomplete',
					'stage'  => 'deployment.operator_reviewed',
				),
				(object) array(
					'id'     => '3',
					'status' => 'incomplete',
					'stage'  => 'private-stage-canary',
				),
			),
		);
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$facts                                   = new InstallationFacts(
			new ProviderRegistry(),
			github_observation: static fn(): array => array(
				'state'                  => 'missing',
				'admitted'               => false,
				'selected'               => 'external',
				'version'                => '2.3.4',
				'provider_api'           => 15,
				'compatibility_revision' => 'private-revision-canary',
				'identity'               => null,
			)
		);
		$text                                    = ( new SupportReport( $facts, activity: new ActivityStore( $connection ) ) )->generate( array() )['text'];
		self::assertStringContainsString( 'GitHub selection at request bootstrap: missing', $text );
		self::assertStringContainsString( 'GitHub admitted=No; selected=external; version=2.3.4; provider API=15', $text );
		self::assertStringContainsString( 'incomplete; stage=deployment.needs_attention', $text );
		self::assertStringContainsString( 'incomplete; stage=deployment.operator_reviewed', $text );
		self::assertStringContainsString( 'incomplete; stage=Unknown', $text );
		self::assertStringNotContainsString( 'canary', $text );
	}

	public function test_real_capture_reports_safe_entries_omissions_expiry_and_never_starts_itself(): void {
		$this->environment();
		$directory = sys_get_temp_dir() . '/ran-support-report-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated native capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$now     = 1000;
		$capture = new TemporaryDebugCapture(
			$directory . '/credentials.php',
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$report  = new SupportReport( new InstallationFacts( new ProviderRegistry() ), capture: $capture, clock: static fn(): float => 1100.0 );
		try {
			self::assertStringContainsString( 'Logging [Not captured]', $report->generate( array() )['text'] );
			self::assertFileDoesNotExist( $directory . '/ran-booster-debug.php' );
			self::assertFileDoesNotExist( $directory . '/ran-booster-debug.php.lock' );
			$capture->start();
			self::assertStringContainsString( 'Captured entries observed=0; omitted by sharing policy=0', $report->generate( array() )['text'] );
			self::assertTrue( $capture->append( '[ran-booster] attempt finished {"attempt_id":17,"package_slug":"private-package-canary"}' ) );
			self::assertTrue( $capture->append( '[ran-booster] private raw message canary' ) );
			$captured = $report->generate( array() );
			self::assertStringContainsString( 'Logging [Truncated]', $captured['text'] );
			self::assertStringContainsString( 'Captured entries observed=2; omitted by sharing policy=1', $captured['text'] );
			self::assertStringContainsString( 'attempt finished; attempt=17', $captured['text'] );
			self::assertStringNotContainsString( 'canary', $captured['text'] );
			$now = 1000 + 3600 + 86400;
			self::assertStringContainsString( 'Logging [Not captured]', $report->generate( array() )['text'] );
			self::assertFileDoesNotExist( $directory . '/ran-booster-debug.php' );
			self::assertStringContainsString( 'attempt finished; attempt=17', $captured['text'] );
		} finally {
			foreach ( array( $directory . '/ran-booster-debug.php', $directory . '/ran-booster-debug.php.lock' ) as $path ) {
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only exact isolated capture fixture files.
					unlink( $path );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated capture fixture directory.
			rmdir( $directory );
		}
	}

	public function test_package_detail_limit_is_shared_and_reports_omitted_type(): void {
		$this->environment();
		$plugins = new class() extends PluginRepository {
			public function support_snapshot( int $limit = 100 ): array {
				return array(
					'available' => true,
					'truncated' => true,
					'records'   => array_fill(
						0,
						$limit,
						array(
							'package'           => 'package',
							'repository'        => 'owner/repository',
							'branch'            => 'main',
							'provider'          => 'gh',
							'deployment_policy' => 'disabled',
							'source'            => 'branch',
						)
					),
				);
			}
		};
		$themes  = new class() extends ThemeRepository {
			public ?int $observed_limit = null;
			public function support_snapshot( int $limit = 100 ): array {
				$this->observed_limit = $limit;
				return array(
					'available' => true,
					'truncated' => true,
					'records'   => array(),
				);
			}
		};
		$text    = ( new SupportReport( new InstallationFacts( new ProviderRegistry() ), plugins: $plugins, themes: $themes ) )->generate( array() )['text'];
		self::assertSame( 0, $themes->observed_limit );
		self::assertStringContainsString( 'Managed packages [Truncated]', $text );
		self::assertStringContainsString( 'plugin included rows: 100', $text );
		self::assertStringContainsString( 'theme included rows: 0', $text );
		self::assertSame( 100, preg_match_all( '/^plugin; Package /m', $text ) );
		self::assertStringContainsString( 'Included count: plugin; GitHub; policy=disabled; rows=100', $text );
		self::assertLessThanOrEqual( SupportReport::MAX_BYTES, strlen( $text ) );
	}

	public function test_component_coverage_reports_omissions_without_exporting_addon_identities(): void {
		$this->environment();
		$addons = new AdminAddOnRegistry();
		for ( $index = 0; $index < 35; ++$index ) {
			$addons->register(
				new AdminAddOnTab(
					'addon' . $index,
					'addon' . $index,
					'Private Addon ' . $index,
					static function (): never {
						throw new \RuntimeException( 'A report may not render add-ons.' );
					}
				)
			);
		}
		$text = ( new SupportReport( new InstallationFacts( new ProviderRegistry(), add_ons: $addons ) ) )->generate( array() )['text'];
		self::assertStringContainsString( 'Installation [Truncated]', $text );
		self::assertStringContainsString( 'Provider and add-on declarations beyond the first 32 are not included.', $text );
		self::assertStringContainsString( 'Component 32; Active=Yes; Admission=Admitted; Version=Unknown', $text );
		self::assertStringNotContainsString( 'Private Addon', $text );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_storage_source_comes_from_exact_configuration_presence_without_exporting_value(): void {
		$this->environment();
		$report = new SupportReport( new InstallationFacts( new ProviderRegistry() ) );
		self::assertStringContainsString( 'Configuration source: none', $report->generate( array( 'source' => 'constant' ) )['text'] );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/private/configured-path-canary' );
		$text = $report->generate( array( 'source' => '/private/incorrect-source-canary' ) )['text'];
		self::assertStringContainsString( 'Configuration source: constant', $text );
		self::assertStringNotContainsString( 'canary', $text );
	}

	public function test_activity_subjects_and_real_deployment_operations_keep_their_meaning(): void {
		$this->environment();
		$connection                              = new ActivityConnection();
		$connection->read_results                = array(
			array_map(
				static fn( string $type ): object => (object) array(
					'id'               => '2',
					'subject_type'     => $type,
					'subject_identity' => 'private-subject-canary',
					'provider'         => 'gh',
					'operation'        => 'update',
				),
				array( 'plugin', 'theme', 'repository', 'provider', 'unrecognized' )
			),
		);
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$text                                    = ( new SupportReport( new InstallationFacts( new ProviderRegistry() ), activity: new ActivityStore( $connection ) ) )->generate( array() )['text'];
		self::assertSame( 2, substr_count( $text, 'Package 1' ) );
		self::assertStringContainsString( 'Repository 1', $text );
		self::assertStringContainsString( 'Provider 1', $text );
		self::assertStringContainsString( 'Subject 1', $text );
		self::assertSame( 5, substr_count( $text, '; update;' ) );
		self::assertStringNotContainsString( 'canary', $text );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_recorded_core_provenance_and_known_updater_code_are_exported_without_discovery(): void {
		$this->environment();
		$directory = sys_get_temp_dir() . '/ran-report-provenance-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated release-provenance fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$marker = $directory . '/ran-booster-release.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the isolated reviewed provenance marker fixture.
		file_put_contents(
			$marker,
			wp_json_encode(
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '1.0.0-beta.41',
					'commit'         => str_repeat( 'a', 40 ),
				)
			)
		);
		$updater = new class() {
			public int $status_reads = 0;
			/** @return array{state:string,declaration_accepted:bool,hooks_registered:bool,code:string,native:array<never,never>} */
			public function status(): array {
				++$this->status_reads;
				return array(
					'state'                => 'inactive',
					'declaration_accepted' => false,
					'hooks_registered'     => false,
					'code'                 => 'runtime_environment_invalid',
					'native'               => array(),
				);
			}
		};
		try {
			$facts = new InstallationFacts( new ProviderRegistry(), new CoreSelfUpdateStatus( CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.0.0-beta.41' ), new CoreSelfUpdateNativeTarget( $updater ) ) );
			$text  = ( new SupportReport( $facts ) )->generate( array() )['text'];
			self::assertStringContainsString( 'reason: verified_release', $text );
			self::assertStringContainsString( 'marker_commit: ' . str_repeat( 'a', 40 ), $text );
			self::assertStringContainsString( 'updater_code: github_updater_runtime_environment_invalid', $text );
			self::assertSame( 1, $updater->status_reads );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the exact isolated marker fixture.
			unlink( $marker );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated provenance fixture directory.
			rmdir( $directory );
		}
	}

	private function environment(): void {
		unset( $GLOBALS['ran_booster_support_header_failure'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Selected runtime version fixture for the environment boundary.
		$GLOBALS['wp_version']                  = '7.0.1';
		$GLOBALS['ran_booster_support_version'] = '1.0.0-beta.41';
		$_SERVER['SERVER_SOFTWARE']             = 'Apache/2.4.66 private-host-canary';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Runtime-fact boundary fixture, never an actual WordPress connection.
		$GLOBALS['wpdb'] = new class() {
			public function db_version(): string {
				return '8.4.6'; }
		};
	}

	/** @return array{completed_at:float,implementation:string,credential_selected:bool,repository_selected:bool,partial:bool,partial_reason:?string,results:list<array{status:string,code:string,message:string,remediation:string}>} */
	private function diagnostic_run(): array {
		return array(
			'completed_at'        => 1000.0,
			'implementation'      => str_repeat( 'a', 64 ),
			'credential_selected' => true,
			'repository_selected' => true,
			'partial'             => true,
			'partial_reason'      => 'provider_unavailable',
			'results'             => array(
				array(
					'status'      => 'fail',
					'code'        => 'gh.repository.denied',
					'message'     => 'private-diagnostic-prose-canary',
					'remediation' => 'private-remediation-canary',
				),
			),
		);
	}

	private function empty_themes(): ThemeRepository {
		return new class() extends ThemeRepository {
			/** @phpstan-pure */
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- This empty source has no records regardless of the caller's remaining bound.
			public function support_snapshot( int $limit = 100 ): array {
				return array(
					'available' => true,
					'truncated' => false,
					'records'   => array(),
				);
			}
		};
	}
}

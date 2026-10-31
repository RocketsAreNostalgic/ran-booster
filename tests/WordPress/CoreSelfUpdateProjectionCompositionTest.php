<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\Troubleshooting\InstallationFacts;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RAN\Troubleshooting\SupportReport;
use RAN\Troubleshooting\TroubleshootingService;
use RAN\WordPress\CoreSelfUpdateCheck;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CoreSelfUpdateProjectionCompositionTest extends TestCase {

	private mixed $previous_database;

	#[BeforeClass]
	public static function load_shims(): void {
		require_once __DIR__ . '/CoreSelfUpdateCheckWordPressFunctions.php';
		require_once __DIR__ . '/CoreSelfUpdateProjectionCompositionWordPressFunctions.php';
	}

	#[Before]
	protected function prepare(): void {
		$GLOBALS['ran_booster_core_check_options'] = array();
		$GLOBALS['ran_booster_core_check_actions'] = array();
		$this->previous_database                   = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This isolated composition fixture supplies the Core-check CAS database boundary.
		$GLOBALS['wpdb'] = new CoreSelfUpdateProjectionDatabase();
	}

	#[After]
	protected function restore(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the inherited test-process database binding.
		$GLOBALS['wpdb'] = $this->previous_database;
	}

	public function test_observed_success_then_missing_observation_preserves_success_through_public_projections(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The exact Core configuration constant is required to exercise its enabled policy projection.
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'enabled' );
		$target  = $this->target( 1700000000, 1700000900 );
		$success = new CoreSelfUpdateCheck(
			$target,
			static fn (): bool => true,
			'ran-booster/ran-booster.php',
			'stable',
			static function (): void {
				self::fire_observation( 'succeeded', 'no_candidates' );
			},
			static fn (): int => 1000
		);
		self::assertSame(
			array(
				'outcome' => 'success',
				'code'    => 'no_candidates',
			),
			$success->check()
		);
		[$success_payload, $success_report] = $this->projections( new CoreSelfUpdateStatus( $this->enabled_policy(), $target, $success ) );
		self::assertSame( 1000, $success_payload['core_self_update']['last_successful_check'] );
		self::assertSame( 'success', $success_payload['core_self_update']['last_check_outcome'] );
		self::assertStringContainsString( 'last_successful_check: ' . gmdate( 'c', 1000 ), $success_report );
		self::assertStringContainsString( 'last_check_outcome: success', $success_report );

		$failure = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1400 );
		self::assertSame(
			array(
				'outcome' => 'unknown',
				'code'    => 'check_not_observed',
			),
			$failure->check()
		);

		$status  = new CoreSelfUpdateStatus( $this->enabled_policy(), $target, $failure );
		$details = $status->diagnostics();
		self::assertSame( 1000, $details['last_successful_check'] );
		self::assertSame( 'unknown', $details['last_check_outcome'] );
		self::assertSame( 'check_not_observed', $details['last_check_code'] );
		self::assertSame( 1700000000, $details['last_check'] );
		self::assertSame( 1700000900, $details['next_check'] );

		[$payload, $report] = $this->projections( $status );
		self::assertSame( '1.0.0-beta.41', $payload['core_self_update']['installed_version'] );
		foreach ( $details as $key => $value ) {
			self::assertSame( $value, $payload['core_self_update'][ $key ] );
		}
		self::assertSame( gmdate( 'c', 1000 ), $payload['installation_details']['Booster Core updates']['Last successful operator check (UTC)'] );
		self::assertSame( 'unknown', $payload['installation_details']['Booster Core updates']['Last operator check outcome'] );
		self::assertStringContainsString( 'last_check: ' . gmdate( 'c', 1700000000 ), $report );
		self::assertStringContainsString( 'next_check: ' . gmdate( 'c', 1700000900 ), $report );
		self::assertStringContainsString( 'last_successful_check: ' . gmdate( 'c', 1000 ), $report );
		self::assertStringContainsString( 'last_check_code: check_not_observed', $report );
	}

	public function test_skipped_active_claim_and_past_cooldown_keep_operator_and_target_state_distinct(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The exact Core configuration constant is required to exercise its enabled policy projection.
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'enabled' );
		$target  = $this->target( 1700000100, 1700001900 );
		$skipped = new CoreSelfUpdateCheck( $target, static fn (): bool => false, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1000 );
		self::assertSame(
			array(
				'outcome' => 'skipped',
				'code'    => 'native_discovery_disabled',
			),
			$skipped->check()
		);
		[$disabled_payload, $disabled_report] = $this->projections( new CoreSelfUpdateStatus( $this->enabled_policy(), $target, $skipped ) );
		$this->assert_missing_operator_observation( $disabled_payload, $disabled_report, 1700001900 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Seed the Core-check shim with its exact serialized option representation.
		$GLOBALS['ran_booster_core_check_options'][ CoreSelfUpdateCheck::OPTION_NAME ] = serialize(
			array(
				'claim'      => str_repeat( 'a', 32 ),
				'claimed_at' => 1100,
			)
		);
		$active = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1150 );
		self::assertSame(
			array(
				'outcome' => 'skipped',
				'code'    => 'cooldown_active',
			),
			$active->check()
		);
		[$active_payload, $active_report] = $this->projections( new CoreSelfUpdateStatus( $this->enabled_policy(), $target, $active ) );
		$this->assert_missing_operator_observation( $active_payload, $active_report, 1700001900 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Seed the expired cooldown through the Core-check shim's persisted option representation.
		$GLOBALS['ran_booster_core_check_options'][ CoreSelfUpdateCheck::OPTION_NAME ] = serialize(
			array( 'cooldown_until' => 1000 )
		);
		$past = new CoreSelfUpdateCheck(
			$target,
			static fn (): bool => true,
			'ran-booster/ran-booster.php',
			'stable',
			static function (): void {
				self::fire_observation( 'failed', 'bounded_unreviewed_code' );
			},
			static fn (): int => 1001
		);
		self::assertSame(
			array(
				'outcome' => 'failed',
				'code'    => 'bounded_unreviewed_code',
			),
			$past->check()
		);

		$status             = new CoreSelfUpdateStatus( $this->enabled_policy(), $target, $past );
		[$payload, $report] = $this->projections( $status );
		self::assertSame( 'failed', $payload['core_self_update']['last_check_outcome'] );
		self::assertSame( 'bounded_unreviewed_code', $payload['core_self_update']['last_check_code'] );
		self::assertSame( gmdate( 'c', 1700001900 ), $payload['installation_details']['Booster Core updates']['Next check (UTC)'] );
		self::assertSame( 'bounded_unreviewed_code', $payload['installation_details']['Booster Core updates']['Last operator check code'] );
		self::assertStringContainsString( 'next_check: ' . gmdate( 'c', 1700001900 ), $report );
		self::assertStringContainsString( 'last_check_code: Unknown', $report );
	}

	/** @return array{0:array<string,mixed>,1:string} */
	private function projections( CoreSelfUpdateStatus $status ): array {
		$facts   = new InstallationFacts( new ProviderRegistry(), $status );
		$report  = new SupportReport( $facts );
		$service = new TroubleshootingService(
			new LocalTroubleshootingService( new SecretsFile( '/unused/core-self-update-projection.json' ) ),
			new ProviderRegistry(),
			core_self_update: $status,
			support_report: $report,
			installation_facts: $facts
		);

		return array(
			$service->installation_payload( null, array( 'state' => 'unknown' ) ),
			$service->generate_support_report( array( 'state' => 'unknown' ) )['text'],
		);
	}

	/** @return RepositoryReleaseNativeTarget&object{refreshes:int} */
	private function target( int $last_check, int $next_check ): RepositoryReleaseNativeTarget {
		return new class( $last_check, $next_check ) implements RepositoryReleaseNativeTarget {

			public int $refreshes = 0;

			public function __construct( private readonly int $last_check, private readonly int $next_check ) {
			}

			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				unset( $runtime_provider, $repository_identity, $channel );

				return false;
			}

			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true, '1.0.0-beta.42', 'newer', $this->last_check, $this->next_check );
			}

			public function refresh(): bool {
				++$this->refreshes;

				return true;
			}
		};
	}

	private function enabled_policy(): CoreSelfUpdatePolicy {
		return CoreSelfUpdatePolicy::detect( '/unused/ran-booster.php', '1.0.0-beta.41' );
	}

	/** @param array<string,mixed> $payload */
	private function assert_missing_operator_observation( array $payload, string $report, int $target_next_check ): void {
		foreach ( array( 'last_successful_check', 'last_check_outcome', 'last_check_code', 'check_cooldown_until' ) as $key ) {
			self::assertNull( $payload['core_self_update'][ $key ] );
		}
		self::assertSame( gmdate( 'c', $target_next_check ), $payload['installation_details']['Booster Core updates']['Next check (UTC)'] );
		foreach ( array( 'last_successful_check', 'last_check_outcome', 'last_check_code', 'check_cooldown_until' ) as $key ) {
			self::assertStringContainsString( $key . ': Unknown', $report );
		}
	}

	private static function fire_observation( string $status, string $reason ): void {
		foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_started_v1'] ?? array() as $callback ) {
			$callback(
				array(
					'check_id'            => str_repeat( 'a', 32 ),
					'target_type'         => 'plugin',
					'provider_code'       => 'github',
					'repository_identity' => '1319710173',
					'installed_identity'  => 'ran-booster/ran-booster.php',
					'channel'             => 'stable',
				)
			);
		}
		foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_completed_v1'] ?? array() as $callback ) {
			$callback(
				array(
					'check_id' => str_repeat( 'a', 32 ),
					'status'   => $status,
					'reason'   => $reason,
				)
			);
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The isolated Core-check CAS double is local to this composition test.
final class CoreSelfUpdateProjectionDatabase {

	public string $options    = 'wp_options';
	public string $last_error = '';

	public function prepare( string $query, mixed ...$arguments ): string {
		return serialize( array( $query, $arguments ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserve the exact prepared CAS tuple in the test-only database double.
	}

	public function query( string $prepared ): int {
		[ $query, $arguments ] = unserialize( $prepared ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Decode only the database double's own prepared tuple.
		if ( str_starts_with( $query, 'INSERT' ) ) {
			[ $name, $new ] = $arguments;
			if ( isset( $GLOBALS['ran_booster_core_check_options'][ $name ] ) ) {
				return 0;
			}
			$GLOBALS['ran_booster_core_check_options'][ $name ] = $new;

			return 1;
		}
		[ $new, $name, $old ] = $arguments;
		if ( ( $GLOBALS['ran_booster_core_check_options'][ $name ] ?? null ) !== $old ) {
			return 0;
		}
		$GLOBALS['ran_booster_core_check_options'][ $name ] = $new;

		return 1;
	}
}

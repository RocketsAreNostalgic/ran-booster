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
use RAN\WordPress\CoreSelfUpdateCheck;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CoreSelfUpdateCheckTest extends TestCase {
	private mixed $previous_database;

	#[BeforeClass]
	public static function load_shims(): void {
		require_once __DIR__ . '/CoreSelfUpdateCheckWordPressFunctions.php';
	}

	#[Before]
	protected function prepare(): void {
		$GLOBALS['ran_booster_core_check_options']     = array();
		$GLOBALS['ran_booster_core_check_actions']     = array();
		$GLOBALS['ran_booster_core_check_write_fails'] = false;
		$this->previous_database                       = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The isolated process supplies the database double required by the production CAS boundary.
		$GLOBALS['wpdb'] = new class() {
			public string $options    = 'wp_options';
			public string $last_error = '';
			public function prepare( string $query, mixed ...$arguments ): string {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Store the isolated fake's exact prepared tuple.
				return serialize( array( $query, $arguments ) ); }
			public function query( string $prepared ): int {
				[ $query, $arguments ] = unserialize( $prepared ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Read the isolated fake's exact prepared tuple.
				if ( $GLOBALS['ran_booster_core_check_write_fails'] ) {
					return 0; }
				if ( str_starts_with( $query, 'INSERT' ) ) {
					[ $name, $new ] = $arguments;
					if ( isset( $GLOBALS['ran_booster_core_check_options'][ $name ] ) ) {
						return 0; }
					$GLOBALS['ran_booster_core_check_options'][ $name ] = $new;
					return 1;
				}
				[ $new, $name, $old ] = $arguments;
				if ( ( $GLOBALS['ran_booster_core_check_options'][ $name ] ?? null ) !== $old ) {
					return 0; }
				$GLOBALS['ran_booster_core_check_options'][ $name ] = $new;
				return 1;
			}
		};
	}

	#[After]
	protected function restore(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the isolated process's inherited database binding.
		$GLOBALS['wpdb'] = $this->previous_database; }

	public function test_rejects_disabled_policy_before_refresh_or_orchestration(): void {
		$target = $this->target();
		$runs   = 0;
		$check  = new CoreSelfUpdateCheck(
			$target,
			static fn (): bool => false,
			'ran-booster/ran-booster.php',
			'stable',
			static function () use ( &$runs ): void {
				++$runs;
			},
			static fn (): int => 1000
		);
		self::assertSame(
			array(
				'outcome' => 'skipped',
				'code'    => 'native_discovery_disabled',
			),
			$check->check()
		);
		self::assertSame( 0, $target->refreshes );
		self::assertSame( 0, $runs );
	}

	public function test_persists_success_only_from_matching_completed_event_and_keeps_it_after_failure(): void {
		$target = $this->target();
		$run    = static function (): void {
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_started_v1'] as $callback ) {
				$callback( self::start( 'b', 'theme' ) );
				$callback( self::start() );
			}
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_completed_v1'] as $callback ) {
				$callback( self::completion() );
			}
		};
		$times  = array( 1000, 1025 );
		$clock  = static function () use ( &$times ): int {
			$time = array_shift( $times );
			return is_int( $time ) ? $time : 1025;
		};
		$check  = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', $run, $clock );
		self::assertSame(
			array(
				'outcome' => 'success',
				'code'    => 'no_candidates',
			),
			$check->check()
		);
		self::assertSame( 1025, $check->diagnostics()['last_successful_check'] );
		$second = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1400 );
		self::assertSame(
			array(
				'outcome' => 'unknown',
				'code'    => 'check_not_observed',
			),
			$second->check()
		);
		self::assertSame( 1025, $second->diagnostics()['last_successful_check'] );
	}

	public function test_current_claim_blocks_concurrent_check_and_expired_claim_can_be_recovered(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Seed exact option data in the isolated fake.
		$GLOBALS['ran_booster_core_check_options'][ CoreSelfUpdateCheck::OPTION_NAME ] = serialize(
			array(
				'claim'      => str_repeat( 'a', 32 ),
				'claimed_at' => 1000,
			)
		);
		$target  = $this->target();
		$blocked = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1100 );
		self::assertSame( 'cooldown_active', $blocked->check()['code'] );
		$recovered = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1121 );
		self::assertSame( 'check_not_observed', $recovered->check()['code'] );
	}

	public function test_unrelated_start_does_not_erase_the_core_observation_and_cooldown_blocks_a_second_request(): void {
		$target = $this->target();
		$run    = static function (): void {
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_started_v1'] as $callback ) {
				$callback( self::start() );
				$callback( self::start( 'b', 'plugin', 'other', 'other/other.php' ) );
			}
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_completed_v1'] as $callback ) {
				$callback( self::completion() );
			}
		};
		$check  = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', $run, static fn (): int => 1000 );
		self::assertSame( 'success', $check->check()['outcome'] );
		self::assertSame( 'cooldown_active', $check->check()['code'] );
	}

	public function test_reports_a_failed_claim_when_persistence_is_unavailable(): void {
		$GLOBALS['ran_booster_core_check_write_fails'] = true;
		$check = new CoreSelfUpdateCheck( $this->target(), static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', static function (): void {}, static fn (): int => 1000 );
		self::assertSame(
			array(
				'outcome' => 'failed',
				'code'    => 'claim_unavailable',
			),
			$check->check()
		);
	}

	public function test_policy_change_during_orchestration_does_not_persist_a_success(): void {
		$allowed = true;
		$target  = $this->target();
		$run     = static function () use ( &$allowed ): void {
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_started_v1'] as $callback ) {
				$callback( self::start() );
			}
			$allowed = false;
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_completed_v1'] as $callback ) {
				$callback( self::completion() );
			}
		};
		$check   = new CoreSelfUpdateCheck(
			$target,
			static function () use ( &$allowed ): bool {
				return $allowed;
			},
			'ran-booster/ran-booster.php',
			'stable',
			$run,
			static fn (): int => 1000
		);
		self::assertSame(
			array(
				'outcome' => 'skipped',
				'code'    => 'native_discovery_disabled',
			),
			$check->check()
		);
		self::assertNull( $check->diagnostics()['last_successful_check'] );
	}

	public function test_stale_claim_cannot_finalize_after_another_request_replaces_it(): void {
		$target = $this->target();
		$run    = static function (): void {
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_started_v1'] as $callback ) {
				$callback( self::start() );
			}
			foreach ( $GLOBALS['ran_booster_core_check_actions']['ran_wp_release_updater_check_completed_v1'] as $callback ) {
				$callback( self::completion() );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Replace the fake's exact claim between observation and finalization.
			$GLOBALS['ran_booster_core_check_options'][ CoreSelfUpdateCheck::OPTION_NAME ] = serialize(
				array(
					'claim'      => str_repeat( 'b', 32 ),
					'claimed_at' => 1000,
				)
			);
		};
		$check  = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable', $run, static fn (): int => 1000 );
		self::assertSame(
			array(
				'outcome' => 'unknown',
				'code'    => 'claim_lost',
			),
			$check->check()
		);
	}

	/** @return RepositoryReleaseNativeTarget&object{refreshes: int} */
	private function target(): RepositoryReleaseNativeTarget {
		return new class() implements RepositoryReleaseNativeTarget {
			public int $refreshes = 0;
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				unset( $runtime_provider, $repository_identity, $channel );
				return false; }
			public function register(): bool {
				return true; }
			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true ); }
			public function refresh(): bool {
				++$this->refreshes;
				return true; }
		};
	}

	/** @return array<string,string> */
	private static function start( string $check = 'a', string $type = 'plugin', string $repository = '1319710173', string $plugin = 'ran-booster/ran-booster.php' ): array {
		return array(
			'check_id'            => str_repeat( $check, 32 ),
			'target_type'         => $type,
			'provider_code'       => 'github',
			'repository_identity' => $repository,
			'installed_identity'  => $plugin,
			'channel'             => 'stable',
		);
	}

	/** @return array<string,string> */
	private static function completion( string $check = 'a' ): array {
		return array(
			'check_id' => str_repeat( $check, 32 ),
			'status'   => 'succeeded',
			'reason'   => 'no_candidates',
		);
	}
}

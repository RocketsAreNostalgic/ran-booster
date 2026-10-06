<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[CoversClass( CoreSelfUpdateStatus::class )]
final class CoreSelfUpdateStatusTest extends TestCase {

	public function test_returns_only_bounded_passive_target_state(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() implements RepositoryReleaseNativeTarget {
			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus(
					active: true,
					offered_version: '1.2.4',
					version_relationship: 'newer',
					last_check: 1_700_000_000,
					next_check: 1_700_000_900,
					failure_code: 'neutral_target_unavailable'
				);
			}

			public function refresh(): bool {
				return true;
			}
		};

		$status = ( new CoreSelfUpdateStatus( $policy, $target ) )->diagnostics();

		self::assertSame( 'disabled', $status['effective_mode'] );
		self::assertSame( 'active', $status['updater_state'] );
		self::assertSame( 'neutral_target_unavailable', $status['updater_code'] );
		self::assertNull( $status['selected_version'] );
		self::assertSame( '1.2.4', $status['offered_version'] );
		self::assertSame( 1_700_000_000, $status['last_check'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function test_updater_diagnostics_failure_is_contained(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() implements RepositoryReleaseNativeTarget {
			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				throw new \RuntimeException( 'sensitive failure' );
			}

			public function refresh(): bool {
				return false;
			}
		};

		$status = ( new CoreSelfUpdateStatus( $policy, $target ) )->diagnostics();

		self::assertSame( 'inactive', $status['updater_state'] );
		self::assertSame( 'diagnostics_unavailable', $status['updater_code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function test_disabled_policy_without_target_reports_native_discovery_disabled(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );

		$status = ( new CoreSelfUpdateStatus( $policy, null ) )->diagnostics();

		self::assertSame( 'disabled', $status['effective_mode'] );
		self::assertSame( 'inactive', $status['updater_state'] );
		self::assertSame( 'native_discovery_disabled', $status['updater_code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function test_normalizes_the_neutral_native_target_status(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() implements RepositoryReleaseNativeTarget {
			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true, '1.2.4', 'newer' );
			}

			public function refresh(): bool {
				return true;
			}
		};

		$status = ( new CoreSelfUpdateStatus( $policy, $target ) )->diagnostics();

		self::assertSame( 'active', $status['updater_state'] );
		self::assertNull( $status['updater_code'] );
		self::assertSame( '1.2.4', $status['offered_version'] );
		self::assertNull( $status['selected_version'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Troubleshooting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[CoversClass( CoreSelfUpdateStatus::class )]
final class CoreSelfUpdateStatusTest extends TestCase {

	public function testReturnsOnlyBoundedPassiveTargetState(): void {
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
					offeredVersion: '1.2.4',
					versionRelationship: 'newer',
					lastCheck: 1_700_000_000,
					nextCheck: 1_700_000_900,
					failureCode: 'neutral_target_unavailable'
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

	public function testUpdaterDiagnosticsFailureIsContained(): void {
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

	public function testDisabledPolicyWithoutTargetReportsNativeDiscoveryDisabled(): void {
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

	public function testNormalizesTheNeutralNativeTargetStatus(): void {
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

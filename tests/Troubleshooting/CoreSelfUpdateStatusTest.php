<?php

declare(strict_types=1);

namespace Tests\Troubleshooting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[CoversClass( CoreSelfUpdateStatus::class )]
final class CoreSelfUpdateStatusTest extends TestCase {

	public function testReturnsOnlyBoundedPassiveUpdaterState(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy  = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$updater = new class() {
			public function diagnostics(): array {
				return array(
					'state'            => 'unavailable',
					'code'             => 'github_updater_github_http_error',
					'selected_version' => '1.5.0-beta.9',
					'offered_version'  => '1.2.4',
					'last_check'       => 1_700_000_000,
					'next_check'       => 1_700_000_900,
					'repository'       => '<script>alert(1)</script>',
					'access_token'     => 'secret',
				);
			}
		};

		$status = ( new CoreSelfUpdateStatus( $policy, $updater ) )->diagnostics();

		self::assertSame( 'disabled', $status['effective_mode'] );
		self::assertSame( 'unavailable', $status['updater_state'] );
		self::assertSame( 'github_updater_github_http_error', $status['updater_code'] );
		self::assertSame( '1.5.0-beta.9', $status['selected_version'] );
		self::assertSame( '1.2.4', $status['offered_version'] );
		self::assertSame( 1_700_000_000, $status['last_check'] );
		self::assertArrayNotHasKey( 'repository', $status );
		self::assertArrayNotHasKey( 'access_token', $status );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function testUpdaterDiagnosticsFailureIsContained(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy  = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$updater = new class() {
			public function diagnostics(): array {
				throw new \RuntimeException( 'sensitive failure' );
			}
		};

		$status = ( new CoreSelfUpdateStatus( $policy, $updater ) )->diagnostics();

		self::assertSame( 'inactive', $status['updater_state'] );
		self::assertSame( 'diagnostics_unavailable', $status['updater_code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function testNormalizesTheNeutralNativeTargetStatus(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() {
			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true, '1.2.4', 'newer' );
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

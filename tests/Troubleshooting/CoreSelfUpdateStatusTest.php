<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\Troubleshooting\CoreSelfUpdateStatus;
use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\WordPress\CoreSelfUpdateCheck;

#[CoversClass( CoreSelfUpdateStatus::class )]
final class CoreSelfUpdateStatusTest extends TestCase {

	public function test_returns_only_bounded_passive_target_state(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() implements RepositoryReleaseNativeTarget {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The public native-target signature is required; this self-update double never matches managed history.
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				return false;
			}

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

		$status = ( new CoreSelfUpdateStatus( static fn (): CoreSelfUpdatePolicy => $policy, $target ) )->diagnostics();

		self::assertSame( 'disabled', $status['effective_mode'] );
		self::assertSame( 'active', $status['updater_state'] );
		self::assertSame( 'neutral_target_unavailable', $status['updater_code'] );
		self::assertSame( 1_700_000_900, $status['next_check'] );
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
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The public native-target signature is required; this self-update double never matches managed history.
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				return false;
			}

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

		$status = ( new CoreSelfUpdateStatus( static fn (): CoreSelfUpdatePolicy => $policy, $target ) )->diagnostics();

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

		$status = ( new CoreSelfUpdateStatus( static fn (): CoreSelfUpdatePolicy => $policy, null ) )->diagnostics();

		self::assertSame( 'disabled', $status['effective_mode'] );
		self::assertSame( 'inactive', $status['updater_state'] );
		self::assertSame( 'native_discovery_disabled', $status['updater_code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function test_policy_is_refreshed_per_operation_with_one_snapshot_per_diagnostics(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$disabled = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone test without WordPress runtime.
		$marker = json_encode(
			array(
				'schema'         => 'ran-booster-core-release',
				'schema_version' => 1,
				'version'        => '1.2.3',
				'commit'         => str_repeat( 'a', 40 ),
			),
			JSON_THROW_ON_ERROR
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable release provenance fixture.
		file_put_contents( $directory . '/ran-booster-release.json', $marker );
		$enabled = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$policy  = $disabled;
		$calls   = 0;
		$target  = new class() implements RepositoryReleaseNativeTarget {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- Required native-target signature.
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				return false;
			}
			public function register(): bool {
				return true;
			}
			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true );
			}
			public function refresh(): bool {
				return true;
			}
		};
		$check   = new CoreSelfUpdateCheck( $target, static fn (): bool => true, 'ran-booster/ran-booster.php', 'stable' );
		$status  = new CoreSelfUpdateStatus(
			static function () use ( &$policy, &$calls ): CoreSelfUpdatePolicy {
				++$calls;
				return $policy;
			},
			null,
			$check
		);
		self::assertFalse( $status->can_check() );
		self::assertSame( 'native_discovery_disabled', $status->diagnostics()['updater_code'] );
		self::assertSame( 2, $calls );
		$policy = $enabled;
		self::assertTrue( $status->can_check() );
		$diagnostics = $status->diagnostics();
		self::assertSame( 'enabled', $diagnostics['effective_mode'] );
		self::assertNull( $diagnostics['updater_code'] );
		self::assertSame( 4, $calls );
		$policy = $disabled;
		self::assertFalse( $status->can_check() );
		self::assertSame( 'disabled', $status->diagnostics()['effective_mode'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture cleanup.
		unlink( $directory . '/ran-booster-release.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}

	public function test_normalizes_the_neutral_native_target_status(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-self-update-status-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$policy = CoreSelfUpdatePolicy::detect( $directory . '/ran-booster.php', '1.2.3' );
		$target = new class() implements RepositoryReleaseNativeTarget {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The public native-target signature is required; this self-update double never matches managed history.
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				return false;
			}

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

		$status = ( new CoreSelfUpdateStatus( static fn (): CoreSelfUpdatePolicy => $policy, $target ) )->diagnostics();

		self::assertSame( 'active', $status['updater_state'] );
		self::assertNull( $status['updater_code'] );
		self::assertSame( '1.2.4', $status['offered_version'] );
		self::assertNull( $status['last_check'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $directory );
	}
}

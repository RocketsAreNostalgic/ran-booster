<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Deployment\PackageMutationGuard;
use RuntimeException;

final class PackageMutationGuardTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_package_mutation_guard_contexts'],
			$GLOBALS['ran_booster_wp_pusher_active_plugins']
		);
	}

	public function test_multisite_rejects_every_manual_package_action(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = true;

		foreach ( array( 'install-plugin', 'install-theme', 'edit-plugin', 'edit-theme', 'update-plugin', 'update-theme', 'unlink-plugin', 'unlink-theme', 'unlink-delete-plugin', 'unlink-delete-theme' ) as $action ) {
			try {
				PackageMutationGuard::assert_admin_action_allowed( action: $action, request: array() );
				self::fail( 'Expected multisite package operations to be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame(
					'RAN Booster managed operations are unavailable on WordPress Multisite.',
					$exception->getMessage()
				);
			}
		}
	}

	public function test_only_the_exact_booster_plugin_file_is_rejected(): void {
		foreach ( array( 'ran-booster/ran-booster.php', 'ran-booster\\ran-booster.php' ) as $identifier ) {
			try {
				PackageMutationGuard::assert_admin_action_allowed( 'update-plugin', array( 'file' => $identifier ) );
				self::fail( 'Expected Booster to reject its exact plugin file.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'own plugin files', $exception->getMessage() );
			}
		}
	}

	public function test_active_wp_pusher_blocks_package_mutations(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Deactivate WP Pusher' );
		PackageMutationGuard::assert_webhook_dispatch_allowed();
	}

	public function test_similar_plugin_file_names_remain_allowed(): void {
		PackageMutationGuard::assert_bulk_admin_allowed( package_type: 'plugin', identifiers: array( 'other/plugin.php' ) );

		foreach ( array( 'ran-booster/ran-booster-extra.php', 'ran-booster-extra/ran-booster.php', 'other/ran-booster.php' ) as $identifier ) {
			PackageMutationGuard::assert_admin_action_allowed( 'update-plugin', array( 'file' => $identifier ) );
		}

		self::assertTrue( true );
	}

	public function test_installed_plugin_link_guard_rejects_only_the_exact_booster_file(): void {
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assert_plugin_file_allowed( 'ran-booster/ran-booster.php' );
	}

	public function test_installed_plugin_link_guard_allows_a_similar_name(): void {
		PackageMutationGuard::assert_plugin_file_allowed( 'ran-booster-extra/ran-booster.php' );

		self::assertTrue( true );
	}

	public function test_target_cap_allows_sixty_four_targets_and_rejects_the_sixty_fifth(): void {
		PackageMutationGuard::assert_deployment_target_count( 64 );
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assert_deployment_target_count( 65 );
	}

	public function test_filesystem_mutation_requires_the_word_press_policy_to_allow_booster(): void {
		PackageMutationGuard::assert_filesystem_mutation_allowed();
		self::assertSame( array( 'ran-booster' ), $GLOBALS['ran_booster_package_mutation_guard_contexts'] );

		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assert_filesystem_mutation_allowed();
	}
}

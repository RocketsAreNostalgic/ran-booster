<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once __DIR__ . '/PackageMutationGuardWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Deployment\PackageMutationGuard;
use RuntimeException;

final class PackageMutationGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_package_mutation_guard_file_mods'],
			$GLOBALS['ran_booster_package_mutation_guard_contexts'],
			$GLOBALS['ran_booster_wp_pusher_active_plugins']
		);
	}

	public function testMultisiteRejectsEveryManualPackageAction(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = true;

		foreach ( array( 'install-plugin', 'install-theme', 'edit-plugin', 'edit-theme', 'update-plugin', 'update-theme', 'unlink-plugin', 'unlink-theme', 'unlink-delete-plugin', 'unlink-delete-theme' ) as $action ) {
			try {
				PackageMutationGuard::assertAdminActionAllowed( $action, array() );
				self::fail( 'Expected multisite package operations to be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame(
					'RAN Booster managed operations are unavailable on WordPress Multisite.',
					$exception->getMessage()
				);
			}
		}
	}

	public function testOnlyTheExactBoosterPluginFileIsRejected(): void {
		foreach ( array( 'ran-booster/ran-booster.php', 'ran-booster\\ran-booster.php' ) as $identifier ) {
			try {
				PackageMutationGuard::assertAdminActionAllowed( 'update-plugin', array( 'file' => $identifier ) );
				self::fail( 'Expected Booster to reject its exact plugin file.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'own plugin files', $exception->getMessage() );
			}
		}
	}

	public function testActiveWpPusherBlocksPackageMutations(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Deactivate WP Pusher' );
		PackageMutationGuard::assertWebhookDispatchAllowed();
	}

	public function testSimilarPluginFileNamesRemainAllowed(): void {
		foreach ( array( 'ran-booster/ran-booster-extra.php', 'ran-booster-extra/ran-booster.php', 'other/ran-booster.php' ) as $identifier ) {
			PackageMutationGuard::assertAdminActionAllowed( 'update-plugin', array( 'file' => $identifier ) );
		}

		self::assertTrue( true );
	}

	public function testInstalledPluginLinkGuardRejectsOnlyTheExactBoosterFile(): void {
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assertPluginFileAllowed( 'ran-booster/ran-booster.php' );
	}

	public function testInstalledPluginLinkGuardAllowsASimilarName(): void {
		PackageMutationGuard::assertPluginFileAllowed( 'ran-booster-extra/ran-booster.php' );

		self::assertTrue( true );
	}

	public function testTargetCapAllowsSixtyFourTargetsAndRejectsTheSixtyFifth(): void {
		PackageMutationGuard::assertDeploymentTargetCount( 64 );
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assertDeploymentTargetCount( 65 );
	}

	public function testFilesystemMutationRequiresTheWordPressPolicyToAllowBooster(): void {
		PackageMutationGuard::assertFilesystemMutationAllowed();
		self::assertSame( array( 'ran-booster' ), $GLOBALS['ran_booster_package_mutation_guard_contexts'] );

		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;
		$this->expectException( RuntimeException::class );
		PackageMutationGuard::assertFilesystemMutationAllowed();
	}
}

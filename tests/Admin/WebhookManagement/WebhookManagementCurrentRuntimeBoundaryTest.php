<?php

declare(strict_types=1);

namespace Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;

final class WebhookManagementCurrentRuntimeBoundaryTest extends TestCase {

	public function testProductionRuntimeCarriesNoAssistedHooksCompatibilityAdapter(): void {
		$root  = dirname( __DIR__, 3 );
		$paths = array( $root . '/ran-booster.php' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/RAN', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === strtolower( $file->getExtension() ) ) {
				$paths[] = $file->getPathname();
			}
		}

		$forbidden = array(
			'legacyAssistedHooksAddOnIsActive',
			'registerLegacyAssistedHooksAddOnNotice',
			'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION',
			'RAN_BOOSTER_BUNDLED_GITHUB_WEBHOOK_MANAGEMENT_VERSION',
			'RAN\\AssistedHooks\\Plugin',
			'pre-retirement RAN Booster Assisted Hooks',
		);

		foreach ( $paths as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local source contract.
			$source = file_get_contents( $path );
			self::assertIsString( $source, $path );
			foreach ( $forbidden as $identifier ) {
				self::assertStringNotContainsString( $identifier, $source, $path );
			}
		}
	}
}

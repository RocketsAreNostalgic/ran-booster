<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class UninstallBoundaryTest extends TestCase {
	#[DataProvider( 'diagnostics' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_safe_uninstall_diagnostic( string $input_message, string $code, string $guidance ): void {
		require __DIR__ . '/UninstallBoundaryFunctions.php';
		class_alias( BoundaryAvailability::class, \RAN\Secrets\SecretsRuntimeAvailability::class );
		class_alias( BoundaryLogger::class, \RAN\Logging\BoosterLogger::class );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress boundary fixture.
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress boundary fixture.
		define( 'WP_UNINSTALL_PLUGIN', 'ran-booster/ran-booster.php' );
		$GLOBALS['ran_booster_boundary_message'] = $input_message;
		try {
			require dirname( __DIR__, 2 ) . '/uninstall.php';
			self::fail( 'The uninstall must stop.' );
		} catch ( \LogicException $result ) {
			self::assertStringContainsString( $code, $result->getMessage() );
			self::assertStringContainsString( $guidance, $result->getMessage() );
			self::assertStringNotContainsString( $input_message, $result->getMessage() );
		}
	}

	/** @return list<array{string, string, string}> */
	public static function diagnostics(): array {
		return array(
			array( 'Booster has an unfinished native mutation that must be resolved before cleanup.', 'uninstall_native_unfinished', 'GitHub page' ),
			array( 'Booster has unfinished work that must be resolved before cleanup.', 'uninstall_deployments_unfinished', 'unfinished deployments' ),
			array( 'Booster could not verify recorded work before cleanup.', 'uninstall_custody_unverified', 'Troubleshooting' ),
			array( 'secret-path-sensitive-value', 'uninstall_failed', 'Resolve the reported problem' ),
		);
	}
}

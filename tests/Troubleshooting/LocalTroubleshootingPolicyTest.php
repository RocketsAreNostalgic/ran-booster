<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Troubleshooting\LocalTroubleshootingService;
use ReflectionClass;
use ReflectionMethod;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class LocalTroubleshootingPolicyTest extends TestCase {
	/** @return list<array{bool}> */
	public static function policy_provider(): array {
		return array( array( true ), array( false ) );
	}

	#[DataProvider( 'policy_provider' )]
	public function test_host_policy_result_and_context_take_precedence_over_the_constant( bool $allowed ): void {
		require __DIR__ . '/LocalTroubleshootingPolicyWordPressFunctions.php';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned policy constant without changing its runtime identity.
		define( 'DISALLOW_FILE_MODS', $allowed );
		$GLOBALS['ran_booster_diagnostic_policy_allowed']  = $allowed;
		$GLOBALS['ran_booster_diagnostic_policy_contexts'] = array();
		$service = ( new ReflectionClass( LocalTroubleshootingService::class ) )->newInstanceWithoutConstructor();

		self::assertSame( $allowed, ( new ReflectionMethod( LocalTroubleshootingService::class, 'filesystem_modification_allowed' ) )->invoke( $service ) );
		self::assertSame( array( 'ran_booster_diagnostics' ), $GLOBALS['ran_booster_diagnostic_policy_contexts'] );
	}

	public function test_missing_host_policy_reports_unavailable_before_any_filesystem_probe(): void {
		self::assertFalse( function_exists( 'wp_is_file_mod_allowed' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned policy constant without changing its runtime identity.
		define( 'DISALLOW_FILE_MODS', false );
		$service = new class() extends LocalTroubleshootingService {
			public int $filesystem_reads = 0;

			public function __construct() {}

			protected function filesystem_method(): string {
				++$this->filesystem_reads;
				return 'ftpext';
			}
		};

		$result = ( new ReflectionMethod( LocalTroubleshootingService::class, 'filesystem_result' ) )->invoke( $service );

		self::assertSame( 'local.filesystem.unavailable', $result->code );
		self::assertSame( 0, $service->filesystem_reads );
	}
}

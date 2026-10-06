<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class BootstrapApiMarkerTest extends TestCase {
	/** @return iterable<string, array{string, mixed, string}> */
	public static function conflicting_marker_provider(): iterable {
		foreach ( array(
			'PROVIDER'          => 14,
			'ADDON'             => 17,
			'ADMIN_INTERACTION' => 3,
			'PORTABILITY'       => 3,
		) as $api => $version ) {
			foreach ( array(
				'older'  => $version - 1,
				'newer'  => $version + 1,
				'string' => (string) $version,
				'false'  => false,
				'null'   => null,
			) as $case => $value ) {
				yield $api . ' ' . $case => array( 'RAN_BOOSTER_' . $api . '_API_VERSION', $value, 'conflicts with an existing API version marker.' );
			}
		}
	}

	#[DataProvider( 'conflicting_marker_provider' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_conflicting_preexisting_marker_stops_bootstrap( string $marker, mixed $value, string $message ): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WPINC', 'wp-includes' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Exercise the exact runtime configuration marker selected by this fixture.
		define( $marker, $value );
		$GLOBALS['ran_booster_bootstrap_multisite'] = false;

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( $message );
		require dirname( __DIR__, 2 ) . '/ran-booster.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_matching_early_markers_remain_accepted(): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WPINC', 'wp-includes' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );
		define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 );

		require dirname( __DIR__, 2 ) . '/ran-booster.php';

		self::assertSame( 'multisite_unsupported', RAN_BOOSTER_RUNTIME_MODE );
	}
}

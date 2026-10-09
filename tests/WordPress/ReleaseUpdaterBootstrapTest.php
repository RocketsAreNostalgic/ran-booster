<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\ReleaseUpdaterBootstrap;

#[CoversClass( ReleaseUpdaterBootstrap::class )]
final class ReleaseUpdaterBootstrapTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_returns_the_public_registrar_which_schedules_activation(): void {
		global $wp_version;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated runtime-selection fixture.
		$wp_version = '6.8.0';

		$registrar = ReleaseUpdaterBootstrap::register();
		$broker    = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;

		self::assertIsObject( $registrar ); // @phpstan-ignore staticMethod.alreadyNarrowedType (Runtime bootstrap acceptance asserts the actual loaded public registrar rather than relying on its declared return type.)
		self::assertIsObject( $broker );
		self::assertTrue( method_exists( $broker, 'protocol_version' ) );
		self::assertTrue( method_exists( $broker, 'diagnostics' ) );
		self::assertSame( 5, $broker->protocol_version() );
		self::assertSame( 1, $broker->diagnostics()['candidate_count'] );
	}
}

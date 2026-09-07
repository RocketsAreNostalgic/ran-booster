<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\ReleaseUpdaterBootstrap;

#[CoversClass( ReleaseUpdaterBootstrap::class )]
final class ReleaseUpdaterBootstrapTest extends TestCase {

	#[RunInSeparateProcess]
	public function testReturnsThePublicRegistrarWhichSchedulesActivation(): void {
		global $wp_version;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated runtime-selection fixture.
		$wp_version = '6.8.0';

		$registrar = ReleaseUpdaterBootstrap::register();
		$broker    = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;

		self::assertIsObject( $registrar );
		self::assertIsObject( $broker );
		self::assertSame( 4, $broker->protocolVersion() );
		self::assertSame( 1, $broker->diagnostics()['candidate_count'] );
	}
}

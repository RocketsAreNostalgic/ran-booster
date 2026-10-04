<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\BoosterNoticeScope;

final class BoosterNoticeScopeTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_current_screen_is_observed_fresh_and_malformed_ids_are_rejected(): void {
		require_once dirname( __DIR__ ) . '/Support/BoosterNoticeScopeWordPressFunctions.php';

		foreach ( array( null, 'plugins', array( 'id' => 'plugins' ), (object) array(), (object) array( 'id' => null ), (object) array( 'id' => 1 ), (object) array( 'id' => array( 'plugins' ) ) ) as $screen ) {
			$GLOBALS['ran_booster_notice_scope_screen'] = $screen;
			self::assertFalse( BoosterNoticeScope::allows() );
			self::assertFalse( BoosterNoticeScope::is_booster_screen() );
		}

		$screen                                     = (object) array( 'id' => 'plugins' );
		$GLOBALS['ran_booster_notice_scope_screen'] = $screen;
		self::assertTrue( BoosterNoticeScope::allows() );
		self::assertFalse( BoosterNoticeScope::is_booster_screen() );
		$screen->id = 'toplevel_page_ran-booster';
		self::assertTrue( BoosterNoticeScope::allows() );
		self::assertTrue( BoosterNoticeScope::is_booster_screen() );
		unset( $screen->id );
		self::assertFalse( BoosterNoticeScope::allows() );
		self::assertFalse( BoosterNoticeScope::is_booster_screen() );
		$GLOBALS['ran_booster_notice_scope_screen'] = new class() {
			public function __isset( string $name ): bool {
				return 'id' === $name;
			}

			public function __get( string $name ): mixed {
				return 'id' === $name ? 'toplevel_page_ran-booster' : null;
			}
		};
		self::assertTrue( BoosterNoticeScope::allows() );
		self::assertTrue( BoosterNoticeScope::is_booster_screen() );
	}
}

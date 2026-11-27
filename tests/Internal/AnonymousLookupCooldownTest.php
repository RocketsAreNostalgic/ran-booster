<?php

declare(strict_types=1);

namespace RAN\Tests\Internal;

require_once __DIR__ . '/AnonymousLookupCooldownWordPressFunctions.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Internal\AnonymousLookupCooldown;

#[CoversClass( AnonymousLookupCooldown::class )]
final class AnonymousLookupCooldownTest extends TestCase {

	private const NOW = 1_800_000_000;

	protected function setUp(): void {
		$GLOBALS['ran_booster_cooldown_transients'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_cooldown_transients'] );
	}

	public function test_a_trustworthy_retry_time_is_kept_until_it_passes_and_expires_with_it(): void {
		AnonymousLookupCooldown::record(
			'gh',
			array(
				'provider' => 'GitHub',
				'retry_at' => self::NOW + 3540,
			),
			self::NOW
		);

		// Only the retry time is stored, never the label or other facts, and it expires on its own.
		self::assertSame(
			array(
				'ran_booster_anonymous_cooldown_gh' => array(
					'value'      => self::NOW + 3540,
					'expiration' => 3540,
				),
			),
			$GLOBALS['ran_booster_cooldown_transients']
		);
		self::assertSame( self::NOW + 3540, AnonymousLookupCooldown::until( 'gh', self::NOW ) );
		self::assertNull( AnonymousLookupCooldown::until( 'gh', self::NOW + 3540 ) );
		self::assertNull( AnonymousLookupCooldown::until( 'bb', self::NOW ) );
	}

	public function test_an_unknown_past_or_distant_deadline_is_never_stored(): void {
		foreach ( array( null, self::NOW, self::NOW - 60, self::NOW + 86401, '1800003540', 1.5 ) as $retry_at ) {
			AnonymousLookupCooldown::record(
				'gh',
				array(
					'provider' => 'GitHub',
					'retry_at' => $retry_at,
				),
				self::NOW
			);
		}
		AnonymousLookupCooldown::record( 'gh', null, self::NOW );

		self::assertSame( array(), $GLOBALS['ran_booster_cooldown_transients'] );
	}

	public function test_an_invalid_provider_code_or_stored_value_reads_as_no_cooldown(): void {
		AnonymousLookupCooldown::record(
			'Not A Code',
			array(
				'provider' => 'GitHub',
				'retry_at' => self::NOW + 60,
			),
			self::NOW
		);
		self::assertSame( array(), $GLOBALS['ran_booster_cooldown_transients'] );

		foreach ( array( 'not-an-int', array( self::NOW + 60 ), self::NOW + 86401, false ) as $stored ) {
			$GLOBALS['ran_booster_cooldown_transients']['ran_booster_anonymous_cooldown_gh'] = array( 'value' => $stored );
			self::assertNull( AnonymousLookupCooldown::until( 'gh', self::NOW ) );
		}
	}
}

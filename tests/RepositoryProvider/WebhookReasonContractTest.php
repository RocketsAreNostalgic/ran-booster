<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;

final class WebhookReasonContractTest extends TestCase {

	public function test_closed_optional_reasons_preserve_old_callers_and_original_hostile_values(): void {
		foreach ( array_merge( array( null, '', 'unknown', "signature_missing\n", str_repeat( 'x', 200 ) . 'signature_missing' ), WebhookRejected::REASONS ) as $reason ) {
			$expected = in_array( $reason, WebhookRejected::REASONS, true ) ? $reason : null;
			$rejected = new WebhookRejected( 403, 'hostile original message', $reason );
			$ignored  = WebhookEnvelope::ignored( $reason );
			self::assertSame( 403, $rejected->get_status_code() );
			self::assertSame( 'hostile original message', $rejected->getMessage() );
			self::assertSame( $expected, $rejected->get_reason() );
			self::assertSame( $expected, $ignored->get_reason() );
			self::assertTrue( $ignored->is_ignored() );
			self::assertSame( array(), $ignored->get_events() );
		}
		self::assertNull( ( new WebhookRejected( 400, 'old provider' ) )->get_reason() );
		self::assertNull( WebhookEnvelope::ignored()->get_reason() );
		self::assertNull( WebhookEnvelope::probe()->get_reason() );
	}
}

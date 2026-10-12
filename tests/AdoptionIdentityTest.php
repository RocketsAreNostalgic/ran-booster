<?php

declare(strict_types=1);

namespace RAN\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RAN\AdoptionIdentity;

final class AdoptionIdentityTest extends RANBoosterTestCase {
	public function test_opaque_id_roundtrip_and_distinct_lifetimes(): void {
		$id     = '000:{opaque}_%/é white space';
		$first  = AdoptionIdentity::create( 'gh', $id );
		$second = AdoptionIdentity::create( 'gh', $id );
		self::assertNotSame( $first, $second );
		self::assertSame( $id, AdoptionIdentity::parse( $first )['repository_id'] );
		self::assertTrue( AdoptionIdentity::matches( $first, 'gh', $id ) );
		self::assertFalse( AdoptionIdentity::matches( $first, 'bb', $id ) );
		self::assertFalse( AdoptionIdentity::matches( $first, 'gh', strtoupper( $id ) ) );
		$largest = AdoptionIdentity::create( str_repeat( 'a', 32 ), str_repeat( ':', 191 ) );
		self::assertSame( AdoptionIdentity::MAX_LENGTH, strlen( $largest ) );
	}

	/** @return array<string,array{string}> */
	public static function malformed_tokens(): array {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		return array(
			'empty'               => array( '' ),
			'legacy'              => array( 'gh_123_' . $uuid ),
			'raw colon'           => array( 'gh:a:b:' . $uuid ),
			'noncanonical escape' => array( 'gh:%41:' . $uuid ),
			'lowercase escape'    => array( 'gh:%3a:' . $uuid ),
			'control'             => array( 'gh:%00:' . $uuid ),
			'empty id'            => array( 'gh::' . $uuid ),
			'uppercase provider'  => array( 'GH:123:' . $uuid ),
			'uppercase uuid'      => array( 'gh:123:' . strtoupper( $uuid ) ),
			'wrong uuid version'  => array( 'gh:123:' . str_replace( '-4234-', '-1234-', $uuid ) ),
			'truncated'           => array( 'gh:123:' . substr( $uuid, 0, -1 ) ),
			'oversize id'         => array( 'gh:' . str_repeat( 'a', 192 ) . ':' . $uuid ),
		);
	}

	#[DataProvider( 'malformed_tokens' )]
	public function test_malformed_or_alternate_encodings_fail( string $token ): void {
		$this->expectException( InvalidArgumentException::class );
		AdoptionIdentity::parse( $token );
	}
}

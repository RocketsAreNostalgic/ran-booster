<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use RuntimeException;

final class PublicRepositoryLookupProfileStoreTest extends TestCase {

	public function test_changed_and_unchanged_writes_preserve_then_clear_the_preference(): void {
		$store = new InMemoryPublicRepositoryLookupProfileStore();
		$store->set( 'gh', 'public-profile' );
		$store->set( 'gh', 'public-profile' );
		self::assertSame( 'public-profile', $store->get( 'gh' ) );
		$store->set( 'gh', null );
		self::assertNull( $store->get( 'gh' ) );
		self::assertSame( array(), $store->profiles );
	}

	public function test_false_write_accepts_an_identical_readback(): void {
		$store              = new InMemoryPublicRepositoryLookupProfileStore();
		$store->profiles    = array( 'gh' => 'public-profile' );
		$store->fail_writes = true;
		$store->set( 'gh', 'public-profile' );
		self::assertSame( 'public-profile', $store->get( 'gh' ) );
	}

	public function test_failed_changed_write_preserves_the_previous_preference(): void {
		$store              = new InMemoryPublicRepositoryLookupProfileStore();
		$store->profiles    = array( 'gh' => 'public-profile' );
		$store->fail_writes = true;
		try {
			$store->set( 'gh', 'replacement-profile' );
			self::fail( 'Changed preferences require successful persistence or matching readback.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Booster could not save the public repository lookup preference.', $error->getMessage() );
		}
		self::assertSame( 'public-profile', $store->get( 'gh' ) );
	}

	public function test_malformed_stored_preferences_do_not_become_lookup_authority(): void {
		$store           = new InMemoryPublicRepositoryLookupProfileStore();
		$store->profiles = array(
			'gh'               => array( 'secret' ),
			'invalid provider' => 'public-profile',
			'bb'               => 'valid-profile',
		);
		self::assertNull( $store->get( 'gh' ) );
		self::assertNull( $store->get( 'invalid provider' ) );
		self::assertSame( 'valid-profile', $store->get( 'bb' ) );
	}
}

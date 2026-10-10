<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RuntimeException;

final class CredentialExpiryObservationStoreTest extends TestCase {

	public function test_changed_and_unchanged_writes_preserve_then_clear_the_observation(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		self::assertSame( array( 'manual_expires_on' => '2027-01-01' ), $store->get( 'gh', 'public-profile' ) );
		$store->clear( 'gh', 'public-profile' );
		self::assertSame( array(), $store->observations() );
		self::assertSame(
			array(
				'version'  => 1,
				'profiles' => array(),
			),
			$store->document
		);
	}

	public function test_false_write_accepts_an_identical_readback(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->fail_writes = true;
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		self::assertSame( array( 'manual_expires_on' => '2027-01-01' ), $store->get( 'gh', 'public-profile' ) );
	}

	public function test_failed_changed_write_preserves_the_previous_observation(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->fail_writes = true;
		try {
			$store->set_manual_expiry( 'gh', 'public-profile', '2028-01-01' );
			self::fail( 'Changed observations require successful persistence or matching readback.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Booster could not save credential expiry information.', $error->getMessage() );
		}
		self::assertSame( array( 'manual_expires_on' => '2027-01-01' ), $store->get( 'gh', 'public-profile' ) );
	}

	public function test_unknown_schema_does_not_become_observations(): void {
		$store           = new InMemoryCredentialExpiryObservationStore();
		$store->document = array(
			'version'  => 2,
			'profiles' => array( 'gh' => array( 'public-profile' => array( 'manual_expires_on' => '2027-01-01' ) ) ),
		);
		self::assertSame( array(), $store->observations() );
	}

	public function test_malformed_records_do_not_become_observations(): void {
		$store                       = new InMemoryCredentialExpiryObservationStore();
		$store->document['version']  = 1;
		$store->document['profiles'] = array(
			'gh' => array(
				'public-profile' => array(
					'manual_expires_on'   => '2027-02-30',
					'provider_expires_at' => '2027-01-01T00:00:00Z',
				),
			),
		);
		self::assertSame( array(), $store->observations() );
	}
}

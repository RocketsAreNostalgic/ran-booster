<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RuntimeException;

final class CredentialExpiryObservationStoreTest extends TestCase {

	public function test_stale_matching_snapshot_does_not_skip_a_required_write(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->stale_read = $store->document;
		$store->document['profiles']['gh']['public-profile']['manual_expires_on'] = '2028-01-01';
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->stale_read = null;
		self::assertSame( array( 'manual_expires_on' => '2027-01-01' ), $store->get( 'gh', 'public-profile' ) );
	}

	public function test_concurrent_provider_observation_survives_manual_expiry_patch(): void {
		$store               = new InMemoryCredentialExpiryObservationStore();
		$store->before_write = static function () use ( $store ): void {
			$store->record_provider_expiry( 'gh', 'public-profile', \RAN\RepositoryProvider\CredentialExpiryReport::unknown(), '2026-10-13T00:00:00Z' );
		};
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		self::assertSame(
			array(
				'manual_expires_on'   => '2027-01-01',
				'provider_checked_at' => '2026-10-13T00:00:00Z',
			),
			$store->get( 'gh', 'public-profile' )
		);
	}

	public function test_clear_preserves_concurrent_other_profile(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		$store->before_write = static function () use ( $store ): void {
			$store->set_manual_expiry( 'gh', 'other-profile', '2028-01-01' );
		};
		$store->clear( 'gh', 'public-profile' );
		self::assertSame( array(), $store->get( 'gh', 'public-profile' ) );
		self::assertSame( array( 'manual_expires_on' => '2028-01-01' ), $store->get( 'gh', 'other-profile' ) );
	}

	public function test_concurrent_other_profile_is_preserved(): void {
		$store               = new InMemoryCredentialExpiryObservationStore();
		$store->before_write = static function () use ( $store ): void {
			$store->set_manual_expiry( 'gh', 'other-profile', '2028-01-01' );
		};
		$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
		self::assertSame( array( 'manual_expires_on' => '2028-01-01' ), $store->get( 'gh', 'other-profile' ) );
		self::assertSame( array( 'manual_expires_on' => '2027-01-01' ), $store->get( 'gh', 'public-profile' ) );
	}

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

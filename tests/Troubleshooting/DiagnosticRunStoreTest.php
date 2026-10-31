<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\TestCase;
use RAN\Troubleshooting\DiagnosticRunStore;
require_once __DIR__ . '/DiagnosticRunMemory.php';
require_once __DIR__ . '/DiagnosticRunFixture.php';

final class DiagnosticRunStoreTest extends TestCase {
	public function test_initiating_scope_cannot_be_reassigned_to_another_administrator(): void {
		$memory = new DiagnosticRunMemory();
		$store  = new DiagnosticRunFixture( $memory );
		$scope  = $store->scope_key();
		self::assertSame( '1:1', $scope );
		$store->identity = array(
			'site' => 1,
			'user' => 2,
		);
		self::assertFalse( $store->save( 'gh', $this->safe_run(), $scope ) );
		self::assertNull( $memory->raw );
		self::assertSame( 0, $memory->writes );
	}

	public function test_navigation_independent_providers_administrators_and_sites(): void {
		$memory = new DiagnosticRunMemory();
		$first  = new DiagnosticRunFixture( $memory );
		self::assertTrue( $first->save( 'gh', $this->safe_run() ) );
		self::assertTrue( $first->save( 'bb', $this->safe_run( 1001.0 ) ) );
		$reloaded = new DiagnosticRunFixture( $memory );
		self::assertSame( array( 'gh', 'bb' ), array_column( $reloaded->available()['runs'], 'provider' ) );
		$reloaded->identity = array(
			'site' => 1,
			'user' => 2,
		);
		self::assertSame( array(), $reloaded->available()['runs'] );
		self::assertTrue( $reloaded->save( 'gh', $this->safe_run() ) );
		$reloaded->identity = array(
			'site' => 2,
			'user' => 1,
		);
		self::assertSame( array(), $reloaded->available()['runs'] );
		self::assertTrue( $reloaded->save( 'gh', $this->safe_run() ) );
		self::assertCount( 2, $first->available()['runs'] );
		$reloaded->identity = null;
		self::assertFalse( $reloaded->available()['available'] );
		self::assertFalse( $reloaded->save( 'gh', $this->safe_run() ) );
	}

	public function test_exact_expiry_does_not_slide_and_cleanup_is_bounded(): void {
		$memory = new DiagnosticRunMemory();
		$store  = new DiagnosticRunFixture( $memory );
		self::assertTrue( $store->save( 'gh', $this->safe_run( 1000.25 ) ) );
		$original    = $memory->raw;
		$store->time = 4600.249;
		self::assertCount( 1, $store->available()['runs'] );
		self::assertSame( 4600.25, $store->available()['runs'][0]['expires_at'] );
		self::assertSame( $original, $memory->raw );
		$store->time = 4600.25;
		self::assertSame( array(), $store->available()['runs'] );
		self::assertSame( $original, $memory->raw );
		$store->cleanup();
		self::assertSame( array(), json_decode( $memory->raw, true )['entries'] );
	}

	public function test_later_completed_run_wins_even_when_older_write_arrives_last(): void {
		$store = new DiagnosticRunFixture( new DiagnosticRunMemory() );
		self::assertTrue( $store->save( 'gh', $this->safe_run( 1002.0 ) ) );
		self::assertTrue( $store->save( 'gh', $this->safe_run( 1001.0 ) ) );
		self::assertSame( 1002.0, $store->available()['runs'][0]['completed_at'] );
		$failure                         = $this->safe_run( 1003.0 );
		$failure['partial']              = true;
		$failure['partial_reason']       = 'provider_unavailable';
		$failure['results'][0]['status'] = 'fail';
		self::assertTrue( $store->save( 'gh', $failure ) );
		self::assertTrue( $store->available()['runs'][0]['partial'] );
		self::assertSame( 'fail', $store->available()['runs'][0]['results'][0]['status'] );
	}

	public function test_compare_and_swap_retries_preserve_concurrent_provider_and_newer_completion(): void {
		$memory           = new DiagnosticRunMemory();
		$first            = new DiagnosticRunFixture( $memory );
		$second           = new DiagnosticRunFixture( $memory );
		$memory->conflict = function () use ( $second ): void {
			self::assertTrue( $second->save( 'bb', $this->safe_run( 1002.0 ) ) );
			self::assertTrue( $second->save( 'gh', $this->safe_run( 1003.0 ) ) );
		};
		self::assertTrue( $first->save( 'gh', $this->safe_run( 1001.0 ) ) );
		self::assertSame( array( 'bb', 'gh' ), array_column( $first->available()['runs'], 'provider' ) );
		self::assertSame( 1003.0, $first->available()['runs'][1]['completed_at'] );
	}

	public function test_saturation_refuses_new_slots_preserves_other_evidence_and_reclaims_expired_slots(): void {
		$memory = new DiagnosticRunMemory();
		$store  = new DiagnosticRunFixture( $memory );
		for ( $user = 1; $user <= 64; ++$user ) {
			$store->identity = array(
				'site' => 1,
				'user' => $user,
			);
			self::assertTrue( $store->save( 'gh', $this->safe_run() ) );
		}
		$before          = $memory->raw;
		$store->identity = array(
			'site' => 1,
			'user' => 65,
		);
		self::assertFalse( $store->save( 'gh', $this->safe_run() ) );
		self::assertSame( $before, $memory->raw );
		$store->time = 4600.0;
		self::assertTrue( $store->save( 'gh', $this->safe_run( 4600.0 ) ) );
		self::assertCount( 1, json_decode( $memory->raw, true )['entries'] );
	}

	public function test_failures_invalid_storage_and_conflicts_are_unavailable_without_throwing(): void {
		$memory = new DiagnosticRunMemory();
		$store  = new DiagnosticRunFixture( $memory );
		foreach ( array( '{invalid', str_repeat( 'x', 524289 ), '{"schema":1,"entries":[],"secret":"canary"}' ) as $raw ) {
			$memory->raw = $raw;
			self::assertFalse( $store->available()['available'] );
			self::assertFalse( $store->save( 'gh', $this->safe_run() ) );
			$store->cleanup();
			self::assertSame( $raw, $memory->raw );
		}
		$memory->raw    = null;
		$memory->refuse = true;
		$before         = $memory->writes;
		self::assertFalse( $store->save( 'gh', $this->safe_run() ) );
		self::assertSame( 5, $memory->writes - $before );
		$memory->broken = true;
		self::assertFalse( $store->save( 'gh', $this->safe_run() ) );
		self::assertFalse( $store->available()['available'] );
	}

	public function test_only_the_fixed_scalar_input_schema_enters_storage(): void {
		$store                    = new DiagnosticRunFixture( new DiagnosticRunMemory() );
		$invalid                  = array();
		$run                      = $this->safe_run();
		$run['credential_id']     = 'private-canary';
		$invalid[]                = $run;
		$run                      = $this->safe_run();
		$run['results'][0]['raw'] = 'private-canary';
		$invalid[]                = $run;
		$run                      = $this->safe_run();
		$run['results']           = array_fill( 0, 9, $run['results'][0] );
		$invalid[]                = $run;
		foreach ( array( NAN, INF, -1.0, 2000.0 ) as $timestamp ) {
			$run       = $this->safe_run( $timestamp );
			$invalid[] = $run;
		}
		foreach ( array( "unsafe\ntext", "invalid\xff", str_repeat( 'a', 513 ) ) as $text ) {
			$run                          = $this->safe_run();
			$run['results'][0]['message'] = $text;
			$invalid[]                    = $run;
		}
		foreach ( $invalid as $run ) {
			self::assertFalse( $store->save( 'gh', $run ) );
		}
		self::assertFalse( $store->save( '../private', $this->safe_run() ) );
		self::assertTrue( $store->save( 'gh', $this->safe_run() ) );
		$public = $store->available()['runs'][0];
		self::assertArrayNotHasKey( 'site', $public );
		self::assertArrayNotHasKey( 'user', $public );
		self::assertArrayNotHasKey( 'nonce', $public );
	}

	public function test_equal_completion_tie_and_stored_field_tampering(): void {
		$memory = new DiagnosticRunMemory();
		$store  = new DiagnosticRunFixture( $memory );
		self::assertTrue( $store->save( 'gh', $this->safe_run() ) );
		$document                               = json_decode( $memory->raw, true );
		$document['entries']['1:1:gh']['nonce'] = str_repeat( 'f', 32 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact boundary fixture JSON preserves fractional completion timestamps.
		$memory->raw                  = json_encode( $document, JSON_PRESERVE_ZERO_FRACTION );
		$tied                         = $this->safe_run();
		$tied['results'][0]['status'] = 'fail';
		self::assertTrue( $store->save( 'gh', $tied ) );
		self::assertSame( 'pass', $store->available()['runs'][0]['results'][0]['status'] );
		$document['entries']['1:1:gh']['run']['actor'] = 'private-canary';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact boundary fixture JSON preserves fractional completion timestamps.
		$memory->raw = json_encode( $document, JSON_PRESERVE_ZERO_FRACTION );
		self::assertFalse( $store->available()['available'] );
	}

	public function test_entry_size_and_authorization_changes_refuse_writes(): void {
		$memory                           = new DiagnosticRunMemory();
		$store                            = new DiagnosticRunFixture( $memory );
		$run                              = $this->safe_run();
		$run['results'][0]['message']     = str_repeat( 'a', 512 );
		$run['results'][0]['remediation'] = str_repeat( 'a', 512 );
		$run['results']                   = array_fill( 0, 8, $run['results'][0] );
		self::assertFalse( $store->save( 'gh', $run ) );
		self::assertNull( $memory->raw );
		$memory->conflict = static function () use ( $store ): void {
			$store->identity = array(
				'site' => 1,
				'user' => 2,
			);
		};
		self::assertFalse( $store->save( 'gh', $this->safe_run() ) );
		self::assertNull( $memory->raw );
	}

	/** @return array<string,mixed> */
	private function safe_run( float $completed_at = 1000.0 ): array {
		return array(
			'completed_at'        => $completed_at,
			'implementation'      => str_repeat( 'a', 64 ),
			'credential_selected' => true,
			'repository_selected' => true,
			'partial'             => false,
			'partial_reason'      => null,
			'results'             => array(
				array(
					'status'      => 'pass',
					'code'        => 'provider.check',
					'message'     => 'Provider result details omitted by the sharing policy.',
					'remediation' => 'Review the immediate diagnostic results locally.',
				),
			),
		);
	}
}

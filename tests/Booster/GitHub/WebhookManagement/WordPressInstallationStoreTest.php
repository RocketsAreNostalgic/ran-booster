<?php

declare( strict_types = 1 );

namespace Tests\Booster\GitHub\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationRecord;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationStore;
use RAN\Booster\GitHub\WebhookManagement\Installation\WordPressInstallationStore;

require_once __DIR__ . '/WordPressInstallationStoreWordPressFunctions.php';

final class WordPressInstallationStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_assisted_hooks_test_options'] = array();
	}

	public function testNumericRepositoryIdentityRoundTripsAcrossStoreInstancesAndRemainsIdempotent(): void {
		$record = new InstallationRecord(
			'gh',
			'424242',
			'owner/repository',
			'99',
			'profile_1',
			'repository',
			1,
			'created',
			'https://example.test/wp-json/ran-booster/webhook',
			'configured',
			'2026-07-23T16:00:00Z',
			'2026-07-23T16:00:00Z'
		);

		self::assertSame( InstallationStore::WRITE_APPLIED, $this->store()->saveIfCurrent( $record, null ) );

		$fresh = $this->store();
		self::assertSame( $record->toArray(), $fresh->find( 'gh', '424242' )?->toArray() );
		self::assertSame( InstallationStore::WRITE_UNCHANGED, $fresh->saveIfCurrent( $record, $record ) );
		self::assertSame( $record->toArray(), $this->store()->find( 'gh', '424242' )?->toArray() );
	}

	public function testProviderAndRepositoryFormTheStorageIdentity(): void {
		$github = new InstallationRecord( 'gh', 'same', 'owner/repository', '99', 'profile_1', 'owner', 1, 'reused', 'https://example.test/wp-json/ran-booster/webhook', 'configured', '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
		$other  = new InstallationRecord( 'fixture', 'same', 'workspace/repository', 'opaque-hook', 'profile_2', 'owner', 1, 'reused', 'https://example.test/wp-json/ran-booster/webhook', 'configured', '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
		$store  = $this->store();

		self::assertSame( InstallationStore::WRITE_APPLIED, $store->saveIfCurrent( $github, null ) );
		self::assertSame( InstallationStore::WRITE_APPLIED, $store->saveIfCurrent( $other, null ) );
		self::assertSame( '99', $store->find( 'gh', 'same' )?->hookId() );
		self::assertSame( 'opaque-hook', $store->find( 'fixture', 'same' )?->hookId() );
	}

	public function testWholeMapCasRetriesWithoutLosingAnInterleavedIndependentTarget(): void {
		$github = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$other  = $this->record( 'fixture', 'other', 'workspace/repository', 'opaque-hook' );
		$store  = $this->store(
			static function () use ( $other ): void {
				$GLOBALS['ran_booster_assisted_hooks_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$other->storageKey() => $other->toArray(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_APPLIED, $store->saveIfCurrent( $github, null ) );
		self::assertSame( $github->toArray(), $store->find( 'gh', 'github' )?->toArray() );
		self::assertSame( $other->toArray(), $store->find( 'fixture', 'other' )?->toArray() );
	}

	public function testSameTargetCasNeverOverwritesAnInterleavedKnownRecordWithAmbiguousRecovery(): void {
		$known    = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$recovery = $this->record( 'gh', 'github', 'owner/repository', InstallationRecord::unknownHookId(), 'orphaned' );
		$store    = $this->store(
			static function () use ( $known ): void {
				$GLOBALS['ran_booster_assisted_hooks_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$known->storageKey() => $known->toArray(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_CONFLICT, $store->saveIfCurrent( $recovery, null ) );
		self::assertSame( $known->toArray(), $store->find( 'gh', 'github' )?->toArray() );
	}

	public function testSameTargetCasNeverOverwritesInterleavedRecoveryWithStaleKnownEvidence(): void {
		$known    = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$recovery = $this->record( 'gh', 'github', 'owner/repository', InstallationRecord::unknownHookId(), 'orphaned' );
		$store    = $this->store(
			static function () use ( $recovery ): void {
				$GLOBALS['ran_booster_assisted_hooks_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$recovery->storageKey() => $recovery->toArray(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_CONFLICT, $store->saveIfCurrent( $known, null ) );
		self::assertSame( $recovery->toArray(), $store->find( 'gh', 'github' )?->toArray() );
	}

	public function testMalformedAndFutureRecordsFailClosedWithoutRewritingTheOption(): void {
		$valid                    = $this->record( 'gh', 'valid', 'owner/repository', '77' );
		$future                   = $valid->toArray();
		$future['schema_version'] = 4;
		$raw                      = array(
			$valid->storageKey() => $valid->toArray(),
			'gh:future'          => $future,
			'gh:malformed'       => array(
				'schema_version' => 3,
				'token'          => 'must-not-be-read',
			),
		);
		$GLOBALS['ran_booster_assisted_hooks_test_options'][ WordPressInstallationStore::OPTION_NAME ] = $raw;

		$records = $this->store()->all();

		self::assertSame( array( $valid->storageKey() ), array_keys( $records ) );
		self::assertSame( $valid->toArray(), $records[ $valid->storageKey() ]->toArray() );
		self::assertSame( $raw, $GLOBALS['ran_booster_assisted_hooks_test_options'][ WordPressInstallationStore::OPTION_NAME ] );
	}

	private function record( string $providerCode, string $repositoryId, string $repository, string $hookId, string $status = 'configured' ): InstallationRecord {
		return new InstallationRecord( $providerCode, $repositoryId, $repository, $hookId, 'profile_1', 'repository', 1, 'created', 'https://example.test/wp-json/ran-booster/webhook', $status, '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
	}

	private function store( ?callable $beforeFirstCas = null ): WordPressInstallationStore {
		$before = null === $beforeFirstCas ? null : \Closure::fromCallable( $beforeFirstCas );

		return new WordPressInstallationStore(
			static function ( string $option, mixed $expected, mixed $replacement, bool $exists ) use ( &$before ): bool {
				if ( null !== $before ) {
					$interleave = $before;
					$before     = null;
					$interleave();
				}
				$currentExists = array_key_exists( $option, $GLOBALS['ran_booster_assisted_hooks_test_options'] );
				$current       = $GLOBALS['ran_booster_assisted_hooks_test_options'][ $option ] ?? null;
				if ( $exists !== $currentExists || ( $exists && $expected !== $current ) ) {
					return false;
				}
				$GLOBALS['ran_booster_assisted_hooks_test_options'][ $option ] = $replacement;

				return true;
			}
		);
	}
}

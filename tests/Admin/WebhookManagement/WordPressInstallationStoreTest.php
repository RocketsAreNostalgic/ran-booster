<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;

require_once __DIR__ . '/WordPressInstallationStoreWordPressFunctions.php';

final class WordPressInstallationStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_webhook_management_test_options'] = array();
	}

	public function test_numeric_repository_identity_round_trips_across_store_instances_and_remains_idempotent(): void {
		$record = new InstallationRecord(
			'gh',
			'424242',
			'owner/repository',
			'99',
			'credential_1',
			'profile_1',
			'repository',
			1,
			'created',
			'https://example.test/wp-json/ran-booster/webhook',
			'configured',
			'2026-07-23T16:00:00Z',
			'2026-07-23T16:00:00Z'
		);

		self::assertSame( InstallationStore::WRITE_APPLIED, $this->store()->save_if_current( $record, null ) );

		$fresh = $this->store();
		self::assertSame( $record->to_array(), $fresh->find( 'gh', '424242' )?->to_array() );
		self::assertSame( InstallationStore::WRITE_UNCHANGED, $fresh->save_if_current( $record, $record ) );
		self::assertSame( $record->to_array(), $this->store()->find( 'gh', '424242' )?->to_array() );
	}

	public function test_provider_and_repository_form_the_storage_identity(): void {
		$github = new InstallationRecord( 'gh', 'same', 'owner/repository', '99', 'credential_1', 'profile_1', 'owner', 1, 'reused', 'https://example.test/wp-json/ran-booster/webhook', 'configured', '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
		$other  = new InstallationRecord( 'fixture', 'same', 'workspace/repository', 'opaque-hook', 'credential_2', 'profile_2', 'owner', 1, 'reused', 'https://example.test/wp-json/ran-booster/webhook', 'configured', '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
		$store  = $this->store();

		self::assertSame( InstallationStore::WRITE_APPLIED, $store->save_if_current( $github, null ) );
		self::assertSame( InstallationStore::WRITE_APPLIED, $store->save_if_current( $other, null ) );
		self::assertSame( '99', $store->find( 'gh', 'same' )?->hook_id() );
		self::assertSame( 'opaque-hook', $store->find( 'fixture', 'same' )?->hook_id() );
	}

	public function test_whole_map_cas_retries_without_losing_an_interleaved_independent_target(): void {
		$github = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$other  = $this->record( 'fixture', 'other', 'workspace/repository', 'opaque-hook' );
		$store  = $this->store(
			static function () use ( $other ): void {
				$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$other->storage_key() => $other->to_array(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_APPLIED, $store->save_if_current( $github, null ) );
		self::assertSame( $github->to_array(), $store->find( 'gh', 'github' )?->to_array() );
		self::assertSame( $other->to_array(), $store->find( 'fixture', 'other' )?->to_array() );
	}

	public function test_same_target_cas_never_overwrites_an_interleaved_known_record_with_ambiguous_recovery(): void {
		$known    = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$recovery = $this->record( 'gh', 'github', 'owner/repository', InstallationRecord::unknown_hook_id(), 'orphaned' );
		$store    = $this->store(
			static function () use ( $known ): void {
				$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$known->storage_key() => $known->to_array(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_CONFLICT, $store->save_if_current( $recovery, null ) );
		self::assertSame( $known->to_array(), $store->find( 'gh', 'github' )?->to_array() );
	}

	public function test_same_target_cas_never_overwrites_interleaved_recovery_with_stale_known_evidence(): void {
		$known    = $this->record( 'gh', 'github', 'owner/repository', '77' );
		$recovery = $this->record( 'gh', 'github', 'owner/repository', InstallationRecord::unknown_hook_id(), 'orphaned' );
		$store    = $this->store(
			static function () use ( $recovery ): void {
				$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
					$recovery->storage_key() => $recovery->to_array(),
				);
			}
		);

		self::assertSame( InstallationStore::WRITE_CONFLICT, $store->save_if_current( $known, null ) );
		self::assertSame( $recovery->to_array(), $store->find( 'gh', 'github' )?->to_array() );
	}

	public function test_malformed_and_future_records_fail_closed_without_rewriting_the_option(): void {
		$valid                    = $this->record( 'gh', 'valid', 'owner/repository', '77' );
		$future                   = $valid->to_array();
		$future['schema_version'] = 5;
		$raw                      = array(
			$valid->storage_key() => $valid->to_array(),
			'gh:future'           => $future,
			'gh:malformed'        => array(
				'schema_version' => 3,
				'token'          => 'must-not-be-read',
			),
		);
		$GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] = $raw;

		$records = $this->store()->all();

		self::assertSame( array( $valid->storage_key() ), array_keys( $records ) );
		self::assertSame( $valid->to_array(), $records[ $valid->storage_key() ]->to_array() );
		self::assertSame( $raw, $GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] );
	}

	public function test_save_fails_without_rewriting_an_option_containing_malformed_or_future_records(): void {
		$raw = $this->incomplete_raw();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] = $raw;

		self::assertSame( InstallationStore::WRITE_FAILED, $this->store()->save_if_current( $this->record( 'gh', 'new', 'owner/new', '88' ), null ) );
		self::assertSame( $raw, $GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] );
	}

	public function test_remove_fails_without_rewriting_an_option_containing_malformed_or_future_records(): void {
		$record = $this->record( 'gh', 'valid', 'owner/repository', '77' );
		$raw    = $this->incomplete_raw();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] = $raw;

		self::assertSame( InstallationStore::WRITE_FAILED, $this->store()->delete_if_current( $record->provider_code(), $record->repository_id(), $record ) );
		self::assertSame( $raw, $GLOBALS['ran_booster_repository_webhook_management_test_options'][ WordPressInstallationStore::OPTION_NAME ] );
	}

	private function record( string $provider_code, string $repository_id, string $repository, string $hook_id, string $status = 'configured' ): InstallationRecord {
		return new InstallationRecord( $provider_code, $repository_id, $repository, $hook_id, 'credential_1', 'profile_1', 'repository', 1, 'created', 'https://example.test/wp-json/ran-booster/webhook', $status, '2026-07-23T16:00:00Z', '2026-07-23T16:00:00Z' );
	}

	/** @return array<string, array<string, int|string>> */
	private function incomplete_raw(): array {
		$valid                    = $this->record( 'gh', 'valid', 'owner/repository', '77' );
		$future                   = $valid->to_array();
		$future['schema_version'] = 5;

		return array(
			$valid->storage_key() => $valid->to_array(),
			'gh:future'           => $future,
			'gh:malformed'        => array(
				'schema_version' => 4,
				'token'          => 'must-not-be-read',
			),
		);
	}

	private function store( ?callable $before_first_cas = null ): WordPressInstallationStore {
		$before = null === $before_first_cas ? null : \Closure::fromCallable( $before_first_cas );

		return new WordPressInstallationStore(
			static function ( string $option, mixed $expected, mixed $replacement, bool $exists ) use ( &$before ): bool {
				if ( null !== $before ) {
					$interleave = $before;
					$before     = null;
					$interleave();
				}
				$current_exists = array_key_exists( $option, $GLOBALS['ran_booster_repository_webhook_management_test_options'] );
				$current       = $GLOBALS['ran_booster_repository_webhook_management_test_options'][ $option ] ?? null;
				if ( $exists !== $current_exists || ( $exists && $expected !== $current ) ) {
					return false;
				}
				$GLOBALS['ran_booster_repository_webhook_management_test_options'][ $option ] = $replacement;

				return true;
			}
		);
	}
}

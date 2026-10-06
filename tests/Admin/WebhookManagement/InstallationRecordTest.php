<?php

declare( strict_types = 1 );

namespace RAN\Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;

final class InstallationRecordTest extends TestCase {
	public function test_it_persists_only_non_secret_metadata(): void {
		$record = new InstallationRecord(
			'gh',
			'1234',
			'owner/repository',
			'00099',
			'credential_1',
			'profile_1',
			'owner',
			1,
			'reused',
			'https://example.test/wp-json/ran-booster/webhook',
			'configured',
			'2026-07-23T16:00:00Z',
			'2026-07-23T16:00:00Z'
		);

		self::assertSame(
			array(
				'schema_version'              => 4,
				'provider_code'               => 'gh',
				'repository_id'               => '1234',
				'repository'                  => 'owner/repository',
				'hook_id'                     => '00099',
				'management_credential_id'    => 'credential_1',
				'webhook_profile_id'          => 'profile_1',
				'webhook_profile_scope'       => 'owner',
				'webhook_profile_revision'    => 1,
				'webhook_profile_disposition' => 'reused',
				'endpoint'                    => 'https://example.test/wp-json/ran-booster/webhook',
				'status'                      => 'configured',
				'created_at'                  => '2026-07-23T16:00:00Z',
				'checked_at'                  => '2026-07-23T16:00:00Z',
			),
			$record->to_array()
		);

		$checked = $record->with_check( 'configuration_drift', '2026-07-23T17:00:00Z' );
		self::assertSame( 'configuration_drift', $checked->status() );
		self::assertSame( '2026-07-23T17:00:00Z', $checked->checked_at() );
		self::assertSame( '2026-07-23T16:00:00Z', $checked->to_array()['created_at'] );
		self::assertSame( 'credential_1', $checked->management_credential_id() );
		self::assertArrayNotHasKey( 'management_credential_label', $checked->to_array() );
		self::assertArrayNotHasKey( 'management_credential_material', $checked->to_array() );
	}

	public function test_it_rejects_unexpected_persisted_fields(): void {
		$record = array(
			'checked_at'                  => '2026-07-23T16:00:00Z',
			'status'                      => 'configured',
			'endpoint'                    => 'https://example.test/wp-json/ran-booster/webhook',
			'webhook_profile_id'          => 'profile_1',
			'webhook_profile_scope'       => 'owner',
			'webhook_profile_revision'    => 1,
			'webhook_profile_disposition' => 'reused',
			'management_credential_id'    => 'credential_1',
			'hook_id'                     => '99',
			'repository'                  => 'owner/repository',
			'repository_id'               => '1234',
			'provider_code'               => 'gh',
			'schema_version'              => 4,
			'created_at'                  => '2026-07-23T16:00:00Z',
			'token'                       => 'must-not-persist',
		);

		$this->expectException( \InvalidArgumentException::class );

		InstallationRecord::from_array( $record );
	}

	public function test_unknown_hook_recovery_uses_the_non_secret_v4_shape(): void {
		$record = new InstallationRecord(
			'gh',
			'1234',
			'owner/repository',
			InstallationRecord::unknown_hook_id(),
			'credential_1',
			'wh_0123456789abcdef01234567',
			'repository',
			1,
			'created',
			'https://example.test/wp-json/ran-booster/webhook',
			'orphaned',
			'2026-08-03T08:00:00Z',
			'2026-08-03T08:00:00Z'
		);

		$restored = InstallationRecord::from_array( $record->to_array() );

		self::assertSame( 4, $restored->to_array()['schema_version'] );
		self::assertTrue( $restored->requires_hook_identification() );
		self::assertSame( 'credential_1', $restored->management_credential_id() );
		self::assertSame( 'wh_0123456789abcdef01234567', $restored->webhook_profile_id() );
		self::assertArrayNotHasKey( 'management_credential_label', $restored->to_array() );
		self::assertArrayNotHasKey( 'management_credential_material', $restored->to_array() );
	}

	public function test_it_rejects_prior_schema_versions_without_migrating_them(): void {
		$record                   = ( new InstallationRecord(
			'gh',
			'1234',
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
		) )->to_array();
		$record['schema_version'] = 3;

		$this->expectException( \InvalidArgumentException::class );

		InstallationRecord::from_array( $record );
	}

	public function test_it_rejects_unsupported_scopes_and_non_positive_revisions(): void {
		foreach ( array( array( 'global', 1 ), array( 'repository', 0 ) ) as [ $scope, $revision ] ) {
			try {
				new InstallationRecord(
					'gh',
					'1234',
					'owner/repository',
					'99',
					'credential_1',
					'profile_1',
					$scope,
					$revision,
					'reused',
					'https://example.test/wp-json/ran-booster/webhook',
					'configured',
					'2026-07-23T16:00:00Z',
					'2026-07-23T16:00:00Z'
				);
				self::fail( 'Invalid profile metadata was accepted.' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_it_rejects_provider_codes_core_cannot_publish(): void {
		$this->expectException( \InvalidArgumentException::class );

		new InstallationRecord(
			'1invalid',
			'1234',
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
	}
}

<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;

final class InstallationRecordTest extends TestCase {
	public function testItPersistsOnlyNonSecretMetadata(): void {
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
			$record->toArray()
		);

		$checked = $record->withCheck( 'configuration_drift', '2026-07-23T17:00:00Z' );
		self::assertSame( 'configuration_drift', $checked->status() );
		self::assertSame( '2026-07-23T17:00:00Z', $checked->checkedAt() );
		self::assertSame( '2026-07-23T16:00:00Z', $checked->toArray()['created_at'] );
		self::assertSame( 'credential_1', $checked->managementCredentialId() );
		self::assertArrayNotHasKey( 'management_credential_label', $checked->toArray() );
		self::assertArrayNotHasKey( 'management_credential_material', $checked->toArray() );
	}

	public function testItRejectsUnexpectedPersistedFields(): void {
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

		InstallationRecord::fromArray( $record );
	}

	public function testUnknownHookRecoveryUsesTheNonSecretV4Shape(): void {
		$record = new InstallationRecord(
			'gh',
			'1234',
			'owner/repository',
			InstallationRecord::unknownHookId(),
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

		$restored = InstallationRecord::fromArray( $record->toArray() );

		self::assertSame( 4, $restored->toArray()['schema_version'] );
		self::assertTrue( $restored->requiresHookIdentification() );
		self::assertSame( 'credential_1', $restored->managementCredentialId() );
		self::assertSame( 'wh_0123456789abcdef01234567', $restored->webhookProfileId() );
		self::assertArrayNotHasKey( 'management_credential_label', $restored->toArray() );
		self::assertArrayNotHasKey( 'management_credential_material', $restored->toArray() );
	}

	public function testItRejectsPriorSchemaVersionsWithoutMigratingThem(): void {
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
		) )->toArray();
		$record['schema_version'] = 3;

		$this->expectException( \InvalidArgumentException::class );

		InstallationRecord::fromArray( $record );
	}

	public function testItRejectsUnsupportedScopesAndNonPositiveRevisions(): void {
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
				self::assertTrue( true );
			}
		}
	}

	public function testItRejectsProviderCodesCoreCannotPublish(): void {
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

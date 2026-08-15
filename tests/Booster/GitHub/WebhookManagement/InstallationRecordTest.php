<?php

declare( strict_types = 1 );

namespace Tests\Booster\GitHub\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationRecord;

final class InstallationRecordTest extends TestCase {
	public function testItPersistsOnlyNonSecretMetadata(): void {
		$record = new InstallationRecord(
			'gh',
			'1234',
			'owner/repository',
			'00099',
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
				'schema_version'              => 3,
				'provider_code'               => 'gh',
				'repository_id'               => '1234',
				'repository'                  => 'owner/repository',
				'hook_id'                     => '00099',
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
			'hook_id'                     => '99',
			'repository'                  => 'owner/repository',
			'repository_id'               => '1234',
			'provider_code'               => 'gh',
			'schema_version'              => 3,
			'created_at'                  => '2026-07-23T16:00:00Z',
			'token'                       => 'must-not-persist',
		);

		$this->expectException( \InvalidArgumentException::class );

		InstallationRecord::fromArray( $record );
	}

	public function testUnknownHookRecoveryUsesTheExistingNonSecretV3Shape(): void {
		$record = new InstallationRecord(
			'gh',
			'1234',
			'owner/repository',
			InstallationRecord::unknownHookId(),
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

		self::assertSame( 3, $restored->toArray()['schema_version'] );
		self::assertTrue( $restored->requiresHookIdentification() );
		self::assertSame( 'wh_0123456789abcdef01234567', $restored->webhookProfileId() );
		self::assertArrayNotHasKey( 'credential', $restored->toArray() );
	}

	public function testItRejectsSchemaVersionTwoWithoutMigratingIt(): void {
		$record                   = ( new InstallationRecord(
			'gh',
			'1234',
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
		) )->toArray();
		$record['schema_version'] = 2;

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

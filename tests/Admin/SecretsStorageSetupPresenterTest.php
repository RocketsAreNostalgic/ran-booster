<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Secrets\SecretsStorageProvisioningResult;

final class SecretsStorageSetupPresenterTest extends TestCase {

	public function testBuildsExactOwnerOnlyManualFallbackForTheProtectedOverview(): void {
		$candidate = "/srv/private site/.ran-booster/0123456789abcdef/secrets'file.json";
		$root      = (string) realpath( dirname( __DIR__, 2 ) );
		$payload   = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::setupAvailable( $candidate ),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
			$root
		);

		self::assertTrue( $payload['can_provision'] );
		self::assertSame( $candidate, $payload['candidate_path'] );
		self::assertSame(
			array(
				"test ! -L '/srv/private site/.ran-booster' && test ! -L '/srv/private site/.ran-booster/0123456789abcdef' && install -d -m 700 -- '/srv/private site/.ran-booster' '/srv/private site/.ran-booster/0123456789abcdef'",
			),
			$payload['directory_commands']
		);
		self::assertSame(
			array(
				'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/srv/private site/.ran-booster/0123456789abcdef' );",
				'wp_cli' => 'wp --path=' . escapeshellarg( $root )
					. " config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR '/srv/private site/.ran-booster/0123456789abcdef' --type=constant",
			),
			$payload['config_alternatives']
		);
		self::assertStringContainsString( 'not a symbolic link', (string) $payload['manual_preflight'] );
	}

	public function testUnsupportedStatusContainsNoPathOrManualCommand(): void {
		$payload = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::unsupported(
				'sodium_unavailable',
				'The Sodium extension is required.'
			),
			'/admin'
		);

		self::assertFalse( $payload['can_provision'] );
		self::assertSame( 'sodium_unavailable', $payload['reason_code'] );
		self::assertNull( $payload['candidate_path'] );
		self::assertSame( array(), $payload['directory_commands'] );
		self::assertNull( $payload['config_alternatives'] );
	}

	public function testConfiguredStatusesKeepTheProtectedPathWithoutOfferingSetupCommands(): void {
		$results = array(
			SecretsStorageProvisioningResult::pathConfigured(
				'/private/canary/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			),
			SecretsStorageProvisioningResult::storageHealthy(
				'/private/canary/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL
			),
			SecretsStorageProvisioningResult::storageNeedsAttention(
				'/private/canary/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL
			),
		);

		foreach ( $results as $result ) {
			$payload = ( new SecretsStorageSetupPresenter() )->build(
				$result,
				'/admin'
			);

			self::assertSame( '/private/canary/secrets.json', $payload['candidate_path'] );
			self::assertSame( $result->pathSource(), $payload['path_source'] );
			self::assertFalse( $payload['can_provision'] );
			self::assertNull( $payload['manual_preflight'] );
			self::assertSame( array(), $payload['directory_commands'] );
			self::assertNull( $payload['config_alternatives'] );
		}
	}

	public function testSensitiveSetupDetailsAreRedactedForAUserWithoutBothCapabilities(): void {
		$payload = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::setupAvailable( '/private/canary/secrets.json' ),
			'/admin',
			(string) realpath( dirname( __DIR__, 2 ) ),
			false
		);

		self::assertFalse( $payload['can_provision'] );
		self::assertSame( 'setup_available', $payload['reason_code'] );
		self::assertNull( $payload['candidate_path'] );
		self::assertNull( $payload['path_source'] );
		self::assertNull( $payload['manual_preflight'] );
		self::assertSame( array(), $payload['directory_commands'] );
		self::assertNull( $payload['config_alternatives'] );
	}
}

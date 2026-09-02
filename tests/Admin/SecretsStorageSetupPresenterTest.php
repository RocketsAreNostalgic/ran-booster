<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/CredentialExpiryWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Secrets\SecretsStorageProvisioner;

final class SecretsStorageSetupPresenterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'], $GLOBALS['ran_booster_repository_admin_translations'] );

		parent::tearDown();
	}

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
		self::assertSame( '/srv/private site/.ran-booster/0123456789abcdef', $payload['candidate_directory'] );
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

	public function testBuildsTranslatedManualPreflightWithoutChangingCommandsOrPaths(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']       = array(
			'Before running these commands, verify every existing path component is a real directory owned by the WordPress account and is not a symbolic link.' => 'Translated preflight guidance.',
		);
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster'] = $GLOBALS['ran_booster_admin_test_translations']['ran-booster'];

		$payload = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::setupAvailable( '/private/.ran-booster/0123456789abcdef/secrets.json' ),
			'/admin'
		);

		self::assertSame( 'Translated preflight guidance.', $payload['manual_preflight'] );
		self::assertSame( '/private/.ran-booster/0123456789abcdef/secrets.json', $payload['candidate_path'] );
		self::assertSame( 1, count( $payload['directory_commands'] ) );
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
		self::assertNull( $payload['candidate_directory'] );
		self::assertSame( array(), $payload['discarded_candidates'] );
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
			self::assertSame( '/private/canary', $payload['candidate_directory'] );
			self::assertSame( $result->pathSource(), $payload['path_source'] );
			self::assertFalse( $payload['can_provision'] );
			self::assertNull( $payload['manual_preflight'] );
			self::assertSame( array(), $payload['directory_commands'] );
			self::assertNull( $payload['config_alternatives'] );
		}
	}

	public function testSensitiveSetupDetailsAreRedactedForAUserWithoutBothCapabilities(): void {
		$payload = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				'No safe location.',
				null,
				array(
					array(
						'directory' => '/private/canary',
						'code'      => 'fixture_rejection',
						'reason'    => 'Fixture path rejected.',
						'component' => '/private',
					),
				)
			),
			'/admin',
			(string) realpath( dirname( __DIR__, 2 ) ),
			false
		);

		self::assertFalse( $payload['can_provision'] );
		self::assertSame( 'location_unavailable', $payload['reason_code'] );
		self::assertNull( $payload['candidate_path'] );
		self::assertNull( $payload['candidate_directory'] );
		self::assertNull( $payload['path_source'] );
		self::assertSame( array(), $payload['discarded_candidates'] );
		self::assertNull( $payload['manual_preflight'] );
		self::assertSame( array(), $payload['directory_commands'] );
		self::assertNull( $payload['config_alternatives'] );
		self::assertNull( $payload['recovery'] );
	}

	public function testPrivilegedOverviewReceivesDiscardedCandidateReasons(): void {
		$discarded = array(
			array(
				'directory' => '/var/www/account/.ran-booster/0123456789abcdef',
				'code'      => 'php_accessible_group_writable_ancestor',
				'reason'    => 'A PHP-accessible group-writable ancestor can replace the account path.',
				'component' => '/var/www',
			),
		);
		$payload   = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::manualRequired(
				'location_unavailable',
				'No safe location.',
				null,
				$discarded
			),
			'/admin'
		);

		self::assertSame( $discarded, $payload['discarded_candidates'] );
	}

	public function testBuildsAnAdoptionOfferOnlyForAnAvailableRecoveryState(): void {
		$recoveryPath = '/private/.ran-booster/abcdef0123456789/secrets.json';
		$payload      = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::storageNeedsAttention(
				'/private/.ran-booster/0123456789abcdef/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			),
			'/admin',
			'',
			true,
			array(
				'state'          => 'available',
				'message'        => 'Authenticated prior storage found.',
				'candidate_path' => $recoveryPath,
				'token'          => str_repeat( 'a', 64 ),
			)
		);

		self::assertTrue( $payload['recovery']['can_adopt'] );
		self::assertFalse( $payload['recovery']['can_reset'] );
		self::assertSame( $recoveryPath, $payload['recovery']['candidate_path'] );
		self::assertSame( dirname( $recoveryPath ), $payload['recovery']['candidate_directory'] );

		$blocked = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::storageNeedsAttention(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL
			),
			'/admin',
			'',
			true,
			array(
				'state'          => 'blocked',
				'message'        => 'Unsafe prior storage found.',
				'candidate_path' => null,
				'token'          => null,
			)
		);
		self::assertFalse( $blocked['recovery']['can_adopt'] );
		self::assertFalse( $blocked['recovery']['can_reset'] );
		self::assertNull( $blocked['recovery']['candidate_path'] );
	}

	public function testBuildsTheSameExplicitResetOfferForEitherIncompleteStorageHalf(): void {
		foreach ( array( 'storage_file_missing', 'storage_key_missing' ) as $reason ) {
			$payload = ( new SecretsStorageSetupPresenter() )->build(
				SecretsStorageProvisioningResult::storageNeedsAttention(
					'/private/current/secrets.json',
					SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC,
					$reason
				),
				'/admin',
				'',
				true,
				array(
					'state'          => 'reset_available',
					'message'        => 'One storage half is missing.',
					'candidate_path' => null,
					'token'          => null,
					'confirmation'   => SecretsStorageProvisioner::RESET_CONFIRMATION,
				)
			);

			self::assertTrue( $payload['recovery']['can_reset'] );
			self::assertFalse( $payload['recovery']['can_adopt'] );
			self::assertSame( SecretsStorageProvisioner::RESET_CONFIRMATION, $payload['recovery']['reset_confirmation'] );
		}

		$redacted = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::storageNeedsAttention(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			),
			'/admin',
			'',
			false,
			array(
				'state'        => 'reset_available',
				'message'      => 'An orphaned key was found.',
				'confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			)
		);
		self::assertNull( $redacted['recovery'] );
	}
}

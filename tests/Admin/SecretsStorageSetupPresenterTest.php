<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/CredentialExpiryWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Secrets/SecretsStorageWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\SecretsStorageSetupPresenter;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Secrets\SecretsStorageProvisioner;

final class SecretsStorageSetupPresenterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
		$GLOBALS['ran_booster_secrets_test_translations']     = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'], $GLOBALS['ran_booster_repository_admin_translations'], $GLOBALS['ran_booster_secrets_test_translations'] );

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

	public function testPresentsTranslatedStorageResultWithoutChangingItsProtectedCodeOrPath(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			'Booster can create secure encrypted secrets storage.' => 'Stockage sécurisé disponible.',
		);
		$candidate = '/private/canary/secrets.json';
		$payload   = ( new SecretsStorageSetupPresenter() )->build(
			SecretsStorageProvisioningResult::setupAvailable( $candidate ),
			'/admin'
		);

		self::assertSame( 'Stockage sécurisé disponible.', $payload['message'] );
		self::assertSame( 'setup_available', $payload['reason_code'] );
		self::assertSame( $candidate, $payload['candidate_path'] );
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

	#[DataProvider( 'discardedCandidateReasonCases' )]
	public function testPrivilegedOverviewLocalizesKnownDiscardedCandidateReasonsWithoutChangingDiagnostics(
		string $code,
		?string $sourceMessage,
		string $expectedReason
	): void {
		if ( null !== $sourceMessage ) {
			$GLOBALS['ran_booster_admin_test_translations']['ran-booster'][ $sourceMessage ]       = $expectedReason;
			$GLOBALS['ran_booster_repository_admin_translations']['ran-booster'][ $sourceMessage ] = $expectedReason;
		}
		$discarded = array(
			array(
				'directory' => '/var/www/account/.ran-booster/0123456789abcdef',
				'code'      => $code,
				'reason'    => 'Original resolver reason.',
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

		self::assertSame(
			array(
				array(
					'directory' => '/var/www/account/.ran-booster/0123456789abcdef',
					'code'      => $code,
					'reason'    => $expectedReason,
					'component' => '/var/www',
				),
			),
			$payload['discarded_candidates']
		);
	}

	/** @return iterable<string, array{string, string|null, string}> */
	public static function discardedCandidateReasonCases(): iterable {
		$reasons = array(
			'invalid_candidate_path'                 => 'The candidate is not a valid absolute secrets.json path.',
			'temporary_storage'                      => 'The candidate is inside the operating system temporary directory.',
			'inside_unsafe_boundary'                 => 'The candidate is inside a public web or version-control directory.',
			'private_anchor_unavailable'             => 'The private account directory is missing, is not a directory or is a symbolic link.',
			'symlink_or_unreadable_component'        => 'A path component is a symbolic link or could not be inspected.',
			'storage_file_not_regular'               => 'The existing storage target is not a regular file.',
			'storage_file_hard_linked'               => 'The existing storage target has more than one hard link.',
			'path_component_not_directory'           => 'A path component is not a directory.',
			'world_writable_host_ancestor'           => 'A host directory is writable by every local user, so the private account path could be replaced.',
			'php_accessible_group_writable_ancestor' => 'A group-writable host directory is owned by, writable by or grouped with the PHP process, so the private account path could be replaced.',
			'broad_private_path_permissions'         => 'A private path component is writable by its group or by other users.',
			'private_anchor_not_owned'               => 'The private account directory is not writable and owned by the PHP process user.',
		);
		foreach ( $reasons as $code => $reason ) {
			yield $code => array( $code, $reason, 'Translated reason: ' . $code );
		}
		yield 'unknown code' => array( 'future_reason', null, 'Original resolver reason.' );
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

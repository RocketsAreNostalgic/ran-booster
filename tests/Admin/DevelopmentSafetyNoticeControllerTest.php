<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\DevelopmentSafetyNoticeController;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

final class DevelopmentSafetyNoticeControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed']               = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_id']               = 17;
		$GLOBALS['ran_booster_repository_admin_user_meta']             = array();
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = false;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_user_id'],
			$GLOBALS['ran_booster_repository_admin_user_meta'],
			$GLOBALS['ran_booster_repository_admin_user_meta_write_fails']
		);
	}

	public function testDismissalIsPersistedForTheCurrentAdministrator(): void {
		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['data']['dismissed'] );
		self::assertSame(
			'1',
			$GLOBALS['ran_booster_repository_admin_user_meta'][17][ DevelopmentSafetyNoticeController::USER_META_KEY ]
		);
	}

	public function testUnauthorizedRequestCannotPersistDismissal(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_user_meta'] );
	}

	public function testInvalidNonceCannotPersistDismissal(): void {
		$GLOBALS['ran_booster_repository_admin_nonce_valid'] = false;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_user_meta'] );
	}

	public function testPersistenceFailureIsReported(): void {
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = true;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 500, $result['status'] );
	}
}

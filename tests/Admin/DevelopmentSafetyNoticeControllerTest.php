<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\DevelopmentSafetyNoticeController;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

final class DevelopmentSafetyNoticeControllerTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST = array( 'state' => DevelopmentSafetyNoticeController::NOTICE_STATE );
		$GLOBALS['ran_booster_repository_admin_allowed']               = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_id']               = 17;
		$GLOBALS['ran_booster_repository_admin_user_meta']             = array();
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = false;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_user_id'],
			$GLOBALS['ran_booster_repository_admin_user_meta'],
			$GLOBALS['ran_booster_repository_admin_user_meta_write_fails']
		);
	}

	public function test_dismissal_is_persisted_for_the_current_administrator(): void {
		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['data']['dismissed'] );
		self::assertSame(
			DevelopmentSafetyNoticeController::NOTICE_STATE,
			$GLOBALS['ran_booster_repository_admin_user_meta'][17][ DevelopmentSafetyNoticeController::USER_META_KEY ]
		);
	}

	public function test_unauthorized_request_cannot_persist_dismissal(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_user_meta'] );
	}

	public function test_invalid_nonce_cannot_persist_dismissal(): void {
		$GLOBALS['ran_booster_repository_admin_nonce_valid'] = false;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_user_meta'] );
	}

	public function test_missing_or_stale_state_cannot_acknowledge_current_notice(): void {
		foreach ( array( '', '1', 'local-development-v1' ) as $state ) {
			$_POST['state'] = $state;
			$result         = ( new DevelopmentSafetyNoticeController() )->handle();
			self::assertFalse( $result['success'] );
			self::assertSame( 403, $result['status'] );
			self::assertSame( array(), $GLOBALS['ran_booster_repository_admin_user_meta'] );
		}
	}

	public function test_persistence_failure_is_reported(): void {
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = true;

		$result = ( new DevelopmentSafetyNoticeController() )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 500, $result['status'] );
	}
}

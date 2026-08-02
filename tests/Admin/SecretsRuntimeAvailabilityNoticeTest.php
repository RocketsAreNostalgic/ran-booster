<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\SecretsRuntimeAvailabilityNotice;
use RAN\Secrets\SecretsRuntimeAvailability;

final class SecretsRuntimeAvailabilityNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
	}

	public function testUnsupportedRuntimeRendersOnePersistentSafeScopedWarning(): void {
		$notice = new SecretsRuntimeAvailabilityNotice(
			new SecretsRuntimeAvailability( false, false ),
			'plugins-network'
		);

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-secrets-runtime-notice' ) );
		self::assertStringContainsString( 'PHP Sodium extension is missing', $html );
		self::assertStringContainsString( 'Public repositories and package-only Transporter Blueprints remain available', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
		self::assertStringNotContainsString( '/srv/', $html );
	}

	public function testAvailableUnauthorizedAndUnrelatedScreensRenderNothing(): void {
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( true, false ),
				'plugins'
			) )->shouldRender()
		);
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( false, false ),
				'dashboard'
			) )->shouldRender()
		);

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( false, false ),
				'plugins'
			) )->shouldRender()
		);
	}

	public function testMultisiteMessageIsSafeAndSpecific(): void {
		$availability = new SecretsRuntimeAvailability( true, true );

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 'multisite_unsupported', $availability->code() );
		self::assertStringContainsString( 'single-site WordPress only', $availability->message() );
	}
}

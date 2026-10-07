<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\SecretsRuntimeAvailabilityNotice;
use RAN\Secrets\SecretsRuntimeAvailability;

final class SecretsRuntimeAvailabilityNoticeTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
	}

	public function test_unsupported_runtime_renders_one_persistent_safe_scoped_warning(): void {
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

	public function test_available_unauthorized_and_unrelated_screens_render_nothing(): void {
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( true, false ),
				'plugins'
			) )->should_render()
		);
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( false, false ),
				'dashboard'
			) )->should_render()
		);

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		self::assertFalse(
			( new SecretsRuntimeAvailabilityNotice(
				new SecretsRuntimeAvailability( false, false ),
				'plugins'
			) )->should_render()
		);
	}

	public function test_multisite_message_is_safe_and_specific(): void {
		$availability = new SecretsRuntimeAvailability( true, true );

		self::assertFalse( $availability->is_available() );
		self::assertSame( 'multisite_unsupported', $availability->code() );
		self::assertStringContainsString( 'single-site WordPress only', $availability->message() );
	}
}

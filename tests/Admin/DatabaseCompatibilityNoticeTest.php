<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\DatabaseCompatibilityNotice;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

final class DatabaseCompatibilityNoticeTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
	}

	public function test_unsupported_database_renders_one_persistent_safe_scoped_warning(): void {
		$notice = $this->notice( false, false );

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-database-compatibility-notice' ) );
		self::assertStringContainsString( DatabaseCompatibilityFailure::REQUIREMENT, $html );
		self::assertStringContainsString( 'Existing Booster data was left unchanged', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
	}

	public function test_blocked_schema_renders_the_lifecycle_message_without_database_details(): void {
		$notice = $this->notice( true, false );

		ob_start();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( DatabaseLifecycleFailure::REQUIREMENT, $html );
		self::assertStringContainsString( 'Existing Booster data was left unchanged', $html );
		self::assertStringNotContainsString( 'schema_operation_failed', $html );
	}

	public function test_supported_unauthorized_and_unrelated_screens_render_nothing(): void {
		self::assertFalse( $this->notice( true, true )->should_render() );
		self::assertFalse( $this->notice( false, false, 'dashboard' )->should_render() );

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		self::assertFalse( $this->notice( false, false )->should_render() );
	}

	private function notice( bool $supported, bool $ready, string $screen_id = 'plugins' ): DatabaseCompatibilityNotice {
		$database = $this->createStub( Database::class );
		$database->method( 'is_supported' )->willReturn( $supported );
		$database->method( 'is_ready' )->willReturn( $ready );

		return new DatabaseCompatibilityNotice( $database, $screen_id );
	}
}

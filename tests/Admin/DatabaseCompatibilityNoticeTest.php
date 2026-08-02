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

	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
	}

	public function testUnsupportedDatabaseRendersOnePersistentSafeScopedWarning(): void {
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

	public function testBlockedSchemaRendersTheLifecycleMessageWithoutDatabaseDetails(): void {
		$notice = $this->notice( true, false );

		ob_start();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( DatabaseLifecycleFailure::REQUIREMENT, $html );
		self::assertStringContainsString( 'Existing Booster data was left unchanged', $html );
		self::assertStringNotContainsString( 'schema_operation_failed', $html );
	}

	public function testSupportedUnauthorizedAndUnrelatedScreensRenderNothing(): void {
		self::assertFalse( $this->notice( true, true )->shouldRender() );
		self::assertFalse( $this->notice( false, false, 'dashboard' )->shouldRender() );

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		self::assertFalse( $this->notice( false, false )->shouldRender() );
	}

	private function notice( bool $supported, bool $ready, string $screenId = 'plugins' ): DatabaseCompatibilityNotice {
		$database = $this->createStub( Database::class );
		$database->method( 'isSupported' )->willReturn( $supported );
		$database->method( 'isReady' )->willReturn( $ready );

		return new DatabaseCompatibilityNotice( $database, $screenId );
	}
}

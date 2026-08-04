<?php

declare(strict_types=1);

namespace Tests\Logging;

require_once __DIR__ . '/LoggingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';

// Direct local filesystem operations inspect the bounded capture under test.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Dashboard;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RuntimeException;
use WP_Error;

final class DashboardNoticeLoggingTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;
	private Dashboard $dashboard;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-dashboard-log-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$this->capture = new TemporaryDebugCapture(
			$this->directory . '/secrets.json',
			static fn(): int => strtotime( '2026-07-23T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configureCapture( $this->capture );

		$this->dashboard = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		BoosterLogger::configureCapture( null );

		foreach (
			array(
				$this->directory . '/ran-booster-debug.php',
				$this->directory . '/ran-booster-debug.php.lock',
			) as $path
		) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
	}

	public function testEveryQueuedAdminWarningAndErrorNoticeCreatesASafeLogEvent(): void {
		$this->dashboard->addMessage(
			array(
				'type'    => 'warning',
				'code'    => 'bulk_update_queue',
				'message' => 'One package was skipped.',
			)
		);
		$this->dashboard->addFailureMessage(
			new WP_Error( 'ran_booster_test_failure', 'A safe public failure.' ),
			new RuntimeException( 'secret-canary-token', 73 ),
			array(
				'operation' => 'install-plugin',
				'step'      => 'manual_package_operation',
			)
		);
		$this->dashboard->addMessage(
			array(
				'type'    => 'success',
				'message' => 'No warning.',
			)
		);

		$entries = $this->capture->snapshot()['entries'];

		self::assertCount( 2, $entries );
		self::assertStringContainsString( 'admin warning notice emitted', $entries[0]['line'] );
		self::assertStringContainsString( '"diagnostic_id":"bulk_update_queue"', $entries[0]['line'] );
		self::assertStringContainsString( 'admin error notice emitted', $entries[1]['line'] );
		self::assertStringContainsString( '"diagnostic_id":"ran_booster_test_failure"', $entries[1]['line'] );
		self::assertStringContainsString( '"exception_class":"RuntimeException"', $entries[1]['line'] );
		self::assertStringContainsString( '"exception_code":"73"', $entries[1]['line'] );
		self::assertStringNotContainsString( 'secret-canary-token', $entries[1]['line'] );
	}

	public function testUnexpectedManualOperationFailureIsLoggedBeforeTheRedactedNotice(): void {
		self::assertFalse( $this->dashboard->postPackageOperation( 'install-plugin', array() ) );

		self::assertCount( 1, $this->dashboard->messages );
		self::assertSame( 'error', $this->dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_manual_action_failed', $this->dashboard->messages[0]['code'] );

		$entries = $this->capture->snapshot()['entries'];

		self::assertCount( 1, $entries );
		self::assertStringContainsString( '"diagnostic_id":"ran_booster_manual_action_failed"', $entries[0]['line'] );
		self::assertStringContainsString( '"operation":"install-plugin"', $entries[0]['line'] );
		self::assertStringContainsString( '"exception_class":"LogicException"', $entries[0]['line'] );
		self::assertStringNotContainsString( 'Package operations are not configured.', $entries[0]['line'] );
	}

	public function testStorageAttentionLogsOnlyStablePathlessDiagnosticContext(): void {
		$method = new \ReflectionMethod( Dashboard::class, 'logSecretsStorageDiagnostic' );
		$method->invoke(
			$this->dashboard,
			SecretsStorageProvisioningResult::storageNeedsAttention(
				'/private/path-canary/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL,
				'storage_key_missing',
				'sentinel-storage-message'
			)
		);

		$entries = $this->capture->snapshot()['entries'];
		self::assertCount( 1, $entries );
		self::assertStringContainsString( 'secrets storage diagnostic reported', $entries[0]['line'] );
		self::assertStringContainsString( '"diagnostic_id":"storage_key_missing"', $entries[0]['line'] );
		self::assertStringContainsString( '"event":"secrets_storage_diagnostic"', $entries[0]['line'] );
		self::assertStringContainsString( '"state":"storage_needs_attention"', $entries[0]['line'] );
		self::assertStringNotContainsString( 'path-canary', $entries[0]['line'] );
		self::assertStringNotContainsString( 'sentinel-storage-message', $entries[0]['line'] );
	}
}

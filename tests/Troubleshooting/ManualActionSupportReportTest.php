<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use PHPUnit\Framework\TestCase;
use RAN\Dashboard;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Troubleshooting\InstallationFacts;
use RAN\Troubleshooting\SupportReport;

require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';

final class ManualActionSupportReportTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-manual-report-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O creates only this isolated private capture fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->capture = new TemporaryDebugCapture( $this->directory . '/secrets.json', static fn(): int => 1700000000 );
		BoosterLogger::configure_capture( $this->capture );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		BoosterLogger::configure_capture( null );
		$this->capture->delete();
		$lock = $this->directory . '/ran-booster-debug.php.lock';
		if ( is_file( $lock ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the lock owned by this isolated capture fixture.
			unlink( $lock );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this test's private fixture directory.
		rmdir( $this->directory );
	}

	public function test_dashboard_failure_reaches_capture_and_report_without_exception_prose(): void {
		$this->capture->start();
		$dashboard = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$dashboard->add_failure_message(
			new \WP_Error( 'ran_booster_manual_action_failed', 'The manual action failed.' ),
			new \RuntimeException( 'sentinel-exception-message', 73 ),
			array(
				'operation' => 'install-plugin',
				'step'      => 'manual_package_operation',
			)
		);
		self::assertFalse( $dashboard->post_package_operation( 'install-plugin', array() ) );
		$entries = $this->capture->snapshot()['entries'];
		self::assertCount( 2, $entries );
		self::assertStringContainsString( 'admin error notice emitted', $entries[0]['line'] );
		self::assertStringContainsString( '"exception_code":"73"', $entries[0]['line'] );
		self::assertStringContainsString( '"exception_class":"RuntimeException"', $entries[0]['line'] );
		self::assertStringContainsString( '"diagnostic_id":"ran_booster_manual_action_failed"', $entries[1]['line'] );
		self::assertStringNotContainsString( 'sentinel-exception-message', $entries[0]['line'] );
		$report = $this->report();
		$data   = json_decode( $report, true, 512, JSON_THROW_ON_ERROR )['sections']['logging'];
		self::assertSame( 'Included', $data['state'] );
		self::assertSame( 2, $data['data']['entries_observed'] );
		self::assertSame( 0, $data['data']['omitted_by_sharing_policy'] );
		self::assertSame( array( '73', '0' ), array_column( $data['data']['entries'], 'cause' ) );
		self::assertSame( array( 'Unknown', 'Unknown' ), array_column( $data['data']['entries'], 'attempt' ) );
		self::assertSame( array( 'Unknown', 'Unknown' ), array_column( $data['data']['entries'], 'correlation' ) );
		self::assertSame( array( 'Unknown', 'Unknown' ), array_column( $data['data']['entries'], 'outcome' ) );
		self::assertSame( array( 'admin error notice emitted', 'admin error notice emitted' ), array_column( $data['data']['entries'], 'message' ) );
		foreach ( array( 'sentinel-exception-message', 'RuntimeException', 'LogicException', 'exception_class', 'Package operations are not configured.' ) as $canary ) {
			self::assertStringNotContainsString( $canary, $report );
		}
	}

	public function test_sharing_projection_rejects_injected_context_and_malformed_cause_codes(): void {
		$this->capture->start();
		$canaries = array( 'sentinel-raw-exception', 'credential-canary', 'ghp_token_canary', 'signature-canary', str_repeat( 'd', 64 ), 'https://user:password@example.test/private', 'arbitrary-context-canary' );
		foreach ( array( '999999', '-1', '1000000', 73, null, array( '73' ), 'sentinel-code-canary' ) as $code ) {
			$context = array(
				'exception_code'    => $code,
				'exception_class'   => $canaries,
				'exception_message' => $canaries[0],
				'credential'        => $canaries[1],
				'token'             => $canaries[2],
				'signature'         => $canaries[3],
				'digest'            => $canaries[4],
				'url'               => $canaries[5],
				'context'           => $canaries[6],
				'attempt_id'        => 17,
				'correlation_id'    => str_repeat( 'a', 32 ),
				'outcome_code'      => 'diagnostics.failed',
			);
			self::assertTrue( $this->capture->append( '[ran-booster] admin error notice emitted ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) ) );
		}
		self::assertTrue( $this->capture->append( '[ran-booster] unrelated unrecognized message' ) );
		$entries = $this->capture->snapshot()['entries'];
		self::assertCount( 8, $entries );
		foreach ( $canaries as $canary ) {
			self::assertStringContainsString( $canary, $entries[0]['line'] );
		}
		$report = $this->report();
		$data   = json_decode( $report, true, 512, JSON_THROW_ON_ERROR )['sections']['logging'];
		self::assertSame( 'Truncated', $data['state'] );
		self::assertSame( 1, $data['data']['omitted_by_sharing_policy'] );
		self::assertCount( 7, $data['data']['entries'] );
		self::assertSame( array( '999999', 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown' ), array_column( $data['data']['entries'], 'cause' ) );
		self::assertSame( '17', $data['data']['entries'][0]['attempt'] );
		self::assertSame( str_repeat( 'a', 32 ), $data['data']['entries'][0]['correlation'] );
		self::assertSame( 'diagnostics.failed', $data['data']['entries'][0]['outcome'] );
		self::assertSame( array( 'timestamp', 'message', 'attempt', 'correlation', 'outcome', 'cause' ), array_keys( $data['data']['entries'][0] ) );
		foreach ( array_merge( $canaries, array( 'sentinel-code-canary', 'exception_class', 'unrelated unrecognized message' ) ) as $canary ) {
			self::assertStringNotContainsString( $canary, $report );
		}
	}

	public function test_logging_and_report_generation_do_not_start_capture(): void {
		BoosterLogger::log_exception( 'admin error notice emitted', new \RuntimeException( 'inactive-exception-canary', 73 ) );
		$report = $this->report();
		self::assertSame( 'Not captured', json_decode( $report, true, 512, JSON_THROW_ON_ERROR )['sections']['logging']['state'] );
		self::assertSame( 'inactive', $this->capture->snapshot()['state'] );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );
		self::assertFileDoesNotExist( $this->directory . '/ran-booster-debug.php' );
		self::assertFileDoesNotExist( $this->directory . '/ran-booster-debug.php.lock' );
		self::assertStringNotContainsString( 'inactive-exception-canary', $report );
	}

	private function report(): string {
		$report = new SupportReport( new InstallationFacts( new ProviderRegistry() ), capture: $this->capture, clock: static fn(): float => 1700000000.0 );
		return $report->generate( array() )['text'];
	}
}

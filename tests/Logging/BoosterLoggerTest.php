<?php

declare(strict_types=1);

namespace Tests\Logging;

// Direct local filesystem operations inspect the bounded capture under test.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RuntimeException;

require_once __DIR__ . '/LoggingWordPressFunctions.php';

#[CoversClass( BoosterLogger::class )]
final class BoosterLoggerTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-logger-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$this->capture = new TemporaryDebugCapture(
			$this->directory . '/secrets.json',
			static fn(): int => strtotime( '2026-07-23T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configure_capture( $this->capture );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		BoosterLogger::configure_capture( null );

		foreach (
			array(
				$this->directory . '/ran-booster-debug.php',
				$this->directory . '/ran-booster-debug.php.lock',
				$this->directory . '/wordpress-debug.log',
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

	public function test_capture_works_without_word_press_debug_logging_and_uses_only_safe_one_line_context(): void {
		self::assertFalse( defined( 'WP_DEBUG_LOG' ) );

		$logged = BoosterLogger::log(
			"deployment\nstarted",
			array(
				'attempt_id' => "attempt-\r\n1",
				'step'       => 'download',
				'token'      => 'sentinel-secret-token',
				'headers'    => array( 'Authorization' => 'sentinel-authorization' ),
			)
		);
		self::assertTrue( $logged );

		$entries = $this->capture->snapshot()['entries'];
		self::assertCount( 1, $entries );
		self::assertSame(
			'[ran-booster] deployment started {"attempt_id":"attempt- 1","step":"download"}',
			$entries[0]['line']
		);
		self::assertStringNotContainsString( 'sentinel-secret-token', $entries[0]['line'] );
		self::assertStringNotContainsString( 'sentinel-authorization', $entries[0]['line'] );
		self::assertStringNotContainsString( "\n", $entries[0]['line'] );
	}

	public function test_exception_messages_and_traces_never_enter_the_capture(): void {
		$exception = new RuntimeException( 'sentinel-raw-exception-secret', 73 );

		BoosterLogger::log_exception(
			'deployment failed',
			$exception,
			array(
				'correlation_id' => str_repeat( 'a', 32 ),
				'diagnostic_id'  => 'ran_booster_manual_action_failed',
				'reference'      => 'ignored-generic-reference',
				'step'           => 'deploy',
				'source'         => "manual\nadmin",
			)
		);

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertSame(
			'[ran-booster] deployment failed {"correlation_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","diagnostic_id":"ran_booster_manual_action_failed","step":"deploy","source":"manual admin","exception_class":"RuntimeException","exception_code":"73"}',
			$line
		);
		self::assertStringNotContainsString( 'sentinel-raw-exception-secret', $line );
		self::assertStringNotContainsString( 'ignored-generic-reference', $line );
		self::assertStringNotContainsString( __FILE__, $line );
	}

	public function test_inactive_or_broken_capture_does_not_affect_logging_callers(): void {
		$this->capture->stop();
		self::assertFalse( BoosterLogger::log( 'after stop', array( 'step' => 'ignored' ) ) );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );

		chmod( $this->directory . '/ran-booster-debug.php', 0644 );
		self::assertFalse( BoosterLogger::log( 'unsafe file', array( 'step' => 'ignored' ) ) );
		self::assertSame( 'malformed', $this->capture->snapshot()['state'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_word_press_debug_logging_remains_enabled_alongside_capture(): void {
		$wordpress_log = $this->directory . '/wordpress-debug.log';
		define( 'WP_DEBUG_LOG', true );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Separate-process test redirects PHP's normal error-log destination to a disposable fixture.
		self::assertNotFalse( ini_set( 'error_log', $wordpress_log ) );

		self::assertTrue( BoosterLogger::log( 'dual destination', array( 'step' => 'logging' ) ) );

		$expected = '[ran-booster] dual destination {"step":"logging"}';
		self::assertStringContainsString( $expected, (string) file_get_contents( $wordpress_log ) );
		self::assertSame( $expected, $this->capture->snapshot()['entries'][0]['line'] );
	}
}

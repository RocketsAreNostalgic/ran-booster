<?php

declare(strict_types=1);

namespace Tests\AddOn\Logging;

// Direct local filesystem operations inspect the bounded capture under test.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\AddOn\Logging\CoreLoggingFacade;
use Tests\Support\NullLoggingFacade;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/Logging/LoggingWordPressFunctions.php';

final class LoggingFacadeTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-add-on-logging-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable test capture directory.
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$this->capture = new TemporaryDebugCapture( $this->directory . '/secrets.json' );
		$this->capture->start();
		BoosterLogger::configureCapture( $this->capture );
	}

	protected function tearDown(): void {
		BoosterLogger::configureCapture( null );

		$paths = glob( $this->directory . '/*' );
		foreach ( false === $paths ? array() : $paths as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable test capture file.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable test capture directory.
			rmdir( $this->directory );
		}
	}

	public function testCoreFacadeDelegatesThroughTheSafeCoreLogger(): void {
		( new CoreLoggingFacade() )->log(
			'add-on failure',
			array(
				'operation'       => 'diagnostics',
				'outcome_code'    => 'remote_unavailable',
				'arbitrary'       => 'should-not-emit',
				'credential'      => 'credential-secret',
				'api_token'       => 'secret-token',
				'webhook_payload' => array( 'secret' => 'raw-payload' ),
			)
		);

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertStringContainsString( '"operation":"diagnostics"', $line );
		self::assertStringContainsString( '"outcome_code":"remote_unavailable"', $line );
		self::assertStringNotContainsString( 'should-not-emit', $line );
		self::assertStringNotContainsString( 'credential-secret', $line );
		self::assertStringNotContainsString( 'secret-token', $line );
		self::assertStringNotContainsString( 'raw-payload', $line );
	}

	public function testExceptionLoggingKeepsTheMessageButNotTheExceptionSecret(): void {
		( new CoreLoggingFacade() )->logException(
			'add-on exception',
			new RuntimeException( 'exception-secret', 17 ),
			array( 'step' => 'workflow' )
		);

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertStringContainsString( '"exception_class":"RuntimeException"', $line );
		self::assertStringContainsString( '"exception_code":"17"', $line );
		self::assertStringNotContainsString( 'exception-secret', $line );
	}

	public function testNoOpFacadeDoesNotEmitAnything(): void {
		( new NullLoggingFacade() )->log( 'ignored', array( 'step' => 'test' ) );
		( new NullLoggingFacade() )->logException( 'ignored', new RuntimeException(), array( 'step' => 'test' ) );

		self::assertSame( array(), $this->capture->snapshot()['entries'] );
	}
}

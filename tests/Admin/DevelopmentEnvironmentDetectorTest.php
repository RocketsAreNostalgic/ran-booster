<?php

declare(strict_types=1);

namespace RAN\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DevelopmentEnvironmentDetectorWordPressFunctions.php';

final class DevelopmentEnvironmentDetectorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_development_detector_environment_type'] = 'production';
		$GLOBALS['ran_booster_development_detector_modes']            = array();
		$GLOBALS['ran_booster_admin_test_home_url']                   = 'https://example.com';
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_development_detector_environment_type'],
			$GLOBALS['ran_booster_development_detector_modes'],
			$GLOBALS['ran_booster_admin_test_home_url']
		);
	}

	/** @return list<array{string, string, list<string>, bool}> */
	public static function environmentProvider(): array {
		return array(
			array( 'https://example.com', 'local', array(), true ),
			array( 'https://example.com', 'development', array(), true ),
			array( 'https://example.com', 'production', array( 'plugin' ), true ),
			array( 'https://example.com', 'production', array( 'theme' ), true ),
			array( 'http://localhost', 'production', array(), true ),
			array( 'http://site.localhost', 'production', array(), true ),
			array( 'http://127.0.0.1', 'production', array(), true ),
			array( 'http://[::1]', 'production', array(), true ),
			array( 'https://example.com:10443', 'production', array(), true ),
			array( 'http://example.com:80', 'production', array(), false ),
			array( 'https://example.com:443', 'production', array(), false ),
			array( 'https://example.com', 'production', array(), false ),
			array( 'https://example.com', 'staging', array(), false ),
		);
	}

	/** @param list<string> $developmentModes */
	#[DataProvider( 'environmentProvider' )]
	public function testDetectsBoundedDevelopmentSignals( string $homeUrl, string $environmentType, array $developmentModes, bool $expected ): void {
		$GLOBALS['ran_booster_admin_test_home_url']                   = $homeUrl;
		$GLOBALS['ran_booster_development_detector_environment_type'] = $environmentType;
		$GLOBALS['ran_booster_development_detector_modes']            = $developmentModes;

		self::assertSame( $expected, DevelopmentEnvironmentDetector::isLikely() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testEnabledWpDebugIsADevelopmentSignal(): void {
		define( 'WP_DEBUG', true );

		self::assertTrue( DevelopmentEnvironmentDetector::isLikely() );
	}
}

<?php

declare(strict_types=1);

namespace Tests\WordPress;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused activation spies belong to this test.

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

final class ActivationRequirementsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	/** @return list<array{bool, bool, string}> */
	public static function unsupportedEnvironmentProvider(): array {
		return array(
			array( false, false, 'requires the PHP Sodium extension' ),
			array( true, true, 'not available on multisite' ),
		);
	}

	#[DataProvider( 'unsupportedEnvironmentProvider' )]
	public function testUnsupportedFreshActivationStopsBeforeDatabaseOrWakeupSideEffects( bool $sodium, bool $multisite, string $message ): void {
		$database = new ActivationRequirementsDatabase();
		$wakeup   = new ActivationRequirementsWakeup();
		$booster  = new ActivationRequirementsBooster( $sodium, $multisite );
		$booster->bind( 'RAN\Storage\Database', $database );
		$booster->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$booster->activate();
			self::fail( 'Unsupported activation must terminate through wp_die().' );
		} catch ( \RuntimeException $failure ) {
			self::assertStringContainsString( $message, $failure->getMessage() );
		}

		self::assertSame( 0, $database->installs );
		self::assertSame( 0, $wakeup->requests );
	}

	/** @return array<string, array{DatabaseCompatibilityFailure|DatabaseLifecycleFailure}> */
	public static function databaseFailureProvider(): array {
		return array(
			'unsupported server' => array( new DatabaseCompatibilityFailure( 'unsupported_version' ) ),
			'blocked lifecycle'  => array( new DatabaseLifecycleFailure( 'schema_operation_failed' ) ),
		);
	}

	#[DataProvider( 'databaseFailureProvider' )]
	public function testDatabaseFailureStopsFreshActivationThroughWpDieBeforeWakeup(
		DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	): void {
		$database = new FailingActivationDatabase( $failure );
		$wakeup   = new ActivationRequirementsWakeup();
		$booster  = new ActivationRequirementsBooster( true, false );
		$booster->bind( Database::class, $database );
		$booster->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$booster->activate();
			self::fail( 'Database activation failure must terminate through wp_die().' );
		} catch ( \RuntimeException $wpDie ) {
			self::assertSame( $failure->getMessage(), $wpDie->getMessage() );
		}

		self::assertSame( 1, $database->installs );
		self::assertSame( 0, $wakeup->requests );
	}

	public function testActiveWpPusherStopsActivationBeforeDatabaseOrWakeupSideEffects(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		$database                                        = new ActivationRequirementsDatabase();
		$wakeup  = new ActivationRequirementsWakeup();
		$booster = new ActivationRequirementsBooster( true, false );
		$booster->bind( 'RAN\Storage\Database', $database );
		$booster->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$booster->activate();
			self::fail( 'Concurrent package authority must stop activation through wp_die().' );
		} catch ( \RuntimeException $failure ) {
			self::assertStringContainsString( 'Deactivate WP Pusher', $failure->getMessage() );
		}

		self::assertSame( 0, $database->installs );
		self::assertSame( 0, $wakeup->requests );
	}
}

final class ActivationRequirementsBooster extends Booster {
	public function __construct(
		private readonly bool $sodium,
		private readonly bool $multisite
	) {
	}

	protected function sodiumAvailable(): bool {
		return $this->sodium;
	}

	protected function isMultisiteInstallation(): bool {
		return $this->multisite;
	}
}

final class ActivationRequirementsDatabase {
	public int $installs = 0;

	public function install(): void {
		++$this->installs;
	}
}

final class FailingActivationDatabase extends Database {
	public int $installs = 0;

	public function __construct( private DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure ) {
	}

	public function install(): void {
		++$this->installs;
		throw $this->failure;
	}
}

final class ActivationRequirementsWakeup {
	public int $requests = 0;

	public function request(): string {
		++$this->requests;

		return 'scheduled';
	}
}

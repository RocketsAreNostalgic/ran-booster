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
use RAN\Internal\CoreContainer;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

final class ActivationRequirementsTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	/** @return list<array{bool, bool, string}> */
	public static function unsupported_environment_provider(): array {
		return array(
			array( false, false, 'requires the PHP Sodium extension' ),
			array( true, true, 'not available on multisite' ),
		);
	}

	#[DataProvider( 'unsupported_environment_provider' )]
	public function test_unsupported_fresh_activation_stops_before_database_or_wakeup_side_effects( bool $sodium, bool $multisite, string $message ): void {
		$database  = new ActivationRequirementsDatabase();
		$wakeup    = new ActivationRequirementsWakeup();
		$container = new CoreContainer();
		$booster   = new ActivationRequirementsBooster( $container, $sodium, $multisite );
		$container->bind( 'RAN\Storage\Database', $database );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

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
	public static function database_failure_provider(): array {
		return array(
			'unsupported server' => array( new DatabaseCompatibilityFailure( 'unsupported_version' ) ),
			'blocked lifecycle'  => array( new DatabaseLifecycleFailure( 'schema_operation_failed' ) ),
		);
	}

	#[DataProvider( 'database_failure_provider' )]
	public function test_database_failure_stops_fresh_activation_through_wp_die_before_wakeup(
		DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	): void {
		$database  = new FailingActivationDatabase( $failure );
		$wakeup    = new ActivationRequirementsWakeup();
		$container = new CoreContainer();
		$booster   = new ActivationRequirementsBooster( $container, true, false );
		$container->bind( Database::class, $database );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$booster->activate();
			self::fail( 'Database activation failure must terminate through wp_die().' );
		} catch ( \RuntimeException $wp_die ) {
			self::assertSame( $failure->getMessage(), $wp_die->getMessage() );
		}

		self::assertSame( 1, $database->installs );
		self::assertSame( 0, $wakeup->requests );
	}

	public function test_active_wp_pusher_stops_activation_before_database_or_wakeup_side_effects(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		$database                                        = new ActivationRequirementsDatabase();
		$wakeup    = new ActivationRequirementsWakeup();
		$container = new CoreContainer();
		$booster   = new ActivationRequirementsBooster( $container, true, false );
		$container->bind( 'RAN\Storage\Database', $database );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

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
		CoreContainer $container,
		private readonly bool $sodium,
		private readonly bool $multisite
	) {
		parent::__construct( $container );
	}

	protected function sodium_available(): bool {
		return $this->sodium;
	}

	protected function is_multisite_installation(): bool {
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

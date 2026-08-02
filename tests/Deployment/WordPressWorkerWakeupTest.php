<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once __DIR__ . '/WordPressWorkerWakeupCron.php';
require_once __DIR__ . '/WordPressWorkerWakeupWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Internal\CoreContainer;
use RAN\Logging\TemporaryDebugCapture;

final class WordPressWorkerWakeupTest extends TestCase {

	private WordPressWorkerWakeupDatabase $database;
	private WordPressWorkerWakeup $wakeup;

	protected function setUp(): void {
		WordPressWorkerWakeupCron::reset();
		$this->database = new WordPressWorkerWakeupDatabase();
		$this->wakeup   = new WordPressWorkerWakeup(
			new DeploymentAttemptRepository( $this->database, 'wp_ran_booster_deployment_attempts' )
		);
	}

	public function testNoQueuedAttemptRequiresNoWakeup(): void {
		self::assertSame( 'not_required', $this->wakeup->request() );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
	}

	public function testQueuedAttemptSchedulesOneArgsFreeSingleEvent(): void {
		$this->database->queuedAt = gmdate( 'Y-m-d H:i:s', time() + 60 );

		self::assertSame( 'scheduled', $this->wakeup->request() );
		self::assertCount( 1, WordPressWorkerWakeupCron::$events );
		self::assertSame( WordPressWorkerWakeup::HOOK, WordPressWorkerWakeupCron::$events[0]->hook );
		self::assertSame( array(), WordPressWorkerWakeupCron::$events[0]->args );
		self::assertFalse( WordPressWorkerWakeupCron::$events[0]->schedule );
	}

	public function testExistingEventIsNotDuplicated(): void {
		$this->database->queuedAt            = gmdate( 'Y-m-d H:i:s', time() + 60 );
		WordPressWorkerWakeupCron::$events[] = $this->event( WordPressWorkerWakeup::HOOK, time() + 30 );

		self::assertSame( 'already_scheduled', $this->wakeup->request() );
		self::assertCount( 1, WordPressWorkerWakeupCron::$events );
	}

	public function testQueueReadOrScheduleFailureIsUnavailable(): void {
		$this->database->readFails = true;
		self::assertSame( 'unavailable', $this->wakeup->request() );

		$this->database->readFails                   = false;
		$this->database->queuedAt                    = gmdate( 'Y-m-d H:i:s', time() + 60 );
		WordPressWorkerWakeupCron::$scheduleSucceeds = false;
		self::assertSame( 'unavailable', $this->wakeup->request() );
	}

	public function testInspectAndClearAffectOnlyTheBoosterHook(): void {
		WordPressWorkerWakeupCron::$events = array(
			$this->event( WordPressWorkerWakeup::HOOK, time() + 30 ),
			$this->event( 'another_plugin_event', time() + 30 ),
		);

		self::assertSame( 'scheduled', $this->wakeup->inspect()['status'] );
		self::assertTrue( $this->wakeup->clear() );
		self::assertCount( 1, WordPressWorkerWakeupCron::$events );
		self::assertSame( 'another_plugin_event', WordPressWorkerWakeupCron::$events[0]->hook );
	}

	public function testActivationInstallsSchemaBeforeRequestingWakeup(): void {
		$wakeup    = new WordPressWorkerWakeupActivationWakeup();
		$schema    = new WordPressWorkerWakeupSchema( $wakeup );
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( 'RAN\Storage\Database', $schema );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

		$booster->activate();
		self::assertSame( 1, $schema->installs );
		self::assertSame( 1, $wakeup->requests );
		$booster->deactivate();
		self::assertSame( 1, $wakeup->clears );
	}

	public function testDeactivationStopsAndRetainsTheTemporaryDebugCapture(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-deactivation-capture-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture   = new TemporaryDebugCapture( $directory . '/secrets.json' );
		$wakeup    = new WordPressWorkerWakeupActivationWakeup();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( TemporaryDebugCapture::class, $capture );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$capture->start();
			self::assertTrue( $capture->append( '[ran-booster] retained during deactivation' ) );

			$booster->deactivate();

			$snapshot = $capture->snapshot();
			self::assertSame( 'retained', $snapshot['state'] );
			self::assertCount( 1, $snapshot['entries'] );
			self::assertSame( 1, $wakeup->clears );
		} finally {
			$capture->delete();
			foreach ( array( $directory . '/ran-booster-debug.php', $directory . '/ran-booster-debug.php.lock' ) as $path ) {
				if ( is_file( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
					unlink( $path );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
			rmdir( $directory );
		}
	}

	public function testActivationFailureUsesSafeActionableWpDieAndDoesNotRequestWakeup(): void {
		$wakeup          = new WordPressWorkerWakeupActivationWakeup();
		$schema          = new WordPressWorkerWakeupSchema( $wakeup );
		$container       = new CoreContainer();
		$booster         = new Booster( $container );
		$schema->failure = new \Error(
			'Database error for token secret-token at /srv/private/wp-content/db.php.'
		);
		$container->bind( 'RAN\Storage\Database', $schema );
		$container->bind( WordPressWorkerWakeup::class, $wakeup );

		try {
			$booster->activate();
			self::fail( 'Activation failure should terminate through wp_die().' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'WordPress can create and update plugin tables', $exception->getMessage() );
			self::assertStringContainsString( 'WordPress left the plugin inactive', $exception->getMessage() );
			self::assertStringNotContainsString( 'secret-token', $exception->getMessage() );
			self::assertStringNotContainsString( '/srv/private', $exception->getMessage() );
		}

		self::assertSame( 1, $schema->installs );
		self::assertSame( 0, $wakeup->requests );
	}

	private function event( string $hook, int $timestamp ): object {
		return (object) array(
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'args'      => array(),
			'schedule'  => false,
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused database stub for the wake-up repository query.
final class WordPressWorkerWakeupDatabase {

	public string $prefix     = 'wp_';
	public string $last_error = '';
	public ?string $queuedAt  = null;
	public bool $readFails    = false;

	public function db_server_info(): string {
		return '8.4.6';
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		return $query;
	}

	/** @return list<object>|null */
	public function get_results( string $query ): ?array {
		if ( 'SHOW ENGINES' === $query ) {
			return array(
				(object) array(
					'Engine'  => 'InnoDB',
					'Support' => 'DEFAULT',
				),
			);
		}
		if ( $this->readFails ) {
			return null;
		}

		return null === $this->queuedAt ? array() : array( (object) array( 'created_at' => $this->queuedAt ) );
	}
}

final class WordPressWorkerWakeupSchema {

	public int $installs        = 0;
	public ?\Throwable $failure = null;

	public function __construct( private WordPressWorkerWakeupActivationWakeup $wakeup ) {}

	public function install(): void {
		++$this->installs;

		if ( 0 !== $this->wakeup->requests ) {
			throw new \LogicException( 'Wakeup requested before schema installation.' );
		}

		if ( null !== $this->failure ) {
			throw $this->failure;
		}
	}
}

final class WordPressWorkerWakeupActivationWakeup {

	public int $requests = 0;
	public int $clears   = 0;

	public function request(): string {
		++$this->requests;

		return 'not_required';
	}

	public function clear(): bool {
		++$this->clears;

		return true;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile

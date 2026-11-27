<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\Dashboard;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;

require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

final class UpdaterLockOwnerNoticeTest extends TestCase {
	private AttemptRepositoryDatabase $database;
	private mixed $previous_database;

	#[Before]
	protected function prepare_database(): void {
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->database          = new AttemptRepositoryDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The updater lock reads its row through this isolated fake.
		$GLOBALS['wpdb'] = $this->database;
		WordPressUpdaterLock::take_refusal();
	}

	#[After]
	protected function restore_database(): void {
		WordPressUpdaterLock::take_refusal();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the database boundary replaced above.
		$GLOBALS['wpdb'] = $this->previous_database;
	}

	public function test_only_a_failure_after_a_refused_lock_names_the_recorded_owner(): void {
		$token     = ( new WordPressUpdaterLock() )->acquire();
		$dashboard = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$owner     = new \ReflectionProperty( Dashboard::class, 'updater_lock_owner' );

		$dashboard->add_failure_message( new \WP_Error( 'ran_booster_test', 'Unrelated failure.' ), new RuntimeException( 'unrelated' ) );
		self::assertNull( $owner->getValue( $dashboard ) );

		$refused = null;
		try {
			( new WordPressUpdaterLock() )->acquire();
		} catch ( RuntimeException $failure ) {
			$refused = $failure;
		}
		self::assertInstanceOf( RuntimeException::class, $refused );
		$dashboard->add_failure_message( new \WP_Error( 'ran_booster_test', 'Another package operation is in progress.' ), $refused );
		self::assertSame(
			array(
				'token'      => $token,
				'operation'  => WordPressUpdaterLock::DEFAULT_OPERATION,
				'started_at' => (int) $token,
			),
			$owner->getValue( $dashboard )
		);
		// The refusal is reported once per refused attempt.
		self::assertFalse( WordPressUpdaterLock::take_refusal() );
		self::assertNull( ( new WordPressUpdaterLock() )->try_acquire_without_reclaim() );
		self::assertTrue( WordPressUpdaterLock::take_refusal() );
	}
}

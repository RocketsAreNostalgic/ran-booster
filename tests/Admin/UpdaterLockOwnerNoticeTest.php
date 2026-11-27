<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/** @return iterable<string, array{\Closure(AttemptRepositoryDatabase): void, bool}> */
	public static function refusals(): iterable {
		yield 'an unrelated failure' => array( static function (): void {}, false );
		yield 'a refused acquire' => array( static fn () => self::refuse( 'acquire' ), true );
		yield 'a refused try' => array( static fn () => self::refuse( 'try' ), true );
		yield 'the holder hands off after the refusal' => array(
			static function ( AttemptRepositoryDatabase $database ): void {
				self::refuse( 'try' );
				self::hand_off( $database );
			},
			false,
		);
		foreach ( array( 'acquire', 'try' ) as $path ) {
			// The holder releases and an unrelated operation takes the lock right after the refusing request's first lock-row statement.
			yield "the holder hands off inside the {$path} refusal" => array(
				static function ( AttemptRepositoryDatabase $database ) use ( $path ): void {
					$seen                   = false;
					$database->before_query = static function ( string $query ) use ( $database, &$seen ): void {
						if ( $seen ) {
							$database->before_query = null;
							self::hand_off( $database );
						}
						$seen = $seen || str_contains( $query, "'auto_updater.lock'" );
					};
					self::refuse( $path );
				},
				false,
			);
		}
		yield 'a later acquisition reclaims an expired row' => array(
			static function ( AttemptRepositoryDatabase $database ): void {
				self::refuse( 'try' );
				$database->option_rows['auto_updater.lock'] = (string) ( time() - 7200 );
				( new WordPressUpdaterLock() )->acquire();
				// A started acquisition clears the earlier refusal.
				self::assertNull( WordPressUpdaterLock::take_refusal() );
			},
			false,
		);
	}

	#[DataProvider( 'refusals' )]
	public function test_an_error_names_only_the_booster_owner_whose_lock_refused_it( \Closure $scenario, bool $named ): void {
		$token = ( new WordPressUpdaterLock() )->acquire();
		$scenario( $this->database );
		$dashboard = ( new \ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		// A success notice leaves the refusal for the error that reports it.
		$dashboard->add_message( 'Unrelated success.' );
		$dashboard->add_message( new \WP_Error( 'ran_booster_test', 'Another package operation is in progress.' ) );
		$owner = ( new \ReflectionProperty( Dashboard::class, 'updater_lock_owner' ) )->getValue( $dashboard );
		self::assertSame( $named ? $token : null, $owner['token'] ?? null );
		self::assertSame( $named ? WordPressUpdaterLock::DEFAULT_OPERATION : null, $owner['operation'] ?? null );
		// The refusal is reported once.
		self::assertNull( WordPressUpdaterLock::take_refusal() );
	}

	private static function refuse( string $path ): void {
		try {
			if ( 'acquire' === $path ) {
				( new WordPressUpdaterLock() )->acquire();
			} else {
				self::assertNull( ( new WordPressUpdaterLock() )->try_acquire_without_reclaim() );
			}
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The WordPress updater is already running.', $failure->getMessage() );
		}
	}

	private static function hand_off( AttemptRepositoryDatabase $database ): void {
		$later                 = time() . '.' . str_repeat( '5', 27 );
		$database->option_rows = array(
			'auto_updater.lock'                => $later,
			WordPressUpdaterLock::OWNER_OPTION => $later . '|package_operation|' . time(),
		);
	}

	public function test_the_failure_notices_offer_a_confirmed_release_only_with_a_recorded_owner(): void {
		$rendered = array();
		foreach ( array(
			null,
			array(
				'token'      => '1700000000.123456789123456789123456789',
				'operation'  => 'package_operation',
				'started_at' => 1700000000,
			),
		) as $updater_lock_owner ) {
			$messages = array(
				array(
					'type'    => 'error',
					'message' => 'Another package operation is in progress.',
				),
			);
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/notices.php';
			$rendered[] = (string) ob_get_clean();
		}
		self::assertStringNotContainsString( 'release-updater-lock', $rendered[0] );
		self::assertStringContainsString( 'A Booster package operation started at ', $rendered[1] );
		self::assertStringContainsString( 'name="ran_booster[lock_token]" value="1700000000.123456789123456789123456789"', $rendered[1] );
		self::assertStringContainsString( 'name="ran_booster[confirm_stopped]" value="1" required', $rendered[1] );
	}
}

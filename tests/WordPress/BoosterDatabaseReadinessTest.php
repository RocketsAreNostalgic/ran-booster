<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Activity\ActivityStore;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;

final class BoosterDatabaseReadinessTest extends TestCase {
	/** @return iterable<string, array{?\RuntimeException}> */
	public static function readiness_outcomes(): iterable {
		yield 'healthy' => array( null );
		yield 'incompatible' => array( new DatabaseCompatibilityFailure( 'unsupported_engine' ) );
		yield 'missing lifecycle' => array( new DatabaseLifecycleFailure( 'selection_install_required' ) );
	}

	#[DataProvider( 'readiness_outcomes' )]
	public function test_activity_initializes_only_after_core_readiness( ?\RuntimeException $failure ): void {
		$schema = $this->createMock( Database::class );
		$call   = $schema->expects( self::once() )->method( 'maybe_upgrade' );
		if ( null !== $failure ) {
			$call->willThrowException( $failure );
		}
		$activity  = new class() {
			public int $installs = 0;
			public function available(): bool {
				return false;
			}
			public function install(): void {
				++$this->installs;
			}
		};
		$container = new CoreContainer();
		$container->bind( Database::class, $schema );
		$resolutions = 0;
		$container->bind(
			ActivityStore::class,
			static function () use ( $activity, &$resolutions ): object {
				++$resolutions;
				return $activity;
			}
		);
		$booster = new Booster( $container );
		$booster->maybe_upgrade_database();
		self::assertSame( null === $failure ? 1 : 0, $resolutions );
		self::assertSame( null === $failure ? 1 : 0, $activity->installs );
	}
}

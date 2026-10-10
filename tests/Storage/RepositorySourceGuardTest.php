<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\RepositorySourceGuard;
use RAN\Tests\Support\RepositorySourceGuardDatabase;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class RepositorySourceGuardTest extends TestCase {

	/** @param list<object> $rows */
	#[DataProvider( 'truth_matrix' )]
	public function test_assess_rows_enforces_the_exact_repository_source_shape( array $rows, PackageSource $proposed, bool $allowed, string $code, int $release_count ): void {
		$result = RepositorySourceGuard::assess_rows( $rows, 'gh', 'R_1', 1, 'self/self.php', $proposed );

		self::assertSame( $allowed, $result['allowed'] );
		self::assertSame( $code, $result['code'] );
		self::assertSame( $release_count, $result['release_count'] );
	}

	/** @return iterable<string, array{list<object>, PackageSource, bool, string, int}> */
	public static function truth_matrix(): iterable {
		$branch  = static fn ( int|string $type, string $package ): object => (object) array(
			'type'                   => $type,
			'package'                => $package,
			'source'                 => 'branch',
			'provider'               => 'gh',
			'provider_repository_id' => 'R_1',
		);
		$release = static fn ( int|string $type, string $package ): object => (object) array(
			'type'                   => $type,
			'package'                => $package,
			'source'                 => 'release_asset',
			'provider'               => 'gh',
			'provider_repository_id' => 'R_1',
		);

		yield 'empty branch admission' => array( array(), PackageSource::BRANCH, true, 'allowed', 0 );
		yield 'empty release admission' => array( array(), PackageSource::RELEASE_ASSET, true, 'allowed', 0 );
		yield 'shared branches remain branches' => array( array( $branch( '1', 'self/self.php' ), $branch( 2, 'theme' ) ), PackageSource::BRANCH, true, 'allowed', 0 );
		yield 'sole root branch may become release' => array( array( $branch( 1, 'self/self.php' ) ), PackageSource::RELEASE_ASSET, true, 'allowed', 0 );
		yield 'release rejects companion branch' => array( array( $branch( 1, 'self/self.php' ), $branch( 2, 'theme' ) ), PackageSource::RELEASE_ASSET, false, 'repository_source_conflict', 0 );
		yield 'new branch rejects release' => array( array( $release( 2, 'theme' ) ), PackageSource::BRANCH, false, 'repository_release_owner_exists', 1 );
		yield 'legacy branch self edit progresses' => array( array( $branch( 1, 'self/self.php' ), $release( 2, 'theme' ) ), PackageSource::BRANCH, true, 'allowed', 1 );
		yield 'legacy release return progresses' => array( array( $release( 1, 'self/self.php' ), $release( 2, 'theme' ) ), PackageSource::BRANCH, true, 'allowed', 2 );
		yield 'new branch remains blocked in multi release legacy' => array( array( $release( 1, 'other/other.php' ), $release( 2, 'theme' ) ), PackageSource::BRANCH, false, 'repository_release_owner_exists', 2 );
		yield 'unknown row fails closed' => array(
			array(
				(object) array(
					'type'                   => 1,
					'package'                => 'self/self.php',
					'source'                 => 'unknown',
					'provider'               => 'gh',
					'provider_repository_id' => 'R_1',
				),
			),
			PackageSource::BRANCH,
			false,
			'repository_source_unavailable',
			0,
		);
	}

	public function test_assert_allowed_explains_shared_branch_conflict_without_inventing_release_owner(): void {
		$database       = new RepositorySourceGuardDatabase();
		$database->rows = array(
			(object) array(
				'type'                   => 1,
				'package'                => 'self/self.php',
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
			(object) array(
				'type'                   => 2,
				'package'                => 'theme',
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
		);
		$guard          = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );

		try {
			$guard->assert_allowed( 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );
			self::fail( 'A shared Branch repository must reject Release source.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_repository_source_conflict', $failure->get_diagnostic_id() );
			self::assertSame( 'This repository is shared by managed packages. Releases require a repository used by only one managed package. Review the repository’s package settings before changing source.', $failure->getMessage() );
			self::assertStringNotContainsString( 'already supplies releases to', $failure->getMessage() );
		}
	}

	public function test_assert_allowed_rejects_invalid_arguments_as_invalid_provider_identity(): void {
		$guard = new RepositorySourceGuard( new RepositorySourceGuardDatabase(), $this->createStub( Database::class ) );

		try {
			$guard->assert_allowed( '', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );
			self::fail( 'Expected invalid provider identity to throw storage invalid identity.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_invalid_provider_identity', $failure->get_diagnostic_id() );
		}
	}

	public function test_unavailable_structural_connection_fails_closed_after_lifecycle_readiness(): void {
		$previous_database = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate the absent WordPress connection; the original global is restored in finally.
		$GLOBALS['wpdb'] = null;
		try {
			foreach ( array(
				null,
				new \stdClass(),
				new class() {
					public function prepare(): never {
						throw new \LogicException( 'Incomplete connections must not prepare a query.' );
					}
				},
				new class() {
					public function get_results(): never {
						throw new \LogicException( 'Incomplete connections must not read rows.' );
					}
				},
			) as $database ) {
				$lifecycle = $this->createMock( Database::class );
				$lifecycle->expects( self::exactly( 2 ) )->method( 'require_ready' );
				$guard  = new RepositorySourceGuard( $database, $lifecycle );
				$result = $guard->assess( 'gh', 'R_1', 1, 'self/self.php', PackageSource::BRANCH );
				self::assertFalse( $result['allowed'] );
				self::assertSame( 'repository_source_unavailable', $result['code'] );
				try {
					$guard->assert_allowed( 'gh', 'R_1', 1, 'self/self.php', PackageSource::BRANCH );
					self::fail( 'Unavailable connections must reject source admission.' );
				} catch ( PackageStorageFailure $failure ) {
					self::assertSame( 'ran_booster_storage_query_failed', $failure->get_diagnostic_id() );
				}
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact connection replaced by this missing-database regression.
			$GLOBALS['wpdb'] = $previous_database;
		}
	}

	public function test_assert_allowed_treats_database_read_failure_as_query_failure(): void {
		$database             = new RepositorySourceGuardDatabase();
		$database->last_error = 'database down';

		$guard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );

		try {
			$guard->assert_allowed( 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );
			self::fail( 'Expected query failures to throw a storage query failure.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_query_failed', $failure->get_diagnostic_id() );
		}
	}

	public function test_assert_allowed_treats_malformed_rows_as_query_failure(): void {
		$database       = new RepositorySourceGuardDatabase();
		$database->rows = array(
			(object) array(
				'type'                   => 1,
				'package'                => 'self/self.php',
				'source'                 => 'not-a-source-shape',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
		);
		$guard          = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );

		try {
			$guard->assert_allowed( 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );
			self::fail( 'Expected malformed source rows to throw query failure.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_query_failed', $failure->get_diagnostic_id() );
		}
	}

	public function test_assessment_queries_opaque_repository_ids_with_binary_comparison(): void {
		$database = new RepositorySourceGuardDatabase();
		$guard    = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );

		$guard->assess( 'gh', 'Repository_ID', 1, 'self/self.php', PackageSource::BRANCH );

		self::assertStringContainsString( 'BINARY provider_repository_id = BINARY %s', $database->prepared_query );
		self::assertSame( 1, $database->reads );
	}

	public function test_conflict_projection_lists_other_packages_only_and_is_bounded(): void {
		$rows = array(
			(object) array(
				'type'                   => 1,
				'package'                => 'self/self.php',
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
			(object) array(
				'type'                   => 1,
				'package'                => 'companion/companion.php',
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
			(object) array(
				'type'                   => 2,
				'package'                => 'companion-theme',
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => 'R_1',
			),
		);

		$result = RepositorySourceGuard::assess_rows( $rows, 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );

		self::assertSame( 3, $result['relationship_count'] );
		self::assertSame(
			array(
				array(
					'type'       => 1,
					'identifier' => 'companion/companion.php',
				),
				array(
					'type'       => 2,
					'identifier' => 'companion-theme',
				),
			),
			$result['other_packages']
		);
	}
}

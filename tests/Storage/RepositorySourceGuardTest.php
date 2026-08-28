<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\RepositorySourceGuard;
use Tests\Support\RepositorySourceGuardDatabase;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class RepositorySourceGuardTest extends TestCase {

	/** @dataProvider truthMatrix */
	public function testAssessRowsEnforcesTheExactRepositorySourceShape( array $rows, PackageSource $proposed, bool $allowed, string $code, int $releaseCount ): void {
		$result = RepositorySourceGuard::assessRows( $rows, 'gh', 'R_1', 1, 'self/self.php', $proposed );

		self::assertSame( $allowed, $result['allowed'] );
		self::assertSame( $code, $result['code'] );
		self::assertSame( $releaseCount, $result['release_count'] );
	}

	/** @return iterable<string, array{list<object>, PackageSource, bool, string, int}> */
	public static function truthMatrix(): iterable {
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

	public function testAssertAllowedExplainsSharedBranchConflictWithoutInventingReleaseOwner(): void {
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
			$guard->assertAllowed( 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );
			self::fail( 'A shared Branch repository must reject Release source.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_repository_source_conflict', $failure->getDiagnosticId() );
			self::assertSame( 'This repository is shared by managed packages. Releases require a repository used by only one managed package. Review the repository’s package settings before changing source.', $failure->getMessage() );
			self::assertStringNotContainsString( 'already supplies releases to', $failure->getMessage() );
		}
	}

	public function testConflictProjectionListsOtherPackagesOnlyAndIsBounded(): void {
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

		$result = RepositorySourceGuard::assessRows( $rows, 'gh', 'R_1', 1, 'self/self.php', PackageSource::RELEASE_ASSET );

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

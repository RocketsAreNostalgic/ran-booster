<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Storage\CredentialUsageReader;
use RuntimeException;
use Tests\Support\CredentialUsageDatabase;

final class CredentialUsageReaderTest extends TestCase {

	public function testReturnsExactTotalAndBoundedDisplaySafePluginAndThemeRowsIncludingMissingPackages(): void {
		$database        = new CredentialUsageDatabase();
		$database->count = '23';
		for ( $index = 0; $index < 20; ++$index ) {
			$database->rows[] = (object) array(
				'type'    => 0 === $index % 2 ? '1' : 2,
				'package' => 0 === $index % 2 ? 'missing/plugin-' . $index . '.php' : 'missing-theme-' . $index,
			);
		}

		$usage = ( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'gh', 'profile_one' );

		self::assertSame( 23, $usage['total'] );
		self::assertCount( 20, $usage['packages'] );
		self::assertSame( array( 'plugin', 'theme' ), array_column( array_slice( $usage['packages'], 0, 2 ), 'type' ) );
		self::assertFalse( $usage['packages'][0]['installed'] );
		self::assertFalse( $usage['packages'][1]['installed'] );
		self::assertSame( array( 'wp_ran_booster_packages', 'gh', 'profile_one' ), $database->prepared[0]['arguments'] );
		self::assertSame( array( 'wp_ran_booster_packages', 'gh', 'profile_one', 20 ), $database->prepared[1]['arguments'] );
	}

	public function testSuccessfulEmptyReadUsesOneExactCountQuery(): void {
		$database = new CredentialUsageDatabase();
		$usage    = ( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'bb', 'profile_two' );

		self::assertSame(
			array(
				'total'    => 0,
				'packages' => array(),
			),
			$usage
		);
		self::assertCount( 1, $database->prepared );
	}

	/** @return array<string, array{mixed, list<object>}> */
	public static function malformedResults(): array {
		return array(
			'null count'         => array( null, array() ),
			'boolean count'      => array( false, array() ),
			'noncanonical count' => array( '01', array() ),
			'boolean type'       => array(
				'1',
				array(
					(object) array(
						'type'    => true,
						'package' => 'plugin/plugin.php',
					),
				),
			),
			'unknown type'       => array(
				'1',
				array(
					(object) array(
						'type'    => '3',
						'package' => 'plugin/plugin.php',
					),
				),
			),
			'trimmed identity'   => array(
				'1',
				array(
					(object) array(
						'type'    => '1',
						'package' => ' plugin/plugin.php ',
					),
				),
			),
		);
	}

	#[DataProvider( 'malformedResults' )]
	public function testMalformedDatabaseResultsFailClosedWithoutLeakingValues( mixed $count, array $rows ): void {
		$database        = new CredentialUsageDatabase();
		$database->count = $count;
		$database->rows  = $rows;

		try {
			( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'gh', 'canary_profile' );
			self::fail( 'A malformed usage result must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Booster could not verify repository credential usage.', $exception->getMessage() );
			self::assertStringNotContainsString( 'canary_profile', $exception->getMessage() );
		}
	}

	public function testDatabaseFailureFailsClosedWithoutLeakingTheDatabaseError(): void {
		$database             = new CredentialUsageDatabase();
		$database->last_error = 'secret-canary-database-error';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Booster could not verify repository credential usage.' );
		( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'gh', 'profile_one' );
	}

	public function testUnsafePackagePathsRemainCountedButNeverBecomeInstalledLinks(): void {
		$database        = new CredentialUsageDatabase();
		$database->count = '4';
		$database->rows  = array(
			(object) array(
				'type'    => '1',
				'package' => 'group/../escape.php',
			),
			(object) array(
				'type'    => '1',
				'package' => '/absolute.php',
			),
			(object) array(
				'type'    => '2',
				'package' => '.',
			),
			(object) array(
				'type'    => '2',
				'package' => '..',
			),
		);

		$usage = ( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'gh', 'profile_one' );

		self::assertSame( 4, $usage['total'] );
		self::assertSame( array( false, false, false, false ), array_column( $usage['packages'], 'installed' ) );
	}
}

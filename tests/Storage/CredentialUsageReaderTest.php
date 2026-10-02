<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Storage\CredentialUsageReader;
use RuntimeException;
use Tests\Support\CredentialUsageDatabase;

final class CredentialUsageReaderTest extends TestCase {

	public function test_returns_exact_total_and_bounded_display_safe_plugin_and_theme_rows_including_missing_packages(): void {
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

	public function test_successful_empty_read_uses_one_exact_count_query(): void {
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
	public static function malformed_results(): array {
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

	#[DataProvider( 'malformed_results' )]
	public function test_malformed_database_results_fail_closed_without_leaking_values( mixed $count, array $rows ): void {
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

	public function test_database_failure_fails_closed_without_leaking_the_database_error(): void {
		$database             = new CredentialUsageDatabase();
		$database->last_error = 'secret-canary-database-error';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Booster could not verify repository credential usage.' );
		( new CredentialUsageReader( $database, 'wp_ran_booster_packages' ) )->read( 'gh', 'profile_one' );
	}

	public function test_unsafe_package_paths_remain_counted_but_never_become_installed_links(): void {
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

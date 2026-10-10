<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Storage\CredentialUsageReader;
use RuntimeException;
use RAN\Tests\Support\CredentialUsageDatabase;

final class CredentialUsageReaderTest extends TestCase {

	public function test_native_admission_follows_lifecycle_and_identity_validation_without_querying(): void {
		$database = new class() {
			public int $queries = 0;

			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );
				++$this->queries;
				return $query;
			}

			public function get_var( string $query ): int {
				unset( $query );
				++$this->queries;
				return 0;
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				unset( $query );
				++$this->queries;
				return array();
			}
		};
		foreach ( array(
			array( '5.0.0', '!', 'Booster could not verify repository credential usage because database storage is unavailable.' ),
			array( '8.4.6', '!', 'The repository credential identity is invalid.' ),
			array( '8.4.6', 'profile_one', 'Booster could not verify repository credential usage.' ),
		) as [$server_version, $credential, $message] ) {
			$lifecycle_database              = new CredentialUsageDatabase();
			$lifecycle_database->server_info = $server_version;
			$reader                          = new CredentialUsageReader( $database, 'wp_ran_booster_packages', new \RAN\Storage\Database( $lifecycle_database ) );
			try {
				$reader->read( 'gh', $credential );
				self::fail( 'An undeclared connection must not admit a credential usage read.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $message, $exception->getMessage() );
			}
			self::assertSame( 0, $database->queries );
		}
	}

	public function test_declared_count_and_detail_reader_does_not_require_an_error_property(): void {
		$database = new class() implements \RAN\Storage\CredentialUsageConnection {
			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );
				return $query;
			}

			public function get_var( string $query ): int {
				unset( $query );
				return 1;
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				unset( $query );
				return array(
					(object) array(
						'type'    => '1',
						'package' => 'missing/plugin.php',
					),
				);
			}
		};
		$reader   = new CredentialUsageReader( $database, 'wp_ran_booster_packages', new \RAN\Storage\Database( new CredentialUsageDatabase() ) );
		self::assertSame(
			array(
				'total'    => 1,
				'packages' => array(
					array(
						'type'       => 'plugin',
						'identifier' => 'missing/plugin.php',
						'installed'  => false,
					),
				),
			),
			$reader->read( 'gh', 'profile_one' )
		);
	}

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

	/** @param list<object> $rows Deliberately malformed database row properties. */
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

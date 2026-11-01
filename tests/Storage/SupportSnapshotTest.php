<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Tests\RANBoosterTestCase;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class SupportSnapshotTest extends RANBoosterTestCase {
	public function test_passive_window_excludes_sensitive_columns_without_hydration(): void {
		$database   = $this->ready_database();
		$connection = new StorageTestWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused passive-reader database fixture.
		$GLOBALS['wpdb']            = $connection;
		$connection->forced_results = array_fill(
			0,
			101,
			(object) array(
				'package'               => str_repeat( 'p', 300 ),
				'repository'            => 'private/repository',
				'branch'                => 'private-branch',
				'package_type'          => '1',
				'provider'              => 'gh',
				'deployment_policy'     => 'disabled',
				'source'                => 'branch',
				'private'               => '1',
				'credential_id'         => 'private credential',
				'release_configuration' => 'private config',
				'adoption_token'        => 'private adoption',
			)
		);
		$snapshot                   = ( new PluginRepository( $database ) )->support_snapshot();
		self::assertTrue( $snapshot['available'] );
		self::assertTrue( $snapshot['truncated'] );
		self::assertCount( 100, $snapshot['records'] );
		self::assertSame( str_repeat( 'p', 255 ), $snapshot['records'][0]['package'] );
		self::assertSame( array( 'package', 'repository', 'branch', 'package_type', 'provider', 'deployment_policy', 'source', 'private' ), array_keys( $snapshot['records'][0] ) );
		$query = $connection->read_queries[0];
		self::assertStringContainsString( 'LEFT(repository,512) AS repository', $query );
		self::assertStringEndsWith( 'WHERE package_type = 1 ORDER BY id LIMIT 101', $query );
		foreach ( array( '*', 'credential_id', 'release_configuration', 'adoption_token', 'provider_repository_id' ) as $excluded ) {
			self::assertStringNotContainsString( $excluded, $query );
		}
		self::assertSame( array(), $connection->queries );
		self::assertSame( array(), $connection->inserts );
		self::assertSame( array(), $connection->updates );
		self::assertSame( array(), $connection->schemas );
	}

	public function test_shared_detail_budget_includes_zero_and_honest_empty_state(): void {
		$connection = new StorageTestWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused passive-reader database fixture.
		$GLOBALS['wpdb']            = $connection;
		$database                   = $this->ready_database();
		$connection->forced_results = array(
			(object) array(
				'package'      => 'one',
				'package_type' => '2',
			),
		);
		$snapshot                   = ( new ThemeRepository( $database ) )->support_snapshot( 0 );
		self::assertTrue( $snapshot['available'] );
		self::assertTrue( $snapshot['truncated'] );
		self::assertSame( array(), $snapshot['records'] );
		self::assertStringEndsWith( 'WHERE package_type = 2 ORDER BY id LIMIT 1', $connection->read_queries[0] );
		$connection->forced_results = array();
		self::assertSame(
			array(
				'available' => true,
				'records'   => array(),
				'truncated' => false,
			),
			( new ThemeRepository( $database ) )->support_snapshot( 23 )
		);
		self::assertStringEndsWith( 'LIMIT 24', $connection->read_queries[1] );
	}

	public function test_unknown_schema_and_invalid_limits_never_query_or_prepare_schema(): void {
		$connection = new StorageTestWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused passive-reader database fixture.
		$GLOBALS['wpdb'] = $connection;
		$database        = new class() extends Database {
			public function is_ready(): bool {
				return false;
			}
			public function require_ready(): void {
				throw new \RuntimeException( 'A passive read must not prepare schema.' );
			}
		};
		foreach ( array( -1, 0, 100, 101 ) as $limit ) {
			self::assertFalse( ( new PluginRepository( $database ) )->support_snapshot( $limit )['available'] );
		}
		self::assertSame( array(), $connection->read_queries );
		self::assertSame( array(), $connection->queries );
	}

	public function test_failed_and_malformed_reads_are_unavailable_instead_of_empty(): void {
		$connection = new StorageTestWpdb();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused passive-reader database fixture.
		$GLOBALS['wpdb']          = $connection;
		$repository               = new PluginRepository( $this->ready_database() );
		$connection->read_failure = true;
		self::assertFalse( $repository->support_snapshot()['available'] );
		$connection->read_failure   = false;
		$connection->last_error     = '';
		$connection->forced_results = array( (object) array( 'package' => array( 'malformed' ) ) );
		self::assertFalse( $repository->support_snapshot()['available'] );
	}

	private function ready_database(): Database {
		return new class() extends Database {
			public function is_ready(): bool {
				return true;
			}
			public function require_ready(): void {
				throw new \RuntimeException( 'A passive read must not prepare schema.' );
			}
		};
	}
}

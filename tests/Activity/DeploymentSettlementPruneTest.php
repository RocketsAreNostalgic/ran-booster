<?php

declare(strict_types=1);

namespace RAN\Tests\Activity;

use PDO;
use PHPUnit\Framework\TestCase;
use RAN\Activity\ActivityStore;
use RAN\Storage\DeploymentWriteConnection;

require_once __DIR__ . '/ActivityWordPressFunctions.php';

final class DeploymentSettlementPruneTest extends TestCase {
	public function test_prune_protects_unresolved_deployments_with_and_without_settlement_fact(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'SQLite is required for the SQL pruning proof.' );
		}
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$connection                              = new SettlementPruneConnection();
		$connection->database->exec( 'CREATE TABLE activity (id INTEGER PRIMARY KEY, status TEXT, facts TEXT, started_at TEXT, context_key TEXT, purpose TEXT, subject_type TEXT, subject_identity TEXT, adoption_token TEXT, context TEXT, effect TEXT, reason TEXT, completed_at TEXT)' );
		$insert = $connection->database->prepare( 'INSERT INTO activity VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' );
		self::assertNotFalse( $insert );
		foreach ( array( 'needs_attention', 'needs_attention', 'failed', 'failed' ) as $index => $state ) {
			$facts = array(
				'deployment_attempt_id'  => $index + 1,
				'deployment_state'       => $state,
				'deployment_resolved_at' => null,
			);
			if ( 1 === $index % 2 ) {
				$facts['deployment_settled_by'] = 'runner_throw';
			}
			$insert->execute( array( $index + 1, 'failed' === $state ? 'failed' : 'incomplete', \RAN\Activity\wp_json_encode( $facts ), '2000-01-01 00:00:00', 'key', 'branch.check', 'plugin', 'example', null, '', 'none', '', null ) );
		}
		$store = new ActivityStore( $connection, 'activity' );
		self::assertSame( 2, $store->prune( array() ) );
		$remaining = $connection->database->query( 'SELECT id FROM activity ORDER BY id' );
		self::assertNotFalse( $remaining );
		self::assertSame( array( 1, 2 ), $remaining->fetchAll( PDO::FETCH_COLUMN ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- SQL adapter is private to the pruning proof.
final class SettlementPruneConnection implements DeploymentWriteConnection {
	public PDO $database;

	public function __construct() {
		$this->database = new PDO( 'sqlite::memory:' );
		$this->database->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function prepare( string $query, mixed ...$arguments ): mixed {
		foreach ( $arguments as $argument ) {
			$value = is_int( $argument ) ? (string) $argument : $this->database->quote( (string) $argument );
			$query = (string) preg_replace( '/%[sd]/', $value, $query, 1 );
		}
		return $query;
	}

	public function get_results( string $query ): mixed {
		$result = $this->database->query( $this->sqlite_query( $query ) );
		if ( false === $result ) {
			return false;
		}
		return $result->fetchAll( PDO::FETCH_ASSOC );
	}

	public function query( string $query ): mixed {
		return $this->database->exec( $this->sqlite_query( $query ) );
	}

	private function sqlite_query( string $query ): string {
		foreach ( array( 'deployment_state', 'deployment_resolved_at' ) as $key ) {
			$extract = "JSON_EXTRACT(facts, '$.$key')";
			$query   = str_replace( "JSON_UNQUOTE($extract)", $extract, $query );
		}
		return $query;
	}

	public function insert( string $table, array $data ): mixed {
		throw new \LogicException( 'The pruning proof does not insert through this adapter.' );
	}
}

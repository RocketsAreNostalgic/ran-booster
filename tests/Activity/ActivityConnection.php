<?php

declare(strict_types=1);

namespace RAN\Tests\Activity;

use RAN\Storage\DeploymentWriteConnection;

/** Scripted connection makes failed reads and conditional-write races explicit. */
final class ActivityConnection implements DeploymentWriteConnection {
	/** @var list<array{sql:string,args:list<mixed>}> */
	public array $prepared = array();
	/** @var list<string> */
	public array $queries = array();
	/** @var list<array<string,mixed>> */
	public array $inserted = array();
	/** @var list<mixed> */
	public array $read_results = array();
	/** @var list<int|false> */
	public array $query_results = array();
	/** @var list<string> */
	public array $insert_tables = array();
	public bool $fail_insert    = false;

	public function prepare( string $query, mixed ...$arguments ): mixed {
		$this->prepared[] = array(
			'sql'  => $query,
			'args' => array_values( $arguments ),
		);
		return $query;
	}

	public function get_results( string $query ): mixed {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'SELECT id FROM ' ) && str_contains( $query, 'correlation =' ) ) {
			return array( (object) array( 'id' => count( $this->inserted ) ) );
		}
		return array_shift( $this->read_results ) ?? array();
	}

	public function query( string $query ): mixed {
		$this->queries[] = $query;
		return array_shift( $this->query_results ) ?? 1;
	}

	public function insert( string $table, array $data ): mixed {
		if ( $this->fail_insert ) {
			return false;
		}
		$this->insert_tables[] = $table;
		$this->inserted[]      = $data;
		return 1;
	}
}

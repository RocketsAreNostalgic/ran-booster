<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

use RAN\Storage\DeploymentWriteConnection;

/** In-memory SQL boundary for branch verification and history lifecycle tests. */
final class BranchActivityConnection implements DeploymentWriteConnection {
	/** @var array<int,array<string,mixed>> */
	public array $records = array();
	/** @var array<string,list<mixed>> */
	private array $arguments   = array();
	public bool $fail_read     = false;
	public bool $fail_insert   = false;
	public bool $fail_complete = false;

	public function prepare( string $query, mixed ...$arguments ): mixed {
		$this->arguments[ $query ] = array_values( $arguments );
		return $query;
	}

	public function insert( string $table, array $data ): mixed {
		if ( 'test_activity' !== $table ) {
			throw new \RuntimeException( 'Unexpected activity table.' );
		}
		if ( $this->fail_insert ) {
			return false;
		}
		$id                   = count( $this->records ) + 1;
		$this->records[ $id ] = $data + array( 'id' => $id );
		return 1;
	}

	public function query( string $query ): mixed {
		$args = $this->arguments[ $query ] ?? array();
		if ( str_contains( $query, 'SET status =' ) ) {
			if ( $this->fail_complete ) {
				return false;
			}
			$id = (int) $args[7];
			foreach ( array( 'status', 'reason', 'message', 'remediation', 'facts', 'effect', 'completed_at' ) as $index => $key ) {
				$this->records[ $id ][ $key ] = $args[ $index ];
			}
		}
		return 1;
	}

	public function get_results( string $query ): mixed {
		if ( $this->fail_read ) {
			return false;
		}
		$args = $this->arguments[ $query ] ?? end( $this->arguments );
		$args = is_array( $args ) ? $args : array();
		if ( str_contains( $query, 'WHERE correlation =' ) ) {
			return array( array( 'id' => count( $this->records ) ) );
		}
		$rows = array_reverse( $this->records );
		return array_slice(
			array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $args, $query ): bool {
						if ( str_contains( $query, "effect <> 'none'" ) && 'none' === ( $row['effect'] ?? 'none' ) ) {
							return false;
						}
						foreach ( array(
							'subject_type'     => 1,
							'subject_identity' => 2,
							'adoption_token'   => 3,
							'context'          => 4,
							'purpose'          => 5,
						) as $key => $index ) {
							if ( ( $row[ $key ] ?? null ) !== ( $args[ $index ] ?? null ) ) {
								return false;
							}
						}
						return true;
					}
				)
			),
			0,
			1
		);
	}
}

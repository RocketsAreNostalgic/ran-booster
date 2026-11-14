<?php

declare(strict_types=1);

namespace RAN\Tests\Activity;

use RAN\Storage\DeploymentWriteConnection;

/** Keeps real rows so the store's own insert, completion, discard and summary SQL decide what a test sees. */
final class InMemoryActivityConnection implements DeploymentWriteConnection {
	/** @var list<array<string,mixed>> */
	public array $rows      = array();
	public bool $fail_reads = false;

	public function prepare( string $query, mixed ...$arguments ): mixed {
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function () use ( &$arguments ): string {
				$value = array_shift( $arguments );
				return is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( string $table, array $data ): mixed {
		unset( $table );
		$this->rows[] = array_merge(
			array(
				'effect'       => 'none',
				'completed_at' => null,
			),
			$data,
			array( 'id' => count( $this->rows ) + 1 )
		);
		return 1;
	}

	public function get_results( string $query ): mixed {
		if ( 1 === preg_match( "/WHERE correlation = '([a-f0-9]+)'/", $query, $match ) ) {
			return array_map( static fn ( array $row ): object => (object) array( 'id' => $row['id'] ), array_values( array_filter( $this->rows, static fn ( array $row ): bool => $row['correlation'] === $match[1] ) ) );
		}
		if ( $this->fail_reads && ! str_contains( $query, 'correlation' ) ) {
			return false;
		}
		if ( 1 === preg_match( '/AS own WHERE own\.id = (\d+)\) AND id < (\d+) ORDER BY id DESC LIMIT 1/', $query, $match ) ) {
			$key   = $this->rows[ (int) $match[1] - 1 ]['context_key'] ?? null;
			$older = array_values( array_filter( array_reverse( $this->rows ), static fn ( array $row ): bool => $row['context_key'] === $key && $row['id'] < (int) $match[2] ) );
			return array() === $older ? array() : array( (object) array_intersect_key( $older[0], array_flip( array( 'status', 'reason', 'started_at' ) ) ) );
		}
		if ( 1 === preg_match( "/WHERE context_key = '([a-f0-9]+)'/", $query, $match ) ) {
			$rows = array_filter( array_reverse( $this->rows ), static fn ( array $row ): bool => $row['context_key'] === $match[1] && ( ! str_contains( $query, "effect <> 'none'" ) || 'none' !== $row['effect'] ) );
			if ( str_contains( $query, "ORDER BY status = 'skipped', id DESC" ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => 'skipped' === $a['status'] && 'skipped' !== $b['status'] ? 1 : ( 'skipped' !== $a['status'] && 'skipped' === $b['status'] ? -1 : $b['id'] <=> $a['id'] ) );
			}
			return array_map( static fn ( array $row ): object => (object) $row, array_slice( array_values( $rows ), 0, 1 ) );
		}
		return array();
	}

	public function query( string $query ): mixed {
		if ( 1 === preg_match( "/^DELETE FROM \\S+ WHERE id = (\\d+) AND status = 'incomplete'$/", $query, $match ) ) {
			$id = (int) $match[1];
			foreach ( $this->rows as $row ) {
				if ( $row['id'] === $id && 'incomplete' === $row['status'] ) {
					$this->rows = array_values( array_filter( $this->rows, static fn ( array $kept ): bool => $kept['id'] !== $id ) );
					return 1;
				}
			}
			return 0;
		}
		if ( 1 === preg_match( "/^UPDATE \\S+ SET status = '(.*?)', reason = '(.*?)', message = '.*?', remediation = '.*?', facts = '.*?', effect = '(.*?)', completed_at = '.*?' WHERE id = (\\d+) AND status = 'incomplete'$/s", $query, $match ) ) {
			foreach ( $this->rows as $index => $row ) {
				if ( $row['id'] === (int) $match[4] && 'incomplete' === $row['status'] ) {
					$this->rows[ $index ]['status'] = $match[1];
					$this->rows[ $index ]['reason'] = $match[2];
					$this->rows[ $index ]['effect'] = $match[3];
					return 1;
				}
			}
			return 0;
		}
		return false;
	}
}

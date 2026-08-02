<?php

declare(strict_types=1);

namespace Tests\WordPress;

final class ManagedReleaseStoreDatabase {

	public string $last_error = '';

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
	public array $updates = array();

	/** @param array<string, mixed> $row */
	public function __construct( public array $row ) {
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );

		return $query;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		unset( $query );

		return array( (object) $this->row );
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where */
	public function update( string $table, array $data, array $where ): int {
		$this->updates[] = array( $table, $data, $where );
		foreach ( $where as $key => $value ) {
			if ( ! array_key_exists( $key, $this->row ) || (string) $this->row[ $key ] !== (string) $value ) {
				return 0;
			}
		}
		$this->row = array_merge( $this->row, $data );

		return 1;
	}
}

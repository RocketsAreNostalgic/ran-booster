<?php

declare(strict_types=1);

namespace Tests\WordPress;

final class ManagedReleaseStoreDatabase {

	public string $last_error = '';

	public bool $sourceGuardUnavailable = false;

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
	public array $updates = array();

	/** @param array<string, mixed> $row */
	public function __construct( array $row ) {
		$this->row = array_merge(
			array(
				'provider'               => 'gh',
				'provider_repository_id' => 'fixture-repository',
			),
			$row
		);
	}

	/** @var array<string, mixed> */
	public array $row;

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );

		return $query;
	}

	public function query( string $query ): int {
		unset( $query );

		return 1;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		if ( $this->sourceGuardUnavailable && str_contains( $query, 'provider_repository_id' ) ) {
			return array( (object) array( 'type' => 1 ) );
		}

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

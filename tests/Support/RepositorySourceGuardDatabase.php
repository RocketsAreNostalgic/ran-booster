<?php

declare(strict_types=1);

namespace Tests\Support;

/** Raw-row fixture for the production repository admission helper. */
final class RepositorySourceGuardDatabase {

	/** @var list<object> */
	public array $rows        = array();
	public string $last_error = '';
	public int $reads         = 0;

	/** @return list<mixed> */
	public function prepare( string $query, mixed ...$arguments ): array {
		return $arguments;
	}

	/** @return list<object> */
	public function get_results( array $arguments ): array {
		++$this->reads;
		return array_values( array_filter( $this->rows, static fn ( object $row ): bool => $row->provider === $arguments[1] && $row->provider_repository_id === $arguments[2] ) );
	}
}

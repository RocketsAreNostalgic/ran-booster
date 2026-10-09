<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

/** Raw-row fixture for the production repository admission helper. */
final class RepositorySourceGuardDatabase {

	/** @var list<object{provider: string, provider_repository_id: string}> */
	public array $rows            = array();
	public string $last_error     = '';
	public int $reads             = 0;
	public string $prepared_query = '';

	/** @return array<array-key, mixed> */
	public function prepare( string $query, mixed ...$arguments ): array {
		$this->prepared_query = $query;
		return $arguments;
	}

	/**
	 * @param array<array-key, mixed> $arguments
	 * @return list<object>
	 */
	public function get_results( array $arguments ): array {
		++$this->reads;
		return array_values( array_filter( $this->rows, static fn ( $row ): bool => $row->provider === $arguments[1] && $row->provider_repository_id === $arguments[2] ) );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Support;

final class CredentialUsageDatabase {
	public string $last_error    = '';
	public mixed $count          = '0';
	public string $serverInfo    = '8.4.6';
	public string $innodbSupport = 'DEFAULT';

	/** @var list<object> */
	public array $rows = array();

	/** @var list<array{query: string, arguments: list<mixed>}> */
	public array $prepared = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		$this->prepared[] = array(
			'query'     => $query,
			'arguments' => $arguments,
		);

		return $query;
	}

	public function db_server_info(): string {
		return $this->serverInfo;
	}

	public function get_var( string $query ): mixed {
		unset( $query );

		return $this->count;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		if ( 'SHOW ENGINES' === $query ) {
			return array(
				(object) array(
					'Engine'  => 'InnoDB',
					'Support' => $this->innodbSupport,
				),
			);
		}
		unset( $query );

		return $this->rows;
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

/** Focused SQL-boundary double. */
final class GitHubVisibilityConnection {

	public string $last_error = '';

	public bool $fail_read = false;

	/** @var list<string> */
	public array $queries = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$query = (string) preg_replace_callback(
				'/%[is]/',
				static fn ( array $matches ): string => '%i' === $matches[0] ? '`' . (string) $argument . '`' : "'" . (string) $argument . "'",
				$query,
				1
			);
		}
		return $query;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		$this->queries[] = $query;
		if ( $this->fail_read ) {
			$this->last_error = 'read failed';
		}
		return array();
	}
}

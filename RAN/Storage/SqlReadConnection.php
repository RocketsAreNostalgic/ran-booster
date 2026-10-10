<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Native opt-in for injected connections used to read SQL query results. */
interface SqlReadConnection {

	public function prepare( string $query, mixed ...$arguments ): mixed;

	public function get_results( string $query ): mixed;
}

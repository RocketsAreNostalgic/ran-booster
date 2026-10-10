<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Native opt-in for schema inspection with mutable database error reporting. */
abstract class SchemaConnection {

	public string $last_error = '';

	abstract public function get_charset_collate(): string;

	abstract public function esc_like( string $value ): string;

	abstract public function prepare( string $query, mixed ...$arguments ): mixed;

	abstract public function get_var( string $query ): mixed;

	abstract public function get_row( string $query ): mixed;

	abstract public function get_results( string $query ): mixed;
}

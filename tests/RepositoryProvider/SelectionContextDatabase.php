<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use RAN\Storage\SchemaConnection;
use RAN\Tests\Storage\SelectionDatabase;

/** Adds the independently mutable authoritative WordPress active list to the SQL double. */
final class SelectionContextDatabase extends SchemaConnection {
	public SelectionDatabase $selection;
	public string $active_bytes = 'a:0:{}';
	public bool $fail_active    = false;
	public int $active_reads    = 0;
	/** @var \Closure():void|null */
	public ?\Closure $on_active_read = null;
	public function __construct() {
		$this->selection = new SelectionDatabase();
	}
	public function query( string $query ): int|bool {
		return $this->selection->query( $query );
	}
	public function get_charset_collate(): string {
		return '';
	}
	public function esc_like( string $value ): string {
		return $value;
	}
	public function prepare( string $query, mixed ...$arguments ): string {
		return $this->selection->prepare( $query, ...$arguments );
	}
	public function get_var( string $query ): mixed {
		throw new \LogicException( $query );
	}
	public function get_row( string $query ): object {
		return $this->selection->get_row( $query );
	}
	/** @return list<object> */
	public function get_results( string $query ): array {
		$data = json_decode( $query, true, 8, JSON_THROW_ON_ERROR );
		if ( is_array( $data ) && is_array( $data[1] ?? null ) && 'active_plugins' === ( $data[1][1] ?? null ) ) {
			++$this->active_reads;
			if ( null !== $this->on_active_read ) {
				( $this->on_active_read )();
			}
			if ( $this->fail_active ) {
				$this->last_error = 'fixture read failure';
			}
			return array(
				(object) array(
					'option_name'  => 'active_plugins',
					'option_value' => $this->active_bytes,
				),
			);
		}
		return $this->selection->get_results( $query );
	}
}

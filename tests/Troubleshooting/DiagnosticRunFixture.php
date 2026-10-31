<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use RAN\Troubleshooting\DiagnosticRunStore;
use RuntimeException;

final class DiagnosticRunFixture extends DiagnosticRunStore {
	public float $time = 1100.0;
	/** @var array{site:int,user:int}|null */
	public ?array $identity = array(
		'site' => 1,
		'user' => 1,
	);

	public function __construct( private readonly DiagnosticRunMemory $memory ) {}

	protected function scope(): ?array {
		return $this->identity; }
	protected function now(): float {
		return $this->time; }
	protected function read_raw(): ?string {
		if ( $this->memory->broken ) {
			throw new RuntimeException( 'Unavailable.' );
		}
		return $this->memory->raw;
	}
	protected function compare_and_swap( ?string $expected, string $replacement ): bool {
		++$this->memory->writes;
		if ( null !== $this->memory->conflict ) {
			$conflict               = $this->memory->conflict;
			$this->memory->conflict = null;
			$conflict();
			return false;
		}
		if ( $this->memory->refuse || $expected !== $this->memory->raw ) {
			return false;
		}
		$this->memory->raw = $replacement;
		return true;
	}
}

<?php

declare(strict_types=1);

namespace Tests\WordPress;

final class RuntimeUpdaterFacade {

	private bool $registered = false;
	private int $refreshes   = 0;

	/** @param array<string, mixed> $target @param array<string, mixed> $diagnostics */
	public function __construct(
		private array $target = array(),
		private array $diagnostics = array()
	) {
	}

	public function register(): void {
		$this->registered = true;
	}

	public function refresh(): void {
		++$this->refreshes;
	}

	public function refreshes(): int {
		return $this->refreshes;
	}

	/** @return array<string, mixed> */
	public function diagnostics(): array {
		return array( 'registered' => $this->registered ) + $this->diagnostics + $this->target;
	}
}

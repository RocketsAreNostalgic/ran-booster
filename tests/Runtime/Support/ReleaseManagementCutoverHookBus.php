<?php

declare(strict_types=1);

namespace RAN\Tests\Runtime\Support;

final class ReleaseManagementCutoverHookBus {
	/** @var array<string,list<array{callback:callable,priority:int,sequence:int}>> */
	private array $actions = array();

	/** @var array<string,list<array{callback:callable,priority:int,sequence:int}>> */
	private array $filters = array();

	private int $sequence = 0;

	public function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		$this->actions[ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
			'sequence' => $this->sequence++,
		);
	}

	public function add_filter( string $hook, callable $callback, int $priority = 10 ): void {
		$this->filters[ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
			'sequence' => $this->sequence++,
		);
	}

	public function fire( string $hook, mixed ...$arguments ): void {
		$callbacks = $this->actions[ $hook ] ?? array();
		usort(
			$callbacks,
			static fn ( array $left, array $right ): int => array( $left['priority'], $left['sequence'] )
				<=> array( $right['priority'], $right['sequence'] )
		);
		foreach ( $callbacks as $registered ) {
			( $registered['callback'] )( ...$arguments );
		}
	}

	/** @return list<string> */
	public function action_hooks(): array {
		return array_keys( $this->actions );
	}

	/** @return list<string> */
	public function filter_hooks(): array {
		return array_keys( $this->filters );
	}
}

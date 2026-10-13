<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

use RAN\Admin\CredentialExpiryObservationStore;

final class InMemoryCredentialExpiryObservationStore extends CredentialExpiryObservationStore {

	/** @var array<string, mixed> */
	public array $document = array();

	public ?\Closure $before_write = null;

	/** @var array<string, mixed>|null */
	public ?array $stale_read = null;

	public bool $fail_writes   = false;
	public bool $last_autoload = true;

	/** @return array<string, mixed> */
	protected function read_option( bool $fresh = false ): array {
		return ! $fresh && null !== $this->stale_read ? $this->stale_read : $this->document;
	}

	/** @param array<string, mixed> $document */
	protected function write_option( mixed $expected, array $document ): bool {
		$this->last_autoload = false;
		if ( null !== $this->before_write ) {
			$callback           = $this->before_write;
			$this->before_write = null;
			$callback();
		}
		if ( $this->fail_writes || $expected !== $this->document ) {
			return false;
		}

		$changed        = $document !== $this->document;
		$this->document = $document;

		return $changed;
	}
}

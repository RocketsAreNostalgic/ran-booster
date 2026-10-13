<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

use RAN\Admin\PublicRepositoryLookupProfileStore;

final class InMemoryPublicRepositoryLookupProfileStore extends PublicRepositoryLookupProfileStore {

	/** @var array<string, mixed> */
	public array $profiles = array();

	public ?\Closure $before_write = null;

	/** @var array<string, mixed>|null */
	public ?array $stale_read = null;

	public bool $fail_writes = false;

	/**
	 * @return array<string, mixed>
	 */
	protected function read_option( bool $fresh = false ): array {
		return ! $fresh && null !== $this->stale_read ? $this->stale_read : $this->profiles;
	}

	/**
	 * @param array<string, string> $profiles Provider-to-profile mapping.
	 */
	protected function write_option( mixed $expected, array $profiles ): bool {
		if ( null !== $this->before_write ) {
			$callback           = $this->before_write;
			$this->before_write = null;
			$callback();
		}
		if ( $this->fail_writes || $expected !== $this->profiles ) {
			return false;
		}

		$changed        = $profiles !== $this->profiles;
		$this->profiles = $profiles;

		return $changed;
	}
}

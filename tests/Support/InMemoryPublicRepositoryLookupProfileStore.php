<?php

declare(strict_types=1);

namespace Tests\Support;

use RAN\Admin\PublicRepositoryLookupProfileStore;

final class InMemoryPublicRepositoryLookupProfileStore extends PublicRepositoryLookupProfileStore {

	/** @var array<string, mixed> */
	public array $profiles = array();

	public bool $failWrites = false;

	/**
	 * @return array<string, mixed>
	 */
	protected function readOption(): array {
		return $this->profiles;
	}

	/**
	 * @param array<string, string> $profiles Provider-to-profile mapping.
	 */
	protected function writeOption( array $profiles ): bool {
		if ( $this->failWrites ) {
			return false;
		}

		$changed        = $profiles !== $this->profiles;
		$this->profiles = $profiles;

		return $changed;
	}
}

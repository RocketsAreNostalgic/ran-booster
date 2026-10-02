<?php

declare(strict_types=1);

namespace Tests\Support;

use RAN\Admin\CredentialExpiryObservationStore;

final class InMemoryCredentialExpiryObservationStore extends CredentialExpiryObservationStore {

	/** @var array<string, mixed> */
	public array $document = array();

	public bool $fail_writes   = false;
	public bool $last_autoload = true;

	/** @return array<string, mixed> */
	protected function read_option(): array {
		return $this->document;
	}

	/** @param array<string, mixed> $document */
	protected function write_option( array $document ): bool {
		$this->last_autoload = false;
		if ( $this->fail_writes ) {
			return false;
		}

		$changed        = $document !== $this->document;
		$this->document = $document;

		return $changed;
	}
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use RAN\Admin\CredentialExpiryObservationStore;

final class InMemoryCredentialExpiryObservationStore extends CredentialExpiryObservationStore {

	/** @var array<string, mixed> */
	public array $document = array();

	public bool $failWrites   = false;
	public bool $lastAutoload = true;

	/** @return array<string, mixed> */
	protected function read_option(): array {
		return $this->document;
	}

	/** @param array<string, mixed> $document */
	protected function write_option( array $document ): bool {
		$this->lastAutoload = false;
		if ( $this->failWrites ) {
			return false;
		}

		$changed        = $document !== $this->document;
		$this->document = $document;

		return $changed;
	}
}

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
	protected function readOption(): array {
		return $this->document;
	}

	/** @param array<string, mixed> $document */
	protected function writeOption( array $document ): bool {
		$this->lastAutoload = false;
		if ( $this->failWrites ) {
			return false;
		}

		$changed        = $document !== $this->document;
		$this->document = $document;

		return $changed;
	}
}

<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\Support;

use RAN\RepositoryProvider\ProviderWebhookProfileReader;

final class WebhookProfileReaderStub implements ProviderWebhookProfileReader {

	public int $calls = 0;

	public function __construct( private bool $configured = true, private bool $unreadable = false ) {
	}

	public function hasWebhookProfile(): bool {
		++$this->calls;
		if ( $this->unreadable ) {
			throw new \RuntimeException( 'github-webhook-secret-canary' );
		}

		return $this->configured;
	}
}

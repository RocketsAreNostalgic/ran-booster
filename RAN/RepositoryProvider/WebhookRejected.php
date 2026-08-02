<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class WebhookRejected extends RuntimeException {

	public function __construct(
		private readonly int $statusCode,
		string $safeMessage
	) {
		parent::__construct( $safeMessage );
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}
}

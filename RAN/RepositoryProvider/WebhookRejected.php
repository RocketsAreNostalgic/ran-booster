<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class WebhookRejected extends RuntimeException {

	public function __construct(
		private readonly int $status_code,
		string $safe_message
	) {
		parent::__construct( $safe_message );
	}

	public function get_status_code(): int {
		return $this->status_code;
	}
}

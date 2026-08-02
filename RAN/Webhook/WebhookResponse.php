<?php

declare(strict_types=1);

namespace RAN\Webhook;

final readonly class WebhookResponse {

	/**
	 * @param array<string, int|string> $data Safe response data.
	 */
	public function __construct(
		private int $status,
		private array $data
	) {
	}

	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * @return array<string, int|string>
	 */
	public function getData(): array {
		return $this->data;
	}
}

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

	public function get_status(): int {
		return $this->status;
	}

	/**
	 * @return array<string, int|string>
	 */
	public function get_data(): array {
		return $this->data;
	}
}

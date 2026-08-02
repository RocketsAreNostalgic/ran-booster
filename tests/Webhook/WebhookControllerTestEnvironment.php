<?php

declare(strict_types=1);

// Focused WordPress REST request and response doubles necessarily live in the
// global namespace.
// phpcs:disable

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		public function __construct(
			private array $urlParams,
			private array $mergedParams,
			private string $body = '{}',
			private array $headers = array()
		) {
		}

		public function get_url_params(): array {
			return $this->urlParams;
		}

		public function get_param( string $key ): mixed {
			return $this->mergedParams[ $key ] ?? null;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		public function __construct(
			private array $data,
			private int $status
		) {
		}

		public function get_data(): array {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

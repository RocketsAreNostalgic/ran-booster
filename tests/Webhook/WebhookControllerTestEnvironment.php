<?php

declare(strict_types=1);

// Focused WordPress REST request and response doubles necessarily live in the
// global namespace.
// phpcs:disable

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public int $bodyCalls   = 0;
		public int $headerCalls = 0;

	public function __construct(
			private array $urlParams,
			private array $mergedParams,
			private string $body = '{}',
			private array $headers = array(),
			private string $method = 'POST',
			private array $queryParams = array(),
			private string $route = '/ran-booster/v1/webhooks/gh'
		) {
		}

		public function get_url_params(): array {
			return $this->urlParams;
		}

		public function get_param( string $key ): mixed {
			return $this->mergedParams[ $key ] ?? null;
		}

		public function get_body(): string {
			++$this->bodyCalls;

			return $this->body;
		}

		public function get_headers(): array {
			++$this->headerCalls;

			return $this->headers;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_header( string $name ): string {
			$name = str_replace( '-', '_', strtolower( $name ) );

			return (string) ( $this->headers[ $name ] ?? $this->headers[ str_replace( '_', '-', $name ) ] ?? '' );
		}

		public function get_query_params(): array {
			return $this->queryParams;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		public function __construct(
			private array $data,
			private int $status,
			private array $headers = array()
		) {
		}

		public function get_data(): array {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

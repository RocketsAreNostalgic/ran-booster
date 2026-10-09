<?php

declare(strict_types=1);

// Focused WordPress REST request and response doubles necessarily live in the
// global namespace.

if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress class identity is required by the host fixture contract.
	class WP_REST_Request {
		public int $body_calls   = 0;
		public int $header_calls = 0;

		/**
		 * @param array<string, mixed> $url_params
		 * @param array<string, mixed> $merged_params
		 * @param array<string, string> $headers
		 * @param array<string, mixed> $query_params
		 */
		public function __construct(
			private array $url_params,
			private array $merged_params,
			private string $body = '{}',
			private array $headers = array(),
			private string $method = 'POST',
			private array $query_params = array(),
			private string $route = '/ran-booster/v1/webhooks/gh'
		) {
		}

		/** @return array<string, mixed> */
		public function get_url_params(): array {
			return $this->url_params;
		}

		public function get_param( string $key ): mixed {
			return $this->merged_params[ $key ] ?? null;
		}

		public function get_body(): string {
			++$this->body_calls;

			return $this->body;
		}

		/** @return array<string, string> */
		public function get_headers(): array {
			++$this->header_calls;

			return $this->headers;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_header( string $name ): ?string {
			$name = str_replace( '-', '_', strtolower( $name ) );

			return $this->headers[ $name ] ?? $this->headers[ str_replace( '_', '-', $name ) ] ?? null;
		}

		/** @return array<string, mixed> */
		public function get_query_params(): array {
			return $this->query_params;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound -- The isolated REST environment loads its native request and response doubles together. WordPress class identity is required by the host fixture contract.
	class WP_REST_Response {

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, string> $headers
		 */
		public function __construct(
			private array $data,
			private int $status,
			private array $headers = array()
		) {
		}

		/** @return array<string, mixed> */
		public function get_data(): array {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		/** @return array<string, string> */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

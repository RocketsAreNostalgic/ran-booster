<?php

declare(strict_types=1);

namespace RAN\Webhook;

use WP_REST_Request;
use WP_REST_Response;

final readonly class WebhookController {

	public function __construct( private WebhookProcessor $processor ) {
	}

	public function register_routes(): void {
		register_rest_route(
			'ran-booster/v1',
			'/webhooks/(?P<provider>[a-z0-9-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_canonical_request( $request ) ) {
			return $this->response( array( 'message' => 'Invalid webhook request.' ), 400 );
		}

		$url_params = $request->get_url_params();
		$provider   = isset( $url_params['provider'] ) && is_scalar( $url_params['provider'] )
			? (string) $url_params['provider']
			: '';
		$response   = $this->processor->handle(
			$provider,
			static fn (): array => array(
				'body'    => $request->get_body(),
				'headers' => $request->get_headers(),
			)
		);

		return $this->response( $response->get_data(), $response->get_status() );
	}

	private function is_canonical_request( WP_REST_Request $request ): bool {
		$original_method = $_SERVER['REQUEST_METHOD'] ?? $request->get_method();
		if ( ! is_string( $original_method )
			|| 'POST' !== strtoupper( $original_method )
			|| 'POST' !== strtoupper( $request->get_method() )
			|| ! in_array( $request->get_header( 'x-http-method-override' ), array( null, '' ), true )
		) {
			return false;
		}

		$query = $request->get_query_params();

		return array() === $query
			|| array( 'rest_route' => $request->get_route() ) === $query;
	}

	/** @param array<string, int|string> $data */
	private function response( array $data, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			$data,
			$status,
			array( 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' )
		);
	}
}

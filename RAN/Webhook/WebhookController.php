<?php

declare(strict_types=1);

namespace RAN\Webhook;

use WP_REST_Request;
use WP_REST_Response;

final readonly class WebhookController {

	public function __construct( private WebhookProcessor $processor ) {
	}

	public function registerRoutes(): void {
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
		if ( ! $this->isCanonicalRequest( $request ) ) {
			return $this->response( array( 'message' => 'Invalid webhook request.' ), 400 );
		}

		$urlParams = $request->get_url_params();
		$provider  = isset( $urlParams['provider'] ) && is_scalar( $urlParams['provider'] )
			? (string) $urlParams['provider']
			: '';
		$response  = $this->processor->handle(
			$provider,
			static fn (): array => array(
				'body'    => $request->get_body(),
				'headers' => $request->get_headers(),
			)
		);

		return $this->response( $response->getData(), $response->getStatus() );
	}

	private function isCanonicalRequest( WP_REST_Request $request ): bool {
		$originalMethod = $_SERVER['REQUEST_METHOD'] ?? $request->get_method();
		if ( ! is_string( $originalMethod )
			|| 'POST' !== strtoupper( $originalMethod )
			|| 'POST' !== strtoupper( $request->get_method() )
			|| '' !== $request->get_header( 'x-http-method-override' )
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

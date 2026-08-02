<?php

declare(strict_types=1);

namespace RAN\Webhook;

use RAN\Runtime\RuntimeSupport;
use WP_REST_Request;
use WP_REST_Response;

final readonly class WebhookController {

	public function __construct( private WebhookProcessor $processor ) {
	}

	public function registerRoutes(): void {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return;
		}

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
		$urlParams = $request->get_url_params();
		$provider  = isset( $urlParams['provider'] ) && is_scalar( $urlParams['provider'] )
			? (string) $urlParams['provider']
			: '';
		$response  = $this->processor->handle(
			$provider,
			$request->get_body(),
			$request->get_headers()
		);

		return new WP_REST_Response( $response->getData(), $response->getStatus() );
	}
}

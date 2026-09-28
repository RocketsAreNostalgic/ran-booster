<?php

declare(strict_types=1);

namespace RAN\Admin;

use RuntimeException;

/** A fixed, display-safe bulk-action rejection. */
final class BulkPackageActionFailure extends RuntimeException {

	private function __construct( public readonly string $reason, string $message ) {
		parent::__construct( $message );
	}

	public static function unavailable_provider(): self {
		return new self( 'provider_unavailable', __( 'A selected package uses an unavailable repository provider.', 'ran-booster' ) );
	}

	public static function unavailable_credential(): self {
		return new self( 'credential_unavailable', __( 'A selected package does not have its required repository credential.', 'ran-booster' ) );
	}

	public static function unavailable_webhook(): self {
		return new self( 'webhook_unavailable', __( 'A selected package provider does not support Automatic deployment.', 'ran-booster' ) );
	}

	public static function stale_selection(): self {
		return new self( 'stale', __( 'A selected managed package changed or is no longer available. Refresh the page and try again.', 'ran-booster' ) );
	}
}

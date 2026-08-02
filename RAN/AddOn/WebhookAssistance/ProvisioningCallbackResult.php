<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

/** Callback-only acknowledgement; it deliberately carries no provider response. */
final readonly class ProvisioningCallbackResult {

	private function __construct( private bool $succeeded ) {
	}

	public static function succeeded(): self {
		return new self( true );
	}

	public static function failed(): self {
		return new self( false );
	}

	public function wasSuccessful(): bool {
		return $this->succeeded;
	}
}

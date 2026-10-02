<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class InvalidProviderPolicy extends RuntimeException {

	public static function missing_credential_policy(): self {
		return new self( 'Credential-bearing providers must supply a credential policy.' );
	}

	public static function unavailable_credential_policy(): self {
		return new self( 'The provider credential policy is unavailable.' );
	}

	public static function unavailable_webhook_policy(): self {
		return new self( 'The provider webhook policy is unavailable.' );
	}

	public static function missing_webhook_policy(): self {
		return new self( 'Webhook-capable provider metadata must supply webhook normalization and policy.' );
	}

	public static function mismatched_provider(): self {
		return new self( 'Provider policy identity does not match its provider.' );
	}

	public static function duplicate_provider(): self {
		return new self( 'Provider secret policy is already registered.' );
	}

	public static function credential_store_unavailable(): self {
		return new self( 'A provider-bound credential store is unavailable.' );
	}

	public static function invalid_credential_store_factory(): self {
		return new self( 'The provider credential-store factory returned an invalid store.' );
	}

	public static function delivery_evidence_reader_unavailable(): self {
		return new self( 'A provider-bound delivery-evidence reader is unavailable.' );
	}

	public static function invalid_delivery_evidence_reader_factory(): self {
		return new self( 'The provider delivery-evidence factory returned an invalid reader.' );
	}

	public static function invalid_provider_factory_signature(): self {
		return new self( 'The provider factory does not implement the Provider API 13 registration signature.' );
	}


	public static function invalid_provider_factory(): self {
		return new self( 'The provider factory returned an invalid provider.' );
	}

	public static function unavailable_metadata(): self {
		return new self( 'Repository provider metadata could not be supplied.' );
	}

	public static function mismatched_factory_provider(): self {
		return new self( 'The provider factory returned a different provider identity.' );
	}
}

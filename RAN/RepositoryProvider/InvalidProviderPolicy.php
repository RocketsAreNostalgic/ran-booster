<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class InvalidProviderPolicy extends RuntimeException {

	public static function missingCredentialPolicy(): self {
		return new self( 'Credential-bearing providers must supply a credential policy.' );
	}

	public static function unavailableCredentialPolicy(): self {
		return new self( 'The provider credential policy is unavailable.' );
	}

	public static function unavailableWebhookPolicy(): self {
		return new self( 'The provider webhook policy is unavailable.' );
	}

	public static function missingWebhookPolicy(): self {
		return new self( 'Webhook-capable provider metadata must supply webhook normalization and policy.' );
	}

	public static function mismatchedProvider(): self {
		return new self( 'Provider policy identity does not match its provider.' );
	}

	public static function duplicateProvider(): self {
		return new self( 'Provider secret policy is already registered.' );
	}

	public static function credentialStoreUnavailable(): self {
		return new self( 'A provider-bound credential store is unavailable.' );
	}

	public static function invalidCredentialStoreFactory(): self {
		return new self( 'The provider credential-store factory returned an invalid store.' );
	}

	public static function invalidProviderFactory(): self {
		return new self( 'The provider factory returned an invalid provider.' );
	}

	public static function unavailableMetadata(): self {
		return new self( 'Repository provider metadata could not be supplied.' );
	}

	public static function mismatchedFactoryProvider(): self {
		return new self( 'The provider factory returned a different provider identity.' );
	}
}

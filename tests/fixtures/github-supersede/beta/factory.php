<?php

declare(strict_types=1);

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryProvider;

// A factory defined inside the `beta` plugin directory; a second claim on `gh` is a conflict.
return static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence, ProviderRegistrationContext $context ): RepositoryProvider {
	unset( $credentials, $delivery_evidence, $context );
	throw new LogicException( 'A conflicting claim must never run.' );
};

<?php

declare(strict_types=1);

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryProvider;

// Factories defined inside the `alpha` plugin directory, so the registry attributes their claims to `alpha`.
return array(
	'valid'  => static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence, ProviderRegistrationContext $context ): RepositoryProvider {
		unset( $credentials, $delivery_evidence, $context );
		$provider = $GLOBALS['ran_booster_supersede_provider'] ?? null;
		if ( ! $provider instanceof RepositoryProvider ) {
			throw new LogicException( 'The test supplies no provider.' );
		}
		return $provider;
	},
	'legacy' => static function ( ProviderCredentialStore $credentials ): RepositoryProvider {
		unset( $credentials );
		throw new LogicException( 'A factory without the Provider API 15 signature must never run.' );
	},
);

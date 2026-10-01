<?php

declare(strict_types=1);

// Preserve the API-12 guard: never load old method implementations on a new host.
add_action(
	'ran_booster_register_providers',
	static function ( object $registry ): void {
		if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 12 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! $registry instanceof \RAN\RepositoryProvider\ProviderRegistry ) {
			return;
		}
		require __DIR__ . '/repository-provider.php';
	}
);

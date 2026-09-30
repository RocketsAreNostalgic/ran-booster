<?php

declare(strict_types=1);

// Preserve the documented API-11 guard: never load the old facet on a new host.
add_action(
	'ran_booster_register_providers',
	static function ( object $registry ): void {
		if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 11 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! $registry instanceof \RAN\RepositoryProvider\ProviderRegistry ) {
			return;
		}
		require __DIR__ . '/workflow-provider.php';
	}
);

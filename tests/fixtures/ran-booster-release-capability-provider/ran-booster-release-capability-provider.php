<?php
/**
 * Plugin Name: RAN Booster Release Capability Fixture
 * Description: Test-only installed Provider API 13 release-capability fixture.
 * Version: 0.0.0
 * Requires PHP: 8.2
 * License: GPL-2.0-only
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'ran_booster_register_providers',
	static function ( object $registry ): void {
		if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 14 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! $registry instanceof \RAN\RepositoryProvider\ProviderRegistry
		) {
			return;
		}

		require_once __DIR__ . '/src/Providers.php';

		$registry->register( new \RAN_Booster_ReleaseCapabilityFixture\ZeroProvider() );
		$registry->register( new \RAN_Booster_ReleaseCapabilityFixture\PartialProvider() );
		$registry->register( new \RAN_Booster_ReleaseCapabilityFixture\ReleaseProvider() );
	}
);

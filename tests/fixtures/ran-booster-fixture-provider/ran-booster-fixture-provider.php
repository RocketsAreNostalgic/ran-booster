<?php
/**
 * Plugin Name: RAN Booster Fixture Provider
 * Description: Test-only external Provider API 10 conformance fixture.
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
			|| 10 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! $registry instanceof \RAN\RepositoryProvider\ProviderRegistry
		) {
			return;
		}

		foreach (
			array(
				'Client.php',
				'CredentialPolicy.php',
				'PreparedArchive.php',
				'Diagnostics.php',
				'Provider.php',
			) as $fixtureFile
		) {
			require_once __DIR__ . '/src/' . $fixtureFile;
		}

		$registry->registerWithCredentialStore(
			'fixture-provider',
			static fn (
				\RAN\RepositoryProvider\ProviderCredentialStore $credentials,
				\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
			): \RAN\RepositoryProvider\RepositoryProvider => new \RANBoosterFixtureProvider\Provider( $credentials, $deliveryEvidence )
		);
	}
);

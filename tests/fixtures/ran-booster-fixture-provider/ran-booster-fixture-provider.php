<?php
/**
 * Plugin Name: RAN Booster Fixture Provider
 * Description: Test-only external Provider API 13 conformance fixture.
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

		foreach (
			array(
				'Client.php',
				'CredentialPolicy.php',
				'WebhookPolicy.php',
				'PreparedArchive.php',
				'Diagnostics.php',
				'Provider.php',
			) as $fixture_file
		) {
			require_once __DIR__ . '/src/' . $fixture_file;
		}

		$registry->register_with_credential_store(
			'fixture-provider',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			static fn (
				\RAN\RepositoryProvider\ProviderCredentialStore $credentials,
				\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				\RAN\RepositoryProvider\ProviderRegistrationContext $registration_context
			): \RAN\RepositoryProvider\RepositoryProvider => new \RAN_Booster_FixtureProvider\Provider( $credentials, $delivery_evidence )
		);
	}
);

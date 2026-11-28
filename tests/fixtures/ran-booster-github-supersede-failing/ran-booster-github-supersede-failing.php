<?php
/**
 * Plugin Name: RAN Booster GitHub Supersede Failing Fixture
 * Description: Test-only claimant whose GitHub factory always throws.
 * Version: 0.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: ran-booster
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace RAN_Booster_GitHubSupersedeFailingFixture;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A valid Provider API 15 claim: alone its factory fails; beside another claim it is a conflict and never runs.
add_action(
	'ran_booster_register_providers',
	static function ( object $registry ): void {
		if ( ! $registry instanceof ProviderRegistry ) {
			return;
		}
		$GLOBALS['ran_booster_supersede_failing_calls'] = 0;
		$registry->supersede_github(
			static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence, ProviderRegistrationContext $context ): RepositoryProvider {
				unset( $credentials, $delivery_evidence, $context );
				$calls = $GLOBALS['ran_booster_supersede_failing_calls'] ?? 0;
				$GLOBALS['ran_booster_supersede_failing_calls'] = ( is_int( $calls ) ? $calls : 0 ) + 1;
				throw new \LogicException( 'The failing GitHub claimant fixture always throws.' );
			}
		);
	}
);

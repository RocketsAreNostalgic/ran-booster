<?php

declare(strict_types=1);

namespace RANBoosterGitHubProviderExtensionFixture;

use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

/**
 * External-plugin composition root for the released GitHub provider package.
 */
final class Plugin {
	public static function boot(): void {
		add_action( 'ran_booster_register_providers', array( new self(), 'register_provider' ) );
	}

	public function register_provider( object $registry ): void {
		if ( ! self::has_compatible_core() || ! $registry instanceof ProviderRegistry ) {
			return;
		}

		$inner_registrar = require dirname( __DIR__ ) . '/vendor/ran/wp-release-updater/bootstrap.php';
		$registrar       = new ReleaseUpdaterRegistrar( $inner_registrar );

		$factory = static function (
			ProviderCredentialStore $credentials,
			AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
			ProviderRegistrationContext $registration_context
		) use ( $registrar ): RepositoryProvider {
			return GitHubProvider::create(
				$credentials,
				$delivery_evidence,
				$registrar,
				static fn (): int => $registration_context->maximum_artifact_bytes()
			);
		};

		$registry->register_with_credential_store( 'gh', $factory );
	}

	private static function has_compatible_core(): bool {
		return ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) || 'single_site_supported' === RAN_BOOSTER_RUNTIME_MODE )
			&& defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			&& 14 === RAN_BOOSTER_PROVIDER_API_VERSION;
	}
}

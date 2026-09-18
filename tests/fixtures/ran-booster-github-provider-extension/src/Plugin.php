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
		add_action( 'ran_booster_register_providers', array( new self(), 'registerProvider' ) );
	}

	public function registerProvider( object $registry ): void {
		if ( ! self::hasCompatibleCore() || ! $registry instanceof ProviderRegistry ) {
			return;
		}

		$innerRegistrar = require dirname( __DIR__ ) . '/vendor/ran/wp-release-updater/bootstrap.php';
		$registrar      = new ReleaseUpdaterRegistrar( $innerRegistrar );

		$factory = static fn (
			ProviderCredentialStore $credentials,
			AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
		): RepositoryProvider => GitHubProvider::create(
			$credentials,
			$deliveryEvidence,
			$registrar
		);

		if ( class_exists( ProviderRegistrationContext::class ) ) {
			$factory = static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
				ProviderRegistrationContext $registrationContext
			) use ( $registrar ): RepositoryProvider {
				return GitHubProvider::create(
					$credentials,
					$deliveryEvidence,
					$registrar,
					static fn (): int => $registrationContext->maximumArtifactBytes()
				);
			};
		}

		$registry->registerWithCredentialStore( 'gh', $factory );
	}

	private static function hasCompatibleCore(): bool {
		return ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) || 'single_site_supported' === RAN_BOOSTER_RUNTIME_MODE )
			&& defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			&& 10 === RAN_BOOSTER_PROVIDER_API_VERSION;
	}
}

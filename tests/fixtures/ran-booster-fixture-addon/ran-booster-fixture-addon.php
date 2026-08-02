<?php
/**
 * Plugin Name: RAN Booster Fixture Add-on
 * Description: Test-only Booster Add-on API 12 conformance fixture.
 * Version: 0.0.0
 * Requires PHP: 8.2
 * License: GPL-2.0-only
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			|| 12 !== RAN_BOOSTER_ADDON_API_VERSION
			|| ! defined( 'RAN_BOOSTER_LOGGING_API_VERSION' )
			|| 1 !== RAN_BOOSTER_LOGGING_API_VERSION ) {
			return;
		}

		add_action(
			'ran_booster_webhook_assistance_ready',
			static function ( object $facade, object $logging ): void {
				if ( ! $facade instanceof \RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade
					|| ! $logging instanceof \RAN\AddOn\Logging\LoggingFacade ) {
					return;
				}

				add_filter(
					'ran_booster_admin_provider_repository_rows',
					static function ( array $rows, string $providerCode ) use ( $facade ): array {
						if ( 'gh' !== $providerCode ) {
							return $rows;
						}

						try {
							$repositories = $facade->readiness( $providerCode )->toArray()['repositories'];
						} catch ( \Throwable ) {
							return $rows;
						}

						foreach ( $repositories as $repository ) {
							$repositoryId = $repository['repository_id'] ?? null;
							if ( ! is_string( $repositoryId ) || ! isset( $rows[ $repositoryId ]['actions']['core:assisted-hooks'] ) ) {
								continue;
							}
							$rows[ $repositoryId ]['actions']['core:assisted-hooks']['url']      =
								'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&assisted_repository=' . rawurlencode( $repositoryId );
							$rows[ $repositoryId ]['actions']['core:assisted-hooks']['disabled'] = false;
						}

						return $rows;
					},
					10,
					4
				);
			},
			10,
			2
		);
	}
);

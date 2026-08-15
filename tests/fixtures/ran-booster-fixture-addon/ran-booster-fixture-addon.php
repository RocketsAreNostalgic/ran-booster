<?php
/**
 * Plugin Name: RAN Booster Fixture Add-on
 * Description: Test-only provider-owned webhook-management adapter fixture.
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
			|| 15 !== RAN_BOOSTER_ADDON_API_VERSION ) {
			return;
		}

		add_action(
			'ran_booster_webhook_assistance_ready',
			static function ( object $facade ): void {
				if ( ! $facade instanceof \RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade ) {
					return;
				}

				add_filter(
					'ran_booster_admin_provider_repository_assistance_active',
					static fn ( bool $active, string $providerCode ): bool => 'fixture-provider' === $providerCode ? true : $active,
					10,
					2
				);
				add_filter(
					'ran_booster_admin_provider_repository_rows',
					static function ( array $rows, string $providerCode ) use ( $facade ): array {
						if ( 'fixture-provider' !== $providerCode ) {
							return $rows;
						}

						try {
							$repositories = $facade->readiness( $providerCode )->toArray()['repositories'];
						} catch ( \Throwable ) {
							return $rows;
						}

						foreach ( $repositories as $repository ) {
							$repositoryId = $repository['repository_id'] ?? null;
							if ( ! is_string( $repositoryId ) || ! isset( $rows[ $repositoryId ]['actions']['core:webhook-management'] ) ) {
								continue;
							}
							$rows[ $repositoryId ]['actions']['core:webhook-management']['url']      =
								admin_url( 'admin.php?page=ran-booster&tab=fixture-provider&repository=' . rawurlencode( $repositoryId ) );
							$rows[ $repositoryId ]['actions']['core:webhook-management']['disabled'] = false;
						}

						return $rows;
					},
					10,
					4
				);
				add_action(
					'ran_booster_admin_provider_repository_panel',
					static function ( string $providerCode, string $repositoryId ) use ( $facade ): void {
						if ( 'fixture-provider' !== $providerCode
							|| null === $facade->target( $providerCode, $repositoryId ) ) {
							return;
						}
						?>
						<form method="post" action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fixture_webhook_management_setup">
							<input type="hidden" name="provider_code" value="fixture-provider">
							<input type="hidden" name="repository_id" value="<?php echo esc_attr( $repositoryId ); ?>">
							<?php wp_nonce_field( 'fixture_webhook_management_setup_' . $repositoryId ); ?>
							<label>Fixture API key <input type="password" name="fixture_api_key" autocomplete="off"></label>
							<button type="submit">Configure fixture webhook</button>
						</form>
						<?php
					},
					10,
					3
				);
				add_action(
					'admin_post_fixture_webhook_management_setup',
					static function () use ( $facade ): void {
						$request      = is_array( $_POST ) ? $_POST : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below against the exact target.
						$providerCode = is_string( $request['provider_code'] ?? null ) ? trim( $request['provider_code'] ) : '';
						$repositoryId = is_string( $request['repository_id'] ?? null ) ? trim( $request['repository_id'] ) : '';
						$nonce        = is_string( $request['_wpnonce'] ?? null ) ? trim( $request['_wpnonce'] ) : '';
						$credential   = is_string( $request['fixture_api_key'] ?? null ) ? trim( $request['fixture_api_key'] ) : '';

						if ( ! current_user_can( 'manage_options' )
							|| 'fixture-provider' !== $providerCode
							|| '' === $repositoryId
							|| '' === $credential
							|| 1 !== wp_verify_nonce( $nonce, 'fixture_webhook_management_setup_' . $repositoryId ) ) {
							return;
						}

						$target = $facade->target( $providerCode, $repositoryId );
						if ( null === $target ) {
							return;
						}

						try {
							$facade->setup( $target, null, $nonce, $credential );
						} finally {
							$credential = '';
							unset( $request['fixture_api_key'], $_POST['fixture_api_key'] );
						}
					}
				);
			},
			10,
			1
		);
	}
);

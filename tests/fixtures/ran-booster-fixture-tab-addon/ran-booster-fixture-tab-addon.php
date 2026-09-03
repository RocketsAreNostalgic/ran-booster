<?php
/**
 * Plugin Name: RAN Booster Fixture Tab Add-on
 * Description: Test-only Booster Add-on API 16 tab conformance fixture.
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
			|| 16 !== RAN_BOOSTER_ADDON_API_VERSION ) {
			return;
		}

		add_action(
			'ran_booster_register_admin_tabs',
			static function ( object $registry ): void {
				if ( ! $registry instanceof \RAN\Admin\AdminAddOnRegistry ) {
					return;
				}

				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The external fixture owns its text domain.
				$label = __( 'Fixture Tab', 'ran-booster-fixture-tab-addon' );

				$registry->register(
					new \RAN\Admin\AdminAddOnTab(
						'ran-booster-fixture-tab-addon',
						'fixture-tab',
						$label,
						static function ( \RAN\Admin\AdminAddOnContext $context ) use ( $label ): void {
							printf(
								'<div id="ran-booster-fixture-tab" data-scope="%s" data-url="%s">%s</div>',
								esc_attr( $context->scope() ),
								esc_attr( $context->boosterUrl() ),
								esc_html( $label )
							);
						},
						7,
						7,
						7,
						7
					)
				);
			}
		);
	}
);
